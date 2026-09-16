<?php
/**
 * Process send_jobs: segment sync then Resend Broadcast create+send.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SendJobType;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Infrastructure\ResendResult;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron/worker: claim one job and process it.
 *
 * Hard rule: uses send_broadcast only — never transactional batch for campaigns.
 */
class BroadcastSender {

	/**
	 * WP-Cron hook name.
	 */
	public const CRON_HOOK = 'wprn_process_send_queue';

	/**
	 * Max attempts before permanent failure.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Jobs to process per cron tick.
	 */
	public const JOBS_PER_TICK = 5;

	/**
	 * Job repository.
	 *
	 * @var SendJobRepository
	 */
	private SendJobRepository $jobs;

	/**
	 * Campaign repository.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $campaigns;

	/**
	 * Segment sync.
	 *
	 * @var SegmentSyncService
	 */
	private SegmentSyncService $sync;

	/**
	 * Resend client.
	 *
	 * @var ResendClient
	 */
	private ResendClient $client;

	/**
	 * Constructor.
	 *
	 * @param SendJobRepository  $jobs      Jobs.
	 * @param CampaignRepository $campaigns Campaigns.
	 * @param SegmentSyncService $sync      Sync.
	 * @param ResendClient       $client    Resend.
	 */
	public function __construct(
		SendJobRepository $jobs,
		CampaignRepository $campaigns,
		SegmentSyncService $sync,
		ResendClient $client
	) {
		$this->jobs      = $jobs;
		$this->campaigns = $campaigns;
		$this->sync      = $sync;
		$this->client    = $client;
	}

	/**
	 * Build sender with WP defaults.
	 *
	 * @param ResendClient|null $client Optional injectable client (tests).
	 * @return self
	 */
	public static function from_wp( ?ResendClient $client = null ): self {
		$client    = $client ?? ResendClient::from_wp();
		$subs      = new \WpResendNewsletter\Persistence\SubscriberRepository();
		$campaigns = new CampaignRepository();
		return new self(
			new SendJobRepository(),
			$campaigns,
			new SegmentSyncService( $client, $subs, $campaigns ),
			$client
		);
	}

	/**
	 * Claim and process the next due job.
	 *
	 * @return array{ok: bool, processed: bool, code?: string, message: string, job_id?: int}
	 */
	public function process_next(): array {
		$job = $this->jobs->claim_next();
		if ( null === $job ) {
			return array(
				'ok'        => true,
				'processed' => false,
				'message'   => __( 'No pending send jobs.', 'wp-resend-newsletter' ),
			);
		}

		$job_id      = (int) $job->id;
		$campaign_id = (int) $job->campaign_id;
		$job_type    = (string) $job->job_type;
		$attempts    = (int) $job->attempts;

		if ( SendJobType::SEND_BROADCAST === $job_type ) {
			$gate = $this->assert_sync_complete( $campaign_id );
			if ( null !== $gate ) {
				if ( 'sync_incomplete' === $gate['code'] ) {
					// Put back without consuming a failure budget (sync still running).
					$this->jobs->update(
						$job_id,
						array(
							'status'          => SendJobStatus::PENDING,
							'attempts'        => max( 0, $attempts - 1 ),
							'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 30 ),
							'last_error'      => $gate['message'],
						)
					);
				} else {
					$this->requeue_or_fail( $job_id, $campaign_id, $attempts, $gate['message'], $gate['retryable'] ?? false, false );
				}
				return array(
					'ok'        => false,
					'processed' => true,
					'job_id'    => $job_id,
					'code'      => $gate['code'],
					'message'   => $gate['message'],
				);
			}
		}

		if ( SendJobType::SYNC_SEGMENT === $job_type ) {
			$result = $this->sync->sync_for_campaign( $campaign_id );
			if ( $result['ok'] ) {
				$this->jobs->update(
					$job_id,
					array(
						'status'     => SendJobStatus::SENT,
						'last_error' => null,
					)
				);
				return array(
					'ok'        => true,
					'processed' => true,
					'job_id'    => $job_id,
					'message'   => (string) $result['message'],
				);
			}

			$retryable = ! empty( $result['retryable'] ) || (
				isset( $result['code'] ) && ResendClient::is_retryable_result(
					ResendResult::failure( (string) $result['code'], (string) $result['message'] )
				)
			);

			$this->requeue_or_fail(
				$job_id,
				$campaign_id,
				$attempts,
				(string) $result['message'],
				$retryable,
				true
			);

			return array(
				'ok'        => false,
				'processed' => true,
				'job_id'    => $job_id,
				'code'      => (string) ( $result['code'] ?? 'sync_failed' ),
				'message'   => (string) $result['message'],
			);
		}

		if ( SendJobType::SEND_BROADCAST === $job_type ) {
			return $this->process_broadcast_job( $job_id, $campaign_id, $attempts );
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'     => SendJobStatus::FAILED,
				'last_error' => sprintf(
					/* translators: %s: job type slug */
					__( 'Unknown job_type: %s', 'wp-resend-newsletter' ),
					$job_type
				),
			)
		);
		$this->fail_campaign( $campaign_id, __( 'Unknown send job type.', 'wp-resend-newsletter' ) );

		return array(
			'ok'        => false,
			'processed' => true,
			'job_id'    => $job_id,
			'code'      => 'unknown_job_type',
			'message'   => __( 'Unknown send job type.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Process several jobs (cron tick).
	 *
	 * @param int $max Max jobs.
	 * @return int Number processed.
	 */
	public function process_queue( int $max = self::JOBS_PER_TICK ): int {
		$processed = 0;
		$max       = max( 1, min( 20, $max ) );
		for ( $i = 0; $i < $max; $i++ ) {
			$result = $this->process_next();
			if ( empty( $result['processed'] ) ) {
				break;
			}
			++$processed;
		}
		return $processed;
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function handle_cron(): void {
		self::from_wp()->process_queue();
	}

	/**
	 * Schedule WP-Cron if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wprn_every_minute', self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled cron.
	 *
	 * @return void
	 */
	public static function clear_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Register custom cron interval (every minute).
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['wprn_every_minute'] ) ) {
			$schedules['wprn_every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (WP Resend Newsletter)', 'wp-resend-newsletter' ),
			);
		}
		return $schedules;
	}

	/**
	 * Run Broadcast create+send for a claimed job.
	 *
	 * @param int $job_id      Job ID.
	 * @param int $campaign_id Campaign ID.
	 * @param int $attempts    Attempt count after claim.
	 * @return array{ok: bool, processed: bool, code?: string, message: string, job_id?: int}
	 */
	private function process_broadcast_job( int $job_id, int $campaign_id, int $attempts ): array {
		$campaign = $this->campaigns->find_by_id( $campaign_id );
		if ( null === $campaign ) {
			$this->jobs->update(
				$job_id,
				array(
					'status'     => SendJobStatus::FAILED,
					'last_error' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
				)
			);
			return array(
				'ok'        => false,
				'processed' => true,
				'job_id'    => $job_id,
				'code'      => 'not_found',
				'message'   => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		$settings   = self::get_settings();
		$tag_ids    = CampaignRepository::decode_filter_tag_ids( $campaign->filter_tag_ids ?? null );
		$segment_id = '';
		if ( array() !== $tag_ids ) {
			$segment_id = isset( $campaign->audience_segment_id ) ? trim( (string) $campaign->audience_segment_id ) : '';
		}
		if ( '' === $segment_id ) {
			$segment_id = isset( $settings['segment_id'] ) ? trim( (string) $settings['segment_id'] ) : '';
		}
		$from = self::build_from_address( $settings );

		if ( '' === $segment_id ) {
			$missing_msg = array() !== $tag_ids
				? __( 'Missing campaign audience_segment_id after filtered sync.', 'wp-resend-newsletter' )
				: __( 'Missing segment_id in settings after sync.', 'wp-resend-newsletter' );
			$this->requeue_or_fail(
				$job_id,
				$campaign_id,
				$attempts,
				$missing_msg,
				false,
				true
			);
			return array(
				'ok'        => false,
				'processed' => true,
				'job_id'    => $job_id,
				'code'      => 'missing_segment_id',
				'message'   => $missing_msg,
			);
		}

		if ( '' === $from ) {
			$this->requeue_or_fail(
				$job_id,
				$campaign_id,
				$attempts,
				__( 'From email is not configured.', 'wp-resend-newsletter' ),
				false,
				true
			);
			return array(
				'ok'        => false,
				'processed' => true,
				'job_id'    => $job_id,
				'code'      => 'missing_from',
				'message'   => __( 'From email is not configured.', 'wp-resend-newsletter' ),
			);
		}

		// Campaign path: Broadcasts only — never transactional batch.
		$result = $this->client->send_broadcast(
			array(
				'segment_id' => $segment_id,
				'from'       => $from,
				'subject'    => (string) $campaign->subject,
				'html'       => (string) $campaign->body_html,
				'text'       => (string) $campaign->body_text,
				'send'       => true,
			)
		);

		if ( ! $result->is_success() ) {
			$this->requeue_or_fail(
				$job_id,
				$campaign_id,
				$attempts,
				$result->error_message(),
				ResendClient::is_retryable_result( $result ),
				true
			);
			return array(
				'ok'        => false,
				'processed' => true,
				'job_id'    => $job_id,
				'code'      => $result->error_code(),
				'message'   => $result->error_message(),
			);
		}

		$broadcast_id = '';
		$data         = $result->data();
		if ( is_array( $data ) && isset( $data['id'] ) ) {
			$broadcast_id = (string) $data['id'];
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'                => SendJobStatus::SENT,
				'provider_broadcast_id' => '' !== $broadcast_id ? $broadcast_id : null,
				'last_error'            => null,
			)
		);

		$campaign_patch = array( 'status' => CampaignStatus::SENT );
		if ( '' !== $broadcast_id ) {
			$campaign_patch['resend_broadcast_id'] = $broadcast_id;
		}
		$this->campaigns->update( $campaign_id, $campaign_patch );

		/**
		 * After Broadcast was created and sent.
		 *
		 * @param int    $campaign_id  Campaign ID.
		 * @param string $broadcast_id Provider broadcast id.
		 */
		do_action( 'wprn_campaign_broadcast_sent', $campaign_id, $broadcast_id );

		return array(
			'ok'        => true,
			'processed' => true,
			'job_id'    => $job_id,
			'message'   => __( 'Broadcast sent.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Ensure sync_segment job for campaign completed successfully.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{code: string, message: string, retryable?: bool}|null
	 */
	private function assert_sync_complete( int $campaign_id ): ?array {
		$jobs = $this->jobs->find_by_campaign( $campaign_id );
		// Use the latest sync_segment job only — an older failed attempt must not
		// block Broadcast after a successful re-enqueue + sync.
		$sync = null;
		foreach ( $jobs as $job ) {
			if ( SendJobType::SYNC_SEGMENT === (string) $job->job_type ) {
				$sync = $job;
			}
		}

		if ( null === $sync ) {
			return array(
				'code'      => 'sync_missing',
				'message'   => __( 'Missing sync_segment job for campaign.', 'wp-resend-newsletter' ),
				'retryable' => false,
			);
		}

		$status = (string) $sync->status;
		if ( SendJobStatus::SENT === $status ) {
			return null;
		}
		if ( SendJobStatus::FAILED === $status ) {
			return array(
				'code'      => 'sync_failed',
				'message'   => __( 'Segment sync failed; Broadcast will not send.', 'wp-resend-newsletter' ),
				'retryable' => false,
			);
		}

		// Still pending/processing — put broadcast back for later.
		return array(
			'code'      => 'sync_incomplete',
			'message'   => __( 'Waiting for segment sync to finish.', 'wp-resend-newsletter' ),
			'retryable' => true,
		);
	}

	/**
	 * Requeue with backoff or mark failed (and campaign) after max attempts.
	 *
	 * @param int    $job_id           Job ID.
	 * @param int    $campaign_id      Campaign ID.
	 * @param int    $attempts         Attempts after claim.
	 * @param string $error            Error message.
	 * @param bool   $retryable        Whether to retry.
	 * @param bool   $fail_siblings    Cancel sibling pending jobs on permanent fail.
	 * @return void
	 */
	private function requeue_or_fail(
		int $job_id,
		int $campaign_id,
		int $attempts,
		string $error,
		bool $retryable,
		bool $fail_siblings
	): void {
		if ( $retryable && $attempts < self::MAX_ATTEMPTS ) {
			$delay = $this->backoff_seconds( $attempts );
			$next  = gmdate( 'Y-m-d H:i:s', time() + $delay );
			$this->jobs->update(
				$job_id,
				array(
					'status'          => SendJobStatus::PENDING,
					'next_attempt_at' => $next,
					'last_error'      => $error,
				)
			);
			return;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'     => SendJobStatus::FAILED,
				'last_error' => $error,
			)
		);

		if ( $fail_siblings ) {
			foreach ( $this->jobs->find_by_campaign( $campaign_id ) as $sibling ) {
				if ( (int) $sibling->id === $job_id ) {
					continue;
				}
				if ( SendJobStatus::PENDING === (string) $sibling->status ) {
					$this->jobs->update(
						(int) $sibling->id,
						array(
							'status'     => SendJobStatus::FAILED,
							'last_error' => __( 'Cancelled because a sibling job failed.', 'wp-resend-newsletter' ),
						)
					);
				}
			}
		}

		$this->fail_campaign( $campaign_id, $error );
	}

	/**
	 * Record permanent send failure for admin (jobs already failed).
	 *
	 * CampaignStatus has no "failed" value in MVP — reset to ready (not stuck in
	 * sending), and persist the error option for area 05 UI.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $error       Error.
	 * @return void
	 */
	private function fail_campaign( int $campaign_id, string $error ): void {
		// MVP has no CampaignStatus::FAILED — return to ready so admin can fix/retry
		// and the campaign is not left silently stuck in `sending`.
		$this->campaigns->update(
			$campaign_id,
			array( 'status' => CampaignStatus::READY )
		);

		update_option(
			'wprn_campaign_send_error_' . $campaign_id,
			array(
				'message'   => $error,
				'failed_at' => current_time( 'mysql', true ),
			),
			false
		);

		/**
		 * Campaign send permanently failed (jobs marked failed; status back to ready).
		 *
		 * @param int    $campaign_id Campaign ID.
		 * @param string $error       Error message.
		 */
		do_action( 'wprn_campaign_send_failed', $campaign_id, $error );
	}

	/**
	 * Exponential backoff seconds.
	 *
	 * @param int $attempts Attempt number (>=1).
	 * @return int
	 */
	private function backoff_seconds( int $attempts ): int {
		$attempts = max( 1, $attempts );
		return min( 3600, 60 * ( 2 ** ( $attempts - 1 ) ) );
	}


	/**
	 * Plugin settings array.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_settings(): array {
		$option = get_option( ResendClient::SETTINGS_OPTION, array() );
		return is_array( $option ) ? $option : array();
	}

	/**
	 * Build From header from settings (name + email on verified domain preferred).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return string
	 */
	private static function build_from_address( array $settings ): string {
		$email = isset( $settings['from_email'] ) ? trim( (string) $settings['from_email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}

		$name = isset( $settings['from_name'] ) ? trim( (string) $settings['from_name'] ) : '';
		if ( '' === $name ) {
			return $email;
		}

		// "Name <email@domain>".
		return sprintf( '%s <%s>', $name, $email );
	}
}
