<?php
/**
 * Unit tests for SegmentSyncService campaign segment cleanup.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Application\SegmentSyncService;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;

/**
 * @covers \WpResendNewsletter\Application\SegmentSyncService
 */
class SegmentSyncService_Test extends TestCase {

	/**
	 * Before creating a campaign segment, delete matching WPRN campaign #N
	 * segments, skip General / other names, then create the new segment.
	 */
	public function test_ensure_campaign_segment_deletes_stale_wprn_segments_skips_general(): void {
		$deleted = array();
		$created = array();

		$client = new ResendClient(
			're_test_key',
			null,
			null,
			static function ( array $params ) use ( &$created ) {
				$created[] = $params['name'];
				return array(
					'id'   => 'seg_new_42',
					'name' => $params['name'],
				);
			},
			null,
			static function () {
				return array(
					'data' => array(
						array( 'id' => 'seg_general', 'name' => 'General' ),
						array( 'id' => 'seg_old_1', 'name' => 'WPRN campaign #1' ),
						array( 'id' => 'seg_keep_me', 'name' => 'WP Resend Newsletter' ),
						array( 'id' => 'seg_old_7', 'name' => 'WPRN campaign #7' ),
						array( 'id' => 'seg_almost', 'name' => 'WPRN campaign #x' ),
					),
				);
			},
			static function ( string $id ) use ( &$deleted ) {
				$deleted[] = $id;
				return array( 'id' => $id, 'deleted' => true );
			}
		);

		$campaign = (object) array(
			'id'                   => 42,
			'audience_segment_id'  => '',
			'filter_tag_ids'       => null,
		);

		$campaigns = new class( $campaign ) extends CampaignRepository {
			/** @var object */
			public object $campaign;
			/** @var list<array{0: int, 1: array<string, mixed>}> */
			public array $updates = array();

			public function __construct( object $campaign ) {
				$this->campaign = $campaign;
			}

			public function find_by_id( int $id ): ?object {
				return (int) $this->campaign->id === $id ? $this->campaign : null;
			}

			public function update( int $id, array $data ): bool {
				$this->updates[] = array( $id, $data );
				foreach ( $data as $key => $value ) {
					$this->campaign->{$key} = $value;
				}
				return true;
			}
		};

		$subscribers = new class() extends SubscriberRepository {
			// Unused by ensure_campaign_segment.
		};

		$service = new SegmentSyncService( $client, $subscribers, $campaigns );
		$result  = $service->ensure_campaign_segment( 42 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'seg_new_42', $result['segment_id'] );
		$this->assertSame( array( 'WPRN campaign #42' ), $created );
		$this->assertSame( array( 'seg_old_1', 'seg_old_7' ), $deleted );
		$this->assertSame( 'seg_new_42', $campaign->audience_segment_id );
	}

	/**
	 * Soft-handle delete failures: still attempt remaining deletes and create.
	 */
	public function test_ensure_campaign_segment_continues_after_delete_failure(): void {
		$deleted_attempts = array();
		$created          = array();

		$client = new ResendClient(
			're_test_key',
			null,
			null,
			static function ( array $params ) use ( &$created ) {
				$created[] = $params['name'];
				return array( 'id' => 'seg_ok', 'name' => $params['name'] );
			},
			null,
			static function () {
				return array(
					'data' => array(
						array( 'id' => 'seg_fail', 'name' => 'WPRN campaign #1' ),
						array( 'id' => 'seg_ok_del', 'name' => 'WPRN campaign #2' ),
					),
				);
			},
			static function ( string $id ) use ( &$deleted_attempts ) {
				$deleted_attempts[] = $id;
				if ( 'seg_fail' === $id ) {
					throw new \RuntimeException( 'boom' );
				}
				return array( 'id' => $id, 'deleted' => true );
			}
		);

		$campaign = (object) array(
			'id'                  => 99,
			'audience_segment_id' => '',
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

		$service = new SegmentSyncService( $client, new class() extends SubscriberRepository {}, $campaigns );
		$result  = $service->ensure_campaign_segment( 99 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'seg_fail', 'seg_ok_del' ), $deleted_attempts );
		$this->assertSame( array( 'WPRN campaign #99' ), $created );
	}

	/**
	 * When create still fails (e.g. plan limit), surface the clear Resend error.
	 */
	public function test_ensure_campaign_segment_surfaces_create_failure(): void {
		$client = new ResendClient(
			're_test_key',
			null,
			null,
			static function () {
				throw new \RuntimeException( 'You have reached the maximum number of segments for your plan.' );
			},
			null,
			static function () {
				return array( 'data' => array() );
			},
			static function ( string $id ) {
				return array( 'id' => $id );
			}
		);

		$campaign = (object) array(
			'id'                  => 5,
			'audience_segment_id' => '',
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
		};

		$service = new SegmentSyncService( $client, new class() extends SubscriberRepository {}, $campaigns );
		$result  = $service->ensure_campaign_segment( 5 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'resend_error', $result['code'] );
		$this->assertStringContainsString( 'maximum number of segments', $result['message'] );
	}
}
