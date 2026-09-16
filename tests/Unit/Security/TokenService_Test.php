<?php
/**
 * Unit tests for TokenService.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Security\TokenService;

/**
 * @covers \WpResendNewsletter\Security\TokenService
 */
class TokenService_Test extends TestCase {

	/**
	 * Raw tokens are long hex strings; hashes are deterministic for a purpose.
	 */
	public function test_generate_and_hash_roundtrip(): void {
		$svc = new TokenService();
		$raw = $svc->generate_raw();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $raw );

		$hash1 = $svc->hash( $raw, TokenService::PURPOSE_CONFIRM );
		$hash2 = $svc->hash( $raw, TokenService::PURPOSE_CONFIRM );

		$this->assertSame( $hash1, $hash2 );
		$this->assertTrue( $svc->verify( $raw, TokenService::PURPOSE_CONFIRM, $hash1 ) );
		$this->assertFalse( $svc->verify( $raw . 'x', TokenService::PURPOSE_CONFIRM, $hash1 ) );
		$this->assertFalse( $svc->verify( $raw, TokenService::PURPOSE_UNSUB, $hash1 ) );
	}

	/**
	 * Different purposes produce different digests for the same raw token.
	 */
	public function test_purpose_separates_hashes(): void {
		$svc  = new TokenService();
		$raw  = $svc->generate_raw();
		$c    = $svc->hash( $raw, TokenService::PURPOSE_CONFIRM );
		$u    = $svc->hash( $raw, TokenService::PURPOSE_UNSUB );

		$this->assertNotSame( $c, $u );
	}
}
