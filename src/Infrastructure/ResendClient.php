<?php
/**
 * Resend API client wrapper.
 *
 * Campaigns → Broadcasts (send_broadcast). Transactional confirm/test → send_batch only.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Infrastructure;

use Resend;
use Resend\Exceptions\ErrorException;
use Throwable;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around resend/resend-php.
 *
 * - send_broadcast(): newsletter campaigns (marketing/bulk) via Broadcasts API.
 * - send_batch(): transactional/operational only (double opt-in, admin test).
 *   Never use send_batch for campaign blasts — see spec/mvp/overview.md.
 * - create_segment() / list_segments() / delete_segment() / upsert_contact(): Segment audience for Broadcasts.
 */
class ResendClient {

	/**
	 * Option name for plugin settings.
	 */
	public const SETTINGS_OPTION = 'wprn_settings';

	/**
	 * Constant name that overrides the stored API key.
	 */
	public const API_KEY_CONSTANT = 'WPRN_RESEND_API_KEY';

	/**
	 * Resend Broadcast unsubscribe placeholder.
	 */
	public const UNSUBSCRIBE_PLACEHOLDER = '{{{RESEND_UNSUBSCRIBE_URL}}}';

	/**
	 * Verified sending domain.
	 */
	public const VERIFIED_FROM_DOMAIN = 'news.oberonlai.blog';

	/**
	 * Resolved API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Optional injectable sender for tests: fn(array $messages): mixed.
	 * Used by send_batch only.
	 *
	 * @var callable|null
	 */
	private $sender;

	/**
	 * Optional injectable broadcast creator for tests: fn(array $params): mixed.
	 *
	 * @var callable|null
	 */
	private $broadcast_sender;

	/**
	 * Optional injectable segment creator: fn(array $params): mixed.
	 *
	 * @var callable|null
	 */
	private $segment_creator;

	/**
	 * Optional injectable contact upsert: fn(string $email, string $segment_id): mixed.
	 *
	 * @var callable|null
	 */
	private $contact_upsert;

	/**
	 * Optional injectable segment list: fn(): mixed.
	 *
	 * @var callable|null
	 */
	private $segment_lister;

	/**
	 * Optional injectable segment remover: fn(string $id): mixed.
	 *
	 * @var callable|null
	 */
	private $segment_remover;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key           API key (empty fails closed on send).
	 * @param callable|null $sender            Optional transactional batch callback for tests.
	 * @param callable|null $broadcast_sender  Optional Broadcast create callback for tests.
	 * @param callable|null $segment_creator   Optional segment create callback for tests.
	 * @param callable|null $contact_upsert    Optional contact upsert callback for tests.
	 * @param callable|null $segment_lister    Optional segment list callback for tests.
	 * @param callable|null $segment_remover   Optional segment remove callback for tests.
	 */
	public function __construct(
		string $api_key = '',
		?callable $sender = null,
		?callable $broadcast_sender = null,
		?callable $segment_creator = null,
		?callable $contact_upsert = null,
		?callable $segment_lister = null,
		?callable $segment_remover = null
	) {
		$this->api_key          = $api_key;
		$this->sender           = $sender;
		$this->broadcast_sender = $broadcast_sender;
		$this->segment_creator  = $segment_creator;
		$this->contact_upsert   = $contact_upsert;
		$this->segment_lister   = $segment_lister;
		$this->segment_remover  = $segment_remover;
	}

	/**
	 * Create a client using WP settings / constant resolution.
	 *
	 * @return self
	 */
	public static function from_wp(): self {
		$settings = array();
		if ( function_exists( 'get_option' ) ) {
			$option   = get_option( self::SETTINGS_OPTION, array() );
			$settings = is_array( $option ) ? $option : array();
		}

		return new self( self::resolve_api_key_from( self::API_KEY_CONSTANT, $settings ) );
	}

	/**
	 * Resolve API key: non-empty constant wins over settings option.
	 *
	 * @param string               $constant_name Constant to check.
	 * @param array<string, mixed> $settings      Settings array (expects api_key key).
	 * @return string
	 */
	public static function resolve_api_key_from( string $constant_name, array $settings ): string {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		if ( isset( $settings['api_key'] ) && is_string( $settings['api_key'] ) ) {
			return $settings['api_key'];
		}

		return '';
	}

	/**
	 * Whether the API key is provided via wp-config constant.
	 *
	 * @return bool
	 */
	public static function is_api_key_from_constant(): bool {
		if ( ! defined( self::API_KEY_CONSTANT ) ) {
			return false;
		}
		$value = constant( self::API_KEY_CONSTANT );
		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Send a newsletter campaign via Resend Broadcasts (marketing/bulk).
	 *
	 * Maps to `$resend->broadcasts->create([ 'segment_id' => …, 'from' => …, 'subject' => …, 'html' => …, 'text' => …, 'send' => true ])`.
	 * Do not route campaigns through send_batch / emails / emails/batch.
	 *
	 * @param array<string, mixed> $params Broadcast params (segment_id, from, subject, html, text, …).
	 * @return ResendResult
	 */
	public function send_broadcast( array $params ): ResendResult {
		if ( '' === $this->api_key ) {
			return ResendResult::failure(
				'missing_api_key',
				__( 'Resend API key is not configured.', 'wp-resend-newsletter' )
			);
		}

		$segment_id = isset( $params['segment_id'] ) && is_string( $params['segment_id'] )
			? trim( $params['segment_id'] )
			: '';
		if ( '' === $segment_id ) {
			return ResendResult::failure(
				'invalid_broadcast_params',
				__( 'Broadcast requires a non-empty segment_id.', 'wp-resend-newsletter' )
			);
		}

		$from = isset( $params['from'] ) && is_string( $params['from'] ) ? trim( $params['from'] ) : '';
		if ( '' === $from ) {
			return ResendResult::failure(
				'invalid_broadcast_params',
				__( 'Broadcast requires a non-empty from address.', 'wp-resend-newsletter' )
			);
		}

		$subject = isset( $params['subject'] ) && is_string( $params['subject'] ) ? trim( $params['subject'] ) : '';
		if ( '' === $subject ) {
			return ResendResult::failure(
				'invalid_broadcast_params',
				__( 'Broadcast requires a non-empty subject.', 'wp-resend-newsletter' )
			);
		}

		$html = isset( $params['html'] ) && is_string( $params['html'] ) ? $params['html'] : '';
		$text = isset( $params['text'] ) && is_string( $params['text'] ) ? $params['text'] : '';

		$html = self::ensure_unsubscribe_placeholder( $html, true );
		$text = self::ensure_unsubscribe_placeholder( $text, false );

		$payload = array(
			'segment_id' => $segment_id,
			'from'       => $from,
			'subject'    => $subject,
			'html'       => $html,
			'text'       => $text,
			'send'       => true,
		);

		try {
			if ( null !== $this->broadcast_sender ) {
				$data = ( $this->broadcast_sender )( $payload );
			} else {
				$resend = Resend::client( $this->api_key );
				$data   = $resend->broadcasts->create( $payload );
			}

			$normalized = self::normalize_resource( $data );
			return ResendResult::success( $normalized );
		} catch ( Throwable $e ) {
			return self::failure_from_throwable( $e );
		}
	}

	/**
	 * Create a Resend Segment (audience) for Broadcast targeting.
	 *
	 * @param string $name Segment name.
	 * @return ResendResult Success data includes `id` when available.
	 */
	public function create_segment( string $name ): ResendResult {
		if ( '' === $this->api_key ) {
			return ResendResult::failure(
				'missing_api_key',
				__( 'Resend API key is not configured.', 'wp-resend-newsletter' )
			);
		}

		$name = trim( $name );
		if ( '' === $name ) {
			return ResendResult::failure(
				'invalid_segment_params',
				__( 'Segment name is required.', 'wp-resend-newsletter' )
			);
		}

		$params = array( 'name' => $name );

		try {
			if ( null !== $this->segment_creator ) {
				$data = ( $this->segment_creator )( $params );
			} else {
				$resend = Resend::client( $this->api_key );
				$data   = $resend->segments->create( $params );
			}

			return ResendResult::success( self::normalize_resource( $data ) );
		} catch ( Throwable $e ) {
			return self::failure_from_throwable( $e );
		}
	}

	/**
	 * List Resend Segments.
	 *
	 * @return ResendResult Success data is a normalized list payload (typically `data` => segments).
	 */
	public function list_segments(): ResendResult {
		if ( '' === $this->api_key ) {
			return ResendResult::failure(
				'missing_api_key',
				__( 'Resend API key is not configured.', 'wp-resend-newsletter' )
			);
		}

		try {
			if ( null !== $this->segment_lister ) {
				$data = ( $this->segment_lister )();
			} else {
				$resend = Resend::client( $this->api_key );
				$data   = $resend->segments->list();
			}

			return ResendResult::success( self::normalize_resource( $data ) );
		} catch ( Throwable $e ) {
			return self::failure_from_throwable( $e );
		}
	}

	/**
	 * Delete a Resend Segment by id.
	 *
	 * @param string $id Segment id.
	 * @return ResendResult
	 */
	public function delete_segment( string $id ): ResendResult {
		if ( '' === $this->api_key ) {
			return ResendResult::failure(
				'missing_api_key',
				__( 'Resend API key is not configured.', 'wp-resend-newsletter' )
			);
		}

		$id = trim( $id );
		if ( '' === $id ) {
			return ResendResult::failure(
				'invalid_segment_params',
				__( 'Segment id is required.', 'wp-resend-newsletter' )
			);
		}

		try {
			if ( null !== $this->segment_remover ) {
				$data = ( $this->segment_remover )( $id );
			} else {
				$resend = Resend::client( $this->api_key );
				$data   = $resend->segments->remove( $id );
			}

			return ResendResult::success( self::normalize_resource( $data ) );
		} catch ( Throwable $e ) {
			return self::failure_from_throwable( $e );
		}
	}

	/**
	 * Upsert a confirmed subscriber email as a Resend contact on a segment.
	 *
	 * Creates the contact with the segment attached; if create fails because
	 * the contact exists, adds the contact to the segment.
	 *
	 * @param string $email      Subscriber email.
	 * @param string $segment_id Resend segment ID.
	 * @return ResendResult
	 */
	public function upsert_contact( string $email, string $segment_id ): ResendResult {
		if ( '' === $this->api_key ) {
			return ResendResult::failure(
				'missing_api_key',
				__( 'Resend API key is not configured.', 'wp-resend-newsletter' )
			);
		}

		$email      = strtolower( trim( $email ) );
		$segment_id = trim( $segment_id );

		if ( '' === $email || ! is_email( $email ) ) {
			return ResendResult::failure(
				'invalid_contact_params',
				__( 'A valid contact email is required.', 'wp-resend-newsletter' )
			);
		}

		if ( '' === $segment_id ) {
			return ResendResult::failure(
				'invalid_contact_params',
				__( 'Contact upsert requires a non-empty segment_id.', 'wp-resend-newsletter' )
			);
		}

		try {
			if ( null !== $this->contact_upsert ) {
				$data = ( $this->contact_upsert )( $email, $segment_id );
				return ResendResult::success( self::normalize_resource( $data ) );
			}

			$resend = Resend::client( $this->api_key );

			try {
				$data = $resend->contacts->create(
					array(
						'email'        => $email,
						'segments'     => array(
							array( 'id' => $segment_id ),
						),
						'unsubscribed' => false,
					)
				);
				return ResendResult::success( self::normalize_resource( $data ) );
			} catch ( Throwable $create_error ) {
				// Contact may already exist — attach to segment by email.
				$data = $resend->contacts->segments->add( $email, $segment_id );
				return ResendResult::success( self::normalize_resource( $data ) );
			}
		} catch ( Throwable $e ) {
			return self::failure_from_throwable( $e );
		}
	}

	/**
	 * Send a transactional batch of email messages (max WPRN_RESEND_BATCH_MAX).
	 *
	 * For operational mail only (double opt-in confirm, optional admin test).
	 * Do NOT use for campaign blasts (use send_broadcast).
	 *
	 * @param array<int, array<string, mixed>> $messages Email payloads for Resend batch API.
	 * @return ResendResult
	 */
	public function send_batch( array $messages ): ResendResult {
		if ( '' === $this->api_key ) {
			return ResendResult::failure(
				'missing_api_key',
				__( 'Resend API key is not configured.', 'wp-resend-newsletter' )
			);
		}

		$max = defined( 'WPRN_RESEND_BATCH_MAX' ) ? (int) WPRN_RESEND_BATCH_MAX : 50;

		if ( count( $messages ) > $max ) {
			return ResendResult::failure(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Batch size exceeds maximum of %d recipients per request.', 'wp-resend-newsletter' ),
					$max
				)
			);
		}

		if ( array() === $messages ) {
			return ResendResult::success( array() );
		}

		try {
			if ( null !== $this->sender ) {
				$data = ( $this->sender )( $messages );
				return ResendResult::success( $data );
			}

			$resend = Resend::client( $this->api_key );
			$data   = $resend->batch->send( $messages );

			return ResendResult::success( $data );
		} catch ( Throwable $e ) {
			return self::failure_from_throwable( $e );
		}
	}

	/**
	 * Inject {{{RESEND_UNSUBSCRIBE_URL}}} when missing.
	 *
	 * @param string $body   HTML or text body.
	 * @param bool   $is_html Whether body is HTML.
	 * @return string
	 */
	public static function ensure_unsubscribe_placeholder( string $body, bool $is_html ): string {
		if ( str_contains( $body, self::UNSUBSCRIBE_PLACEHOLDER ) ) {
			return $body;
		}

		if ( $is_html ) {
			$link = '<p><a href="' . self::UNSUBSCRIBE_PLACEHOLDER . '">' . esc_html__( 'Unsubscribe', 'wp-resend-newsletter' ) . '</a></p>';
			return rtrim( $body ) . "\n" . $link;
		}

		$line = __( 'Unsubscribe:', 'wp-resend-newsletter' ) . ' ' . self::UNSUBSCRIBE_PLACEHOLDER;
		return rtrim( $body ) . "\n\n" . $line;
	}

	/**
	 * Whether a ResendResult failure should be retried.
	 *
	 * @param ResendResult $result Result.
	 * @return bool
	 */
	public static function is_retryable_result( ResendResult $result ): bool {
		if ( $result->is_success() ) {
			return false;
		}

		$code = $result->error_code();
		if ( in_array( $code, array( 'rate_limited', 'service_unavailable', 'retryable' ), true ) ) {
			return true;
		}

		// Numeric HTTP-style codes in message or code.
		if ( preg_match( '/\b(429|503|502|504)\b/', $code . ' ' . $result->error_message() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize SDK resource / array / object to array with optional id.
	 *
	 * @param mixed $data Raw response.
	 * @return array<string, mixed>
	 */
	private static function normalize_resource( mixed $data ): array {
		if ( is_array( $data ) ) {
			return $data;
		}

		if ( is_object( $data ) ) {
			if ( method_exists( $data, 'toArray' ) ) {
				$arr = $data->toArray();
				return is_array( $arr ) ? $arr : array();
			}
			if ( isset( $data->id ) ) {
				return array( 'id' => (string) $data->id );
			}
		}

		return array( 'raw' => $data );
	}

	/**
	 * Map a throwable to a typed ResendResult failure.
	 *
	 * @param Throwable $e Exception.
	 * @return ResendResult
	 */
	private static function failure_from_throwable( Throwable $e ): ResendResult {
		if ( $e instanceof ErrorException ) {
			$http = $e->getErrorCode();
			if ( 429 === $http ) {
				return ResendResult::failure( 'rate_limited', $e->getMessage() );
			}
			if ( in_array( $http, array( 502, 503, 504 ), true ) ) {
				return ResendResult::failure( 'service_unavailable', $e->getMessage() );
			}
			return ResendResult::failure( 'resend_error', $e->getMessage() );
		}

		$message = $e->getMessage();
		if ( preg_match( '/\b429\b/', $message ) ) {
			return ResendResult::failure( 'rate_limited', $message );
		}
		if ( preg_match( '/\b(502|503|504)\b/', $message ) ) {
			return ResendResult::failure( 'service_unavailable', $message );
		}

		return ResendResult::failure( 'resend_error', $message );
	}
}
