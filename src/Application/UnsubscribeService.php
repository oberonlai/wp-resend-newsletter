<?php
/**
 * Unsubscribe application service.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\TokenService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unsubscribes via signed link; idempotent when already unsubscribed.
 */
class UnsubscribeService {

	/**
	 * Repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $repository;

	/**
	 * Token service.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository $repository Repository.
	 * @param TokenService         $tokens     Tokens.
	 */
	public function __construct( SubscriberRepository $repository, TokenService $tokens ) {
		$this->repository = $repository;
		$this->tokens     = $tokens;
	}

	/**
	 * Issue (rotate) a fresh unsubscribe raw token for a subscriber.
	 *
	 * Raw tokens are never persisted — only the HMAC hash is stored. Callers
	 * (confirm hook, campaign footer builder) must embed the returned raw value
	 * in the signed link they send to the subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return string|false Raw token on success, false on failure.
	 */
	public function issue_token( int $subscriber_id ): string|false {
		if ( $subscriber_id <= 0 ) {
			return false;
		}

		$raw  = $this->tokens->generate_raw();
		$hash = $this->tokens->hash( $raw, TokenService::PURPOSE_UNSUB );
		$ok   = $this->repository->update(
			$subscriber_id,
			array(
				'unsub_token_hash' => $hash,
			)
		);

		return $ok ? $raw : false;
	}

	/**
	 * Unsubscribe with a raw unsub token.
	 *
	 * @param string $raw_token Raw token.
	 * @return array{ok: bool, message: string, code?: string}
	 */
	public function unsubscribe( string $raw_token ): array {
		$raw_token = trim( $raw_token );
		if ( '' === $raw_token ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_token',
				'message' => __( 'This unsubscribe link is invalid.', 'wp-resend-newsletter' ),
			);
		}

		$hash = $this->tokens->hash( $raw_token, TokenService::PURPOSE_UNSUB );
		$row  = $this->repository->find_by_unsub_token_hash( $hash );

		if ( null === $row ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_token',
				'message' => __( 'This unsubscribe link is invalid.', 'wp-resend-newsletter' ),
			);
		}

		// Idempotent: already unsubscribed → success.
		if ( SubscriberStatus::UNSUBSCRIBED === $row->status ) {
			return array(
				'ok'      => true,
				'message' => __( 'You have been unsubscribed.', 'wp-resend-newsletter' ),
			);
		}

		$ok = $this->repository->update(
			(int) $row->id,
			array(
				'status'             => SubscriberStatus::UNSUBSCRIBED,
				'confirm_token_hash' => null,
				'confirm_expires_at' => null,
			)
		);

		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not unsubscribe. Please try again.', 'wp-resend-newsletter' ),
			);
		}

		/**
		 * Subscriber unsubscribed — hook for later Segment contact removal (area 04).
		 *
		 * @param int    $id    Subscriber ID.
		 * @param string $email Email.
		 */
		do_action( 'wprn_subscriber_unsubscribed', (int) $row->id, (string) $row->email );

		return array(
			'ok'      => true,
			'message' => __( 'You have been unsubscribed.', 'wp-resend-newsletter' ),
		);
	}
}
