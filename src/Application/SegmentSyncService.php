<?php
/**
 * Ensure Resend Segment and sync confirmed subscribers as contacts.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Infrastructure\ResendResult;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Segment + contact sync for Broadcast campaigns.
 *
 * Filtered sends (v1.1) use a campaign-scoped segment with only matching contacts.
 * Tags stay local — never synced to Resend Topics.
 */
class SegmentSyncService {

	/**
	 * Settings option name.
	 */
	public const SETTINGS_OPTION = 'wprn_settings';

	/**
	 * Default segment name when creating.
	 */
	public const DEFAULT_SEGMENT_NAME = 'WP Resend Newsletter';

	/**
	 * Resend client.
	 *
	 * @var ResendClient
	 */
	private ResendClient $client;

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Campaign repository (filter + audience_segment_id).
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $campaigns;

	/**
	 * Constructor.
	 *
	 * @param ResendClient         $client      Resend client.
	 * @param SubscriberRepository $subscribers Subscribers.
	 * @param CampaignRepository   $campaigns   Campaigns.
	 */
	public function __construct(
		ResendClient $client,
		SubscriberRepository $subscribers,
		CampaignRepository $campaigns
	) {
		$this->client      = $client;
		$this->subscribers = $subscribers;
		$this->campaigns   = $campaigns;
	}

	/**
	 * Ensure a Resend segment_id exists in settings (create + store if missing).
	 *
	 * @return array{ok: bool, segment_id?: string, code?: string, message: string}
	 */
	public function ensure_segment(): array {
		$settings   = $this->get_settings();
		$segment_id = isset( $settings['segment_id'] ) && is_string( $settings['segment_id'] )
			? trim( $settings['segment_id'] )
			: '';

		if ( '' !== $segment_id ) {
			return array(
				'ok'         => true,
				'segment_id' => $segment_id,
				'message'    => __( 'Segment already configured.', 'wp-resend-newsletter' ),
			);
		}

		$result = $this->client->create_segment( self::DEFAULT_SEGMENT_NAME );
		if ( ! $result->is_success() ) {
			return array(
				'ok'      => false,
				'code'    => $result->error_code(),
				'message' => $result->error_message(),
			);
		}

		$new_id = self::extract_id( $result );
		if ( '' === $new_id ) {
			return array(
				'ok'      => false,
				'code'    => 'missing_segment_id',
				'message' => __( 'Resend did not return a segment id.', 'wp-resend-newsletter' ),
			);
		}

		$settings['segment_id'] = $new_id;
		update_option( self::SETTINGS_OPTION, $settings, false );

		return array(
			'ok'         => true,
			'segment_id' => $new_id,
			'message'    => __( 'Segment created and stored.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Ensure a campaign-scoped Resend segment (create + store on campaign if missing).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{ok: bool, segment_id?: string, code?: string, message: string, retryable?: bool}
	 */
	public function ensure_campaign_segment( int $campaign_id ): array {
		$campaign = $this->campaigns->find_by_id( $campaign_id );
		if ( null === $campaign ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		$existing = isset( $campaign->audience_segment_id ) ? trim( (string) $campaign->audience_segment_id ) : '';
		if ( '' !== $existing ) {
			return array(
				'ok'         => true,
				'segment_id' => $existing,
				'message'    => __( 'Campaign segment already configured.', 'wp-resend-newsletter' ),
			);
		}

		// Resend plans allow a small number of segments; purge disposable prior
		// campaign segments before creating a new one (keep General / other names).
		$this->delete_stale_campaign_segments( $existing );

		$name   = sprintf( 'WPRN campaign #%d', $campaign_id );
		$result = $this->client->create_segment( $name );
		if ( ! $result->is_success() ) {
			return array(
				'ok'        => false,
				'code'      => $result->error_code(),
				'message'   => $result->error_message(),
				'retryable' => ResendClient::is_retryable_result( $result ),
			);
		}

		$new_id = self::extract_id( $result );
		if ( '' === $new_id ) {
			return array(
				'ok'      => false,
				'code'    => 'missing_segment_id',
				'message' => __( 'Resend did not return a segment id.', 'wp-resend-newsletter' ),
			);
		}

		$updated = $this->campaigns->update(
			$campaign_id,
			array( 'audience_segment_id' => $new_id )
		);
		if ( ! $updated ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not store campaign audience segment id.', 'wp-resend-newsletter' ),
			);
		}

		return array(
			'ok'         => true,
			'segment_id' => $new_id,
			'message'    => __( 'Campaign segment created and stored.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Upsert all confirmed subscriber emails onto the given segment.
	 *
	 * @param string $segment_id Resend segment ID.
	 * @return array{ok: bool, synced?: int, code?: string, message: string, retryable?: bool}
	 */
	public function sync_confirmed_contacts( string $segment_id ): array {
		$emails = array();
		$offset = 0;
		$limit  = 500;

		do {
			$rows = $this->subscribers->find_confirmed( $limit, $offset );
			if ( array() === $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$email = isset( $row->email ) ? (string) $row->email : '';
				if ( '' !== $email ) {
					$emails[] = $email;
				}
			}
			$row_count = count( $rows );
			$offset   += $row_count;
		} while ( $row_count === $limit );

		return $this->sync_contacts( $segment_id, $emails );
	}

	/**
	 * Upsert a specific list of emails onto a segment (and store contact ids when returned).
	 *
	 * @param string $segment_id Resend segment ID.
	 * @param array  $emails     Emails to upsert (list of strings).
	 * @return array{ok: bool, synced?: int, code?: string, message: string, retryable?: bool}
	 */
	public function sync_contacts( string $segment_id, array $emails ): array {
		$segment_id = trim( $segment_id );
		if ( '' === $segment_id ) {
			return array(
				'ok'      => false,
				'code'    => 'missing_segment_id',
				'message' => __( 'Segment id is required for contact sync.', 'wp-resend-newsletter' ),
			);
		}

		$synced = 0;
		foreach ( $emails as $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( '' === $email ) {
				continue;
			}

			$result = $this->client->upsert_contact( $email, $segment_id );
			if ( ! $result->is_success() ) {
				return array(
					'ok'        => false,
					'code'      => $result->error_code(),
					'message'   => $result->error_message(),
					'retryable' => ResendClient::is_retryable_result( $result ),
					'synced'    => $synced,
				);
			}

			$contact_id = self::extract_id( $result );
			if ( '' !== $contact_id ) {
				$row = $this->subscribers->find_by_email( $email );
				if ( null !== $row && isset( $row->id ) ) {
					$this->subscribers->update(
						(int) $row->id,
						array( 'resend_contact_id' => $contact_id )
					);
				}
			}

			++$synced;
		}

		return array(
			'ok'      => true,
			'synced'  => $synced,
			'message' => sprintf(
				/* translators: %d: number of contacts synced */
				__( 'Synced %d confirmed subscriber(s) to Resend segment.', 'wp-resend-newsletter' ),
				$synced
			),
		);
	}

	/**
	 * Sync audience for a campaign: unfiltered → settings segment; filtered → campaign segment (AND tags).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{ok: bool, segment_id?: string, synced?: int, code?: string, message: string, retryable?: bool}
	 */
	public function sync_for_campaign( int $campaign_id ): array {
		$campaign = $this->campaigns->find_by_id( $campaign_id );
		if ( null === $campaign ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		$tag_ids = CampaignRepository::decode_filter_tag_ids( $campaign->filter_tag_ids ?? null );

		if ( array() === $tag_ids ) {
			return $this->run();
		}

		$audience = $this->subscribers->find_confirmed_with_all_tags( $tag_ids );
		if ( array() === $audience ) {
			return array(
				'ok'        => false,
				'code'      => 'empty_audience',
				'message'   => __( 'No confirmed subscribers match the selected tags. Broadcast was not created.', 'wp-resend-newsletter' ),
				'retryable' => false,
			);
		}

		$ensure = $this->ensure_campaign_segment( $campaign_id );
		if ( ! $ensure['ok'] ) {
			return array_merge(
				$ensure,
				array(
					'retryable' => ! empty( $ensure['retryable'] ),
				)
			);
		}

		$segment_id = (string) $ensure['segment_id'];
		$emails     = array();
		foreach ( $audience as $row ) {
			$emails[] = (string) $row->email;
		}

		$sync = $this->sync_contacts( $segment_id, $emails );
		if ( ! $sync['ok'] ) {
			$sync['segment_id'] = $segment_id;
			return $sync;
		}

		return array(
			'ok'         => true,
			'segment_id' => $segment_id,
			'synced'     => (int) ( $sync['synced'] ?? 0 ),
			'message'    => (string) $sync['message'],
		);
	}

	/**
	 * Ensure settings segment then sync all confirmed contacts (MVP / unfiltered).
	 *
	 * @return array{ok: bool, segment_id?: string, synced?: int, code?: string, message: string, retryable?: bool}
	 */
	public function run(): array {
		$ensure = $this->ensure_segment();
		if ( ! $ensure['ok'] ) {
			return array_merge(
				$ensure,
				array(
					'retryable' => isset( $ensure['code'] ) && in_array(
						(string) $ensure['code'],
						array( 'rate_limited', 'service_unavailable', 'retryable' ),
						true
					),
				)
			);
		}

		$segment_id = (string) $ensure['segment_id'];
		$sync       = $this->sync_confirmed_contacts( $segment_id );

		if ( ! $sync['ok'] ) {
			$sync['segment_id'] = $segment_id;
			return $sync;
		}

		return array(
			'ok'         => true,
			'segment_id' => $segment_id,
			'synced'     => (int) ( $sync['synced'] ?? 0 ),
			'message'    => (string) $sync['message'],
		);
	}

	/**
	 * Count confirmed audience (for enqueue empty-check).
	 *
	 * @param list<int>|null $tag_ids Optional AND tag filter; null/empty = all confirmed.
	 * @return int
	 */
	public function count_confirmed( ?array $tag_ids = null ): int {
		if ( null === $tag_ids || array() === $tag_ids ) {
			return $this->subscribers->count_by_status( SubscriberStatus::CONFIRMED );
		}
		return $this->subscribers->count_confirmed_with_all_tags( $tag_ids );
	}


	/**
	 * Delete prior WPRN campaign segments so plan limits do not block create.
	 *
	 * Soft-handles list/delete failures (continues). Never deletes General or
	 * other non-matching names. Optionally skips a keep id (this campaign's
	 * already-stored audience_segment_id).
	 *
	 * @param string $keep_segment_id Segment id to retain when set.
	 * @return void
	 */
	private function delete_stale_campaign_segments( string $keep_segment_id = '' ): void {
		$list = $this->client->list_segments();
		if ( ! $list->is_success() ) {
			return;
		}

		$payload = $list->data();
		$items   = array();
		if ( is_array( $payload ) ) {
			if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
				$items = $payload['data'];
			} elseif ( array_is_list( $payload ) ) {
				$items = $payload;
			}
		}

		foreach ( $items as $item ) {
			if ( is_object( $item ) ) {
				$seg_id = isset( $item->id ) ? trim( (string) $item->id ) : '';
				$name   = isset( $item->name ) ? (string) $item->name : '';
			} elseif ( is_array( $item ) ) {
				$seg_id = isset( $item['id'] ) ? trim( (string) $item['id'] ) : '';
				$name   = isset( $item['name'] ) ? (string) $item['name'] : '';
			} else {
				continue;
			}

			if ( '' === $seg_id ) {
				continue;
			}

			if ( 1 !== preg_match( '/^WPRN campaign #\d+$/', $name ) ) {
				continue;
			}

			if ( '' !== $keep_segment_id && $seg_id === $keep_segment_id ) {
				continue;
			}

			// Soft-handle: continue trying others even if one delete fails.
			$this->client->delete_segment( $seg_id );
		}
	}

	/**
	 * Settings array.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$option = get_option( self::SETTINGS_OPTION, array() );
		return is_array( $option ) ? $option : array();
	}

	/**
	 * Extract id from ResendResult data.
	 *
	 * @param ResendResult $result Result.
	 * @return string
	 */
	private static function extract_id( ResendResult $result ): string {
		$data = $result->data();
		if ( is_array( $data ) && isset( $data['id'] ) && ( is_string( $data['id'] ) || is_numeric( $data['id'] ) ) ) {
			return (string) $data['id'];
		}
		return '';
	}
}
