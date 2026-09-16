<?php
/**
 * Integration tests for Settings page.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Admin;

use WP_UnitTestCase;
use WpResendNewsletter\Admin\SettingsPage;

/**
 * @covers \WpResendNewsletter\Admin\SettingsPage
 */
class SettingsPage_Test extends WP_UnitTestCase {

	/**
	 * Reset options between tests.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( SettingsPage::OPTION_NAME );
	}

	/**
	 * Scenario: Valid settings save.
	 *
	 * Given an admin with manage_options
	 * When they submit a non-empty API key, from email, and sanitization runs
	 * Then option wprn_settings stores from email and api key.
	 */
	public function test_valid_settings_save_persists_schema(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$input = array(
			'from_email'     => 'news@example.com',
			'from_name'      => 'News Desk',
			'api_key'        => 're_test_xxx',
			'webhook_secret' => '',
		);

		$sanitized = SettingsPage::sanitize_settings( $input );
		update_option( SettingsPage::OPTION_NAME, $sanitized );

		$stored = get_option( SettingsPage::OPTION_NAME );

		$this->assertSame( 'news@example.com', $stored['from_email'] );
		$this->assertSame( 'News Desk', $stored['from_name'] );
		$this->assertSame( 're_test_xxx', $stored['api_key'] );
		$this->assertArrayHasKey( 'webhook_secret', $stored );
	}

	/**
	 * Scenario: API key is masked in rendered HTML (never full key).
	 */
	public function test_api_key_field_masks_existing_key(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => 're_test_secret_full_key_value',
				'webhook_secret' => '',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		SettingsPage::render_api_key_field( array( 'description' => '' ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 're_test_secret_full_key_value', $html );
		$this->assertStringContainsString( 'wprn-api-key-masked', $html );
	}

	/**
	 * Scenario: Insufficient capability is rejected on render.
	 */
	public function test_subscriber_cannot_render_settings_page(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->expectException( \WPDieException::class );
		SettingsPage::render_page();
	}

	/**
	 * Scenario: Clear-key action without nonce is rejected.
	 */
	public function test_clear_api_key_rejects_missing_nonce(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => 're_should_remain',
				'webhook_secret' => '',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_POST = array(); // no nonce

		try {
			SettingsPage::handle_clear_api_key();
			$this->fail( 'Expected wp_die on missing nonce' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$stored = get_option( SettingsPage::OPTION_NAME );
		$this->assertSame( 're_should_remain', $stored['api_key'] );
	}

	/**
	 * Scenario: Clear-key with subscriber capability is rejected.
	 */
	public function test_clear_api_key_rejects_insufficient_capability(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => 're_should_remain',
				'webhook_secret' => '',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$_POST['_wpnonce'] = wp_create_nonce( SettingsPage::CLEAR_KEY_ACTION );

		try {
			SettingsPage::handle_clear_api_key();
			$this->fail( 'Expected wp_die on insufficient capability' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$stored = get_option( SettingsPage::OPTION_NAME );
		$this->assertSame( 're_should_remain', $stored['api_key'] );
	}

	/**
	 * Defaults expose the expected schema keys.
	 */
	public function test_defaults_include_schema_keys(): void {
		$defaults = SettingsPage::get_defaults();

		$this->assertArrayHasKey( 'from_email', $defaults );
		$this->assertArrayHasKey( 'from_name', $defaults );
		$this->assertArrayHasKey( 'api_key', $defaults );
		$this->assertArrayHasKey( 'webhook_secret', $defaults );
	}

	/**
	 * Empty api_key input preserves existing stored key.
	 */
	public function test_empty_api_key_input_preserves_existing(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'old@example.com',
				'from_name'      => 'Old',
				'api_key'        => 're_existing',
				'webhook_secret' => '',
			)
		);

		$sanitized = SettingsPage::sanitize_settings(
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => 'News',
				'api_key'        => '',
				'webhook_secret' => '',
			)
		);

		$this->assertSame( 're_existing', $sanitized['api_key'] );
		$this->assertSame( 'news@example.com', $sanitized['from_email'] );
	}

	/**
	 * Scenario: Constant override shows read-only hint (no key input).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_api_key_field_shows_constant_override_hint(): void {
		if ( ! defined( 'WPRN_RESEND_API_KEY' ) ) {
			define( 'WPRN_RESEND_API_KEY', 're_from_wp_config_constant' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		SettingsPage::render_api_key_field( array( 'description' => '' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wprn-api-key-constant', $html );
		$this->assertStringContainsString( 'WPRN_RESEND_API_KEY', $html );
		$this->assertStringNotContainsString( 're_from_wp_config_constant', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::OPTION_NAME . '[api_key]"', $html );
	}

	/**
	 * Webhook secret must never appear in full in rendered HTML.
	 */
	public function test_webhook_secret_field_masks_existing_secret(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => '',
				'webhook_secret' => 'whsec_super_secret_value_12345',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		SettingsPage::render_webhook_secret_field( array( 'description' => '' ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'whsec_super_secret_value_12345', $html );
		$this->assertStringContainsString( 'wprn-webhook-secret-masked', $html );
	}

	/**
	 * Empty webhook_secret input preserves existing stored secret.
	 */
	public function test_empty_webhook_secret_input_preserves_existing(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => '',
				'webhook_secret' => 'whsec_keep_me',
			)
		);

		$sanitized = SettingsPage::sanitize_settings(
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => '',
				'webhook_secret' => '',
			)
		);

		$this->assertSame( 'whsec_keep_me', $sanitized['webhook_secret'] );
	}

	/**
	 * Scenario: Insufficient capability — sanitize leaves settings unchanged.
	 */
	public function test_sanitize_rejects_insufficient_capability(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'keep@example.com',
				'from_name'      => 'Keep',
				'api_key'        => 're_keep',
				'webhook_secret' => '',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$sanitized = SettingsPage::sanitize_settings(
			array(
				'from_email'     => 'hacked@example.com',
				'from_name'      => 'Hacked',
				'api_key'        => 're_hacked',
				'webhook_secret' => 'hacked',
			)
		);

		$this->assertSame( 'keep@example.com', $sanitized['from_email'] );
		$this->assertSame( 're_keep', $sanitized['api_key'] );
	}
}
