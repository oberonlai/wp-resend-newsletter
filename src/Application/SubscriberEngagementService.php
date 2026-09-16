<?php
/**
 * Per-subscriber recent engagement events.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\DeliveryEventRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists recent delivery/engagement events for a subscriber.
 */
class SubscriberEngagementService {

	/**
	 * Delivery events repository.
	 *
	 * @var DeliveryEventRepository
	 */
	private DeliveryEventRepository $events;

	/**
	 * Campaign repository.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $campaigns;

	/**
	 * Constructor.
	 *
	 * @param DeliveryEventRepository|null $events    Optional events repo.
	 * @param CampaignRepository|null      $campaigns Optional campaigns repo.
	 */
	public function __construct(
		?DeliveryEventRepository $events = null,
		?CampaignRepository $campaigns = null
	) {
		$this->events    = $events ?? new DeliveryEventRepository();
		$this->campaigns = $campaigns ?? new CampaignRepository();
	}

	/**
	 * Recent events enriched with campaign subject when known.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $limit         Max rows.
	 * @return list<array{
	 *   id: int,
	 *   event_type: string,
	 *   campaign_id: int,
	 *   campaign_subject: string,
	 *   link_url: string,
	 *   created_at: string
	 * }>
	 */
	public function recent( int $subscriber_id, int $limit = 20 ): array {
		$rows = $this->events->find_recent_for_subscriber( $subscriber_id, $limit );
		if ( array() === $rows ) {
			return array();
		}

		$campaign_ids = array();
		foreach ( $rows as $row ) {
			$cid = (int) ( $row->campaign_id ?? 0 );
			if ( $cid > 0 ) {
				$campaign_ids[] = $cid;
			}
		}
		$campaigns = $this->campaigns->find_by_ids( $campaign_ids );

		$out = array();
		foreach ( $rows as $row ) {
			$campaign_id = (int) ( $row->campaign_id ?? 0 );
			$subject     = '';
			if ( $campaign_id > 0 && isset( $campaigns[ $campaign_id ] ) ) {
				$subject = (string) $campaigns[ $campaign_id ]->subject;
			}

			$out[] = array(
				'id'               => (int) ( $row->id ?? 0 ),
				'event_type'       => (string) ( $row->event_type ?? '' ),
				'campaign_id'      => $campaign_id,
				'campaign_subject' => $subject,
				'link_url'         => (string) ( $row->link_url ?? '' ),
				'created_at'       => (string) ( $row->created_at ?? '' ),
			);
		}

		return $out;
	}
}
