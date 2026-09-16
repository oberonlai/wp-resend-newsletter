<?php
/**
 * Tag repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\TagsTable;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for `{prefix}wprn_tags`.
 */
class TagRepository {

	/**
	 * Columns allowed in insert/update.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_COLUMNS = array(
		'name',
		'slug',
		'created_at',
		'updated_at',
	);

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table(): string {
		return TagsTable::get_table_name();
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
	 * Find by slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public function find_by_slug( string $slug ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s',
				$this->table(),
				$slug
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * Find by name (exact).
	 *
	 * @param string $name Name.
	 * @return object|null
	 */
	public function find_by_name( string $name ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE name = %s',
				$this->table(),
				$name
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * List all tags (name ASC).
	 *
	 * @return list<object>
	 */
	public function find_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY name ASC',
				$this->table()
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find tags by IDs.
	 *
	 * @param array $ids Tag IDs (list of ints).
	 * @return list<object>
	 */
	public function find_by_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
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
				'SELECT * FROM %i WHERE id IN (' . $placeholders . ') ORDER BY name ASC',
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a tag row.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table(), $filtered, $this->formats_for( $filtered ) );
		if ( false === $result ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update tag by ID.
	 *
	 * @param int                  $id   ID.
	 * @param array<string, mixed> $data Data.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );
		$filtered           = $this->filter_columns( $data );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Columns allowlisted.
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		return false !== $result;
	}

	/**
	 * Delete tag by ID.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Count all tags.
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
	 * Drop non-allowlisted columns; null if unknown key present.
	 *
	 * @param array<string, mixed> $data Raw.
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
	 * Format placeholders.
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
