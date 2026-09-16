<?php
/**
 * Campaign repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Domain\CampaignStatus;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for `{prefix}wprn_campaigns`.
 *
 * No Resend API calls here — Broadcast send lives in area 04.
 */
class CampaignRepository {

	/**
	 * Columns allowed in insert/update (defense-in-depth for $wpdb).
	 *
	 * @var list<string>
	 */
	private const ALLOWED_COLUMNS = array(
		'subject',
		'body_html',
		'body_text',
		'status',
		'scheduled_at',
		'resend_broadcast_id',
		'filter_tag_ids',
		'audience_segment_id',
		'created_by',
		'created_at',
		'updated_at',
	);

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table(): string {
		return CampaignsTable::get_table_name();
	}

	/**
	 * Find by primary key.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public function find_by_id( int $id ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table(),
				$id
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * Find campaign by Resend broadcast id.
	 *
	 * @param string $resend_broadcast_id Provider broadcast id.
	 * @return object|null
	 */
	public function find_by_resend_broadcast_id( string $resend_broadcast_id ): ?object {
		global $wpdb;
		if ( '' === $resend_broadcast_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE resend_broadcast_id = %s LIMIT 1',
				$this->table(),
				$resend_broadcast_id
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * List campaigns by created_at newest-first (admin list support).
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return list<object>
	 */
	public function find_all( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$this->table(),
				$limit,
				$offset
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find campaigns by IDs (keyed by id).
	 *
	 * @param array $ids Campaign IDs (list of ints).
	 * @return array<int, object>
	 */
	public function find_by_ids( array $ids ): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);
		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$values       = array_merge( array( $this->table() ), $ids );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN for allowlisted ints.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id IN (' . $placeholders . ')',
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	/**
	 * Count all campaigns.
	 *
	 * @return int
	 */
	public function count_all(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$this->table()
			)
		);
		return (int) $count;
	}


	/**
	 * Count campaigns by status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public function count_by_status( string $status ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$this->table(),
				$status
			)
		);
		return (int) $count;
	}

	/**
	 * List sent campaigns newest-first (public archive).
	 *
	 * @param int $limit  Max rows (1–100).
	 * @param int $offset Offset.
	 * @return list<object>
	 */
	public function find_sent( int $limit = 10, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$this->table(),
				CampaignStatus::SENT,
				$limit,
				$offset
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List sent campaigns newest-first, excluding one ID (sidebar recent list).
	 *
	 * @param int $exclude_id Campaign ID to omit.
	 * @param int $limit      Max rows (1–100).
	 * @return list<object>
	 */
	public function find_sent_excluding( int $exclude_id, int $limit = 5 ): array {
		global $wpdb;
		$limit      = max( 1, min( 100, $limit ) );
		$exclude_id = max( 0, $exclude_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND id != %d ORDER BY created_at DESC, id DESC LIMIT %d',
				$this->table(),
				CampaignStatus::SENT,
				$exclude_id,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count campaigns with status sent.
	 *
	 * @return int
	 */
	public function count_sent(): int {
		return $this->count_by_status( CampaignStatus::SENT );
	}

	/**
	 * Insert a campaign row.
	 *
	 * @param array<string, mixed> $data Column data.
	 * @return int|false Insert ID or false.
	 */
	public function insert( array $data ): int|false {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);

		$filtered = $this->filter_columns( $row );
		if ( null === $filtered ) {
			return false;
		}

		$formats = $this->formats_for( $filtered );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table(), $filtered, $formats );
		if ( false === $result ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update campaign by ID.
	 *
	 * Null values are written as SQL NULL (wpdb->update would coerce them).
	 * Only allowlisted column names are accepted.
	 *
	 * @param int                  $id   ID.
	 * @param array<string, mixed> $data Data.
	 * @return bool True on success (including 0 rows changed).
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$filtered = $this->filter_columns( $data );
		if ( null === $filtered || array() === $filtered ) {
			return false;
		}

		$set_parts = array();
		$values    = array( $this->table() );

		foreach ( $filtered as $column => $value ) {
			// Column names are allowlisted — strip backticks then quote.
			$col_sql = '`' . str_replace( '`', '', $column ) . '`';
			if ( null === $value ) {
				$set_parts[] = "{$col_sql} = NULL";
				continue;
			}
			$set_parts[] = "{$col_sql} = %s";
			$values[]    = $value;
		}

		$values[] = $id;
		$sql      = 'UPDATE %i SET ' . implode( ', ', $set_parts ) . ' WHERE id = %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Columns allowlisted; table via %i; values prepared.
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		return false !== $result;
	}

	/**
	 * Drop any non-allowlisted columns; return null if an unknown key was present.
	 *
	 * @param array<string, mixed> $data Raw column data.
	 * @return array<string, mixed>|null
	 */
	private function filter_columns( array $data ): ?array {
		$filtered = array();
		foreach ( $data as $column => $value ) {
			if ( ! in_array( $column, self::ALLOWED_COLUMNS, true ) ) {
				return null;
			}
			$filtered[ $column ] = $value;
		}
		return $filtered;
	}

	/**
	 * Format placeholders for columns.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return list<string>
	 */
	private function formats_for( array $data ): array {
		$formats = array();
		foreach ( array_keys( $data ) as $_column ) {
			unset( $_column );
			$formats[] = '%s';
		}
		return $formats;
	}

	/**
	 * Delete campaign by ID.
	 *
	 * @param int $id Campaign ID.
	 * @return bool True if a row was deleted.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result && (int) $result > 0;
	}

	/**
	 * Encode filter tag IDs as JSON (empty array = no filter).
	 *
	 * @param array $tag_ids Tag IDs (list of ints).
	 * @return string
	 */
	public static function encode_filter_tag_ids( array $tag_ids ): string {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );
		$json = wp_json_encode( $ids );
		return false === $json ? '[]' : $json;
	}

	/**
	 * Decode filter_tag_ids column (JSON / serialized list / null).
	 *
	 * @param mixed $raw Column value.
	 * @return list<int>
	 */
	public static function decode_filter_tag_ids( mixed $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			$ids = $raw;
		} elseif ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				$maybe = maybe_unserialize( $raw );
				$ids   = is_array( $maybe ) ? $maybe : array();
			} else {
				$ids = $decoded;
			}
		} else {
			return array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );
		return $ids;
	}
}
