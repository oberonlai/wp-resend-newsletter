<?php
/**
 * Integration tests: engagement analytics (opens/clicks) + campaign_id resolution.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Analytics;

use WP_REST_Request;
use WP_UnitTestCase;
use WpResendNewsletter\Admin\CampaignAnalyticsPage;
use WpResendNewsletter\Admin\Menu;
use WpResendNewsletter\Admin\SettingsPage;
use WpResendNewsletter\Admin\SubscriberDetailPage;
use WpResendNewsletter\Application\CampaignAnalyticsService;
use WpResendNewsletter\Application\SubscriberEngagementService;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\DeliveryEventsTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\DeliveryEventRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Tests\Support\WebhookSigning;

/**
 * @covers \WpResendNewsletter\Application\WebhookProcessor
 * @covers \WpResendNewsletter\Application\CampaignAnalyticsService
 * @covers \WpResendNewsletter\Application\SubscriberEngagementService
 * @covers \WpResendNewsletter\Persistence\DeliveryEventRepository
 * @covers \WpResendNewsletter\Persistence\CampaignRepository
 * @covers \WpResendNewsletter\Database\DeliveryEventsTable
 * @covers \WpResendNewsletter\Admin\CampaignAnalyticsPage
 * @covers \WpResendNewsletter\Admin\SubscriberDetailPage
 */
class EngagementAnalytics_Test extends WP_UnitTestCase {

	private const BROADCAST_ID = 'br_analytics_campaign_001';

	/** @var string */
	private string $secret;

	/** @var int */
	private int $campaign_id;

	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		CampaignsTable::create_table();
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

		$cid = ( new CampaignRepository() )->insert(
			array(
				'subject'              => 'Weekly digest',
				'body_html'            => '<p>Hi {{{RESEND_UNSUBSCRIBE_URL}}}</p>',
				'body_text'            => 'Hi',
				'status'               => CampaignStatus::SENT,
				'resend_broadcast_id'  => self::BROADCAST_ID,
				'created_by'           => 1,
			)
		);
		$this->assertNotFalse( $cid );
		$this->campaign_id = (int) $cid;
	}

	public function tear_down(): void {
		global $wpdb;
		foreach (
			array(
				SubscribersTable::get_table_name(),
				CampaignsTable::get_table_name(),
				DeliveryEventsTable::get_table_name(),
			) as $table
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		parent::tear_down();
	}

	private function fixture( string $name ): string {
		$path = WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'tests/Fixtures/webhooks/' . $name;
		$this->assertFileExists( $path );
		$body = file_get_contents( $path );
		$this->assertNotFalse( $body );
		return $body;
	}

	private function seed_confirmed( string $email ): int {
		$id = ( new SubscriberRepository() )->insert(
			array(
				'email'  => strtolower( $email ),
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$this->assertNotFalse( $id );
		return (int) $id;
	}

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
	 * A1: Signed open + known broadcast_id + email → campaign_id + subscriber_id.
	 */
	public function test_a1_signed_open_resolves_campaign_and_subscriber(): void {
		$sid  = $this->seed_confirmed( 'alice@example.com' );
		$body = $this->fixture( 'email.opened.json' );
		$msg  = 'msg_open_a1';

		$response = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'processed', $data['code'] ?? '' );

		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg );
		$this->assertNotNull( $event );
		$this->assertSame( 'email.opened', $event->event_type );
		$this->assertSame( $this->campaign_id, (int) $event->campaign_id );
		$this->assertSame( $sid, (int) $event->subscriber_id );
		$this->assertSame( self::BROADCAST_ID, (string) $event->provider_broadcast_id );
		$this->assertSame( '56761188-7520-42d8-8898-ff6fc54ce630', (string) $event->provider_email_id );

		$row = ( new SubscriberRepository() )->find_by_id( $sid );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
	}

	/**
	 * A2: Signed click stores link_url; no IP/UA columns.
	 */
	public function test_a2_signed_click_stores_link_url_not_ip_ua(): void {
		$this->seed_confirmed( 'alice@example.com' );
		$body = $this->fixture( 'email.clicked.json' );
		$msg  = 'msg_click_a2';

		$response = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );
		$this->assertSame( 200, $response->get_status() );

		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg );
		$this->assertNotNull( $event );
		$this->assertSame( 'email.clicked', $event->event_type );
		$this->assertSame( 'https://example.com/post', (string) $event->link_url );
		$this->assertSame( $this->campaign_id, (int) $event->campaign_id );

		// No IP/UA columns on the table / object.
		$this->assertFalse( isset( $event->ip_address ) || isset( $event->ipAddress ) || property_exists( $event, 'ip_address' ) );
		$this->assertFalse( property_exists( $event, 'user_agent' ) || property_exists( $event, 'userAgent' ) );

		global $wpdb;
		$table = DeliveryEventsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );
		$this->assertIsArray( $cols );
		$this->assertNotContains( 'ip_address', $cols );
		$this->assertNotContains( 'user_agent', $cols );
		$this->assertNotContains( 'ipAddress', $cols );
		$this->assertNotContains( 'userAgent', $cols );
	}

	/**
	 * A3: Replay same svix-id → duplicate; single row.
	 */
	public function test_a3_idempotent_replay_open(): void {
		$this->seed_confirmed( 'alice@example.com' );
		$body = $this->fixture( 'email.opened.json' );
		$msg  = 'msg_open_idempotent';

		$r1 = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );
		$r2 = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );

		$this->assertSame( 200, $r1->get_status() );
		$this->assertSame( 200, $r2->get_status() );
		$data = $r2->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'duplicate', $data['code'] ?? '' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE provider_event_id = %s',
				DeliveryEventsTable::get_table_name(),
				$msg
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * A4: Unknown broadcast → campaign_id 0; still 200.
	 */
	public function test_a4_unknown_broadcast_campaign_id_zero(): void {
		$sid  = $this->seed_confirmed( 'alice@example.com' );
		$body = $this->fixture( 'email.opened-unknown-broadcast.json' );
		$msg  = 'msg_open_unknown_br';

		$response = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );
		$this->assertSame( 200, $response->get_status() );

		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg );
		$this->assertNotNull( $event );
		$this->assertSame( 0, (int) $event->campaign_id );
		$this->assertSame( $sid, (int) $event->subscriber_id );
		$this->assertSame( 'br_unknown_not_in_db', (string) $event->provider_broadcast_id );
	}

	/**
	 * A5: Bad signature → 401; no row.
	 */
	public function test_a5_bad_signature_no_row(): void {
		$this->seed_confirmed( 'alice@example.com' );
		$body = $this->fixture( 'email.opened.json' );

		$request = new WP_REST_Request( 'POST', '/wprn/v1/webhooks/resend' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$request->set_header( 'svix-id', 'msg_open_bad_sig' );
		$request->set_header( 'svix-timestamp', (string) time() );
		$request->set_header( 'svix-signature', 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertNull( ( new DeliveryEventRepository() )->find_by_provider_event_id( 'msg_open_bad_sig' ) );
	}

	/**
	 * A6: Summarize unique vs total.
	 */
	public function test_a6_summarize_unique_vs_total(): void {
		$repo = new DeliveryEventRepository();
		$s1   = $this->seed_confirmed( 'u1@example.com' );
		$s2   = $this->seed_confirmed( 'u2@example.com' );
		$s3   = $this->seed_confirmed( 'u3@example.com' );

		// 10 opens across 7 unique subscribers (reuse s1–s3 + 4 more).
		$subscribers = array( $s1, $s2, $s3 );
		for ( $i = 4; $i <= 7; $i++ ) {
			$subscribers[] = $this->seed_confirmed( "u{$i}@example.com" );
		}
		// 10 open rows: first 7 unique once, then 3 extras on s1/s2/s3.
		$open_subs = array_merge( $subscribers, array( $s1, $s2, $s3 ) );
		foreach ( $open_subs as $i => $sid ) {
			$repo->insert(
				array(
					'subscriber_id'     => $sid,
					'campaign_id'       => $this->campaign_id,
					'event_type'        => 'email.opened',
					'provider_event_id' => 'seed_open_' . $i,
					'payload_hash'      => hash( 'sha256', 'open' . $i ),
				)
			);
		}

		// 4 clicks / 3 unique.
		$click_subs = array( $s1, $s2, $s3, $s1 );
		foreach ( $click_subs as $i => $sid ) {
			$repo->insert(
				array(
					'subscriber_id'     => $sid,
					'campaign_id'       => $this->campaign_id,
					'event_type'        => 'email.clicked',
					'provider_event_id' => 'seed_click_' . $i,
					'payload_hash'      => hash( 'sha256', 'click' . $i ),
					'link_url'          => 0 === $i % 2 ? 'https://example.com/a' : 'https://example.com/b',
				)
			);
		}

		$summary = ( new CampaignAnalyticsService() )->summarize( $this->campaign_id );
		$this->assertSame( 10, $summary['opens_total'] );
		$this->assertSame( 7, $summary['opens_unique'] );
		$this->assertSame( 4, $summary['clicks_total'] );
		$this->assertSame( 3, $summary['clicks_unique'] );
		$this->assertNotEmpty( $summary['top_links'] );
	}

	/**
	 * A7: Bounce still sets bounced + resolves campaign_id.
	 */
	public function test_a7_bounce_resolves_campaign_id(): void {
		// Re-point bounce fixture broadcast to our campaign.
		$campaigns = new CampaignRepository();
		$campaigns->update(
			$this->campaign_id,
			array(
				'resend_broadcast_id' => '8b146471-e88e-4322-86af-016cd36fd216',
			)
		);

		$sid  = $this->seed_confirmed( 'bounce-target@example.com' );
		$body = $this->fixture( 'email.bounced.json' );
		$msg  = 'msg_bounce_a7';

		$response = rest_get_server()->dispatch( $this->signed_request( $body, $msg, $this->secret ) );
		$this->assertSame( 200, $response->get_status() );

		$row = ( new SubscriberRepository() )->find_by_id( $sid );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::BOUNCED, $row->status );

		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg );
		$this->assertNotNull( $event );
		$this->assertSame( $this->campaign_id, (int) $event->campaign_id );
		$this->assertSame( 'email.bounced', $event->event_type );
	}

	/**
	 * A8: Open does not change confirmed status.
	 */
	public function test_a8_open_does_not_change_status(): void {
		$sid  = $this->seed_confirmed( 'alice@example.com' );
		$body = $this->fixture( 'email.opened.json' );

		rest_get_server()->dispatch( $this->signed_request( $body, 'msg_open_a8', $this->secret ) );

		$row = ( new SubscriberRepository() )->find_by_id( $sid );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );
	}

	/**
	 * A9: Secret missing → 503 fail closed.
	 */
	public function test_a9_secret_missing_fails_closed(): void {
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

		$body     = $this->fixture( 'email.opened.json' );
		$response = rest_get_server()->dispatch(
			$this->signed_request( $body, 'msg_no_secret_open', $this->secret )
		);
		$this->assertSame( 503, $response->get_status() );
	}

	/**
	 * A10: Analytics page capability — non-admin denied.
	 */
	public function test_a10_analytics_page_denies_author(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		$_GET['id'] = (string) $this->campaign_id;
		$this->expectException( \WPDieException::class );
		CampaignAnalyticsPage::render();
	}

	/**
	 * Campaign analytics view shows unique + total for admin.
	 */
	public function test_analytics_page_renders_counts_for_admin(): void {
		$repo = new DeliveryEventRepository();
		$s1   = $this->seed_confirmed( 'a@example.com' );
		$s2   = $this->seed_confirmed( 'b@example.com' );
		foreach ( array( $s1, $s2, $s1 ) as $i => $sid ) {
			$repo->insert(
				array(
					'subscriber_id'     => $sid,
					'campaign_id'       => $this->campaign_id,
					'event_type'        => 'email.opened',
					'provider_event_id' => 'ui_open_' . $i,
					'payload_hash'      => hash( 'sha256', 'ui' . $i ),
				)
			);
		}
		$repo->insert(
			array(
				'subscriber_id'     => $s1,
				'campaign_id'       => $this->campaign_id,
				'event_type'        => 'email.clicked',
				'provider_event_id' => 'ui_click_0',
				'payload_hash'      => hash( 'sha256', 'uic' ),
				'link_url'          => 'https://example.com/x',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_GET['id'] = (string) $this->campaign_id;

		ob_start();
		CampaignAnalyticsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wprn-opens-total', $html );
		$this->assertStringContainsString( '>3</td>', $html ); // opens total
		$this->assertStringContainsString( 'wprn-opens-unique', $html );
		$this->assertStringContainsString( 'wprn-clicks-total', $html );
		$this->assertStringContainsString( 'https://example.com/x', $html );
		$this->assertStringNotContainsString( 're_test_fake', $html );
	}

	/**
	 * Empty analytics shows tracking guidance.
	 */
	public function test_analytics_empty_state_shows_tracking_note(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_GET['id'] = (string) $this->campaign_id;

		ob_start();
		CampaignAnalyticsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wprn-analytics-empty', $html );
		$this->assertStringContainsString( 'email.opened', $html );
		$this->assertStringContainsString( 'email.clicked', $html );
	}

	/**
	 * Subscriber recent events UI.
	 */
	public function test_subscriber_recent_events_ui(): void {
		$sid  = $this->seed_confirmed( 'alice@example.com' );
		$repo = new DeliveryEventRepository();
		$repo->insert(
			array(
				'subscriber_id'     => $sid,
				'campaign_id'       => $this->campaign_id,
				'event_type'        => 'email.opened',
				'provider_event_id' => 'sub_open_1',
				'payload_hash'      => hash( 'sha256', 'so1' ),
			)
		);
		$repo->insert(
			array(
				'subscriber_id'     => $sid,
				'campaign_id'       => $this->campaign_id,
				'event_type'        => 'email.clicked',
				'provider_event_id' => 'sub_click_1',
				'payload_hash'      => hash( 'sha256', 'sc1' ),
				'link_url'          => 'https://example.com/post',
			)
		);

		$recent = ( new SubscriberEngagementService() )->recent( $sid, 20 );
		$this->assertCount( 2, $recent );
		$this->assertSame( 'Weekly digest', $recent[0]['campaign_subject'] );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_GET['id'] = (string) $sid;

		ob_start();
		SubscriberDetailPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wprn-subscriber-events', $html );
		$this->assertStringContainsString( 'email.opened', $html );
		$this->assertStringContainsString( 'email.clicked', $html );
		$this->assertStringContainsString( 'https://example.com/post', $html );
		$this->assertStringContainsString( 'alice@example.com', $html );
	}

	/**
	 * Settings lists all four webhook event types + tracking note.
	 */
	public function test_settings_lists_opened_and_clicked_events(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		SettingsPage::render_webhook_secret_field( array( 'description' => 'desc' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'email.opened', $html );
		$this->assertStringContainsString( 'email.clicked', $html );
		$this->assertStringContainsString( 'email.bounced', $html );
		$this->assertStringContainsString( 'email.complained', $html );
		$this->assertStringContainsString( 'wprn-tracking-note', $html );
	}

	/**
	 * Schema upgrade adds new delivery_events columns.
	 */
	public function test_delivery_events_schema_has_v11_columns(): void {
		$this->assertSame( '1.1.0', DeliveryEventsTable::VERSION );
		global $wpdb;
		$table = DeliveryEventsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );
		$this->assertContains( 'provider_broadcast_id', $cols );
		$this->assertContains( 'provider_email_id', $cols );
		$this->assertContains( 'link_url', $cols );
	}

	/**
	 * CampaignRepository::find_by_resend_broadcast_id.
	 */
	public function test_find_by_resend_broadcast_id(): void {
		$found = ( new CampaignRepository() )->find_by_resend_broadcast_id( self::BROADCAST_ID );
		$this->assertNotNull( $found );
		$this->assertSame( $this->campaign_id, (int) $found->id );
		$this->assertNull( ( new CampaignRepository() )->find_by_resend_broadcast_id( 'missing' ) );
		$this->assertNull( ( new CampaignRepository() )->find_by_resend_broadcast_id( '' ) );
	}

	/**
	 * Menu registers analytics slug as orphan hidden page.
	 */
	public function test_menu_registers_analytics_orphan_page(): void {
		global $menu, $submenu, $_registered_pages, $_parent_pages;
		$menu    = array();
		$submenu = array();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		Menu::add_menu_pages();

		$orphan = get_plugin_page_hookname( Menu::CAMPAIGN_ANALYTICS_SLUG, '' );
		$this->assertTrue( ! empty( $_registered_pages[ $orphan ] ) );
		$this->assertSame( Menu::PARENT_SLUG, $_parent_pages[ Menu::CAMPAIGN_ANALYTICS_SLUG ] ?? null );
	}

	/**
	 * A10b: Subscriber detail capability — non-admin denied.
	 */
	public function test_a10b_subscriber_detail_denies_author(): void {
		$sid = $this->seed_confirmed( 'cap-check@example.com' );
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		$_GET['id'] = (string) $sid;
		$this->expectException( \WPDieException::class );
		SubscriberDetailPage::render();
	}

	/**
	 * javascript: click links must not be persisted (esc_url_raw).
	 */
	public function test_click_rejects_javascript_link_url(): void {
		$this->seed_confirmed( 'alice@example.com' );
		$body = wp_json_encode(
			array(
				'type'       => 'email.clicked',
				'created_at' => '2024-01-15T12:25:00.000Z',
				'data'       => array(
					'broadcast_id' => self::BROADCAST_ID,
					'email_id'     => 'em_js_link',
					'to'           => array( 'alice@example.com' ),
					'click'        => array(
						'link'      => 'javascript:alert(1)',
						'ipAddress' => '203.0.113.10',
						'userAgent' => 'Evil',
					),
				),
			)
		);
		$this->assertNotFalse( $body );
		$msg = 'msg_click_js_link';
		$response = rest_get_server()->dispatch( $this->signed_request( (string) $body, $msg, $this->secret ) );
		$this->assertSame( 200, $response->get_status() );
		$event = ( new DeliveryEventRepository() )->find_by_provider_event_id( $msg );
		$this->assertNotNull( $event );
		$link = $event->link_url ?? null;
		$this->assertTrue( null === $link || '' === (string) $link );
	}

	public function test_summarize_merges_kit_historical_stats(): void {
		update_option(
			CampaignAnalyticsService::KIT_STATS_OPTION,
			array(
				(string) $this->campaign_id => array(
					'emails_opened' => 42,
					'total_clicks'  => 7,
					'links'         => array(
						array(
							'link_url' => 'https://example.com/a',
							'clicks'   => 5,
						),
						array(
							'link_url' => 'https://example.com/b',
							'clicks'   => 2,
						),
					),
				),
			),
			false
		);

		$stats = ( new CampaignAnalyticsService() )->summarize( $this->campaign_id );
		$this->assertSame( 42, $stats['opens_total'] );
		$this->assertSame( 42, $stats['opens_unique'] );
		$this->assertSame( 7, $stats['clicks_total'] );
		$this->assertSame( 'kit', $stats['source'] );
		$this->assertSame( 'https://example.com/a', $stats['top_links'][0]['link_url'] );
		$this->assertSame( 5, $stats['top_links'][0]['clicks'] );
	}

	public function test_merge_top_links_prefers_higher_click_count(): void {
		$merged = CampaignAnalyticsService::merge_top_links(
			array(
				array(
					'link_url' => 'https://example.com/a',
					'clicks'   => 1,
				),
			),
			array(
				array(
					'link_url' => 'https://example.com/a',
					'clicks'   => 9,
				),
			)
		);
		$this->assertSame( 9, $merged[0]['clicks'] );
	}


}
