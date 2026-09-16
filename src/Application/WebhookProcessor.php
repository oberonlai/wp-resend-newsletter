<?php
/**
 * Process Resend (Svix-signed) webhook events.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use Resend\Exceptions\WebhookSignatureVerificationException;
use Resend\WebhookSignature;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\DeliveryEventRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\WebhookSecret;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies signatures and maps bounce/complaint/open/click events.
 *
 * Fail-closed when secret is missing. Never logs raw secrets, bodies, IP, or UA.
 */
class WebhookProcessor {

	/**
	 * Resend event type → local subscriber status.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_STATUS_MAP = array(
		'email.bounced'    => SubscriberStatus::BOUNCED,
		'email.complained' => SubscriberStatus::COMPLAINED,
		// Spec aliases.
		'bounce'           => SubscriberStatus::BOUNCED,
		'complaint'        => SubscriberStatus::COMPLAINED,
	);

	/**
	 * Engagement events that are stored but must not change subscriber status.
	 *
	 * @var list<string>
	 */
	private const ENGAGEMENT_TYPES = array(
		'email.opened',
		'email.clicked',
	);

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Delivery events repository.
	 *
	 * @var DeliveryEventRepository
	 */
	private DeliveryEventRepository $events;

	/**
	 * Campaign repository.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $campaigns;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository|null    $subscribers Optional repo.
	 * @param DeliveryEventRepository|null $events      Optional events repo.
	 * @param CampaignRepository|null      $campaigns   Optional campaigns repo.
	 */
	public function __construct(
		?SubscriberRepository $subscribers = null,
		?DeliveryEventRepository $events = null,
		?CampaignRepository $campaigns = null
	) {
		$this->subscribers = $subscribers ?? new SubscriberRepository();
		$this->events      = $events ?? new DeliveryEventRepository();
		$this->campaigns   = $campaigns ?? new CampaignRepository();
	}

	/**
	 * Process a raw webhook request.
	 *
	 * @param string                $raw_body Exact request body bytes used for signing.
	 * @param array<string, string> $headers  Must include svix-id, svix-timestamp, svix-signature.
	 * @return array{ok: bool, status: int, code: string, message: string}
	 */
	public function process( string $raw_body, array $headers ): array {
		$secret = WebhookSecret::resolve();
		if ( '' === $secret ) {
			return $this->result( false, 503, 'webhook_secret_missing', __( 'Webhook secret is not configured.', 'wp-resend-newsletter' ) );
		}

		$svix_headers = $this->normalize_svix_headers( $headers );
		if ( null === $svix_headers ) {
			return $this->result( false, 401, 'invalid_signature', __( 'Missing webhook signature headers.', 'wp-resend-newsletter' ) );
		}

		try {
			WebhookSignature::verify( $raw_body, $svix_headers, $secret );
		} catch ( WebhookSignatureVerificationException $e ) {
			unset( $e );
			return $this->result( false, 401, 'invalid_signature', __( 'Invalid webhook signature.', 'wp-resend-newsletter' ) );
		} catch ( \Throwable $e ) {
			// Malformed secret / unexpected crypto failure — fail closed; never leak details.
			unset( $e );
			return $this->result( false, 503, 'webhook_secret_invalid', __( 'Webhook secret is misconfigured.', 'wp-resend-newsletter' ) );
		}

		$provider_event_id = $svix_headers['svix-id'];

		// Idempotency: already processed this Svix message.
		if ( null !== $this->events->find_by_provider_event_id( $provider_event_id ) ) {
			return $this->result( true, 200, 'duplicate', __( 'Event already processed.', 'wp-resend-newsletter' ) );
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			// Signed but unusable body — acknowledge to avoid retry storms.
			$persist = $this->persist_event( $provider_event_id, 'invalid_payload', 0, 0, $raw_body, array() );
			if ( null !== $persist ) {
				return $persist;
			}
			return $this->result( true, 200, 'invalid_payload', __( 'Acknowledged invalid payload.', 'wp-resend-newsletter' ) );
		}

		$event_type = isset( $decoded['type'] ) ? (string) $decoded['type'] : '';
		$data       = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();

		$meta        = $this->extract_metadata( $data, $event_type );
		$campaign_id = $this->resolve_campaign_id( $meta['provider_broadcast_id'] );

		$status_target  = self::EVENT_STATUS_MAP[ $event_type ] ?? null;
		$is_engagement  = in_array( $event_type, self::ENGAGEMENT_TYPES, true );
		$subscriber_id  = 0;
		$resolve_emails = null !== $status_target || $is_engagement;

		if ( $resolve_emails ) {
			$emails = $this->extract_emails( $data );
			foreach ( $emails as $email ) {
				$row = $this->subscribers->find_by_email( $email );
				if ( null === $row ) {
					continue;
				}
				$subscriber_id = (int) $row->id;

				if ( null !== $status_target ) {
					$this->subscribers->update(
						$subscriber_id,
						array(
							'status' => $status_target,
						)
					);

					/**
					 * Fires after a webhook updates subscriber status.
					 *
					 * @param int    $subscriber_id Subscriber ID.
					 * @param string $status        New status.
					 * @param string $event_type    Provider event type.
					 */
					do_action( 'wprn_webhook_subscriber_status', $subscriber_id, $status_target, $event_type );
				}
			}
		}

		$persist = $this->persist_event(
			$provider_event_id,
			'' !== $event_type ? $event_type : 'unknown',
			$subscriber_id,
			$campaign_id,
			$raw_body,
			$meta
		);
		if ( null !== $persist ) {
			return $persist;
		}

		if ( null === $status_target && ! $is_engagement ) {
			return $this->result( true, 200, 'ignored', __( 'Event acknowledged.', 'wp-resend-newsletter' ) );
		}

		return $this->result( true, 200, 'processed', __( 'Event processed.', 'wp-resend-newsletter' ) );
	}

	/**
	 * Resolve local campaign id from Resend broadcast id.
	 *
	 * @param string|null $broadcast_id Provider broadcast id.
	 * @return int
	 */
	private function resolve_campaign_id( ?string $broadcast_id ): int {
		if ( null === $broadcast_id || '' === $broadcast_id ) {
			return 0;
		}
		$campaign = $this->campaigns->find_by_resend_broadcast_id( $broadcast_id );
		return null !== $campaign ? (int) $campaign->id : 0;
	}

	/**
	 * Extract safe metadata columns from event data (no IP/UA).
	 *
	 * @param array<string, mixed> $data       Event data.
	 * @param string               $event_type Event type.
	 * @return array{provider_broadcast_id: ?string, provider_email_id: ?string, link_url: ?string}
	 */
	private function extract_metadata( array $data, string $event_type ): array {
		$broadcast_id = null;
		if ( isset( $data['broadcast_id'] ) && is_string( $data['broadcast_id'] ) && '' !== $data['broadcast_id'] ) {
			$broadcast_id = substr( $data['broadcast_id'], 0, 64 );
		}

		$email_id = null;
		if ( isset( $data['email_id'] ) && is_string( $data['email_id'] ) && '' !== $data['email_id'] ) {
			$email_id = substr( $data['email_id'], 0, 64 );
		}

		$link_url = null;
		if ( 'email.clicked' === $event_type && isset( $data['click'] ) && is_array( $data['click'] ) ) {
			$link = $data['click']['link'] ?? null;
			if ( is_string( $link ) && '' !== $link ) {
				$sanitized = esc_url_raw( $link );
				if ( '' !== $sanitized ) {
					$link_url = substr( $sanitized, 0, 500 );
				}
			}
		}

		return array(
			'provider_broadcast_id' => $broadcast_id,
			'provider_email_id'     => $email_id,
			'link_url'              => $link_url,
		);
	}

	/**
	 * Extract recipient emails from Resend event data.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return list<string>
	 */
	private function extract_emails( array $data ): array {
		$emails = array();
		if ( isset( $data['to'] ) && is_array( $data['to'] ) ) {
			foreach ( $data['to'] as $addr ) {
				if ( is_string( $addr ) && is_email( $addr ) ) {
					$emails[] = strtolower( $addr );
				}
			}
		} elseif ( isset( $data['email'] ) && is_string( $data['email'] ) && is_email( $data['email'] ) ) {
			$emails[] = strtolower( $data['email'] );
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Normalize Svix header keys to the names Resend\WebhookSignature expects.
	 *
	 * @param array<string, string> $headers Incoming headers (any case).
	 * @return array{svix-id: string, svix-timestamp: string, svix-signature: string}|null
	 */
	private function normalize_svix_headers( array $headers ): ?array {
		$normalized = array();
		foreach ( $headers as $key => $value ) {
			$normalized[ strtolower( (string) $key ) ] = (string) $value;
		}

		$id        = $normalized['svix-id'] ?? '';
		$timestamp = $normalized['svix-timestamp'] ?? '';
		$signature = $normalized['svix-signature'] ?? '';

		if ( '' === $id || '' === $timestamp || '' === $signature ) {
			return null;
		}

		return array(
			'svix-id'        => $id,
			'svix-timestamp' => $timestamp,
			'svix-signature' => $signature,
		);
	}

	/**
	 * Persist delivery event for idempotency / audit (hash only, not raw PII body).
	 *
	 * On unique-key race, returns a duplicate success result. On other DB failures,
	 * returns 500 so the provider retries (status updates are safe to re-apply).
	 *
	 * @param string               $provider_event_id Provider id.
	 * @param string               $event_type        Event type.
	 * @param int                  $subscriber_id     Subscriber id or 0.
	 * @param int                  $campaign_id       Campaign id or 0.
	 * @param string               $raw_body          Raw body for hashing.
	 * @param array<string, mixed> $meta              Optional metadata columns.
	 * @return array{ok: bool, status: int, code: string, message: string}|null Null when persisted OK.
	 */
	private function persist_event(
		string $provider_event_id,
		string $event_type,
		int $subscriber_id,
		int $campaign_id,
		string $raw_body,
		array $meta
	): ?array {
		$row = array(
			'subscriber_id'     => $subscriber_id,
			'campaign_id'       => $campaign_id,
			'event_type'        => substr( $event_type, 0, 64 ),
			'provider_event_id' => substr( $provider_event_id, 0, 191 ),
			'payload_hash'      => hash( 'sha256', $raw_body ),
		);

		if ( isset( $meta['provider_broadcast_id'] ) && is_string( $meta['provider_broadcast_id'] ) && '' !== $meta['provider_broadcast_id'] ) {
			$row['provider_broadcast_id'] = $meta['provider_broadcast_id'];
		}
		if ( isset( $meta['provider_email_id'] ) && is_string( $meta['provider_email_id'] ) && '' !== $meta['provider_email_id'] ) {
			$row['provider_email_id'] = $meta['provider_email_id'];
		}
		if ( isset( $meta['link_url'] ) && is_string( $meta['link_url'] ) && '' !== $meta['link_url'] ) {
			$row['link_url'] = $meta['link_url'];
		}

		$inserted = $this->events->insert( $row );

		if ( false !== $inserted ) {
			return null;
		}

		// Concurrent delivery of the same svix-id — treat as idempotent success.
		if ( null !== $this->events->find_by_provider_event_id( $provider_event_id ) ) {
			return $this->result( true, 200, 'duplicate', __( 'Event already processed.', 'wp-resend-newsletter' ) );
		}

		return $this->result( false, 500, 'event_persist_failed', __( 'Could not persist delivery event.', 'wp-resend-newsletter' ) );
	}

	/**
	 * Build a result array.
	 *
	 * @param bool   $ok      Success flag.
	 * @param int    $status  HTTP status.
	 * @param string $code    Machine code.
	 * @param string $message Human message (no secrets).
	 * @return array{ok: bool, status: int, code: string, message: string}
	 */
	private function result( bool $ok, int $status, string $code, string $message ): array {
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
	}
}
