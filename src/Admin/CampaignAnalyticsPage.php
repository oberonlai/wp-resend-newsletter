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
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows unique + total opens/clicks for a campaign, plus opener/clicker lists.
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

		$stats  = ( new CampaignAnalyticsService() )->summarize( $id );
		$empty  = 0 === $stats['opens_total'] && 0 === $stats['clicks_total'];
		$source = (string) $stats['source'];
		$tags   = ( new TagRepository() )->find_all();

		$subscriber_ids = array();
		foreach ( $stats['openers'] as $opener ) {
			$subscriber_ids[] = (int) $opener['subscriber_id'];
		}
		foreach ( $stats['clicks_by_link'] as $group ) {
			foreach ( $group['subscribers'] as $clicker ) {
				$subscriber_ids[] = (int) $clicker['subscriber_id'];
			}
		}
		$tags_by_subscriber = ( new SubscriberTagRepository() )->find_tags_for_subscribers( $subscriber_ids );

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
							<?php
							$section_id = self::link_section_id( (string) $link['link_url'] );
							?>
							<tr>
								<td>
									<a href="#<?php echo esc_attr( $section_id ); ?>">
										<code><?php echo esc_html( $link['link_url'] ); ?></code>
									</a>
								</td>
								<td><?php echo esc_html( (string) $link['clicks'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			self::render_engagement_table(
				$id,
				'openers',
				__( 'Opened by', 'wp-resend-newsletter' ),
				$stats['openers'],
				$tags,
				$tags_by_subscriber
			);
			self::render_clicks_by_link_sections(
				$id,
				$stats['clicks_by_link'],
				$tags,
				$tags_by_subscriber
			);
			?>
		</div>
		<?php
	}

	/**
	 * Stable HTML id for a per-link analytics section.
	 *
	 * @param string $link_url Stored link URL.
	 * @return string
	 */
	public static function link_section_id( string $link_url ): string {
		return 'wprn-link-' . substr( md5( $link_url ), 0, 12 );
	}

	/**
	 * Render per-link clicker sections with bulk-tag forms.
	 *
	 * @param int                                                                                                                                         $campaign_id Campaign ID.
	 * @param list<array{link_url: string, clicks: int, subscribers: list<array{subscriber_id: int, email: string, events_count: int, last_at: string}>}> $groups Groups.
	 * @param array                                                                                                                                       $tags Available tags.
	 * @param array<int, list<object>>                                                                                                                    $tags_by_subscriber Tags keyed by subscriber ID.
	 * @return void
	 */
	private static function render_clicks_by_link_sections( int $campaign_id, array $groups, array $tags, array $tags_by_subscriber ): void {
		echo '<div class="wprn-analytics-clickers-by-link">';

		if ( array() === $groups ) {
			echo '<h2>' . esc_html__( 'Clicked by link', 'wp-resend-newsletter' ) . '</h2>';
			echo '<p class="description wprn-analytics-clickers-by-link-empty">';
			echo esc_html__( 'No subscribers in this list yet.', 'wp-resend-newsletter' );
			echo '</p></div>';
			return;
		}

		foreach ( $groups as $group ) {
			$url        = (string) $group['link_url'];
			$clicks     = (int) $group['clicks'];
			$section_id = self::link_section_id( $url );
			$list_key   = 'clickers-' . substr( md5( $url ), 0, 12 );
			// Escape % in URL so sprintf does not treat query encodings as placeholders.
			$heading = sprintf(
				/* translators: 1: link URL, 2: number of clicks */
				__( 'Clicked: %1$s (%2$d clicks)', 'wp-resend-newsletter' ),
				str_replace( '%', '%%', $url ),
				$clicks
			);
			self::render_engagement_table(
				$campaign_id,
				$list_key,
				$heading,
				$group['subscribers'],
				$tags,
				$tags_by_subscriber,
				$section_id
			);
		}

		echo '</div>';
	}

	/**
	 * Render an engagement subscriber table with optional bulk-tag form.
	 *
	 * @param int                                                                                $campaign_id Campaign ID.
	 * @param string                                                                             $list_key    Form list key (openers|clickers-…).
	 * @param string                                                                             $heading     Section heading.
	 * @param list<array{subscriber_id: int, email: string, events_count: int, last_at: string}> $rows Rows.
	 * @param array                                                                              $tags Available tags.
	 * @param array<int, list<object>>                                                           $tags_by_subscriber Tags keyed by subscriber ID.
	 * @param string|null                                                                        $section_id Optional HTML id for the heading (per-link anchors).
	 * @return void
	 */
	private static function render_engagement_table(
		int $campaign_id,
		string $list_key,
		string $heading,
		array $rows,
		array $tags,
		array $tags_by_subscriber,
		?string $section_id = null
	): void {
		?>
		<h2
			<?php if ( null !== $section_id && '' !== $section_id ) : ?>
				id="<?php echo esc_attr( $section_id ); ?>"
			<?php endif; ?>
			class="wprn-analytics-<?php echo esc_attr( $list_key ); ?>-heading"
		><?php echo esc_html( $heading ); ?></h2>
		<?php if ( array() === $rows ) : ?>
			<p class="description wprn-analytics-<?php echo esc_attr( $list_key ); ?>-empty">
				<?php esc_html_e( 'No subscribers in this list yet.', 'wp-resend-newsletter' ); ?>
			</p>
			<?php
			return;
		endif;

		$form_id = 'wprn-analytics-bulk-' . $list_key;
		?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			id="<?php echo esc_attr( $form_id ); ?>"
			class="wprn-analytics-bulk-form wprn-analytics-<?php echo esc_attr( $list_key ); ?>"
		>
			<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::ANALYTICS_BULK_ADD_TAG_ACTION ); ?>" />
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>" />
			<?php wp_nonce_field( AdminActions::ANALYTICS_BULK_ADD_TAG_ACTION ); ?>

			<?php if ( array() !== $tags ) : ?>
				<div class="alignleft actions" style="margin:0.5em 0;">
					<label class="screen-reader-text" for="<?php echo esc_attr( $form_id ); ?>-tag">
						<?php esc_html_e( 'Select tag', 'wp-resend-newsletter' ); ?>
					</label>
					<select name="tag_id" id="<?php echo esc_attr( $form_id ); ?>-tag">
						<option value="0"><?php esc_html_e( 'Select tag…', 'wp-resend-newsletter' ); ?></option>
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?php echo esc_attr( (string) (int) $tag->id ); ?>">
								<?php echo esc_html( (string) $tag->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
					submit_button(
						__( 'Add tag', 'wp-resend-newsletter' ),
						'secondary',
						'submit',
						false
					);
					?>
				</div>
			<?php endif; ?>

			<table class="widefat striped wprn-analytics-<?php echo esc_attr( $list_key ); ?>-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" class="wprn-analytics-select-all" data-list="<?php echo esc_attr( $list_key ); ?>" />
						</td>
						<th scope="col"><?php esc_html_e( 'Email', 'wp-resend-newsletter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tags', 'wp-resend-newsletter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Events', 'wp-resend-newsletter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last activity', 'wp-resend-newsletter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$sid       = (int) $row['subscriber_id'];
						$detail    = admin_url( 'admin.php?page=' . Menu::SUBSCRIBER_DETAIL_SLUG . '&id=' . $sid );
						$last_disp = AdminDate::format_gmt( (string) $row['last_at'] );
						?>
						<tr>
							<th scope="row" class="check-column">
								<input
									type="checkbox"
									name="subscriber_ids[]"
									value="<?php echo esc_attr( (string) $sid ); ?>"
								/>
							</th>
							<td>
								<a href="<?php echo esc_url( $detail ); ?>">
									<?php echo esc_html( (string) $row['email'] ); ?>
								</a>
							</td>
							<td>
								<?php
								$row_tags = $tags_by_subscriber[ $sid ] ?? array();
								if ( array() === $row_tags ) {
									echo '—';
								} else {
									$names = array();
									foreach ( $row_tags as $tag ) {
										$names[] = (string) $tag->name;
									}
									echo esc_html( implode( ', ', $names ) );
								}
								?>
							</td>
							<td><?php echo esc_html( (string) (int) $row['events_count'] ); ?></td>
							<td><?php echo esc_html( $last_disp ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<script>
		(function () {
			var form = document.getElementById(<?php echo wp_json_encode( $form_id ); ?>);
			if (!form) { return; }
			var master = form.querySelector('.wprn-analytics-select-all');
			if (!master) { return; }
			master.addEventListener('change', function () {
				form.querySelectorAll('tbody input[type="checkbox"]').forEach(function (cb) {
					cb.checked = master.checked;
				});
			});
		})();
		</script>
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
