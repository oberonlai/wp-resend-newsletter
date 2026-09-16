<?php
/**
 * Campaign application service (CRUD + status transitions).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Persistence\CampaignRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create / update / mark ready or scheduled.
 *
 * Hard rule: never call Resend Broadcasts or transactional send_batch here.
 * Delivery is owned by area 04. Recommend including
 * {{{RESEND_UNSUBSCRIBE_URL}}} in HTML/text bodies. From-domain must be
 * verified: news.oberonlai.blog.
 */
class CampaignService {

	/**
	 * Capability required to mutate campaigns.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Max subject length (matches varchar(255) column).
	 */
	public const MAX_SUBJECT_LENGTH = 255;

	/**
	 * Resend Broadcast unsubscribe placeholder (recommend in bodies).
	 *
	 * @see https://resend.com/docs/dashboard/emails/broadcast
	 */
	public const RESEND_UNSUBSCRIBE_PLACEHOLDER = '{{{RESEND_UNSUBSCRIBE_URL}}}';

	/**
	 * Verified sending domain hint for from address.
	 */
	public const VERIFIED_FROM_DOMAIN = 'news.oberonlai.blog';

	/**
	 * Repository.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository $repository Repository.
	 */
	public function __construct( CampaignRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Create a draft campaign.
	 *
	 * @param array{subject?: string, body_html?: string, body_text?: string} $data Fields.
	 * @return array{ok: bool, id?: int, code?: string, message: string}
	 */
	public function create( array $data ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$subject   = isset( $data['subject'] ) ? $this->sanitize_subject( (string) $data['subject'] ) : '';
		$body_html = isset( $data['body_html'] ) ? $this->sanitize_body_html( (string) $data['body_html'] ) : '';
		$body_text = isset( $data['body_text'] ) ? $this->sanitize_body_text( (string) $data['body_text'] ) : '';

		$subject_error = $this->validate_subject( $subject );
		if ( null !== $subject_error ) {
			return $subject_error;
		}

		$id = $this->repository->insert(
			array(
				'subject'    => $subject,
				'body_html'  => $body_html,
				'body_text'  => $body_text,
				'status'     => CampaignStatus::DRAFT,
				'created_by' => get_current_user_id(),
			)
		);

		if ( false === $id ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not save campaign. Please try again.', 'wp-resend-newsletter' ),
			);
		}

		/**
		 * After a draft campaign is created (no send).
		 *
		 * @param int $id Campaign ID.
		 */
		do_action( 'wprn_campaign_created', $id );

		return array(
			'ok'      => true,
			'id'      => $id,
			'message' => __( 'Campaign draft saved.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Update an editable campaign's content fields.
	 *
	 * @param int                                                             $id   Campaign ID.
	 * @param array{subject?: string, body_html?: string, body_text?: string} $data Fields.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function update( int $id, array $data ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$row = $this->repository->find_by_id( $id );
		if ( null === $row ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		if ( ! in_array( (string) $row->status, CampaignStatus::editable(), true ) ) {
			return array(
				'ok'      => false,
				'code'    => 'not_editable',
				'message' => __( 'This campaign can no longer be edited.', 'wp-resend-newsletter' ),
			);
		}

		$patch = array();

		if ( array_key_exists( 'subject', $data ) ) {
			$subject       = $this->sanitize_subject( (string) $data['subject'] );
			$subject_error = $this->validate_subject( $subject );
			if ( null !== $subject_error ) {
				return $subject_error;
			}
			$patch['subject'] = $subject;
		}

		if ( array_key_exists( 'body_html', $data ) ) {
			$patch['body_html'] = $this->sanitize_body_html( (string) $data['body_html'] );
		}

		if ( array_key_exists( 'body_text', $data ) ) {
			$patch['body_text'] = $this->sanitize_body_text( (string) $data['body_text'] );
		}

		if ( array() === $patch ) {
			return array(
				'ok'      => true,
				'message' => __( 'No changes.', 'wp-resend-newsletter' ),
			);
		}

		if ( ! $this->repository->update( $id, $patch ) ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not update campaign. Please try again.', 'wp-resend-newsletter' ),
			);
		}

		/**
		 * After campaign content is updated (no send).
		 *
		 * @param int $id Campaign ID.
		 */
		do_action( 'wprn_campaign_updated', $id );

		return array(
			'ok'      => true,
			'message' => __( 'Campaign updated.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Mark a draft (or scheduled) campaign ready to send — does not start Broadcast.
	 *
	 * @param int $id Campaign ID.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function mark_ready( int $id ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$row = $this->repository->find_by_id( $id );
		if ( null === $row ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		$from = array( CampaignStatus::DRAFT, CampaignStatus::SCHEDULED );
		if ( ! in_array( (string) $row->status, $from, true ) ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_transition',
				'message' => __( 'Campaign cannot be marked ready from its current status.', 'wp-resend-newsletter' ),
			);
		}

		$subject_error = $this->validate_subject( trim( (string) $row->subject ) );
		if ( null !== $subject_error ) {
			return $subject_error;
		}

		$ok = $this->repository->update(
			$id,
			array(
				'status'       => CampaignStatus::READY,
				'scheduled_at' => null,
			)
		);

		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not update campaign status.', 'wp-resend-newsletter' ),
			);
		}

		/**
		 * Campaign marked ready (Broadcast not started).
		 *
		 * @param int $id Campaign ID.
		 */
		do_action( 'wprn_campaign_ready', $id );

		return array(
			'ok'      => true,
			'message' => __( 'Campaign marked ready.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Mark a draft/ready campaign scheduled for a future time — does not start Broadcast.
	 *
	 * @param int    $id           Campaign ID.
	 * @param string $scheduled_at MySQL datetime (UTC) in the future.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function mark_scheduled( int $id, string $scheduled_at ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$row = $this->repository->find_by_id( $id );
		if ( null === $row ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Campaign not found.', 'wp-resend-newsletter' ),
			);
		}

		$from = array( CampaignStatus::DRAFT, CampaignStatus::READY, CampaignStatus::SCHEDULED );
		if ( ! in_array( (string) $row->status, $from, true ) ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_transition',
				'message' => __( 'Campaign cannot be scheduled from its current status.', 'wp-resend-newsletter' ),
			);
		}

		$subject_error = $this->validate_subject( trim( (string) $row->subject ) );
		if ( null !== $subject_error ) {
			return $subject_error;
		}

		$scheduled_at = trim( $scheduled_at );
		$ts           = strtotime( $scheduled_at . ' UTC' );
		if ( false === $ts || $ts <= time() ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_schedule',
				'message' => __( 'Schedule time must be a valid future datetime.', 'wp-resend-newsletter' ),
			);
		}

		$normalized = gmdate( 'Y-m-d H:i:s', $ts );

		$ok = $this->repository->update(
			$id,
			array(
				'status'       => CampaignStatus::SCHEDULED,
				'scheduled_at' => $normalized,
			)
		);

		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not schedule campaign.', 'wp-resend-newsletter' ),
			);
		}

		/**
		 * Campaign scheduled (Broadcast not started).
		 *
		 * @param int    $id           Campaign ID.
		 * @param string $scheduled_at UTC datetime.
		 */
		do_action( 'wprn_campaign_scheduled', $id, $normalized );

		return array(
			'ok'      => true,
			'message' => __( 'Campaign scheduled.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Sanitize campaign subject (plain text).
	 *
	 * @param string $subject Raw subject.
	 * @return string
	 */
	private function sanitize_subject( string $subject ): string {
		return sanitize_text_field( wp_unslash( $subject ) );
	}

	/**
	 * Sanitize HTML body (email content stored locally).
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function sanitize_body_html( string $html ): string {
		return wp_kses_post( wp_unslash( $html ) );
	}

	/**
	 * Sanitize plain-text body.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function sanitize_body_text( string $text ): string {
		return sanitize_textarea_field( wp_unslash( $text ) );
	}

	/**
	 * Validate subject after sanitization.
	 *
	 * @param string $subject Sanitized subject.
	 * @return array{ok: bool, code: string, message: string}|null
	 */
	private function validate_subject( string $subject ): ?array {
		if ( '' === $subject ) {
			return array(
				'ok'      => false,
				'code'    => 'empty_subject',
				'message' => __( 'Campaign subject is required.', 'wp-resend-newsletter' ),
			);
		}

		if ( strlen( $subject ) > self::MAX_SUBJECT_LENGTH ) {
			return array(
				'ok'      => false,
				'code'    => 'subject_too_long',
				'message' => __( 'Campaign subject must be 255 characters or fewer.', 'wp-resend-newsletter' ),
			);
		}

		return null;
	}

	/**
	 * Reject when current user lacks manage_options.
	 *
	 * @return array{ok: bool, code: string, message: string}|null
	 */
	private function deny_if_unauthorized(): ?array {
		if ( current_user_can( self::CAPABILITY ) ) {
			return null;
		}

		return array(
			'ok'      => false,
			'code'    => 'forbidden',
			'message' => __( 'You do not have permission to manage campaigns.', 'wp-resend-newsletter' ),
		);
	}
}
