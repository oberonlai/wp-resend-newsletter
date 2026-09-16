<?php
/**
 * Unit tests for SubscriberStatus.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Domain\SubscriberStatus;

/**
 * @covers \WpResendNewsletter\Domain\SubscriberStatus
 */
class SubscriberStatus_Test extends TestCase {

	/**
	 * Known statuses include pending / confirmed / unsubscribed.
	 */
	public function test_known_statuses(): void {
		$this->assertTrue( SubscriberStatus::is_valid( SubscriberStatus::PENDING ) );
		$this->assertTrue( SubscriberStatus::is_valid( SubscriberStatus::CONFIRMED ) );
		$this->assertTrue( SubscriberStatus::is_valid( SubscriberStatus::UNSUBSCRIBED ) );
		$this->assertFalse( SubscriberStatus::is_valid( 'nope' ) );
		$this->assertContains( SubscriberStatus::BOUNCED, SubscriberStatus::all() );
	}
}
