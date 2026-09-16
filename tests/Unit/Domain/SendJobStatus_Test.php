<?php
/**
 * Unit tests for SendJobStatus.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Domain\SendJobType;

/**
 * @covers \WpResendNewsletter\Domain\SendJobStatus
 * @covers \WpResendNewsletter\Domain\SendJobType
 */
class SendJobStatus_Test extends TestCase {

	public function test_status_all_contains_expected(): void {
		$this->assertSame(
			array( 'pending', 'processing', 'sent', 'failed' ),
			SendJobStatus::all()
		);
		$this->assertTrue( SendJobStatus::is_valid( SendJobStatus::PENDING ) );
		$this->assertFalse( SendJobStatus::is_valid( 'nope' ) );
	}

	public function test_job_types(): void {
		$this->assertSame(
			array( 'sync_segment', 'send_broadcast' ),
			SendJobType::all()
		);
		$this->assertTrue( SendJobType::is_valid( SendJobType::SEND_BROADCAST ) );
		$this->assertFalse( SendJobType::is_valid( 'send_batch' ) );
	}
}
