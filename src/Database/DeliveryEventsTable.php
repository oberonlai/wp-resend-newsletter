<?php
/**
 * Delivery events custom table (webhook audit / idempotency / engagement).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages `{prefix}wprn_delivery_events` via dbDelta.
 */
class DeliveryEventsTable {

	/**
	 * Schema version — bump when columns change.
	 */
	public const VERSION = '1.1.0';

	/**
	 * Table name without wp prefix (includes plugin prefix).
	 */
	public const TABLE_NAME = 'wprn_delivery_events';

	/**
	 * Option storing installed schema version.
	 */
	public const VERSION_OPTION = 'wprn_delivery_events_db_version';

	/**
	 * Full table name with $wpdb->prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or update the table with dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta rules: TWO spaces after PRIMARY KEY; KEY name space before (.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(64) NOT NULL DEFAULT '',
			provider_event_id varchar(191) NOT NULL,
			payload_hash varchar(64) NOT NULL DEFAULT '',
			provider_broadcast_id varchar(64) DEFAULT NULL,
			provider_email_id varchar(64) DEFAULT NULL,
			link_url varchar(500) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_event_id (provider_event_id),
			KEY subscriber_id (subscriber_id),
			KEY campaign_id (campaign_id),
			KEY event_type (event_type),
			KEY provider_broadcast_id (provider_broadcast_id),
			KEY campaign_event (campaign_id, event_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Create/update table when installed version is behind or table is missing.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed    = get_option( self::VERSION_OPTION, '0' );
		$needs_schema = version_compare( (string) $installed, self::VERSION, '<' );
		// Version option can be set while the table is missing (SQLite/dbDelta quirks,
		// manual drops, or activate-before-table-added). Recreate when gone.
		if ( $needs_schema || ! self::table_exists() ) {
			self::create_table();
		}
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $result === $table_name;
	}
}
