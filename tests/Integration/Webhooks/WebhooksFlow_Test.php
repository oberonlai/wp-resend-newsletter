<?php
/**
 * Integration tests: Resend webhook endpoint + processor.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Webhooks;

use WP_REST_Request;
use WP_UnitTestCase;
use WpResendNewsletter\Admin\SettingsPage;
use WpResendNewsletter\Application\WebhookProcessor;
use WpResendNewsletter\Database\DeliveryEventsTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\DeliveryEventRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Rest\WebhooksController;
use WpResendNewsletter\Tests\Support\WebhookSigning;

/**
 * @covers \WpResendNewsletter\Application\WebhookProcessor
 * @covers \WpResendNewsletter\Rest\WebhooksController
 * @covers \WpResendNewsletter\Persistence\DeliveryEventRepository
 * @covers \WpResendNewsletter\Database\DeliveryEventsTable
 * @covers \WpResendNewsletter\Security\WebhookSecret
 */
class WebhooksFlow_Test extends WP_UnitTestCase {

	/**
	 * Test signing secret (whsec_ + base64).
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Create tables and settings.
	 */
	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		DeliveryEventsTable::create_table();

		$this->secret = WebhookSigning::make_secret( 'wprn_test_webhook_key_01' );

		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'Test News',
				'api_key'        => 're_test_fake',
				'segment_id'     => '',
				'webhook_secret' => $this->secret,
			),
			false
		);

	}

	/**
	 * Truncate tables.
	 */
	public function tear_down(): void {
		global $wpdb;
		foreach ( array( SubscribersTable::get_table_name(), DeliveryEventsTable::get_table_name() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		parent::tear_down();
	}

	/**
	 * Load a fixture payload.
	 */
	private function fixture( string $name ): string {
		$path = WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'tests/Fixtures/webhooks/' . $name;
		$this->assertFileExists( $path );
		$body = file_get_contents( $path );
		$this->assertNotFalse( $body );
		return $body;
	}

	/**
	 * Insert a confirmed subscriber.
	 */
	private function seed_confirmed( string $email ): int {
		$repo = new SubscriberRepository();
		$id   = $repo->insert(
			array(
				'email'  => strtolower( $email ),
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$this->assertNotFalse( $id );
		return (int) $id;
	}

	/**
	 * Build signed REST request.
	 *
	 * @param string $body   Raw JSON body.
	 * @param string $msg_id Svix id.
	 * @param string $secret Secret to sign with (empty = unsigned).
	 */
	private function signed_request( string $body, string $msg_id, string $secret = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wprn/v1/webhooks/resend' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		if ( '' !== $secret ) {
			$ts  = time();
			$sig = WebhookSigning::sign( $secret, $msg_id, $ts, $body );
			$request->set_header( 'svix-id', $msg_id );
			$request->set_header( 'svix-timestamp', (string) $ts );
			$request->set_header( 'svix-signature', $sig );
		}

		return $request;
	}

	/**
	 * Scenario: Valid bounce webhook marks subscriber bounced + stores event.
	 */
	public function test_valid_bounce_marks_bounced_and_stores_event(): void {
		$email = 'bounce-target@example.com';
		$id    = $this->seed_confirmed( $email );
		$body  = $this->fixture( 'email.bounced.json' );
		$msg   = 'msg_bounce_001';

		$response = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );

		$this->assertSame( 200, $response->get_status() );
		$row = ( new SubscriberRepository() )->find_by_id( $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::BOUNCED, $row->status );

		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg );
		$this->assertNotNull( $event );
		$this->assertSame( 'email.bounced', $event->event_type );
		$this->assertSame( $id, (int) $event->subscriber_id );
		$this->assertSame( hash( 'sha256', $body ), $event->payload_hash );
	}

	/**
	 * Scenario: Valid complaint webhook marks complained.
	 */
	public function test_valid_complaint_marks_complained(): void {
		$email = 'complaint-target@example.com';
		$id    = $this->seed_confirmed( $email );
		$body  = $this->fixture( 'email.complained.json' );

		$response = rest_get_server()->dispatch(
			$this->signed_request( $body, 'msg_complaint_001', $this->secret )
		);

		$this->assertSame( 200, $response->get_status() );
		$row = ( new SubscriberRepository() )->find_by_id( $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::COMPLAINED, $row->status );
	}

	/**
	 * Scenario: Invalid signature → 401 and no DB change.
	 */
	public function test_invalid_signature_rejected_no_db_change(): void {
		$email = 'bounce-target@example.com';
		$id    = $this->seed_confirmed( $email );
		$body  = $this->fixture( 'email.bounced.json' );

		$request = new WP_REST_Request( 'POST', '/wprn/v1/webhooks/resend' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$request->set_header( 'svix-id', 'msg_bad_sig' );
		$request->set_header( 'svix-timestamp', (string) time() );
		$request->set_header( 'svix-signature', 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$row = ( new SubscriberRepository() )->find_by_id( $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
		$this->assertNull( ( new DeliveryEventRepository() )->find_by_provider_event_id( 'msg_bad_sig' ) );
	}

	/**
	 * Scenario: Unknown signed event → 200, no bad status change.
	 */
	public function test_unknown_signed_event_acknowledged_without_status_change(): void {
		$email = 'delivered-target@example.com';
		$id    = $this->seed_confirmed( $email );
		$body  = $this->fixture( 'email.delivered.json' );

		$response = rest_get_server()->dispatch(
			$this->signed_request( $body, 'msg_delivered_001', $this->secret )
		);

		$this->assertSame( 200, $response->get_status() );
		$row = ( new SubscriberRepository() )->find_by_id( $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );

		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( 'msg_delivered_001' );
		$this->assertNotNull( $event );
		$this->assertSame( 'email.delivered', $event->event_type );
	}

	/**
	 * Scenario: Missing webhook secret fails closed with 503.
	 */
	public function test_missing_secret_fails_closed(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'Test',
				'api_key'        => '',
				'segment_id'     => '',
				'webhook_secret' => '',
			),
			false
		);

		$body     = $this->fixture( 'email.bounced.json' );
		$response = rest_get_server()->dispatch(
			$this->signed_request( $body, 'msg_no_secret', $this->secret )
		);

		$this->assertSame( 503, $response->get_status() );
	}

	/**
	 * Idempotency: replaying the same svix-id does not duplicate events.
	 */
	public function test_idempotent_on_provider_event_id(): void {
		$this->seed_confirmed( 'bounce-target@example.com' );
		$body = $this->fixture( 'email.bounced.json' );
		$msg  = 'msg_idempotent_001';

		$r1 = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );
		$r2 = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );

		$this->assertSame( 200, $r1->get_status() );
		$this->assertSame( 200, $r2->get_status() );

		global $wpdb;
		$table = DeliveryEventsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE provider_event_id = %s',
				$table,
				$msg
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * Settings page shows webhook URL (not the secret).
	 */
	public function test_settings_shows_webhook_url_not_secret(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		SettingsPage::render_webhook_secret_field( array( 'description' => 'desc' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wprn-webhook-url', $html );
		$this->assertStringContainsString( 'wprn/v1/webhooks/resend', $html );
		$this->assertStringNotContainsString( $this->secret, $html );
		$this->assertStringContainsString( 'wprn-webhook-secret-masked', $html );
	}

	/**
	 * Processor never includes secret in result messages.
	 */
	public function test_processor_result_never_contains_secret(): void {
		$processor = new WebhookProcessor();
		$result    = $processor->process(
			'{}',
			array(
				'svix-id'        => 'x',
				'svix-timestamp' => (string) time(),
				'svix-signature' => 'v1,bad',
			)
		);

		$this->assertStringNotContainsString( $this->secret, $result['message'] );
		$this->assertStringNotContainsString( 'whsec_', $result['message'] );
	}

	/**
	 * Scenario: Missing Svix signature headers → 401, no DB change.
	 */
	public function test_missing_signature_headers_rejected(): void {
		$email = 'bounce-target@example.com';
		$id    = $this->seed_confirmed( $email );
		$body  = $this->fixture( 'email.bounced.json' );

		$request = new WP_REST_Request( 'POST', '/wprn/v1/webhooks/resend' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		// Intentionally omit svix-* headers.

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$row = ( new SubscriberRepository() )->find_by_id( $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
	}

	/**
	 * Scenario: Signed payload with expired timestamp → 401.
	 */
	public function test_expired_timestamp_rejected(): void {
		$email = 'bounce-target@example.com';
		$id    = $this->seed_confirmed( $email );
		$body  = $this->fixture( 'email.bounced.json' );
		$msg   = 'msg_expired_ts_001';
		$ts    = time() - 600; // Beyond Resend/Svix default 300s tolerance.

		$request = new WP_REST_Request( 'POST', '/wprn/v1/webhooks/resend' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$request->set_header( 'svix-id', $msg );
		$request->set_header( 'svix-timestamp', (string) $ts );
		$request->set_header( 'svix-signature', WebhookSigning::sign( $this->secret, $msg, $ts, $body ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$row = ( new SubscriberRepository() )->find_by_id( $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
		$this->assertNull( ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg ) );
	}

	/**
	 * Fail-closed: Throwable during verify (e.g. unusable secret material) must not 500-leak.
	 */
	public function test_unusable_secret_fails_closed_without_leaking(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'Test',
				'api_key'        => '',
				'segment_id'     => '',
				// Force a secret that survives resolve() but breaks crypto in verify.
				'webhook_secret' => 'whsec_',
			),
			false
		);

		$body     = $this->fixture( 'email.bounced.json' );
		$msg      = 'msg_bad_secret_material';
		$ts       = time();
		$request  = new WP_REST_Request( 'POST', '/wprn/v1/webhooks/resend' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$request->set_header( 'svix-id', $msg );
		$request->set_header( 'svix-timestamp', (string) $ts );
		$request->set_header( 'svix-signature', 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertContains( $response->get_status(), array( 401, 503 ) );
		$this->assertIsArray( $data );
		$encoded = wp_json_encode( $data );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'whsec_', $encoded );
		$this->assertStringNotContainsString( 'stack', strtolower( $encoded ) );
	}
}
