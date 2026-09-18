<?php
/**
 * Unit tests for ResendClient.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Infrastructure\ResendResult;

/**
 * @covers \WpResendNewsletter\Infrastructure\ResendClient
 */
class ResendClient_Test extends TestCase {

	/**
	 * Scenario: Batch size is capped at 50.
	 *
	 * Given a ResendClient instance
	 * When code requests sending to 51 recipients in one call
	 * Then the client refuses so no single Resend request exceeds 50
	 * And no HTTP send callback is invoked.
	 */
	public function test_batch_size_is_capped_at_50(): void {
		$http_called = false;
		$client      = new ResendClient(
			're_test_key',
			static function () use ( &$http_called ) {
				$http_called = true;
				return array( 'data' => array() );
			}
		);

		$messages = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$messages[] = array(
				'from'    => 'news@example.com',
				'to'      => array( "user{$i}@example.com" ),
				'subject' => 'Hello',
				'html'    => '<p>Hi</p>',
			);
		}

		$result = $client->send_batch( $messages );

		$this->assertInstanceOf( ResendResult::class, $result );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'batch_too_large', $result->error_code() );
		$this->assertFalse( $http_called, 'HTTP must not be called when batch exceeds max' );
		$this->assertSame( 50, WPRN_RESEND_BATCH_MAX );
	}

	/**
	 * Scenario: Missing API key fails closed.
	 *
	 * Given no API key
	 * When ResendClient attempts a send
	 * Then it returns a typed error and no HTTP call is made.
	 */
	public function test_missing_api_key_fails_closed(): void {
		$http_called = false;
		$client      = new ResendClient(
			'',
			static function () use ( &$http_called ) {
				$http_called = true;
				return array( 'data' => array() );
			}
		);

		$result = $client->send_batch(
			array(
				array(
					'from'    => 'news@example.com',
					'to'      => array( 'user@example.com' ),
					'subject' => 'Hello',
					'html'    => '<p>Hi</p>',
				),
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'missing_api_key', $result->error_code() );
		$this->assertFalse( $http_called, 'HTTP must not be called when API key is missing' );
	}

	/**
	 * Scenario: Constant overrides option for API key.
	 *
	 * Given a constant value and an option key
	 * When resolve_api_key_from runs
	 * Then the constant value wins.
	 */
	public function test_constant_overrides_option_for_api_key(): void {
		if ( ! defined( 'WPRN_TEST_RESEND_API_KEY' ) ) {
			define( 'WPRN_TEST_RESEND_API_KEY', 're_from_constant' );
		}

		$resolved = ResendClient::resolve_api_key_from(
			'WPRN_TEST_RESEND_API_KEY',
			array( 'api_key' => 're_from_option' )
		);

		$this->assertSame( 're_from_constant', $resolved );
	}

	/**
	 * Option is used when constant is absent or empty.
	 */
	public function test_option_used_when_constant_absent(): void {
		$resolved = ResendClient::resolve_api_key_from(
			'WPRN_TEST_RESEND_API_KEY_ABSENT_XYZ',
			array( 'api_key' => 're_from_option' )
		);

		$this->assertSame( 're_from_option', $resolved );
	}

	/**
	 * Valid batch at the max boundary invokes the sender once.
	 */
	public function test_batch_at_max_is_accepted(): void {
		$http_called = false;
		$client      = new ResendClient(
			're_test_key',
			static function ( array $messages ) use ( &$http_called ) {
				$http_called = true;
				return array( 'count' => count( $messages ) );
			}
		);

		$messages = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$messages[] = array(
				'from'    => 'news@example.com',
				'to'      => array( "user{$i}@example.com" ),
				'subject' => 'Hello',
				'html'    => '<p>Hi</p>',
			);
		}

		$result = $client->send_batch( $messages );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $http_called );
	}

	/**
	 * Scenario: send_broadcast fails closed when API key missing.
	 */
	public function test_send_broadcast_missing_api_key_fails_closed(): void {
		$client = new ResendClient( '' );

		$result = $client->send_broadcast(
			array(
				'segment_id' => 'seg_test',
				'from'       => 'news@news.oberonlai.blog',
				'subject'    => 'Hello',
				'html'       => '<p>Hi {{{RESEND_UNSUBSCRIBE_URL}}}</p>',
				'send'       => true,
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'missing_api_key', $result->error_code() );
	}

	/**
	 * Scenario: send_broadcast calls Broadcast injectable — never transactional batch.
	 *
	 * Given a valid API key and segment_id
	 * When send_broadcast is called
	 * Then Broadcast callback receives send=>true and batch sender is never invoked.
	 */
	public function test_send_broadcast_uses_broadcast_callback_not_batch(): void {
		$batch_called     = false;
		$broadcast_called = false;
		$captured         = null;
		$client           = new ResendClient(
			're_test_key',
			static function () use ( &$batch_called ) {
				$batch_called = true;
				return array();
			},
			static function ( array $params ) use ( &$broadcast_called, &$captured ) {
				$broadcast_called = true;
				$captured         = $params;
				return array( 'id' => 'bcast_123' );
			}
		);

		$result = $client->send_broadcast(
			array(
				'segment_id' => 'seg_test',
				'from'       => 'news@news.oberonlai.blog',
				'subject'    => 'Hello',
				'html'       => '<p>Hi</p>',
				'text'       => 'Hi',
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $broadcast_called );
		$this->assertFalse( $batch_called, 'Campaign Broadcast must not use transactional batch sender' );
		$this->assertIsArray( $captured );
		$this->assertTrue( $captured['send'] );
		$this->assertSame( 'seg_test', $captured['segment_id'] );
		$this->assertStringContainsString( '{{{RESEND_UNSUBSCRIBE_URL}}}', $captured['html'] );
		$this->assertSame( 'bcast_123', $result->data()['id'] );
	}

	/**
	 * send_broadcast requires from and subject.
	 */
	public function test_send_broadcast_requires_from_and_subject(): void {
		$client = new ResendClient(
			're_test_key',
			null,
			static function () {
				return array( 'id' => 'x' );
			}
		);

		$missing_from = $client->send_broadcast(
			array(
				'segment_id' => 'seg_test',
				'subject'    => 'Hello',
				'html'       => '<p>Hi</p>',
			)
		);
		$this->assertFalse( $missing_from->is_success() );
		$this->assertSame( 'invalid_broadcast_params', $missing_from->error_code() );

		$missing_subject = $client->send_broadcast(
			array(
				'segment_id' => 'seg_test',
				'from'       => 'news@news.oberonlai.blog',
				'html'       => '<p>Hi</p>',
			)
		);
		$this->assertFalse( $missing_subject->is_success() );
		$this->assertSame( 'invalid_broadcast_params', $missing_subject->error_code() );
	}

	/**
	 * create_segment + upsert_contact use injectables.
	 */
	public function test_segment_and_contact_helpers(): void {
		$client = new ResendClient(
			're_test_key',
			null,
			null,
			static function ( array $params ) {
				return array( 'id' => 'seg_new', 'name' => $params['name'] );
			},
			static function ( string $email, string $segment_id ) {
				return array( 'id' => 'contact_1', 'email' => $email, 'segment_id' => $segment_id );
			}
		);

		$seg = $client->create_segment( 'WP Resend Newsletter' );
		$this->assertTrue( $seg->is_success() );
		$this->assertSame( 'seg_new', $seg->data()['id'] );

		$contact = $client->upsert_contact( 'a@example.com', 'seg_new' );
		$this->assertTrue( $contact->is_success() );
		$this->assertSame( 'contact_1', $contact->data()['id'] );
	}

	/**
	 * send_broadcast requires segment_id.
	 */
	public function test_send_broadcast_requires_segment_id(): void {
		$client = new ResendClient( 're_test_key' );

		$result = $client->send_broadcast(
			array(
				'from'    => 'news@news.oberonlai.blog',
				'subject' => 'Hello',
				'html'    => '<p>Hi</p>',
				'send'    => true,
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'invalid_broadcast_params', $result->error_code() );
	}


	/**
	 * list_segments + delete_segment use injectables.
	 */
	public function test_list_and_delete_segment_helpers(): void {
		$listed  = false;
		$removed = array();
		$client  = new ResendClient(
			're_test_key',
			null,
			null,
			null,
			null,
			static function () use ( &$listed ) {
				$listed = true;
				return array(
					'data' => array(
						array( 'id' => 'seg_1', 'name' => 'General' ),
						array( 'id' => 'seg_2', 'name' => 'WPRN campaign #9' ),
					),
				);
			},
			static function ( string $id ) use ( &$removed ) {
				$removed[] = $id;
				return array( 'id' => $id, 'deleted' => true );
			}
		);

		$list = $client->list_segments();
		$this->assertTrue( $list->is_success() );
		$this->assertTrue( $listed );
		$this->assertCount( 2, $list->data()['data'] );

		$del = $client->delete_segment( 'seg_2' );
		$this->assertTrue( $del->is_success() );
		$this->assertSame( array( 'seg_2' ), $removed );
	}

	/**
	 * delete_segment requires a non-empty id.
	 */
	public function test_delete_segment_requires_id(): void {
		$client = new ResendClient(
			're_test_key',
			null,
			null,
			null,
			null,
			null,
			static function () {
				return array( 'id' => 'x' );
			}
		);

		$result = $client->delete_segment( '   ' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'invalid_segment_params', $result->error_code() );
	}

	/**
	 * list/delete fail closed without API key.
	 */
	public function test_list_and_delete_missing_api_key_fails_closed(): void {
		$client = new ResendClient( '' );
		$list   = $client->list_segments();
		$this->assertFalse( $list->is_success() );
		$this->assertSame( 'missing_api_key', $list->error_code() );

		$del = $client->delete_segment( 'seg_1' );
		$this->assertFalse( $del->is_success() );
		$this->assertSame( 'missing_api_key', $del->error_code() );
	}

}
