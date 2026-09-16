<?php
/**
 * Subscriber repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\SubscriberStatus;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for `{prefix}wprn_subscribers`.
 */
class SubscriberRepository {

	/**
	 * Columns allowed in insert/update (defense-in-depth for $wpdb).
	 *
	 * @var list<string>
	 */
	private const ALLOWED_COLUMNS = array(
		'email',
		'status',
		'confirm_token_hash',
		'unsub_token_hash',
		'confirm_expires_at',
		'confirmed_at',
		'resend_contact_id',
		'created_at',
		'updated_at',
	);

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table(): string {
		return SubscribersTable::get_table_name();
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
	 * Find by email (case-normalized storage expected lowercase).
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public function find_by_email( string $email ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s',
				$this->table(),
				strtolower( $email )
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * Find by confirm token hash.
	 *
	 * @param string $hash HMAC hex.
	 * @return object|null
	 */
	public function find_by_confirm_token_hash( string $hash ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE confirm_token_hash = %s',
				$this->table(),
				$hash
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * Find by unsubscribe token hash.
	 *
	 * @param string $hash HMAC hex.
	 * @return object|null
	 */
	public function find_by_unsub_token_hash( string $hash ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE unsub_token_hash = %s',
				$this->table(),
				$hash
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * Insert a subscriber row.
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
	 * Update subscriber by ID.
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
	 * Clear confirm token fields after successful confirm (one-time use).
	 *
	 * @param int $id Subscriber ID.
	 * @return bool
	 */
	public function clear_confirm_token( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET confirm_token_hash = NULL, confirm_expires_at = NULL, updated_at = %s WHERE id = %d',
				$this->table(),
				current_time( 'mysql', true ),
				$id
			)
		);
		return false !== $result;
	}


	/**
	 * Count subscribers with a given status.
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
	 * List confirmed subscribers (oldest first) for Segment sync.
	 *
	 * @param int $limit  Max rows (capped).
	 * @param int $offset Offset.
	 * @return list<object>
	 */
	public function find_confirmed( int $limit = 500, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 5000, $limit ) );
		$offset = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				$this->table(),
				SubscriberStatus::CONFIRMED,
				$limit,
				$offset
			)
		);
		return is_array( $rows ) ? $rows : array();
	}


	/**
	 * List subscribers for admin UI (newest first by default).
	 *
	 * @param string|null $status Optional status filter.
	 * @param int         $limit  Max rows.
	 * @param int         $offset Offset.
	 * @param string      $orderby Column (allowlisted).
	 * @param string      $order   ASC|DESC.
	 * @return list<object>
	 */
	public function find_for_admin( ?string $status = null, int $limit = 20, int $offset = 0, string $orderby = 'id', string $order = 'DESC' ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$allowed_orderby = array( 'id', 'email', 'status', 'created_at', 'updated_at', 'confirmed_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}
		$order = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		// $orderby / $order are allowlisted identifiers — safe to interpolate.
		$order_sql = $orderby . ' ' . $order;

		if ( null !== $status && '' !== $status ) {
			if ( ! SubscriberStatus::is_valid( $status ) ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ORDER BY allowlisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY ' . $order_sql . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY allowlisted.
					$this->table(),
					$status,
					$limit,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ORDER BY allowlisted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY ' . $order_sql . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY allowlisted.
					$this->table(),
					$limit,
					$offset
				)
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count subscribers for admin UI (optional status filter).
	 *
	 * @param string|null $status Optional status.
	 * @return int
	 */
	public function count_for_admin( ?string $status = null ): int {
		global $wpdb;

		if ( null !== $status && '' !== $status ) {
			if ( ! SubscriberStatus::is_valid( $status ) ) {
				return 0;
			}
			return $this->count_by_status( $status );
		}

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
	 * Mark a subscriber unsubscribed (admin bulk / row action).
	 *
	 * @param int $id Subscriber ID.
	 * @return bool
	 */
	public function mark_unsubscribed( int $id ): bool {
		$row = $this->find_by_id( $id );
		if ( null === $row ) {
			return false;
		}

		if ( SubscriberStatus::UNSUBSCRIBED === (string) $row->status ) {
			return true;
		}

		$ok = $this->update(
			$id,
			array(
				'status'             => SubscriberStatus::UNSUBSCRIBED,
				'confirm_token_hash' => null,
				'confirm_expires_at' => null,
			)
		);

		if ( $ok ) {
			/**
			 * Subscriber unsubscribed via admin.
			 *
			 * @param int    $id    Subscriber ID.
			 * @param string $email Email.
			 */
			do_action( 'wprn_subscriber_unsubscribed', $id, (string) $row->email );
		}

		return $ok;
	}


	/**
	 * Confirmed subscriber IDs that have ALL of the given tags (AND).
	 *
	 * Empty $tag_ids returns all confirmed IDs (no filter).
	 *
	 * @param array $tag_ids Tag IDs (AND, list of ints).
	 * @return list<int>
	 */
	public function find_confirmed_ids_with_all_tags( array $tag_ids ): array {
		$tag_ids = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );

		if ( array() === $tag_ids ) {
			$rows = $this->find_confirmed( 5000, 0 );
			$ids  = array();
			foreach ( $rows as $row ) {
				$ids[] = (int) $row->id;
			}
			return $ids;
		}

		global $wpdb;
		$subs   = $this->table();
		$join   = SubscriberTagTable::get_table_name();
		$n      = count( $tag_ids );
		$ph     = implode( ',', array_fill( 0, $n, '%d' ) );
		$values = array_merge(
			array( $subs, $join, SubscriberStatus::CONFIRMED ),
			$tag_ids,
			array( $n )
		);

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN placeholders for allowlisted ints; values prepared via $values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.id FROM %i s
				INNER JOIN %i st ON st.subscriber_id = s.id
				WHERE s.status = %s AND st.tag_id IN ($ph)
				GROUP BY s.id
				HAVING COUNT(DISTINCT st.tag_id) = %d
				ORDER BY s.id ASC",
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * Confirmed subscriber rows that have ALL of the given tags (AND).
	 *
	 * @param array $tag_ids Tag IDs (list of ints).
	 * @return list<object>
	 */
	public function find_confirmed_with_all_tags( array $tag_ids ): array {
		$ids = $this->find_confirmed_ids_with_all_tags( $tag_ids );
		if ( array() === $ids ) {
			return array();
		}

		$tag_ids = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );
		if ( array() === $tag_ids ) {
			return $this->find_confirmed( 5000, 0 );
		}

		global $wpdb;
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$values = array_merge( array( $this->table() ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id IN (' . $ph . ') ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$values
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count confirmed subscribers matching ALL tags (AND). Empty = all confirmed.
	 *
	 * @param array $tag_ids Tag IDs (list of ints).
	 * @return int
	 */
	public function count_confirmed_with_all_tags( array $tag_ids ): int {
		$tag_ids = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );
		if ( array() === $tag_ids ) {
			return $this->count_by_status( SubscriberStatus::CONFIRMED );
		}
		return count( $this->find_confirmed_ids_with_all_tags( $tag_ids ) );
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
}
