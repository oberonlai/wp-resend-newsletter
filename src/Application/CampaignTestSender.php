<?php
/**
 * Send a campaign preview to a single address (transactional test mail).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Admin\SettingsPage;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\CampaignRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin test send via Resend send_batch (one recipient, never a Broadcast).
 *
 * Does not change campaign status and does not touch the send queue.
 */
class CampaignTestSender {

	/**
	 * Campaign repository.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $campaigns;

	/**
	 * Resend client.
	 *
	 * @var ResendClient
	 */
	private ResendClient $client;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository $campaigns Campaigns.
	 * @param ResendClient       $client    Resend.
	 */
	public function __construct( CampaignRepository $campaigns, ResendClient $client ) {
		$this->campaigns = $campaigns;
		$this->client    = $client;
	}

	/**
	 * Build sender with WP defaults.
	 *
	 * @return self
	 */
	public static function from_wp(): self {
		return new self( new CampaignRepository(), ResendClient::from_wp() );
	}

	/**
	 * Send the saved campaign to one address with a [Test] subject prefix.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $to          Recipient email.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function send( int $campaign_id, string $to ): array {
		if ( ! current_user_can( CampaignService::CAPABILITY ) ) {
			return self::failure( 'forbidden', __( 'Insufficient permissions.', 'wp-resend-newsletter' ) );
		}

		if ( '' === $to || ! is_email( $to ) ) {
			return self::failure( 'invalid_recipient', __( 'Admin email address is not valid.', 'wp-resend-newsletter' ) );
		}

		$campaign = $campaign_id > 0 ? $this->campaigns->find_by_id( $campaign_id ) : null;
		if ( null === $campaign ) {
			return self::failure( 'not_found', __( 'Campaign not found.', 'wp-resend-newsletter' ) );
		}

		$settings   = SettingsPage::get_options();
		$from_email = (string) $settings['from_email'];
		$from_name  = (string) $settings['from_name'];
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return self::failure( 'missing_from', __( 'From email is not configured in Settings.', 'wp-resend-newsletter' ) );
		}

		$from = '' !== $from_name
			? sprintf( '%s <%s>', $from_name, $from_email )
			: $from_email;

		// Re-wrap without the Broadcast-only unsubscribe placeholder (send_batch cannot fill it).
		$html = EmailHtmlRenderer::render( (string) $campaign->body_html, false );
		$html = str_replace( ResendClient::UNSUBSCRIBE_PLACEHOLDER, '#', $html );
		$html = EmailHtmlRenderer::to_document( $html );
		$text = str_replace( ResendClient::UNSUBSCRIBE_PLACEHOLDER, '#', (string) $campaign->body_text );

		if ( '' === trim( $html ) && '' === trim( $text ) ) {
			return self::failure( 'empty_body', __( 'Campaign body is empty.', 'wp-resend-newsletter' ) );
		}

		$message = array(
			'from'    => $from,
			'to'      => array( $to ),
			/* translators: %s: campaign subject */
			'subject' => sprintf( __( '[Test] %s', 'wp-resend-newsletter' ), (string) $campaign->subject ),
		);
		if ( '' !== trim( $html ) ) {
			$message['html'] = $html;
		}
		if ( '' !== trim( $text ) ) {
			$message['text'] = $text;
		}

		$result = $this->client->send_batch( array( $message ) );
		if ( ! $result->is_success() ) {
			return self::failure(
				$result->error_code(),
				sprintf(
					/* translators: %s: error message from Resend */
					__( 'Test email failed: %s', 'wp-resend-newsletter' ),
					$result->error_message()
				)
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: recipient email */
				__( 'Test email sent to %s.', 'wp-resend-newsletter' ),
				$to
			),
		);
	}

	/**
	 * Failure payload.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array{ok: bool, code: string, message: string}
	 */
	private static function failure( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		);
	}
}
