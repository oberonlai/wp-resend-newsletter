<?php
/**
 * Integration tests for Admin UI (area 05) — capability gates + smoke.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Admin;

use WP_UnitTestCase;
use WpResendNewsletter\Admin\AdminActions;
use WpResendNewsletter\Admin\CampaignsListTable;
use WpResendNewsletter\Admin\CampaignsPage;
use WpResendNewsletter\Admin\ErrorSanitizer;
use WpResendNewsletter\Admin\Menu;
use WpResendNewsletter\Admin\QueuePage;
use WpResendNewsletter\Admin\SubscribersListTable;
use WpResendNewsletter\Admin\SubscribersPage;
use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SendJobType;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;

/**
 * @covers \WpResendNewsletter\Admin\Menu
 * @covers \WpResendNewsletter\Admin\AdminActions
 * @covers \WpResendNewsletter\Admin\SubscribersPage
 * @covers \WpResendNewsletter\Admin\CampaignsPage
 * @covers \WpResendNewsletter\Admin\QueuePage
 * @covers \WpResendNewsletter\Admin\ErrorSanitizer
 * @covers \WpResendNewsletter\Admin\SubscribersListTable
 * @covers \WpResendNewsletter\Admin\CampaignsListTable
 */
class AdminUi_Test extends WP_UnitTestCase {

	/**
	 * Create tables.
	 */
	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		CampaignsTable::create_table();
		SendJobsTable::create_table();
	}

	/**
	 * Truncate tables.
	 */
	public function tear_down(): void {
		global $wpdb;
		foreach ( array( SubscribersTable::get_table_name(), CampaignsTable::get_table_name(), SendJobsTable::get_table_name() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		parent::tear_down();
	}

	/**
	 * @return int Admin user ID.
	 */
	private function as_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * @return int Author user ID.
	 */
	private function as_author(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Scenario: Plugin menu visible to admins only.
	 */
	public function test_menu_registered_for_manage_options(): void {
		global $menu, $submenu;

		$menu    = array();
		$submenu = array();

		$this->as_admin();
		set_current_screen( 'dashboard' );

		Menu::add_menu_pages();

		$slugs = array();
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) ) {
				$slugs[] = $item[2];
			}
		}
		$this->assertContains( Menu::PARENT_SLUG, $slugs );

		$this->assertArrayHasKey( Menu::PARENT_SLUG, $submenu );
		$child_slugs = array_map(
			static function ( $row ) {
				return $row[2] ?? '';
			},
			$submenu[ Menu::PARENT_SLUG ]
		);
		$this->assertContains( Menu::PARENT_SLUG, $child_slugs );
		$this->assertContains( Menu::SUBSCRIBERS_SLUG, $child_slugs );
		$this->assertContains( Menu::CAMPAIGNS_SLUG, $child_slugs );
		$this->assertContains( Menu::QUEUE_SLUG, $child_slugs );
	}

	/**
	 * Scenario: Hidden campaign edit page stays accessible after submenu removal.
	 *
	 * remove_submenu_page() drops the item from $submenu, so get_admin_page_parent()
	 * is empty and user_can_access_admin_page() checks the empty-parent hook.
	 */
	public function test_campaign_edit_page_registered_for_empty_parent_hook(): void {
		global $menu, $submenu, $plugin_page, $pagenow, $_registered_pages, $_parent_pages;
		global $_wp_menu_nopriv, $_wp_submenu_nopriv;

		$menu               = array();
		$submenu            = array();
		$_wp_menu_nopriv    = array();
		$_wp_submenu_nopriv = array();

		$this->as_admin();
		set_current_screen( 'dashboard' );

		Menu::add_menu_pages();

		// Still hidden from the visible submenu.
		$child_slugs = array_map(
			static function ( $row ) {
				return $row[2] ?? '';
			},
			$submenu[ Menu::PARENT_SLUG ] ?? array()
		);
		$this->assertNotContains( Menu::CAMPAIGN_EDIT_SLUG, $child_slugs );

		$orphan_hook = get_plugin_page_hookname( Menu::CAMPAIGN_EDIT_SLUG, '' );
		$this->assertArrayHasKey( $orphan_hook, $_registered_pages );
		$this->assertTrue( $_registered_pages[ $orphan_hook ] );
		$this->assertSame( Menu::PARENT_SLUG, $_parent_pages[ Menu::CAMPAIGN_EDIT_SLUG ] ?? null );
		$this->assertNotFalse( has_action( $orphan_hook ) );

		// Simulate admin.php?page=wprn-campaign-edit capability gate.
		$plugin_page = Menu::CAMPAIGN_EDIT_SLUG;
		$pagenow     = 'admin.php';
		$this->assertTrue( user_can_access_admin_page() );
	}

	/**
	 * Scenario: Non-admin cannot render subscribers (wp_die).
	 */
	public function test_subscribers_page_denies_author(): void {
		$this->as_author();
		$this->expectException( \WPDieException::class );
		SubscribersPage::render();
	}

	/**
	 * Scenario: Non-admin cannot render campaigns.
	 */
	public function test_campaigns_page_denies_author(): void {
		$this->as_author();
		$this->expectException( \WPDieException::class );
		CampaignsPage::render_list();
	}

	/**
	 * Scenario: Non-admin cannot render queue.
	 */
	public function test_queue_page_denies_author(): void {
		$this->as_author();
		$this->expectException( \WPDieException::class );
		QueuePage::render();
	}

	/**
	 * Scenario: Admin sees subscribers list with status filter data.
	 */
	public function test_subscribers_list_shows_email_status_for_admin(): void {
		$this->as_admin();
		$repo = new SubscriberRepository();
		$repo->insert(
			array(
				'email'  => 'one@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$repo->insert(
			array(
				'email'  => 'two@example.com',
				'status' => SubscriberStatus::PENDING,
			)
		);

		$_REQUEST = array();
		$_GET     = array();

		ob_start();
		SubscribersPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'one@example.com', $html );
		$this->assertStringContainsString( 'two@example.com', $html );
		$this->assertStringContainsString( 'Confirmed', $html );
		$this->assertStringContainsString( 'Pending', $html );
	}

	/**
	 * Scenario: Admin bulk unsubscribe with valid nonce updates status.
	 */
	public function test_bulk_unsubscribe_with_nonce_for_admin(): void {
		$this->as_admin();
		$repo = new SubscriberRepository();
		$id   = $repo->insert(
			array(
				'email'  => 'bulk@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$this->assertNotFalse( $id );

		$table    = new SubscribersListTable( $repo );
		$_REQUEST = array(
			'action'         => 'unsubscribe',
			'subscriber_ids' => array( (int) $id ),
			'_wpnonce'       => wp_create_nonce( 'bulk-subscribers' ),
		);

		$redirected = null;
		$capture    = static function ( $location ) use ( &$redirected ) {
			$redirected = $location;
			throw new \WPDieException( 'redirect' );
		};
		add_filter( 'wp_redirect', $capture, 10, 1 );

		try {
			$table->process_bulk_action();
			$this->fail( 'Expected redirect exit' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $capture, 10 );
		}

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'wprn_notice=', (string) $redirected );
		$this->assertStringNotContainsString( '%25', (string) $redirected ); // no double-encoding

		$row = $repo->find_by_id( (int) $id );
		$this->assertNotNull( $row );
		$this->assertSame( SubscriberStatus::UNSUBSCRIBED, (string) $row->status );
	}

	/**
	 * Scenario: Bulk unsubscribe without nonce is denied.
	 */
	public function test_bulk_unsubscribe_rejects_missing_nonce(): void {
		$this->as_admin();
		$repo = new SubscriberRepository();
		$id   = $repo->insert(
			array(
				'email'  => 'keep@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);

		$table    = new SubscribersListTable( $repo );
		$_REQUEST = array(
			'action'         => 'unsubscribe',
			'subscriber_ids' => array( (int) $id ),
		);

		try {
			$table->process_bulk_action();
			$this->fail( 'Expected wp_die on missing nonce' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$row = $repo->find_by_id( (int) $id );
		$this->assertSame( SubscriberStatus::CONFIRMED, (string) $row->status );
	}

	/**
	 * Scenario: Author cannot bulk unsubscribe even with nonce.
	 */
	public function test_bulk_unsubscribe_rejects_author(): void {
		$this->as_admin();
		$repo = new SubscriberRepository();
		$id   = $repo->insert(
			array(
				'email'  => 'author-bulk@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);

		$this->as_author();
		$table    = new SubscribersListTable( $repo );
		$_REQUEST = array(
			'action'         => 'unsubscribe',
			'subscriber_ids' => array( (int) $id ),
			'_wpnonce'       => wp_create_nonce( 'bulk-subscribers' ),
		);

		try {
			$table->process_bulk_action();
			$this->fail( 'Expected wp_die on insufficient capability' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$row = $repo->find_by_id( (int) $id );
		$this->assertSame( SubscriberStatus::CONFIRMED, (string) $row->status );
	}

	/**
	 * Scenario: Admin can create campaign via UI handler path (service).
	 */
	public function test_admin_can_create_and_mark_ready_campaign(): void {
		$this->as_admin();
		$svc = new CampaignService( new CampaignRepository() );

		$created = $svc->create(
			array(
				'subject'   => 'UI Campaign',
				'body_html' => '<p>Hi {{{RESEND_UNSUBSCRIBE_URL}}}</p>',
				'body_text' => 'Hi',
			)
		);
		$this->assertTrue( $created['ok'] );
		$id = (int) $created['id'];

		$ready = $svc->mark_ready( $id );
		$this->assertTrue( $ready['ok'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( CampaignStatus::READY, (string) $row->status );
	}

	/**
	 * Scenario: Queue send admin-post rejects missing nonce.
	 */
	public function test_queue_send_rejects_missing_nonce(): void {
		$this->as_admin();
		$_POST = array( 'campaign_id' => 1 );

		try {
			AdminActions::handle_queue_send();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	/**
	 * Scenario: Queue send rejects author.
	 */
	public function test_queue_send_rejects_author(): void {
		$this->as_author();
		$_POST = array(
			'campaign_id' => 1,
			'_wpnonce'    => wp_create_nonce( AdminActions::QUEUE_SEND_ACTION ),
		);

		try {
			AdminActions::handle_queue_send();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	/**
	 * Scenario: Queue page shows counts and sanitizes last_error (no API key).
	 */
	public function test_queue_page_shows_counts_and_redacts_api_key(): void {
		$this->as_admin();

		$campaign_id = ( new CampaignRepository() )->insert(
			array(
				'subject'    => 'Queued',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::SENDING,
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $campaign_id );

		$jobs = new SendJobRepository();
		$jobs->insert(
			array(
				'campaign_id' => (int) $campaign_id,
				'job_type'    => SendJobType::SEND_BROADCAST,
				'status'      => SendJobStatus::FAILED,
				'attempts'    => 1,
				'last_error'  => 'Auth failed with key re_test_secret_apikey_value_12345 and Bearer tok_abc',
			)
		);
		$jobs->insert(
			array(
				'campaign_id' => (int) $campaign_id,
				'job_type'    => SendJobType::SYNC_SEGMENT,
				'status'      => SendJobStatus::PENDING,
				'attempts'    => 0,
			)
		);

		$_GET = array( 'campaign_id' => (string) $campaign_id );

		ob_start();
		QueuePage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Pending', $html );
		$this->assertStringContainsString( 'Failed', $html );
		$this->assertStringNotContainsString( 're_test_secret_apikey_value_12345', $html );
		$this->assertStringContainsString( '[redacted]', $html );
		$this->assertStringNotContainsString( 'tok_abc', $html );
	}

	/**
	 * Unit-ish: ErrorSanitizer redacts secrets.
	 */
	public function test_error_sanitizer_redacts_keys(): void {
		$raw  = 'failed api_key=re_live_abcdefghijklmnop Bearer secret123 whsec_abc_def whsec_dGVzdCtrc2VjcmV0Ky8=';
		$safe = ErrorSanitizer::for_display( $raw );
		$this->assertStringNotContainsString( 're_live_abcdefghijklmnop', $safe );
		$this->assertStringNotContainsString( 'secret123', $safe );
		$this->assertStringNotContainsString( 'whsec_abc_def', $safe );
		$this->assertStringNotContainsString( 'whsec_dGVzdCtrc2VjcmV0Ky8=', $safe );
		$this->assertStringContainsString( '[redacted]', $safe );
	}

	/**
	 * Campaign edit form renders for admin.
	 */
	public function test_campaign_edit_form_renders_for_admin(): void {
		$this->as_admin();
		$_GET = array();

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wprn_campaign_subject', $html );
		$this->assertStringContainsString( AdminActions::SAVE_CAMPAIGN_ACTION, $html );
	}

	/**
	 * Save campaign admin-post rejects author.
	 */
	public function test_save_campaign_rejects_author(): void {
		$this->as_author();
		$_POST = array(
			'subject'   => 'Nope',
			'body_html' => '<p>x</p>',
			'body_text' => 'x',
			'_wpnonce'  => wp_create_nonce( AdminActions::SAVE_CAMPAIGN_ACTION ),
		);

		try {
			AdminActions::handle_save_campaign();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	/**
	 * Queue / notice output escapes attacker-controlled markup (XSS).
	 */
	public function test_queue_and_notice_escape_html(): void {
		$this->as_admin();

		$campaign_id = ( new CampaignRepository() )->insert(
			array(
				'subject'    => '<script>alert(1)</script>',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::SENDING,
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $campaign_id );

		( new SendJobRepository() )->insert(
			array(
				'campaign_id' => (int) $campaign_id,
				'job_type'    => SendJobType::SEND_BROADCAST,
				'status'      => SendJobStatus::FAILED,
				'attempts'    => 1,
				'last_error'  => '<img src=x onerror=alert(1)> re_live_abcdefghijklmnop',
			)
		);

		$_GET = array(
			'campaign_id' => (string) $campaign_id,
			'wprn_notice' => '<b>owned</b>',
			'wprn_type'   => 'error',
		);

		ob_start();
		QueuePage::render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $html );
		$this->assertStringContainsString( '&lt;img', $html );
		// sanitize_text_field strips tags from flash notices; ensure raw markup is gone.
		$this->assertStringNotContainsString( '<b>owned</b>', $html );
		$this->assertStringContainsString( 'owned', $html );
		$this->assertStringNotContainsString( 're_live_abcdefghijklmnop', $html );
	}

	/**
	 * Scenario: Campaigns list shows checkboxes and bulk Delete for admin.
	 */
	public function test_campaigns_list_shows_checkboxes_for_admin(): void {
		$this->as_admin();
		$id = ( new CampaignRepository() )->insert(
			array(
				'subject'    => 'Bulk List Campaign',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::DRAFT,
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id );

		$_REQUEST = array();
		$_GET     = array();

		ob_start();
		CampaignsPage::render_list();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="campaign_ids[]"', $html );
		$this->assertStringContainsString( 'value="' . (int) $id . '"', $html );
		$this->assertStringContainsString( 'Bulk List Campaign', $html );
		$this->assertStringContainsString( 'Delete', $html );
	}

	/**
	 * Scenario: Admin bulk delete with valid nonce removes campaigns and related send jobs.
	 */
	public function test_bulk_delete_campaigns_with_nonce_for_admin(): void {
		$this->as_admin();
		$repo = new CampaignRepository();
		$id1  = $repo->insert(
			array(
				'subject'    => 'Delete Me One',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::DRAFT,
				'created_by' => get_current_user_id(),
			)
		);
		$id2  = $repo->insert(
			array(
				'subject'    => 'Delete Me Two',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::READY,
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id1 );
		$this->assertNotFalse( $id2 );

		$jobs = new SendJobRepository();
		$jobs->insert(
			array(
				'campaign_id' => (int) $id1,
				'job_type'    => SendJobType::SEND_BROADCAST,
				'status'      => SendJobStatus::PENDING,
				'attempts'    => 0,
			)
		);

		$table    = new CampaignsListTable( $repo );
		$_REQUEST = array(
			'action'       => 'delete',
			'campaign_ids' => array( (int) $id1, (int) $id2 ),
			'_wpnonce'     => wp_create_nonce( 'bulk-campaigns' ),
		);

		$redirected = null;
		$capture    = static function ( $location ) use ( &$redirected ) {
			$redirected = $location;
			throw new \WPDieException( 'redirect' );
		};
		add_filter( 'wp_redirect', $capture, 10, 1 );

		try {
			$table->process_bulk_action();
			$this->fail( 'Expected redirect exit' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $capture, 10 );
		}

		$this->assertNotNull( $redirected );
		$this->assertStringContainsString( 'wprn_notice=', (string) $redirected );
		$this->assertStringContainsString( 'Deleted', rawurldecode( (string) $redirected ) );

		$this->assertNull( $repo->find_by_id( (int) $id1 ) );
		$this->assertNull( $repo->find_by_id( (int) $id2 ) );
		$this->assertSame( array(), $jobs->find_by_campaign( (int) $id1 ) );
	}

	/**
	 * Scenario: Bulk delete without nonce is denied.
	 */
	public function test_bulk_delete_campaigns_rejects_missing_nonce(): void {
		$this->as_admin();
		$repo = new CampaignRepository();
		$id   = $repo->insert(
			array(
				'subject'    => 'Keep Me',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::DRAFT,
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id );

		$table    = new CampaignsListTable( $repo );
		$_REQUEST = array(
			'action'       => 'delete',
			'campaign_ids' => array( (int) $id ),
		);

		try {
			$table->process_bulk_action();
			$this->fail( 'Expected wp_die on missing nonce' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$this->assertNotNull( $repo->find_by_id( (int) $id ) );
	}

	/**
	 * Scenario: Author cannot bulk delete campaigns even with nonce.
	 */
	public function test_bulk_delete_campaigns_rejects_author(): void {
		$this->as_admin();
		$repo = new CampaignRepository();
		$id   = $repo->insert(
			array(
				'subject'    => 'Author Cannot Delete',
				'body_html'  => '<p>x</p>',
				'body_text'  => 'x',
				'status'     => CampaignStatus::DRAFT,
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id );

		$this->as_author();
		$table    = new CampaignsListTable( $repo );
		$_REQUEST = array(
			'action'       => 'delete',
			'campaign_ids' => array( (int) $id ),
			'_wpnonce'     => wp_create_nonce( 'bulk-campaigns' ),
		);

		try {
			$table->process_bulk_action();
			$this->fail( 'Expected wp_die on insufficient capability' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$this->assertNotNull( $repo->find_by_id( (int) $id ) );
	}

}
