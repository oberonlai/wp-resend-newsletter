<?php
/**
 * Subscribe (double opt-in start) application service.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Admin\SettingsPage;
use WpResendNewsletter\Frontend\ConfirmPage;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\TokenService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles public subscribe requests; enumeration-safe responses.
 */
class SubscribeService {

	/**
	 * Confirm token TTL in seconds (7 days).
	 */
	public const CONFIRM_TTL = 604800; // 7 days.


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
	 * Resend client (transactional path only).
	 *
	 * @var ResendClient
	 */
	private ResendClient $resend;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository $repository Repository.
	 * @param TokenService         $tokens     Tokens.
	 * @param ResendClient         $resend     Resend client.
	 */
	public function __construct(
		SubscriberRepository $repository,
		TokenService $tokens,
		ResendClient $resend
	) {
		$this->repository = $repository;
		$this->tokens     = $tokens;
		$this->resend     = $resend;
	}

	/**
	 * Subscribe with a valid email. Always enumeration-safe on success path.
	 *
	 * @param string $email Raw email input.
	 * @return array{ok: bool, message: string, code?: string, confirm_token?: string}
	 */
	public function subscribe( string $email ): array {
		$email = strtolower( trim( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_email',
				'message' => __( 'Please provide a valid email address.', 'wp-resend-newsletter' ),
			);
		}

		$existing     = $this->repository->find_by_email( $email );
		$raw_confirm  = $this->tokens->generate_raw();
		$confirm_hash = $this->tokens->hash( $raw_confirm, TokenService::PURPOSE_CONFIRM );
		$expires      = gmdate( 'Y-m-d H:i:s', time() + self::CONFIRM_TTL );

		if ( null === $existing ) {
			$raw_unsub  = $this->tokens->generate_raw();
			$unsub_hash = $this->tokens->hash( $raw_unsub, TokenService::PURPOSE_UNSUB );

			$id = $this->repository->insert(
				array(
					'email'              => $email,
					'status'             => SubscriberStatus::PENDING,
					'confirm_token_hash' => $confirm_hash,
					'unsub_token_hash'   => $unsub_hash,
					'confirm_expires_at' => $expires,
				)
			);

			if ( false === $id ) {
				return array(
					'ok'      => false,
					'code'    => 'db_error',
					'message' => __( 'Could not save subscription. Please try again.', 'wp-resend-newsletter' ),
				);
			}

			$this->send_confirm_email( $email, $raw_confirm );

			/**
			 * Fires after a new pending subscriber is created (hooks for later Segment sync).
			 *
			 * @param int    $id    Subscriber ID.
			 * @param string $email Email.
			 */
			do_action( 'wprn_subscriber_pending', $id, $email );

			return array(
				'ok'            => true,
				'message'       => __( 'If this email can be subscribed, a confirmation message has been sent.', 'wp-resend-newsletter' ),
				'confirm_token' => $raw_confirm, // Tests only; REST must not expose this.
			);
		}

		// Already confirmed: silent success (enumeration-safe).
		if ( SubscriberStatus::CONFIRMED === $existing->status ) {
			return array(
				'ok'      => true,
				'message' => __( 'If this email can be subscribed, a confirmation message has been sent.', 'wp-resend-newsletter' ),
			);
		}

		// Pending / unsubscribed / bounced / complained → refresh confirm token and re-send.
		$this->repository->update(
			(int) $existing->id,
			array(
				'status'             => SubscriberStatus::PENDING,
				'confirm_token_hash' => $confirm_hash,
				'confirm_expires_at' => $expires,
				'confirmed_at'       => null,
			)
		);

		$this->send_confirm_email( $email, $raw_confirm );

		return array(
			'ok'            => true,
			'message'       => __( 'If this email can be subscribed, a confirmation message has been sent.', 'wp-resend-newsletter' ),
			'confirm_token' => $raw_confirm,
		);
	}

	/**
	 * Send transactional confirmation email via Resend batch/single path.
	 *
	 * @param string $email        Recipient.
	 * @param string $raw_confirm  Raw confirm token for URL.
	 * @return void
	 */
	private function send_confirm_email( string $email, string $raw_confirm ): void {
		$settings   = SettingsPage::get_options();
		$from_email = $settings['from_email'];
		$from_name  = $settings['from_name'];

		if ( '' === $from_email || ! is_email( $from_email ) ) {
			/**
			 * Fired when confirm mail cannot be sent (missing from address).
			 *
			 * @param string $email Recipient.
			 */
			do_action( 'wprn_confirm_mail_skipped', $email );
			return;
		}

		$from = '' !== $from_name
			? sprintf( '%s <%s>', $from_name, $from_email )
			: $from_email;

		// Browser-friendly HTML page (REST /confirm remains JSON for API clients).
		$confirm_url = ConfirmPage::url( $raw_confirm );

		$subject = __( 'Please confirm your subscription', 'wp-resend-newsletter' );
		$inner   = sprintf(
			'<p>%s</p><p><a href="%s">%s</a></p>',
			esc_html__( 'Confirm your email to finish subscribing.', 'wp-resend-newsletter' ),
			esc_url( $confirm_url ),
			esc_html__( 'Confirm subscription', 'wp-resend-newsletter' )
		);
		// Brand shell without Broadcast unsubscribe placeholder (transactional send_batch).
		$html = EmailHtmlRenderer::to_document( EmailHtmlRenderer::render( $inner, false ) );

		$result = $this->resend->send_batch(
			array(
				array(
					'from'    => $from,
					'to'      => array( $email ),
					'subject' => $subject,
					'html'    => $html,
				),
			)
		);

		/**
		 * After attempting transactional confirm mail (Broadcasts must never be used here).
		 *
		 * @param string $email  Recipient.
		 * @param bool   $ok     Whether Resend accepted the send.
		 * @param string $code   Error code if any.
		 */
		do_action( 'wprn_confirm_mail_sent', $email, $result->is_success(), $result->error_code() );
	}
}
