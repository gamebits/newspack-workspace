<?php
/**
 * Enable Woos block email editor.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request, WP_REST_Response, WP_REST_Server;

/**
 * WooCommerce Emails class.
 */
class WooCommerce_Emails {
	/**
	 * Option to track if the feature is enabled.
	 *
	 * @var string
	 */
	const WOOCOMMERCE_EMAIL_EDITOR_OPTION = 'newspack_woocommerce_feature_block_email_editor_enabled';

	/**
	 * Option to determine whether email templates have been updated.
	 *
	 * @var string
	 */
	const WOOCOMMERCE_EMAILS_UPDATED_OPTION = 'newspack_woocommerce_block_editor_emails_updated_to_latest';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'option_woocommerce_feature_block_email_editor_enabled', [ __CLASS__, 'override_woocommerce_email_editor_option' ], 10, 2 );
		add_action( 'admin_init', [ __CLASS__, 'update_woocommerce_emails_to_latest' ] );
		add_filter( 'newspack_email_configs', [ __CLASS__, 'get_email_configs' ] );
	}

	/**
	 * Curated allowlist of WooCommerce-source emails surfaced in the
	 * unified emails wizard, keyed by `WC_Email::$id`. Wrapped in a
	 * function so `__()` runs at filter time, not class-load time.
	 *
	 * The `class` field carries the WC_Email subclass name as a scalar
	 * string. Storing the class name (not a live instance) keeps the
	 * `newspack_email_configs` schema JSON-serializable and avoids
	 * re-instantiating WC_Email objects across requests — the live
	 * mailer-owned instance is resolved on demand via
	 * {@see get_wc_email_by_id()}.
	 *
	 * @return array<string, array{
	 *     class: string,
	 *     chip: 'auth-account'|'reader-revenue',
	 *     recipient: 'reader'|'admin',
	 *     recommended: bool,
	 *     plugin_dependency: ?string,
	 *     label: string,
	 *     trigger_description: string,
	 * }>
	 */
	private static function surfaced_wc_emails(): array {
		return [
			'customer_notification_auto_renewal' => [
				'class'               => 'WCS_Email_Customer_Notification_Auto_Renewal',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => 'woocommerce-subscriptions',
				'label'               => __( 'Renewal reminder', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent before automatic renewal (timing depends on WooCommerce Subscriptions settings).', 'newspack-plugin' ),
			],
			'customer_payment_retry'             => [
				'class'               => 'WCS_Email_Customer_Payment_Retry',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => 'woocommerce-subscriptions',
				'label'               => __( 'Failed order retry', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent when a renewal payment fails, before the retry attempt.', 'newspack-plugin' ),
			],
			'expired_subscription'               => [
				'class'               => 'WCS_Email_Expired_Subscription',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => 'woocommerce-subscriptions',
				'label'               => __( 'Subscription expired', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent when a subscription reaches its expiration date.', 'newspack-plugin' ),
			],
			'customer_completed_switch_order'    => [
				'class'               => 'WCS_Email_Completed_Switch_Order',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => 'woocommerce-subscriptions',
				'label'               => __( 'Subscription switch complete', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent when a reader switches their subscription.', 'newspack-plugin' ),
			],
			'WCSG_Email_Customer_New_Account'    => [
				'class'               => 'WCSG_Email_Customer_New_Account',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => 'woocommerce-subscriptions',
				'label'               => __( 'New giftee account', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent to the giftee when a gift subscription creates their account.', 'newspack-plugin' ),
			],
			'recipient_completed_order'          => [
				// Note: the id is `recipient_completed_order` but the WCSG
				// class name is `Recipient_New_Initial_Order`. Class and id
				// don't follow the same naming pattern here — the entry is
				// keyed by id (the mailer-emitted slug) and `class` carries
				// the actual subclass name.
				'class'               => 'WCSG_Email_Recipient_New_Initial_Order',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => 'woocommerce-subscriptions',
				'label'               => __( 'New gift order', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent to the giftee to notify them of a gift subscription.', 'newspack-plugin' ),
			],
			'customer_new_account'               => [
				'class'               => 'WC_Email_Customer_New_Account',
				'chip'                => 'auth-account',
				'recipient'           => 'reader',
				'recommended'         => true,
				'plugin_dependency'   => null,
				'label'               => __( 'New account', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent when a customer creates a new account.', 'newspack-plugin' ),
			],
			'customer_refunded_order'            => [
				'class'               => 'WC_Email_Customer_Refunded_Order',
				'chip'                => 'reader-revenue',
				'recipient'           => 'reader',
				'recommended'         => false,
				'plugin_dependency'   => null,
				'label'               => __( 'Order refund', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent when an order is refunded.', 'newspack-plugin' ),
			],
			'new_order'                          => [
				'class'               => 'WC_Email_New_Order',
				'chip'                => 'reader-revenue',
				'recipient'           => 'admin',
				'recommended'         => false,
				'plugin_dependency'   => null,
				'label'               => __( 'New order', 'newspack-plugin' ),
				'trigger_description' => __( 'Sent to the admin when a new order is placed.', 'newspack-plugin' ),
			],
		];
	}

	/**
	 * Inject WooCommerce-source email configs into the unified
	 * `newspack_email_configs` filter set.
	 *
	 * Iterates the curated allowlist (see {@see surfaced_wc_emails()})
	 * directly — no `WC()->mailer()->get_emails()` call from the filter
	 * callback. The mailer is consulted on-demand from
	 * {@see get_wc_email_by_id()} when the toggle endpoint, first-run
	 * auto-enable, or serialization actually needs the live instance.
	 *
	 * Each entry carries `wc_email_class` (the WC_Email subclass name as
	 * a scalar string), NOT a live `WC_Email` instance — the schema
	 * stays JSON-serializable, and no WC objects get re-instantiated
	 * per call.
	 *
	 * @param array $configs Existing email configs from upstream providers.
	 * @return array Configs with surfaced WC emails added.
	 */
	public static function get_email_configs( $configs ) {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Emails' ) ) {
			return $configs;
		}
		foreach ( self::surfaced_wc_emails() as $id => $meta ) {
			if ( ! empty( $meta['plugin_dependency'] ) ) {
				$plugin_file = $meta['plugin_dependency'] . '/' . $meta['plugin_dependency'] . '.php';
				if ( ! \Newspack\is_plugin_active( $plugin_file ) ) {
					continue;
				}
			}
			$configs[ $id ] = [
				'name'                => $id,
				'category'            => 'woocommerce',
				'source'              => 'woocommerce',
				'label'               => $meta['label'],
				'description'         => $meta['trigger_description'],
				'trigger_description' => $meta['trigger_description'],
				'recipient'           => $meta['recipient'],
				'recommended'         => $meta['recommended'],
				'chip'                => $meta['chip'],
				'woo_email_id'        => $id,
				'plugin_dependency'   => $meta['plugin_dependency'],
				'wc_email_class'      => $meta['class'],
			];
		}
		return $configs;
	}

	/**
	 * Memoized lookup of the mailer-owned `WC_Email` instance for a
	 * surfaced email id.
	 *
	 * Calls `WC()->mailer()->get_emails()` once per request and caches
	 * the by-id map for subsequent lookups. Returns `null` for ids that
	 * the mailer doesn't have (WC not active, plugin not loaded, etc.) —
	 * callers handle that gracefully (toggle/first-run skip; serialize
	 * returns null for the row).
	 *
	 * The returned instance is the mailer-owned singleton — same object
	 * across calls, no fresh instantiation. Tests can prime the cache
	 * via {@see set_wc_email_by_id_for_test()} when real WC isn't
	 * loaded in the test env.
	 *
	 * @param string $id The WC_Email id (e.g. `customer_payment_retry`).
	 * @return \WC_Email|null The mailer-owned instance, or null.
	 */
	public static function get_wc_email_by_id( string $id ) {
		if ( null === self::$by_id_cache ) {
			self::$by_id_cache = [];
			if ( function_exists( 'WC' ) && class_exists( 'WC_Emails' ) ) {
				try {
					foreach ( \WC()->mailer()->get_emails() as $wc_email ) {
						self::$by_id_cache[ $wc_email->id ] = $wc_email;
					}
				} catch ( \Throwable $e ) {
					// Mailer not available — leave cache empty so callers
					// fall through their null-handling paths.
					unset( $e );
				}
			}
		}
		return self::$by_id_cache[ $id ] ?? null;
	}

	/**
	 * Memoization cache for `get_wc_email_by_id()`. Null until first
	 * lookup; populated lazily from `WC()->mailer()->get_emails()`.
	 *
	 * @var array<string, \WC_Email>|null
	 */
	private static $by_id_cache = null;

	/**
	 * Prime the by-id cache with a stub WC_Email instance.
	 *
	 * Test-only — lets PHPUnit suites that don't load real WC inject a
	 * `WC_Email`-shaped stub for `get_wc_email_by_id()` to return. Tests
	 * should pair this with `reset_wc_email_cache_for_test()` in
	 * tear_down so cache state doesn't leak across tests.
	 *
	 * @internal
	 *
	 * @param string $id       WC_Email id (e.g. `customer_payment_retry`).
	 * @param mixed  $instance The stub instance to return for this id.
	 */
	public static function set_wc_email_by_id_for_test( string $id, $instance ): void {
		if ( null === self::$by_id_cache ) {
			self::$by_id_cache = [];
		}
		self::$by_id_cache[ $id ] = $instance;
	}

	/**
	 * Reset the by-id cache so the next `get_wc_email_by_id()` call
	 * re-populates from the live mailer.
	 *
	 * Test-only — used in `tear_down` to prevent cross-test state leak.
	 * Also useful in production if WC's mailer registrations change
	 * within a single request, though that's a rare case.
	 *
	 * @internal
	 */
	public static function reset_wc_email_cache_for_test(): void {
		self::$by_id_cache = null;
	}

	/**
	 * Force enable WooCommerce email editor.
	 *
	 * @param mixed  $value  Current value.
	 * @param string $option Option name.
	 */
	public static function override_woocommerce_email_editor_option( $value, $option ) {
		if ( ! self::is_active() ) {
			return $value;
		}
		return self::is_enabled();
	}

	/**
	 * Update the option to enable WooCommerce block email editor.
	 *
	 * @param bool $enable Whether to enable the feature.
	 */
	public static function set_enabled( $enable ) {
		update_option( self::WOOCOMMERCE_EMAIL_EDITOR_OPTION, $enable ? 'yes' : 'no' );
	}

	/**
	 * Check if WooCommerce block email editor is enabled. Default to enabled.
	 *
	 * @return string 'yes' if enabled, 'no' if not.
	 */
	public static function is_enabled() {
		return get_option( self::WOOCOMMERCE_EMAIL_EDITOR_OPTION, 'yes' );
	}

	/**
	 * Update any existing woocommerce block emails to the latest content if they haven't been customized.
	 */
	public static function update_woocommerce_emails_to_latest() {
		if ( ! self::is_active() || ! class_exists( '\Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmails' ) ) {
			return;
		}
		if ( 'yes' === self::is_enabled() && 'v1' !== get_option( self::WOOCOMMERCE_EMAILS_UPDATED_OPTION, '' ) ) {
			$email_ids              = \Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmails::get_transactional_emails();
			$email_template_manager = \Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsManager::get_instance();
			foreach ( $email_ids as $email_id ) {
				$template_id = $email_template_manager->get_email_template_post_id( $email_id );
				if ( ! $template_id ) {
					continue;
				}
				$publish_date       = get_the_date( 'Y-m-d H:i:s', $template_id );
				$last_modified_date = get_the_modified_date( 'Y-m-d H:i:s', $template_id );
				// Template has not been modified, so delete the post so we can regenerate the template.
				if ( $publish_date === $last_modified_date ) {
					wp_delete_post( $template_id, true );
				}
			}
			delete_transient( 'wc_email_editor_initial_templates_generated' );
			$email_template_generator = new \Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsGenerator();
			$email_template_generator->initialize();
			update_option( self::WOOCOMMERCE_EMAILS_UPDATED_OPTION, 'v1' );
		}
	}

	/**
	 * Whether email enhancements are active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		/**
		 * Enables Newspack WooCommerce email enhancements including
		 * improved templates and transactional email customizations.
		 *
		 * @constant NEWSPACK_EMAIL_ENHANCEMENTS
		 * @type     bool
		 * @default  Email enhancements disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_EMAIL_ENHANCEMENTS', true );
		 */
		return defined( 'NEWSPACK_EMAIL_ENHANCEMENTS' ) && NEWSPACK_EMAIL_ENHANCEMENTS;
	}
}
WooCommerce_Emails::init();
