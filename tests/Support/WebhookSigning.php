<?php
/**
 * Shared Svix/Resend webhook signing helpers for tests.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Support;

/**
 * Builds whsec_ secrets and Svix v1 signatures for fixture vectors.
 */
final class WebhookSigning {

	/**
	 * Build a whsec_ secret from raw key bytes.
	 *
	 * @param string $raw_key Raw key material.
	 * @return string
	 */
	public static function make_secret( string $raw_key = 'test_webhook_secret_key!!' ): string {
		return 'whsec_' . base64_encode( $raw_key );
	}

	/**
	 * Sign a payload the same way Resend/Svix does.
	 *
	 * @param string $secret  whsec_… secret.
	 * @param string $msg_id  svix-id.
	 * @param int    $ts      Unix timestamp.
	 * @param string $payload Raw body.
	 * @return string
	 */
	public static function sign( string $secret, string $msg_id, int $ts, string $payload ): string {
		$prefix = 'whsec_';
		$key    = $secret;
		if ( 0 === strpos( $key, $prefix ) ) {
			$key = substr( $key, strlen( $prefix ) );
		}
		$decoded = base64_decode( $key, true );
		if ( false === $decoded ) {
			return 'v1,';
		}

		$to_sign = $msg_id . '.' . $ts . '.' . $payload;
		$digest  = base64_encode( hash_hmac( 'sha256', $to_sign, $decoded, true ) );
		return 'v1,' . $digest;
	}
}
