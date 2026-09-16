<?php
/**
 * Subscriber ↔ tag join repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\TagsTable;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attach / detach tags; list helpers.
 */
class SubscriberTagRepository {

	/**
	 * Join table.
	 *
	 * @return string
	 */
	private function table(): string {
		return SubscriberTagTable::get_table_name();
	}

	/**
	 * Tags table.
	 *
	 * @return string
	 */
	private function tags_table(): string {
		return TagsTable::get_table_name();
	}

	/**
	 * Assign a tag to a subscriber (idempotent).
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $tag_id        Tag ID.
	 * @return bool True if row exists after call.
	 */
	public function attach( int $subscriber_id, int $tag_id ): bool {
		if ( $subscriber_id < 1 || $tag_id < 1 ) {
			return false;
		}

		if ( $this->has( $subscriber_id, $tag_id ) ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table(),
			array(
				'subscriber_id' => $subscriber_id,
				'tag_id'        => $tag_id,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Remove a tag from a subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $tag_id        Tag ID.
	 * @return bool
	 */
	public function detach( int $subscriber_id, int $tag_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$this->table(),
			array(
				'subscriber_id' => $subscriber_id,
				'tag_id'        => $tag_id,
			),
			array( '%d', '%d' )
		);
		return false !== $result;
	}

	/**
	 * Whether subscriber has tag.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $tag_id        Tag ID.
	 * @return bool
	 */
	public function has( int $subscriber_id, int $tag_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE subscriber_id = %d AND tag_id = %d',
				$this->table(),
				$subscriber_id,
				$tag_id
			)
		);
		return (int) $count > 0;
	}

	/**
	 * List tag rows for a subscriber (joined with tag name/slug).
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return list<object>
	 */
	public function find_tags_for_subscriber( int $subscriber_id ): array {
		global $wpdb;
		$join = $this->table();
		$tags = $this->tags_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.* FROM %i st INNER JOIN %i t ON t.id = st.tag_id WHERE st.subscriber_id = %d ORDER BY t.name ASC',
				$join,
				$tags,
				$subscriber_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map of subscriber_id => list of tag objects for a page of subscribers.
	 *
	 * @param array $subscriber_ids Subscriber IDs (list of ints).
	 * @return array<int, list<object>>
	 */
	public function find_tags_for_subscribers( array $subscriber_ids ): array {
		$subscriber_ids = array_values( array_unique( array_filter( array_map( 'intval', $subscriber_ids ) ) ) );
		$map            = array();
		foreach ( $subscriber_ids as $id ) {
			$map[ $id ] = array();
		}
		if ( array() === $subscriber_ids ) {
			return $map;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
		$values       = array_merge( array( $this->table(), $this->tags_table() ), $subscriber_ids );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN for allowlisted ints.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT st.subscriber_id, t.id, t.name, t.slug FROM %i st INNER JOIN %i t ON t.id = st.tag_id WHERE st.subscriber_id IN (' . $placeholders . ') ORDER BY t.name ASC',
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return $map;
		}

		foreach ( $rows as $row ) {
			$sid = (int) $row->subscriber_id;
			if ( ! isset( $map[ $sid ] ) ) {
				$map[ $sid ] = array();
			}
			$map[ $sid ][] = $row;
		}

		return $map;
	}

	/**
	 * Remove all join rows for a tag (used when deleting a tag).
	 *
	 * @param int $tag_id Tag ID.
	 * @return int Rows deleted.
	 */
	public function delete_by_tag( int $tag_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $this->table(), array( 'tag_id' => $tag_id ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Count subscribers assigned to a tag.
	 *
	 * @param int $tag_id Tag ID.
	 * @return int
	 */
	public function count_subscribers_for_tag( int $tag_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE tag_id = %d',
				$this->table(),
				$tag_id
			)
		);
		return (int) $count;
	}

	/**
	 * Count subscribers per tag for a set of tag IDs (single query).
	 *
	 * @param array $tag_ids Tag IDs (list of ints).
	 * @return array<int, int> Map of tag_id => subscriber count.
	 */
	public function count_subscribers_for_tags( array $tag_ids ): array {
		$tag_ids = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );
		$map     = array();
		foreach ( $tag_ids as $id ) {
			$map[ $id ] = 0;
		}
		if ( array() === $tag_ids ) {
			return $map;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
		$values       = array_merge( array( $this->table() ), $tag_ids );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN for allowlisted ints.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT tag_id, COUNT(*) AS subscriber_count FROM %i WHERE tag_id IN (' . $placeholders . ') GROUP BY tag_id',
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return $map;
		}

		foreach ( $rows as $row ) {
			$map[ (int) $row->tag_id ] = (int) $row->subscriber_count;
		}

		return $map;
	}
}
