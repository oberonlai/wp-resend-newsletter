<?php
/**
 * Unit tests for CampaignStatus.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Domain\CampaignStatus;

/**
 * @covers \WpResendNewsletter\Domain\CampaignStatus
 */
class CampaignStatus_Test extends TestCase {

	/**
	 * Known statuses include draft / ready / scheduled / sending / sent / cancelled.
	 */
	public function test_known_statuses(): void {
		$this->assertTrue( CampaignStatus::is_valid( CampaignStatus::DRAFT ) );
		$this->assertTrue( CampaignStatus::is_valid( CampaignStatus::READY ) );
		$this->assertTrue( CampaignStatus::is_valid( CampaignStatus::SCHEDULED ) );
		$this->assertTrue( CampaignStatus::is_valid( CampaignStatus::SENDING ) );
		$this->assertTrue( CampaignStatus::is_valid( CampaignStatus::SENT ) );
		$this->assertTrue( CampaignStatus::is_valid( CampaignStatus::CANCELLED ) );
		$this->assertFalse( CampaignStatus::is_valid( 'nope' ) );
		$this->assertContains( CampaignStatus::DRAFT, CampaignStatus::editable() );
		$this->assertNotContains( CampaignStatus::SENT, CampaignStatus::editable() );
	}
}
