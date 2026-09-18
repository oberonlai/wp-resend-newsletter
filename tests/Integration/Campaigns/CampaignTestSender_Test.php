<?php
/**
 * Integration tests: campaign test email to admin.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Campaigns;

use WP_UnitTestCase;
use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Application\CampaignTestSender;
use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\CampaignRepository;

/**
 * @covers \WpResendNewsletter\Application\CampaignTestSender
 */
class CampaignTestSender_Test extends WP_UnitTestCase {

	/**
	 * Captured Resend batches.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $sent_batches = array();

	/**
	 * Set up table + settings.
	 */
	public function set_up(): void {
		parent::set_up();
		CampaignsTable::create_table();
		$this->sent_batches = array();

		update_option(
			'wprn_settings',
			array(
				'from_email'     => 'news@news.oberonlai.blog',
				'from_name'      => 'Test News',
				'api_key'        => 're_test_fake',
				'webhook_secret' => '',
			),
			false
		);
	}

	/**
	 * Truncate campaigns.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = CampaignsTable::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		parent::tear_down();
	}

	/**
	 * Sender with captured batch callback.
	 *
	 * @return CampaignTestSender
	 */
	private function sender(): CampaignTestSender {
		$client = new ResendClient(
			're_test_fake',
			function ( array $messages ) {
				$this->sent_batches[] = $messages;
				return array( 'data' => array() );
			}
		);
		return new CampaignTestSender( new CampaignRepository(), $client );
	}

	/**
	 * Create a draft as admin.
	 *
	 * @return int
	 */
	private function create_draft(): int {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = ( new CampaignService( new CampaignRepository() ) )->create(
			array(
				'subject'   => 'Hello list',
				'body_html' => EmailHtmlRenderer::render( '<p>Body here</p>' ),
				'body_text' => 'Body here',
			)
		);
		return (int) $result['id'];
	}

	/**
	 * Scenario: admin sends test; one message, [Test] prefix, no unsubscribe placeholder, status unchanged.
	 */
	public function test_admin_sends_test_email(): void {
		$id     = $this->create_draft();
		$result = $this->sender()->send( $id, 'admin@example.org' );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertCount( 1, $this->sent_batches );
		$message = $this->sent_batches[0][0];
		$this->assertSame( array( 'admin@example.org' ), $message['to'] );
		$this->assertSame( '[Test] Hello list', $message['subject'] );
		$this->assertSame( 'Test News <news@news.oberonlai.blog>', $message['from'] );
		$this->assertStringContainsString( 'Body here', $message['html'] );
		$this->assertStringNotContainsString( ResendClient::UNSUBSCRIBE_PLACEHOLDER, $message['html'] );

		$row = ( new CampaignRepository() )->find_by_id( $id );
		$this->assertSame( CampaignStatus::DRAFT, (string) $row->status );
	}

	/**
	 * Scenario: non-admin cannot send.
	 */
	public function test_non_admin_rejected(): void {
		$id = $this->create_draft();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$result = $this->sender()->send( $id, 'admin@example.org' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertCount( 0, $this->sent_batches );
	}

	/**
	 * Scenario: missing campaign / invalid recipient fail without sending.
	 */
	public function test_invalid_input_rejected(): void {
		$id = $this->create_draft();

		$this->assertSame( 'not_found', $this->sender()->send( 999999, 'admin@example.org' )['code'] );
		$this->assertSame( 'invalid_recipient', $this->sender()->send( $id, 'not-an-email' )['code'] );
		$this->assertCount( 0, $this->sent_batches );
	}
}
