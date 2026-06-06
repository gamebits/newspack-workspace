<?php
/**
 * Tests for the unified email config schema and the wizard response builder.
 *
 * Slice 1 (NPPD-945) coverage — schema completeness across Newspack-side
 * providers, per-provider content, response shape, and the
 * apply_config_defaults() merge mechanism. The WC integration test bucket
 * lands with slice 2.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Reader_Activation_Emails;
use Newspack\Reader_Revenue_Emails;
use Newspack\Wizards\Newspack\Emails_Section;

/**
 * Tests the unified Emails config schema and the wizard response builder.
 */
class Newspack_Test_Emails_Section extends WP_UnitTestCase {
	/*
	 * ------------------------------------------------------------------
	 * Bucket A — Schema completeness
	 * ------------------------------------------------------------------
	 * Iterates every config from `newspack_email_configs` after defaults
	 * are applied. Catches provider classes that forget to declare the
	 * new fields, or that declare invalid values.
	 */

	/**
	 * Every config has the four new schema fields after defaults are applied.
	 */
	public function test_email_configs_have_all_required_fields() {
		$configs = Emails::get_email_configs();
		$this->assertNotEmpty( $configs, 'Expected at least one registered email config.' );

		foreach ( $configs as $type => $config ) {
			$this->assertArrayHasKey( 'trigger_description', $config, "Config '$type' is missing trigger_description." );
			$this->assertIsString( $config['trigger_description'], "Config '$type' trigger_description must be a string." );

			$this->assertArrayHasKey( 'recipient', $config, "Config '$type' is missing recipient." );
			$this->assertContains(
				$config['recipient'],
				[ 'reader', 'admin' ],
				"Config '$type' has an invalid recipient value."
			);

			$this->assertArrayHasKey( 'recommended', $config, "Config '$type' is missing recommended." );
			$this->assertIsBool( $config['recommended'], "Config '$type' recommended must be a bool." );

			$this->assertArrayHasKey( 'chip', $config, "Config '$type' is missing chip." );
			$this->assertContains(
				$config['chip'],
				[ 'auth-account', 'reader-revenue' ],
				"Config '$type' has an invalid chip value."
			);
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket B — Per-provider content
	 * ------------------------------------------------------------------
	 * Asserts each provider class registers entries with the expected
	 * field values. Catches data regressions when providers are touched.
	 */

	/**
	 * Reader-revenue provider: 3 entries chip='reader-revenue', recipient='reader', recommended=true.
	 */
	public function test_reader_revenue_provider_entries() {
		$configs = Emails::get_email_configs();

		$expected = [
			Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] => 'Sent after a successful payment.',
			Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] => 'Sent to new supporters after their first payment.',
			Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] => 'Sent when a reader cancels their subscription.',
		];

		foreach ( $expected as $type => $trigger_description ) {
			$this->assertArrayHasKey( $type, $configs, "Reader-revenue type '$type' not registered." );
			$this->assertSame( 'reader-revenue', $configs[ $type ]['chip'], "Type '$type' should chip to reader-revenue." );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'], "Type '$type' should target reader." );
			$this->assertTrue( $configs[ $type ]['recommended'], "Type '$type' should be recommended." );
			$this->assertSame( $trigger_description, $configs[ $type ]['trigger_description'] );
		}
	}

	/**
	 * Reader-activation provider: chip='auth-account', recipient='reader' for all entries.
	 * Recommended=true for the four core sign-in flows; false for the account-management flows.
	 *
	 * CHANGE_EMAIL and CHANGE_EMAIL_CANCEL are gated on WooCommerce_My_Account::is_email_change_enabled(),
	 * so they're only asserted when registered.
	 */
	public function test_reader_activation_provider_entries() {
		$configs = Emails::get_email_configs();

		$recommended_types     = [
			Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
			Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
			Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
			Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
		];
		$non_recommended_types = [
			Reader_Activation_Emails::EMAIL_TYPES['DELETE_ACCOUNT'],
			Reader_Activation_Emails::EMAIL_TYPES['NON_READER'],
		];
		$conditional_types     = [
			Reader_Activation_Emails::EMAIL_TYPES['CHANGE_EMAIL'],
			Reader_Activation_Emails::EMAIL_TYPES['CHANGE_EMAIL_CANCEL'],
		];

		foreach ( array_merge( $recommended_types, $non_recommended_types ) as $type ) {
			$this->assertArrayHasKey( $type, $configs, "Reader-activation type '$type' not registered." );
			$this->assertSame( 'auth-account', $configs[ $type ]['chip'], "Type '$type' should chip to auth-account." );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'], "Type '$type' should target reader." );
			$this->assertNotEmpty( $configs[ $type ]['trigger_description'], "Type '$type' should have a trigger description." );
		}
		foreach ( $recommended_types as $type ) {
			$this->assertTrue( $configs[ $type ]['recommended'], "Type '$type' should be recommended." );
		}
		foreach ( $non_recommended_types as $type ) {
			$this->assertFalse( $configs[ $type ]['recommended'], "Type '$type' should NOT be recommended." );
		}
		// CHANGE_EMAIL pair only registers when WC My Account email change is enabled.
		foreach ( $conditional_types as $type ) {
			if ( ! isset( $configs[ $type ] ) ) {
				continue;
			}
			$this->assertSame( 'auth-account', $configs[ $type ]['chip'] );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
			$this->assertFalse( $configs[ $type ]['recommended'] );
		}
	}

	/**
	 * Group-subscription-invite provider: chip='reader-revenue' (paid product), not recommended.
	 */
	public function test_group_subscription_invite_provider_entry() {
		$configs = Emails::get_email_configs();
		$type    = 'group-subscription-invite';

		if ( ! isset( $configs[ $type ] ) ) {
			$this->markTestSkipped( 'group-subscription-invite config not registered in this environment.' );
		}
		$this->assertSame( 'reader-revenue', $configs[ $type ]['chip'] );
		$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
		$this->assertFalse( $configs[ $type ]['recommended'] );
		$this->assertSame( 'Sent to invite a reader to join a group subscription.', $configs[ $type ]['trigger_description'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket C — Response shape of api_get_email_settings()
	 * ------------------------------------------------------------------
	 * Verifies the wizard endpoint response structure after the rewrite:
	 * top-level keys are correct, each row carries the new fields,
	 * registry_slug is the config key string, no view_category leakage,
	 * and category sort grouping holds. (WooCommerce-source surfacing is
	 * covered in emails-section-woocommerce.php; slice 2a no longer
	 * excludes WC rows here.)
	 */

	/**
	 * Response has the expected top-level shape and rows carry the new fields.
	 */
	public function test_api_get_email_settings_response_shape() {
		$result = Emails_Section::api_get_email_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'newspack_emails', $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertIsArray( $result['newspack_emails'] );

		// Precondition: if Emails::supports_emails() returns false (e.g.
		// Newspack_Newsletters isn't loaded in this test bootstrap),
		// newspack_emails is [] and the foreach below runs zero times —
		// making the per-row assertions vacuous. Skip explicitly rather
		// than letting a regression slip through silently.
		if ( empty( $result['newspack_emails'] ) ) {
			$this->markTestSkipped( 'Emails::supports_emails() is false in this environment; no rows to assert against.' );
		}

		$required_row_fields = [
			'label',
			'trigger_description',
			'recipient',
			'recommended',
			'chip',
			'source',
			'category',
			'status',
		];
		foreach ( $result['newspack_emails'] as $email ) {
			foreach ( $required_row_fields as $field ) {
				$this->assertArrayHasKey( $field, $email, "Row '{$email['label']}' is missing field '$field'." );
			}
		}
	}

	/**
	 * `view_category` is dead — the response builder never emits it.
	 */
	public function test_api_get_email_settings_omits_view_category() {
		$result = Emails_Section::api_get_email_settings();
		foreach ( $result['newspack_emails'] as $email ) {
			$this->assertArrayNotHasKey( 'view_category', $email );
		}
	}

	/**
	 * Within-category tiebreaker is config-registration order, not
	 * alphabetical. Providers register in deliberate order
	 * (Reader_Revenue_Emails: receipt → welcome → cancellation;
	 * Reader_Activation_Emails: verification → magic-link → otp →
	 * reset-password → ...), so registration order = intended display
	 * order. Lock that contract in.
	 */
	public function test_api_get_email_settings_within_category_follows_registration_order() {
		$result = Emails_Section::api_get_email_settings();

		// Reader-revenue group: receipt → welcome → cancellation
		// (per Reader_Revenue_Emails::add_email_configs() order), then
		// group-subscription-invite (also reader-revenue category after
		// the slice-1 fix flipped it from reader-activation).
		$rr_types = array_values(
			array_map(
				fn( $email ) => $email['type'],
				array_filter(
					$result['newspack_emails'],
					fn( $email ) => 'reader-revenue' === ( $email['category'] ?? '' )
				)
			)
		);
		$this->assertSame(
			[
				Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'],
				Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'],
				Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'],
				'group-subscription-invite',
			],
			$rr_types,
			'Reader-revenue rows must follow provider registration order.'
		);

		// Reader-activation group: verification → magic-link → otp →
		// reset-password (the four sign-in flows that are always present,
		// per the order in Reader_Activation_Emails::add_email_configs()).
		$ra_types_all  = array_values(
			array_map(
				fn( $email ) => $email['type'],
				array_filter(
					$result['newspack_emails'],
					fn( $email ) => 'reader-activation' === ( $email['category'] ?? '' )
				)
			)
		);
		$ra_core_types = array_values(
			array_filter(
				$ra_types_all,
				fn( $type ) => in_array(
					$type,
					[
						Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
						Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
						Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
						Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
					],
					true
				)
			)
		);
		$this->assertSame(
			[
				Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
				Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
				Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
				Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
			],
			$ra_core_types,
			'Reader-activation rows must follow Reader_Activation_Emails provider registration order.'
		);
	}

	/**
	 * Sort order: reader-revenue first, then reader-activation, then everything else.
	 */
	public function test_api_get_email_settings_sort_order() {
		$result    = Emails_Section::api_get_email_settings();
		$group_map = [
			'reader-revenue'    => 0,
			'reader-activation' => 1,
		];

		$last_group = -1;
		foreach ( $result['newspack_emails'] as $i => $email ) {
			$group = $group_map[ $email['category'] ?? '' ] ?? 2;
			$this->assertGreaterThanOrEqual(
				$last_group,
				$group,
				"Email at index $i (category '{$email['category']}') is out of sort order."
			);
			$last_group = $group;
		}
	}


	/*
	 * ------------------------------------------------------------------
	 * Bucket D — Default-merge mechanism
	 * ------------------------------------------------------------------
	 * Direct coverage of Emails::apply_config_defaults() — the public
	 * helper introduced in commit 1. Catches changes to the documented
	 * defaults and regressions in the merge logic.
	 */

	/**
	 * Partial config gets the documented defaults filled in.
	 */
	public function test_apply_config_defaults_fills_missing_fields() {
		$partial = [
			'name'     => 'test-email',
			'label'    => 'Test Email',
			'category' => 'reader-activation',
		];
		$merged  = Emails::apply_config_defaults( $partial );

		$this->assertSame( '', $merged['trigger_description'] );
		$this->assertSame( 'reader', $merged['recipient'] );
		// `recommended` defaults to false — third-parties must opt in.
		$this->assertFalse( $merged['recommended'] );
		// `chip` is derived from category in apply_config_defaults().
		$this->assertSame( 'auth-account', $merged['chip'] );

		// Declared fields pass through unchanged.
		$this->assertSame( 'test-email', $merged['name'] );
		$this->assertSame( 'Test Email', $merged['label'] );
		$this->assertSame( 'reader-activation', $merged['category'] );
	}

	/**
	 * Chip is derived from category: reader-revenue → reader-revenue.
	 */
	public function test_apply_config_defaults_derives_reader_revenue_chip_from_category() {
		$partial = [
			'name'     => 'test-rr-email',
			'category' => 'reader-revenue',
		];
		$merged  = Emails::apply_config_defaults( $partial );
		$this->assertSame( 'reader-revenue', $merged['chip'] );
	}

	/**
	 * Explicit chip declaration wins over the category-derived default.
	 */
	public function test_apply_config_defaults_explicit_chip_overrides_derivation() {
		$partial = [
			'name'     => 'test-email',
			'category' => 'reader-revenue',
			'chip'     => 'auth-account',
		];
		$merged  = Emails::apply_config_defaults( $partial );
		$this->assertSame( 'auth-account', $merged['chip'] );
	}

	/**
	 * A config that declares the new fields keeps its values — defaults
	 * do not clobber explicit declarations.
	 */
	public function test_apply_config_defaults_preserves_declared_fields() {
		$full   = [
			'name'                => 'test-email',
			'trigger_description' => 'A specific trigger.',
			'recipient'           => 'admin',
			'recommended'         => false,
			'chip'                => 'reader-revenue',
		];
		$merged = Emails::apply_config_defaults( $full );

		$this->assertSame( 'A specific trigger.', $merged['trigger_description'] );
		$this->assertSame( 'admin', $merged['recipient'] );
		$this->assertFalse( $merged['recommended'] );
		$this->assertSame( 'reader-revenue', $merged['chip'] );
	}

	/**
	 * A third-party provider can register with no new fields at all and
	 * still get a complete config back out of Emails::get_email_configs().
	 */
	public function test_email_configs_filter_partial_provider_gets_defaults() {
		$type     = 'test-partial-third-party-config';
		$callback = function ( $configs ) use ( $type ) {
			$configs[ $type ] = [
				'name'     => $type,
				'category' => 'reader-activation',
				'label'    => 'Third-party',
			];
			return $configs;
		};

		add_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();
		$configs = Emails::get_email_configs();
		remove_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();

		$this->assertArrayHasKey( $type, $configs );
		$this->assertSame( '', $configs[ $type ]['trigger_description'] );
		$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
		$this->assertFalse( $configs[ $type ]['recommended'] );
		$this->assertSame( 'auth-account', $configs[ $type ]['chip'] );
	}

	/**
	 * Defensive: a third-party filter that returns a non-array entry at a
	 * key must NOT fatal get_email_configs() — the bad row is dropped and
	 * the other entries pass through unchanged.
	 */
	public function test_email_configs_filter_skips_non_array_entries() {
		$callback = function ( $configs ) {
			$configs['malformed-string']    = 'not-an-array';
			$configs['malformed-null']      = null;
			$configs['valid-third-party']   = [
				'name'     => 'valid-third-party',
				'category' => 'reader-activation',
				'label'    => 'Valid third-party',
			];
			return $configs;
		};

		add_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();
		$configs = Emails::get_email_configs();
		remove_filter( 'newspack_email_configs', $callback );
		Emails::reset_email_configs_cache();

		// Bad rows silently dropped.
		$this->assertArrayNotHasKey( 'malformed-string', $configs );
		$this->assertArrayNotHasKey( 'malformed-null', $configs );
		// Valid row still surfaces with defaults applied.
		$this->assertArrayHasKey( 'valid-third-party', $configs );
		$this->assertSame( 'reader', $configs['valid-third-party']['recipient'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket E — Reader Activation gating
	 * ------------------------------------------------------------------
	 * Covers Emails_Section::filter_configs_by_ra_state(), the helper
	 * api_get_email_settings() uses to scope the visible config set when
	 * Reader Activation is disabled. Tests the helper directly because
	 * Reader_Activation::is_enabled() hard-returns true in the test
	 * environment (see class-reader-activation.php:1013), making the
	 * disabled branch unreachable through the public endpoint.
	 */

	/**
	 * When RA is enabled, all configs pass through unchanged.
	 */
	public function test_filter_configs_by_ra_state_passes_through_when_enabled() {
		$configs = [
			'receipt'                        => [ 'name' => 'receipt' ],
			'reader-activation-verification' => [ 'name' => 'reader-activation-verification' ],
			'group-subscription-invite'      => [ 'name' => 'group-subscription-invite' ],
		];
		$filtered = Emails_Section::filter_configs_by_ra_state( true, $configs );
		$this->assertSame( $configs, $filtered );
	}

	/**
	 * When RA is disabled, only configs tagged with `chip: 'reader-revenue'`
	 * survive — i.e. anything in the reader-revenue grouping regardless of
	 * which provider class registered it.
	 */
	public function test_filter_configs_by_ra_state_scopes_to_reader_revenue_when_disabled() {
		$configs = [
			'receipt'                        => [
				'name' => 'receipt',
				'chip' => 'reader-revenue',
			],
			'welcome'                        => [
				'name' => 'welcome',
				'chip' => 'reader-revenue',
			],
			'group-subscription-invite'      => [
				'name' => 'group-subscription-invite',
				'chip' => 'reader-revenue',
			],
			'reader-activation-verification' => [
				'name' => 'verification',
				'chip' => 'auth-account',
			],
			'reader-activation-magic-link'   => [
				'name' => 'magic-link',
				'chip' => 'auth-account',
			],
			// Edge: a config that somehow lost its chip — must be excluded.
			'untagged'                       => [ 'name' => 'untagged' ],
		];
		$filtered = Emails_Section::filter_configs_by_ra_state( false, $configs );

		// Reader-revenue-chipped configs should survive.
		$this->assertArrayHasKey( 'receipt', $filtered );
		$this->assertArrayHasKey( 'welcome', $filtered );
		$this->assertArrayHasKey( 'group-subscription-invite', $filtered );

		// Auth-account-chipped configs should be dropped.
		$this->assertArrayNotHasKey( 'reader-activation-verification', $filtered );
		$this->assertArrayNotHasKey( 'reader-activation-magic-link', $filtered );

		// Untagged configs (no chip) should also be dropped — conservative.
		$this->assertArrayNotHasKey( 'untagged', $filtered );
	}

	/**
	 * Empty configs in, empty configs out — regardless of RA state.
	 */
	public function test_filter_configs_by_ra_state_handles_empty_configs() {
		$this->assertSame( [], Emails_Section::filter_configs_by_ra_state( true, [] ) );
		$this->assertSame( [], Emails_Section::filter_configs_by_ra_state( false, [] ) );
	}
}
