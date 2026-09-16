<?php
/**
 * Confirm (double opt-in complete) application service.
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
 * Activates a pending subscriber via signed confirm token.
 */
class ConfirmService {

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
	 * Confirm subscription with a raw token from the URL.
	 *
	 * @param string $raw_token Raw confirm token.
	 * @return array{ok: bool, message: string, code?: string}
	 */
	public function confirm( string $raw_token ): array {
		$raw_token = trim( $raw_token );
		if ( '' === $raw_token ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_token',
				'message' => __( 'This confirmation link is invalid.', 'wp-resend-newsletter' ),
			);
		}

		$hash = $this->tokens->hash( $raw_token, TokenService::PURPOSE_CONFIRM );
		$row  = $this->repository->find_by_confirm_token_hash( $hash );

		if ( null === $row ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_token',
				'message' => __( 'This confirmation link is invalid or has already been used.', 'wp-resend-newsletter' ),
			);
		}

		if ( ! empty( $row->confirm_expires_at ) ) {
			$expires_ts = strtotime( $row->confirm_expires_at . ' UTC' );
			if ( false !== $expires_ts && $expires_ts < time() ) {
				return array(
					'ok'      => false,
					'code'    => 'expired_token',
					'message' => __( 'This confirmation link has expired. Please subscribe again.', 'wp-resend-newsletter' ),
				);
			}
		}

		if ( SubscriberStatus::CONFIRMED === $row->status ) {
			// Idempotent if somehow still has hash — clear and succeed.
			$this->repository->clear_confirm_token( (int) $row->id );
			return array(
				'ok'      => true,
				'message' => __( 'Your subscription is confirmed.', 'wp-resend-newsletter' ),
			);
		}

		if ( SubscriberStatus::PENDING !== $row->status ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_status',
				'message' => __( 'This confirmation link cannot be used.', 'wp-resend-newsletter' ),
			);
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $this->repository->update(
			(int) $row->id,
			array(
				'status'       => SubscriberStatus::CONFIRMED,
				'confirmed_at' => $now,
			)
		);

		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not confirm subscription. Please try again.', 'wp-resend-newsletter' ),
			);
		}

		$this->repository->clear_confirm_token( (int) $row->id );

		// Rotate unsub token so callers (welcome mail / campaigns) get a deliverable raw link.
		$unsub_svc = new UnsubscribeService( $this->repository, $this->tokens );
		$raw_unsub = $unsub_svc->issue_token( (int) $row->id );

		/**
		 * Subscriber confirmed — hook for later Resend Segment contact sync (area 04).
		 *
		 * @param int         $id         Subscriber ID.
		 * @param string      $email      Email.
		 * @param string|false $raw_unsub Raw unsubscribe token for signed links (false on failure).
		 */
		do_action( 'wprn_subscriber_confirmed', (int) $row->id, (string) $row->email, $raw_unsub );

		return array(
			'ok'      => true,
			'message' => __( 'Your subscription is confirmed. Thank you!', 'wp-resend-newsletter' ),
		);
	}
}
