<?php
/**
 * Subscriber detail — recent engagement events.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Application\SubscriberEngagementService;
use WpResendNewsletter\Persistence\SubscriberRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a subscriber’s recent delivery/engagement events.
 */
class SubscriberDetailPage {

	/**
	 * Render subscriber detail.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id <= 0 ) {
			wp_die(
				esc_html__( 'Subscriber not found.', 'wp-resend-newsletter' ),
				esc_html__( 'Not Found', 'wp-resend-newsletter' ),
				array( 'response' => 404 )
			);
		}

		$subscriber = ( new SubscriberRepository() )->find_by_id( $id );
		if ( null === $subscriber ) {
			wp_die(
				esc_html__( 'Subscriber not found.', 'wp-resend-newsletter' ),
				esc_html__( 'Not Found', 'wp-resend-newsletter' ),
				array( 'response' => 404 )
			);
		}

		$events = ( new SubscriberEngagementService() )->recent( $id, 20 );

		SubscribersPage::maybe_render_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscriber', 'wp-resend-newsletter' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SUBSCRIBERS_SLUG ) ); ?>">
					&larr; <?php esc_html_e( 'Back to subscribers', 'wp-resend-newsletter' ); ?>
				</a>
			</p>

			<p>
				<strong><?php echo esc_html( (string) $subscriber->email ); ?></strong>
				—
				<?php echo esc_html( (string) $subscriber->status ); ?>
			</p>

			<h2><?php esc_html_e( 'Recent events', 'wp-resend-newsletter' ); ?></h2>
			<?php if ( array() === $events ) : ?>
				<p class="description wprn-subscriber-events-empty">
					<?php esc_html_e( 'No delivery or engagement events recorded for this subscriber yet.', 'wp-resend-newsletter' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat striped wprn-subscriber-events">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Campaign', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Link', 'wp-resend-newsletter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td>
									<?php
									// created_at is GMT (current_time mysql true).
									echo esc_html( AdminDate::format_gmt( $event['created_at'] ) );
									?>
								</td>
								<td><?php echo esc_html( $event['event_type'] ); ?></td>
								<td>
									<?php
									if ( $event['campaign_id'] > 0 ) {
										$label = '' !== $event['campaign_subject']
											? $event['campaign_subject']
											: sprintf(
												/* translators: %d: campaign id */
												__( 'Campaign #%d', 'wp-resend-newsletter' ),
												$event['campaign_id']
											);
										printf(
											'<a href="%s">%s</a>',
											esc_url( admin_url( 'admin.php?page=' . Menu::CAMPAIGN_ANALYTICS_SLUG . '&id=' . $event['campaign_id'] ) ),
											esc_html( $label )
										);
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php
									if ( '' !== $event['link_url'] ) {
										echo '<code>' . esc_html( $event['link_url'] ) . '</code>';
									} else {
										echo '—';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
