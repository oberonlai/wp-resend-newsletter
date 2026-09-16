<?php
/**
 * Subscribers custom table.
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
 * Manages `{prefix}wprn_subscribers` via dbDelta.
 */
class SubscribersTable {

	/**
	 * Schema version — bump when columns change.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Table name without wp prefix (includes plugin prefix).
	 */
	public const TABLE_NAME = 'wprn_subscribers';

	/**
	 * Option storing installed schema version.
	 */
	public const VERSION_OPTION = 'wprn_subscribers_db_version';

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
			email varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			confirm_token_hash varchar(64) DEFAULT NULL,
			unsub_token_hash varchar(64) DEFAULT NULL,
			confirm_expires_at datetime DEFAULT NULL,
			confirmed_at datetime DEFAULT NULL,
			resend_contact_id varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status),
			KEY confirm_token_hash (confirm_token_hash),
			KEY unsub_token_hash (unsub_token_hash)
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
