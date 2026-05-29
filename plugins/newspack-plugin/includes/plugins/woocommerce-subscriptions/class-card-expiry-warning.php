<?php
/**
 * Card Expiry Warning Email.
 *
 * Sends a warning email when a reader's saved credit card is about to expire
 * on an active WooCommerce Subscription. Runs a daily cron scan to find
 * expiring CC tokens and notifies the subscription owner.
 *
 * Publisher-respect — first-deploy seed:
 *
 *   On the very first scheduled scan after install (detected via the
 *   absence of `newspack_card_expiry_warning_seeded` option), this class
 *   runs a SEED pass instead of a normal scan: it iterates the same
 *   in-window (subscription, token) pairs the normal scan would have
 *   sent to, marks each as already-warned via a per-token SEEDED meta
 *   entry (see SEEDED_META_PREFIX), and writes the seeded option flag
 *   — all WITHOUT sending. On subsequent scheduled runs, the normal
 *   scan proceeds.
 *
 *   This protects publishers from a Day 0 mass-email burst. Sites
 *   that DO want to send the deferred warnings (publisher-initiated
 *   explicit action) can run:
 *
 *     wp newspack card-expiry-warning-backfill
 *
 *   The CLI command passes a $bypass_idempotency flag to
 *   maybe_send_warning() so the per-token SEEDED meta doesn't block
 *   the send. The SENT meta (SENT_META_PREFIX) still blocks even
 *   under bypass, so CLI re-runs on the same window are silently
 *   idempotent — see is_already_processed() for the gating logic.
 *   See Newspack\CLI\WooCommerce_Subscriptions for the command.
 *
 * Publisher-respect — per-pass SQL LIMIT:
 *
 *   The discovery query carries a SQL-level LIMIT (filterable via
 *   `newspack_card_expiry_warning_limit_per_pass`, default 100) so a
 *   migration day or unusual burst can't load unbounded rows into
 *   memory. A site exceeding the cap on a given day will see the
 *   remaining warnings roll into subsequent cron runs — sustained-
 *   load is fine; this only affects bursts and migrations.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Card Expiry Warning class.
 */
class Card_Expiry_Warning {

	/**
	 * Email type identifier used in the Newspack email config system.
	 */
	const EMAIL_TYPE = 'card-expiry-warning';

	/**
	 * Cron hook name for the daily expiry scan.
	 */
	const CRON_HOOK = 'newspack_card_expiry_warning_scan';

	/**
	 * Per-token meta prefix for the seed pass marker.
	 *
	 * Written by `seed_in_window_pairs()` to record that a (subscription,
	 * token) pair was in-window at the first-deploy seed but was NOT sent
	 * — see the class docblock's "Publisher-respect — first-deploy seed"
	 * section. The CLI backfill bypasses the SEEDED gate (operator opt-in
	 * to release deferred warnings) but NOT the SENT gate (see
	 * SENT_META_PREFIX).
	 *
	 * Per-token suffix (not a single per-subscription key) so a
	 * subscription with multiple in-window CC tokens has independent
	 * suppression state per token. The earlier single-key shape collapsed
	 * all tokens onto one meta value and lost suppression for all but the
	 * last-iterated token — see NPPD-1568.
	 *
	 * Full meta key = `self::SEEDED_META_PREFIX . $token->get_id()`.
	 */
	const SEEDED_META_PREFIX = '_newspack_card_expiry_warning_seeded_';

	/**
	 * Per-token meta prefix for the actual-send marker.
	 *
	 * Written after a successful `Emails::send_email()` call. Always
	 * blocks re-sends — even when `$bypass_idempotency=true`. This makes
	 * the CLI backfill silently idempotent across operator re-runs on
	 * the same window (NPPD-1568): a second invocation hits the SENT
	 * gate for every pair the first invocation completed.
	 *
	 * Invariant: at any moment, a (subscription, token) pair has at most
	 * ONE of {SEEDED, SENT}, never both. On a CLI-driven release of a
	 * seeded pair, the SEEDED meta is deleted as SENT is written.
	 *
	 * Full meta key = `self::SENT_META_PREFIX . $token->get_id()`.
	 */
	const SENT_META_PREFIX = '_newspack_card_expiry_warning_sent_';

	/**
	 * Option flagging that the first-deploy seed pass has run.
	 *
	 * Stored with autoload=false so it doesn't sit in alloptions on
	 * every pageload.
	 */
	const SEEDED_OPTION = 'newspack_card_expiry_warning_seeded';

	/**
	 * Default per-pass cap on the discovery query.
	 *
	 * Filterable via `newspack_card_expiry_warning_limit_per_pass`.
	 * Applied at the SQL level — see get_expiring_cc_tokens().
	 */
	const LIMIT_PER_PASS_DEFAULT = 100;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Register the deactivation cleanup outside the is_enabled() guard
		// so a cron event that was scheduled on an earlier request (when
		// WC Subs + RA were both enabled) still gets cleared on plugin
		// deactivation, even if WCS or RA was disabled in between.
		add_action( 'newspack_deactivation', [ __CLASS__, 'unschedule_cron' ] );

		if ( ! WooCommerce_Subscriptions::is_enabled() ) {
			return;
		}

		add_filter( 'newspack_email_configs', [ __CLASS__, 'add_email_config' ] );
		add_action( 'init', [ __CLASS__, 'schedule_cron' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'scan_expiring_cards' ] );
		add_action( 'woocommerce_subscription_payment_method_updated', [ __CLASS__, 'clear_sent_flag' ] );
	}

	/**
	 * Unschedule the cron event on plugin deactivation.
	 */
	public static function unschedule_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Get the number of days before expiry to send the warning.
	 *
	 * @return int Days before card expiry.
	 */
	public static function get_days_before_expiry(): int {
		/**
		 * Filters the number of days before card expiry to send the warning email.
		 *
		 * @param int $days Default 14.
		 */
		return max( 1, (int) apply_filters( 'newspack_card_expiry_warning_days', 14 ) );
	}

	/**
	 * Get the per-pass discovery-query cap.
	 *
	 * Applied at the SQL level (see get_expiring_cc_tokens) so we
	 * don't pull unbounded rows into PHP memory on a burst day. Sites
	 * exceeding the cap on a given day will see remaining warnings
	 * roll into subsequent cron runs.
	 *
	 * @return int Max tokens to consider per pass.
	 */
	public static function get_limit_per_pass(): int {
		/**
		 * Filters the per-pass cap on the discovery query for the
		 * card-expiry warning scan.
		 *
		 * @param int $limit Default 100.
		 */
		return max( 1, (int) apply_filters( 'newspack_card_expiry_warning_limit_per_pass', self::LIMIT_PER_PASS_DEFAULT ) );
	}

	/**
	 * Register the email configuration.
	 *
	 * The four UI-metadata fields (`recommended`, `recipient`, `chip`,
	 * `trigger_description`) are declared explicitly. Slice 1's
	 * `Emails::apply_config_defaults()` would auto-fill defaults for
	 * `recipient` and derive `chip` from `category`, but explicit
	 * declaration keeps this provider's UI surface visible at the
	 * registration site rather than buried in inheritance.
	 *
	 * @param array $configs Existing email configs.
	 * @return array Modified email configs.
	 */
	public static function add_email_config( $configs ) {
		$configs[ self::EMAIL_TYPE ] = [
			'name'                   => self::EMAIL_TYPE,
			'category'               => 'reader-revenue',
			'chip'                   => 'reader-revenue',
			'recipient'              => 'reader',
			'recommended'            => true,
			'label'                  => __( 'Card expiry warning', 'newspack-plugin' ),
			'description'            => __( "Email sent when a reader's saved payment method is about to expire.", 'newspack-plugin' ),
			'trigger_description'    => __( "Sent when a reader's saved payment method is about to expire on an active subscription.", 'newspack-plugin' ),
			'template'               => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/card-expiry-warning.php',
			'editor_notice'          => __( 'This email will be sent to readers when their saved credit card is about to expire on an active subscription.', 'newspack-plugin' ),
			'from_email'             => Reader_Revenue_Emails::get_from_email(),
			'available_placeholders' => [
				[
					'label'    => __( 'the customer billing first name', 'newspack-plugin' ),
					'template' => '*BILLING_FIRST_NAME*',
				],
				[
					'label'    => __( 'the last four digits of the expiring card', 'newspack-plugin' ),
					'template' => '*CARD_LAST_4*',
				],
				[
					'label'    => __( 'the card expiry date (MM/YYYY)', 'newspack-plugin' ),
					'template' => '*EXPIRY_DATE*',
				],
				[
					'label'    => __( 'the next renewal date', 'newspack-plugin' ),
					'template' => '*RENEWAL_DATE*',
				],
				[
					'label'    => __( 'link to update payment method', 'newspack-plugin' ),
					'template' => '*UPDATE_PAYMENT_URL*',
				],
				[
					'label'    => __(
						'the contact email to your site (same as the "From" email address)',
						'newspack-plugin'
					),
					'template' => '*CONTACT_EMAIL*',
				],
				[
					'label'    => __( 'the site title', 'newspack-plugin' ),
					'template' => '*SITE_TITLE*',
				],
				[
					'label'    => __( 'the site url', 'newspack-plugin' ),
					'template' => '*SITE_URL*',
				],
			],
		];
		return $configs;
	}

	/**
	 * Schedule the daily cron event if not already scheduled.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Defer the first run by 24h so publishers get an opt-in
			// window after install to review the email template / flip
			// the email post to draft before the seed pass writes meta.
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Scan for expiring credit cards and send warning emails.
	 *
	 * On the first scheduled run after install (SEEDED_OPTION absent),
	 * runs a seed pass instead — see the seed_in_window_pairs() docblock
	 * and the class-level docblock for the publisher-respect rationale.
	 */
	public static function scan_expiring_cards() {
		if ( ! Emails::can_send_email( self::EMAIL_TYPE ) ) {
			return;
		}

		if ( ! get_option( self::SEEDED_OPTION ) ) {
			self::seed_in_window_pairs();
			return;
		}

		$pairs = self::get_in_window_pairs(
			self::get_days_before_expiry(),
			self::get_limit_per_pass()
		);
		foreach ( $pairs as $pair ) {
			// Per-pair try/catch so a single throwing pair (e.g. an SMTP
			// filter rejecting a malformed address, a third-party WC hook
			// that throws on save) doesn't abort the rest of the pass and
			// skip every later pair until tomorrow's cron.
			try {
				self::maybe_send_warning( $pair['subscription'], $pair['token'] );
			} catch ( \Throwable $e ) {
				Logger::log(
					sprintf(
						'Card expiry warning send failed for subscription %d: %s',
						$pair['subscription']->get_id(),
						$e->getMessage()
					),
					'NEWSPACK-CARD-EXPIRY',
					'error'
				);
				continue;
			}
		}
	}

	/**
	 * First-deploy seed pass.
	 *
	 * Iterates every currently-in-window (subscription, token) pair the
	 * normal scan would have sent to, marks each as already-warned via
	 * a per-token SEEDED meta entry, and writes the SEEDED_OPTION flag —
	 * WITHOUT sending anything. Logs the result via Newspack\Logger.
	 *
	 * Sites that DO want to send the deferred warnings should run the
	 * WP-CLI backfill (see class docblock).
	 */
	private static function seed_in_window_pairs() {
		// Use PHP_INT_MAX (effectively no SQL LIMIT) so the seed marks
		// EVERY currently-in-window pair, not just the per-pass cap. The
		// per-pass cap exists to bound steady-state cron memory; the seed
		// runs once per install and MUST cover the full window or the
		// un-seeded remainder leaks into the next cron's normal-scan
		// branch — exactly the Day-0 burst the seed is built to prevent.
		$pairs = self::get_in_window_pairs(
			self::get_days_before_expiry(),
			PHP_INT_MAX
		);
		$count = 0;
		foreach ( $pairs as $pair ) {
			$token      = $pair['token'];
			$token_id   = $token->get_id();
			$expiry_key = $token_id . ':' . $token->get_expiry_month() . '/' . $token->get_expiry_year();
			// Per-token meta key (NPPD-1568): a subscription with multiple
			// in-window CC tokens gets independent suppression state per
			// token. The earlier single-key shape collapsed all tokens
			// onto one value and lost suppression for all but the last
			// iterated token.
			$pair['subscription']->update_meta_data( self::SEEDED_META_PREFIX . $token_id, $expiry_key );
			$pair['subscription']->save();
			++$count;
		}
		// autoload=false so this option doesn't sit in alloptions on every pageload.
		update_option( self::SEEDED_OPTION, '1', false );

		$message = sprintf(
			'Card expiry warning first-deploy seed: marked %d (subscription, token) pair(s) as already-warned without sending. Run `wp newspack card-expiry-warning-backfill` to send the deferred warnings.',
			$count
		);

		// Logger::log is gated by NEWSPACK_LOG_LEVEL (off on most
		// production sites). Also fire newspack_log so the event is
		// visible to Newspack Manager and any other listeners on the
		// action — the seed is a significant one-time event and must
		// be diagnostically discoverable.
		Logger::log( $message, 'NEWSPACK-CARD-EXPIRY', 'warning' );
		Logger::newspack_log(
			'card_expiry_warning_seeded',
			$message,
			[ 'pair_count' => $count ],
			'warning'
		);
	}

	/**
	 * Discover (subscription, token) pairs currently in the warning window.
	 *
	 * Returns at most `$limit` pairs (cap applied at the SQL level via
	 * get_expiring_cc_tokens). A site exceeding `$limit` will see
	 * remaining warnings roll into subsequent cron runs — sustained-load
	 * is fine; this only affects bursts and migrations.
	 *
	 * Public because the WP-CLI backfill needs to iterate the same set
	 * without duplicating the discovery logic.
	 *
	 * @param int $days  Window in days.
	 * @param int $limit Max tokens to consider.
	 * @return array<int, array{subscription: \WC_Subscription, token: \WC_Payment_Token_CC}>
	 */
	public static function get_in_window_pairs( int $days, int $limit ): array {
		if ( ! class_exists( 'WCS_Payment_Tokens' ) ) {
			return [];
		}
		$tokens = self::get_expiring_cc_tokens( $days, $limit );
		if ( empty( $tokens ) ) {
			return [];
		}
		$pairs = [];
		foreach ( $tokens as $token ) {
			$subscription_ids = \WCS_Payment_Tokens::get_subscriptions_from_token( $token );
			foreach ( $subscription_ids as $subscription_id ) {
				$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
				if ( ! $subscription || 'active' !== $subscription->get_status() ) {
					continue;
				}
				$pairs[] = [
					'subscription' => $subscription,
					'token'        => $token,
				];
			}
		}
		return $pairs;
	}

	/**
	 * Find CC tokens expiring within the given number of days, capped at $limit.
	 *
	 * Direct DB query on woocommerce_payment_tokenmeta joined to
	 * woocommerce_payment_tokens to filter at the DB level. The
	 * `LIMIT %d` is applied at the SQL level so we don't pull
	 * unbounded rows into PHP memory on a migration or burst day.
	 *
	 * @param int $days  Number of days in the warning window.
	 * @param int $limit Max number of token rows to return.
	 * @return \WC_Payment_Token_CC[] Array of expiring CC token objects (length <= $limit).
	 */
	private static function get_expiring_cc_tokens( int $days, int $limit ): array {
		global $wpdb;

		$today  = gmdate( 'Y-m-d' );
		$cutoff = gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS );

		// A card with expiry MM/YYYY is valid through the last day of that month.
		// Find tokens whose last-valid-day falls between today and $cutoff.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// LENGTH guard on expiry_year: WC_Payment_Token_CC::set_expiry_year
		// does not zero-pad (unlike set_expiry_month), so legacy 2-digit
		// values would produce a year-26 date through STR_TO_DATE and
		// silently fall outside the BETWEEN window. Filter them out at
		// the SQL level so we don't miss them — they can't be matched
		// here either way, but the guard makes the skip explicit.
		// ORDER BY token_id ASC for deterministic ordering across cron
		// runs (without it, MySQL is free to return any LIMIT-sized
		// subset and seeding/normal-scan handoffs become unstable on
		// sites that exceed the per-pass cap).
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.token_id
				FROM {$wpdb->prefix}woocommerce_payment_tokens t
				INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta em
					ON em.payment_token_id = t.token_id AND em.meta_key = 'expiry_month'
				INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta ey
					ON ey.payment_token_id = t.token_id AND ey.meta_key = 'expiry_year'
				WHERE t.type = 'CC'
					AND CHAR_LENGTH( ey.meta_value ) = 4
					AND LAST_DAY(
						STR_TO_DATE(
							CONCAT(ey.meta_value, '-', em.meta_value, '-01'),
							'%%Y-%%m-%%d'
						)
					) BETWEEN %s AND %s
				ORDER BY t.token_id ASC
				LIMIT %d",
				$today,
				$cutoff,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $token_ids ) ) {
			return [];
		}

		$tokens = [];
		foreach ( $token_ids as $token_id ) {
			$token = \WC_Payment_Tokens::get( (int) $token_id );
			if ( $token instanceof \WC_Payment_Token_CC ) {
				$tokens[] = $token;
			}
		}
		return $tokens;
	}

	/**
	 * Whether this (subscription, token, expiry) tuple has already been
	 * processed and should be skipped.
	 *
	 * Two-prefix schema (see SEEDED_META_PREFIX + SENT_META_PREFIX):
	 *
	 *   - SENT_META always blocks. Even with `$bypass_idempotency=true`,
	 *     a real prior send is never re-sent. This is what makes the CLI
	 *     backfill silently idempotent across operator re-runs.
	 *   - SEEDED_META blocks unless `$bypass_idempotency=true`. The seed
	 *     pass writes it without sending; the CLI bypass is the explicit
	 *     publisher opt-in to release the deferred warning.
	 *
	 * Value-match (`=== $expiry_key`) is intentional, not just key-
	 * existence. A token's `expiry_month`/`expiry_year` meta can change
	 * in place — e.g., a Stripe Card Account Updater webhook reissues
	 * the same `token_id` with a new expiry — and the value-match
	 * invalidates the stale mark so the next expiry cycle gets warned.
	 * Replacing this with `metadata_exists()` would silently block the
	 * new warning. Do NOT simplify to existence-only.
	 *
	 * @param \WC_Subscription $subscription       The subscription.
	 * @param int              $token_id           The CC token id.
	 * @param string           $expiry_key         `token_id:MM/YYYY`.
	 * @param bool             $bypass_idempotency When true, ignore the SEEDED gate (SENT still blocks).
	 * @return bool True if already processed (skip), false if proceed.
	 */
	private static function is_already_processed( $subscription, int $token_id, string $expiry_key, bool $bypass_idempotency = false ): bool {
		if ( $subscription->get_meta( self::SENT_META_PREFIX . $token_id, true ) === $expiry_key ) {
			return true;
		}
		if ( ! $bypass_idempotency && $subscription->get_meta( self::SEEDED_META_PREFIX . $token_id, true ) === $expiry_key ) {
			return true;
		}
		return false;
	}

	/**
	 * Send the expiry warning for a subscription.
	 *
	 * Idempotency: gated by `is_already_processed()`, which checks
	 * per-token SEEDED + SENT meta with value-match against the current
	 * expiry tuple. See that method's docblock for the schema rationale.
	 *
	 * The WP-CLI backfill passes `$bypass_idempotency=true` to release
	 * seed-suppressed warnings — but SENT still blocks, so a re-run of
	 * the CLI on the same window is a no-op against the prior send.
	 *
	 * @internal Public only so the WP-CLI backfill in
	 *           `Newspack\CLI\WooCommerce_Subscriptions::card_expiry_warning_backfill()`
	 *           can pass the bypass flag. Not part of the stable public
	 *           API — external callers should not depend on this
	 *           signature.
	 *
	 * @param \WC_Subscription     $subscription       The subscription.
	 * @param \WC_Payment_Token_CC $token              The expiring CC token.
	 * @param bool                 $bypass_idempotency When true, skip the
	 *                                                 SEEDED gate. The SENT
	 *                                                 gate still blocks.
	 * @return bool Whether the email was sent.
	 */
	public static function maybe_send_warning( $subscription, $token, bool $bypass_idempotency = false ): bool {
		$token_id   = $token->get_id();
		$expiry_key = $token_id . ':' . $token->get_expiry_month() . '/' . $token->get_expiry_year();

		if ( self::is_already_processed( $subscription, $token_id, $expiry_key, $bypass_idempotency ) ) {
			return false;
		}

		$customer = $subscription->get_user();
		if ( ! $customer ) {
			return false;
		}

		$update_url   = \wc_get_account_endpoint_url( 'payment-methods' );
		$next_payment = $subscription->get_date( 'next_payment' );
		// Use wp_date() — not date_i18n() — so the GMT timestamp from
		// WC_Subscription::get_time() is correctly converted into the
		// site's timezone for display. date_i18n() carries a legacy
		// quirk that misinterprets GMT timestamps for sites whose
		// timezone straddles the UTC date boundary.
		$renewal_date = $next_payment
			? wp_date( get_option( 'date_format', 'F j, Y' ), $subscription->get_time( 'next_payment' ) )
			: __( 'your next renewal', 'newspack-plugin' );

		$first_name = $subscription->get_billing_first_name();
		if ( '' === $first_name ) {
			$first_name = $customer->first_name;
		}

		$placeholders = [
			[
				'template' => '*BILLING_FIRST_NAME*',
				'value'    => esc_html( $first_name ),
			],
			[
				'template' => '*CARD_LAST_4*',
				'value'    => esc_html( $token->get_last4() ),
			],
			[
				'template' => '*EXPIRY_DATE*',
				'value'    => esc_html( $token->get_expiry_month() . '/' . $token->get_expiry_year() ),
			],
			[
				'template' => '*RENEWAL_DATE*',
				'value'    => esc_html( $renewal_date ),
			],
			[
				'template' => '*UPDATE_PAYMENT_URL*',
				'value'    => esc_url( $update_url ),
			],
		];

		$sent = Emails::send_email(
			self::EMAIL_TYPE,
			$subscription->get_billing_email(),
			$placeholders
		);

		if ( $sent ) {
			// Promote SEEDED → SENT: delete the seed mark first so the
			// invariant holds (at most one of {SEEDED, SENT} per token).
			// `delete_meta_data` is a no-op when the key is absent, so
			// this is safe whether the pair was previously seeded or not.
			$subscription->delete_meta_data( self::SEEDED_META_PREFIX . $token_id );
			$subscription->update_meta_data( self::SENT_META_PREFIX . $token_id, $expiry_key );
			$subscription->save();
		}
		return (bool) $sent;
	}

	/**
	 * Clear the sent flag when the payment method is updated on a subscription.
	 *
	 * Hooked to 'woocommerce_subscription_payment_method_updated'. Clears
	 * BOTH SEEDED and SENT per-token entries on the subscription via
	 * `get_meta_data()` iteration — WC CRUD pattern, composes correctly
	 * with WC's meta cache and the `woocommerce_after_save_subscription_meta`
	 * hook chain. (LIKE-query on `wp_postmeta` would be faster on huge
	 * meta tables but skirts WC's CRUD layer.)
	 *
	 * `$changed` guard prevents firing `save()` (and the hooks it
	 * triggers) when no per-token meta matched.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 */
	public static function clear_sent_flag( $subscription ) {
		$changed = false;
		foreach ( $subscription->get_meta_data() as $meta ) {
			$key = $meta->key;
			if ( 0 === strpos( $key, self::SEEDED_META_PREFIX ) || 0 === strpos( $key, self::SENT_META_PREFIX ) ) {
				$subscription->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$subscription->save();
		}
	}
}
