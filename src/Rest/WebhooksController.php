<?php
/**
 * Public REST endpoint for Resend webhooks.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WpResendNewsletter\Application\WebhookProcessor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller — POST /wprn/v1/webhooks/resend.
 *
 * Unauthenticated public endpoint; auth is Svix signature verification.
 */
class WebhooksController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wprn/v1';

	/**
	 * Base.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhooks';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/resend',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_resend' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Public webhook URL for the Resend dashboard (Settings UI).
	 *
	 * @return string
	 */
	public static function endpoint_url(): string {
		return rest_url( 'wprn/v1/webhooks/resend' );
	}

	/**
	 * POST /webhooks/resend
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_resend( WP_REST_Request $request ) {
		$raw_body = $request->get_body();
		$headers  = array(
			'svix-id'        => (string) $request->get_header( 'svix-id' ),
			'svix-timestamp' => (string) $request->get_header( 'svix-timestamp' ),
			'svix-signature' => (string) $request->get_header( 'svix-signature' ),
		);

		$processor = new WebhookProcessor();
		$result    = $processor->process( $raw_body, $headers );

		if ( ! $result['ok'] ) {
			return new WP_Error(
				$result['code'],
				$result['message'],
				array( 'status' => $result['status'] )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'code'    => $result['code'],
				'message' => $result['message'],
			),
			$result['status']
		);
	}
}
