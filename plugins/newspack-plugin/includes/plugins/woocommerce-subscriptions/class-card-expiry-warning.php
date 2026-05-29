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
 *   sent to, marks each as already-warned via SENT_META, and writes
 *   the seeded flag — all WITHOUT sending. On subsequent scheduled
 *   runs, the normal scan proceeds.
 *
 *   This protects publishers from a Day 0 mass-email burst. Sites
 *   that DO want to send the deferred warnings (publisher-initiated
 *   explicit action) can run:
 *
 *     wp newspack card-expiry-warning-backfill
 *
 *   The CLI command passes a $bypass_idempotency flag to
 *   maybe_send_warning() so the seeded SENT_META doesn't block the
 *   send. See Newspack\CLI\WooCommerce_Subscriptions for the command.
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
	 * Subscription meta key for idempotency tracking.
	 */
	const SENT_META = '_newspack_card_expiry_warning_sent';

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
	 * SENT_META, and writes the SEEDED_OPTION flag — WITHOUT sending
	 * anything. Logs the result via Newspack\Logger.
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
			$expiry_key = $token->get_id() . ':' . $token->get_expiry_month() . '/' . $token->get_expiry_year();
			$pair['subscription']->update_meta_data( self::SENT_META, $expiry_key );
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
	 * Send the expiry warning for a subscription.
	 *
	 * Steady-state behavior uses per-subscription meta for idempotency:
	 * the meta value encodes `token_id:expiry_month/year`, so it
	 * auto-invalidates when the payment method changes or for a new
	 * expiry cycle.
	 *
	 * The WP-CLI backfill command passes `$bypass_idempotency=true` so
	 * that the seeded SENT_META (from the first-deploy seed pass) doesn't
	 * block its explicit publisher-initiated sends.
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
	 *                                                 SENT_META check.
	 * @return bool Whether the email was sent.
	 */
	public static function maybe_send_warning( $subscription, $token, bool $bypass_idempotency = false ): bool {
		$expiry_key = $token->get_id() . ':'
			. $token->get_expiry_month() . '/' . $token->get_expiry_year();

		// Idempotency: skip if we already sent for this token+expiry combo,
		// unless the caller is an explicit publisher-initiated backfill.
		if ( ! $bypass_idempotency && $subscription->get_meta( self::SENT_META, true ) === $expiry_key ) {
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
			$subscription->update_meta_data( self::SENT_META, $expiry_key );
			$subscription->save();
		}
		return (bool) $sent;
	}

	/**
	 * Clear the sent flag when the payment method is updated on a subscription.
	 *
	 * Hooked to 'woocommerce_subscription_payment_method_updated'.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 */
	public static function clear_sent_flag( $subscription ) {
		$subscription->delete_meta_data( self::SENT_META );
		$subscription->save();
	}
}
