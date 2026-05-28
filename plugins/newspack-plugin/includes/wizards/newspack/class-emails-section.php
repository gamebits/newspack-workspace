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
	 * @var string
	 */
	protected $wizard_slug = 'newspack-settings';

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
			'wizard/' . $this->wizard_slug . '/emails',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_email_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		// Toggle endpoint for WooCommerce-source emails. Only registered
		// when WC is loaded; without WC there are no WC configs to toggle.
		if ( self::is_woocommerce_active() ) {
			register_rest_route(
				NEWSPACK_API_NAMESPACE,
				'wizard/' . $this->wizard_slug . '/emails/(?P<id>[A-Za-z0-9_]+)/toggle',
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

		$configs   = Emails::get_email_configs();
		$wc_config = $configs[ $wc_email_id ] ?? null;
		if ( ! $wc_config || 'woocommerce' !== ( $wc_config['source'] ?? 'newspack' ) ) {
			return new \WP_Error(
				'newspack_wc_email_not_allowed',
				__( 'WooCommerce email is not in the surfaced allowlist.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$wc_email = WooCommerce_Emails::get_wc_email_by_id( $wc_email_id );
		if ( ! $wc_email ) {
			return new \WP_Error(
				'newspack_wc_email_not_allowed',
				__( 'WooCommerce email is not in the surfaced allowlist.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		// In-memory write first — keeps the cached mailer instance's
		// $enabled property in sync for any downstream code in the same
		// request that reads it directly rather than going through the
		// option.
		$wc_email->enabled = $enabled ? 'yes' : 'no';

		// Option write — authoritative source. serialize_wc_email_row()
		// reads from this option, so the refreshed api_get_email_settings()
		// response below reflects the toggled state.
		$option_key         = $wc_email->get_option_key();
		$options            = (array) get_option( $option_key, [] );
		$options['enabled'] = $enabled ? 'yes' : 'no';
		update_option( $option_key, $options );

		return rest_ensure_response( self::api_get_email_settings() );
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
	 *         preview_post_id?:    ?int,
	 *     }>,
	 *     post_type: string,
	 * }
	 */
	public static function api_get_email_settings(): array {
		self::maybe_first_run_enable_wc_emails();

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
	 * Special case: `customer_notification_auto_renewal` requires the WC
	 * Subscriptions master switch
	 * (`woocommerce_subscriptions_customer_notifications_enabled`) to
	 * also be on — otherwise the email never fires regardless of its own
	 * flag. We enable the master switch only when the option doesn't
	 * exist in the DB (`false === get_option(..., false)`). A publisher
	 * who explicitly disabled it is making a site-wide policy choice
	 * about all WCS customer notifications, not just this one email; we
	 * do not silently reverse that.
	 */
	private static function maybe_first_run_enable_wc_emails(): void {
		if ( ! self::is_woocommerce_active() ) {
			return;
		}

		$processed = (array) get_option( self::FIRST_RUN_OPTION, [] );
		$configs   = Emails::get_email_configs();
		$changed   = false;

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
			$wc_email = WooCommerce_Emails::get_wc_email_by_id( $type );
			if ( ! $wc_email ) {
				continue;
			}

			$option_key = $wc_email->get_option_key();
			$options    = (array) get_option( $option_key, [] );

			// Only write when the publisher hasn't recorded a decision
			// yet. An explicit 'no' is preserved.
			if ( ! isset( $options['enabled'] ) ) {
				$wc_email->enabled  = 'yes';
				$options['enabled'] = 'yes';
				update_option( $option_key, $options );
			}

			// WCS master switch: enable only when not present in the DB.
			// `get_option(..., false)` returns the default `false` only
			// when the option row doesn't exist — an explicit `'no'`
			// returns `'no'` and is preserved.
			if (
				'customer_notification_auto_renewal' === $type
				&& false === get_option( 'woocommerce_subscriptions_customer_notifications_enabled', false )
			) {
				update_option( 'woocommerce_subscriptions_customer_notifications_enabled', 'yes' );
			}

			// Mark as processed regardless of whether we wrote — once we
			// considered the slug, we don't reconsider it. Without this,
			// a publisher with explicit 'no' would have us re-evaluate
			// (and skip) the slug on every wizard load.
			$processed[] = $type;
			$changed     = true;
		}

		if ( $changed ) {
			// autoload=false: read once per wizard request, not on every page load.
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

		return [
			'type'                => $type,
			'category'            => 'woocommerce',
			'label'               => $config['label'] ?? '',
			'description'         => $config['description'] ?? ( $config['trigger_description'] ?? '' ),
			'post_id'             => 'wc:' . $type,
			'edit_link'           => self::get_wc_email_edit_link( $type, $config['wc_email_class'] ?? get_class( $wc_email ) ),
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
			'registry_slug'       => $type,
			'preview_post_id'     => self::get_wc_email_template_post_id( $type ),
		];
	}

	/**
	 * Resolve the block-editor template post ID for a WooCommerce email.
	 *
	 * Returns null when the WC block email editor is disabled, when the
	 * WC posts-manager class isn't loaded, or when no template post
	 * exists for this email ID. Self-contained — depends only on WC core
	 * (the option and the posts-manager class). No Email_Preview machinery
	 * involved; that ships with slice 2b.
	 *
	 * @param string $wc_email_id The WC_Email instance ID.
	 * @return ?int Template post ID, or null.
	 */
	private static function get_wc_email_template_post_id( string $wc_email_id ): ?int {
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
	 * Routes to the block editor template post when one exists (and the
	 * WC block email editor is enabled), otherwise falls back to the
	 * classic WC settings page filtered to the email's section.
	 *
	 * @param string $wc_email_id    The WC_Email instance ID.
	 * @param string $wc_email_class Fully-qualified WC_Email subclass name.
	 * @return string Admin URL.
	 */
	private static function get_wc_email_edit_link( string $wc_email_id, string $wc_email_class ): string {
		$classic_url = add_query_arg(
			[
				'page'    => 'wc-settings',
				'tab'     => 'email',
				'section' => strtolower( $wc_email_class ),
			],
			admin_url( 'admin.php' )
		);

		$template_post_id = self::get_wc_email_template_post_id( $wc_email_id );
		if ( ! $template_post_id ) {
			return $classic_url;
		}

		return add_query_arg(
			[
				'post'   => $template_post_id,
				'action' => 'edit',
			],
			admin_url( 'post.php' )
		);
	}
}
