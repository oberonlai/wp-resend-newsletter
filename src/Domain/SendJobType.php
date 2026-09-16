<?php
/**
 * Send job type constants.
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
 * Job types for the Broadcast send pipeline.
 *
 * Campaigns use sync_segment → send_broadcast only — never transactional batch.
 */
final class SendJobType {

	public const SYNC_SEGMENT   = 'sync_segment';
	public const SEND_BROADCAST = 'send_broadcast';

	/**
	 * All known job types.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::SYNC_SEGMENT,
			self::SEND_BROADCAST,
		);
	}

	/**
	 * Whether a job type string is valid.
	 *
	 * @param string $type Job type.
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}
}
