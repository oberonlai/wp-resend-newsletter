<?php
/**
 * Public campaign view using the active WordPress theme shell.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Frontend;

use WpResendNewsletter\Application\EmailHtmlRenderer;
use WpResendNewsletter\Domain\CampaignStatus;
use WpResendNewsletter\Persistence\CampaignRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Browser-friendly campaign body viewer inside theme header/footer.
 *
 * Pretty URL: `/newsletter/{id}/`
 * Legacy: `/?wprn_campaign={id}` (301 → pretty).
 */
class CampaignViewPage {

	/**
	 * Query var / GET key holding the campaign ID.
	 */
	public const QUERY_KEY = 'wprn_campaign';

	/**
	 * Directory path segment for public campaign URLs.
	 */
	public const PATH_SEGMENT = 'newsletter';

	/**
	 * Option storing the last flushed rewrite version.
	 */
	public const REWRITE_VERSION_OPTION = 'wprn_rewrite_version';

	/**
	 * Bump when rewrite rules change so upgrades flush without re-activate.
	 */
	public const REWRITE_VERSION = '0.1.4';

	/**
	 * Default path for the newsletter landing page (back link fallback).
	 */
	public const ARCHIVE_PATH = '/wordpress-newsletter/';

	/**
	 * Style handle for the public view.
	 */
	public const STYLE_HANDLE = 'wprn-campaign-view';

	/**
	 * Register rewrite, query var, flush check, and template_redirect.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 0 );
	}

	/**
	 * Add pretty-permalink rewrite rule.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			self::PATH_SEGMENT . '/([0-9]+)/?$',
			'index.php?' . self::QUERY_KEY . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register public query var.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_KEY;
		return $vars;
	}

	/**
	 * Flush rewrite rules once per REWRITE_VERSION (deploy without re-activate).
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites(): void {
		$stored = get_option( self::REWRITE_VERSION_OPTION, '' );
		if ( self::REWRITE_VERSION === $stored ) {
			return;
		}

		self::add_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Build the public campaign view URL.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	public static function url( int $campaign_id ): string {
		return home_url( trailingslashit( '/' . self::PATH_SEGMENT . '/' . $campaign_id ) );
	}

	/**
	 * Resolve a campaign for public view (testable; no exit).
	 *
	 * @param int                     $id   Campaign ID.
	 * @param CampaignRepository|null $repo Optional repo.
	 * @return array{ok: bool, status: int, campaign: ?object, message: string}
	 */
	public static function resolve( int $id, ?CampaignRepository $repo = null ): array {
		$repo = $repo ?? new CampaignRepository();

		if ( $id <= 0 ) {
			return array(
				'ok'       => false,
				'status'   => 404,
				'campaign' => null,
				'message'  => __( 'Newsletter not found.', 'wp-resend-newsletter' ),
			);
		}

		$campaign = $repo->find_by_id( $id );
		if ( null === $campaign || CampaignStatus::SENT !== (string) $campaign->status ) {
			return array(
				'ok'       => false,
				'status'   => 404,
				'campaign' => null,
				'message'  => __( 'Newsletter not found.', 'wp-resend-newsletter' ),
			);
		}

		return array(
			'ok'       => true,
			'status'   => 200,
			'campaign' => $campaign,
			'message'  => '',
		);
	}

	/**
	 * If campaign query present, render HTML or 404.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		$id = absint( get_query_var( self::QUERY_KEY ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only archive link.
		$legacy_query = isset( $_GET[ self::QUERY_KEY ] );
		if ( $id <= 0 && $legacy_query ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = wp_unslash( $_GET[ self::QUERY_KEY ] );
			$id  = is_scalar( $raw ) ? absint( $raw ) : 0;
		}

		if ( $id <= 0 ) {
			return;
		}

		// Legacy `/?wprn_campaign={id}` → pretty `/newsletter/{id}/`.
		if ( $legacy_query ) {
			wp_safe_redirect( self::url( $id ), 301 );
			exit;
		}

		$result = self::resolve( $id );
		if ( ! $result['ok'] || null === $result['campaign'] ) {
			self::render_not_found( $result['message'], $result['status'] );
			return;
		}

		self::render_campaign( $result['campaign'] );
	}

	/**
	 * Preferred back URL: same-host referrer, else newsletter archive path.
	 *
	 * @return string
	 */
	public static function back_url(): string {
		$fallback = home_url( self::ARCHIVE_PATH );
		$referer  = wp_get_referer();
		if ( ! is_string( $referer ) || '' === $referer ) {
			return $fallback;
		}

		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$ref_host  = wp_parse_url( $referer, PHP_URL_HOST );
		if ( is_string( $home_host ) && is_string( $ref_host ) && strtolower( $home_host ) === strtolower( $ref_host ) ) {
			return $referer;
		}

		return $fallback;
	}

	/**
	 * Format campaign created_at for display.
	 *
	 * @param object $campaign Campaign row.
	 * @return string
	 */
	public static function format_date( object $campaign ): string {
		$raw = isset( $campaign->created_at ) ? (string) $campaign->created_at : '';
		if ( '' === $raw ) {
			return '';
		}

		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return $raw;
		}

		return date_i18n( get_option( 'date_format', 'Y/m/d' ), $ts );
	}

	/**
	 * Enqueue light scoped styles for the campaign view (before get_header).
	 *
	 * @return void
	 */
	public static function enqueue_styles(): void {
		$path = WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'src/Frontend/css/campaign-view.css';
		$url  = WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Frontend/css/campaign-view.css';
		$ver  = is_readable( $path ) ? (string) filemtime( $path ) : WP_RESEND_NEWSLETTER_VERSION;

		wp_enqueue_style( self::STYLE_HANDLE, $url, array(), $ver );
	}

	/**
	 * Build campaign content markup only (no html/head; theme provides shell).
	 *
	 * @param object                  $campaign Sent campaign row.
	 * @param CampaignRepository|null $repo     Optional campaign repository.
	 * @return string
	 */
	public static function build_content_html( object $campaign, ?CampaignRepository $repo = null ): string {
		$subject     = isset( $campaign->subject ) ? (string) $campaign->subject : '';
		$date        = self::format_date( $campaign );
		$raw         = isset( $campaign->body_html ) ? (string) $campaign->body_html : '';
		$body        = wp_kses_post( EmailHtmlRenderer::for_web( $raw ) );
		$back        = self::back_url();
		$title       = '' !== $subject ? $subject : __( 'Newsletter', 'wp-resend-newsletter' );
		$campaign_id = isset( $campaign->id ) ? (int) $campaign->id : 0;
		$repo        = $repo ?? new CampaignRepository();
		$recent      = $campaign_id > 0 ? $repo->find_sent_excluding( $campaign_id, 5 ) : array();
		$subscribe   = self::build_sidebar_subscribe_html();
		$archive_url = home_url( self::ARCHIVE_PATH );

		ob_start();
		?>
<main class="wprn-campaign-view" role="main">
	<div class="wprn-campaign-view__layout">
		<article class="wprn-campaign-view__primary">
			<p class="wprn-campaign-view__back">
				<a href="<?php echo esc_url( $back ); ?>">&larr; <?php echo esc_html__( 'Back to newsletter archive', 'wp-resend-newsletter' ); ?></a>
			</p>
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== $date ) : ?>
			<p class="wprn-campaign-view__date"><?php echo esc_html( $date ); ?></p>
			<?php endif; ?>
			<div class="wprn-campaign-view__body">
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above. ?>
			</div>
		</article>
		<aside class="wprn-campaign-view__sidebar" aria-label="<?php echo esc_attr__( 'Newsletter sidebar', 'wp-resend-newsletter' ); ?>">
			<section class="wprn-campaign-view__subscribe">
				<?php echo $subscribe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block/render escapes. ?>
			</section>
			<section class="wprn-campaign-view__recent">
				<h2 class="wprn-campaign-view__recent-title"><?php echo esc_html__( 'Past newsletters', 'wp-resend-newsletter' ); ?></h2>
				<?php if ( array() === $recent ) : ?>
					<p class="wprn-campaign-view__recent-empty"><?php echo esc_html__( 'No other newsletters yet.', 'wp-resend-newsletter' ); ?></p>
				<?php else : ?>
					<ul class="wprn-campaign-view__recent-list">
						<?php foreach ( $recent as $row ) : ?>
							<?php
							$rid    = isset( $row->id ) ? (int) $row->id : 0;
							$rsubj  = isset( $row->subject ) ? (string) $row->subject : '';
							$rdate  = self::format_date( $row );
							$rtitle = '' !== $rsubj ? $rsubj : __( 'Newsletter', 'wp-resend-newsletter' );
							?>
							<li class="wprn-campaign-view__recent-item">
								<a class="wprn-campaign-view__recent-link" href="<?php echo esc_url( self::url( $rid ) ); ?>">
									<?php echo esc_html( $rtitle ); ?>
								</a>
								<?php if ( '' !== $rdate ) : ?>
									<span class="wprn-campaign-view__recent-date"><?php echo esc_html( $rdate ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="wprn-campaign-view__recent-more">
					<a href="<?php echo esc_url( $archive_url ); ?>"><?php echo esc_html__( 'View all newsletters', 'wp-resend-newsletter' ); ?></a>
				</p>
			</section>
		</aside>
	</div>
</main>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Compact subscribe block markup for the campaign sidebar (no letter image).
	 *
	 * @return string
	 */
	public static function build_sidebar_subscribe_html(): string {
		$attrs = wp_json_encode(
			array(
				'showMedia'   => false,
				'title'       => __( 'Subscribe to our newsletter', 'wp-resend-newsletter' ),
				'buttonLabel' => __( 'Subscribe', 'wp-resend-newsletter' ),
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( false === $attrs ) {
			$attrs = '{"showMedia":false}';
		}

		$html = do_blocks(
			'<!-- wp:wp-resend-newsletter/subscribe ' . $attrs . ' /-->'
		);

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Build 404 content markup only (no html/head; theme provides shell).
	 *
	 * @param string $message User-facing message.
	 * @return string
	 */
	public static function build_not_found_content_html( string $message ): string {
		$back  = self::back_url();
		$title = __( 'Newsletter not found', 'wp-resend-newsletter' );

		ob_start();
		?>
<main class="wprn-campaign-view wprn-campaign-view--404" role="main">
	<h1><?php echo esc_html( $title ); ?></h1>
	<p><?php echo esc_html( $message ); ?></p>
	<p class="wprn-campaign-view__back">
		<a href="<?php echo esc_url( $back ); ?>"><?php echo esc_html__( 'Back to newsletter archive', 'wp-resend-newsletter' ); ?></a>
	</p>
</main>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Apply document title + body class for the public view.
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
				$classes[] = 'wprn-campaign-view-page';
				return $classes;
			}
		);
	}

	/**
	 * Output campaign inside theme header/footer and exit.
	 *
	 * @param object $campaign Campaign row.
	 * @return void
	 */
	public static function render_campaign( object $campaign ): void {
		$subject = isset( $campaign->subject ) ? (string) $campaign->subject : '';
		$title   = '' !== $subject ? $subject : __( 'Newsletter', 'wp-resend-newsletter' );

		status_header( 200 );
		nocache_headers();
		self::enqueue_styles();
		self::prepare_theme_chrome( $title );

		get_header();
		echo self::build_content_html( $campaign ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
		get_footer();
		exit;
	}

	/**
	 * Output 404 inside theme header/footer and exit.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	public static function render_not_found( string $message, int $status = 404 ): void {
		$title = __( 'Newsletter not found', 'wp-resend-newsletter' );

		status_header( $status );
		nocache_headers();
		self::enqueue_styles();
		self::prepare_theme_chrome( $title );

		get_header();
		echo self::build_not_found_content_html( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
		get_footer();
		exit;
	}
}
