<?php
/**
 * Admin-post handlers (nonce + manage_options).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Application\QueueService;
use WpResendNewsletter\Application\TagService;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles admin-post actions for campaigns / queue / tags.
 */
class AdminActions {

	public const SAVE_CAMPAIGN_ACTION    = 'wprn_save_campaign';
	public const MARK_READY_ACTION       = 'wprn_mark_campaign_ready';
	public const QUEUE_SEND_ACTION       = 'wprn_queue_campaign_send';
	public const BULK_UNSUBSCRIBE_ACTION = 'wprn_bulk_unsubscribe';
	public const CREATE_TAG_ACTION       = 'wprn_create_tag';
	public const RENAME_TAG_ACTION       = 'wprn_rename_tag';
	public const DELETE_TAG_ACTION       = 'wprn_delete_tag';
	public const BULK_ADD_TAG_ACTION     = 'wprn_bulk_add_tag';
	public const BULK_REMOVE_TAG_ACTION  = 'wprn_bulk_remove_tag';

	/**
	 * Capability.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Hook admin-post handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE_CAMPAIGN_ACTION, array( __CLASS__, 'handle_save_campaign' ) );
		add_action( 'admin_post_' . self::MARK_READY_ACTION, array( __CLASS__, 'handle_mark_ready' ) );
		add_action( 'admin_post_' . self::QUEUE_SEND_ACTION, array( __CLASS__, 'handle_queue_send' ) );
		add_action( 'admin_post_' . self::BULK_UNSUBSCRIBE_ACTION, array( __CLASS__, 'handle_bulk_unsubscribe' ) );
		add_action( 'admin_post_' . self::CREATE_TAG_ACTION, array( __CLASS__, 'handle_create_tag' ) );
		add_action( 'admin_post_' . self::RENAME_TAG_ACTION, array( __CLASS__, 'handle_rename_tag' ) );
		add_action( 'admin_post_' . self::DELETE_TAG_ACTION, array( __CLASS__, 'handle_delete_tag' ) );
		add_action( 'admin_post_' . self::BULK_ADD_TAG_ACTION, array( __CLASS__, 'handle_bulk_add_tag' ) );
		add_action( 'admin_post_' . self::BULK_REMOVE_TAG_ACTION, array( __CLASS__, 'handle_bulk_remove_tag' ) );
	}

	/**
	 * Save (create/update) campaign draft (includes filter_tag_ids snapshot).
	 *
	 * @return void
	 */
	public static function handle_save_campaign(): void {
		self::assert_nonce_and_cap( self::SAVE_CAMPAIGN_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assert_nonce_and_cap() above.
		$id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$body_html_raw = isset( $_POST['body_html'] ) ? wp_kses_post( wp_unslash( $_POST['body_html'] ) ) : '';
		$body_html     = wp_kses_post( EmailHtmlRenderer::render( $body_html_raw ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$body_text = isset( $_POST['body_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body_text'] ) ) : '';
		if ( '' === trim( $body_text ) && '' !== trim( $body_html ) ) {
			$body_text = sanitize_textarea_field( wp_strip_all_tags( $body_html ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$mark_ready_raw = isset( $_POST['mark_ready'] ) ? sanitize_text_field( wp_unslash( $_POST['mark_ready'] ) ) : '';
		$mark_ready     = ( '1' === $mark_ready_raw );

		$filter_tag_ids = self::read_filter_tag_ids_from_post();

		$service = new CampaignService( new CampaignRepository() );
		$data    = array(
			'subject'   => $subject,
			'body_html' => $body_html,
			'body_text' => $body_text,
		);

		if ( $id > 0 ) {
			$result = $service->update( $id, $data );
		} else {
			$result = $service->create( $data );
			if ( ! empty( $result['ok'] ) && isset( $result['id'] ) ) {
				$id = (int) $result['id'];
			}
		}

		if ( empty( $result['ok'] ) ) {
			self::redirect_with_notice(
				admin_url( 'admin.php?page=' . Menu::CAMPAIGN_EDIT_SLUG . ( $id > 0 ? '&id=' . $id : '' ) ),
				'error',
				(string) $result['message']
			);
		}

		if ( $id > 0 ) {
			( new CampaignRepository() )->update(
				$id,
				array( 'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids( $filter_tag_ids ) )
			);
		}

		if ( $mark_ready && $id > 0 ) {
			$ready = $service->mark_ready( $id );
			if ( empty( $ready['ok'] ) ) {
				self::redirect_with_notice(
					admin_url( 'admin.php?page=' . Menu::CAMPAIGN_EDIT_SLUG . '&id=' . $id ),
					'error',
					(string) $ready['message']
				);
			}
		}

		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::CAMPAIGNS_SLUG ),
			'success',
			(string) $result['message']
		);
	}

	/**
	 * Mark campaign ready.
	 *
	 * @return void
	 */
	public static function handle_mark_ready(): void {
		self::assert_nonce_and_cap( self::MARK_READY_ACTION );

		$id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$service = new CampaignService( new CampaignRepository() );
		$result  = $service->mark_ready( $id );

		$redirect = admin_url( 'admin.php?page=' . Menu::CAMPAIGNS_SLUG );
		if ( $id > 0 ) {
			$redirect = admin_url( 'admin.php?page=' . Menu::CAMPAIGN_EDIT_SLUG . '&id=' . $id );
		}

		self::redirect_with_notice(
			$redirect,
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Queue send for a ready campaign (optionally refresh filter_tag_ids from POST).
	 *
	 * @return void
	 */
	public static function handle_queue_send(): void {
		self::assert_nonce_and_cap( self::QUEUE_SEND_ACTION );

		$id = isset( $_REQUEST['campaign_id'] ) ? absint( wp_unslash( $_REQUEST['campaign_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id > 0 && isset( $_POST['filter_tag_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$filter_tag_ids = self::read_filter_tag_ids_from_post();
			( new CampaignRepository() )->update(
				$id,
				array( 'filter_tag_ids' => CampaignRepository::encode_filter_tag_ids( $filter_tag_ids ) )
			);
		}

		$queue  = new QueueService(
			new CampaignRepository(),
			new SendJobRepository(),
			new SubscriberRepository()
		);
		$result = $queue->enqueue_campaign( $id );

		$redirect = admin_url( 'admin.php?page=' . Menu::QUEUE_SLUG . ( $id > 0 ? '&campaign_id=' . $id : '' ) );

		self::redirect_with_notice(
			$redirect,
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Bulk unsubscribe subscribers (admin-post fallback / form POST).
	 *
	 * @return void
	 */
	public static function handle_bulk_unsubscribe(): void {
		self::assert_nonce_and_cap( self::BULK_UNSUBSCRIBE_ACTION );

		$ids = array();
		// Nonce verified in assert_nonce_and_cap() above.
		if ( isset( $_POST['subscriber_ids'] ) && is_array( $_POST['subscriber_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint mapped; nonce checked above.
			$ids = array_map( 'absint', wp_unslash( $_POST['subscriber_ids'] ) );
		}

		$repo  = new SubscriberRepository();
		$count = 0;
		foreach ( $ids as $id ) {
			if ( $id > 0 && $repo->mark_unsubscribed( $id ) ) {
				++$count;
			}
		}

		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::SUBSCRIBERS_SLUG ),
			'success',
			sprintf(
				/* translators: %d: number of subscribers */
				_n( '%d subscriber unsubscribed.', '%d subscribers unsubscribed.', $count, 'wp-resend-newsletter' ),
				$count
			)
		);
	}

	/**
	 * Create tag.
	 *
	 * @return void
	 */
	public static function handle_create_tag(): void {
		self::assert_nonce_and_cap( self::CREATE_TAG_ACTION );
		$name   = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = self::tag_service()->create( $name );
		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::TAGS_SLUG ),
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Rename tag.
	 *
	 * @return void
	 */
	public static function handle_rename_tag(): void {
		self::assert_nonce_and_cap( self::RENAME_TAG_ACTION );
		$id     = isset( $_POST['tag_id'] ) ? absint( wp_unslash( $_POST['tag_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name   = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = self::tag_service()->rename( $id, $name );
		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::TAGS_SLUG ),
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Delete tag.
	 *
	 * @return void
	 */
	public static function handle_delete_tag(): void {
		self::assert_nonce_and_cap( self::DELETE_TAG_ACTION );
		$id     = isset( $_POST['tag_id'] ) ? absint( wp_unslash( $_POST['tag_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = self::tag_service()->delete( $id );
		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::TAGS_SLUG ),
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Bulk add tag to selected subscribers.
	 *
	 * @return void
	 */
	public static function handle_bulk_add_tag(): void {
		self::assert_nonce_and_cap( self::BULK_ADD_TAG_ACTION );
		$tag_id = isset( $_POST['tag_id'] ) ? absint( wp_unslash( $_POST['tag_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids    = array();
		if ( isset( $_POST['subscriber_ids'] ) && is_array( $_POST['subscriber_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ids = array_map( 'absint', wp_unslash( $_POST['subscriber_ids'] ) );
		}
		$result = self::tag_service()->bulk_assign( $ids, $tag_id );
		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::SUBSCRIBERS_SLUG ),
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Bulk remove tag from selected subscribers.
	 *
	 * @return void
	 */
	public static function handle_bulk_remove_tag(): void {
		self::assert_nonce_and_cap( self::BULK_REMOVE_TAG_ACTION );
		$tag_id = isset( $_POST['tag_id'] ) ? absint( wp_unslash( $_POST['tag_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids    = array();
		if ( isset( $_POST['subscriber_ids'] ) && is_array( $_POST['subscriber_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ids = array_map( 'absint', wp_unslash( $_POST['subscriber_ids'] ) );
		}
		$result = self::tag_service()->bulk_remove( $ids, $tag_id );
		self::redirect_with_notice(
			admin_url( 'admin.php?page=' . Menu::SUBSCRIBERS_SLUG ),
			! empty( $result['ok'] ) ? 'success' : 'error',
			(string) $result['message']
		);
	}

	/**
	 * Verify nonce + manage_options or wp_die 403.
	 *
	 * @param string $action Nonce/action name.
	 * @return void
	 */
	public static function assert_nonce_and_cap( string $action ): void {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die(
				esc_html__( 'Invalid nonce.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Read filter_tag_ids[] from POST.
	 *
	 * @return list<int>
	 */
	private static function read_filter_tag_ids_from_post(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified nonce.
		if ( ! isset( $_POST['filter_tag_ids'] ) || ! is_array( $_POST['filter_tag_ids'] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['filter_tag_ids'] ) ) ) ) );
	}

	/**
	 * TagService factory.
	 *
	 * @return TagService
	 */
	private static function tag_service(): TagService {
		return new TagService(
			new TagRepository(),
			new SubscriberTagRepository(),
			new SubscriberRepository()
		);
	}

	/**
	 * Redirect with a flash query arg notice.
	 *
	 * @param string $url     Destination.
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @return void
	 */
	private static function redirect_with_notice( string $url, string $type, string $message ): void {
		// add_query_arg() encodes values — do not rawurlencode (double-encoding).
		wp_safe_redirect(
			add_query_arg(
				array(
					'wprn_notice' => $message,
					'wprn_type'   => $type,
				),
				$url
			)
		);
		exit;
	}
}
