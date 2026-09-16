<?php
/**
 * Unit tests: Svix/Resend webhook signature vectors (no WordPress).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Resend\Exceptions\WebhookSignatureVerificationException;
use Resend\WebhookSignature;
use WpResendNewsletter\Tests\Support\WebhookSigning;

/**
 * @coversNothing
 */
class WebhookSignatureVector_Test extends TestCase {

	/**
	 * Valid signature verifies.
	 */
	public function test_valid_signature_vector_verifies(): void {
		$secret  = WebhookSigning::make_secret();
		$payload = '{"type":"email.bounced","data":{"to":["a@example.com"]}}';
		$msg_id  = 'msg_test_vector_001';
		$ts      = time();
		$sig     = WebhookSigning::sign( $secret, $msg_id, $ts, $payload );

		$ok = WebhookSignature::verify(
			$payload,
			array(
				'svix-id'        => $msg_id,
				'svix-timestamp' => (string) $ts,
				'svix-signature' => $sig,
			),
			$secret
		);

		$this->assertTrue( $ok );
	}

	/**
	 * Wrong signature is rejected.
	 */
	public function test_invalid_signature_rejected(): void {
		$secret  = WebhookSigning::make_secret();
		$payload = '{"type":"email.bounced"}';
		$msg_id  = 'msg_test_vector_002';
		$ts      = time();

		$this->expectException( WebhookSignatureVerificationException::class );

		WebhookSignature::verify(
			$payload,
			array(
				'svix-id'        => $msg_id,
				'svix-timestamp' => (string) $ts,
				'svix-signature' => 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
			),
			$secret
		);
	}

	/**
	 * Tampered body fails even with otherwise-valid headers.
	 */
	public function test_tampered_body_fails(): void {
		$secret  = WebhookSigning::make_secret();
		$payload = '{"type":"email.bounced"}';
		$msg_id  = 'msg_test_vector_003';
		$ts      = time();
		$sig     = WebhookSigning::sign( $secret, $msg_id, $ts, $payload );

		$this->expectException( WebhookSignatureVerificationException::class );

		WebhookSignature::verify(
			$payload . ' ',
			array(
				'svix-id'        => $msg_id,
				'svix-timestamp' => (string) $ts,
				'svix-signature' => $sig,
			),
			$secret
		);
	}
}
