<?php
/**
 * Campaigns WP_List_Table.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for campaigns.
 */
class CampaignsListTable extends \WP_List_Table {

	/**
	 * Repository.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository|null $repository Optional.
	 */
	public function __construct( ?CampaignRepository $repository = null ) {
		parent::__construct(
			array(
				'singular' => 'campaign',
				'plural'   => 'campaigns',
				'ajax'     => false,
			)
		);
		$this->repository = $repository ?? new CampaignRepository();
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'subject'    => __( 'Subject', 'wp-resend-newsletter' ),
			'status'     => __( 'Status', 'wp-resend-newsletter' ),
			'created_at' => __( 'Created', 'wp-resend-newsletter' ),
			'updated_at' => __( 'Updated', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns               = $this->get_columns();
		$this->_column_headers = array( $columns, array(), array() );

		$this->process_bulk_action();

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = $this->repository->count_all();
		$this->items  = $this->repository->find_all( $per_page, ( $current_page - 1 ) * $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Default column.
	 *
	 * @param object $item        Item.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		if ( in_array( $column_name, array( 'created_at', 'updated_at' ), true ) ) {
			// created_at / updated_at are GMT (current_time mysql true).
			return esc_html( AdminDate::format_gmt( (string) ( $item->$column_name ?? '' ) ) );
		}
		if ( isset( $item->$column_name ) ) {
			return esc_html( (string) $item->$column_name );
		}
		return '';
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="campaign_ids[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Subject with row actions.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_subject( $item ): string {
		$edit_url  = admin_url( 'admin.php?page=' . Menu::CAMPAIGN_EDIT_SLUG . '&id=' . (int) $item->id );
		$queue_url = admin_url( 'admin.php?page=' . Menu::QUEUE_SLUG . '&campaign_id=' . (int) $item->id );

		$actions = array(
			'edit'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'wp-resend-newsletter' )
			),
			'queue' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $queue_url ),
				esc_html__( 'View queue', 'wp-resend-newsletter' )
			),
		);

		if ( CampaignAnalyticsPage::is_analytics_status( (string) $item->status ) ) {
			$analytics_url        = admin_url( 'admin.php?page=' . Menu::CAMPAIGN_ANALYTICS_SLUG . '&id=' . (int) $item->id );
			$actions['analytics'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $analytics_url ),
				esc_html__( 'Analytics', 'wp-resend-newsletter' )
			);
		}

		if ( CampaignStatus::READY === (string) $item->status ) {
			$actions['queue_send'] = self::queue_send_link( (int) $item->id );
		}

		$delete_url         = wp_nonce_url(
			admin_url(
				sprintf(
					'admin.php?page=%s&action=delete&campaign_id=%d',
					Menu::CAMPAIGNS_SLUG,
					(int) $item->id
				)
			),
			'bulk-campaigns'
		);
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm( %s );">%s</a>',
			esc_url( $delete_url ),
			wp_json_encode( __( 'Are you sure you want to delete this campaign?', 'wp-resend-newsletter' ) ),
			esc_html__( 'Delete', 'wp-resend-newsletter' )
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( (string) $item->subject ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Status badge.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = (string) $item->status;
		$labels = array(
			CampaignStatus::DRAFT     => __( 'Draft', 'wp-resend-newsletter' ),
			CampaignStatus::READY     => __( 'Ready', 'wp-resend-newsletter' ),
			CampaignStatus::SCHEDULED => __( 'Scheduled', 'wp-resend-newsletter' ),
			CampaignStatus::SENDING   => __( 'Sending', 'wp-resend-newsletter' ),
			CampaignStatus::SENT      => __( 'Sent', 'wp-resend-newsletter' ),
			CampaignStatus::CANCELLED => __( 'Cancelled', 'wp-resend-newsletter' ),
		);
		$label  = $labels[ $status ] ?? $status;
		return sprintf(
			'<span class="wprn-status wprn-status-%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Process bulk / row delete with nonce + capability.
	 *
	 * @return void
	 */
	public function process_bulk_action(): void {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'bulk-campaigns' ) ) {
			wp_die(
				esc_html__( 'Invalid nonce.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		$ids = array();
		if ( isset( $_REQUEST['campaign_ids'] ) && is_array( $_REQUEST['campaign_ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['campaign_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_REQUEST['campaign_id'] ) ) {
			$ids = array( absint( wp_unslash( $_REQUEST['campaign_id'] ) ) );
		}

		$jobs  = new SendJobRepository();
		$count = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$jobs->delete_by_campaign( $id );
			if ( $this->repository->delete( $id ) ) {
				++$count;
			}
		}

		$message = sprintf(
			/* translators: %d: number of campaigns deleted */
			_n( 'Deleted %d campaign.', 'Deleted %d campaigns.', $count, 'wp-resend-newsletter' ),
			$count
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::CAMPAIGNS_SLUG,
					'wprn_notice' => $message,
					'wprn_type'   => 'success',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Queue-send row action as nonce URL (avoids nested forms inside bulk list form).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	public static function queue_send_link( int $campaign_id ): string {
		$url = wp_nonce_url(
			admin_url(
				sprintf(
					'admin-post.php?action=%s&campaign_id=%d',
					AdminActions::QUEUE_SEND_ACTION,
					$campaign_id
				)
			),
			AdminActions::QUEUE_SEND_ACTION
		);
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Queue send', 'wp-resend-newsletter' )
		);
	}

	/**
	 * No items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No campaigns found.', 'wp-resend-newsletter' );
	}
}
