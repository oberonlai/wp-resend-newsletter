<?php
/**
 * Campaigns custom table.
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
 * Manages `{prefix}wprn_campaigns` via dbDelta.
 *
 * Bodies should include Resend Broadcast unsubscribe placeholder
 * `{{{RESEND_UNSUBSCRIBE_URL}}}`. From address must use verified domain
 * `news.oberonlai.blog`. Send path (Broadcast create+send) is area 04 —
 * column `resend_broadcast_id` is nullable for that later wiring.
 *
 * v1.1: filter_tag_ids (JSON AND filter) + audience_segment_id (campaign-scoped).
 */
class CampaignsTable {

	/**
	 * Schema version — bump when columns change.
	 */
	public const VERSION = '1.1.1';

	/**
	 * Table name without wp prefix (includes plugin prefix).
	 */
	public const TABLE_NAME = 'wprn_campaigns';

	/**
	 * Option storing installed schema version.
	 */
	public const VERSION_OPTION = 'wprn_campaigns_db_version';

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
			subject varchar(255) NOT NULL DEFAULT '',
			body_html longtext NOT NULL,
			body_text longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			scheduled_at datetime DEFAULT NULL,
			resend_broadcast_id varchar(64) DEFAULT NULL,
			filter_tag_ids text DEFAULT NULL,
			audience_segment_id varchar(64) DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at),
			KEY created_by (created_by),
			KEY resend_broadcast_id (resend_broadcast_id)
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
