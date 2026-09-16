<?php
/**
 * Integration tests: footer subscribe helper + global enqueue.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Frontend;

use WP_UnitTestCase;
use WpResendNewsletter\Blocks\Subscribe\SubscribeBlock;
use WpResendNewsletter\Frontend\FooterSubscribe;

/**
 * @covers \WpResendNewsletter\Frontend\FooterSubscribe
 */
class FooterSubscribe_Test extends WP_UnitTestCase {

	/**
	 * Ensure block scripts registered (Bootstrap may already have done so).
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( SubscribeBlock::BLOCK_NAME ) ) {
			SubscribeBlock::register();
		}
		FooterSubscribe::register();
	}

	/**
	 * Render contains view.js hooks + email field.
	 */
	public function test_render_contains_data_wprn_subscribe_and_email(): void {
		$html = FooterSubscribe::get_html();

		$this->assertStringContainsString( 'data-wprn-subscribe', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'wprn-subscribe__form', $html );
		$this->assertStringContainsString( 'wprn-subscribe__button', $html );
		$this->assertStringContainsString( 'wprn-subscribe__message', $html );
		$this->assertStringContainsString( 'oberon-subscribe', $html );
		$this->assertStringContainsString( 'oberon-newsletter-embed', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--with-intro', $html );
		$this->assertStringNotContainsString( 'handwritten-letter', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe__media', $html );
		$this->assertStringNotContainsString( 'wprn-subscribe--split', $html );
	}

	/**
	 * Shortcode returns same markup family.
	 */
	public function test_shortcode_registered(): void {
		global $shortcode_tags;
		$this->assertArrayHasKey( FooterSubscribe::SHORTCODE, $shortcode_tags );

		$html = do_shortcode( '[' . FooterSubscribe::SHORTCODE . ']' );
		$this->assertStringContainsString( 'data-wprn-subscribe', $html );
		$this->assertStringContainsString( 'name="email"', $html );
	}

	/**
	 * wp_enqueue_scripts registers/enqueues view script + style.
	 */
	public function test_enqueue_assets_registered(): void {
		FooterSubscribe::enqueue_assets();

		$this->assertTrue(
			wp_script_is( SubscribeBlock::VIEW_HANDLE, 'enqueued' )
			|| wp_script_is( SubscribeBlock::VIEW_HANDLE, 'registered' ),
			'Expected wprn-subscribe-view registered/enqueued'
		);
		$this->assertTrue(
			wp_style_is( FooterSubscribe::STYLE_HANDLE, 'enqueued' )
			|| wp_style_is( FooterSubscribe::STYLE_HANDLE, 'registered' ),
			'Expected subscribe style registered/enqueued'
		);

		// Localized config for fetch.
		$data = wp_scripts()->get_data( SubscribeBlock::VIEW_HANDLE, 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'wprnSubscribe', $data );
	}

	/**
	 * Page subscribe block shortcode must stay unregistered.
	 */
	public function test_does_not_register_page_subscribe_shortcode(): void {
		global $shortcode_tags;
		$this->assertArrayNotHasKey( 'wprn_subscribe', $shortcode_tags );
	}
}
