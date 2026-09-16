<?php
/**
 * Integration tests for ResendClient WP credential resolution.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Infrastructure;

use WP_UnitTestCase;
use WpResendNewsletter\Infrastructure\ResendClient;

/**
 * @covers \WpResendNewsletter\Infrastructure\ResendClient
 */
class ResendClient_Test extends WP_UnitTestCase {

	/**
	 * Reset settings option.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( ResendClient::SETTINGS_OPTION );
	}

	/**
	 * Option api_key is used when WPRN_RESEND_API_KEY constant is not set.
	 */
	public function test_option_api_key_resolves_when_constant_unset(): void {
		update_option(
			ResendClient::SETTINGS_OPTION,
			array(
				'from_email'     => 'news@example.com',
				'from_name'      => '',
				'api_key'        => 're_from_option_int',
				'webhook_secret' => '',
			)
		);

		if ( ResendClient::is_api_key_from_constant() ) {
			$this->markTestSkipped( 'WPRN_RESEND_API_KEY is defined in this environment.' );
		}

		$resolved = ResendClient::resolve_api_key_from(
			ResendClient::API_KEY_CONSTANT,
			(array) get_option( ResendClient::SETTINGS_OPTION, array() )
		);

		$this->assertSame( 're_from_option_int', $resolved );

		$client = ResendClient::from_wp();
		$this->assertInstanceOf( ResendClient::class, $client );
	}
}
