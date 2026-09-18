<?php
/**
 * Campaign engagement analytics (opens / clicks).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Persistence\DeliveryEventRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Summarizes unique + total open/click counts per campaign.
 *
 * Merges live Resend webhook events with optional Kit historical aggregates
 * stored in option `wprn_kit_campaign_stats` (keyed by campaign id).
 */
class CampaignAnalyticsService {

	/**
	 * Option name for Kit historical stats import.
	 */
	public const KIT_STATS_OPTION = 'wprn_kit_campaign_stats';

	/**
	 * Delivery events repository.
	 *
	 * @var DeliveryEventRepository
	 */
	private DeliveryEventRepository $events;

	/**
	 * Constructor.
	 *
	 * @param DeliveryEventRepository|null $events Optional repo.
	 */
	public function __construct( ?DeliveryEventRepository $events = null ) {
		$this->events = $events ?? new DeliveryEventRepository();
	}

	/**
	 * Summarize engagement for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{
	 *   opens_total: int,
	 *   opens_unique: int,
	 *   clicks_total: int,
	 *   clicks_unique: int,
	 *   top_links: list<array{link_url: string, clicks: int}>,
	 *   source: string,
	 *   openers: list<array{subscriber_id: int, email: string, events_count: int, last_at: string}>,
	 *   clickers: list<array{subscriber_id: int, email: string, events_count: int, last_at: string, link_urls?: list<string>}>,
	 *   clicks_by_link: list<array{link_url: string, clicks: int, subscribers: list<array{subscriber_id: int, email: string, events_count: int, last_at: string}>}>
	 * }
	 */
	public function summarize( int $campaign_id ): array {
		$opens  = $this->events->count_for_campaign_event( $campaign_id, 'email.opened' );
		$clicks = $this->events->count_for_campaign_event( $campaign_id, 'email.clicked' );
		$links  = $this->events->top_links_for_campaign( $campaign_id );

		$kit = self::kit_stats_for_campaign( $campaign_id );

		$opens_total   = $opens['total'];
		$opens_unique  = $opens['unique'];
		$clicks_total  = $clicks['total'];
		$clicks_unique = $clicks['unique'];
		$source        = 'resend';

		if ( null !== $kit ) {
			$kit_opens  = (int) ( $kit['emails_opened'] ?? 0 );
			$kit_clicks = (int) ( $kit['total_clicks'] ?? 0 );
			// Historical Kit aggregates have no per-subscriber rows; treat opened/clicked as unique.
			$opens_total   = max( $opens_total, $kit_opens );
			$opens_unique  = max( $opens_unique, $kit_opens );
			$clicks_total  = max( $clicks_total, $kit_clicks );
			$clicks_unique = max( $clicks_unique, $kit_clicks );
			$links         = self::merge_top_links( $links, $kit['links'] ?? array() );
			$source        = ( $opens['total'] > 0 || $clicks['total'] > 0 ) ? 'mixed' : 'kit';
		}

		$openers        = $this->events->find_unique_subscribers_for_campaign_event( $campaign_id, 'email.opened' );
		$clickers       = $this->events->find_unique_subscribers_for_campaign_event( $campaign_id, 'email.clicked' );
		$clicks_by_link = $this->events->find_clickers_grouped_by_link( $campaign_id );

		return array(
			'opens_total'    => $opens_total,
			'opens_unique'   => $opens_unique,
			'clicks_total'   => $clicks_total,
			'clicks_unique'  => $clicks_unique,
			'top_links'      => $links,
			'source'         => $source,
			'openers'        => $openers,
			'clickers'       => $clickers,
			'clicks_by_link' => $clicks_by_link,
		);
	}

	/**
	 * Kit historical stats for one campaign, if imported.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string, mixed>|null
	 */
	public static function kit_stats_for_campaign( int $campaign_id ): ?array {
		if ( $campaign_id <= 0 ) {
			return null;
		}
		$all = get_option( self::KIT_STATS_OPTION, array() );
		if ( ! is_array( $all ) ) {
			return null;
		}
		$key = (string) $campaign_id;
		if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return null;
		}
		return $all[ $key ];
	}

	/**
	 * Merge Resend top links with Kit link rows (sum clicks by URL).
	 *
	 * @param list<array{link_url: string, clicks: int}>   $primary Primary links.
	 * @param list<array{link_url?: string, clicks?: int}> $secondary Secondary links.
	 * @return list<array{link_url: string, clicks: int}>
	 */
	public static function merge_top_links( array $primary, array $secondary ): array {
		$by = array();
		foreach ( array_merge( $primary, $secondary ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = isset( $row['link_url'] ) ? (string) $row['link_url'] : '';
			if ( '' === $url ) {
				continue;
			}
			$clicks = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
			if ( ! isset( $by[ $url ] ) ) {
				$by[ $url ] = 0;
			}
			$by[ $url ] = max( $by[ $url ], $clicks );
		}
		$out = array();
		foreach ( $by as $url => $clicks ) {
			$out[] = array(
				'link_url' => $url,
				'clicks'   => $clicks,
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['clicks'] <=> $a['clicks'];
			}
		);
		return array_slice( $out, 0, 20 );
	}
}
