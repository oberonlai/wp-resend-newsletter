<?php
/**
 * Subscribe block stylesheet contracts.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SubscribeStyle_Test extends TestCase {

	private function css(): string {
		$path = dirname( __DIR__, 3 ) . '/src/Blocks/Subscribe/style.css';
		$this->assertFileExists( $path );
		$css = file_get_contents( $path );
		$this->assertNotFalse( $css );
		return $css;
	}

	public function test_subscribe_card_is_horizontally_centered(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe\s*\{[^}]*margin:\s*0\s+auto\s+1\.75rem;/s',
			$css
		);
	}

	public function test_footer_embed_keeps_zero_margin(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression(
			'/\.site-footer__subscribe\s+\.wprn-subscribe\s*\{[^}]*margin:\s*0;/s',
			$css
		);
	}

	public function test_split_layout_rules(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--split/',
			$css
		);
		$this->assertStringContainsString( 'wprn-subscribe__media', $css );
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--split[^}]*max-width:\s*none;/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--split[^}]*width:\s*100%;/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(min-width:\s*720px\)/',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/grid-template-columns:/',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/object-fit:\s*cover/',
			$css
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.wprn-subscribe:has\(\.wprn-subscribe__lead\)\s*\{[^}]*max-width:\s*40rem;/s',
			$css
		);
	}

	public function test_split_inner_corners_are_square(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--split \.wprn-subscribe__photo[^}]*border-radius:\s*0;/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--split \.wprn-subscribe__body[^}]*border-radius:\s*0;/s',
			$css
		);
	}

	public function test_compact_layout_rules(): void {
		$css = $this->css();
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--compact/',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.wprn-subscribe--compact \.wprn-subscribe__body[^}]*padding:\s*0;/s',
			$css
		);
	}
}
