<?php
/**
 * Token generation and hashing for confirm/unsubscribe links.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Security;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Random tokens + HMAC hashes. Raw tokens are never persisted.
 */
class TokenService {

	/**
	 * Purpose salt keys for HMAC.
	 */
	public const PURPOSE_CONFIRM = 'wprn_confirm';
	public const PURPOSE_UNSUB   = 'wprn_unsub';

	/**
	 * Generate a cryptographically random raw token (hex).
	 *
	 * @param int $bytes Number of random bytes (default 32 → 64 hex chars).
	 * @return string
	 */
	public function generate_raw( int $bytes = 32 ): string {
		return bin2hex( random_bytes( max( 16, $bytes ) ) );
	}

	/**
	 * Hash a raw token with HMAC-SHA256 using WP auth salt + purpose.
	 *
	 * @param string $raw     Raw token from the URL.
	 * @param string $purpose Purpose constant (confirm / unsub).
	 * @return string Hex HMAC digest.
	 */
	public function hash( string $raw, string $purpose ): string {
		$key = $this->signing_key( $purpose );
		return hash_hmac( 'sha256', $raw, $key );
	}

	/**
	 * Constant-time compare of a raw token against a stored hash.
	 *
	 * @param string $raw          Raw token.
	 * @param string $purpose      Purpose.
	 * @param string $stored_hash  Stored HMAC hex.
	 * @return bool
	 */
	public function verify( string $raw, string $purpose, string $stored_hash ): bool {
		if ( '' === $raw || '' === $stored_hash ) {
			return false;
		}
		$computed = $this->hash( $raw, $purpose );
		return hash_equals( $stored_hash, $computed );
	}

	/**
	 * Build signing key from WordPress salts + purpose string.
	 *
	 * @param string $purpose Purpose.
	 * @return string
	 */
	private function signing_key( string $purpose ): string {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : 'wprn-fallback-salt';
		return $salt . '|' . $purpose;
	}
}
