<?php
/**
 * Public HTML confirmation page for email confirm links.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Frontend;

use WpResendNewsletter\Application\ConfirmService;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Security\TokenService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Browser-friendly confirm handler inside theme header/footer.
 *
 * REST confirm stays JSON for API clients.
 *
 * Query: `/?wprn_confirm={token}`
 */
class ConfirmPage {

	/**
	 * Query var / GET key holding the raw confirm token.
	 */
	public const QUERY_KEY = 'wprn_confirm';

	/**
	 * Style handle for the public confirm page.
	 */
	public const STYLE_HANDLE = 'wprn-confirm';

	/**
	 * Hook template_redirect.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 0 );
	}

	/**
	 * Build the public confirm URL used in transactional emails.
	 *
	 * @param string $raw_token Raw confirm token.
	 * @return string
	 */
	public static function url( string $raw_token ): string {
		return add_query_arg(
			array( self::QUERY_KEY => $raw_token ),
			home_url( '/' )
		);
	}

	/**
	 * If confirm query present, run ConfirmService and print HTML.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public signed token link from email.
		if ( ! isset( $_GET[ self::QUERY_KEY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below after type check.
		$raw   = wp_unslash( $_GET[ self::QUERY_KEY ] );
		$token = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		$service = new ConfirmService( new SubscriberRepository(), new TokenService() );
		$result  = $service->confirm( $token );

		$status  = ! empty( $result['ok'] ) ? 200 : 400;
		$message = (string) $result['message'];
		$ok      = ! empty( $result['ok'] );

		self::render_html( $ok, $message, $status );
	}

	/**
	 * Enqueue scoped styles for the confirm page (before get_header).
	 *
	 * @return void
	 */
	public static function enqueue_styles(): void {
		$path = WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'src/Frontend/css/confirm.css';
		$url  = WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Frontend/css/confirm.css';
		$ver  = is_readable( $path ) ? (string) filemtime( $path ) : WP_RESEND_NEWSLETTER_VERSION;

		wp_enqueue_style( self::STYLE_HANDLE, $url, array(), $ver );
	}

	/**
	 * Build confirm content markup only (no html/head; theme provides shell).
	 *
	 * @param bool   $ok      Success.
	 * @param string $message User-facing message.
	 * @return string
	 */
	public static function build_content_html( bool $ok, string $message ): string {
		$title = $ok
			? __( 'Subscription confirmed', 'wp-resend-newsletter' )
			: __( 'Confirmation failed', 'wp-resend-newsletter' );

		$home = home_url( '/' );

		ob_start();
		?>
<main class="wprn-confirm wprn-confirm--<?php echo $ok ? 'ok' : 'err'; ?>" role="main">
	<h1><?php echo esc_html( $title ); ?></h1>
	<p><?php echo esc_html( $message ); ?></p>
	<p><a href="<?php echo esc_url( $home ); ?>"><?php echo esc_html__( 'Return to site', 'wp-resend-newsletter' ); ?></a></p>
</main>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Deprecated-compatible wrapper: content only (theme shell is applied in render_html).
	 *
	 * @param bool   $ok      Success.
	 * @param string $message User-facing message.
	 * @return string
	 */
	public static function build_html( bool $ok, string $message ): string {
		return self::build_content_html( $ok, $message );
	}

	/**
	 * Apply document title + body class for the public confirm page.
	 *
	 * @param string $title Document title.
	 * @return void
	 */
	private static function prepare_theme_chrome( string $title ): void {
		add_filter(
			'pre_get_document_title',
			static function () use ( $title ): string {
				return $title;
			},
			20
		);

		add_filter(
			'body_class',
			static function ( array $classes ): array {
				$classes[] = 'wprn-confirm-page';
				return $classes;
			}
		);
	}

	/**
	 * Output confirm content inside theme header/footer and exit.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	public static function render_html( bool $ok, string $message, int $status = 200 ): void {
		$title = $ok
			? __( 'Subscription confirmed', 'wp-resend-newsletter' )
			: __( 'Confirmation failed', 'wp-resend-newsletter' );

		status_header( $status );
		nocache_headers();
		self::enqueue_styles();
		self::prepare_theme_chrome( $title );

		get_header();
		echo self::build_content_html( $ok, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
		get_footer();
		exit;
	}
}
