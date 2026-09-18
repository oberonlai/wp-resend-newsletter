<?php
/**
 * Queue status admin view.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Domain\SendJobStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SendJobRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows send job counts and recent jobs (sanitized errors).
 */
class QueuePage {

	/**
	 * Render queue screen.
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

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$jobs_repo   = new SendJobRepository();
		$filter_id   = $campaign_id > 0 ? $campaign_id : null;
		$counts      = $jobs_repo->count_grouped_by_status( $filter_id );
		$jobs        = $jobs_repo->find_for_admin( $filter_id, null, 50, 0 );

		$campaign_subject = '';
		if ( $campaign_id > 0 ) {
			$campaign = ( new CampaignRepository() )->find_by_id( $campaign_id );
			if ( null !== $campaign ) {
				$campaign_subject = (string) $campaign->subject;
			}
		}

		SubscribersPage::maybe_render_notice();

		$status_labels = array(
			SendJobStatus::PENDING    => __( 'Pending', 'wp-resend-newsletter' ),
			SendJobStatus::PROCESSING => __( 'Processing', 'wp-resend-newsletter' ),
			SendJobStatus::SENT       => __( 'Sent', 'wp-resend-newsletter' ),
			SendJobStatus::FAILED     => __( 'Failed', 'wp-resend-newsletter' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Send Queue', 'wp-resend-newsletter' ); ?></h1>

			<?php if ( $campaign_id > 0 ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: campaign id, 2: subject */
							__( 'Filtered to campaign #%1$d — %2$s', 'wp-resend-newsletter' ),
							$campaign_id,
							'' !== $campaign_subject ? $campaign_subject : __( '(unknown)', 'wp-resend-newsletter' )
						)
					);
					?>
					|
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::QUEUE_SLUG ) ); ?>">
						<?php esc_html_e( 'Show all jobs', 'wp-resend-newsletter' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Counts by status', 'wp-resend-newsletter' ); ?></h2>
			<ul class="ul-disc">
				<?php foreach ( $status_labels as $status => $label ) : ?>
					<li>
						<strong><?php echo esc_html( $label ); ?>:</strong>
						<?php echo esc_html( (string) ( $counts[ $status ] ?? 0 ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'Recent jobs', 'wp-resend-newsletter' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'wp-resend-newsletter' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'wp-resend-newsletter' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-resend-newsletter' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-resend-newsletter' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'wp-resend-newsletter' ); ?></th>
						<th><?php esc_html_e( 'Last error', 'wp-resend-newsletter' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'wp-resend-newsletter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $jobs ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No jobs found.', 'wp-resend-newsletter' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $jobs as $job ) : ?>
							<?php
							$raw_error  = isset( $job->last_error ) ? (string) $job->last_error : '';
							$safe_error = ErrorSanitizer::for_display( $raw_error );
							?>
							<tr>
								<td><?php echo esc_html( (string) $job->id ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::QUEUE_SLUG . '&campaign_id=' . (int) $job->campaign_id ) ); ?>">
										#<?php echo esc_html( (string) $job->campaign_id ); ?>
									</a>
								</td>
								<td><?php echo esc_html( (string) $job->job_type ); ?></td>
								<td><?php echo esc_html( (string) $job->status ); ?></td>
								<td><?php echo esc_html( (string) $job->attempts ); ?></td>
								<td>
									<?php
									echo '' !== $safe_error
										? esc_html( $safe_error )
										: '—';
									?>
								</td>
								<td>
									<?php
									// updated_at is GMT (current_time mysql true).
									echo esc_html( AdminDate::format_gmt( (string) ( $job->updated_at ?? '' ) ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
