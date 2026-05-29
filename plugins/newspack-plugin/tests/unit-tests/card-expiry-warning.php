<?php
/**
 * Tests Card_Expiry_Warning.
 *
 * Coverage:
 *   - Email config shape: registration via newspack_email_configs, the
 *     four UI-metadata fields the legacy registry pattern used to host
 *     (chip, recommended, recipient, trigger_description) now declared
 *     directly on the config, placeholder set.
 *   - `get_days_before_expiry()` and `get_limit_per_pass()` filter +
 *     minimum-clamp behavior.
 *   - First-deploy seed flag-setting via the observable side effect on
 *     SEEDED_OPTION. The seed-iteration behavior (marks the per-token
 *     SEEDED meta on in-window pairs without sending) needs real WC
 *     Subscriptions + WCS_Payment_Tokens — covered in the integration
 *     smoke script.
 *   - `maybe_send_warning()` signature: confirms the
 *     `$bypass_idempotency` arg defaults to false (cron path
 *     unchanged) — the actual bypass behavior needs real WC and is
 *     covered in smoke.
 *
 * Why config-shape-and-flag-only here:
 *   In CI, WooCommerce Subscriptions is not loaded so the
 *   `class_exists('WCS_Payment_Tokens')` guard in
 *   get_in_window_pairs() returns an empty pair list. The seed flag
 *   still gets set (and we assert that here), but the actual
 *   per-pair iteration + send paths run against a real WC env in
 *   the smoke script.
 *
 * @package Newspack\Tests
 */

use Newspack\Card_Expiry_Warning;
use Newspack\Emails;

require_once __DIR__ . '/../mocks/newsletters-mocks.php';

/**
 * Tests Card_Expiry_Warning.
 */
class Newspack_Test_Card_Expiry_Warning extends WP_UnitTestCase {

	/**
	 * The card-expiry config callback registered in set_up.
	 *
	 * @var callable|null
	 */
	private $config_filter_callback = null;

	/**
	 * Post ID of the email post created in set_up so
	 * `Emails::can_send_email( EMAIL_TYPE )` returns true and the
	 * seed/scan branch logic can be exercised.
	 *
	 * @var int|null
	 */
	private $email_post_id = null;

	/**
	 * Set up. See class docblock for the CI-without-WC strategy.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();

		// Reset SEEDED_OPTION so each test starts unseeded by default.
		// Tests that exercise the post-seed path set it explicitly.
		delete_option( Card_Expiry_Warning::SEEDED_OPTION );

		// In CI, WC Subs is not loaded so Card_Expiry_Warning::init()
		// never hooks the filter. Register the callback ourselves so
		// Emails::get_email_configs() reflects the card-expiry config
		// (needed for tests that exercise can_send_email + the
		// scan/seed branches via Emails::can_send_email).
		$this->config_filter_callback = function ( $configs ) {
			return Card_Expiry_Warning::add_email_config( $configs );
		};
		add_filter( 'newspack_email_configs', $this->config_filter_callback );
		Emails::reset_email_configs_cache();

		// Create the email post with status='publish' so
		// Emails::can_send_email returns true. This unblocks the
		// scan-branch tests below without triggering the lazy-create
		// path inside get_email_config_by_type (which would also work
		// but adds noise).
		$this->email_post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Card expiry warning (test)',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META         => Card_Expiry_Warning::EMAIL_TYPE,
					// serialize_email returns false unless EMAIL_HTML_META
					// has a payload; the seed/scan-branch tests need
					// can_send_email → true.
					\Newspack_Newsletters::EMAIL_HTML_META => '<p>test</p>',
				],
			]
		);
	}

	/**
	 * Tear down. Removes filters, resets cache, and deletes the email post.
	 */
	public function tear_down() {
		if ( $this->config_filter_callback ) {
			remove_filter( 'newspack_email_configs', $this->config_filter_callback );
			$this->config_filter_callback = null;
		}
		Emails::reset_email_configs_cache();
		delete_option( Card_Expiry_Warning::SEEDED_OPTION );
		if ( $this->email_post_id ) {
			wp_delete_post( $this->email_post_id, true );
			$this->email_post_id = null;
		}
		// Safe today: only this test file registers these two filter
		// hooks (production code only reads them). If a future sibling
		// integration adds a bootstrap-time default callback, switch
		// these to targeted remove_filter() calls using stored callback
		// references so the production default survives the tear_down.
		remove_all_filters( 'newspack_card_expiry_warning_days' );
		remove_all_filters( 'newspack_card_expiry_warning_limit_per_pass' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Helper: get the email config by calling add_email_config directly.
	 *
	 * In CI, WooCommerce Subscriptions is not active so init() never
	 * hooks the filter. We test the static method directly instead.
	 *
	 * @return array Card-expiry config entry.
	 */
	private function get_config(): array {
		$configs = Card_Expiry_Warning::add_email_config( [] );
		return $configs[ Card_Expiry_Warning::EMAIL_TYPE ];
	}

	// --------------------------------------------------------------------
	// Email config shape.
	// --------------------------------------------------------------------

	/**
	 * The card-expiry config registers under EMAIL_TYPE.
	 */
	public function test_email_config_registered() {
		$configs = Card_Expiry_Warning::add_email_config( [] );
		$this->assertArrayHasKey( Card_Expiry_Warning::EMAIL_TYPE, $configs );
	}

	/**
	 * The legacy registry pattern that hosted the four UI-metadata
	 * fields (recommended, recipient, chip, trigger_description) is
	 * gone in slice 1's refactor — they're now declared directly on
	 * the config. Required-keys assertion includes them so a future
	 * accidental drop is caught.
	 */
	public function test_email_config_has_required_keys() {
		$config        = $this->get_config();
		$required_keys = [
			'name',
			'category',
			'chip',
			'recipient',
			'recommended',
			'label',
			'description',
			'trigger_description',
			'template',
			'editor_notice',
			'from_email',
			'available_placeholders',
		];
		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey( $key, $config, "Email config is missing required key '$key'." );
		}
	}

	/**
	 * The config's `name` matches EMAIL_TYPE (the registration key).
	 */
	public function test_email_config_name() {
		$this->assertSame( Card_Expiry_Warning::EMAIL_TYPE, $this->get_config()['name'] );
	}

	/**
	 * Categorized as `reader-revenue` — drives the chip and the UI grouping.
	 */
	public function test_email_config_category() {
		$this->assertSame( 'reader-revenue', $this->get_config()['category'] );
	}

	/**
	 * The template file referenced by the config exists on disk.
	 */
	public function test_email_config_template_exists() {
		$this->assertFileExists( $this->get_config()['template'] );
	}

	/**
	 * All expected merge placeholders are advertised in available_placeholders.
	 */
	public function test_email_config_placeholders() {
		$placeholders = $this->get_config()['available_placeholders'];
		$templates    = array_column( $placeholders, 'template' );
		$expected     = [
			'*BILLING_FIRST_NAME*',
			'*CARD_LAST_4*',
			'*EXPIRY_DATE*',
			'*RENEWAL_DATE*',
			'*UPDATE_PAYMENT_URL*',
			'*CONTACT_EMAIL*',
			'*SITE_TITLE*',
			'*SITE_URL*',
		];
		foreach ( $expected as $token ) {
			$this->assertContains( $token, $templates, "Placeholder '$token' should be in available_placeholders." );
		}
	}

	/**
	 * Direct config-shape assertion that replaces the legacy
	 * `test_registry_entry_chip_is_reader_revenue`. Slice 1's
	 * `apply_config_defaults()` would also derive `chip` from
	 * `category` here, but we declare it explicitly in the config so
	 * this is a direct rather than a derived assertion.
	 */
	public function test_email_config_chip_is_reader_revenue() {
		$this->assertSame( 'reader-revenue', $this->get_config()['chip'] );
	}

	/**
	 * Direct replacement for `test_registry_entry_is_recommended`.
	 *
	 * Card-expiry is the publisher's defense against involuntary churn
	 * — recommended-by-default is correct. (Slice 1's
	 * EMAIL_CONFIG_DEFAULTS.recommended is `false` for third-party
	 * configs; this one declares true explicitly.)
	 */
	public function test_email_config_recommended() {
		$this->assertTrue( $this->get_config()['recommended'] );
	}

	/**
	 * Direct replacement for `test_registry_entry_recipient`.
	 */
	public function test_email_config_recipient() {
		$this->assertSame( 'reader', $this->get_config()['recipient'] );
	}

	/**
	 * The `trigger_description` is populated (non-empty string) — drives
	 * the "Triggered when..." UI copy in the emails admin.
	 */
	public function test_email_config_trigger_description_present() {
		$this->assertNotEmpty( $this->get_config()['trigger_description'] );
		$this->assertIsString( $this->get_config()['trigger_description'] );
	}

	// --------------------------------------------------------------------
	// get_days_before_expiry.
	// --------------------------------------------------------------------

	/**
	 * Default lead time is 14 days. Changing this is a publisher-facing
	 * choice — flag if the default drifts unintentionally.
	 */
	public function test_days_before_expiry_default() {
		$this->assertSame( 14, Card_Expiry_Warning::get_days_before_expiry() );
	}

	/**
	 * The `newspack_card_expiry_warning_days` filter overrides the default.
	 */
	public function test_days_before_expiry_filterable() {
		add_filter( 'newspack_card_expiry_warning_days', fn() => 7 );
		$this->assertSame( 7, Card_Expiry_Warning::get_days_before_expiry() );
	}

	/**
	 * Hostile/zero/negative filter values clamp to a minimum of 1 day.
	 */
	public function test_days_before_expiry_minimum_is_one() {
		add_filter( 'newspack_card_expiry_warning_days', fn() => -5 );
		$this->assertSame( 1, Card_Expiry_Warning::get_days_before_expiry() );
	}

	// --------------------------------------------------------------------
	// get_limit_per_pass — pins the publisher-respect SQL-LIMIT (see the
	// "Publisher-respect — per-pass SQL LIMIT" section of the class
	// docblock).
	// --------------------------------------------------------------------

	/**
	 * Default per-pass LIMIT is LIMIT_PER_PASS_DEFAULT (100).
	 */
	public function test_limit_per_pass_default() {
		$this->assertSame( 100, Card_Expiry_Warning::get_limit_per_pass() );
	}

	/**
	 * The `newspack_card_expiry_warning_limit_per_pass` filter overrides
	 * the default — the publisher-facing escape hatch for large catalogs.
	 */
	public function test_limit_per_pass_filterable() {
		add_filter( 'newspack_card_expiry_warning_limit_per_pass', fn() => 50 );
		$this->assertSame( 50, Card_Expiry_Warning::get_limit_per_pass() );
	}

	/**
	 * Hostile/zero filter values clamp to a minimum of 1 — protects the
	 * SQL LIMIT clause from receiving 0 (which would mean "all rows" in
	 * some configurations).
	 */
	public function test_limit_per_pass_minimum_is_one() {
		add_filter( 'newspack_card_expiry_warning_limit_per_pass', fn() => 0 );
		$this->assertSame( 1, Card_Expiry_Warning::get_limit_per_pass() );
	}

	// --------------------------------------------------------------------
	// First-deploy seed — pins the "Publisher-respect — first-deploy seed"
	// section of the class docblock. The seed flag-setting is what we can
	// observe in CI without WC; the per-pair SEEDED meta marking + the
	// subsequent-scan distinction live in the integration smoke script.
	// --------------------------------------------------------------------

	/**
	 * First-deploy contract: with SEEDED_OPTION absent, scan_expiring_cards
	 * runs the seed pass and writes SEEDED_OPTION. Without WC Subs loaded,
	 * the per-pair iteration is empty — but the flag MUST still flip so
	 * the second cron run takes the normal-scan branch.
	 */
	public function test_scan_seeds_seeded_option_when_unseeded() {
		$this->assertFalse(
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION should be unset before first scan.'
		);

		Card_Expiry_Warning::scan_expiring_cards();

		$this->assertSame(
			'1',
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION should be set to "1" after the first scan.'
		);
	}

	/**
	 * Idempotency on the seed itself: a second scan call after the flag
	 * is set must not overwrite the flag or re-run the seed pass. (The
	 * stored value remains exactly what we set — proves we took the
	 * normal-scan branch on the second call.)
	 */
	public function test_scan_does_not_reset_seeded_option_when_already_set() {
		update_option( Card_Expiry_Warning::SEEDED_OPTION, 'sentinel-value', false );

		Card_Expiry_Warning::scan_expiring_cards();

		$this->assertSame(
			'sentinel-value',
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION must be untouched on subsequent scans — proves the normal-scan branch ran.'
		);
	}

	/**
	 * Defense in depth: when Emails::can_send_email() returns false (e.g.
	 * the email post is in draft), the scan exits at its top guard and
	 * MUST NOT flip the seed flag. Otherwise, a publisher who keeps the
	 * email disabled until they review it would silently lose their
	 * first-deploy seed protection.
	 */
	public function test_scan_no_op_when_emails_cannot_send_leaves_seed_unset() {
		// Move the email post out of publish so can_send_email returns false.
		wp_update_post(
			[
				'ID'          => $this->email_post_id,
				'post_status' => 'draft',
			]
		);

		Card_Expiry_Warning::scan_expiring_cards();

		$this->assertFalse(
			get_option( Card_Expiry_Warning::SEEDED_OPTION ),
			'SEEDED_OPTION must NOT be set when the scan no-ops at the can_send_email guard.'
		);
	}

	// --------------------------------------------------------------------
	// maybe_send_warning signature — pins the `$bypass_idempotency` arg
	// contract that the WP-CLI backfill relies on. Actual bypass behavior
	// needs real WC and is covered in smoke.
	// --------------------------------------------------------------------

	/**
	 * The cron path is the dominant caller and must not change behavior:
	 * `$bypass_idempotency` MUST default to false so a 2-arg call (the
	 * cron path) is equivalent to the pre-commit-2 behavior.
	 */
	public function test_maybe_send_warning_bypass_idempotency_default_is_false() {
		$reflection = new ReflectionMethod( Card_Expiry_Warning::class, 'maybe_send_warning' );
		$params     = $reflection->getParameters();

		$bypass_param = null;
		foreach ( $params as $param ) {
			if ( 'bypass_idempotency' === $param->getName() ) {
				$bypass_param = $param;
				break;
			}
		}

		$this->assertNotNull( $bypass_param, 'maybe_send_warning() must accept a $bypass_idempotency parameter.' );
		$this->assertTrue( $bypass_param->isDefaultValueAvailable(), '$bypass_idempotency must have a default value.' );
		$this->assertFalse( $bypass_param->getDefaultValue(), '$bypass_idempotency default MUST be false to keep the cron path unchanged.' );
	}
}
