<?php
/**
 * Campaign status constants.
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
 * Allowed campaign statuses (local source of truth).
 *
 * Send execution (Broadcasts) lives in area 04 — this area is CRUD + transitions only.
 */
final class CampaignStatus {

	public const DRAFT     = 'draft';
	public const READY     = 'ready';
	public const SCHEDULED = 'scheduled';
	public const SENDING   = 'sending';
	public const SENT      = 'sent';
	public const CANCELLED = 'cancelled';

	/**
	 * All known statuses.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::READY,
			self::SCHEDULED,
			self::SENDING,
			self::SENT,
			self::CANCELLED,
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

	/**
	 * Statuses that may be edited (content CRUD).
	 *
	 * @return list<string>
	 */
	public static function editable(): array {
		return array(
			self::DRAFT,
			self::READY,
			self::SCHEDULED,
		);
	}
}
