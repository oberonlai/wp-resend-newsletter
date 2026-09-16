<?php
/**
 * Integration tests: campaign CRUD + status transitions.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Campaigns;

use WP_UnitTestCase;
use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Persistence\CampaignRepository;

/**
 * @covers \WpResendNewsletter\Application\CampaignService
 * @covers \WpResendNewsletter\Persistence\CampaignRepository
 * @covers \WpResendNewsletter\Database\CampaignsTable
 */
class CampaignsFlow_Test extends WP_UnitTestCase {

	/**
	 * Create table before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		CampaignsTable::create_table();
	}

	/**
	 * Truncate campaigns between tests.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = CampaignsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		parent::tear_down();
	}

	/**
	 * Service under test.
	 */
	private function service(): CampaignService {
		return new CampaignService( new CampaignRepository() );
	}

	/**
	 * Set current user to administrator (manage_options).
	 */
	private function as_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Scenario: Create draft campaign with subject + bodies.
	 */
	public function test_create_draft_persists_fields(): void {
		$admin = $this->as_admin();
		$svc   = $this->service();

		$result = $svc->create(
			array(
				'subject'   => 'Hello',
				'body_html' => '<p>Hi {{{RESEND_UNSUBSCRIBE_URL}}}</p>',
				'body_text' => 'Hi {{{RESEND_UNSUBSCRIBE_URL}}}',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertArrayHasKey( 'id', $result );

		$row = ( new CampaignRepository() )->find_by_id( (int) $result['id'] );
		$this->assertNotNull( $row );
		$this->assertSame( CampaignStatus::DRAFT, $row->status );
		$this->assertSame( 'Hello', $row->subject );
		$this->assertSame( '<p>Hi {{{RESEND_UNSUBSCRIBE_URL}}}</p>', $row->body_html );
		$this->assertSame( 'Hi {{{RESEND_UNSUBSCRIBE_URL}}}', $row->body_text );
		$this->assertSame( (string) $admin, (string) $row->created_by );
		$this->assertNull( $row->resend_broadcast_id );
	}

	/**
	 * Scenario: Empty subject rejected on create — no row.
	 */
	public function test_empty_subject_rejected_on_create(): void {
		$this->as_admin();
		$svc = $this->service();

		$result = $svc->create(
			array(
				'subject'   => '   ',
				'body_html' => '<p>Hi</p>',
				'body_text' => 'Hi',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty_subject', $result['code'] );
		$this->assertSame( 0, ( new CampaignRepository() )->count_all() );
	}

	/**
	 * Scenario: Empty subject rejected on update — row unchanged.
	 */
	public function test_empty_subject_rejected_on_update(): void {
		$this->as_admin();
		$svc = $this->service();

		$created = $svc->create(
			array(
				'subject'   => 'Hello',
				'body_html' => '<p>Hi</p>',
				'body_text' => 'Hi',
			)
		);
		$id = (int) $created['id'];

		$result = $svc->update( $id, array( 'subject' => '' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty_subject', $result['code'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( 'Hello', $row->subject );
	}

	/**
	 * Scenario: Transition draft → ready.
	 */
	public function test_mark_ready_from_draft(): void {
		$this->as_admin();
		$svc = $this->service();

		$created = $svc->create(
			array(
				'subject'   => 'Ready me',
				'body_html' => '<p>Hi</p>',
				'body_text' => 'Hi',
			)
		);
		$id = (int) $created['id'];

		$result = $svc->mark_ready( $id );
		$this->assertTrue( $result['ok'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( CampaignStatus::READY, $row->status );
		$this->assertNull( $row->scheduled_at );
	}

	/**
	 * Scenario: Transition draft → scheduled with future time.
	 */
	public function test_mark_scheduled_from_draft(): void {
		$this->as_admin();
		$svc = $this->service();

		$created = $svc->create(
			array(
				'subject'   => 'Later',
				'body_html' => '<p>Hi</p>',
				'body_text' => 'Hi',
			)
		);
		$id = (int) $created['id'];

		$future = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		$result = $svc->mark_scheduled( $id, $future );
		$this->assertTrue( $result['ok'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( CampaignStatus::SCHEDULED, $row->status );
		$this->assertSame( $future, $row->scheduled_at );
	}

	/**
	 * Scenario: Past schedule time rejected.
	 */
	public function test_mark_scheduled_rejects_past_time(): void {
		$this->as_admin();
		$svc = $this->service();

		$created = $svc->create(
			array(
				'subject'   => 'Past',
				'body_html' => '<p>Hi</p>',
				'body_text' => 'Hi',
			)
		);
		$id = (int) $created['id'];

		$result = $svc->mark_scheduled( $id, '2000-01-01 00:00:00' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_schedule', $result['code'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( CampaignStatus::DRAFT, $row->status );
	}

	/**
	 * Scenario: Non-admin cannot mutate campaigns.
	 */
	public function test_non_admin_cannot_create_or_update(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$svc    = $this->service();
		$result = $svc->create(
			array(
				'subject'   => 'Nope',
				'body_html' => '<p>x</p>',
				'body_text' => 'x',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertSame( 0, ( new CampaignRepository() )->count_all() );

		// Seed a draft as admin, then try update as subscriber.
		$this->as_admin();
		$created = $svc->create(
			array(
				'subject'   => 'Seed',
				'body_html' => '<p>x</p>',
				'body_text' => 'x',
			)
		);
		$id = (int) $created['id'];

		wp_set_current_user( $user_id );
		$update = $svc->update( $id, array( 'subject' => 'Hacked' ) );
		$this->assertFalse( $update['ok'] );
		$this->assertSame( 'forbidden', $update['code'] );

		$ready = $svc->mark_ready( $id );
		$this->assertFalse( $ready['ok'] );
		$this->assertSame( 'forbidden', $ready['code'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( 'Seed', $row->subject );
		$this->assertSame( CampaignStatus::DRAFT, $row->status );
	}

	/**
	 * Scenario: Update content on draft succeeds; list helpers work for admin UI.
	 */
	public function test_update_and_list_support_admin_ui(): void {
		$this->as_admin();
		$svc  = $this->service();
		$repo = new CampaignRepository();

		$a = $svc->create(
			array(
				'subject'   => 'One',
				'body_html' => '<p>1</p>',
				'body_text' => '1',
			)
		);
		$b = $svc->create(
			array(
				'subject'   => 'Two',
				'body_html' => '<p>2</p>',
				'body_text' => '2',
			)
		);

		$upd = $svc->update(
			(int) $a['id'],
			array(
				'subject'   => 'One edited',
				'body_html' => '<p>1 {{{RESEND_UNSUBSCRIBE_URL}}}</p>',
			)
		);
		$this->assertTrue( $upd['ok'] );

		$row = $repo->find_by_id( (int) $a['id'] );
		$this->assertSame( 'One edited', $row->subject );
		$this->assertStringContainsString( CampaignService::RESEND_UNSUBSCRIBE_PLACEHOLDER, $row->body_html );

		$this->assertSame( 2, $repo->count_all() );
		$list = $repo->find_all( 10, 0 );
		$this->assertCount( 2, $list );
		// Newest first.
		$this->assertSame( (int) $b['id'], (int) $list[0]->id );

		$this->assertSame( 'news.oberonlai.blog', CampaignService::VERIFIED_FROM_DOMAIN );
		$this->assertTrue( CampaignsTable::table_exists() );
	}

	/**
	 * Repository rejects unknown columns (SQL safety).
	 */
	public function test_repository_rejects_unknown_columns(): void {
		$repo   = new CampaignRepository();
		$result = $repo->insert(
			array(
				'subject'     => 'X',
				'body_html'   => '',
				'body_text'   => '',
				'status'      => CampaignStatus::DRAFT,
				'evil_column' => 'nope',
			)
		);
		$this->assertFalse( $result );
	}

	/**
	 * Invalid transition: cannot mark ready from sent.
	 */
	public function test_invalid_transition_from_sent(): void {
		$this->as_admin();
		$svc  = $this->service();
		$repo = new CampaignRepository();

		$created = $svc->create(
			array(
				'subject'   => 'Done',
				'body_html' => '<p>x</p>',
				'body_text' => 'x',
			)
		);
		$id = (int) $created['id'];
		$repo->update( $id, array( 'status' => CampaignStatus::SENT ) );

		$result = $svc->mark_ready( $id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_transition', $result['code'] );
	}


	/**
	 * Scenario: scheduled → ready clears schedule (still no Broadcast).
	 */
	public function test_mark_ready_from_scheduled(): void {
		$this->as_admin();
		$svc = $this->service();

		$created = $svc->create(
			array(
				'subject'   => 'Soon',
				'body_html' => '<p>Hi</p>',
				'body_text' => 'Hi',
			)
		);
		$id     = (int) $created['id'];
		$future = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		$this->assertTrue( $svc->mark_scheduled( $id, $future )['ok'] );

		$result = $svc->mark_ready( $id );
		$this->assertTrue( $result['ok'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( CampaignStatus::READY, $row->status );
		$this->assertNull( $row->scheduled_at );
	}

	/**
	 * Scenario: content not editable once sent.
	 */
	public function test_update_rejected_when_not_editable(): void {
		$this->as_admin();
		$svc  = $this->service();
		$repo = new CampaignRepository();

		$created = $svc->create(
			array(
				'subject'   => 'Locked',
				'body_html' => '<p>x</p>',
				'body_text' => 'x',
			)
		);
		$id = (int) $created['id'];
		$repo->update( $id, array( 'status' => CampaignStatus::SENT ) );

		$result = $svc->update( $id, array( 'subject' => 'Nope' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_editable', $result['code'] );

		$row = $repo->find_by_id( $id );
		$this->assertSame( 'Locked', $row->subject );
	}

	/**
	 * Subject is sanitized; overlong subjects rejected (varchar 255).
	 */
	public function test_subject_sanitized_and_length_limited(): void {
		$this->as_admin();
		$svc = $this->service();

		$created = $svc->create(
			array(
				'subject'   => 'Hello <script>alert(1)</script> World',
				'body_html' => '<p>Hi</p><script>alert(1)</script>',
				'body_text' => "Hi\n<script>x</script>",
			)
		);
		$this->assertTrue( $created['ok'] );
		$row = ( new CampaignRepository() )->find_by_id( (int) $created['id'] );
		$this->assertSame( 'Hello World', $row->subject );
		$this->assertStringNotContainsString( '<script>', $row->body_html );
		$this->assertStringContainsString( '<p>Hi</p>', $row->body_html );
		$this->assertStringNotContainsString( '<script>', $row->body_text );

		$long = str_repeat( 'a', CampaignService::MAX_SUBJECT_LENGTH + 1 );
		$fail = $svc->create(
			array(
				'subject'   => $long,
				'body_html' => '<p>x</p>',
				'body_text' => 'x',
			)
		);
		$this->assertFalse( $fail['ok'] );
		$this->assertSame( 'subject_too_long', $fail['code'] );
	}

	/**
	 * Hard rule: campaign area must not depend on ResendClient / send_batch.
	 */
	public function test_campaign_service_has_no_resend_send_dependency(): void {
		$ref    = new \ReflectionClass( CampaignService::class );
		$ctor   = $ref->getConstructor();
		$this->assertNotNull( $ctor );
		$params = $ctor->getParameters();
		$this->assertCount( 1, $params );
		$type = $params[0]->getType();
		$this->assertInstanceOf( \ReflectionNamedType::class, $type );
		$this->assertSame( CampaignRepository::class, $type->getName() );

		$source = file_get_contents( $ref->getFileName() );
		$this->assertIsString( $source );
		// Strip comments so docblock hard-rule mentions do not false-positive.
		$code = preg_replace( '!/\\*.*?\\*/!s', '', $source );
		$code = preg_replace( '!//.*$!m', '', (string) $code );
		$this->assertIsString( $code );
		$this->assertStringNotContainsString( 'ResendClient', $code );
		$this->assertStringNotContainsString( 'send_batch', $code );
		$this->assertStringNotContainsString( 'send_broadcast', $code );
		$this->assertDoesNotMatchRegularExpression( '/->\\s*broadcasts\\b/', $code );
	}

}
