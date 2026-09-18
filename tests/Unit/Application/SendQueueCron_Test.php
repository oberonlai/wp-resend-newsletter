<?php
/**
 * Unit tests: ensure send-queue WP-Cron is re-scheduled.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Application\BroadcastSender;
use WpResendNewsletter\Application\QueueService;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;

/**
 * @covers \WpResendNewsletter\Application\BroadcastSender::schedule_cron
 * @covers \WpResendNewsletter\Application\QueueService::enqueue_campaign
 */
class SendQueueCron_Test extends TestCase {

	/**
	 * Reset cron stubs between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wprn_test_cron'] = array(
			'next'      => false,
			'scheduled' => array(),
			'can'       => true,
		);
	}

	/**
	 * Scenario: schedule_cron schedules when no event exists.
	 */
	public function test_schedule_cron_schedules_when_missing(): void {
		$GLOBALS['wprn_test_cron']['next'] = false;

		BroadcastSender::schedule_cron();

		$this->assertCount( 1, $GLOBALS['wprn_test_cron']['scheduled'] );
		$event = $GLOBALS['wprn_test_cron']['scheduled'][0];
		$this->assertSame( 'wprn_every_minute', $event['recurrence'] );
		$this->assertSame( BroadcastSender::CRON_HOOK, $event['hook'] );
	}

	/**
	 * Scenario: schedule_cron is idempotent when already scheduled.
	 */
	public function test_schedule_cron_skips_when_already_scheduled(): void {
		$GLOBALS['wprn_test_cron']['next'] = time() + 30;

		BroadcastSender::schedule_cron();

		$this->assertSame( array(), $GLOBALS['wprn_test_cron']['scheduled'] );
	}

	/**
	 * Scenario: successful enqueue re-ensures cron even if wiped.
	 */
	public function test_enqueue_success_calls_schedule_cron(): void {
		$GLOBALS['wprn_test_cron']['next'] = false;

		$campaign = (object) array(
			'id'             => 134,
			'status'         => CampaignStatus::READY,
			'filter_tag_ids' => null,
		);

		$campaigns = new class( $campaign ) extends CampaignRepository {
			/** @var object */
			public object $campaign;

			public function __construct( object $campaign ) {
				$this->campaign = $campaign;
			}

			public function find_by_id( int $id ): ?object {
				return (int) $this->campaign->id === $id ? $this->campaign : null;
			}

			public function update( int $id, array $data ): bool {
				foreach ( $data as $key => $value ) {
					$this->campaign->{$key} = $value;
				}
				return true;
			}
		};

		$jobs = new class() extends SendJobRepository {
			/** @var int */
			private int $next_id = 1;

			public function find_by_campaign( int $campaign_id ): array {
				unset( $campaign_id );
				return array();
			}

			/**
			 * @param array<string, mixed> $data Row.
			 * @return int|false
			 */
			public function insert( array $data ): int|false {
				unset( $data );
				$id = $this->next_id;
				++$this->next_id;
				return $id;
			}

			public function update( int $id, array $data ): bool {
				unset( $id, $data );
				return true;
			}
		};

		$subscribers = new class() extends SubscriberRepository {
			public function count_confirmed_with_all_tags( array $tag_ids ): int {
				unset( $tag_ids );
				return 3;
			}
		};

		$service = new QueueService( $campaigns, $jobs, $subscribers );
		$result  = $service->enqueue_campaign( 134 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 1, 2 ), $result['job_ids'] );
		$this->assertCount( 1, $GLOBALS['wprn_test_cron']['scheduled'] );
		$this->assertSame( BroadcastSender::CRON_HOOK, $GLOBALS['wprn_test_cron']['scheduled'][0]['hook'] );
		$this->assertSame( CampaignStatus::SENDING, $campaign->status );
	}

	/**
	 * Bootstrap wires init → schedule_cron (deploy-safe re-ensure).
	 */
	public function test_bootstrap_registers_schedule_cron_on_init(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Bootstrap.php';
		$code = file_get_contents( $path );
		$this->assertIsString( $code );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'init',\s*array\(\s*BroadcastSender::class,\s*'schedule_cron'\s*\)/",
			$code
		);
	}
}
