<?php
/**
 * Integration tests for classic campaign editor (v1.2).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Admin;

use WP_UnitTestCase;
use WpResendNewsletter\Admin\AdminActions;
use WpResendNewsletter\Admin\CampaignsPage;
use WpResendNewsletter\Admin\Menu;
use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Database\TagsTable;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\TagRepository;

/**
 * @covers \WpResendNewsletter\Admin\CampaignsPage
 */
class CampaignBlockEditor_Test extends WP_UnitTestCase {

	/**
	 * Create tables.
	 */
	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		CampaignsTable::create_table();
		TagsTable::create_table();
	}

	/**
	 * Truncate tables.
	 */
	public function tear_down(): void {
		global $wpdb;
		foreach (
			array(
				SubscribersTable::get_table_name(),
				CampaignsTable::get_table_name(),
				TagsTable::get_table_name(),
			) as $table
		) {
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
	 * Scenario: Classic editor fields render; no block mount.
	 */
	public function test_campaign_edit_renders_classic_editor_not_block_mount(): void {
		$this->as_admin();
		$_GET = array();

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="subject"', $html );
		$this->assertStringContainsString( 'wprn_campaign_body_html', $html );
		$this->assertMatchesRegularExpression(
			'/<textarea[^>]*name=["\']body_html["\']/i',
			$html,
			'wp_editor must output textarea name=body_html'
		);
		$this->assertStringNotContainsString( 'id="wprn-campaign-block-editor"', $html );
		$this->assertStringNotContainsString( 'Loading editor', $html );
		$this->assertStringContainsString( 'name="body_text"', $html );
		$this->assertStringContainsString( AdminActions::SAVE_CAMPAIGN_ACTION, $html );
	}

	/**
	 * Scenario: Block editor assets are not enqueued on campaign edit.
	 */
	public function test_campaign_block_editor_script_not_enqueued(): void {
		$this->as_admin();
		$_GET['page'] = Menu::CAMPAIGN_EDIT_SLUG;
		set_current_screen( 'admin_page_' . Menu::CAMPAIGN_EDIT_SLUG );

		do_action( 'admin_enqueue_scripts', 'admin_page_' . Menu::CAMPAIGN_EDIT_SLUG );

		$this->assertFalse(
			wp_script_is( 'wprn-campaign-block-editor', 'enqueued' ),
			'campaign-block-editor.js must not enqueue'
		);
		$this->assertFalse(
			wp_style_is( 'wprn-campaign-block-editor', 'enqueued' ),
			'campaign-block-editor.css must not enqueue'
		);

		$js_file = dirname( __DIR__, 3 ) . '/src/Admin/js/campaign-block-editor.js';
		$this->assertFileDoesNotExist(
			$js_file,
			'Unused campaign-block-editor.js should be removed'
		);
	}

	/**
	 * Scenario: Existing HTML is available in the classic editor.
	 */
	public function test_existing_body_html_is_available_to_editor(): void {
		$this->as_admin();
		$id = ( new CampaignRepository() )->insert(
			array(
				'subject'    => 'Hello',
				'body_html'  => '<p>Kit imported</p>',
				'body_text'  => 'Kit imported',
				'status'     => 'draft',
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id );

		$_GET = array( 'id' => (string) $id );

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Kit imported', $html );
		$this->assertMatchesRegularExpression(
			'/<textarea[^>]*name=["\']body_html["\']/i',
			$html
		);
		$this->assertStringNotContainsString( 'id="wprn-campaign-block-editor"', $html );
	}

	/**
	 * Scenario: Stored email shell is unwrapped for the classic editor.
	 */
	public function test_edit_exposes_unwrapped_inner_html_to_editor(): void {
		$this->as_admin();
		$wrapped = EmailHtmlRenderer::render( '<p>Inner kit</p>' );
		$id      = ( new CampaignRepository() )->insert(
			array(
				'subject'    => 'Wrapped',
				'body_html'  => $wrapped,
				'body_text'  => 'Inner kit',
				'status'     => 'draft',
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id );

		$_GET = array( 'id' => (string) $id );

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Inner kit', $html );
		// Editable seed must not re-seed the full shell wrapper into the textarea body.
		$this->assertDoesNotMatchRegularExpression(
			'/<textarea[^>]*name=["\']body_html["\'][^>]*>[\s\S]*wprn-email-shell[\s\S]*<\/textarea>/i',
			$html
		);
	}

	/**
	 * Scenario: Tag filter checkboxes render with AND filter name.
	 */
	public function test_campaign_edit_renders_tag_filter_checkboxes(): void {
		$this->as_admin();
		$tag_id = ( new TagRepository() )->insert(
			array(
				'name' => 'VIP',
				'slug' => 'vip',
			)
		);
		$this->assertNotFalse( $tag_id );

		$_GET = array();

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="filter_tag_ids[]"', $html );
		$this->assertStringContainsString( 'VIP', $html );
		$this->assertStringContainsString( 'id="wprn-audience-estimate"', $html );
		$this->assertStringContainsString( 'wprn-audience-matching', $html );
	}

	/**
	 * Scenario: Mark ready checkbox when draft is editable.
	 */
	public function test_campaign_edit_renders_mark_ready_checkbox_for_draft(): void {
		$this->as_admin();
		$_GET = array();

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<input[^>]*name=["\']mark_ready["\'][^>]*value=["\']1["\']/i',
			$html
		);
	}

	/**
	 * Scenario: Queue send form when status=ready.
	 */
	public function test_ready_campaign_shows_queue_send_form(): void {
		$this->as_admin();
		$id = ( new CampaignRepository() )->insert(
			array(
				'subject'    => 'Ready one',
				'body_html'  => '<p>Hi</p>',
				'body_text'  => 'Hi',
				'status'     => 'ready',
				'created_by' => get_current_user_id(),
			)
		);
		$this->assertNotFalse( $id );

		$_GET = array( 'id' => (string) $id );

		ob_start();
		CampaignsPage::render_edit();
		$html = ob_get_clean();

		$this->assertStringContainsString( AdminActions::QUEUE_SEND_ACTION, $html );
	}

	/**
	 * Scenario: Save still persists body_html via existing handler.
	 */
	public function test_save_campaign_persists_body_html_from_post(): void {
		$this->as_admin();

		$nonce = wp_create_nonce( AdminActions::SAVE_CAMPAIGN_ACTION );
		$_POST = array(
			'campaign_id' => '0',
			'subject'     => 'Classic editor save',
			'body_html'   => '<p>From notes</p>',
			'body_text'   => '',
			'_wpnonce'    => $nonce,
		);
		$_REQUEST = array_merge( $_REQUEST, $_POST );

		$redirected = null;
		$capture    = static function ( $location ) use ( &$redirected ) {
			$redirected = $location;
			throw new \WPDieException( 'redirect' );
		};
		add_filter( 'wp_redirect', $capture, 10, 1 );

		try {
			AdminActions::handle_save_campaign();
			$this->fail( 'Expected redirect exit' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $capture, 10 );
		}

		$this->assertNotNull( $redirected );

		$repo  = new CampaignRepository();
		$rows  = $repo->find_all( 50, 0 );
		$found = null;
		foreach ( $rows as $row ) {
			if ( 'Classic editor save' === (string) $row->subject ) {
				$found = $row;
				break;
			}
		}
		$this->assertNotNull( $found );
		$this->assertStringContainsString( 'From notes', (string) $found->body_html );
		$this->assertStringContainsString( 'wprn-email-shell', (string) $found->body_html );
		$this->assertNotSame( '', trim( (string) $found->body_text ) );
	}

	/**
	 * Scenario: Author cannot save campaign.
	 */
	public function test_save_campaign_rejects_author_capability(): void {
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
	 * Scenario: Missing nonce is rejected.
	 */
	public function test_save_campaign_rejects_missing_nonce(): void {
		$this->as_admin();
		$_POST = array(
			'subject'   => 'No nonce',
			'body_html' => '<p>x</p>',
			'body_text' => 'x',
		);

		try {
			AdminActions::handle_save_campaign();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$repo  = new CampaignRepository();
		$rows  = $repo->find_all( 50, 0 );
		$match = false;
		foreach ( $rows as $row ) {
			if ( 'No nonce' === (string) $row->subject ) {
				$match = true;
				break;
			}
		}
		$this->assertFalse( $match );
	}
}
