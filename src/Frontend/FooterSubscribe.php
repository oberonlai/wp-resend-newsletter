<?php
/**
 * Theme footer subscribe form (oberon dark bar + yellow pill).
 *
 * Shares view.js with the Gutenberg subscribe block; enqueued site-wide so the
 * footer works even when the block is not on the page.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Frontend;

use WpResendNewsletter\Blocks\Subscribe\SubscribeBlock;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Footer / shortcode markup compatible with `[data-wprn-subscribe]` + theme CSS.
 */
class FooterSubscribe {

	/**
	 * Shortcode tag for theme/widgets: `[wprn_subscribe_footer]`.
	 */
	public const SHORTCODE = 'wprn_subscribe_footer';

	/**
	 * Style handle for subscribe card CSS (must match block.json file:./style.css).
	 */
	public const STYLE_HANDLE = 'wp-resend-newsletter-subscribe-style';

	/**
	 * Register shortcode + front-end asset enqueue.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Ensure view.js + wprnSubscribe localize + style.css on every front request.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$version = defined( 'WP_RESEND_NEWSLETTER_VERSION' ) ? WP_RESEND_NEWSLETTER_VERSION : '0.1.0';
		$url_base = defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' )
			? WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Blocks/Subscribe/'
			: plugins_url( '../Blocks/Subscribe/', __FILE__ );

		if ( ! wp_script_is( SubscribeBlock::VIEW_HANDLE, 'registered' ) ) {
			wp_register_script(
				SubscribeBlock::VIEW_HANDLE,
				$url_base . 'view.js',
				array(),
				$version,
				true
			);
			wp_localize_script(
				SubscribeBlock::VIEW_HANDLE,
				'wprnSubscribe',
				array(
					'restUrl' => esc_url_raw( rest_url( 'wprn/v1/subscribers' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'submitting' => __( 'Submitting…', 'wp-resend-newsletter' ),
						'error'      => __( 'Something went wrong. Please try again.', 'wp-resend-newsletter' ),
						'invalid'    => __( 'Please provide a valid email address.', 'wp-resend-newsletter' ),
					),
				)
			);
		}

		wp_enqueue_script( SubscribeBlock::VIEW_HANDLE );

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				$url_base . 'style.css',
				array(),
				$version
			);
		}

		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes (unused).
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return self::get_html();
	}

	/**
	 * Echo footer form markup (theme-callable).
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_html() escapes.
		echo self::get_html();
	}

	/**
	 * Build markup matching theme classes + view.js selectors.
	 *
	 * @return string
	 */
	public static function get_html(): string {
		ob_start();
		?>
<div class="wprn-subscribe oberon-newsletter-embed oberon-subscribe" data-wprn-subscribe="1">
	<form class="wprn-subscribe__form" method="post" action="#" novalidate="novalidate">
		<input
			class="wprn-subscribe__input"
			type="email"
			name="email"
			placeholder="<?php echo esc_attr__( '你的電子郵件', 'wp-resend-newsletter' ); ?>"
			required="required"
			autocomplete="email"
			aria-label="<?php echo esc_attr__( 'Email', 'wp-resend-newsletter' ); ?>"
		/>
		<button class="wprn-subscribe__button btn oberon-subscribe__btn" type="submit">
			<?php echo esc_html__( '訂閱', 'wp-resend-newsletter' ); ?>
		</button>
		<p class="wprn-subscribe__message oberon-subscribe__msg" role="status" aria-live="polite" hidden="hidden"></p>
	</form>
</div>
		<?php
		return (string) ob_get_clean();
	}
}
