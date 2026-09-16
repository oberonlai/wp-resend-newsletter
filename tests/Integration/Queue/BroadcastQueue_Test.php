<?php
/**
 * Integration tests: enqueue + Broadcast sender (mocked Resend).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Queue;

use WP_UnitTestCase;
use WpResendNewsletter\Application\BroadcastSender;
use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Application\QueueService;
use WpResendNewsletter\Application\SegmentSyncService;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SendJobType;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;

/**
 * @covers \WpResendNewsletter\Application\QueueService
 * @covers \WpResendNewsletter\Application\BroadcastSender
 * @covers \WpResendNewsletter\Application\SegmentSyncService
 * @covers \WpResendNewsletter\Persistence\SendJobRepository
 * @covers \WpResendNewsletter\Database\SendJobsTable
 */
class BroadcastQueue_Test extends WP_UnitTestCase {

	/** @var list<array<string, mixed>> */
	private array $broadcast_calls = array();

	/** @var list<array{email: string, segment_id: string}> */
	private array $contact_calls = array();

	/** @var int */
	private int $batch_calls = 0;

	/** @var callable|null */
	private $broadcast_behavior = null;

	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		CampaignsTable::create_table();
		SendJobsTable::create_table();

		$this->broadcast_calls   = array();
		$this->contact_calls     = array();
		$this->batch_calls       = 0;
		$this->broadcast_behavior = null;

		update_option(
			'wprn_settings',
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'News',
				'api_key'        => 're_test_mock',
				'segment_id'     => '',
				'webhook_secret' => '',
			),
			false
		);
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( array( SendJobsTable::get_table_name(), CampaignsTable::get_table_name(), SubscribersTable::get_table_name() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		delete_option( 'wprn_settings' );
		parent::tear_down();
	}

	private function as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function mock_client(): ResendClient {
		$self = $this;
		return new ResendClient(
			're_test_mock',
			static function () use ( $self ) {
				++$self->batch_calls;
				return array();
			},
			function ( array $params ) use ( $self ) {
				$self->broadcast_calls[] = $params;
				if ( null !== $self->broadcast_behavior ) {
					return ( $self->broadcast_behavior )( $params );
				}
				return array( 'id' => 'bcast_happy' );
			},
			static function ( array $params ) {
				return array( 'id' => 'seg_created', 'name' => $params['name'] );
			},
			function ( string $email, string $segment_id ) use ( $self ) {
				$self->contact_calls[] = array(
					'email'      => $email,
					'segment_id' => $segment_id,
				);
				return array( 'id' => 'contact_' . md5( $email ), 'email' => $email );
			}
		);
	}

	private function sender( ?ResendClient $client = null ): BroadcastSender {
		$client    = $client ?? $this->mock_client();
		$subs      = new SubscriberRepository();
		$campaigns = new CampaignRepository();
		return new BroadcastSender(
			new SendJobRepository(),
			$campaigns,
			new SegmentSyncService( $client, $subs, $campaigns ),
			$client
		);
	}

	private function queue(): QueueService {
		return new QueueService(
			new CampaignRepository(),
			new SendJobRepository(),
			new SubscriberRepository()
		);
	}

	/**
	 * @param int $confirmed Confirmed count.
	 * @param int $pending   Pending count.
	 */
	private function seed_subscribers( int $confirmed, int $pending = 0 ): void {
		$repo = new SubscriberRepository();
		for ( $i = 0; $i < $confirmed; $i++ ) {
			$repo->insert(
				array(
					'email'  => "confirmed{$i}@example.com",
					'status' => SubscriberStatus::CONFIRMED,
				)
			);
		}
		for ( $i = 0; $i < $pending; $i++ ) {
			$repo->insert(
				array(
					'email'  => "pending{$i}@example.com",
					'status' => SubscriberStatus::PENDING,
				)
			);
		}
	}

	private function create_ready_campaign(): int {
		$svc = new CampaignService( new CampaignRepository() );
		$created = $svc->create(
			array(
				'subject'   => 'Broadcast me',
				'body_html' => '<p>Hello</p>',
				'body_text' => 'Hello',
			)
		);
		$this->assertTrue( $created['ok'] );
		$id = (int) $created['id'];
		$ready = $svc->mark_ready( $id );
		$this->assertTrue( $ready['ok'] );
		return $id;
	}

	/**
	 * Scenario: sync + broadcast happy path; never calls send_batch.
	 */
	public function test_sync_and_broadcast_happy_path(): void {
		$this->as_admin();
		$this->seed_subscribers( 3, 2 );
		$campaign_id = $this->create_ready_campaign();

		$enqueued = $this->queue()->enqueue_campaign( $campaign_id );
		$this->assertTrue( $enqueued['ok'], $enqueued['message'] ?? '' );
		$this->assertSame( CampaignStatus::SENDING, ( new CampaignRepository() )->find_by_id( $campaign_id )->status );

		$jobs = ( new SendJobRepository() )->find_by_campaign( $campaign_id );
		$this->assertCount( 2, $jobs );
		$this->assertSame( SendJobType::SYNC_SEGMENT, $jobs[0]->job_type );
		$this->assertSame( SendJobType::SEND_BROADCAST, $jobs[1]->job_type );

		$sender = $this->sender();
		$r1     = $sender->process_next();
		$this->assertTrue( $r1['ok'], $r1['message'] ?? '' );
		$this->assertSame( SendJobStatus::SENT, ( new SendJobRepository() )->find_by_id( (int) $jobs[0]->id )->status );

		$settings = get_option( 'wprn_settings' );
		$this->assertSame( 'seg_created', $settings['segment_id'] );
		$this->assertCount( 3, $this->contact_calls );
		$this->assertSame( 'confirmed0@example.com', $this->contact_calls[0]['email'] );

		$r2 = $sender->process_next();
		$this->assertTrue( $r2['ok'], $r2['message'] ?? '' );

		$campaign = ( new CampaignRepository() )->find_by_id( $campaign_id );
		$this->assertSame( CampaignStatus::SENT, $campaign->status );
		$this->assertSame( 'bcast_happy', $campaign->resend_broadcast_id );

		$bcast_job = ( new SendJobRepository() )->find_by_id( (int) $jobs[1]->id );
		$this->assertSame( SendJobStatus::SENT, $bcast_job->status );
		$this->assertSame( 'bcast_happy', $bcast_job->provider_broadcast_id );

		$this->assertCount( 1, $this->broadcast_calls );
		$this->assertTrue( $this->broadcast_calls[0]['send'] );
		$this->assertSame( 'seg_created', $this->broadcast_calls[0]['segment_id'] );
		$this->assertStringContainsString( '{{{RESEND_UNSUBSCRIBE_URL}}}', $this->broadcast_calls[0]['html'] );
		$this->assertSame( 0, $this->batch_calls, 'Campaign path must never call send_batch' );
	}

	/**
	 * Scenario: empty audience — clear error; no Broadcast; not stuck sending.
	 */
	public function test_empty_audience_fails_clearly(): void {
		$this->as_admin();
		$campaign_id = $this->create_ready_campaign();

		$result = $this->queue()->enqueue_campaign( $campaign_id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty_audience', $result['code'] );

		$campaign = ( new CampaignRepository() )->find_by_id( $campaign_id );
		$this->assertSame( CampaignStatus::READY, $campaign->status );
		$this->assertSame( array(), ( new SendJobRepository() )->find_by_campaign( $campaign_id ) );
		$this->assertSame( 0, $this->batch_calls );
		$this->assertCount( 0, $this->broadcast_calls );
	}

	/**
	 * Scenario: transient 429 retries then succeeds.
	 */
	public function test_retryable_broadcast_failure_then_success(): void {
		$this->as_admin();
		$this->seed_subscribers( 1 );
		update_option(
			'wprn_settings',
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => '',
				'api_key'        => 're_test_mock',
				'segment_id'     => 'seg_existing',
				'webhook_secret' => '',
			),
			false
		);

		$campaign_id = $this->create_ready_campaign();
		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );

		$tries = 0;
		$this->broadcast_behavior = function () use ( &$tries ) {
			++$tries;
			if ( 1 === $tries ) {
				throw new \RuntimeException( 'HTTP 429 rate limited' );
			}
			return array( 'id' => 'bcast_after_retry' );
		};

		$sender = $this->sender( $this->mock_client() );

		// Sync.
		$this->assertTrue( $sender->process_next()['ok'] );

		// First broadcast attempt — retryable failure.
		$fail = $sender->process_next();
		$this->assertFalse( $fail['ok'] );
		$this->assertSame( 'rate_limited', $fail['code'] );

		$jobs = ( new SendJobRepository() )->find_by_campaign( $campaign_id );
		$bcast = null;
		foreach ( $jobs as $job ) {
			if ( SendJobType::SEND_BROADCAST === $job->job_type ) {
				$bcast = $job;
			}
		}
		$this->assertNotNull( $bcast );
		$this->assertSame( SendJobStatus::PENDING, $bcast->status );
		$this->assertGreaterThanOrEqual( 1, (int) $bcast->attempts );

		// Make job due now.
		( new SendJobRepository() )->update(
			(int) $bcast->id,
			array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) )
		);

		$ok = $sender->process_next();
		$this->assertTrue( $ok['ok'], $ok['message'] ?? '' );
		$this->assertSame( CampaignStatus::SENT, ( new CampaignRepository() )->find_by_id( $campaign_id )->status );
		$this->assertSame( 0, $this->batch_calls );
	}

	/**
	 * Scenario: concurrent claim — only one worker gets the job.
	 */
	public function test_double_claim_only_one_wins(): void {
		$this->as_admin();
		$this->seed_subscribers( 1 );
		$campaign_id = $this->create_ready_campaign();
		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );

		$repo = new SendJobRepository();
		$first  = $repo->claim_next();
		$second = $repo->claim_next();

		$this->assertNotNull( $first );
		$this->assertSame( SendJobType::SYNC_SEGMENT, $first->job_type );
		$this->assertSame( SendJobStatus::PROCESSING, $first->status );

		// Second claim should get broadcast job (sync is processing), or null if we only want one at a time.
		// Spec: same broadcast job claimed once. Claim broadcast separately:
		if ( null !== $second ) {
			$this->assertNotSame( (int) $first->id, (int) $second->id );
			$this->assertSame( SendJobStatus::PROCESSING, $second->status );
		}

		// Re-claim the already-processing sync job must fail.
		global $wpdb;
		$table = SendJobsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE id = %d AND status = %s',
				$table,
				SendJobStatus::PROCESSING,
				(int) $first->id,
				SendJobStatus::PENDING
			)
		);
		$this->assertSame( 0, (int) $claimed, 'Already-processing job must not be claimed twice' );
	}

	/**
	 * Max attempts marks job failed.
	 */
	public function test_max_attempts_marks_failed(): void {
		$this->as_admin();
		$this->seed_subscribers( 1 );
		update_option(
			'wprn_settings',
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => '',
				'api_key'        => 're_test_mock',
				'segment_id'     => 'seg_existing',
				'webhook_secret' => '',
			),
			false
		);

		$campaign_id = $this->create_ready_campaign();
		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );

		$this->broadcast_behavior = static function () {
			throw new \RuntimeException( 'HTTP 503 unavailable' );
		};

		$sender = $this->sender( $this->mock_client() );
		$this->assertTrue( $sender->process_next()['ok'] ); // sync

		$repo = new SendJobRepository();
		$jobs = $repo->find_by_campaign( $campaign_id );
		$bcast_id = 0;
		foreach ( $jobs as $job ) {
			if ( SendJobType::SEND_BROADCAST === $job->job_type ) {
				$bcast_id = (int) $job->id;
			}
		}

		for ( $i = 0; $i < BroadcastSender::MAX_ATTEMPTS; $i++ ) {
			$repo->update(
				$bcast_id,
				array(
					'status'          => SendJobStatus::PENDING,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ),
				)
			);
			$sender->process_next();
		}

		$final = $repo->find_by_id( $bcast_id );
		$this->assertSame( SendJobStatus::FAILED, $final->status );
		$this->assertNotEmpty( $final->last_error );
		$this->assertSame( 0, $this->batch_calls );

		// Spec: campaign must not stay silently stuck in `sending`.
		$campaign = ( new CampaignRepository() )->find_by_id( $campaign_id );
		$this->assertSame( CampaignStatus::READY, $campaign->status );
		$error = get_option( 'wprn_campaign_send_error_' . $campaign_id );
		$this->assertIsArray( $error );
		$this->assertNotEmpty( $error['message'] );
	}

	/**
	 * Application send path source must not call send_batch.
	 */
	public function test_campaign_send_classes_never_reference_send_batch(): void {
		$files = array(
			WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'src/Application/QueueService.php',
			WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'src/Application/BroadcastSender.php',
			WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'src/Application/SegmentSyncService.php',
		);
		foreach ( $files as $file ) {
			$code = file_get_contents( $file );
			$this->assertIsString( $code );
			$this->assertStringNotContainsString( 'send_batch', $code, basename( $file ) );
		}
	}

	/**
	 * Scenario: non-admin cannot enqueue.
	 */
	public function test_enqueue_requires_manage_options(): void {
		$this->as_admin();
		$this->seed_subscribers( 1 );
		$campaign_id = $this->create_ready_campaign();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->queue()->enqueue_campaign( $campaign_id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertSame( CampaignStatus::READY, ( new CampaignRepository() )->find_by_id( $campaign_id )->status );
		$this->assertSame( array(), ( new SendJobRepository() )->find_by_campaign( $campaign_id ) );
	}

	/**
	 * Scenario: second enqueue while jobs active is rejected.
	 */
	public function test_enqueue_rejects_already_queued(): void {
		$this->as_admin();
		$this->seed_subscribers( 1 );
		$campaign_id = $this->create_ready_campaign();

		$first = $this->queue()->enqueue_campaign( $campaign_id );
		$this->assertTrue( $first['ok'] );

		// Force status back to ready to isolate the already_queued guard.
		( new CampaignRepository() )->update( $campaign_id, array( 'status' => CampaignStatus::READY ) );

		$second = $this->queue()->enqueue_campaign( $campaign_id );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 'already_queued', $second['code'] );
		$this->assertCount( 2, ( new SendJobRepository() )->find_by_campaign( $campaign_id ) );
	}

	/**
	 * Cron hook constant matches Bootstrap registration.
	 */
	public function test_cron_hook_name(): void {
		$this->assertSame( 'wprn_process_send_queue', BroadcastSender::CRON_HOOK );
		$this->assertNotFalse( has_action( BroadcastSender::CRON_HOOK ) );
	}
}
