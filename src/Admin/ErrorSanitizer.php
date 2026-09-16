<?php
/**
 * Sanitize error strings for admin display (never leak secrets).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redacts API keys and similar secrets from stored error messages.
 */
final class ErrorSanitizer {

	/**
	 * Sanitize an error string for safe display in wp-admin.
	 *
	 * @param string $error Raw error (may contain provider payloads).
	 * @return string
	 */
	public static function for_display( string $error ): string {
		$error = trim( $error );
		if ( '' === $error ) {
			return '';
		}

		// Resend-style API keys.
		$error = (string) preg_replace( '/\bre_[A-Za-z0-9_]{8,}\b/', '[redacted]', $error );

		// Bearer / Authorization headers.
		$error = (string) preg_replace( '/Bearer\s+\S+/i', 'Bearer [redacted]', $error );
		$error = (string) preg_replace( '/Authorization:\s*\S+/i', 'Authorization: [redacted]', $error );

		// Webhook secrets.
		// whsec_ secrets are base64 (A-Za-z0-9+/=); also allow URL-safe -_.
		$error = (string) preg_replace( '/\bwhsec_[A-Za-z0-9+\/=_-]+/', '[redacted]', $error );

		// Generic api_key / api-key JSON fragments.
		$error = (string) preg_replace(
			'/(["\']?(?:api[_-]?key|secret|token)["\']?\s*[:=]\s*["\']?)[^"\'\s,}]+/i',
			'$1[redacted]',
			$error
		);

		return $error;
	}
}
