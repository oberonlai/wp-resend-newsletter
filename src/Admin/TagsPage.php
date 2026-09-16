<?php
/**
 * Tags admin page (CRUD).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Application\TagService;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List / create / rename / delete tags.
 */
class TagsPage {

	/**
	 * Render page.
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

		$tags_repo = new TagRepository();
		$join_repo = new SubscriberTagRepository();
		$tags      = $tags_repo->find_all();
		$tag_ids   = array();
		foreach ( $tags as $tag_row ) {
			$tag_ids[] = (int) $tag_row->id;
		}
		$counts = $join_repo->count_subscribers_for_tags( $tag_ids );

		SubscribersPage::maybe_render_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tags', 'wp-resend-newsletter' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Local subscriber tags. Used to filter campaign Broadcast audiences (AND). Not synced to Resend Topics.', 'wp-resend-newsletter' ); ?>
			</p>

			<h2><?php esc_html_e( 'Add tag', 'wp-resend-newsletter' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::CREATE_TAG_ACTION ); ?>" />
				<?php wp_nonce_field( AdminActions::CREATE_TAG_ACTION ); ?>
				<label for="wprn_tag_name" class="screen-reader-text"><?php esc_html_e( 'Tag name', 'wp-resend-newsletter' ); ?></label>
				<input type="text" id="wprn_tag_name" name="tag_name" class="regular-text" maxlength="191" required />
				<?php submit_button( __( 'Add Tag', 'wp-resend-newsletter' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Existing tags', 'wp-resend-newsletter' ); ?></h2>
			<?php if ( array() === $tags ) : ?>
				<p><?php esc_html_e( 'No tags yet.', 'wp-resend-newsletter' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Slug', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subscribers', 'wp-resend-newsletter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'wp-resend-newsletter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tags as $tag ) : ?>
							<?php
							$tag_id = (int) $tag->id;
							$count  = $counts[ $tag_id ] ?? 0;
							?>
							<tr>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:0.5em;align-items:center;">
										<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::RENAME_TAG_ACTION ); ?>" />
										<input type="hidden" name="tag_id" value="<?php echo esc_attr( (string) $tag_id ); ?>" />
										<?php wp_nonce_field( AdminActions::RENAME_TAG_ACTION ); ?>
										<input type="text" name="tag_name" value="<?php echo esc_attr( (string) $tag->name ); ?>" maxlength="191" required />
										<?php submit_button( __( 'Rename', 'wp-resend-newsletter' ), 'small', 'submit', false ); ?>
									</form>
								</td>
								<td><code><?php echo esc_html( (string) $tag->slug ); ?></code></td>
								<td><?php echo esc_html( (string) $count ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this tag? Assignments will be removed; subscribers stay.', 'wp-resend-newsletter' ) ); ?>');">
										<input type="hidden" name="action" value="<?php echo esc_attr( AdminActions::DELETE_TAG_ACTION ); ?>" />
										<input type="hidden" name="tag_id" value="<?php echo esc_attr( (string) $tag_id ); ?>" />
										<?php wp_nonce_field( AdminActions::DELETE_TAG_ACTION ); ?>
										<?php submit_button( __( 'Delete', 'wp-resend-newsletter' ), 'delete small', 'submit', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build TagService with defaults.
	 *
	 * @return TagService
	 */
	public static function service(): TagService {
		return new TagService(
			new TagRepository(),
			new SubscriberTagRepository(),
			new SubscriberRepository()
		);
	}
}
