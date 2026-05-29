<?php
/**
 * Card Expiry Warning — Integration Smoke Test.
 *
 * Usage: wp eval-file tests/integration/card-expiry-warning-smoke.php
 *
 * Requires WooCommerce, WooCommerce Subscriptions, and Newspack Newsletters
 * to be active on the site.
 *
 * Creates temporary fixtures, runs 12 scenarios, and cleans up after itself.
 *
 * Legacy steady-state scenarios (run with SEEDED_OPTION pre-set to '1'
 * so the scan exercises the normal-scan branch, not the first-deploy
 * seed branch — that's covered in scenario 7):
 *
 *  1. Cron scheduled
 *  2. Happy-path send (token → subscription → email)
 *  3. Idempotency (no duplicate send)
 *  4. clear_sent_flag() handler clears meta (called directly; the full
 *     woocommerce_subscription_payment_method_updated action triggers
 *     third-party hooks like WCS PayPal that fatal on a minimal fixture)
 *  5. New card triggers new send
 *  6. Unattached card does not trigger email
 *
 * Publisher-respect scenarios — pin the design intent documented in the
 * "Publisher-respect — first-deploy seed" and "Publisher-respect —
 * per-pass SQL LIMIT" sections of the Card_Expiry_Warning class
 * docblock:
 *
 *  7. First-deploy seed: SEEDED_OPTION absent → seed pass marks
 *     SENT_META on in-window pairs WITHOUT sending, then flips the
 *     option. Subsequent scan takes the normal-scan branch.
 *  8. `$bypass_idempotency=true` sends despite SENT_META (the WP-CLI
 *     backfill's escape-hatch contract).
 *  9. SQL `LIMIT` is applied at query time, not after fetch.
 * 10. CLI dry-run: no emails sent.
 * 11. CLI normal run: sends despite SENT_META (uses bypass under the hood).
 * 12. Cleanup.
 *
 * @package Newspack\Tests
 */

use Newspack\Card_Expiry_Warning;
use Newspack\CLI\WooCommerce_Subscriptions as CLI_WC_Subscriptions;
use Newspack\Emails;
use Newspack\Reader_Activation;

// ── Globals ──────────────────────────────────────────────────────────
// wp eval-file runs via eval(), so file-scope vars are local to the eval
// context. Named functions use `global` which references $GLOBALS. Declare
// these as global so both scopes see the same variables.
global $passed, $total, $mails, $cleanup;
$passed  = 0;
$total   = 12;
$mails   = [];
$cleanup = []; // Closures executed in reverse order during cleanup.

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Record a passing scenario.
 *
 * @param string $label Description.
 */
function smoke_pass( string $label ): void {
	global $passed;
	++$passed;
	WP_CLI::log( "  PASS: $label" );
}

/**
 * Record a failing scenario.
 *
 * @param string $label  Description.
 * @param string $detail Optional extra info.
 */
function smoke_fail( string $label, string $detail = '' ): void {
	WP_CLI::log( "  FAIL: $label" . ( $detail ? " -- $detail" : '' ) );
}

// ── Prerequisites ────────────────────────────────────────────────────
WP_CLI::log( '' );
WP_CLI::log( '== Card Expiry Warning -- Smoke Test ==' );
WP_CLI::log( '' );

$prereqs = [
	'WC_Payment_Token_CC'                      => 'WooCommerce (WC_Payment_Token_CC)',
	'WC_Payment_Tokens'                        => 'WooCommerce (WC_Payment_Tokens)',
	'WCS_Payment_Tokens'                       => 'WooCommerce Subscriptions (WCS_Payment_Tokens)',
	'Newspack\\Card_Expiry_Warning'            => 'Newspack Card_Expiry_Warning',
	'Newspack\\Emails'                         => 'Newspack Emails',
	'Newspack\\CLI\\WooCommerce_Subscriptions' => 'Newspack\\CLI\\WooCommerce_Subscriptions',
	'Newspack_Newsletters'                     => 'Newspack Newsletters',
];
foreach ( $prereqs as $class => $label ) {
	if ( ! class_exists( $class ) ) {
		WP_CLI::error( "Prerequisite not met: $label ($class). Aborting." );
	}
}
if ( ! function_exists( 'wcs_create_subscription' ) ) {
	WP_CLI::error( 'wcs_create_subscription() not available. Aborting.' );
}

// Card_Expiry_Warning::init() only registers the email config when
// WooCommerce_Subscriptions::is_enabled() returns true, which in turn
// requires Reader Activation to be enabled. Without this, scan_expiring_cards()
// silently captures 0 emails and the test gives a misleading failure.
if ( ! Reader_Activation::is_enabled() ) {
	WP_CLI::error( 'Reader Activation is not enabled. Aborting.' );
}
WP_CLI::log( 'Prerequisites OK.' );

// ── Pre-set SEEDED_OPTION ────────────────────────────────────────────
// Legacy scenarios 1-6 + bypass + LIMIT + CLI scenarios test the
// STEADY-STATE behavior (post-seed normal-scan path). Scenario 7
// explicitly tests the first-deploy seed by clearing the option,
// then resets the fixture state. Without pre-setting here, every
// pre-7 scan would take the seed branch and the "send" expectations
// would never trigger.
$prior_seeded_option = get_option( Card_Expiry_Warning::SEEDED_OPTION );
update_option( Card_Expiry_Warning::SEEDED_OPTION, '1', false );
$cleanup[] = function () use ( $prior_seeded_option ) {
	if ( false === $prior_seeded_option ) {
		delete_option( Card_Expiry_Warning::SEEDED_OPTION );
	} else {
		update_option( Card_Expiry_Warning::SEEDED_OPTION, $prior_seeded_option, false );
	}
};

// Snapshot the pre-smoke cron schedule so cleanup can restore it
// (Scenario 1 clears + re-schedules the hook; if we let cleanup nuke
// it unconditionally, the host site loses its production cron until
// the next pageload runs init).
$prior_cron_scheduled = wp_next_scheduled( Card_Expiry_Warning::CRON_HOOK );
$cleanup[]            = function () use ( $prior_cron_scheduled ) {
	wp_clear_scheduled_hook( Card_Expiry_Warning::CRON_HOOK );
	if ( $prior_cron_scheduled ) {
		wp_schedule_event( $prior_cron_scheduled, 'daily', Card_Expiry_Warning::CRON_HOOK );
	}
};

// ── Intercept wp_mail via pre_wp_mail ────────────────────────────────
// Returning non-null from pre_wp_mail short-circuits wp_mail() without
// actually sending. We capture the args and return true ("sent OK").
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);

// ── Widen scan window for reliable expiry detection ──────────────────
// A CC token "expires" at the end of its expiry month. The scan query
// finds tokens whose LAST_DAY(expiry) falls in [today, today + N days].
// End-of-current-month is at most ~30 days away, so a 32-day window
// guarantees the token is within range regardless of when the test runs.
add_filter(
	'newspack_card_expiry_warning_days',
	function () {
		return 32;
	},
	99
);

// ── Create test user ─────────────────────────────────────────────────
$rand    = wp_rand( 10000, 99999 );
$user_id = wp_insert_user(
	[
		'user_login' => "smoke_cew_$rand",
		'user_email' => "smoke-cew-$rand@example.test",
		'user_pass'  => wp_generate_password(),
		'first_name' => 'Smoke',
		'last_name'  => 'Tester',
		'role'       => 'subscriber',
	]
);
if ( is_wp_error( $user_id ) ) {
	WP_CLI::error( 'Could not create test user: ' . $user_id->get_error_message() );
}
$cleanup[] = function () use ( $user_id ) {
	wp_delete_user( $user_id );
};
WP_CLI::log( "  Created user #$user_id." );

// ── Create CC token 1 (expires end of current month) ─────────────────
$expiry_month = (int) gmdate( 'n' );
$expiry_year  = (int) gmdate( 'Y' );

$token1 = new WC_Payment_Token_CC();
$token1->set_gateway_id( 'stripe' );
$token1->set_token( 'pm_smoke_' . $rand . '_a' );
$token1->set_last4( '4242' );
$token1->set_expiry_month( str_pad( $expiry_month, 2, '0', STR_PAD_LEFT ) );
$token1->set_expiry_year( (string) $expiry_year );
$token1->set_card_type( 'visa' );
$token1->set_user_id( $user_id );
$token1->save();
$cleanup[] = function () use ( $token1 ) {
	$token1->delete( true );
};
WP_CLI::log( "  Created token #{$token1->get_id()} (last4=4242, exp=$expiry_month/$expiry_year)." );

// ── Create WC Subscription linked to token 1 ────────────────────────
$subscription = wcs_create_subscription(
	[
		'customer_id'      => $user_id,
		'status'           => 'active',
		'billing_period'   => 'month',
		'billing_interval' => 1,
	]
);
if ( is_wp_error( $subscription ) ) {
	WP_CLI::error( 'Could not create subscription: ' . $subscription->get_error_message() );
}
$sub_id = $subscription->get_id();

$subscription->set_billing_email( "smoke-cew-$rand@example.test" );
$subscription->set_billing_first_name( 'Smoke' );
$subscription->set_payment_method( 'stripe' );

// Mark as automatic renewal — wcs_create_subscription() defaults to
// manual, and get_subscriptions_from_token() excludes manual-renewal subs.
$subscription->set_requires_manual_renewal( false );

// Store token string in gateway-specific meta so
// WCS_Payment_Tokens::get_subscriptions_from_token() can match it
// (key-less meta_query on the raw token string).
$subscription->update_meta_data( '_stripe_source_id', $token1->get_token() );

// Set a future next-payment date.
$subscription->update_dates(
	[ 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ]
);
$subscription->save();

$cleanup[] = function () use ( $sub_id ) {
	wp_delete_post( $sub_id, true );
};
WP_CLI::log( "  Created subscription #$sub_id." );


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 1: Cron scheduled
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '1. Cron scheduled' );

// Clear any existing schedule first — `init()` may have already
// scheduled the hook on plugin load, which would make this assertion
// pass even if schedule_cron() itself silently regressed (e.g. typo'd
// recurrence). The cleanup at the bottom of the script restores the
// pre-smoke schedule via $prior_cron_scheduled.
wp_clear_scheduled_hook( Card_Expiry_Warning::CRON_HOOK );
Card_Expiry_Warning::schedule_cron();

if ( wp_next_scheduled( Card_Expiry_Warning::CRON_HOOK ) ) {
	smoke_pass( 'Cron event is scheduled.' );
} else {
	smoke_fail( 'Cron event was not scheduled.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 2: Happy-path send
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '2. Happy-path send' );

$mails = [];
Card_Expiry_Warning::scan_expiring_cards();

$s2_ok = true;
if ( count( $mails ) !== 1 ) {
	smoke_fail( 'Expected exactly 1 email, got ' . count( $mails ) . '.' );
	if ( count( $mails ) === 0 ) {
		// Debug: check prerequisites.
		if ( ! Emails::can_send_email( 'card-expiry-warning' ) ) {
			WP_CLI::log( '    (Debug: Emails::can_send_email returned false.)' );
		}
	}
	$s2_ok = false;
} else {
	$mail = $mails[0];

	// Check recipient.
	if ( $mail['to'] !== "smoke-cew-$rand@example.test" ) {
		smoke_fail( "Wrong recipient: expected smoke-cew-$rand@example.test, got {$mail['to']}." );
		$s2_ok = false;
	}

	// Check body contains last-4 and expiry.
	$body = $mail['message'] ?? '';
	if ( false === strpos( $body, '4242' ) ) {
		smoke_fail( 'Email body missing card last-4 "4242".' );
		$s2_ok = false;
	}
	$expiry_str = $token1->get_expiry_month() . '/' . $token1->get_expiry_year();
	if ( false === strpos( $body, $expiry_str ) ) {
		smoke_fail( "Email body missing expiry \"$expiry_str\"." );
		$s2_ok = false;
	}

	// Check idempotency meta.
	$subscription  = wcs_get_subscription( $sub_id );
	$meta_val      = $subscription->get_meta( '_newspack_card_expiry_warning_sent', true );
	$expected_meta = $token1->get_id() . ':' . $token1->get_expiry_month() . '/' . $token1->get_expiry_year();
	if ( $meta_val !== $expected_meta ) {
		smoke_fail( "Idempotency meta mismatch: expected '$expected_meta', got '$meta_val'." );
		$s2_ok = false;
	}
}

if ( $s2_ok ) {
	smoke_pass( 'Correct recipient, body tokens, and idempotency meta.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 3: Idempotency — re-running scan sends nothing
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '3. Idempotency (no duplicate send)' );

$mails = [];
Card_Expiry_Warning::scan_expiring_cards();

if ( 0 === count( $mails ) ) {
	$subscription  = wcs_get_subscription( $sub_id );
	$meta_val      = $subscription->get_meta( '_newspack_card_expiry_warning_sent', true );
	$expected_meta = $token1->get_id() . ':' . $token1->get_expiry_month() . '/' . $token1->get_expiry_year();

	if ( $meta_val === $expected_meta ) {
		smoke_pass( 'No duplicate email; meta unchanged.' );
	} else {
		smoke_fail( "Meta changed unexpectedly to '$meta_val'." );
	}
} else {
	smoke_fail( 'Duplicate email sent (' . count( $mails ) . ' captured).' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 4: clear_sent_flag() handler clears idempotency meta
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '4. clear_sent_flag() handler clears meta' );

// Call clear_sent_flag() directly rather than firing the full
// woocommerce_subscription_payment_method_updated action. That action
// triggers third-party hooks (WCS PayPal, etc.) that fatal with our
// minimal test fixture.
$subscription = wcs_get_subscription( $sub_id );
Card_Expiry_Warning::clear_sent_flag( $subscription );

$subscription = wcs_get_subscription( $sub_id );
$meta_val     = $subscription->get_meta( '_newspack_card_expiry_warning_sent', true );

if ( empty( $meta_val ) ) {
	smoke_pass( 'Idempotency meta cleared.' );
} else {
	smoke_fail( "Meta should be empty after clear, got '$meta_val'." );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 5: New card in window triggers a new send
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '5. New card triggers new send' );

$token2 = new WC_Payment_Token_CC();
$token2->set_gateway_id( 'stripe' );
$token2->set_token( 'pm_smoke_' . $rand . '_b' );
$token2->set_last4( '1234' );
$token2->set_expiry_month( str_pad( $expiry_month, 2, '0', STR_PAD_LEFT ) );
$token2->set_expiry_year( (string) $expiry_year );
$token2->set_card_type( 'mastercard' );
$token2->set_user_id( $user_id );
$token2->save();
$cleanup[] = function () use ( $token2 ) {
	$token2->delete( true );
};
WP_CLI::log( "  Created token #{$token2->get_id()} (last4=1234)." );

// Point subscription at the new token.
$subscription = wcs_get_subscription( $sub_id );
$subscription->update_meta_data( '_stripe_source_id', $token2->get_token() );
$subscription->save();

$mails = [];
Card_Expiry_Warning::scan_expiring_cards();

$s5_ok = true;
if ( count( $mails ) < 1 ) {
	smoke_fail( 'No email sent for the new token.' );
	$s5_ok = false;
} else {
	$mail = $mails[ count( $mails ) - 1 ];
	$body = $mail['message'] ?? '';

	if ( false === strpos( $body, '1234' ) ) {
		smoke_fail( 'Email body missing new card last-4 "1234".' );
		$s5_ok = false;
	}

	$subscription  = wcs_get_subscription( $sub_id );
	$meta_val      = $subscription->get_meta( '_newspack_card_expiry_warning_sent', true );
	$expected_meta = $token2->get_id() . ':' . $token2->get_expiry_month() . '/' . $token2->get_expiry_year();
	if ( $meta_val !== $expected_meta ) {
		smoke_fail( "Meta should reflect token2: expected '$expected_meta', got '$meta_val'." );
		$s5_ok = false;
	}
}

if ( $s5_ok ) {
	smoke_pass( 'New card triggered email with correct last-4 and updated meta.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 6: Unattached card does NOT trigger an email
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '6. Unattached card does not trigger email' );

// Create a separate user + token with no subscription.
$orphan_user_id = wp_insert_user(
	[
		'user_login' => "smoke_cew_orphan_$rand",
		'user_email' => "smoke-orphan-$rand@example.test",
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	]
);
if ( is_wp_error( $orphan_user_id ) ) {
	WP_CLI::error( 'Could not create orphan user: ' . $orphan_user_id->get_error_message() );
}
$cleanup[] = function () use ( $orphan_user_id ) {
	wp_delete_user( $orphan_user_id );
};

$token3 = new WC_Payment_Token_CC();
$token3->set_gateway_id( 'stripe' );
$token3->set_token( 'pm_smoke_' . $rand . '_c' );
$token3->set_last4( '9999' );
$token3->set_expiry_month( str_pad( $expiry_month, 2, '0', STR_PAD_LEFT ) );
$token3->set_expiry_year( (string) $expiry_year );
$token3->set_card_type( 'amex' );
$token3->set_user_id( $orphan_user_id );
$token3->save();
$cleanup[] = function () use ( $token3 ) {
	$token3->delete( true );
};
WP_CLI::log( "  Created orphan token #{$token3->get_id()} (last4=9999, no subscription)." );

$mails = [];
Card_Expiry_Warning::scan_expiring_cards();

if ( 0 === count( $mails ) ) {
	smoke_pass( 'No email sent for unattached card.' );
} else {
	$orphan_hit = false;
	foreach ( $mails as $mail_item ) {
		if ( false !== strpos( $mail_item['to'], 'orphan' ) ) {
			$orphan_hit = true;
		}
	}
	if ( $orphan_hit ) {
		smoke_fail( 'Email was sent to orphan user with no subscription.' );
	} else {
		smoke_fail( count( $mails ) . ' unexpected email(s) sent (not to orphan user).' );
	}
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 7: First-deploy seed (publisher-respect machinery)
//
// Pins the "Publisher-respect — first-deploy seed" section of the
// Card_Expiry_Warning class docblock: with SEEDED_OPTION absent, the
// scan runs a SEED pass that marks SENT_META on every in-window pair
// WITHOUT sending, then flips the option. The next scan takes the
// normal-scan path.
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '7. First-deploy seed (no send, SENT_META marked, option flipped)' );

// Reset state: unset the option (simulates a fresh install) and clear
// any SENT_META from earlier scenarios.
delete_option( Card_Expiry_Warning::SEEDED_OPTION );
$subscription = wcs_get_subscription( $sub_id );
$subscription->delete_meta_data( '_newspack_card_expiry_warning_sent' );
$subscription->save();

$mails = [];
Card_Expiry_Warning::scan_expiring_cards();

$s7_ok = true;
if ( 0 !== count( $mails ) ) {
	smoke_fail( 'Seed pass sent ' . count( $mails ) . ' email(s); must send zero.' );
	$s7_ok = false;
}
if ( '1' !== get_option( Card_Expiry_Warning::SEEDED_OPTION ) ) {
	smoke_fail( 'SEEDED_OPTION should be "1" after the seed pass.' );
	$s7_ok = false;
}
$subscription  = wcs_get_subscription( $sub_id );
$meta_val      = $subscription->get_meta( '_newspack_card_expiry_warning_sent', true );
$expected_meta = $token2->get_id() . ':' . $token2->get_expiry_month() . '/' . $token2->get_expiry_year();
if ( $meta_val !== $expected_meta ) {
	smoke_fail( "Seed pass did not mark SENT_META; expected '$expected_meta', got '$meta_val'." );
	$s7_ok = false;
}
if ( $s7_ok ) {
	smoke_pass( 'No emails sent, SENT_META marked on in-window pair, SEEDED_OPTION flipped.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 8: $bypass_idempotency=true sends despite SENT_META
//
// The WP-CLI backfill's escape-hatch contract: a publisher who wants to
// send the deferred warnings after a seed-suppressed first deploy can
// run the CLI command, which bypasses the seeded SENT_META.
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '8. $bypass_idempotency=true sends despite SENT_META' );

// SENT_META is set from scenario 7. Direct call should send anyway.
$subscription = wcs_get_subscription( $sub_id );
$mails        = [];
$sent         = Card_Expiry_Warning::maybe_send_warning( $subscription, $token2, true );

$s8_ok = true;
if ( true !== $sent ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Diagnostic output in a manual smoke script, not production code.
	smoke_fail( 'maybe_send_warning(... true) returned ' . var_export( $sent, true ) . '; expected true.' );
	$s8_ok = false;
}
if ( 1 !== count( $mails ) ) {
	smoke_fail( 'Expected exactly 1 email with bypass; got ' . count( $mails ) . '.' );
	$s8_ok = false;
}
if ( $s8_ok ) {
	smoke_pass( 'Bypass flag sent despite pre-existing SENT_META.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 9: SQL `LIMIT` is applied at query time
//
// Pins the "Publisher-respect — per-pass SQL LIMIT" section of the
// Card_Expiry_Warning class docblock. Sets limit_per_pass=1 via the
// filter, calls get_in_window_pairs($days, $limit), asserts AT MOST 1
// pair is returned. The script's $token1 + $token2 are both in window;
// without LIMIT this would return 2 (or be capped by upstream).
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '9. SQL LIMIT respected at query time' );

// We need both token1 and token2 to be discovered. Token1 currently
// has no associated subscription (sub was repointed at token2 in
// scenario 5). Add token1 to a second subscription so both tokens
// have an active sub.
$subscription2 = wcs_create_subscription(
	[
		'customer_id'      => $user_id,
		'status'           => 'active',
		'billing_period'   => 'month',
		'billing_interval' => 1,
	]
);
if ( is_wp_error( $subscription2 ) ) {
	WP_CLI::error( 'Could not create second subscription for LIMIT test: ' . $subscription2->get_error_message() );
}
$sub2_id = $subscription2->get_id();
$subscription2->set_billing_email( "smoke-cew-$rand@example.test" );
$subscription2->set_billing_first_name( 'Smoke' );
$subscription2->set_payment_method( 'stripe' );
$subscription2->set_requires_manual_renewal( false );
$subscription2->update_meta_data( '_stripe_source_id', $token1->get_token() );
$subscription2->update_dates(
	[ 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ]
);
$subscription2->save();
$cleanup[] = function () use ( $sub2_id ) {
	wp_delete_post( $sub2_id, true );
};

// First verify the un-capped baseline returns BOTH pairs (proves the
// fixture is set up correctly), then assert limit=1 returns exactly 1
// (proves the SQL LIMIT actually caps — `<= 1` would pass on a broken
// discovery returning 0 and silently mask a regression).
$days     = Card_Expiry_Warning::get_days_before_expiry();
$baseline = Card_Expiry_Warning::get_in_window_pairs( $days, PHP_INT_MAX );
if ( count( $baseline ) < 2 ) {
	smoke_fail( 'LIMIT-test fixture is wrong: uncapped baseline returned ' . count( $baseline ) . ' pairs; expected 2.' );
} else {
	$pairs = Card_Expiry_Warning::get_in_window_pairs( $days, 1 );
	if ( 1 === count( $pairs ) ) {
		smoke_pass( 'limit=1 returned exactly 1 pair (uncapped baseline = ' . count( $baseline ) . ').' );
	} else {
		smoke_fail( 'limit=1 returned ' . count( $pairs ) . ' pairs; SQL LIMIT not applied at query time.' );
	}
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 10: CLI dry-run does NOT send
//
// Clear SENT_META so there's an in-window pair to back-fill, then call
// the CLI method with --dry-run + --yes. Expect 0 captured emails.
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '10. CLI dry-run does not send' );

$subscription = wcs_get_subscription( $sub_id );
$subscription->delete_meta_data( '_newspack_card_expiry_warning_sent' );
$subscription->save();
$subscription2 = wcs_get_subscription( $sub2_id );
$subscription2->delete_meta_data( '_newspack_card_expiry_warning_sent' );
$subscription2->save();

$cli   = new CLI_WC_Subscriptions();
$mails = [];
$cli->card_expiry_warning_backfill(
	[],
	[
		'dry-run' => true,
		'yes'     => true,
	]
);

if ( 0 === count( $mails ) ) {
	smoke_pass( 'CLI --dry-run captured 0 emails (no real sends).' );
} else {
	smoke_fail( 'CLI --dry-run sent ' . count( $mails ) . ' email(s); must send zero.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 11: CLI normal run sends despite SENT_META
//
// Set SENT_META on sub1 to simulate the post-seed state. CLI should
// bypass it and send anyway (calls maybe_send_warning with bypass=true
// under the hood).
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '11. CLI normal run sends despite SENT_META' );

$subscription  = wcs_get_subscription( $sub_id );
$expected_meta = $token2->get_id() . ':' . $token2->get_expiry_month() . '/' . $token2->get_expiry_year();
$subscription->update_meta_data( '_newspack_card_expiry_warning_sent', $expected_meta );
$subscription->save();

$mails = [];
$cli->card_expiry_warning_backfill(
	[],
	[ 'yes' => true ]
);

if ( count( $mails ) >= 1 ) {
	smoke_pass( 'CLI normal run sent ' . count( $mails ) . ' email(s) despite SENT_META.' );
} else {
	smoke_fail( 'CLI normal run sent 0 emails; bypass not applied.' );
}


// ══════════════════════════════════════════════════════════════════════
// SCENARIO 12: Cleanup
// ══════════════════════════════════════════════════════════════════════
WP_CLI::log( '' );
WP_CLI::log( '12. Cleanup' );

$clean_ok = true;
foreach ( array_reverse( $cleanup ) as $fn ) {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		WP_CLI::log( '    Cleanup error: ' . $e->getMessage() );
		$clean_ok = false;
	}
}

// Remove filters added by this script.
remove_all_filters( 'pre_wp_mail' );
remove_all_filters( 'newspack_card_expiry_warning_days' );
remove_all_filters( 'newspack_card_expiry_warning_limit_per_pass' );

// Cron schedule restoration is handled by the snapshot closure
// pushed onto $cleanup at the top of the script — do NOT
// wp_clear_scheduled_hook here, that would nuke the restore.

if ( $clean_ok ) {
	smoke_pass( 'All fixtures cleaned up.' );
} else {
	smoke_fail( 'Some cleanup steps failed (see above).' );
}


// ── Summary ──────────────────────────────────────────────────────────
WP_CLI::log( '' );
WP_CLI::log( "== $passed/$total PASSED ==" );
WP_CLI::log( '' );

exit( $passed === $total ? 0 : 1 );
