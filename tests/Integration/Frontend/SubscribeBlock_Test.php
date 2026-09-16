<?php
/**
 * Integration tests: subscribe Gutenberg block + confirm HTML page.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Frontend;

use WP_REST_Request;
use WP_UnitTestCase;
use WpResendNewsletter\Application\ConfirmService;
use WpResendNewsletter\Application\SubscribeService;
use WpResendNewsletter\Blocks\Subscribe\SubscribeBlock;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Frontend\ConfirmPage;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\TokenService;

/**
 * @covers \WpResendNewsletter\Blocks\Subscribe\SubscribeBlock
 * @covers \WpResendNewsletter\Frontend\ConfirmPage
 */
class SubscribeBlock_Test extends WP_UnitTestCase {

	/**
	 * Captured Resend batches.
	 *
	 * @var array<int, mixed>
	 */
	private array $sent_batches = array();

	/**
	 * Set up table + settings.
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

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( SubscribeBlock::BLOCK_NAME ) ) {
			SubscribeBlock::register();
		}
	}

	/**
	 * Truncate subscribers.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = SubscribersTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		parent::tear_down();
	}

	/**
	 * @return SubscribeService
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
	 * Block registered; shortcode must not be.
	 */
	public function test_block_registered_and_no_shortcode(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$this->assertTrue(
			$registry->is_registered( 'wp-resend-newsletter/subscribe' ),
			'Expected wp-resend-newsletter/subscribe block'
		);

		global $shortcode_tags;
		$this->assertArrayNotHasKey(
			'wprn_subscribe',
			$shortcode_tags,
			'Shortcode must not be registered (block-only)'
		);
	}

	/**
	 * Render outputs accessible form with attribute labels.
	 */
	public function test_block_render_markup(): void {
		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe {"title":"Join the list","buttonLabel":"Sign up"} /-->'
		);

		$this->assertStringContainsString( 'Join the list', $html );
		$this->assertStringContainsString( 'Sign up', $html );
		$this->assertMatchesRegularExpression( '/type=["\']email["\']/', $html );
		$this->assertMatchesRegularExpression( '/\brequired\b/', $html );
		$this->assertStringContainsString( 'aria-live', $html );
		$this->assertStringContainsString( 'wprn-subscribe', $html );
		$this->assertStringContainsString( 'wprn-subscribe--split', $html );
		$this->assertStringContainsString( 'wprn-subscribe__media', $html );
		$this->assertStringContainsString( 'wprn-subscribe__photo', $html );
		$this->assertStringContainsString( 'wprn-subscribe__body', $html );
		$this->assertStringContainsString( 'handwritten-letter.jpg', $html );
		$this->assertStringContainsString( 'for=', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--with-intro', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe__intro', $html );
	}

	/**
	 * Confirm email link uses public HTML query arg.
	 */
	public function test_confirm_email_uses_html_confirm_url(): void {
		$result = $this->subscribe_service()->subscribe( 'block-confirm-url@example.com' );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $this->sent_batches );

		$html = (string) $this->sent_batches[0][0]['html'];
		$this->assertStringContainsString( ConfirmPage::QUERY_KEY . '=', $html );
		$this->assertStringNotContainsString( '/wp-json/wprn/v1/subscribers/confirm', $html );
		$this->assertStringContainsString( 'wprn-email-shell', $html );
		$this->assertStringNotContainsString( '{{{RESEND_UNSUBSCRIBE_URL}}}', $html );
	}

	/**
	 * Valid confirm → status confirmed; content markup (theme provides shell).
	 */
	public function test_confirm_html_success_confirms_subscriber(): void {
		$result = $this->subscribe_service()->subscribe( 'html-ok@example.com' );
		$token  = $result['confirm_token'];

		$confirm = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$ok      = $confirm->confirm( $token );
		$this->assertTrue( $ok['ok'] );

		$row = ( new SubscriberRepository() )->find_by_email( 'html-ok@example.com' );
		$this->assertSame( SubscriberStatus::CONFIRMED, $row->status );

		$html = ConfirmPage::build_content_html( true, $ok['message'] );
		$this->assertStringNotContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'wprn-confirm--ok', $html );
		$this->assertStringContainsString( 'Your subscription is confirmed', $html );

		$url = ConfirmPage::url( 'abc123' );
		$this->assertStringContainsString( ConfirmPage::QUERY_KEY . '=abc123', $url );
	}

	/**
	 * Invalid token → HTML error content; no confirm.
	 */
	public function test_confirm_html_invalid_token(): void {
		$service = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$result  = $service->confirm( 'not-a-real-token' );
		$this->assertFalse( $result['ok'] );

		$html = ConfirmPage::build_content_html( false, $result['message'] );
		$this->assertStringNotContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'wprn-confirm--err', $html );
		$this->assertStringContainsString( esc_html( $result['message'] ), $html );
	}

	/**
	 * REST confirm still returns JSON.
	 */
	public function test_rest_confirm_still_json(): void {
		$result = $this->subscribe_service()->subscribe( 'rest-json@example.com' );

		$req = new WP_REST_Request( 'GET', '/wprn/v1/subscribers/confirm' );
		$req->set_param( 'token', $result['confirm_token'] );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Confirm HTML must escape untrusted message content (XSS).
	 */
	public function test_confirm_html_escapes_xss_in_message(): void {
		$html = ConfirmPage::build_content_html( false, '<img src=x onerror=alert(1)><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;img', $html );
		$this->assertStringContainsString( 'onerror=alert(1)', $html ); // Escaped as text, not an attribute.
	}

	/**
	 * Array query param must not fatal; treated as invalid token.
	 */
	public function test_confirm_page_rejects_array_token(): void {
		$_GET[ ConfirmPage::QUERY_KEY ] = array( 'evil' );
		$service = new ConfirmService( new SubscriberRepository(), new TokenService() );
		// Mirror ConfirmPage::maybe_render token extraction.
		$raw   = wp_unslash( $_GET[ ConfirmPage::QUERY_KEY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
		$this->assertSame( '', $token );
		$result = $service->confirm( $token );
		$this->assertFalse( $result['ok'] );
		unset( $_GET[ ConfirmPage::QUERY_KEY ] );
	}

	/**
	 * Default (empty) block attributes use translated fallbacks in render.
	 */
	public function test_block_render_uses_i18n_defaults_when_attributes_empty(): void {
		$html = do_blocks( '<!-- wp:wp-resend-newsletter/subscribe /-->' );
		$this->assertStringContainsString( 'Subscribe to our newsletter', $html );
		$this->assertStringContainsString( 'Subscribe', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--with-intro', $html );
	}

	/**
	 * Description attribute renders inside the card, before the form.
	 */
	public function test_block_render_with_description_inside_card(): void {
		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe {"title":"Join","buttonLabel":"Go","description":"Hello line\\nSecond"} /-->'
		);

		$this->assertStringNotContainsString( 'wprn-subscribe--with-intro', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe__intro', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe__panel', $html );
		$this->assertStringContainsString( 'wprn-subscribe--split', $html );
		$this->assertStringContainsString( 'wprn-subscribe__body', $html );
		$this->assertStringContainsString( 'wprn-subscribe__lead', $html );
		$this->assertStringContainsString( 'Hello line', $html );
		$this->assertStringContainsString( 'Join', $html );
		$this->assertStringContainsString( 'Go', $html );
		$body_pos = strpos( $html, 'wprn-subscribe__body' );
		$lead_pos = strpos( $html, 'wprn-subscribe__lead' );
		$form_pos = strpos( $html, 'wprn-subscribe__form' );
		$this->assertNotFalse( $body_pos );
		$this->assertNotFalse( $lead_pos );
		$this->assertNotFalse( $form_pos );
		$this->assertLessThan( $form_pos, $lead_pos, 'Lead should appear before form' );
		$this->assertLessThan( $lead_pos, $body_pos, 'Body wrapper should appear before lead' );
		$this->assertMatchesRegularExpression( '/type=["\']email["\']/', $html );
	}

	/**
	 * Description HTML is escaped in lead paragraph.
	 */
	public function test_block_description_escapes_html(): void {
		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe {"description":"<script>alert(1)</script>Safe"} /-->'
		);

		$this->assertStringContainsString( 'wprn-subscribe__lead', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'Safe', $html );
	}

	/**
	 * Whitespace-only description omits lead paragraph.
	 */
	public function test_block_whitespace_description_has_no_intro(): void {
		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe {"description":"   "} /-->'
		);
		$this->assertStringNotContainsString( 'wprn-subscribe--with-intro', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe__lead', $html );
	}

	/**
	 * showMedia:false renders compact card without letter image / split layout.
	 */
	public function test_block_render_compact_without_media(): void {
		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe {"showMedia":false,"title":"訂閱電子報","buttonLabel":"訂閱"} /-->'
		);

		$this->assertStringContainsString( 'data-wprn-subscribe', $html );
		$this->assertStringContainsString( 'wprn-subscribe--compact', $html );
		$this->assertStringContainsString( '訂閱電子報', $html );
		$this->assertStringContainsString( '訂閱', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--split', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe__media', $html );
		$this->assertStringNotContainsString( 'handwritten-letter', $html );
		$this->assertMatchesRegularExpression( '/type=["\']email["\']/', $html );
	}

	/**
	 * showMedia omitted / true keeps split letter layout (default).
	 */
	public function test_block_show_media_true_keeps_split(): void {
		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe {"showMedia":true,"title":"Join"} /-->'
		);
		$this->assertStringContainsString( 'wprn-subscribe--split', $html );
		$this->assertStringContainsString( 'handwritten-letter.jpg', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--compact', $html );
	}

}

