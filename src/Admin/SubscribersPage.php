<?php
/**
 * Subscribers admin page.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the subscribers list table screen.
 */
class SubscribersPage {

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

		$list_table = new SubscribersListTable();
		$list_table->prepare_items();

		self::maybe_render_notice();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'wp-resend-newsletter' ); ?></h1>
			<hr class="wp-header-end">
			<?php $list_table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SUBSCRIBERS_SLUG ); ?>" />
				<?php
				if ( isset( $_REQUEST['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
				}
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Flash notice from query args.
	 *
	 * @return void
	 */
	public static function maybe_render_notice(): void {
		if ( ! isset( $_GET['wprn_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$message = sanitize_text_field( wp_unslash( $_GET['wprn_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type    = isset( $_GET['wprn_type'] ) ? sanitize_text_field( wp_unslash( $_GET['wprn_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class   = ( 'error' === $type ) ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
