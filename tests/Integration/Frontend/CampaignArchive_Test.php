<?php
/**
 * Integration tests: public campaign archive shortcode + single view.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Frontend;

use WP_UnitTestCase;
use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Blocks\Subscribe\SubscribeBlock;
use WpResendNewsletter\Frontend\CampaignArchiveShortcode;
use WpResendNewsletter\Frontend\CampaignViewPage;
use WpResendNewsletter\Persistence\CampaignRepository;

/**
 * @covers \WpResendNewsletter\Frontend\CampaignArchiveShortcode
 * @covers \WpResendNewsletter\Frontend\CampaignViewPage
 * @covers \WpResendNewsletter\Persistence\CampaignRepository::find_sent
 * @covers \WpResendNewsletter\Persistence\CampaignRepository::find_sent_excluding
 * @covers \WpResendNewsletter\Persistence\CampaignRepository::count_sent
 */
class CampaignArchive_Test extends WP_UnitTestCase {

	/**
	 * Set up table + shortcode.
	 */
	public function set_up(): void {
		parent::set_up();
		CampaignsTable::create_table();
		CampaignArchiveShortcode::register();
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( SubscribeBlock::BLOCK_NAME ) ) {
			SubscribeBlock::register();
		}
		unset( $_GET[ CampaignArchiveShortcode::PAGE_KEY ] );
		unset( $_GET[ CampaignViewPage::QUERY_KEY ] );
	}

	/**
	 * Truncate campaigns.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = CampaignsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		unset( $_GET[ CampaignArchiveShortcode::PAGE_KEY ] );
		unset( $_GET[ CampaignViewPage::QUERY_KEY ] );
		parent::tear_down();
	}

	/**
	 * Insert a campaign row.
	 *
	 * @param string $subject Subject.
	 * @param string $status  Status.
	 * @param string $body    Body HTML.
	 * @param string $created Created_at (UTC mysql).
	 * @return int
	 */
	private function insert_campaign( string $subject, string $status, string $body = '<p>Body</p>', string $created = '' ): int {
		$repo = new CampaignRepository();
		$data = array(
			'subject'   => $subject,
			'body_html' => $body,
			'body_text' => 'Body',
			'status'    => $status,
			'created_by' => 1,
		);
		if ( '' !== $created ) {
			$data['created_at'] = $created;
			$data['updated_at'] = $created;
		}
		$id = $repo->insert( $data );
		$this->assertNotFalse( $id );
		return (int) $id;
	}

	/**
	 * Shortcode lists only sent campaigns with subject, date, and view link.
	 */
	public function test_shortcode_lists_sent_only_with_links(): void {
		$sent_id = $this->insert_campaign( 'Sent Weekly', CampaignStatus::SENT, '<p>Hello</p>', '2026-09-10 01:00:00' );
		$this->insert_campaign( 'Draft Secret', CampaignStatus::DRAFT, '<p>Nope</p>', '2026-09-11 01:00:00' );
		$this->insert_campaign( 'Ready Soon', CampaignStatus::READY, '<p>Nope</p>', '2026-09-12 01:00:00' );

		$html = do_shortcode( '[wprn_archive]' );

		$this->assertStringContainsString( 'wprn-archive', $html );
		$this->assertStringContainsString( 'Sent Weekly', $html );
		$this->assertStringNotContainsString( 'Draft Secret', $html );
		$this->assertStringNotContainsString( 'Ready Soon', $html );
		$this->assertStringContainsString( '/newsletter/' . $sent_id . '/', $html );
		$this->assertStringNotContainsString( CampaignViewPage::QUERY_KEY . '=', $html );
		$this->assertStringContainsString( 'wprn-archive__date', $html );
		$this->assertStringContainsString( 'wprn-archive__items', $html );
		$this->assertStringContainsString( '<article class="wprn-archive__item">', $html );
		$this->assertStringNotContainsString( '<ul class="wprn-archive__list">', $html );
		$this->assertStringNotContainsString( '<li class="wprn-archive__item">', $html );
	}

	/**
	 * Default limit is 10; page 2 via wprn_apage.
	 */
	public function test_shortcode_paginates_default_ten(): void {
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->insert_campaign(
				sprintf( 'Issue %02d', $i ),
				CampaignStatus::SENT,
				'<p>x</p>',
				sprintf( '2026-01-%02d 10:00:00', $i )
			);
		}

		$html = do_shortcode( '[wprn_archive]' );
		$this->assertSame( 10, substr_count( $html, 'class="wprn-archive__item"' ) );
		$this->assertStringContainsString( 'Issue 12', $html );
		$this->assertStringContainsString( CampaignArchiveShortcode::PAGE_KEY . '=2', $html );
		$this->assertStringContainsString( 'wprn-archive__pagination', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertMatchesRegularExpression( '/wprn-archive__page[^>]*>\s*1\s*</', $html );
		$this->assertMatchesRegularExpression( '/wprn-archive__page[^>]*href=[^>]+>\s*2\s*</', $html );
		$this->assertStringNotContainsString( 'Page 1 of', $html );

		$_GET[ CampaignArchiveShortcode::PAGE_KEY ] = '2';
		$html2 = do_shortcode( '[wprn_archive]' );
		$this->assertSame( 2, substr_count( $html2, 'class="wprn-archive__item"' ) );
		$this->assertStringContainsString( 'Issue 02', $html2 );
		$this->assertStringContainsString( 'Issue 01', $html2 );
		$this->assertStringContainsString( 'aria-current="page"', $html2 );
		$this->assertMatchesRegularExpression( '/wprn-archive__page--current[^>]*>\s*2\s*</', $html2 );
	}

	/**
	 * Custom limit attribute.
	 */
	public function test_shortcode_respects_limit_attribute(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->insert_campaign( "C$i", CampaignStatus::SENT );
		}

		$html = do_shortcode( '[wprn_archive limit="3"]' );
		$this->assertSame( 3, substr_count( $html, 'class="wprn-archive__item"' ) );
	}

	/**
	 * Sent campaign resolve + content HTML includes subject, date, unwrapped body.
	 *
	 * Content builder is theme-agnostic (no full document); live render uses get_header/footer.
	 */
	public function test_sent_campaign_view_html(): void {
		$inner = '<p>Archive body content</p>';
		$wrapped = EmailHtmlRenderer::wrap_shell( $inner );
		$id = $this->insert_campaign( 'Readable Issue', CampaignStatus::SENT, $wrapped, '2026-09-01 08:00:00' );

		$result = CampaignViewPage::resolve( $id );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertNotNull( $result['campaign'] );

		$html = CampaignViewPage::build_content_html( $result['campaign'] );
		$this->assertStringNotContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringNotContainsString( '<html', $html );
		$this->assertStringContainsString( 'class="wprn-campaign-view"', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__layout', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__primary', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__sidebar', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__subscribe', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__recent', $html );
		$this->assertStringContainsString( '<h1>Readable Issue</h1>', $html );
		$this->assertStringContainsString( 'Archive body content', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__body', $html );
		$this->assertStringContainsString( 'wprn-campaign-view__back', $html );
		$this->assertStringContainsString( '/wordpress-newsletter/', $html );
		$this->assertStringContainsString( 'data-wprn-subscribe', $html );
		$this->assertStringNotContainsString( 'handwritten-letter', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--split', $html );
		// Brand shell chrome should be unwrapped away from body region content markers if present as shell class.
		$this->assertStringNotContainsString( 'wprn-email-shell', $html );
		$this->assertStringContainsString( 'Past newsletters', $html );
		$this->assertStringContainsString( 'View all newsletters', $html );
		$this->assertStringContainsString( 'Subscribe to our newsletter', $html );
	}

	/**
	 * Sidebar recent list excludes current campaign and links other sent issues.
	 */
	public function test_campaign_view_recent_excludes_current(): void {
		$older = $this->insert_campaign( 'Older Sent', CampaignStatus::SENT, '<p>A</p>', '2026-08-01 10:00:00' );
		$current = $this->insert_campaign( 'Current Sent', CampaignStatus::SENT, '<p>B</p>', '2026-09-01 10:00:00' );
		$this->insert_campaign( 'Draft Skip', CampaignStatus::DRAFT, '<p>C</p>', '2026-09-02 10:00:00' );

		$result = CampaignViewPage::resolve( $current );
		$html   = CampaignViewPage::build_content_html( $result['campaign'] );

		$this->assertStringContainsString( 'Older Sent', $html );
		$this->assertStringContainsString( '/newsletter/' . $older . '/', $html );
		$this->assertStringNotContainsString( '/newsletter/' . $current . '/', $html );
		$this->assertStringNotContainsString( 'Draft Skip', $html );
		// Current subject appears in h1 only — not as a recent link text duplicate requirement;
		// ensure recent list does not link to current.
		$this->assertDoesNotMatchRegularExpression(
			'/wprn-campaign-view__recent-link[^>]+href="[^"]*\/newsletter\/' . $current . '\//',
			$html
		);
	}

	/**
	 * Draft campaign is not publicly viewable.
	 */
	public function test_draft_campaign_view_is_404(): void {
		$id = $this->insert_campaign( 'Hidden Draft', CampaignStatus::DRAFT, '<p>Secret draft body</p>' );

		$result = CampaignViewPage::resolve( $id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 404, $result['status'] );
		$this->assertNull( $result['campaign'] );

		$html = CampaignViewPage::build_not_found_content_html( $result['message'] );
		$this->assertStringNotContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringNotContainsString( '<html', $html );
		$this->assertStringContainsString( 'wprn-campaign-view--404', $html );
		$this->assertStringContainsString( '<h1>', $html );
		$this->assertStringContainsString( '/wordpress-newsletter/', $html );
		$this->assertStringNotContainsString( 'Secret draft body', $html );
		$this->assertStringNotContainsString( 'Hidden Draft', $html );
	}

	/**
	 * Missing id is 404.
	 */
	public function test_missing_campaign_view_is_404(): void {
		$result = CampaignViewPage::resolve( 999999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 404, $result['status'] );
	}

	/**
	 * Non-scalar / zero id rejected.
	 */
	public function test_zero_and_invalid_id_are_404(): void {
		$this->assertFalse( CampaignViewPage::resolve( 0 )['ok'] );
		$this->assertFalse( CampaignViewPage::resolve( -3 )['ok'] );
	}

	/**
	 * Public URL helper uses directory-style /newsletter/{id}/ permalinks.
	 */
	public function test_campaign_view_url_helper(): void {
		$url = CampaignViewPage::url( 42 );
		$this->assertStringContainsString( '/newsletter/', $url );
		$this->assertStringContainsString( '/newsletter/42/', $url );
		$this->assertStringNotContainsString( CampaignViewPage::QUERY_KEY . '=', $url );
	}

	/**
	 * Pagination renders numbered page links (not only status text).
	 */
	public function test_shortcode_pagination_numbered_pages(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->insert_campaign(
				sprintf( 'P%02d', $i ),
				CampaignStatus::SENT,
				'<p>x</p>',
				sprintf( '2026-02-%02d 10:00:00', min( $i, 28 ) )
			);
		}

		$html = do_shortcode( '[wprn_archive limit="10"]' );
		$this->assertStringContainsString( 'wprn-archive__pagination-list', $html );
		$this->assertStringContainsString( 'Next', $html );
		$this->assertMatchesRegularExpression( '/aria-current="page"[^>]*>\s*1\s*</', $html );
		$this->assertStringContainsString( CampaignArchiveShortcode::PAGE_KEY . '=2', $html );
		$this->assertStringContainsString( CampaignArchiveShortcode::PAGE_KEY . '=3', $html );

		$_GET[ CampaignArchiveShortcode::PAGE_KEY ] = '2';
		$html2 = do_shortcode( '[wprn_archive limit="10"]' );
		$this->assertStringContainsString( 'Previous', $html2 );
		$this->assertStringContainsString( 'Next', $html2 );
		$this->assertMatchesRegularExpression( '/wprn-archive__page--current[^>]*>\s*2\s*</', $html2 );
	}

	/**
	 * Repository helpers count / list only sent.
	 */
	public function test_repository_find_sent_and_count_sent(): void {
		$this->insert_campaign( 'A', CampaignStatus::SENT );
		$this->insert_campaign( 'B', CampaignStatus::SENT );
		$this->insert_campaign( 'C', CampaignStatus::DRAFT );

		$repo = new CampaignRepository();
		$this->assertSame( 2, $repo->count_sent() );
		$rows = $repo->find_sent( 10, 0 );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( CampaignStatus::SENT, $row->status );
		}
	}

	/**
	 * find_sent_excluding omits the given id and only returns sent rows.
	 */
	public function test_repository_find_sent_excluding(): void {
		$id_a = $this->insert_campaign( 'Excl A', CampaignStatus::SENT, '<p>x</p>', '2026-01-01 10:00:00' );
		$id_b = $this->insert_campaign( 'Excl B', CampaignStatus::SENT, '<p>x</p>', '2026-01-02 10:00:00' );
		$id_c = $this->insert_campaign( 'Excl C', CampaignStatus::SENT, '<p>x</p>', '2026-01-03 10:00:00' );
		$this->insert_campaign( 'Excl Draft', CampaignStatus::DRAFT, '<p>x</p>', '2026-01-04 10:00:00' );

		$repo  = new CampaignRepository();
		$rows  = $repo->find_sent_excluding( $id_c, 5 );
		$ids   = array_map( static fn( $row ): int => (int) $row->id, $rows );

		$this->assertNotContains( $id_c, $ids );
		$this->assertContains( $id_a, $ids );
		$this->assertContains( $id_b, $ids );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( CampaignStatus::SENT, $row->status );
		}

		$limited = $repo->find_sent_excluding( $id_a, 1 );
		$this->assertCount( 1, $limited );
		$this->assertSame( $id_c, (int) $limited[0]->id );
	}
	/**
	 * Kit-style email CSS must not appear as text in the public campaign view.
	 */
	public function test_campaign_view_strips_leaked_email_css(): void {
		$body = '<style>@media only screen and (max-width:600px){ .ck-mobile-font-size{font-size:50px!important;} }</style>'
			. '<p>可見正文</p>';
		$id   = $this->insert_campaign( 'CSS Leak Check', CampaignStatus::SENT, $body, '2026-09-04 10:00:00' );

		$result = CampaignViewPage::resolve( $id );
		$this->assertTrue( $result['ok'] );
		$html = CampaignViewPage::build_content_html( $result['campaign'] );
		$this->assertStringNotContainsString( '@media', $html );
		$this->assertStringNotContainsString( 'ck-mobile-font-size', $html );
		$this->assertStringNotContainsString( '414px', $html );
		$this->assertStringContainsString( '可見正文', $html );
	}


	/**
	 * Campaign view root must not use overflow-x (breaks sticky sidebar).
	 */
	public function test_campaign_view_css_allows_sticky_sidebar(): void {
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/src/Frontend/css/campaign-view.css' );
		$this->assertIsString( $css );
		$this->assertMatchesRegularExpression(
			'/\.wprn-campaign-view__sidebar\s*\{[^}]*position:\s*sticky/s',
			$css
		);
		// Root .wprn-campaign-view { ... } block must not set overflow-x.
		$this->assertDoesNotMatchRegularExpression(
			'/\.wprn-campaign-view\s*\{[^}]*overflow-x\s*:/s',
			$css
		);
	}

}
