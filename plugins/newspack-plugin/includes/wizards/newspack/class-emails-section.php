<?php
/**
 * Newspack Emails Section.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Emails;
use Newspack\Reader_Activation;
use Newspack\Reader_Revenue_Emails;
use Newspack\Wizards\Wizard_Section;
use Newspack\WooCommerce_Emails;
use WP_Error;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Emails Section Class.
 *
 * Surfaces the unified emails management UI in the Newspack > Settings >
 * Emails wizard tab. Backed by the unified `newspack_email_configs`
 * schema — no parallel registry.
 */
class Emails_Section extends Wizard_Section {
	/**
	 * Containing wizard slug.
	 *
	 * Vestigial: the actual REST path is constructed from `self::REST_BASE`,
	 * not this property. The parent `Wizard_Section::__construct` overwrites
	 * this from the `wizard_slug` arg passed by `Wizard::load_wizard_sections`
	 * anyway. Kept for parity with sibling sections and any inherited base-
	 * class behavior that reads it.
	 *
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

	/**
	 * REST base path for Emails endpoints.
	 *
	 * Hardcoded to 'newspack-settings' for API stability. When NPPD-1538
	 * later moves the Emails screen from Newspack > Settings to Audience >
	 * Configuration, this REST path MUST stay at 'newspack-settings' —
	 * external callers and the frontend depend on it. Do NOT change.
	 */
	const REST_BASE = 'wizard/newspack-settings/emails';

	/**
	 * Constructor — extends Wizard_Section's REST-route hookup with an
	 * admin_init handler for the WC first-run auto-enable. Decoupling
	 * first-run from api_get_email_settings keeps the GET endpoint
	 * idempotent (probes / crawlers can no longer trigger silent
	 * WC option writes).
	 *
	 * @param array $args Section arguments.
	 */
	public function __construct( $args = [] ) {
		parent::__construct( $args );
		add_action( 'admin_init', [ __CLASS__, 'maybe_first_run_enable_wc_emails' ] );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * Mockable in tests via the `newspack_woocommerce_active` filter. The
	 * filter exists because a bare `class_exists( 'WooCommerce' )` check
	 * couples test isolation to whether some sibling test file declared a
	 * global `class WooCommerce {}` shim — i.e., suite-order-dependent.
	 * Tests `add_filter( 'newspack_woocommerce_active', '__return_true' )`
	 * in their setUp; production code paths see the unfiltered default.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_active(): bool {
		/**
		 * Filters whether WooCommerce is considered active for the
		 * unified emails wizard. Default is `class_exists( 'WooCommerce' )`.
		 *
		 * @param bool $active Whether WC is active.
		 */
		return (bool) apply_filters( 'newspack_woocommerce_active', class_exists( 'WooCommerce' ) );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_rest_routes() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_email_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Reset endpoint — trashes the email template post so the next
		// read recreates it from the default template. Owns the action
		// the donations wizard used to register at
		// `/wizard/newspack-audience-donations/emails/{id}` — consolidated
		// under the emails namespace in NPPD-1535. Registered
		// unconditionally; resetting a Newspack-managed email has no WC
		// dependency.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE . '/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'api_reset_email' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Toggle endpoint for WooCommerce-source emails. Only registered
		// when WC is loaded; without WC there are no WC configs to toggle.
		if ( self::is_woocommerce_active() ) {
			register_rest_route(
				NEWSPACK_API_NAMESPACE,
				self::REST_BASE . '/(?P<id>[A-Za-z0-9_]+)/toggle',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_toggle_wc_email' ],
					'permission_callback' => [ $this, 'api_permissions_check' ],
					'args'                => [
						'id'      => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'enabled' => [
							'type'              => 'boolean',
							'required'          => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				]
			);
		}
	}

	/**
	 * Toggle a WooCommerce email's enabled state.
	 *
	 * Validates the email ID against the unified config set — only
	 * WC-source configs registered by `WooCommerce_Emails::get_email_configs()`
	 * are toggleable. Writes both the in-memory `WC_Email::$enabled`
	 * property AND the underlying WC option, in that order:
	 *
	 *   1. `$wc_email->enabled = ...`  — in-memory state, defensive in
	 *      case downstream code reads the cached mailer instance.
	 *   2. `update_option( $wc_email->get_option_key(), ... )` — the
	 *      authoritative source of truth. WP busts the options cache on
	 *      update_option, so the subsequent `get_option()` call inside
	 *      api_get_email_settings() (via serialize_wc_email_row) reads
	 *      the new value in the same request.
	 *
	 * The response is a refreshed wizard payload — the toggled email's
	 * status field reflects the new state.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Refreshed settings payload or error.
	 */
	public static function api_toggle_wc_email( $request ) {
		$wc_email_id = $request->get_param( 'id' );
		$enabled     = (bool) $request->get_param( 'enabled' );

		if ( ! self::is_woocommerce_active() ) {
			return new \WP_Error(
				'newspack_wc_not_active',
				__( 'WooCommerce is not active.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		// Validate the id is one of the surfaced WC configs before
		// touching the mailer — defense against arbitrary id input.
		$configs   = Emails::get_email_configs();
		$wc_config = $configs[ $wc_email_id ] ?? null;
		if ( ! $wc_config || 'woocommerce' !== ( $wc_config['source'] ?? 'newspack' ) ) {
			return new \WP_Error(
				'newspack_wc_email_not_allowed',
				__( 'WooCommerce email is not in the surfaced allowlist.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! WooCommerce_Emails::set_wc_email_enabled_state( $wc_email_id, $enabled ) ) {
			return new \WP_Error(
				'newspack_wc_email_write_failed',
				__( 'Could not update the WooCommerce email state.', 'newspack-plugin' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( self::api_get_email_settings() );
	}

	/**
	 * Reset an email template by trashing the email post.
	 *
	 * Ported from `Audience_Donations::api_reset_donation_email` in
	 * NPPD-1535 — the endpoint conceptually belongs under the emails
	 * namespace, not the donations wizard. Returns the refreshed email
	 * list (same shape the donations endpoint returned) so existing
	 * callers stay compatible.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public static function api_reset_email( $request ) {
		$id    = $request->get_param( 'id' );
		$email = get_post( $id );

		// Source boundary: this route can only ever reset Newspack-managed
		// emails. The `POST_TYPE === $email->post_type` check below is the
		// enforcement — non-Newspack sources are never stored as POST_TYPE
		// posts. WooCommerce-source rows are live `WC_Email` objects with
		// `wc:`-prefixed string ids, which additionally can't match the
		// route's numeric-only `(?P<id>\d+)` pattern (`absint`-sanitized).
		// So a direct REST call cannot reset a row the UI gates behind
		// `source === 'newspack'`.
		if ( null === $email || Emails::POST_TYPE !== $email->post_type ) {
			return new WP_Error(
				'newspack_reset_email_invalid_arg',
				esc_html__( 'Invalid argument: no email template matches the provided id.', 'newspack-plugin' ),
				[
					'status' => 400,
					'level'  => 'notice',
				]
			);
		}

		if ( ! wp_trash_post( $id ) ) {
			return new WP_Error(
				'newspack_reset_email_reset_failed',
				esc_html__( 'Reset failed: unable to reset email template.', 'newspack-plugin' ),
				[
					'status' => 400,
					'level'  => 'notice',
				]
			);
		}

		// Returns the raw Emails::get_emails() array, not the enriched
		// api_get_email_settings() shape that the sibling api_toggle_wc_email
		// returns. Preserves the legacy donations-endpoint contract
		// (callers depending on the raw shape don't break on the move).
		// Aligning sibling endpoints in this class on a single shape is
		// tracked separately — see NPPD-1569.
		return rest_ensure_response(
			Emails::get_emails( Reader_Activation::is_enabled() ? [] : array_values( Reader_Revenue_Emails::EMAIL_TYPES ), false )
		);
	}

	/**
	 * Get email settings.
	 *
	 * Builds the unified emails list directly from the
	 * `newspack_email_configs` schema — no parallel registry, no join.
	 * Newspack-source configs resolve to WP posts via Emails::get_emails();
	 * WooCommerce-source configs build rows by resolving the live WC_Email
	 * instance on-demand via WooCommerce_Emails::get_wc_email_by_id()
	 * (the schema itself only carries `wc_email_class` — a scalar
	 * string — so it stays JSON-serializable).
	 *
	 * @return array{
	 *     newspack_emails: array<int, array{
	 *         type:                string,
	 *         category:            string,
	 *         label:               string,
	 *         description:         string,
	 *         post_id:             int|string,
	 *         edit_link:           string,
	 *         subject:             string,
	 *         from_name:           string,
	 *         from_email:          string,
	 *         reply_to_email:      string,
	 *         status:              string,
	 *         html_payload:        string,
	 *         trigger_description: string,
	 *         recipient:           'reader'|'admin',
	 *         recommended:         bool,
	 *         chip:                'auth-account'|'reader-revenue',
	 *         source:              'newspack'|'woocommerce',
	 *         preview_id?:         int|string|null,
	 *     }>,
	 *     post_type: string,
	 * }
	 */
	public static function api_get_email_settings(): array {
		// First-run runs on admin_init (constructor-hooked), not here —
		// keeps the GET endpoint idempotent so probes / crawlers / SSR
		// bootstrap reads can't trigger WC option writes as a side effect.
		$configs = Emails::get_email_configs();

		// Split by source — Newspack configs go through Emails::get_emails()
		// for post resolution; WC configs build rows by resolving the
		// live WC_Email instance via WooCommerce_Emails::get_wc_email_by_id().
		$newspack_configs = array_filter(
			$configs,
			fn( $config ) => ( $config['source'] ?? 'newspack' ) !== 'woocommerce'
		);
		$wc_configs       = array_filter(
			$configs,
			fn( $config ) => ( $config['source'] ?? 'newspack' ) === 'woocommerce'
		);

		// RA gating applies only to Newspack-source configs (the auth/account
		// flows have no use without RA). WC configs are gated by their own
		// plugin_dependency at registration time and surface regardless of
		// RA state.
		$newspack_configs = self::filter_configs_by_ra_state( Reader_Activation::is_enabled(), $newspack_configs );

		// Resolve each newspack-source config to a Newspack post + serialized
		// payload via the existing Emails::get_emails() pipeline.
		// Guard against the empty-types case: Emails::get_emails() treats
		// an empty $config_names as "no filter" and returns every registered
		// email, which would bypass the WC-source and RA-state filters
		// above. Skip the resolve path when there are no Newspack configs;
		// any WC rows are still appended below.
		$newspack_types = array_keys( $newspack_configs );
		$emails         = empty( $newspack_types ) ? [] : Emails::get_emails( $newspack_types, false );

		$newspack_emails = array_values( $emails );

		// Build a row per WC config — serialize_wc_email_row resolves
		// the live WC_Email instance via WooCommerce_Emails::get_wc_email_by_id().
		foreach ( $wc_configs as $type => $config ) {
			$wc_row = self::serialize_wc_email_row( $type, $config );
			if ( null !== $wc_row ) {
				$newspack_emails[] = $wc_row;
			}
		}

		// Single category-only sort: reader-revenue → reader-activation → other.
		$category_order = [
			'reader-revenue'    => 0,
			'reader-activation' => 1,
		];
		// `usort` is not stable in PHP — same-category rows can reorder
		// across requests without a tiebreaker. Use the config's
		// registration order in `$configs` as the secondary key:
		// providers register in deliberate order (WC: gift emails
		// adjacent in `WooCommerce_Emails::surfaced_wc_emails()`;
		// Newspack: receipt → welcome → cancellation in
		// `Reader_Revenue_Emails`, verification → magic-link → ...
		// in `Reader_Activation_Emails`), so registration order
		// equals intended display order. This mirrors the legacy
		// `array_flip(array_keys($registry))` pattern.
		$type_order = array_flip( array_keys( $configs ) );
		usort(
			$newspack_emails,
			function ( $a, $b ) use ( $category_order, $type_order ) {
				$order_a = $category_order[ $a['category'] ?? '' ] ?? 2;
				$order_b = $category_order[ $b['category'] ?? '' ] ?? 2;
				if ( $order_a !== $order_b ) {
					return $order_a - $order_b;
				}
				$idx_a = $type_order[ $a['type'] ?? '' ] ?? PHP_INT_MAX;
				$idx_b = $type_order[ $b['type'] ?? '' ] ?? PHP_INT_MAX;
				return $idx_a - $idx_b;
			}
		);

		return [
			'newspack_emails' => $newspack_emails,
			'post_type'       => Emails::POST_TYPE,
		];
	}

	/**
	 * Restrict configs to the set visible in the wizard given the current
	 * Reader Activation state.
	 *
	 * When RA is enabled, all configs are visible. When it's disabled, only
	 * reader-revenue configs surface — the auth/account flows have no use
	 * without RA. Membership is keyed off the config's `chip` field
	 * (`'reader-revenue'`) rather than a hardcoded provider-specific
	 * constant: any new reader-revenue provider that declares
	 * `category: 'reader-revenue'` (and therefore inherits
	 * `chip: 'reader-revenue'` via apply_config_defaults) automatically
	 * surfaces in the RA-off view without needing to be added to a list
	 * the section class knows about.
	 *
	 * Extracted from `api_get_email_settings()` so the gating is unit-testable
	 * without toggling `Reader_Activation::is_enabled()` (which hard-returns
	 * true in the test environment).
	 *
	 * @param bool  $ra_enabled Whether Reader Activation is enabled.
	 * @param array $configs    Configs keyed by type.
	 * @return array Configs filtered to the visible set for the given RA state.
	 */
	public static function filter_configs_by_ra_state( bool $ra_enabled, array $configs ): array {
		if ( $ra_enabled ) {
			return $configs;
		}
		return array_filter(
			$configs,
			fn( $config ) => 'reader-revenue' === ( $config['chip'] ?? '' )
		);
	}

	/**
	 * Option name tracking which recommended WC email configs have been
	 * processed by the first-run auto-enable. Storing processed keys (not
	 * a single "ran once" boolean) keeps the logic idempotent across new
	 * config additions: a future config that lands later still gets a
	 * first-run pass on its first appearance, while previously-processed
	 * configs are skipped — even if the user has manually disabled them
	 * since.
	 *
	 * @var string
	 */
	const FIRST_RUN_OPTION = 'newspack_unified_emails_wc_first_run';

	/**
	 * WC Subscriptions site-wide master switch option key. When this is
	 * unset, the renewal-reminder email never fires regardless of its
	 * own enabled flag — first-run mirrors the email's auto-enable into
	 * this switch ONLY when we're also enabling the email itself (see
	 * `maybe_first_run_enable_wc_emails`).
	 *
	 * @var string
	 */
	const WCS_MASTER_SWITCH_OPTION = 'woocommerce_subscriptions_customer_notifications_enabled';

	/**
	 * On first encounter of a recommended WC email, enable it — but only
	 * if the publisher hasn't already recorded an explicit decision for
	 * that email. Idempotent per config key — once a key is in the
	 * FIRST_RUN_OPTION list, this method never reconsiders it. The slug
	 * is added to FIRST_RUN_OPTION regardless of whether we wrote, so
	 * the "considered once" semantics hold whether or not the publisher
	 * already had a decision in place.
	 *
	 * The gate is `! isset( $options['enabled'] )` — we only auto-enable
	 * when the email's WC settings option doesn't carry an `enabled` key
	 * at all (publisher has never saved that email's WC settings form).
	 * An explicit `'no'` is a deliberate publisher decision; preserve it.
	 *
	 * Hooked on `admin_init` rather than called from `api_get_email_settings()`
	 * so the GET endpoint stays idempotent — a probe, schema crawler, or
	 * sibling-admin pageload that hits the wizard route doesn't trigger
	 * silent WC option writes. The endpoint just reads; this method
	 * does the writing exactly once per slug, gated by an authenticated
	 * admin pageload.
	 *
	 * Auth/account-chipped WC configs (e.g. customer_new_account) are
	 * skipped when Reader Activation is disabled — that matches the
	 * filter the wizard surface applies via filter_configs_by_ra_state
	 * for visibility, and prevents an auth-only WC email from auto-firing
	 * on a store-only site that never opted into RA. Crucially they are
	 * NOT added to the processed list while skipped, so enabling RA later
	 * still gives them a real first-run pass.
	 *
	 * Write failures are not marked processed either: if
	 * `set_wc_email_enabled_state()` fails to land the enable, the slug is
	 * left out of the processed list so a later run retries it, and the
	 * WCS master switch is not flipped on the back of a write that didn't
	 * take.
	 *
	 * Special case: `customer_notification_auto_renewal` requires the WC
	 * Subscriptions master switch
	 * (`woocommerce_subscriptions_customer_notifications_enabled`) to
	 * also be on — otherwise the email never fires regardless of its own
	 * flag. The master-switch write is nested INSIDE the `! isset` guard
	 * so it only fires when we're actually auto-enabling the email. A
	 * publisher who toggled the auto_renewal email OFF before first-run
	 * ran (`isset($options['enabled'])` already true) doesn't get the
	 * site-wide WCS master switch silently flipped on as a side effect.
	 */
	public static function maybe_first_run_enable_wc_emails(): void {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}

		$ra_enabled = Reader_Activation::is_enabled();
		$processed  = (array) get_option( self::FIRST_RUN_OPTION, [] );
		$configs    = Emails::get_email_configs();
		$changed    = false;

		foreach ( $configs as $type => $config ) {
			if ( ( $config['source'] ?? 'newspack' ) !== 'woocommerce' ) {
				continue;
			}
			if ( empty( $config['recommended'] ) ) {
				continue;
			}
			// Already processed — don't touch even if currently disabled.
			// This is the user-disabled-after-first-run protection.
			if ( in_array( $type, $processed, true ) ) {
				continue;
			}
			// Don't auto-enable auth-account WC emails on sites that
			// haven't opted into Reader Activation. The wizard's read
			// path already hides them via filter_configs_by_ra_state;
			// mirror that here so the write path doesn't fire unrelated
			// WC emails on store-only sites.
			//
			// Skip WITHOUT marking processed: the config is only hidden
			// because RA is off, so it never got a real first-run pass. If
			// the publisher enables RA later, we want this recommended row
			// reconsidered then. Burning it into the processed list here
			// would permanently deny it the auto-enable it was due once it
			// became eligible. Re-evaluation each admin pageload while RA is
			// off is just the cheap chip check above.
			if ( ! $ra_enabled && 'reader-revenue' !== ( $config['chip'] ?? '' ) ) {
				continue;
			}

			$wc_email = WooCommerce_Emails::get_wc_email_by_id( $type );
			if ( ! $wc_email ) {
				continue;
			}

			$option_key = $wc_email->get_option_key();
			$options    = (array) get_option( $option_key, [] );

			// Only write when the publisher hasn't recorded a decision
			// yet. An explicit 'no' is preserved. The WCS master-switch
			// write is nested inside the same guard so a publisher who
			// already disabled the email doesn't get the site-wide
			// master switch silently flipped on.
			if ( ! isset( $options['enabled'] ) ) {
				if ( ! WooCommerce_Emails::set_wc_email_enabled_state( $type, true ) ) {
					// Write failed (mailer unresolvable, option write
					// rejected). Leave the slug UNPROCESSED so a later
					// admin pageload retries it, and don't flip the
					// site-wide WCS master switch off the back of an
					// enable that never landed.
					continue;
				}

				// WCS master switch: enable only when not present in the
				// DB, and only now that the email enable actually
				// succeeded. `get_option(..., false)` returns the default
				// `false` only when the option row doesn't exist — an
				// explicit `'no'` returns `'no'` and is preserved.
				if (
					'customer_notification_auto_renewal' === $type
					&& false === get_option( self::WCS_MASTER_SWITCH_OPTION, false )
				) {
					update_option( self::WCS_MASTER_SWITCH_OPTION, 'yes' );
				}
			}

			// Mark as processed. Reached either because the publisher
			// already had a decision (isset above) or because the enable
			// write just succeeded — both are "considered, don't revisit".
			// A failed write took the `continue` above and is left
			// unprocessed so it can retry.
			$processed[] = $type;
			$changed     = true;
		}

		if ( $changed ) {
			// autoload=false: read once per admin pageload, not on every page load.
			update_option( self::FIRST_RUN_OPTION, $processed, false );
		}
	}

	/**
	 * Build a wizard response row for a WooCommerce-source config entry.
	 *
	 * Resolves the live `WC_Email` instance on-demand from
	 * {@see WooCommerce_Emails::get_wc_email_by_id()} (memoized; one
	 * mailer init per request). Returns null when the mailer doesn't
	 * have the id — caller skips the row.
	 *
	 * Read the enabled state from the option rather than the in-memory
	 * `WC_Email::$enabled` property — same-request writes to the option
	 * (toggle endpoint, first-run auto-enable) may not be reflected on
	 * the cached instance returned by WC()->mailer()->get_emails().
	 * Falls back to `WC_Email::is_enabled()` (which reads the property
	 * and runs the per-id `woocommerce_email_enabled_*` filter) when
	 * the option key hasn't been written yet.
	 *
	 * The class name for the edit link is read from the config's
	 * `wc_email_class` field — same value as `get_class($wc_email)`,
	 * but avoids a runtime reflection call.
	 *
	 * @param string $type   Config key (equals WC_Email->id).
	 * @param array  $config Unified config entry from newspack_email_configs.
	 * @return ?array Wizard response row, or null if the mailer doesn't know the id.
	 */
	private static function serialize_wc_email_row( string $type, array $config ): ?array {
		$wc_email = WooCommerce_Emails::get_wc_email_by_id( $type );
		if ( ! $wc_email ) {
			return null;
		}

		$option_key = $wc_email->get_option_key();
		$wc_options = (array) get_option( $option_key, [] );
		$is_enabled = isset( $wc_options['enabled'] )
			? 'yes' === $wc_options['enabled']
			: $wc_email->is_enabled();

		// Resolve the block-editor template post ID once — used for both
		// the edit-link (block-editor route when available) and the
		// preview-id smart fallback below. Doing it twice per row would
		// re-pay the option read + class_exists + WC posts-manager DB
		// query for no gain.
		$block_template_post_id = self::get_wc_email_template_post_id( $type );

		// Smart-fallback preview identifier — if a block-editor template
		// post exists for this WC email, emit its integer post ID so the
		// preview endpoint can render via the block path. Otherwise emit
		// the `wc:{id}` string so it routes to WC's classic render
		// (Email_Preview::get_wc_classic_preview_html). Always one of
		// integer | string — never null on WC rows.
		$preview_id = $block_template_post_id ?? ( 'wc:' . $type );

		return [
			'type'                => $type,
			'category'            => 'woocommerce',
			'label'               => $config['label'] ?? '',
			'description'         => $config['description'] ?? ( $config['trigger_description'] ?? '' ),
			'post_id'             => 'wc:' . $type,
			'edit_link'           => self::get_wc_email_edit_link( $block_template_post_id, $config['wc_email_class'] ?? get_class( $wc_email ) ),
			'subject'             => '',
			'from_name'           => '',
			'from_email'          => '',
			'reply_to_email'      => '',
			'status'              => $is_enabled ? 'publish' : 'draft',
			'html_payload'        => '',
			'trigger_description' => $config['trigger_description'] ?? '',
			'recipient'           => $config['recipient'] ?? 'reader',
			'recommended'         => $config['recommended'] ?? false,
			'chip'                => $config['chip'] ?? 'auth-account',
			'source'              => 'woocommerce',
			'preview_id'          => $preview_id,
		];
	}

	/**
	 * Resolve the block-editor template post ID for a WooCommerce email.
	 *
	 * Returns null when the WC block email editor is disabled, when the
	 * WC posts-manager class isn't loaded, or when no template post
	 * exists for this email ID. Self-contained — depends only on WC core
	 * (the option and the posts-manager class).
	 *
	 * Public because Email_Preview consumes it from outside this class
	 * when validating the `wc:{id}` route path — needs to decide between
	 * block-editor render (template post present) and classic render
	 * (no template post). serialize_wc_email_row() also consumes it
	 * to emit the smart-fallback preview_id field.
	 *
	 * @param string $wc_email_id The WC_Email instance ID.
	 * @return ?int Template post ID, or null.
	 */
	public static function get_wc_email_template_post_id( string $wc_email_id ): ?int {
		if ( 'yes' !== get_option( 'woocommerce_feature_block_email_editor_enabled' ) ) {
			return null;
		}

		$posts_manager_class = 'Automattic\\WooCommerce\\Internal\\EmailEditor\\WCTransactionalEmails\\WCTransactionalEmailPostsManager';
		if ( ! class_exists( $posts_manager_class ) ) {
			return null;
		}

		$template_post_id = $posts_manager_class::get_instance()->get_email_template_post_id( $wc_email_id );
		if ( empty( $template_post_id ) ) {
			return null;
		}

		return (int) $template_post_id;
	}

	/**
	 * Build the admin edit link for a WooCommerce email.
	 *
	 * Routes to the block editor template post when one exists (the caller
	 * resolves the template_post_id from get_wc_email_template_post_id —
	 * passed in here so a single resolution serves both this and the
	 * `preview_post_id` field on the row), otherwise falls back to the
	 * classic WC settings page filtered to the email's section.
	 *
	 * @param int|null $template_post_id Block-editor template post ID, or null.
	 * @param string   $wc_email_class   Fully-qualified WC_Email subclass name.
	 * @return string Admin URL.
	 */
	private static function get_wc_email_edit_link( ?int $template_post_id, string $wc_email_class ): string {
		if ( $template_post_id ) {
			return add_query_arg(
				[
					'post'   => $template_post_id,
					'action' => 'edit',
				],
				admin_url( 'post.php' )
			);
		}

		return add_query_arg(
			[
				'page'    => 'wc-settings',
				'tab'     => 'email',
				'section' => strtolower( $wc_email_class ),
			],
			admin_url( 'admin.php' )
		);
	}
}
