<?php
/**
 * Integration tests: subscriber tags CRUD + filtered Broadcast audience (AND).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Tags;

use WP_UnitTestCase;
use WpResendNewsletter\Application\BroadcastSender;
use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Application\QueueService;
use WpResendNewsletter\Application\SegmentSyncService;
use WpResendNewsletter\Application\TagService;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Database\TagsTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

/**
 * @covers \WpResendNewsletter\Application\TagService
 * @covers \WpResendNewsletter\Application\SegmentSyncService
 * @covers \WpResendNewsletter\Application\QueueService
 * @covers \WpResendNewsletter\Application\BroadcastSender
 * @covers \WpResendNewsletter\Persistence\TagRepository
 * @covers \WpResendNewsletter\Persistence\SubscriberTagRepository
 * @covers \WpResendNewsletter\Database\TagsTable
 * @covers \WpResendNewsletter\Database\SubscriberTagTable
 */
class SubscriberTags_Test extends WP_UnitTestCase {

	/** @var list<array<string, mixed>> */
	private array $broadcast_calls = array();

	/** @var list<array{email: string, segment_id: string}> */
	private array $contact_calls = array();

	/** @var list<array{name: string}> */
	private array $segment_calls = array();

	/** @var int */
	private int $batch_calls = 0;

	/** @var int */
	private int $segment_seq = 0;

	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		CampaignsTable::create_table();
		SendJobsTable::create_table();
		TagsTable::create_table();
		SubscriberTagTable::create_table();

		$this->broadcast_calls = array();
		$this->contact_calls   = array();
		$this->segment_calls   = array();
		$this->batch_calls     = 0;
		$this->segment_seq     = 0;

		update_option(
			'wprn_settings',
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'News',
				'api_key'        => 're_test_mock',
				'segment_id'     => 'seg_global',
				'webhook_secret' => '',
			),
			false
		);
	}

	public function tear_down(): void {
		global $wpdb;
		foreach (
			array(
				SubscriberTagTable::get_table_name(),
				TagsTable::get_table_name(),
				SendJobsTable::get_table_name(),
				CampaignsTable::get_table_name(),
				SubscribersTable::get_table_name(),
			) as $table
		) {
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

	private function tag_service(): TagService {
		return new TagService(
			new TagRepository(),
			new SubscriberTagRepository(),
			new SubscriberRepository()
		);
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
				return array( 'id' => 'bcast_tagged' );
			},
			function ( array $params ) use ( $self ) {
				$self->segment_calls[] = $params;
				++$self->segment_seq;
				return array(
					'id'   => 'seg_campaign_' . $self->segment_seq,
					'name' => $params['name'],
				);
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

	private function create_ready_campaign(): int {
		$svc     = new CampaignService( new CampaignRepository() );
		$created = $svc->create(
			array(
				'subject'   => 'Tagged send',
				'body_html' => '<p>Hello {{{RESEND_UNSUBSCRIBE_URL}}}</p>',
				'body_text' => 'Hello',
			)
		);
		$this->assertTrue( $created['ok'] );
		$id = (int) $created['id'];
		$this->assertTrue( $svc->mark_ready( $id )['ok'] );
		return $id;
	}

	/**
	 * @return array{vip: int, product: int}
	 */
	private function create_vip_product_tags(): array {
		$svc = $this->tag_service();
		$a   = $svc->create( 'VIP' );
		$b   = $svc->create( 'Product' );
		$this->assertTrue( $a['ok'] );
		$this->assertTrue( $b['ok'] );
		return array(
			'vip'     => (int) $a['id'],
			'product' => (int) $b['id'],
		);
	}

	/**
	 * Seed A(VIP), B(VIP+Product), C(none), D(pending+VIP), E(Product only).
	 *
	 * @return array{A: int, B: int, C: int, D: int, E: int}
	 */
	private function seed_tagged_subscribers( int $vip, int $product ): array {
		$subs = new SubscriberRepository();
		$svc  = $this->tag_service();

		$a = (int) $subs->insert( array( 'email' => 'a@example.com', 'status' => SubscriberStatus::CONFIRMED ) );
		$b = (int) $subs->insert( array( 'email' => 'b@example.com', 'status' => SubscriberStatus::CONFIRMED ) );
		$c = (int) $subs->insert( array( 'email' => 'c@example.com', 'status' => SubscriberStatus::CONFIRMED ) );
		$d = (int) $subs->insert( array( 'email' => 'd@example.com', 'status' => SubscriberStatus::PENDING ) );
		$e = (int) $subs->insert( array( 'email' => 'e@example.com', 'status' => SubscriberStatus::CONFIRMED ) );

		$svc->assign( $a, $vip );
		$svc->assign( $b, $vip );
		$svc->assign( $b, $product );
		$svc->assign( $d, $vip );
		$svc->assign( $e, $product );

		return array(
			'A' => $a,
			'B' => $b,
			'C' => $c,
			'D' => $d,
			'E' => $e,
		);
	}

	/** T1: Create duplicate tag name fails. */
	public function test_create_duplicate_tag_name_fails(): void {
		$this->as_admin();
		$svc = $this->tag_service();
		$this->assertTrue( $svc->create( 'VIP' )['ok'] );
		$dup = $svc->create( 'VIP' );
		$this->assertFalse( $dup['ok'] );
		$this->assertSame( 'duplicate_name', $dup['code'] );
		$this->assertSame( 1, ( new TagRepository() )->count_all() );
	}

	/** T2: Assign same tag twice is idempotent. */
	public function test_assign_same_tag_twice_idempotent(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$subs = new SubscriberRepository();
		$id   = (int) $subs->insert(
			array(
				'email'  => 'alice@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$svc = $this->tag_service();
		$this->assertTrue( $svc->assign( $id, $tags['vip'] )['ok'] );
		$this->assertTrue( $svc->assign( $id, $tags['vip'] )['ok'] );

		$join = new SubscriberTagRepository();
		$this->assertTrue( $join->has( $id, $tags['vip'] ) );
		$this->assertCount( 1, $join->find_tags_for_subscriber( $id ) );
	}

	/** T3: Delete tag removes join rows; subscribers untouched. */
	public function test_delete_tag_removes_joins_keeps_subscribers(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$subs = new SubscriberRepository();
		$id   = (int) $subs->insert(
			array(
				'email'  => 'alice@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$svc = $this->tag_service();
		$svc->assign( $id, $tags['vip'] );
		$svc->assign( $id, $tags['product'] );

		$this->assertTrue( $svc->delete( $tags['vip'] )['ok'] );
		$this->assertNull( ( new TagRepository() )->find_by_id( $tags['vip'] ) );
		$this->assertFalse( ( new SubscriberTagRepository() )->has( $id, $tags['vip'] ) );
		$this->assertTrue( ( new SubscriberTagRepository() )->has( $id, $tags['product'] ) );
		$this->assertNotNull( $subs->find_by_id( $id ) );
	}

	/** T4 + T6: Filter one tag — only confirmed with that tag; pending excluded. */
	public function test_filter_one_tag_syncs_only_matching_confirmed(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$this->seed_tagged_subscribers( $tags['vip'], $tags['product'] );

		$campaign_id = $this->create_ready_campaign();
		( new CampaignRepository() )->update(
			$campaign_id,
			array( 'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids( array( $tags['vip'] ) ) )
		);

		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );
		$sender = $this->sender();
		$this->assertTrue( $sender->process_next()['ok'] ); // sync
		$this->assertTrue( $sender->process_next()['ok'] ); // broadcast

		$emails = array_column( $this->contact_calls, 'email' );
		sort( $emails );
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $emails );

		$campaign = ( new CampaignRepository() )->find_by_id( $campaign_id );
		$this->assertNotEmpty( $campaign->audience_segment_id );
		$this->assertStringStartsWith( 'seg_campaign_', (string) $campaign->audience_segment_id );

		foreach ( $this->contact_calls as $call ) {
			$this->assertSame( $campaign->audience_segment_id, $call['segment_id'] );
			$this->assertNotSame( 'seg_global', $call['segment_id'] );
		}

		$this->assertCount( 1, $this->broadcast_calls );
		$this->assertSame( $campaign->audience_segment_id, $this->broadcast_calls[0]['segment_id'] );
		$this->assertSame( 0, $this->batch_calls );
		$this->assertSame( CampaignStatus::SENT, $campaign->status );
	}

	/** T5: Filter two tags AND — only intersection. */
	public function test_filter_two_tags_and_intersection(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$this->seed_tagged_subscribers( $tags['vip'], $tags['product'] );

		$campaign_id = $this->create_ready_campaign();
		( new CampaignRepository() )->update(
			$campaign_id,
			array(
				'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids(
					array( $tags['vip'], $tags['product'] )
				),
			)
		);

		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );
		$sender = $this->sender();
		$this->assertTrue( $sender->process_next()['ok'] );
		$this->assertTrue( $sender->process_next()['ok'] );

		$this->assertCount( 1, $this->contact_calls );
		$this->assertSame( 'b@example.com', $this->contact_calls[0]['email'] );
		$this->assertSame( 0, $this->batch_calls );
	}

	/** T7: Empty filter — all confirmed → settings segment. */
	public function test_empty_filter_uses_settings_segment(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$this->seed_tagged_subscribers( $tags['vip'], $tags['product'] );

		$campaign_id = $this->create_ready_campaign();
		// Explicit empty filter.
		( new CampaignRepository() )->update(
			$campaign_id,
			array( 'filter_tag_ids' => '[]' )
		);

		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );
		$sender = $this->sender();
		$this->assertTrue( $sender->process_next()['ok'] );
		$this->assertTrue( $sender->process_next()['ok'] );

		$emails = array_column( $this->contact_calls, 'email' );
		sort( $emails );
		$this->assertSame(
			array( 'a@example.com', 'b@example.com', 'c@example.com', 'e@example.com' ),
			$emails
		);

		foreach ( $this->contact_calls as $call ) {
			$this->assertSame( 'seg_global', $call['segment_id'] );
		}
		$this->assertSame( 'seg_global', $this->broadcast_calls[0]['segment_id'] );
		$this->assertSame( array(), $this->segment_calls, 'Unfiltered path must not create campaign segment' );
		$this->assertSame( 0, $this->batch_calls );

		$campaign = ( new CampaignRepository() )->find_by_id( $campaign_id );
		$this->assertTrue(
			null === $campaign->audience_segment_id || '' === (string) $campaign->audience_segment_id
		);
	}

	/** T8: Empty filtered audience — no broadcast; not stuck sending. */
	public function test_empty_filtered_audience_errors_clearly(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		// Only pending tagged — no confirmed match.
		$subs = new SubscriberRepository();
		$id   = (int) $subs->insert(
			array(
				'email'  => 'pending@example.com',
				'status' => SubscriberStatus::PENDING,
			)
		);
		$this->tag_service()->assign( $id, $tags['vip'] );

		$campaign_id = $this->create_ready_campaign();
		( new CampaignRepository() )->update(
			$campaign_id,
			array( 'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids( array( $tags['vip'] ) ) )
		);

		$result = $this->queue()->enqueue_campaign( $campaign_id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty_audience', $result['code'] );
		$this->assertSame( CampaignStatus::READY, ( new CampaignRepository() )->find_by_id( $campaign_id )->status );
		$this->assertSame( array(), ( new SendJobRepository() )->find_by_campaign( $campaign_id ) );
		$this->assertCount( 0, $this->broadcast_calls );
		$this->assertSame( 0, $this->batch_calls );
	}

	/** T9: Non-admin cannot mutate tags. */
	public function test_non_admin_cannot_mutate_tags(): void {
		$this->as_admin();
		$created = $this->tag_service()->create( 'VIP' );
		$this->assertTrue( $created['ok'] );
		$tag_id = (int) $created['id'];

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$svc = $this->tag_service();
		$this->assertSame( 'forbidden', $svc->create( 'Hacker' )['code'] );
		$this->assertSame( 'forbidden', $svc->rename( $tag_id, 'X' )['code'] );
		$this->assertSame( 'forbidden', $svc->delete( $tag_id )['code'] );

		$subs = new SubscriberRepository();
		$sid  = (int) $subs->insert(
			array(
				'email'  => 'x@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$this->assertSame( 'forbidden', $svc->assign( $sid, $tag_id )['code'] );
		$this->assertFalse( ( new SubscriberTagRepository() )->has( $sid, $tag_id ) );
		$this->assertNotNull( ( new TagRepository() )->find_by_id( $tag_id ) );
	}

	/** Assign + remove tags on a subscriber. */
	public function test_assign_and_remove_tags(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$subs = new SubscriberRepository();
		$id   = (int) $subs->insert(
			array(
				'email'  => 'alice@example.com',
				'status' => SubscriberStatus::CONFIRMED,
			)
		);
		$svc = $this->tag_service();
		$svc->assign( $id, $tags['vip'] );
		$svc->assign( $id, $tags['product'] );
		$this->assertCount( 2, ( new SubscriberTagRepository() )->find_tags_for_subscriber( $id ) );

		$svc->remove( $id, $tags['product'] );
		$remaining = ( new SubscriberTagRepository() )->find_tags_for_subscriber( $id );
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'VIP', $remaining[0]->name );
	}

	/** Bulk assign is idempotent. */
	public function test_bulk_assign_tag(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$subs = new SubscriberRepository();
		$ids  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = (int) $subs->insert(
				array(
					'email'  => "bulk{$i}@example.com",
					'status' => SubscriberStatus::CONFIRMED,
				)
			);
		}
		$svc = $this->tag_service();
		$svc->assign( $ids[0], $tags['vip'] );

		$result = $svc->bulk_assign( $ids, $tags['vip'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 3, $result['assigned'] );
		foreach ( $ids as $id ) {
			$this->assertTrue( ( new SubscriberTagRepository() )->has( $id, $tags['vip'] ) );
		}
	}

	/** AND helper unit-style via repository. */
	public function test_and_intersection_helper(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$seed = $this->seed_tagged_subscribers( $tags['vip'], $tags['product'] );

		$repo = new SubscriberRepository();
		$one  = $repo->find_confirmed_ids_with_all_tags( array( $tags['vip'] ) );
		sort( $one );
		$this->assertSame( array( $seed['A'], $seed['B'] ), $one );

		$both = $repo->find_confirmed_ids_with_all_tags( array( $tags['vip'], $tags['product'] ) );
		$this->assertSame( array( $seed['B'] ), $both );

		$this->assertSame( 2, $repo->count_confirmed_with_all_tags( array( $tags['vip'] ) ) );
		$this->assertSame( 1, $repo->count_confirmed_with_all_tags( array( $tags['vip'], $tags['product'] ) ) );
	}

	/** Campaign path never references send_batch (source assertion). */
	public function test_tagged_send_never_calls_send_batch(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$this->seed_tagged_subscribers( $tags['vip'], $tags['product'] );
		$campaign_id = $this->create_ready_campaign();
		( new CampaignRepository() )->update(
			$campaign_id,
			array( 'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids( array( $tags['vip'] ) ) )
		);
		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );
		$sender = $this->sender();
		$sender->process_next();
		$sender->process_next();
		$this->assertSame( 0, $this->batch_calls );
	}

	/** Campaigns table has v1.1 columns after upgrade. */
	public function test_campaigns_table_has_filter_columns(): void {
		global $wpdb;
		$table = CampaignsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "SELECT filter_tag_ids, audience_segment_id FROM {$table} LIMIT 0" );
		$this->assertNull( $wpdb->last_error === '' ? null : $wpdb->last_error, (string) $wpdb->last_error );
		$this->assertSame( '1.1.1', CampaignsTable::VERSION );
		$this->assertTrue( TagsTable::table_exists() );
		$this->assertTrue( SubscriberTagTable::table_exists() );
	}

	/**
	 * Re-enqueue after a prior filtered sync must create a fresh campaign segment
	 * (no leftover contacts from the previous filter).
	 */
	public function test_reenqueue_clears_audience_segment_for_fresh_filter(): void {
		$this->as_admin();
		$tags = $this->create_vip_product_tags();
		$this->seed_tagged_subscribers( $tags['vip'], $tags['product'] );
		$campaign_id = $this->create_ready_campaign();
		$campaigns   = new CampaignRepository();
		$jobs        = new SendJobRepository();

		$campaigns->update(
			$campaign_id,
			array( 'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids( array( $tags['vip'] ) ) )
		);
		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );
		$sender = $this->sender();
		$sender->process_next(); // sync

		$after_first = $campaigns->find_by_id( $campaign_id );
		$this->assertNotNull( $after_first );
		$first_segment = (string) $after_first->audience_segment_id;
		$this->assertNotSame( '', $first_segment );

		// Simulate permanent failure → READY so admin can change filter and retry.
		foreach ( $jobs->find_by_campaign( $campaign_id ) as $job ) {
			$jobs->update(
				(int) $job->id,
				array(
					'status'     => SendJobStatus::FAILED,
					'last_error' => 'simulated',
				)
			);
		}
		$campaigns->update( $campaign_id, array( 'status' => CampaignStatus::READY ) );

		// Narrow filter to VIP+Product (AND) — leftover A on old segment must not receive Broadcast.
		$this->contact_calls  = array();
		$this->segment_calls  = array();
		$this->broadcast_calls = array();
		$campaigns->update(
			$campaign_id,
			array(
				'filter_tag_ids'       => CampaignRepository::encode_filter_tag_ids(
					array( $tags['vip'], $tags['product'] )
				),
				// Stale segment left from first attempt (would widen audience if reused).
				'audience_segment_id' => $first_segment,
			)
		);

		$this->assertTrue( $this->queue()->enqueue_campaign( $campaign_id )['ok'] );
		$queued = $campaigns->find_by_id( $campaign_id );
		$this->assertNotNull( $queued );
		$this->assertTrue(
			null === $queued->audience_segment_id || '' === (string) $queued->audience_segment_id,
			'Enqueue must clear audience_segment_id before filtered sync'
		);

		$sender = $this->sender();
		$sender->process_next(); // sync
		$sender->process_next(); // broadcast

		$after_second = $campaigns->find_by_id( $campaign_id );
		$this->assertNotNull( $after_second );
		$second_segment = (string) $after_second->audience_segment_id;
		$this->assertNotSame( '', $second_segment );
		$this->assertNotSame( $first_segment, $second_segment, 'Must create a fresh campaign segment' );

		$emails = array_column( $this->contact_calls, 'email' );
		sort( $emails );
		$this->assertSame( array( 'b@example.com' ), $emails );
		foreach ( $this->contact_calls as $call ) {
			$this->assertSame( $second_segment, $call['segment_id'] );
		}
		$this->assertSame( $second_segment, $this->broadcast_calls[0]['segment_id'] );
		$this->assertSame( 0, $this->batch_calls );
	}
}
