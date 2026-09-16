<?php
/**
 * Delivery event repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\DeliveryEventsTable;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for `{prefix}wprn_delivery_events` with idempotency on provider_event_id.
 */
class DeliveryEventRepository {

	/**
	 * Columns allowed in insert.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_COLUMNS = array(
		'subscriber_id',
		'campaign_id',
		'event_type',
		'provider_event_id',
		'payload_hash',
		'provider_broadcast_id',
		'provider_email_id',
		'link_url',
		'created_at',
	);

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table(): string {
		return DeliveryEventsTable::get_table_name();
	}

	/**
	 * Find by provider event id (Svix message id).
	 *
	 * @param string $provider_event_id Provider event id.
	 * @return object|null
	 */
	public function find_by_provider_event_id( string $provider_event_id ): ?object {
		global $wpdb;
		if ( '' === $provider_event_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE provider_event_id = %s',
				$this->table(),
				$provider_event_id
			)
		);
		return null !== $row ? $row : null;
	}

	/**
	 * Insert a delivery event. Returns false if duplicate provider_event_id.
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
	 * Aggregate totals + unique subscribers for a campaign event type.
	 *
	 * Unique = distinct non-zero subscriber_id (idempotent rows already unique on provider_event_id).
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $event_type  Event type (e.g. email.opened).
	 * @return array{total: int, unique: int}
	 */
	public function count_for_campaign_event( int $campaign_id, string $event_type ): array {
		global $wpdb;
		if ( $campaign_id <= 0 || '' === $event_type ) {
			return array(
				'total'  => 0,
				'unique' => 0,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total,
					COUNT(DISTINCT NULLIF(subscriber_id, 0)) AS unique_subscribers
				FROM %i
				WHERE campaign_id = %d AND event_type = %s',
				$this->table(),
				$campaign_id,
				$event_type
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array(
				'total'  => 0,
				'unique' => 0,
			);
		}
		return array(
			'total'  => (int) ( $row['total'] ?? 0 ),
			'unique' => (int) ( $row['unique_subscribers'] ?? 0 ),
		);
	}

	/**
	 * Top clicked links for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Max rows.
	 * @return list<array{link_url: string, clicks: int}>
	 */
	public function top_links_for_campaign( int $campaign_id, int $limit = 10 ): array {
		global $wpdb;
		if ( $campaign_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT link_url, COUNT(*) AS clicks
				FROM %i
				WHERE campaign_id = %d
					AND event_type = %s
					AND link_url IS NOT NULL
					AND link_url != %s
				GROUP BY link_url
				ORDER BY clicks DESC
				LIMIT %d',
				$this->table(),
				$campaign_id,
				'email.clicked',
				'',
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'link_url' => (string) ( $row['link_url'] ?? '' ),
				'clicks'   => (int) ( $row['clicks'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Recent delivery events for a subscriber (newest first).
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $limit         Max rows.
	 * @return list<object>
	 */
	public function find_recent_for_subscriber( int $subscriber_id, int $limit = 20 ): array {
		global $wpdb;
		if ( $subscriber_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE subscriber_id = %d ORDER BY id DESC LIMIT %d',
				$this->table(),
				$subscriber_id,
				$limit
			)
		);
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
		foreach ( array_keys( $data ) as $column ) {
			if ( 'subscriber_id' === $column || 'campaign_id' === $column ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
