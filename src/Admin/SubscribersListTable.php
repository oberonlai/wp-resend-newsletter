<?php
/**
 * Subscribers WP_List_Table.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for subscribers.
 */
class SubscribersListTable extends \WP_List_Table {

	/**
	 * Repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $repository;

	/**
	 * Tags keyed by subscriber_id.
	 *
	 * @var array<int, list<object>>
	 */
	private array $tags_by_subscriber = array();

	/**
	 * All tags for bulk UI.
	 *
	 * @var list<object>
	 */
	private array $all_tags = array();

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository|null $repository Optional repository.
	 */
	public function __construct( ?SubscriberRepository $repository = null ) {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);
		$this->repository = $repository ?? new SubscriberRepository();
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'email'        => __( 'Email', 'wp-resend-newsletter' ),
			'status'       => __( 'Status', 'wp-resend-newsletter' ),
			'tags'         => __( 'Tags', 'wp-resend-newsletter' ),
			'created_at'   => __( 'Created', 'wp-resend-newsletter' ),
			'confirmed_at' => __( 'Confirmed', 'wp-resend-newsletter' ),
			'updated_at'   => __( 'Updated', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'email'      => array( 'email', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
			'updated_at' => array( 'updated_at', false ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->process_bulk_action();

		$per_page     = $this->get_items_per_page( 'wprn_subscribers_per_page', 20 );
		$current_page = $this->get_pagenum();
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order        = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status       = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$status_filter = ( '' !== $status && SubscriberStatus::is_valid( $status ) ) ? $status : null;

		$total_items = $this->repository->count_for_admin( $status_filter );
		$items       = $this->repository->find_for_admin(
			$status_filter,
			$per_page,
			( $current_page - 1 ) * $per_page,
			$orderby,
			$order
		);

		$this->items = $items;

		$ids = array();
		foreach ( $items as $item ) {
			$ids[] = (int) $item->id;
		}
		$this->tags_by_subscriber = ( new SubscriberTagRepository() )->find_tags_for_subscribers( $ids );
		$this->all_tags           = ( new TagRepository() )->find_all();

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
		if ( isset( $item->$column_name ) && '' !== (string) $item->$column_name ) {
			if ( in_array( $column_name, array( 'created_at', 'confirmed_at', 'updated_at' ), true ) ) {
				// Timestamps are GMT (current_time mysql true).
				return esc_html( AdminDate::format_gmt( (string) $item->$column_name ) );
			}
			return esc_html( (string) $item->$column_name );
		}
		return '—';
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="subscriber_ids[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Email column.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_email( $item ): string {
		$detail_url = admin_url( 'admin.php?page=' . Menu::SUBSCRIBER_DETAIL_SLUG . '&id=' . (int) $item->id );
		$actions    = array(
			'events' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $detail_url ),
				esc_html__( 'Recent events', 'wp-resend-newsletter' )
			),
		);
		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( (string) $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Tags column.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_tags( $item ): string {
		$sid  = (int) $item->id;
		$tags = $this->tags_by_subscriber[ $sid ] ?? array();
		if ( array() === $tags ) {
			return '—';
		}
		$names = array();
		foreach ( $tags as $tag ) {
			$names[] = esc_html( (string) $tag->name );
		}
		return implode( ', ', $names );
	}

	/**
	 * Status column.
	 *
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = (string) $item->status;
		$labels = array(
			SubscriberStatus::PENDING      => __( 'Pending', 'wp-resend-newsletter' ),
			SubscriberStatus::CONFIRMED    => __( 'Confirmed', 'wp-resend-newsletter' ),
			SubscriberStatus::UNSUBSCRIBED => __( 'Unsubscribed', 'wp-resend-newsletter' ),
			SubscriberStatus::BOUNCED      => __( 'Bounced', 'wp-resend-newsletter' ),
			SubscriberStatus::COMPLAINED   => __( 'Complained', 'wp-resend-newsletter' ),
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
		$actions = array(
			'unsubscribe' => __( 'Unsubscribe', 'wp-resend-newsletter' ),
		);
		if ( array() === $this->all_tags ) {
			$this->all_tags = ( new TagRepository() )->find_all();
		}
		if ( array() !== $this->all_tags ) {
			$actions['add_tag']    = __( 'Add tag', 'wp-resend-newsletter' );
			$actions['remove_tag'] = __( 'Remove tag', 'wp-resend-newsletter' );
		}
		return $actions;
	}

	/**
	 * Process bulk unsubscribe with nonce + capability.
	 *
	 * @return void
	 */
	public function process_bulk_action(): void {
		$action = $this->current_action();
		if ( ! in_array( $action, array( 'unsubscribe', 'add_tag', 'remove_tag' ), true ) ) {
			return;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'bulk-subscribers' ) ) {
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
		if ( isset( $_REQUEST['subscriber_ids'] ) && is_array( $_REQUEST['subscriber_ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['subscriber_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( 'unsubscribe' === $action ) {
			$count = 0;
			foreach ( $ids as $id ) {
				if ( $id > 0 && $this->repository->mark_unsubscribed( $id ) ) {
					++$count;
				}
			}
			$message = sprintf(
				/* translators: %d: number of subscribers */
				_n( '%d subscriber unsubscribed.', '%d subscribers unsubscribed.', $count, 'wp-resend-newsletter' ),
				$count
			);
		} else {
			$tag_id = isset( $_REQUEST['bulk_tag_id'] ) ? absint( wp_unslash( $_REQUEST['bulk_tag_id'] ) ) : 0;
			$svc    = TagsPage::service();
			if ( 'add_tag' === $action ) {
				$result  = $svc->bulk_assign( $ids, $tag_id );
				$message = (string) $result['message'];
				$ok      = ! empty( $result['ok'] );
			} else {
				$result  = $svc->bulk_remove( $ids, $tag_id );
				$message = (string) $result['message'];
				$ok      = ! empty( $result['ok'] );
			}
			if ( ! $ok ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => Menu::SUBSCRIBERS_SLUG,
							'wprn_notice' => $message,
							'wprn_type'   => 'error',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::SUBSCRIBERS_SLUG,
					'wprn_notice' => $message,
					'wprn_type'   => 'success',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Extra controls for bulk tag selection.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || array() === $this->all_tags ) {
			return;
		}
		echo '<div class="alignleft actions">';
		echo '<label for="wprn_bulk_tag_id" class="screen-reader-text">' . esc_html__( 'Select tag', 'wp-resend-newsletter' ) . '</label>';
		echo '<select name="bulk_tag_id" id="wprn_bulk_tag_id">';
		echo '<option value="0">' . esc_html__( 'Select tag…', 'wp-resend-newsletter' ) . '</option>';
		foreach ( $this->all_tags as $tag ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $tag->id,
				esc_html( (string) $tag->name )
			);
		}
		echo '</select>';
		echo '</div>';
	}

	/**
	 * Status filter views.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current  = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_url = admin_url( 'admin.php?page=' . Menu::SUBSCRIBERS_SLUG );
		$all      = $this->repository->count_for_admin( null );

		$views = array(
			'all' => sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $base_url ),
				( '' === $current ) ? 'current' : '',
				esc_html__( 'All', 'wp-resend-newsletter' ),
				$all
			),
		);

		$labels = array(
			SubscriberStatus::PENDING      => __( 'Pending', 'wp-resend-newsletter' ),
			SubscriberStatus::CONFIRMED    => __( 'Confirmed', 'wp-resend-newsletter' ),
			SubscriberStatus::UNSUBSCRIBED => __( 'Unsubscribed', 'wp-resend-newsletter' ),
			SubscriberStatus::BOUNCED      => __( 'Bounced', 'wp-resend-newsletter' ),
			SubscriberStatus::COMPLAINED   => __( 'Complained', 'wp-resend-newsletter' ),
		);

		foreach ( $labels as $status => $label ) {
			$count            = $this->repository->count_by_status( $status );
			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base_url ) ),
				( $status === $current ) ? 'current' : '',
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * No items message.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No subscribers found.', 'wp-resend-newsletter' );
	}
}
