<?php
/**
 * Send job status constants.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Domain;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local send_jobs status values (Broadcast pipeline).
 */
final class SendJobStatus {

	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const SENT       = 'sent';
	public const FAILED     = 'failed';

	/**
	 * All known statuses.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::PROCESSING,
			self::SENT,
			self::FAILED,
		);
	}

	/**
	 * Whether a status string is valid.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
