<?php
/**
 * Public shortcode listing sent campaigns.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Frontend;

use WpResendNewsletter\Persistence\CampaignRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[wprn_archive]` — paginated list of sent campaigns.
 */
class CampaignArchiveShortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'wprn_archive';

	/**
	 * GET key for archive page number.
	 */
	public const PAGE_KEY = 'wprn_apage';

	/**
	 * Default items per page.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * Stylesheet handle.
	 */
	public const STYLE_HANDLE = 'wprn-archive';

	/**
	 * Whether styles were registered this request.
	 *
	 * @var bool
	 */
	private static bool $style_registered = false;

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/**
	 * Register + enqueue archive front-end CSS (idempotent).
	 *
	 * @return void
	 */
	public static function enqueue_styles(): void {
		if ( ! self::$style_registered ) {
			$version = defined( 'WP_RESEND_NEWSLETTER_VERSION' ) ? WP_RESEND_NEWSLETTER_VERSION : '0.1.0';
			$url     = defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' )
				? WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Frontend/css/archive.css'
				: plugins_url( 'css/archive.css', __FILE__ );

			wp_register_style( self::STYLE_HANDLE, $url, array(), $version );
			self::$style_registered = true;
		}

		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Render archive list HTML.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		self::enqueue_styles();

		$atts = shortcode_atts(
			array(
				'limit' => (string) self::DEFAULT_LIMIT,
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$limit = max( 1, min( 100, absint( $atts['limit'] ) ) );
		if ( 0 === $limit ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$page   = self::current_page();
		$offset = ( $page - 1 ) * $limit;

		$repo  = new CampaignRepository();
		$total = $repo->count_sent();
		$rows  = $repo->find_sent( $limit, $offset );

		ob_start();
		?>
<div class="wprn-archive">
		<?php if ( array() === $rows ) : ?>
	<p class="wprn-archive__empty"><?php echo esc_html__( 'No past newsletters yet.', 'wp-resend-newsletter' ); ?></p>
		<?php else : ?>
	<div class="wprn-archive__items">
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$id      = isset( $row->id ) ? (int) $row->id : 0;
				$subject = isset( $row->subject ) ? (string) $row->subject : '';
				$date    = CampaignViewPage::format_date( $row );
				$url     = CampaignViewPage::url( $id );
				?>
		<article class="wprn-archive__item">
			<a class="wprn-archive__link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $subject ); ?></a>
				<?php if ( '' !== $date ) : ?>
			<span class="wprn-archive__date"><?php echo esc_html( $date ); ?></span>
				<?php endif; ?>
		</article>
			<?php endforeach; ?>
	</div>
			<?php echo self::pagination_html( $total, $limit, $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_url/esc_html/esc_attr. ?>
		<?php endif; ?>
</div>
		<?php
		return (string) ob_get_clean();
	}


	/**
	 * Current archive page (1-based).
	 *
	 * @return int
	 */
	public static function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public pagination.
		if ( ! isset( $_GET[ self::PAGE_KEY ] ) ) {
			return 1;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw  = wp_unslash( $_GET[ self::PAGE_KEY ] );
		$page = is_scalar( $raw ) ? absint( $raw ) : 1;
		return max( 1, $page );
	}

	/**
	 * Build pagination markup with numbered pages.
	 *
	 * @param int $total Total sent count.
	 * @param int $limit Per page.
	 * @param int $page  Current page.
	 * @return string
	 */
	private static function pagination_html( int $total, int $limit, int $page ): string {
		$pages = (int) ceil( $total / max( 1, $limit ) );
		if ( $pages <= 1 ) {
			return '';
		}

		$page  = max( 1, min( $page, $pages ) );
		$base  = remove_query_arg( self::PAGE_KEY );
		$items = array();

		// Previous.
		if ( $page > 1 ) {
			$prev     = $page - 1;
			$prev_url = ( 1 === $prev ) ? $base : add_query_arg( self::PAGE_KEY, $prev, $base );
			$items[]  = sprintf(
				'<li><a class="wprn-archive__page wprn-archive__page--prev" href="%s" rel="prev">%s</a></li>',
				esc_url( $prev_url ),
				esc_html__( 'Previous', 'wp-resend-newsletter' )
			);
		}

		foreach ( self::page_numbers( $page, $pages ) as $n ) {
			if ( null === $n ) {
				$items[] = '<li><span class="wprn-archive__page wprn-archive__page--ellipsis" aria-hidden="true">&hellip;</span></li>';
				continue;
			}

			if ( $n === $page ) {
				$items[] = sprintf(
					'<li><span class="wprn-archive__page wprn-archive__page--current" aria-current="page">%d</span></li>',
					$n
				);
				continue;
			}

			$url     = ( 1 === $n ) ? $base : add_query_arg( self::PAGE_KEY, $n, $base );
			$items[] = sprintf(
				'<li><a class="wprn-archive__page" href="%s">%d</a></li>',
				esc_url( $url ),
				$n
			);
		}

		// Next.
		if ( $page < $pages ) {
			$next_url = add_query_arg( self::PAGE_KEY, $page + 1, $base );
			$items[]  = sprintf(
				'<li><a class="wprn-archive__page wprn-archive__page--next" href="%s" rel="next">%s</a></li>',
				esc_url( $next_url ),
				esc_html__( 'Next', 'wp-resend-newsletter' )
			);
		}

		return '<nav class="wprn-archive__pagination" aria-label="' . esc_attr__( 'Newsletter archive pagination', 'wp-resend-newsletter' ) . '"><ul class="wprn-archive__pagination-list">' . implode( '', $items ) . '</ul></nav>';
	}

	/**
	 * Page numbers to show (null = ellipsis). Always includes 1 and last.
	 *
	 * @param int $current Current page.
	 * @param int $total   Total pages.
	 * @return array<int, int|null>
	 */
	private static function page_numbers( int $current, int $total ): array {
		if ( $total <= 7 ) {
			return range( 1, $total );
		}

		$show = array( 1, $total, $current, $current - 1, $current + 1 );
		if ( $current <= 3 ) {
			$show = array_merge( $show, array( 2, 3, 4 ) );
		}
		if ( $current >= $total - 2 ) {
			$show = array_merge( $show, array( $total - 1, $total - 2, $total - 3 ) );
		}

		$show = array_values(
			array_unique(
				array_filter(
					$show,
					static function ( $n ) use ( $total ): bool {
						return is_int( $n ) && $n >= 1 && $n <= $total;
					}
				)
			)
		);
		sort( $show, SORT_NUMERIC );

		$out  = array();
		$prev = null;
		foreach ( $show as $n ) {
			if ( null !== $prev && $n > $prev + 1 ) {
				$out[] = null;
			}
			$out[] = $n;
			$prev  = $n;
		}

		return $out;
	}
}
