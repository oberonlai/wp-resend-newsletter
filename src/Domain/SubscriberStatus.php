<?php
/**
 * Subscriber status constants.
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
 * Allowed subscriber statuses (local source of truth).
 */
final class SubscriberStatus {

	public const PENDING      = 'pending';
	public const CONFIRMED    = 'confirmed';
	public const UNSUBSCRIBED = 'unsubscribed';
	public const BOUNCED      = 'bounced';
	public const COMPLAINED   = 'complained';

	/**
	 * All known statuses.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::CONFIRMED,
			self::UNSUBSCRIBED,
			self::BOUNCED,
			self::COMPLAINED,
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
