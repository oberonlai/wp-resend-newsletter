<?php
/**
 * Unit tests for EmailHtmlRenderer (v1.1 areas 05–06).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Infrastructure\ResendClient;

/**
 * @covers \WpResendNewsletter\Application\EmailHtmlRenderer
 */
class EmailHtmlRenderer_Test extends TestCase {

	/**
	 * Scenario: wraps and inlines paragraph + button link styles.
	 */
	public function test_render_wraps_and_inlines_paragraph_and_button(): void {
		$inner = '<p>Hello</p><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com">Go</a></div>';
		$out   = EmailHtmlRenderer::render( $inner );

		$this->assertStringContainsString( 'wprn-email-shell', $out );
		$this->assertMatchesRegularExpression( '/<table[^>]*>/i', $out );

		$this->assertMatchesRegularExpression(
			'/<p[^>]*style=["\'][^"\']+["\'][^>]*>Hello<\/p>/i',
			$out
		);

		$this->assertMatchesRegularExpression(
			'/<a[^>]*class=["\'][^"\']*wp-block-button__link[^"\']*["\'][^>]*style=["\'][^"\']*(background|background-color)[^"\']*["\']/i',
			$out
		);
	}

	/**
	 * Scenario: brand template includes text header, contact, unsubscribe; accent on header only.
	 */
	public function test_render_includes_logo_contact_and_unsubscribe(): void {
		$out = EmailHtmlRenderer::render( '<p>品牌測試</p>' );

		$this->assertStringContainsString( 'wprn-email-header', $out );
		$this->assertStringContainsString( 'wprn-email-footer', $out );
		$this->assertStringContainsString( 'WordPress 開發週報', $out );
		$this->assertStringContainsString( 'By Oberon Lai', $out );
		// Accent line beside header (title) only — not full card.
		$this->assertMatchesRegularExpression( '/wprn-email-header[^>]*border-left:\s*3px\s+solid\s+#FFDC73/i', $out );
		$this->assertDoesNotMatchRegularExpression( '/wprn-email-card[^>]*border-left\s*:/i', $out );
		$this->assertDoesNotMatchRegularExpression( '/wprn-email-body[^>]*border-left:\s*3px\s+solid\s+#FFDC73/i', $out );
		$this->assertDoesNotMatchRegularExpression( '/wprn-email-footer[^>]*border-left:\s*3px\s+solid\s+#FFDC73/i', $out );
		$this->assertSame( '#FFDC73', EmailHtmlRenderer::tokens()['accent_color'] );
		// Footer may still include small logo PNG.
		$this->assertMatchesRegularExpression( '/wprn-email-footer[\s\S]*<img[^>]+src=["\'][^"\']+logo\.png["\']/i', $out );
		$contact = EmailHtmlRenderer::footer_contact();
		$this->assertNotSame( '', $contact['name'] );
		$this->assertStringContainsString( $contact['name'], $out );
		$this->assertStringContainsString( $contact['url'], $out );
		$this->assertStringContainsString( $contact['email'], $out );
		$this->assertStringContainsString( ResendClient::UNSUBSCRIBE_PLACEHOLDER, $out );
		$this->assertStringContainsString( '取消訂閱', $out );
	}

	/**
	 * Scenario: idempotent wrap — no double shell.
	 */
	public function test_render_is_idempotent_for_shell(): void {
		$once  = EmailHtmlRenderer::render( '<p>Once</p>' );
		$twice = EmailHtmlRenderer::render( $once );

		$this->assertSame( 1, substr_count( $twice, 'wprn-email-shell' ) );
		$this->assertSame( 1, substr_count( $twice, 'wprn-email-footer' ) );
		$this->assertStringContainsString( 'Once', $twice );
	}

	/**
	 * Scenario: unwrap strips shell/header/footer for editor load.
	 */
	public function test_unwrap_returns_inner_html(): void {
		$wrapped = EmailHtmlRenderer::render( '<p>Kit</p>' );
		$inner   = EmailHtmlRenderer::unwrap( $wrapped );

		$this->assertStringNotContainsString( 'wprn-email-shell', $inner );
		$this->assertStringNotContainsString( 'wprn-email-header', $inner );
		$this->assertStringNotContainsString( 'wprn-email-footer', $inner );
		$this->assertStringNotContainsString( ResendClient::UNSUBSCRIBE_PLACEHOLDER, $inner );
		$this->assertStringContainsString( 'Kit', $inner );
		$this->assertStringContainsString( '<p', $inner );
	}

	/**
	 * Empty input stays empty (no useless shell).
	 */
	public function test_render_empty_returns_empty(): void {
		$this->assertSame( '', EmailHtmlRenderer::render( '' ) );
		$this->assertSame( '', EmailHtmlRenderer::render( '   ' ) );
	}

	/**
	 * Shared token CSS for editor canvas exposes brand tokens.
	 */
	public function test_editor_canvas_css_exposes_tokens(): void {
		$css    = EmailHtmlRenderer::editor_canvas_css();
		$tokens = EmailHtmlRenderer::tokens();

		$this->assertNotSame( '', trim( $css ) );
		$this->assertStringContainsString( 'editor-styles-wrapper', $css );
		$this->assertStringContainsString( 'font-family', $css );
		$this->assertSame( 640, EmailHtmlRenderer::CONTENT_WIDTH );
		$this->assertSame( '#293132', $tokens['color'] );
		$this->assertSame( '#293132', $tokens['heading_color'] );
		$this->assertSame( '#f6f6f6', $tokens['page_bg'] );
		$this->assertSame( '1.7', $tokens['line_height'] );
		$this->assertSame( 640, $tokens['content_width'] );
		$this->assertStringContainsString( '#293132', $css );
	}
	/**
	 * Scenario: transactional render omits Broadcast unsubscribe placeholder.
	 */
	public function test_render_without_unsubscribe_omits_placeholder(): void {
		$with = EmailHtmlRenderer::render( '<p>Campaign</p>', true );
		$without = EmailHtmlRenderer::render( '<p>Confirm</p>', false );

		$this->assertStringContainsString( 'wprn-email-shell', $without );
		$this->assertStringContainsString( 'wprn-email-header', $without );
		$this->assertStringContainsString( 'wprn-email-footer', $without );
		$this->assertStringContainsString( ResendClient::UNSUBSCRIBE_PLACEHOLDER, $with );
		$this->assertStringNotContainsString( ResendClient::UNSUBSCRIBE_PLACEHOLDER, $without );
		$this->assertStringContainsString( 'Confirm', $without );
	}

	/**
	 * Scenario: Kit email <style> must not leak as text on the public web view.
	 */
	public function test_for_web_strips_style_blocks(): void {
		$html = '<style type="text/css">@media only screen and (max-width:600px){ .ck-mobile-font-size{font-size:50px!important;} }</style>'
			. '<p>AI 工作流正文</p>';
		$out  = EmailHtmlRenderer::for_web( $html );
		$this->assertStringNotContainsString( '@media', $out );
		$this->assertStringNotContainsString( 'ck-mobile-font-size', $out );
		$this->assertStringContainsString( 'AI 工作流正文', $out );
	}

	/**
	 * Scenario: Orphan CSS text (style tags already gone) is removed before first HTML block.
	 */
	public function test_for_web_strips_orphan_css_prefix(): void {
		$html = '@media screen and (max-width: 384px) { .message-content { width: 414px !important; } }'
			. '<p>正文段落</p>';
		$out  = EmailHtmlRenderer::for_web( $html );
		$this->assertStringNotContainsString( '414px', $out );
		$this->assertStringContainsString( '正文段落', $out );
	}

	/**
	 * Scenario: Script blocks are stripped for public web.
	 */
	public function test_for_web_strips_script_blocks(): void {
		$html = '<script>alert(1)</script><p>Safe</p>';
		$out  = EmailHtmlRenderer::for_web( $html );
		$this->assertStringNotContainsString( 'alert', $out );
		$this->assertStringContainsString( 'Safe', $out );
	}


	/**
	 * Scenario: send-time document adds viewport meta so mobile clients do not shrink text.
	 */
	public function test_to_document_adds_viewport_and_mobile_css(): void {
		$fragment = EmailHtmlRenderer::wrap_shell( '<p>Hi</p>', false );
		$doc      = EmailHtmlRenderer::to_document( $fragment );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $doc );
		$this->assertStringContainsString( '<meta name="viewport" content="width=device-width, initial-scale=1" />', $doc );
		$this->assertStringContainsString( '@media only screen and (max-width:620px)', $doc );
		$this->assertStringContainsString( 'overflow-wrap:anywhere', $doc );
		$this->assertStringContainsString( $fragment, $doc );
	}

	/**
	 * Scenario: empty input and full documents are returned unchanged.
	 */
	public function test_to_document_is_idempotent(): void {
		$doc = EmailHtmlRenderer::to_document( EmailHtmlRenderer::wrap_shell( '<p>Hi</p>', false ) );

		$this->assertSame( $doc, EmailHtmlRenderer::to_document( $doc ) );
		$this->assertSame( '', EmailHtmlRenderer::to_document( '' ) );
	}
}
