<?php
/**
 * Public REST endpoints for subscribe / confirm / unsubscribe.
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
use WpResendNewsletter\Application\ConfirmService;
use WpResendNewsletter\Application\SubscribeService;
use WpResendNewsletter\Application\UnsubscribeService;
use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\TokenService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller — namespace wprn/v1.
 */
class SubscribersController extends WP_REST_Controller {

	/**
	 * Max subscribe attempts per IP per window (abuse / confirm-mail flooding).
	 */
	public const SUBSCRIBE_RATE_LIMIT = 10;

	/**
	 * Rate-limit window in seconds.
	 */
	public const SUBSCRIBE_RATE_WINDOW = 900; // 15 minutes.

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
	protected $rest_base = 'subscribers';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'subscribe' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'email' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && is_email( $value );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/confirm',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'confirm' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && '' !== trim( $value );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unsubscribe',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'unsubscribe' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && '' !== trim( $value );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * POST /subscribers — start double opt-in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function subscribe( WP_REST_Request $request ) {
		if ( $this->is_subscribe_rate_limited() ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many subscribe attempts. Please try again later.', 'wp-resend-newsletter' ),
				array( 'status' => 429 )
			);
		}

		$email   = (string) $request->get_param( 'email' );
		$service = $this->make_subscribe_service();
		$result  = $service->subscribe( $email );

		if ( ! $result['ok'] ) {
			$status = ( 'invalid_email' === ( $result['code'] ?? '' ) ) ? 400 : 500;
			return new WP_Error(
				$result['code'] ?? 'subscribe_failed',
				$result['message'],
				array( 'status' => $status )
			);
		}

		// Never expose confirm_token or whether email was already known.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * GET /subscribers/confirm?token=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( WP_REST_Request $request ) {
		$token   = (string) $request->get_param( 'token' );
		$service = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$result  = $service->confirm( $token );

		if ( ! $result['ok'] ) {
			return new WP_Error(
				$result['code'] ?? 'confirm_failed',
				$result['message'],
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * GET /subscribers/unsubscribe?token=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unsubscribe( WP_REST_Request $request ) {
		$token   = (string) $request->get_param( 'token' );
		$service = new UnsubscribeService( new SubscriberRepository(), new TokenService() );
		$result  = $service->unsubscribe( $token );

		if ( ! $result['ok'] ) {
			return new WP_Error(
				$result['code'] ?? 'unsubscribe_failed',
				$result['message'],
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * Build SubscribeService with WP Resend client.
	 *
	 * @return SubscribeService
	 */
	private function make_subscribe_service(): SubscribeService {
		return new SubscribeService(
			new SubscriberRepository(),
			new TokenService(),
			ResendClient::from_wp()
		);
	}

	/**
	 * Whether the client IP has exceeded the subscribe rate limit.
	 *
	 * @return bool
	 */
	private function is_subscribe_rate_limited(): bool {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		$key   = 'wprn_sub_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::SUBSCRIBE_RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, self::SUBSCRIBE_RATE_WINDOW );
		return false;
	}
}
