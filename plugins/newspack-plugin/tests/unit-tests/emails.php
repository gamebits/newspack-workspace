<?php
/**
 * Tests Reader Revenue Emails.
 *
 * @package Newspack\Tests
 */

use Newspack\Plugin_Manager;
use Newspack\Emails;

/**
 * Tests Reader Revenue Emails.
 */
class Newspack_Test_Emails extends WP_UnitTestCase {
	/**
	 * Setup.
	 */
	public function set_up() {
		reset_phpmailer_instance();
		add_filter(
			'newspack_email_configs',
			function ( $types ) {
				$types['test-email-config'] = [
					'name'        => 'test-email-config',
					'label'       => __( 'Test config', 'newspack' ),
					'description' => __( 'Email sent to test things.', 'newspack' ),
					'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/receipt.php',
					'category'    => 'test',
				];
				return $types;
			}
		);
		Emails::reset_email_configs_cache();
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		reset_phpmailer_instance();
		Emails::reset_email_configs_cache();
		delete_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_name' );
		delete_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_email_address' );
		delete_option( \Newspack\Reader_Activation::OPTIONS_PREFIX . 'contact_email_address' );
	}

	/**
	 * NPPD-1566: the saved sender/contact overrides are honored by the
	 * send path REGARDLESS of Reader Activation state. Reader Activation
	 * is disabled in the test environment by default, so these assertions
	 * exercise exactly the previously-broken path: a publisher saves the
	 * settings via the Emails modal with RA off, and the send path must
	 * use them instead of silently falling back to derived defaults.
	 */
	public function test_sender_settings_honored_regardless_of_reader_activation() {
		$prefix = \Newspack\Reader_Activation::OPTIONS_PREFIX;
		update_option( $prefix . 'sender_name', 'Custom Sender' );
		update_option( $prefix . 'sender_email_address', 'sender@example.com' );
		update_option( $prefix . 'contact_email_address', 'contact@example.com' );

		self::assertSame( 'Custom Sender', Emails::get_from_name(), 'Saved sender_name must be honored with RA off.' );
		self::assertSame( 'sender@example.com', Emails::get_from_email(), 'Saved sender_email must be honored with RA off.' );
		self::assertSame( 'contact@example.com', Emails::get_reply_to_email(), 'Saved contact_email must be honored with RA off.' );
	}

	/**
	 * An empty (or absent) saved value is the revert-to-default signal:
	 * the send path falls back to the derived default rather than sending
	 * with a blank sender.
	 */
	public function test_sender_settings_fall_back_to_defaults_when_empty() {
		$prefix = \Newspack\Reader_Activation::OPTIONS_PREFIX;
		update_option( $prefix . 'sender_name', '' );
		delete_option( $prefix . 'sender_email_address' );

		self::assertSame( get_bloginfo( 'name' ), Emails::get_from_name(), 'Empty sender_name must fall back to the site title.' );
		self::assertStringStartsWith( 'no-reply@', Emails::get_from_email(), 'Absent sender_email must fall back to the derived no-reply default.' );
	}

	/**
	 * NPPD-1575: the option-layer guard (registered via register_setting)
	 * validates the email keys for EVERY writer — including a direct
	 * update_option() that bypasses the Emails route's inline is_email().
	 * A valid address is stored; a malformed one is coerced to the
	 * last-good stored value (never wiping a good address); empty is the
	 * revert signal and is preserved.
	 */
	public function test_email_option_sanitization_applies_to_any_writer() {
		Emails::register_sender_settings(); // Ensure the sanitize filters are attached.
		$option = \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_email_address';

		// Valid address is stored (sanitized).
		update_option( $option, 'good@example.com' );
		self::assertSame( 'good@example.com', get_option( $option ), 'Valid address must persist.' );

		// Malformed non-empty write (bypassing the route guard) is coerced
		// to the last-good stored value, not allowed to wipe it.
		update_option( $option, 'not-an-email' );
		self::assertSame( 'good@example.com', get_option( $option ), 'Invalid address must coerce to last-good, not overwrite it.' );

		// Empty is the revert-to-default signal and is preserved.
		update_option( $option, '' );
		self::assertSame( '', get_option( $option ), 'Empty must be preserved as the revert signal.' );
	}

	/**
	 * The sender_name option strips CR/LF via sanitize_text_field at the
	 * option layer, so a From:-header injection attempt can't be stored
	 * even by a writer that skips the route.
	 */
	public function test_sender_name_option_strips_newlines() {
		Emails::register_sender_settings();
		$option = \Newspack\Reader_Activation::OPTIONS_PREFIX . 'sender_name';

		update_option( $option, "Evil\r\nBcc: victim@example.com" );
		self::assertStringNotContainsString( "\r", get_option( $option ), 'CR must be stripped from sender_name.' );
		self::assertStringNotContainsString( "\n", get_option( $option ), 'LF must be stripped from sender_name.' );
	}

	/**
	 * Get an email, by type.
	 *
	 * @param string $type Email type.
	 */
	private static function get_test_email( $type ) {
		return Emails::get_emails()[ $type ];
	}

	/**
	 * Email setup & defaults generation.
	 */
	public function test_emails_setup() {
		self::assertTrue(
			Emails::supports_emails(),
			'Emails are configured after Newspack Newsletters plugin is active.'
		);

		self::assertTrue(
			Emails::can_send_email( 'test-email-config' ),
			'Test email can now be sent.'
		);

		$emails     = Emails::get_emails( [ 'test-email-config' ] );
		$test_email = $emails['test-email-config'];
		self::assertEquals(
			'Test config',
			$test_email['label'],
			'Test email has the expected label'
		);
		self::assertEquals(
			'Thank you!',
			$test_email['subject'],
			'Test email has the expected subject'
		);
		self::assertStringContainsString(
			'<!doctype html>',
			$test_email['html_payload'],
			'Test email has the HTML payload'
		);
	}

	/**
	 * Email sending, with a template.
	 */
	public function test_emails_send_with_template() {
		$test_email = self::get_test_email( 'test-email-config' );

		$recipient    = 'tester@tests.com';
		$amount       = '$42';
		$placeholders = [
			[
				'template' => '*AMOUNT*',
				'value'    => $amount,
			],
		];
		$send_result  = Emails::send_email(
			'test-email-config',
			$recipient,
			$placeholders
		);

		self::assertTrue( $send_result, 'Email has been sent.' );

		$mailer = tests_retrieve_phpmailer_instance();

		self::assertContains(
			$recipient,
			$mailer->get_sent()->to[0],
			'Sent email has the expected recipient'
		);
		self::assertEquals(
			$test_email['subject'],
			$mailer->get_sent()->subject,
			'Sent email has the expected subject'
		);
		self::assertStringContainsString(
			'From: Test Blog <no-reply@example.org>',
			$mailer->get_sent()->header,
			'Sent email has the expected "From" header'
		);
		self::assertStringContainsString(
			$amount,
			$mailer->get_sent()->body,
			'Sent email contains the replaced placeholder content'
		);
	}

	/**
	 * Sending by email id.
	 */
	public function test_emails_send_by_id() {
		$test_email = self::get_test_email( 'test-email-config' );

		$send_result = Emails::send_email(
			$test_email['post_id'],
			'someone@example.com'
		);
		self::assertTrue( $send_result, 'Email has been sent.' );

		$send_result = Emails::send_email(
			9999,
			'someone@example.com'
		);
		self::assertFalse( $send_result, 'Non-existent email is not sent.' );
	}

	/**
	 * Email post status handling.
	 */
	public function test_emails_status() {
		$test_email = self::get_test_email( 'test-email-config' );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		self::assertFalse( Emails::can_send_email( 'test-email-config' ), 'Email can\'t be sent – it\'s not published.' );
		$send_result = Emails::send_email(
			'test-email-config',
			'someone@example.com'
		);

		self::assertFalse( $send_result, 'Email has not been sent.' );
	}
}
