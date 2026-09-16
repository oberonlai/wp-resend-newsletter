<?php
/**
 * Admin REST endpoint for live campaign audience estimates.
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
use WpResendNewsletter\Admin\Menu;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\SubscriberRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller — GET /wprn/v1/audience.
 */
class AudienceController extends WP_REST_Controller {

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
	protected $rest_base = 'audience';

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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_audience' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'tag_ids' => array(
							'required'          => false,
							'default'           => array(),
							'type'              => 'array',
							'items'             => array(
								'type' => 'integer',
							),
							'sanitize_callback' => array( $this, 'sanitize_tag_ids' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Capability gate (same as admin Menu).
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Sanitize tag_ids query param to unique positive ints.
	 *
	 * @param mixed           $value   Raw value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return list<int>
	 */
	public function sanitize_tag_ids( $value, $request, string $param ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $request, $param );
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}
		$ids = array_map( 'absint', $value );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return $ids;
	}

	/**
	 * Return matching and confirmed audience counts for selected tags.
	 *
	 * @param WP_REST_Request $request Request (tag_ids optional AND filter).
	 * @return WP_REST_Response
	 */
	public function get_audience( WP_REST_Request $request ): WP_REST_Response {
		$raw_tag_ids = $request->get_param( 'tag_ids' );
		$tag_ids     = is_array( $raw_tag_ids ) ? $raw_tag_ids : array();

		$repo      = new SubscriberRepository();
		$matching  = $repo->count_confirmed_with_all_tags( $tag_ids );
		$confirmed = $repo->count_by_status( SubscriberStatus::CONFIRMED );

		return new WP_REST_Response(
			array(
				'matching'  => $matching,
				'confirmed' => $confirmed,
			),
			200
		);
	}
}
