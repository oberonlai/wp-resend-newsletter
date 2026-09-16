<?php
/**
 * Integration tests: subscribe → confirm → unsubscribe.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Subscribers;

use WP_REST_Request;
use WP_UnitTestCase;
use WpResendNewsletter\Application\ConfirmService;
use WpResendNewsletter\Application\SubscribeService;
use WpResendNewsletter\Application\UnsubscribeService;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\TokenService;

/**
 * @covers \WpResendNewsletter\Application\SubscribeService
 * @covers \WpResendNewsletter\Application\ConfirmService
 * @covers \WpResendNewsletter\Application\UnsubscribeService
 * @covers \WpResendNewsletter\Persistence\SubscriberRepository
 * @covers \WpResendNewsletter\Database\SubscribersTable
 * @covers \WpResendNewsletter\Rest\SubscribersController
 */
class SubscribersFlow_Test extends WP_UnitTestCase {

	/**
	 * Captured Resend batch payloads.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $sent_batches = array();

	/**
	 * Create table and settings before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		$this->sent_batches = array();

		update_option(
			'wprn_settings',
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'Test News',
				'api_key'        => 're_test_fake',
				'webhook_secret' => '',
			),
			false
		);

	}

	/**
	 * Truncate subscribers between tests.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = SubscribersTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		parent::tear_down();
	}

	/**
	 * Build SubscribeService with injectable mock Resend sender.
	 */
	private function subscribe_service(): SubscribeService {
		$client = new ResendClient(
			're_test_fake',
			function ( array $messages ) {
				$this->sent_batches[] = $messages;
				return array( 'data' => array() );
			}
		);

		return new SubscribeService(
			new SubscriberRepository(),
			new TokenService(),
			$client
		);
	}

	/**
	 * Scenario: New subscribe creates pending row and sends confirm mail.
	 */
	public function test_subscribe_creates_pending_and_sends_confirm(): void {
		$svc    = $this->subscribe_service();
		$result = $svc->subscribe( 'alice@example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertArrayHasKey( 'confirm_token', $result );

		$row = ( new SubscriberRepository() )->find_by_email( 'alice@example.com' );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::PENDING, $row->status );
		$this->assertNotEmpty( $row->confirm_token_hash );
		$this->assertNotEmpty( $row->unsub_token_hash );

		// Hash stored, not raw token.
		$this->assertNotSame( $result['confirm_token'], $row->confirm_token_hash );

		$this->assertCount( 1, $this->sent_batches );
		$this->assertSame( array( 'alice@example.com' ), $this->sent_batches[0][0]['to'] );
	}

	/**
	 * Scenario: Invalid email is rejected.
	 */
	public function test_invalid_email_rejected(): void {
		$svc    = $this->subscribe_service();
		$result = $svc->subscribe( 'not-an-email' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_email', $result['code'] );
		$this->assertNull( ( new SubscriberRepository() )->find_by_email( 'not-an-email' ) );
		$this->assertCount( 0, $this->sent_batches );
	}

	/**
	 * Enumeration-safe: same message whether new or existing confirmed.
	 */
	public function test_subscribe_response_is_enumeration_safe(): void {
		$svc = $this->subscribe_service();
		$a   = $svc->subscribe( 'bob@example.com' );
		$this->assertTrue( $a['ok'] );

		$confirm = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$confirm->confirm( $a['confirm_token'] );

		$b = $svc->subscribe( 'bob@example.com' );
		$this->assertTrue( $b['ok'] );
		$this->assertSame( $a['message'], $b['message'] );
		$this->assertArrayNotHasKey( 'confirm_token', $b );
	}

	/**
	 * Scenario: Confirm link activates subscriber; token cannot be reused.
	 */
	public function test_confirm_happy_path_and_reuse_fails(): void {
		$svc    = $this->subscribe_service();
		$result = $svc->subscribe( 'carol@example.com' );
		$token  = $result['confirm_token'];

		$issued_unsub = null;
		$listener     = static function ( $id, $email, $raw_unsub ) use ( &$issued_unsub ) {
			$issued_unsub = $raw_unsub;
		};
		add_action( 'wprn_subscriber_confirmed', $listener, 10, 3 );

		$confirm = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$ok      = $confirm->confirm( $token );

		remove_action( 'wprn_subscriber_confirmed', $listener, 10 );

		$this->assertTrue( $ok['ok'] );
		$this->assertIsString( $issued_unsub );
		$this->assertNotEmpty( $issued_unsub );

		$row = ( new SubscriberRepository() )->find_by_email( 'carol@example.com' );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
		$this->assertNotEmpty( $row->confirmed_at );
		$this->assertNull( $row->confirm_token_hash );
		$this->assertNotEmpty( $row->unsub_token_hash );

		$reuse = $confirm->confirm( $token );
		$this->assertFalse( $reuse['ok'] );
		$this->assertSame( 'invalid_token', $reuse['code'] );

		$row2 = ( new SubscriberRepository() )->find_by_email( 'carol@example.com' );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row2->status );
	}

	/**
	 * Scenario: Forged confirm token fails.
	 */
	public function test_forged_confirm_token_fails(): void {
		$svc = $this->subscribe_service();
		$svc->subscribe( 'dave@example.com' );

		$confirm = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$bad     = $confirm->confirm( str_repeat( 'ab', 32 ) );

		$this->assertFalse( $bad['ok'] );
		$this->assertSame( 'invalid_token', $bad['code'] );

		$row = ( new SubscriberRepository() )->find_by_email( 'dave@example.com' );
		$this->assertSame( SubscriberStatus::PENDING, $row->status );
	}

	/**
	 * Scenario: Expired confirm token fails.
	 */
	public function test_expired_confirm_token_fails(): void {
		$svc    = $this->subscribe_service();
		$result = $svc->subscribe( 'eve@example.com' );
		$token  = $result['confirm_token'];

		$row = ( new SubscriberRepository() )->find_by_email( 'eve@example.com' );
		( new SubscriberRepository() )->update(
			(int) $row->id,
			array( 'confirm_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) )
		);

		$confirm = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$expired = $confirm->confirm( $token );

		$this->assertFalse( $expired['ok'] );
		$this->assertSame( 'expired_token', $expired['code'] );

		$row2 = ( new SubscriberRepository() )->find_by_email( 'eve@example.com' );
		$this->assertSame( SubscriberStatus::PENDING, $row2->status );
	}

	/**
	 * Scenario: Valid unsubscribe + idempotent re-open.
	 */
	public function test_unsubscribe_happy_and_idempotent(): void {
		$tokens = new TokenService();
		$repo   = new SubscriberRepository();
		$svc    = $this->subscribe_service();

		$result = $svc->subscribe( 'frank@example.com' );
		( new ConfirmService( $repo, $tokens ) )->confirm( $result['confirm_token'] );

		$row       = $repo->find_by_email( 'frank@example.com' );
		$unsub_svc = new UnsubscribeService( $repo, $tokens );
		$raw_unsub = $unsub_svc->issue_token( (int) $row->id );
		$this->assertIsString( $raw_unsub );

		$first = $unsub_svc->unsubscribe( $raw_unsub );
		$this->assertTrue( $first['ok'] );

		$row2 = $repo->find_by_email( 'frank@example.com' );
		$this->assertSame( SubscriberStatus::UNSUBSCRIBED, $row2->status );

		$second = $unsub_svc->unsubscribe( $raw_unsub );
		$this->assertTrue( $second['ok'], 'Already unsubscribed must be idempotent' );
	}

	/**
	 * Scenario: Forged unsubscribe token fails; status unchanged.
	 */
	public function test_forged_unsubscribe_token_fails(): void {
		$tokens = new TokenService();
		$repo   = new SubscriberRepository();
		$svc    = $this->subscribe_service();

		$result = $svc->subscribe( 'ivan@example.com' );
		( new ConfirmService( $repo, $tokens ) )->confirm( $result['confirm_token'] );

		$unsub = new UnsubscribeService( $repo, $tokens );
		$bad   = $unsub->unsubscribe( str_repeat( 'cd', 32 ) );

		$this->assertFalse( $bad['ok'] );
		$this->assertSame( 'invalid_token', $bad['code'] );

		$row = $repo->find_by_email( 'ivan@example.com' );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
	}

	/**
	 * Reject unknown columns on repository update (SQL allowlist).
	 */
	public function test_repository_rejects_unknown_columns(): void {
		$svc    = $this->subscribe_service();
		$result = $svc->subscribe( 'judy@example.com' );
		$this->assertTrue( $result['ok'] );

		$row = ( new SubscriberRepository() )->find_by_email( 'judy@example.com' );
		$ok  = ( new SubscriberRepository() )->update(
			(int) $row->id,
			array( 'evil_column' => 'x' )
		);
		$this->assertFalse( $ok );
	}

	/**
	 * REST subscribe rejects invalid email; confirm/unsub work via routes.
	 */
	public function test_rest_subscribe_confirm_unsubscribe(): void {
		$tokens = new TokenService();
		$repo   = new SubscriberRepository();

		// Invalid via REST (validate_callback / service).
		$bad = new WP_REST_Request( 'POST', '/wprn/v1/subscribers' );
		$bad->set_header( 'Content-Type', 'application/json' );
		$bad->set_body( wp_json_encode( array( 'email' => 'not-an-email' ) ) );
		$bad_res = rest_get_server()->dispatch( $bad );
		$this->assertSame( 400, $bad_res->get_status() );

		// Use service for subscribe so we can capture token (REST hides it).
		$svc    = $this->subscribe_service();
		$result = $svc->subscribe( 'grace@example.com' );
		$token  = $result['confirm_token'];

		$confirm_req = new WP_REST_Request( 'GET', '/wprn/v1/subscribers/confirm' );
		$confirm_req->set_param( 'token', $token );
		$confirm_res = rest_get_server()->dispatch( $confirm_req );
		$this->assertSame( 200, $confirm_res->get_status() );

		$row = $repo->find_by_email( 'grace@example.com' );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );

		$raw_unsub = ( new UnsubscribeService( $repo, $tokens ) )->issue_token( (int) $row->id );
		$this->assertIsString( $raw_unsub );

		$unsub_req = new WP_REST_Request( 'GET', '/wprn/v1/subscribers/unsubscribe' );
		$unsub_req->set_param( 'token', $raw_unsub );
		$unsub_res = rest_get_server()->dispatch( $unsub_req );
		$this->assertSame( 200, $unsub_res->get_status() );

		$row2 = $repo->find_by_email( 'grace@example.com' );
		$this->assertSame( SubscriberStatus::UNSUBSCRIBED, $row2->status );
	}

	/**
	 * Activator creates the subscribers table.
	 */
	public function test_activator_create_tables(): void {
		global $wpdb;
		$table = SubscribersTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( SubscribersTable::VERSION_OPTION );

		\WpResendNewsletter\Activator::create_tables();

		$this->assertTrue( SubscribersTable::table_exists() );
	}

	/**
	 * REST subscribe success body does not leak enumeration details.
	 */
	public function test_rest_subscribe_enumeration_safe_body(): void {
		// Ensure ResendClient::from_wp has a key so send path runs or skips cleanly.
		$req = new WP_REST_Request( 'POST', '/wprn/v1/subscribers' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'email' => 'heidi@example.com' ) ) );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayNotHasKey( 'confirm_token', $data );
		$this->assertArrayNotHasKey( 'status', $data );
	}

	/**
	 * REST subscribe is rate-limited after too many attempts from one IP.
	 */
	public function test_rest_subscribe_rate_limited(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		delete_transient( 'wprn_sub_rl_' . md5( '203.0.113.50' ) );

		$limit = \WpResendNewsletter\Rest\SubscribersController::SUBSCRIBE_RATE_LIMIT;
		for ( $i = 0; $i < $limit; $i++ ) {
			$req = new WP_REST_Request( 'POST', '/wprn/v1/subscribers' );
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( array( 'email' => "rate{$i}@example.com" ) ) );
			$res = rest_get_server()->dispatch( $req );
			$this->assertSame( 200, $res->get_status(), "attempt {$i} should succeed" );
		}

		$blocked = new WP_REST_Request( 'POST', '/wprn/v1/subscribers' );
		$blocked->set_header( 'Content-Type', 'application/json' );
		$blocked->set_body( wp_json_encode( array( 'email' => 'rate-blocked@example.com' ) ) );
		$blocked_res = rest_get_server()->dispatch( $blocked );
		$this->assertSame( 429, $blocked_res->get_status() );

		delete_transient( 'wprn_sub_rl_' . md5( '203.0.113.50' ) );
		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
