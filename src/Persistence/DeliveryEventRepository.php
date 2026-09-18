<?php
/**
 * Delivery event repository.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Persistence;

use WpResendNewsletter\Database\DeliveryEventsTable;
use WpResendNewsletter\Database\SubscribersTable;

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
	 * Unique subscribers who triggered an event for a campaign (skip subscriber_id=0).
	 *
	 * Created_at / last_at are GMT (current_time mysql true).
	 * When $link_url is set and $event_type is email.clicked, filter by exact link_url
	 * (events_count / last_at are for that link only; no link_urls aggregation).
	 *
	 * @param int         $campaign_id Campaign ID.
	 * @param string      $event_type  Event type (e.g. email.opened, email.clicked).
	 * @param string|null $link_url    Optional exact link URL filter for clicks.
	 * @return list<array{subscriber_id: int, email: string, events_count: int, last_at: string, link_urls?: list<string>}>
	 */
	public function find_unique_subscribers_for_campaign_event( int $campaign_id, string $event_type, ?string $link_url = null ): array {
		global $wpdb;
		if ( $campaign_id <= 0 || '' === $event_type ) {
			return array();
		}

		$events_table  = $this->table();
		$subs_table    = SubscribersTable::get_table_name();
		$filter_link   = ( null !== $link_url && '' !== $link_url && 'email.clicked' === $event_type );
		$include_links = ( 'email.clicked' === $event_type && ! $filter_link );

		if ( $filter_link ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT e.subscriber_id,
						s.email,
						COUNT(*) AS events_count,
						MAX(e.created_at) AS last_at
					FROM %i e
					INNER JOIN %i s ON s.id = e.subscriber_id
					WHERE e.campaign_id = %d
						AND e.event_type = %s
						AND e.subscriber_id > 0
						AND e.link_url = %s
					GROUP BY e.subscriber_id, s.email
					ORDER BY last_at DESC',
					$events_table,
					$subs_table,
					$campaign_id,
					$event_type,
					$link_url
				),
				ARRAY_A
			);
		} elseif ( $include_links ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.subscriber_id,
						s.email,
						COUNT(*) AS events_count,
						MAX(e.created_at) AS last_at,
						GROUP_CONCAT(DISTINCT NULLIF(e.link_url, '') ORDER BY e.link_url SEPARATOR 0x1e) AS link_urls_raw
					FROM %i e
					INNER JOIN %i s ON s.id = e.subscriber_id
					WHERE e.campaign_id = %d
						AND e.event_type = %s
						AND e.subscriber_id > 0
					GROUP BY e.subscriber_id, s.email
					ORDER BY last_at DESC",
					$events_table,
					$subs_table,
					$campaign_id,
					$event_type
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT e.subscriber_id,
						s.email,
						COUNT(*) AS events_count,
						MAX(e.created_at) AS last_at
					FROM %i e
					INNER JOIN %i s ON s.id = e.subscriber_id
					WHERE e.campaign_id = %d
						AND e.event_type = %s
						AND e.subscriber_id > 0
					GROUP BY e.subscriber_id, s.email
					ORDER BY last_at DESC',
					$events_table,
					$subs_table,
					$campaign_id,
					$event_type
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$item = array(
				'subscriber_id' => (int) ( $row['subscriber_id'] ?? 0 ),
				'email'         => (string) ( $row['email'] ?? '' ),
				'events_count'  => (int) ( $row['events_count'] ?? 0 ),
				'last_at'       => (string) ( $row['last_at'] ?? '' ),
			);
			if ( $include_links ) {
				$raw  = (string) ( $row['link_urls_raw'] ?? '' );
				$urls = array();
				if ( '' !== $raw ) {
					foreach ( explode( "\x1e", $raw ) as $url ) {
						$url = trim( $url );
						if ( '' !== $url ) {
							$urls[] = $url;
						}
					}
				}
				$item['link_urls'] = $urls;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Clickers grouped by distinct link_url for a campaign.
	 *
	 * Only links with ≥1 subscriber-matched click (subscriber_id > 0). Ordered by
	 * total clicks DESC. Each subscriber row is scoped to that link_url.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return list<array{link_url: string, clicks: int, subscribers: list<array{subscriber_id: int, email: string, events_count: int, last_at: string}>}>
	 */
	public function find_clickers_grouped_by_link( int $campaign_id ): array {
		global $wpdb;
		if ( $campaign_id <= 0 ) {
			return array();
		}

		$events_table = $this->table();
		$subs_table   = SubscribersTable::get_table_name();

		// One query: per (link_url, subscriber) aggregates; group in PHP by link.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.link_url,
					e.subscriber_id,
					s.email,
					COUNT(*) AS events_count,
					MAX(e.created_at) AS last_at
				FROM %i e
				INNER JOIN %i s ON s.id = e.subscriber_id
				WHERE e.campaign_id = %d
					AND e.event_type = %s
					AND e.subscriber_id > 0
					AND e.link_url IS NOT NULL
					AND e.link_url != %s
				GROUP BY e.link_url, e.subscriber_id, s.email
				ORDER BY e.link_url ASC, last_at DESC',
				$events_table,
				$subs_table,
				$campaign_id,
				'email.clicked',
				''
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		$by_url = array();
		foreach ( $rows as $row ) {
			$url = (string) ( $row['link_url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			if ( ! isset( $by_url[ $url ] ) ) {
				$by_url[ $url ] = array(
					'link_url'    => $url,
					'clicks'      => 0,
					'subscribers' => array(),
				);
			}
			$events_count                    = (int) ( $row['events_count'] ?? 0 );
			$by_url[ $url ]['clicks']       += $events_count;
			$by_url[ $url ]['subscribers'][] = array(
				'subscriber_id' => (int) ( $row['subscriber_id'] ?? 0 ),
				'email'         => (string) ( $row['email'] ?? '' ),
				'events_count'  => $events_count,
				'last_at'       => (string) ( $row['last_at'] ?? '' ),
			);
		}

		$out = array_values( $by_url );
		usort(
			$out,
			static function ( array $a, array $b ): int {
				$cmp = $b['clicks'] <=> $a['clicks'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strcmp( $a['link_url'], $b['link_url'] );
			}
		);
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
