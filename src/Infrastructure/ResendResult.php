<?php
/**
 * Result of a Resend API operation.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Infrastructure;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed result for ResendClient operations.
 */
final class ResendResult {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Response payload on success.
	 *
	 * @var mixed
	 */
	private mixed $data;

	/**
	 * Machine-readable error code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Human-readable error message.
	 *
	 * @var string
	 */
	private string $error_message;

	/**
	 * Constructor.
	 *
	 * @param bool   $success        Success flag.
	 * @param mixed  $data           Payload.
	 * @param string $error_code     Error code.
	 * @param string $error_message  Error message.
	 */
	private function __construct( bool $success, mixed $data, string $error_code, string $error_message ) {
		$this->success       = $success;
		$this->data          = $data;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	/**
	 * Build a successful result.
	 *
	 * @param mixed $data Payload.
	 * @return self
	 */
	public static function success( mixed $data = null ): self {
		return new self( true, $data, '', '' );
	}

	/**
	 * Build a failed result.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return self
	 */
	public static function failure( string $code, string $message ): self {
		return new self( false, null, $code, $message );
	}

	/**
	 * Whether the operation succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Response payload.
	 *
	 * @return mixed
	 */
	public function data(): mixed {
		return $this->data;
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Error message.
	 *
	 * @return string
	 */
	public function error_message(): string {
		return $this->error_message;
	}
}
