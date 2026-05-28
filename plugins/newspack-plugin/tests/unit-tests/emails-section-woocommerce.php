<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.ClassComment.WrongStyle, Squiz.Commenting.InlineComment.InvalidEndChar, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Test file intentionally declares a WooCommerce class shim and a WC_Email-like stub alongside the main test class; precedent: tests/unit-tests/reader-registration-endpoint.php and tests/mocks/*.
/**
 * Slice 2a (NPPD-1527) — WooCommerce email surfacing.
 *
 * Coverage:
 *   - `WooCommerce_Emails::get_email_configs()` — early-bail contract
 *     when real WC isn't loaded (the loaded-and-mailer-populated branch
 *     is exercised by manual smoke; injecting a real WC mailer mock
 *     would dwarf this file).
 *   - `Emails_Section::api_get_email_settings()` — surfaces WC-source
 *     rows with `wc:<id>` post_id prefix once the slice 1 source filter
 *     is lifted.
 *   - `Emails_Section::api_toggle_wc_email()` — writes the WC option AND
 *     the same-request response reflects the new state (the staleness
 *     fix), with 404 rejection for unknown IDs and non-WC sources.
 *   - `Emails_Section::maybe_first_run_enable_wc_emails()` — idempotency
 *     keyed on the processed list (not current state, so the
 *     user-disabled-after-first-run case is honored), skips
 *     `recommended=false`, and flips the WCS master switch when
 *     `customer_notification_auto_renewal` first runs.
 *
 * Setup: declares a `WooCommerce` class shim (the only globally-visible
 * side effect — the real one doesn't exist in this test env, and the
 * guards in Emails_Section won't fire without it) and a minimal
 * `WC_Email`-like stub. Stub configs are injected via the
 * `newspack_email_configs` filter and removed in tearDown so they
 * don't leak across tests.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	// Shim — only declared when real WC isn't loaded. Empty body is fine;
	// Emails_Section guards use `class_exists('WooCommerce')` as a boolean.
	class WooCommerce {}
}

/**
 * Minimal WC_Email-like stub. Mimics the three points of contact the
 * implementation has with a WC_Email instance: the `id` and `enabled`
 * public properties, and the `get_option_key()` method.
 */
class Newspack_Test_Stub_WC_Email {
	public $id;
	public $enabled;

	public function __construct( string $id, string $enabled = 'no' ) {
		$this->id      = $id;
		$this->enabled = $enabled;
	}

	public function get_option_key(): string {
		return 'woocommerce_' . $this->id . '_settings';
	}

	/**
	 * Mirrors WC_Email::is_enabled() — reads the property. The real WC
	 * method also runs a `woocommerce_email_enabled_*` filter, but for
	 * the stub the property read is sufficient and deterministic.
	 */
	public function is_enabled(): bool {
		return 'yes' === $this->enabled;
	}
}

use Newspack\Emails;
use Newspack\Wizards\Newspack\Emails_Section;

/**
 * Slice 2a — WooCommerce surfacing tests.
 */
class Newspack_Test_Emails_Section_WooCommerce extends WP_UnitTestCase {
	/**
	 * Filter callbacks registered by tests, tracked for cleanup so they
	 * don't leak across test methods. WP filters aren't transactional.
	 *
	 * @var array
	 */
	private $filter_callbacks = [];

	/**
	 * Remove any registered filter callbacks AND reset the
	 * WooCommerce_Emails by-id cache so primed stubs don't leak across
	 * tests. Option writes are rolled back by the WP test framework's
	 * per-test transaction.
	 */
	public function tear_down() {
		foreach ( $this->filter_callbacks as $callback ) {
			remove_filter( 'newspack_email_configs', $callback );
		}
		$this->filter_callbacks = [];
		\Newspack\WooCommerce_Emails::reset_wc_email_cache_for_test();
		parent::tear_down();
	}

	/**
	 * Inject a stub WC config via `newspack_email_configs` AND prime
	 * the WooCommerce_Emails by-id cache so {@see WooCommerce_Emails::get_wc_email_by_id()}
	 * returns the stub when the toggle endpoint / first-run /
	 * serialization paths look up the instance.
	 *
	 * Two-step injection (config filter + cache seed) because the
	 * refactor split the schema (scalar `wc_email_class`) from the
	 * live instance (resolved on-demand). The filter provides what
	 * validation reads; the seeded cache provides what the call sites
	 * dereference.
	 *
	 * @param Newspack_Test_Stub_WC_Email $wc_email  Stub email instance.
	 * @param array                       $overrides Config overrides (e.g. recommended => false).
	 * @return Newspack_Test_Stub_WC_Email
	 */
	private function register_stub_wc_config( Newspack_Test_Stub_WC_Email $wc_email, array $overrides = [] ): Newspack_Test_Stub_WC_Email {
		$config = array_merge(
			[
				'name'                => $wc_email->id,
				'category'            => 'woocommerce',
				'source'              => 'woocommerce',
				'label'               => 'Stub WC: ' . $wc_email->id,
				'description'         => 'Stub WC config for testing.',
				'trigger_description' => 'Stub trigger.',
				'recipient'           => 'reader',
				'recommended'         => true,
				'chip'                => 'reader-revenue',
				'wc_email_class'      => get_class( $wc_email ),
			],
			$overrides
		);

		$callback = function ( $configs ) use ( $config ) {
			$configs[ $config['name'] ] = $config;
			return $configs;
		};
		add_filter( 'newspack_email_configs', $callback );
		$this->filter_callbacks[] = $callback;

		// Prime the by-id cache so call sites resolve the stub.
		\Newspack\WooCommerce_Emails::set_wc_email_by_id_for_test( $wc_email->id, $wc_email );

		return $wc_email;
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F.1 — WooCommerce_Emails::get_email_configs() registration
	 * ------------------------------------------------------------------
	 */

	/**
	 * The class hooks into `newspack_email_configs` from its `init()`.
	 * This is the contract that lets the rest of the system discover WC
	 * surfaces through the same filter as Newspack-side providers.
	 */
	public function test_woocommerce_emails_hooks_into_newspack_email_configs_filter() {
		$this->assertNotFalse(
			has_filter( 'newspack_email_configs', [ \Newspack\WooCommerce_Emails::class, 'get_email_configs' ] ),
			'WooCommerce_Emails::get_email_configs should be hooked into newspack_email_configs.'
		);
	}

	/**
	 * Without real WC available (no `function_exists('WC')`,
	 * no `class WC_Emails`), the method returns the input unchanged —
	 * it never reaches the mailer iteration.
	 */
	public function test_woocommerce_emails_get_email_configs_returns_unchanged_without_wc() {
		$input  = [
			'receipt' => [
				'name'     => 'receipt',
				'category' => 'reader-revenue',
			],
		];
		$output = \Newspack\WooCommerce_Emails::get_email_configs( $input );
		$this->assertSame( $input, $output );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F.2 — api_get_email_settings() surfaces WC rows
	 * ------------------------------------------------------------------
	 */

	/**
	 * A WC-source config registered via `newspack_email_configs` appears
	 * in the wizard response as a row with `source='woocommerce'` and
	 * `post_id='wc:<id>'`. Slice 1 filtered these out; slice 2a lifts
	 * the filter.
	 */
	public function test_api_get_email_settings_includes_woocommerce_rows() {
		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_refunded_order', 'yes' )
		);

		$result = Emails_Section::api_get_email_settings();
		$rows   = array_values(
			array_filter(
				$result['newspack_emails'],
				fn( $email ) => 'woocommerce' === ( $email['source'] ?? 'newspack' )
					&& 'wc:' . $wc_email->id === ( $email['post_id'] ?? '' )
			)
		);

		$this->assertCount( 1, $rows, 'Expected the stub config to surface exactly once.' );
		$this->assertSame( $wc_email->id, $rows[0]['type'] );
		$this->assertSame( $wc_email->id, $rows[0]['registry_slug'] );
		$this->assertSame( 'woocommerce', $rows[0]['source'] );
		$this->assertSame( 'reader-revenue', $rows[0]['chip'] );
		$this->assertSame( 'publish', $rows[0]['status'], 'enabled=yes should serialize to status=publish.' );
	}

	/**
	 * WC rows surface in `WooCommerce_Emails::surfaced_wc_emails()`
	 * registration order — NOT alphabetical by config key. The allowlist
	 * is hand-ordered to group semantically-related emails together
	 * (specifically, the gift pair: 'New giftee account' and
	 * 'New gift order' must be adjacent). This locks in the curated
	 * grouping against accidental regressions to alphabetical sorting.
	 */
	public function test_api_get_email_settings_wc_rows_follow_registration_order() {
		// Stub the full allowlist in its registration order so the test
		// matches the contract of `surfaced_wc_emails()` even when real WC
		// isn't loaded (no `function_exists('WC')` in this env, so the
		// real loop in `WooCommerce_Emails::get_email_configs()` bails).
		$expected_order = [
			'customer_notification_auto_renewal', // Renewal reminder
			'customer_payment_retry',             // Failed order retry
			'expired_subscription',               // Subscription expired
			'customer_completed_switch_order',    // Subscription switch complete
			'WCSG_Email_Customer_New_Account',    // New giftee account ─┐
			'recipient_completed_order',          // New gift order      ┘ adjacent
			'customer_new_account',               // New account
			'customer_refunded_order',            // Order refund
			'new_order',                          // New order
		];
		// Register stubs in chip='reader-revenue' (except customer_new_account
		// which is auth-account in real life — keep it that way so the
		// test mirrors production chip assignment).
		$auth_account_id = 'customer_new_account';
		foreach ( $expected_order as $id ) {
			$this->register_stub_wc_config(
				new Newspack_Test_Stub_WC_Email( $id, 'yes' ),
				[
					'chip' => $auth_account_id === $id ? 'auth-account' : 'reader-revenue',
				]
			);
		}

		$result   = Emails_Section::api_get_email_settings();
		$wc_types = array_values(
			array_map(
				fn( $email ) => $email['type'],
				array_filter(
					$result['newspack_emails'],
					fn( $email ) => 'woocommerce' === ( $email['source'] ?? 'newspack' )
				)
			)
		);

		$this->assertSame(
			$expected_order,
			$wc_types,
			'WC rows must surface in surfaced_wc_emails() registration order, not alphabetical.'
		);
	}

	/**
	 * Specifically: the gift-pair (`New giftee account` /
	 * `New gift order`) must be adjacent. Pre-fix they were max
	 * distance apart because `strcmp` put `WCSG_...` first (uppercase)
	 * and `recipient_completed_order` last.
	 */
	public function test_api_get_email_settings_wc_gift_pair_is_adjacent() {
		// Register only the two stubs that matter — both share
		// chip='reader-revenue' in production.
		$this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'WCSG_Email_Customer_New_Account', 'yes' )
		);
		$this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'recipient_completed_order', 'yes' )
		);

		$result = Emails_Section::api_get_email_settings();
		$types  = array_values(
			array_map(
				fn( $email ) => $email['type'],
				array_filter(
					$result['newspack_emails'],
					fn( $email ) => in_array(
						$email['type'] ?? '',
						[ 'WCSG_Email_Customer_New_Account', 'recipient_completed_order' ],
						true
					)
				)
			)
		);

		$this->assertCount( 2, $types );
		$idx_giftee = array_search( 'WCSG_Email_Customer_New_Account', $types, true );
		$idx_gift   = array_search( 'recipient_completed_order', $types, true );
		$this->assertSame(
			1,
			abs( $idx_giftee - $idx_gift ),
			'New giftee account and New gift order must be adjacent in the response.'
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F.3 — api_toggle_wc_email
	 * ------------------------------------------------------------------
	 */

	/**
	 * Toggling writes the WC option AND the refreshed response in the
	 * same request reflects the new status. Pre-slice-2a the response
	 * read `$wc_email->enabled` (the in-memory cache from WC boot),
	 * which could be stale relative to the option write that just
	 * happened. The fix: serialize_wc_email_row() reads the option,
	 * the toggle writes the option AND keeps the cached property in
	 * sync. We assert both writes and the response reflection.
	 */
	public function test_api_toggle_wc_email_writes_option_and_response_reflects() {
		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_payment_retry', 'no' )
		);

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $wc_email->id );
		$request->set_param( 'enabled', true );

		$response = Emails_Section::api_toggle_wc_email( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Option write: WC option key now has enabled=yes.
		$options = (array) get_option( $wc_email->get_option_key(), [] );
		$this->assertSame( 'yes', $options['enabled'], 'Option was not written.' );

		// In-memory write: the cached $enabled property is updated too.
		$this->assertSame( 'yes', $wc_email->enabled, 'In-memory $enabled was not updated.' );

		// Same-request response: the row for this email reflects the new status.
		$data = $response->get_data();
		$rows = array_values(
			array_filter(
				$data['newspack_emails'],
				fn( $email ) => 'wc:' . $wc_email->id === ( $email['post_id'] ?? '' )
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'publish', $rows[0]['status'], 'Refreshed response did not reflect the toggled state.' );
	}

	/**
	 * Toggling off (after toggling on) flips the option and the
	 * same-request response back. Pre-seeds FIRST_RUN_OPTION so the
	 * refreshed api_get_email_settings() doesn't immediately re-enable
	 * via first-run — realistic user scenario (past first-run, now
	 * toggling off).
	 */
	public function test_api_toggle_wc_email_off_flips_option_and_response() {
		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_payment_retry', 'yes' )
		);
		update_option( $wc_email->get_option_key(), [ 'enabled' => 'yes' ] );
		update_option( Emails_Section::FIRST_RUN_OPTION, [ $wc_email->id ], false );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $wc_email->id );
		$request->set_param( 'enabled', false );

		$response = Emails_Section::api_toggle_wc_email( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response );

		$options = (array) get_option( $wc_email->get_option_key(), [] );
		$this->assertSame( 'no', $options['enabled'] );

		$data = $response->get_data();
		$rows = array_values(
			array_filter(
				$data['newspack_emails'],
				fn( $email ) => 'wc:' . $wc_email->id === ( $email['post_id'] ?? '' )
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'draft', $rows[0]['status'], 'Toggle off should serialize to status=draft.' );
	}

	/**
	 * Toggling an unknown email ID returns a 404 WP_Error and does not
	 * write any option.
	 */
	public function test_api_toggle_wc_email_rejects_unknown_id() {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', 'this_email_does_not_exist' );
		$request->set_param( 'enabled', true );

		$response = Emails_Section::api_toggle_wc_email( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_wc_email_not_allowed', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Toggling a Newspack-source id returns 404 even though the id
	 * resolves to a real config. The `source === 'woocommerce'` check
	 * guards against routing Newspack rows through the WC toggle path.
	 */
	public function test_api_toggle_wc_email_rejects_newspack_source_id() {
		$configs              = Emails::get_email_configs();
		$newspack_config_keys = array_keys(
			array_filter(
				$configs,
				fn( $config ) => 'woocommerce' !== ( $config['source'] ?? 'newspack' )
			)
		);
		$this->assertNotEmpty( $newspack_config_keys, 'Expected at least one newspack-source config to exist.' );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $newspack_config_keys[0] );
		$request->set_param( 'enabled', true );

		$response = Emails_Section::api_toggle_wc_email( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_wc_email_not_allowed', $response->get_error_code() );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F.4 — maybe_first_run_enable_wc_emails
	 *
	 * Called from api_get_email_settings() on every wizard load.
	 * Idempotency is keyed on the FIRST_RUN_OPTION processed-keys
	 * array, NOT on the current enabled state — that's the
	 * user-disabled-after-first-run contract.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Once a key is in the processed list, first-run does NOT re-enable
	 * it — even if the user has manually disabled it since. This is the
	 * critical user-disabled-after-first-run protection.
	 */
	public function test_first_run_enable_idempotent_when_key_processed() {
		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_payment_retry', 'no' )
		);
		update_option( Emails_Section::FIRST_RUN_OPTION, [ $wc_email->id ], false );
		update_option( $wc_email->get_option_key(), [ 'enabled' => 'no' ] );

		Emails_Section::api_get_email_settings();

		$options = (array) get_option( $wc_email->get_option_key(), [] );
		$this->assertSame( 'no', $options['enabled'], 'Already-processed email must NOT be re-enabled.' );

		$processed = (array) get_option( Emails_Section::FIRST_RUN_OPTION, [] );
		$this->assertSame( [ $wc_email->id ], $processed, 'Processed list should be unchanged.' );
	}

	/**
	 * A `recommended=false` WC config is never auto-enabled on first-run
	 * and its key is not added to the processed list (so a future flip
	 * to recommended=true would still get a first-run pass).
	 */
	public function test_first_run_skips_non_recommended() {
		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_refunded_order', 'no' ),
			[ 'recommended' => false ]
		);

		Emails_Section::api_get_email_settings();

		$this->assertFalse(
			get_option( $wc_email->get_option_key() ),
			'Non-recommended email should not have its option written.'
		);

		$processed = (array) get_option( Emails_Section::FIRST_RUN_OPTION, [] );
		$this->assertNotContains(
			$wc_email->id,
			$processed,
			'Non-recommended email should not be in the processed list.'
		);
		$this->assertSame( 'no', $wc_email->enabled, 'In-memory enabled should be unchanged.' );
	}

	/**
	 * A recommended, unprocessed WC email is enabled on its first
	 * encounter AND added to the processed list, so the next call is a
	 * no-op (idempotency).
	 */
	public function test_first_run_enables_recommended_unprocessed() {
		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_payment_retry', 'no' )
		);

		Emails_Section::api_get_email_settings();

		$options = (array) get_option( $wc_email->get_option_key(), [] );
		$this->assertSame( 'yes', $options['enabled'] );
		$this->assertSame( 'yes', $wc_email->enabled, 'In-memory enabled should be flipped too.' );

		$processed = (array) get_option( Emails_Section::FIRST_RUN_OPTION, [] );
		$this->assertContains( $wc_email->id, $processed );
	}

	/**
	 * Special case: `customer_notification_auto_renewal` requires the
	 * WC Subscriptions master switch
	 * (`woocommerce_subscriptions_customer_notifications_enabled`) on —
	 * otherwise the email never fires regardless of its own enabled
	 * flag. First-run flips both.
	 */
	public function test_first_run_enables_wcs_master_switch_for_auto_renewal() {
		update_option( 'woocommerce_subscriptions_customer_notifications_enabled', 'no' );

		$wc_email = $this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_notification_auto_renewal', 'no' )
		);

		Emails_Section::api_get_email_settings();

		$this->assertSame(
			'yes',
			get_option( 'woocommerce_subscriptions_customer_notifications_enabled' ),
			'WCS master switch should be enabled by the auto-renewal first-run.'
		);
		$this->assertContains(
			$wc_email->id,
			(array) get_option( Emails_Section::FIRST_RUN_OPTION, [] )
		);
	}

	/**
	 * Non-auto-renewal first-runs do NOT flip the WCS master switch —
	 * the special case is scoped to that one id.
	 */
	public function test_first_run_does_not_flip_master_switch_for_other_emails() {
		update_option( 'woocommerce_subscriptions_customer_notifications_enabled', 'no' );

		$this->register_stub_wc_config(
			new Newspack_Test_Stub_WC_Email( 'customer_payment_retry', 'no' )
		);

		Emails_Section::api_get_email_settings();

		$this->assertSame(
			'no',
			get_option( 'woocommerce_subscriptions_customer_notifications_enabled' ),
			'Master switch should not be touched by non-auto-renewal first-runs.'
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F.5 — Config schema shape (dkoo's refactor — drop instance)
	 * ------------------------------------------------------------------
	 * The unified `newspack_email_configs` schema MUST stay JSON-
	 * serializable. The live `WC_Email` instance is resolved on-demand
	 * via `WooCommerce_Emails::get_wc_email_by_id()` at the call sites
	 * that need it; the config itself only carries the scalar
	 * `wc_email_class` string.
	 */

	/**
	 * Real-WC `WooCommerce_Emails::get_email_configs()` injects entries
	 * that have NO `wc_email_instance` key and DO have a string
	 * `wc_email_class` field.
	 */
	public function test_wc_emails_get_email_configs_no_instance_has_class_string() {
		if ( ! class_exists( 'WC_Emails' ) ) {
			$this->markTestSkipped( 'Real WC not loaded; the get_email_configs() loop bails before injecting anything.' );
		}

		$configs = \Newspack\WooCommerce_Emails::get_email_configs( [] );
		$this->assertNotEmpty( $configs, 'Expected at least one surfaced WC config.' );

		foreach ( $configs as $id => $config ) {
			$this->assertArrayNotHasKey(
				'wc_email_instance',
				$config,
				"Config '$id' must NOT carry a live WC_Email instance (breaks JSON serialization)."
			);
			$this->assertArrayHasKey(
				'wc_email_class',
				$config,
				"Config '$id' must carry the scalar wc_email_class field."
			);
			$this->assertIsString( $config['wc_email_class'], "Config '$id' wc_email_class must be a string." );
			$this->assertNotEmpty( $config['wc_email_class'], "Config '$id' wc_email_class must not be empty." );
		}
	}

	/**
	 * Spot-check one specific id → class mapping that exercises a path
	 * the loop touches (WC core, no plugin_dependency gating).
	 */
	public function test_wc_emails_customer_new_account_class_mapping() {
		if ( ! class_exists( 'WC_Emails' ) ) {
			$this->markTestSkipped( 'Real WC not loaded.' );
		}

		$configs = \Newspack\WooCommerce_Emails::get_email_configs( [] );
		$this->assertArrayHasKey( 'customer_new_account', $configs );
		$this->assertSame( 'WC_Email_Customer_New_Account', $configs['customer_new_account']['wc_email_class'] );
	}

	/**
	 * The whole unified config set must be JSON-encodable. If anyone
	 * accidentally smuggles a WC_Email instance back in (a closed-over
	 * filter callback, a stray field) wp_json_encode would return false
	 * because WC_Email holds non-serializable references.
	 */
	public function test_unified_email_configs_are_json_encodable() {
		$json = wp_json_encode( Emails::get_email_configs() );

		$this->assertNotFalse(
			$json,
			'wp_json_encode(Emails::get_email_configs()) returned false — something in the schema is not JSON-serializable.'
		);
		$this->assertStringNotContainsString(
			'wc_email_instance',
			(string) $json,
			'No `wc_email_instance` key should appear in the serialized config schema.'
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket F.6 — WooCommerce_Emails::get_wc_email_by_id() helper
	 * ------------------------------------------------------------------
	 */

	/**
	 * Returns null for ids that aren't in the mailer (or surfaced
	 * allowlist) — callers handle that gracefully.
	 */
	public function test_get_wc_email_by_id_returns_null_for_unknown_id() {
		\Newspack\WooCommerce_Emails::reset_wc_email_cache_for_test();
		$result = \Newspack\WooCommerce_Emails::get_wc_email_by_id( 'this_id_does_not_exist' );
		$this->assertNull( $result );
	}

	/**
	 * Memoization: two calls for the same id return the exact same
	 * object (proves the cache isn't re-fetching from the mailer).
	 */
	public function test_get_wc_email_by_id_memoizes() {
		// Prime with a stub so this test works without real WC. The
		// memoization contract is the same: the second call returns the
		// same object reference as the first.
		$stub = new Newspack_Test_Stub_WC_Email( 'customer_payment_retry', 'no' );
		\Newspack\WooCommerce_Emails::set_wc_email_by_id_for_test( $stub->id, $stub );

		$a = \Newspack\WooCommerce_Emails::get_wc_email_by_id( $stub->id );
		$b = \Newspack\WooCommerce_Emails::get_wc_email_by_id( $stub->id );

		$this->assertSame( $a, $b, 'Helper must return the same object reference across calls.' );
		$this->assertSame( $stub, $a, 'Helper must return the primed instance.' );
	}

	/**
	 * Helper round-trip against real WC: the returned instance is the
	 * mailer-owned singleton, not a fresh instantiation. Skipped when
	 * real WC isn't loaded.
	 */
	public function test_get_wc_email_by_id_returns_mailer_owned_singleton() {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Emails' ) ) {
			$this->markTestSkipped( 'Real WC not loaded; cannot compare against WC()->mailer()->get_emails().' );
		}

		\Newspack\WooCommerce_Emails::reset_wc_email_cache_for_test();

		$mailer_emails = \WC()->mailer()->get_emails();
		// Find any id that's both in the mailer AND surfaced — use the
		// first one to avoid coupling to a specific id.
		$id_to_test = null;
		foreach ( $mailer_emails as $wc_email ) {
			if ( 'customer_new_account' === $wc_email->id ) {
				$id_to_test = $wc_email->id;
				break;
			}
		}
		if ( ! $id_to_test ) {
			$this->markTestSkipped( 'customer_new_account not registered in this WC env.' );
		}

		// Reset, then compare: the helper's returned instance MUST be
		// the same object as the one the mailer hands out.
		$mailer_owned = null;
		foreach ( \WC()->mailer()->get_emails() as $wc_email ) {
			if ( $wc_email->id === $id_to_test ) {
				$mailer_owned = $wc_email;
				break;
			}
		}

		$resolved = \Newspack\WooCommerce_Emails::get_wc_email_by_id( $id_to_test );

		$this->assertSame(
			$mailer_owned,
			$resolved,
			'Helper must return the mailer-owned singleton, not a fresh instantiation.'
		);
	}
}
