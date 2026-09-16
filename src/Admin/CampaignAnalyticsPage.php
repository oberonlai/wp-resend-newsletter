<?php
/**
 * Campaign engagement analytics admin view.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Application\CampaignAnalyticsService;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Persistence\CampaignRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows unique + total opens/clicks for a campaign.
 */
class CampaignAnalyticsPage {

	/**
	 * Render analytics panel.
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
				esc_html__( 'Campaign not found.', 'wp-resend-newsletter' ),
				esc_html__( 'Not Found', 'wp-resend-newsletter' ),
				array( 'response' => 404 )
			);
		}

		$campaign = ( new CampaignRepository() )->find_by_id( $id );
		if ( null === $campaign ) {
			wp_die(
				esc_html__( 'Campaign not found.', 'wp-resend-newsletter' ),
				esc_html__( 'Not Found', 'wp-resend-newsletter' ),
				array( 'response' => 404 )
			);
		}

		$stats = ( new CampaignAnalyticsService() )->summarize( $id );
		$empty = 0 === $stats['opens_total'] && 0 === $stats['clicks_total'];

		SubscribersPage::maybe_render_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Campaign Analytics', 'wp-resend-newsletter' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::CAMPAIGNS_SLUG ) ); ?>">
					&larr; <?php esc_html_e( 'Back to campaigns', 'wp-resend-newsletter' ); ?>
				</a>
				|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::CAMPAIGN_EDIT_SLUG . '&id=' . $id ) ); ?>">
					<?php esc_html_e( 'Edit campaign', 'wp-resend-newsletter' ); ?>
				</a>
				|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::QUEUE_SLUG . '&campaign_id=' . $id ) ); ?>">
					<?php esc_html_e( 'View queue', 'wp-resend-newsletter' ); ?>
				</a>
			</p>

			<p>
				<strong><?php echo esc_html( (string) $campaign->subject ); ?></strong>
				—
				<?php echo esc_html( (string) $campaign->status ); ?>
			</p>

			<table class="widefat striped wprn-campaign-analytics" style="max-width:40em;">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Metric', 'wp-resend-newsletter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Total', 'wp-resend-newsletter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Unique', 'wp-resend-newsletter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Opens', 'wp-resend-newsletter' ); ?></td>
						<td class="wprn-opens-total"><?php echo esc_html( (string) $stats['opens_total'] ); ?></td>
						<td class="wprn-opens-unique"><?php echo esc_html( (string) $stats['opens_unique'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Clicks', 'wp-resend-newsletter' ); ?></td>
						<td class="wprn-clicks-total"><?php echo esc_html( (string) $stats['clicks_total'] ); ?></td>
						<td class="wprn-clicks-unique"><?php echo esc_html( (string) $stats['clicks_unique'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $empty ) : ?>
				<p class="description wprn-analytics-empty">
					<?php esc_html_e( 'No open or click events yet. Enable open and click tracking on your Resend sending domain, and subscribe the webhook to email.opened and email.clicked.', 'wp-resend-newsletter' ); ?>
				</p>
			<?php elseif ( 'kit' === $source || 'mixed' === $source ) : ?>
				<p class="description wprn-analytics-kit-note">
					<?php esc_html_e( 'Includes historical Kit (ConvertKit) open/click aggregates imported for this campaign.', 'wp-resend-newsletter' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( array() !== $stats['top_links'] ) : ?>
				<h2><?php esc_html_e( 'Top clicked links', 'wp-resend-newsletter' ); ?></h2>
				<table class="widefat striped wprn-top-links" style="max-width:60em;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'URL', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Clicks', 'wp-resend-newsletter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['top_links'] as $link ) : ?>
							<tr>
								<td><code><?php echo esc_html( $link['link_url'] ); ?></code></td>
								<td><?php echo esc_html( (string) $link['clicks'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether analytics link should show for a campaign status.
	 *
	 * @param string $status Campaign status.
	 * @return bool
	 */
	public static function is_analytics_status( string $status ): bool {
		return in_array(
			$status,
			array( CampaignStatus::SENT, CampaignStatus::SENDING ),
			true
		);
	}
}
