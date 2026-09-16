<?php
/**
 * Enqueue campaign Broadcast pipeline jobs.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SendJobType;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates sync_segment → send_broadcast jobs for a ready campaign.
 *
 * Never calls transactional batch APIs — delivery is Broadcast-only.
 */
class QueueService {

	/**
	 * Capability required to enqueue sends.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Campaigns.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $campaigns;

	/**
	 * Send jobs.
	 *
	 * @var SendJobRepository
	 */
	private SendJobRepository $jobs;

	/**
	 * Subscribers.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository   $campaigns   Campaigns.
	 * @param SendJobRepository    $jobs        Jobs.
	 * @param SubscriberRepository $subscribers Subscribers.
	 */
	public function __construct(
		CampaignRepository $campaigns,
		SendJobRepository $jobs,
		SubscriberRepository $subscribers
	) {
		$this->campaigns   = $campaigns;
		$this->jobs        = $jobs;
		$this->subscribers = $subscribers;
	}

	/**
	 * Enqueue segment sync + Broadcast send for a ready campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{ok: bool, code?: string, message: string, job_ids?: list<int>}
	 */
	public function enqueue_campaign( int $campaign_id ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array(
				'ok'      => false,
				'code'    => 'forbidden',
				'message' => __( 'You do not have permission to send campaigns.', 'wp-resend-newsletter' ),
			);
		}

		$campaign = $this->campaigns->find_by_id( $campaign_id );
		if ( null === $campaign ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		if ( CampaignStatus::READY !== (string) $campaign->status ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_status',
				'message' => __( 'Only ready campaigns can be enqueued for send.', 'wp-resend-newsletter' ),
			);
		}

		$tag_ids   = CampaignRepository::decode_filter_tag_ids( $campaign->filter_tag_ids ?? null );
		$confirmed = $this->subscribers->count_confirmed_with_all_tags( $tag_ids );
		if ( $confirmed < 1 ) {
			$message = array() === $tag_ids
				? __( 'No confirmed subscribers to send to. Add and confirm subscribers before sending.', 'wp-resend-newsletter' )
				: __( 'No confirmed subscribers match the selected tags. Adjust the tag filter or assign tags before sending.', 'wp-resend-newsletter' );
			return array(
				'ok'      => false,
				'code'    => 'empty_audience',
				'message' => $message,
			);
		}

		// Snapshot filter_tag_ids at enqueue so mid-send tag edits do not change audience.
		// Always clear audience_segment_id so a filtered re-send cannot reuse a segment that
		// still contains leftover contacts from a prior filter (Broadcast would include them).
		$this->campaigns->update(
			$campaign_id,
			array(
				'filter_tag_ids'      => CampaignRepository::encode_filter_tag_ids( $tag_ids ),
				'audience_segment_id' => null,
			)
		);

		$existing = $this->jobs->find_by_campaign( $campaign_id );
		foreach ( $existing as $job ) {
			if ( in_array( (string) $job->status, array( SendJobStatus::PENDING, SendJobStatus::PROCESSING ), true ) ) {
				return array(
					'ok'      => false,
					'code'    => 'already_queued',
					'message' => __( 'This campaign already has an active send job.', 'wp-resend-newsletter' ),
				);
			}
		}

		$now = current_time( 'mysql', true );

		$sync_id = $this->jobs->insert(
			array(
				'campaign_id'     => $campaign_id,
				'job_type'        => SendJobType::SYNC_SEGMENT,
				'status'          => SendJobStatus::PENDING,
				'attempts'        => 0,
				'next_attempt_at' => $now,
			)
		);

		if ( false === $sync_id ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not create sync job.', 'wp-resend-newsletter' ),
			);
		}

		$broadcast_id = $this->jobs->insert(
			array(
				'campaign_id'     => $campaign_id,
				'job_type'        => SendJobType::SEND_BROADCAST,
				'status'          => SendJobStatus::PENDING,
				'attempts'        => 0,
				'next_attempt_at' => $now,
			)
		);

		if ( false === $broadcast_id ) {
			$this->jobs->update(
				$sync_id,
				array(
					'status'     => SendJobStatus::FAILED,
					'last_error' => __( 'Failed to create broadcast job after sync job.', 'wp-resend-newsletter' ),
				)
			);
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not create broadcast job.', 'wp-resend-newsletter' ),
			);
		}

		$updated = $this->campaigns->update(
			$campaign_id,
			array( 'status' => CampaignStatus::SENDING )
		);

		if ( ! $updated ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Jobs created but campaign status could not be updated.', 'wp-resend-newsletter' ),
			);
		}

		delete_option( 'wprn_campaign_send_error_' . $campaign_id );

		/**
		 * After campaign send jobs are enqueued (Broadcast path).
		 *
		 * @param int      $campaign_id Campaign ID.
		 * @param list<int> $job_ids     Job IDs (sync, broadcast).
		 */
		do_action( 'wprn_campaign_enqueued', $campaign_id, array( $sync_id, $broadcast_id ) );

		return array(
			'ok'      => true,
			'job_ids' => array( $sync_id, $broadcast_id ),
			'message' => __( 'Campaign queued for Broadcast send.', 'wp-resend-newsletter' ),
		);
	}
}
