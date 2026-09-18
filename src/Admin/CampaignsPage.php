<?php
/**
 * Campaigns list + edit screens.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Application\CampaignService;
use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\CampaignRepository;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\TagRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns admin UI.
 */
class CampaignsPage {

	/**
	 * Script handle for live audience estimate on classic edit.
	 */
	public const AUDIENCE_SCRIPT_HANDLE = 'wprn-campaign-audience-estimate';

	/**
	 * Register admin hooks (assets).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue classic campaign-edit audience estimate script.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		unset( $hook_suffix );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen gate.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( Menu::CAMPAIGN_EDIT_SLUG !== $page ) {
			return;
		}

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		$script_path = WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'src/Admin/js/campaign-audience-estimate.js';
		$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : WP_RESEND_NEWSLETTER_VERSION;

		wp_enqueue_script(
			self::AUDIENCE_SCRIPT_HANDLE,
			WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Admin/js/campaign-audience-estimate.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			self::AUDIENCE_SCRIPT_HANDLE,
			'wprnAudienceEstimate',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wprn/v1/audience' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Render list.
	 *
	 * @return void
	 */
	public static function render_list(): void {
		self::assert_cap();

		$list_table = new CampaignsListTable();
		$list_table->prepare_items();

		SubscribersPage::maybe_render_notice();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'wp-resend-newsletter' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::CAMPAIGN_EDIT_SLUG ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'wp-resend-newsletter' ); ?>
			</a>
			<hr class="wp-header-end">
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::CAMPAIGNS_SLUG ); ?>" />
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render create/edit with classic wp_editor for body_html.
	 *
	 * @return void
	 */
	public static function render_edit(): void {
		self::assert_cap();

		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$repo = new CampaignRepository();
		$item = null;

		if ( $id > 0 ) {
			$item = $repo->find_by_id( $id );
			if ( null === $item ) {
				wp_die(
					esc_html__( 'Campaign not found.', 'wp-resend-newsletter' ),
					esc_html__( 'Not Found', 'wp-resend-newsletter' ),
					array( 'response' => 404 )
				);
			}
		}

		SubscribersPage::maybe_render_notice();

		$subject          = $item ? (string) $item->subject : '';
		$body_html_stored = $item ? (string) $item->body_html : '';
		$body_html        = EmailHtmlRenderer::unwrap( $body_html_stored );
		$body_text        = $item ? (string) $item->body_text : '';
		$status           = $item ? (string) $item->status : CampaignStatus::DRAFT;
		$editable         = null === $item || in_array( $status, CampaignStatus::editable(), true );

		$status_labels = array(
			CampaignStatus::DRAFT     => __( 'Draft', 'wp-resend-newsletter' ),
			CampaignStatus::READY     => __( 'Ready', 'wp-resend-newsletter' ),
			CampaignStatus::SCHEDULED => __( 'Scheduled', 'wp-resend-newsletter' ),
			CampaignStatus::SENDING   => __( 'Sending', 'wp-resend-newsletter' ),
			CampaignStatus::SENT      => __( 'Sent', 'wp-resend-newsletter' ),
			CampaignStatus::CANCELLED => __( 'Cancelled', 'wp-resend-newsletter' ),
		);
		$status_label  = $status_labels[ $status ] ?? $status;

		$all_tags         = ( new TagRepository() )->find_all();
		$selected_tag_ids = $item
			? CampaignRepository::decode_filter_tag_ids( $item->filter_tag_ids ?? null )
			: array();
		$audience_count   = ( new SubscriberRepository() )->count_confirmed_with_all_tags( $selected_tag_ids );
		$total_confirmed  = ( new SubscriberRepository() )->count_by_status( SubscriberStatus::CONFIRMED );

		$can_mark_ready = $editable && ( null === $item || CampaignStatus::DRAFT === $status || CampaignStatus::SCHEDULED === $status );

		$heading = $id > 0
			? __( 'Edit Campaign', 'wp-resend-newsletter' )
			: __( 'Add New Campaign', 'wp-resend-newsletter' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $heading ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: campaign status label */
					esc_html__( 'Status: %s', 'wp-resend-newsletter' ),
					esc_html( $status_label )
				);
				?>
			</p>

			<form
				method="post"
				id="wprn-campaign-editor-form"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			>
				<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::SAVE_CAMPAIGN_ACTION ); ?>" />
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( AdminActions::SAVE_CAMPAIGN_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wprn_campaign_subject"><?php esc_html_e( 'Subject', 'wp-resend-newsletter' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="wprn_campaign_subject"
								name="subject"
								class="large-text"
								value="<?php echo esc_attr( $subject ); ?>"
								maxlength="<?php echo esc_attr( (string) CampaignService::MAX_SUBJECT_LENGTH ); ?>"
								<?php disabled( ! $editable ); ?>
								required
								autocomplete="off"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wprn_campaign_body_html"><?php esc_html_e( 'HTML body', 'wp-resend-newsletter' ); ?></label>
						</th>
						<td>
							<?php
							if ( $editable ) {
								wp_editor(
									$body_html,
									'wprn_campaign_body_html',
									array(
										'textarea_name' => 'body_html',
										'textarea_rows' => 16,
										'media_buttons' => true,
										'teeny'         => false,
										'tinymce'       => true,
										'quicktags'     => true,
									)
								);
							} else {
								?>
								<textarea
									id="wprn_campaign_body_html"
									name="body_html"
									class="large-text code"
									rows="16"
									readonly
									disabled
								><?php echo esc_textarea( $body_html ); ?></textarea>
								<?php
							}
							?>
							<p class="description">
								<?php esc_html_e( 'Use the Text tab to paste HTML from your notes app. The brand email shell is applied on save/send.', 'wp-resend-newsletter' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wprn_campaign_body_text"><?php esc_html_e( 'Plain text body (optional)', 'wp-resend-newsletter' ); ?></label>
						</th>
						<td>
							<textarea
								id="wprn_campaign_body_text"
								name="body_text"
								class="large-text"
								rows="5"
								<?php disabled( ! $editable ); ?>
							><?php echo esc_textarea( $body_text ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Leave empty to auto-generate from the HTML body on save.', 'wp-resend-newsletter' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Send filter tags', 'wp-resend-newsletter' ); ?></th>
						<td>
							<?php if ( empty( $all_tags ) ) : ?>
								<p class="description">
									<?php esc_html_e( 'No tags yet. Create some under Resend Newsletter → Tags.', 'wp-resend-newsletter' ); ?>
								</p>
							<?php else : ?>
								<fieldset <?php disabled( ! $editable ); ?>>
									<legend class="screen-reader-text">
										<?php esc_html_e( 'Send filter tags', 'wp-resend-newsletter' ); ?>
									</legend>
									<?php foreach ( $all_tags as $tag ) : ?>
										<?php
										$tid     = (int) $tag->id;
										$checked = in_array( $tid, $selected_tag_ids, true );
										?>
										<label>
											<input
												type="checkbox"
												name="filter_tag_ids[]"
												value="<?php echo esc_attr( (string) $tid ); ?>"
												<?php checked( $checked ); ?>
												<?php disabled( ! $editable ); ?>
											/>
											<?php echo esc_html( (string) $tag->name ); ?>
										</label><br />
									<?php endforeach; ?>
								</fieldset>
								<p class="description">
									<?php esc_html_e( 'Recipients must have all selected tags (AND). Leave empty to send to all confirmed.', 'wp-resend-newsletter' ); ?>
								</p>
							<?php endif; ?>
							<p
								class="description"
								id="wprn-audience-estimate"
								data-confirmed="<?php echo esc_attr( (string) (int) $total_confirmed ); ?>"
							>
								<?php
								echo wp_kses(
									sprintf(
										/* translators: 1: matching count HTML, 2: total confirmed HTML */
										__( 'Estimated recipients: %1$s of %2$s confirmed.', 'wp-resend-newsletter' ),
										'<span class="wprn-audience-matching">' . esc_html( (string) (int) $audience_count ) . '</span>',
										'<span class="wprn-audience-confirmed">' . esc_html( (string) (int) $total_confirmed ) . '</span>'
									),
									array(
										'span' => array(
											'class' => true,
										),
									)
								);
								?>
							</p>
						</td>
					</tr>
					<?php if ( $can_mark_ready ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mark ready', 'wp-resend-newsletter' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="mark_ready" value="1" />
									<?php esc_html_e( 'Mark ready after saving (does not send).', 'wp-resend-newsletter' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php if ( $editable ) : ?>
					<p class="submit">
						<?php submit_button( __( 'Save Campaign', 'wp-resend-newsletter' ), 'primary', 'submit', false ); ?>
						<button type="submit" name="wprn_send_test" value="1" class="button button-secondary">
							<?php esc_html_e( 'Save & send test email', 'wp-resend-newsletter' ); ?>
						</button>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: site admin email */
							esc_html__( 'The test email goes to the site admin email (%s). It does not change the campaign status.', 'wp-resend-newsletter' ),
							esc_html( (string) get_option( 'admin_email' ) )
						);
						?>
					</p>
				<?php endif; ?>
			</form>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::CAMPAIGNS_SLUG ) ); ?>">
					<?php esc_html_e( '← Back to Campaigns', 'wp-resend-newsletter' ); ?>
				</a>
			</p>

			<?php if ( $item && CampaignStatus::READY === $status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::QUEUE_SEND_ACTION ); ?>" />
					<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( AdminActions::QUEUE_SEND_ACTION ); ?>
					<?php foreach ( $selected_tag_ids as $tid ) : ?>
						<input type="hidden" name="filter_tag_ids[]" value="<?php echo esc_attr( (string) $tid ); ?>" />
					<?php endforeach; ?>
					<?php submit_button( __( 'Queue send', 'wp-resend-newsletter' ), 'primary', 'submit', false ); ?>
				</form>
			<?php elseif ( $item && ( CampaignStatus::DRAFT === $status || CampaignStatus::SCHEDULED === $status ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::MARK_READY_ACTION ); ?>" />
					<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<?php wp_nonce_field( AdminActions::MARK_READY_ACTION ); ?>
					<?php submit_button( __( 'Mark ready', 'wp-resend-newsletter' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Capability gate.
	 *
	 * @return void
	 */
	private static function assert_cap(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}
	}
}
