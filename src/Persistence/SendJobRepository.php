<?php
/**
 * Send job repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SendJobType;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + claim for `{prefix}wprn_send_jobs`.
 */
class SendJobRepository {

	/**
	 * Columns allowed in insert/update.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_COLUMNS = array(
		'campaign_id',
		'job_type',
		'status',
		'attempts',
		'next_attempt_at',
		'provider_broadcast_id',
		'last_error',
		'created_at',
		'updated_at',
	);

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table(): string {
		return SendJobsTable::get_table_name();
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
	 * Jobs for a campaign (oldest first).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return list<object>
	 */
	public function find_by_campaign( int $campaign_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE campaign_id = %d ORDER BY id ASC',
				$this->table(),
				$campaign_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete all send jobs for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_by_campaign( int $campaign_id ): int {
		global $wpdb;
		if ( $campaign_id <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $this->table(), array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Insert a job row.
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
	 * Update job by ID (allowlisted columns; null → SQL NULL).
	 *
	 * @param int                  $id   ID.
	 * @param array<string, mixed> $data Data.
	 * @return bool
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
	 * Atomically claim the next due pending job (optimistic lock).
	 *
	 * Selects the oldest pending row whose next_attempt_at is null or due,
	 * then flips status to processing only if still pending.
	 *
	 * @return object|null Claimed row or null if none / race lost.
	 */
	public function claim_next(): ?object {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// Prefer sync_segment so Broadcast never races ahead of Segment sync.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidate = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE status = %s
				AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
				ORDER BY CASE WHEN job_type = %s THEN 0 ELSE 1 END, id ASC
				LIMIT 1',
				$this->table(),
				SendJobStatus::PENDING,
				$now,
				SendJobType::SYNC_SEGMENT
			)
		);

		if ( null === $candidate ) {
			return null;
		}

		$id       = (int) $candidate->id;
		$attempts = (int) $candidate->attempts + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = %d, updated_at = %s
				WHERE id = %d AND status = %s',
				$this->table(),
				SendJobStatus::PROCESSING,
				$attempts,
				$now,
				$id,
				SendJobStatus::PENDING
			)
		);

		if ( 1 !== (int) $claimed ) {
			return null;
		}

		$row = $this->find_by_id( $id );
		return null !== $row ? $row : null;
	}


	/**
	 * Count jobs by status (optional campaign filter).
	 *
	 * @param string|null $status      Status or null for all.
	 * @param int|null    $campaign_id Optional campaign ID.
	 * @return int
	 */
	public function count_by_status( ?string $status = null, ?int $campaign_id = null ): int {
		global $wpdb;

		if ( null !== $status && '' !== $status && ! SendJobStatus::is_valid( $status ) ) {
			return 0;
		}

		if ( null !== $campaign_id && $campaign_id > 0 ) {
			if ( null !== $status && '' !== $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = %s',
						$this->table(),
						$campaign_id,
						$status
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE campaign_id = %d',
						$this->table(),
						$campaign_id
					)
				);
			}
		} elseif ( null !== $status && '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE status = %s',
					$this->table(),
					$status
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i',
					$this->table()
				)
			);
		}

		return (int) $count;
	}

	/**
	 * Counts grouped by status for admin queue view.
	 *
	 * @param int|null $campaign_id Optional campaign filter.
	 * @return array<string, int> Map of status => count (includes zero for known statuses).
	 */
	public function count_grouped_by_status( ?int $campaign_id = null ): array {
		global $wpdb;

		$counts = array();
		foreach ( SendJobStatus::all() as $status ) {
			$counts[ $status ] = 0;
		}

		if ( null !== $campaign_id && $campaign_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT status, COUNT(*) AS cnt FROM %i WHERE campaign_id = %d GROUP BY status',
					$this->table(),
					$campaign_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT status, COUNT(*) AS cnt FROM %i GROUP BY status',
					$this->table()
				)
			);
		}

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = (string) $row->status;
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = (int) $row->cnt;
				}
			}
		}

		return $counts;
	}

	/**
	 * List jobs for admin queue view (newest first).
	 *
	 * @param int|null    $campaign_id Optional campaign filter.
	 * @param string|null $status      Optional status filter.
	 * @param int         $limit       Max rows.
	 * @param int         $offset      Offset.
	 * @return list<object>
	 */
	public function find_for_admin( ?int $campaign_id = null, ?string $status = null, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		if ( null !== $status && '' !== $status && ! SendJobStatus::is_valid( $status ) ) {
			return array();
		}

		if ( null !== $campaign_id && $campaign_id > 0 && null !== $status && '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE campaign_id = %d AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$this->table(),
					$campaign_id,
					$status,
					$limit,
					$offset
				)
			);
		} elseif ( null !== $campaign_id && $campaign_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE campaign_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
					$this->table(),
					$campaign_id,
					$limit,
					$offset
				)
			);
		} elseif ( null !== $status && '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$this->table(),
					$status,
					$limit,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
					$this->table(),
					$limit,
					$offset
				)
			);
		}

		return is_array( $rows ) ? $rows : array();
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
