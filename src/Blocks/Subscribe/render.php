<?php
/**
 * Server-side render for Newsletter Subscribe block.
 *
 * Available variables: $attributes, $content, $block.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wprn_title = ( isset( $attributes['title'] ) && '' !== (string) $attributes['title'] )
	? (string) $attributes['title']
	: __( 'Subscribe to our newsletter', 'wp-resend-newsletter' );

$wprn_button_label = ( isset( $attributes['buttonLabel'] ) && '' !== (string) $attributes['buttonLabel'] )
	? (string) $attributes['buttonLabel']
	: __( 'Subscribe', 'wp-resend-newsletter' );

$wprn_description = isset( $attributes['description'] ) ? trim( (string) $attributes['description'] ) : '';

$wprn_show_media = ! isset( $attributes['showMedia'] ) || (bool) $attributes['showMedia'];

$wprn_uid = 'wprn-sub-' . wp_unique_id();

$wprn_classes = 'wprn-subscribe';
if ( $wprn_show_media ) {
	$wprn_classes .= ' wprn-subscribe--split';
} else {
	$wprn_classes .= ' wprn-subscribe--compact';
}

$wprn_wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'               => $wprn_classes,
		'data-wprn-subscribe' => '1',
	)
);

$wprn_photo_path = __DIR__ . '/assets/handwritten-letter.jpg';
$wprn_photo_url  = defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' )
	? WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Blocks/Subscribe/assets/handwritten-letter.jpg'
	: plugins_url( 'assets/handwritten-letter.jpg', __FILE__ );

$wprn_photo_w = 1200;
$wprn_photo_h = 675;
if ( $wprn_show_media ) {
	if ( function_exists( 'wp_getimagesize' ) ) {
		$wprn_size = wp_getimagesize( $wprn_photo_path );
		if ( is_array( $wprn_size ) && isset( $wprn_size[0], $wprn_size[1] ) ) {
			$wprn_photo_w = (int) $wprn_size[0];
			$wprn_photo_h = (int) $wprn_size[1];
		}
	} elseif ( function_exists( 'getimagesize' ) && is_readable( $wprn_photo_path ) ) {
		$wprn_size = @getimagesize( $wprn_photo_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $wprn_size ) && isset( $wprn_size[0], $wprn_size[1] ) ) {
			$wprn_photo_w = (int) $wprn_size[0];
			$wprn_photo_h = (int) $wprn_size[1];
		}
	}
}
?>
<div <?php echo $wprn_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes(). ?>>
	<?php if ( $wprn_show_media ) : ?>
	<div class="wprn-subscribe__media">
		<img
			class="wprn-subscribe__photo"
			src="<?php echo esc_url( $wprn_photo_url ); ?>"
			alt="<?php echo esc_attr__( 'Handwritten letter', 'wp-resend-newsletter' ); ?>"
			width="<?php echo esc_attr( (string) $wprn_photo_w ); ?>"
			height="<?php echo esc_attr( (string) $wprn_photo_h ); ?>"
			loading="lazy"
			decoding="async"
		/>
	</div>
	<?php endif; ?>
	<div class="wprn-subscribe__body">
		<?php if ( '' !== $wprn_title ) : ?>
			<h3 class="wprn-subscribe__title"><?php echo esc_html( $wprn_title ); ?></h3>
		<?php endif; ?>
		<?php if ( '' !== $wprn_description ) : ?>
			<p class="wprn-subscribe__lead"><?php echo nl2br( esc_html( $wprn_description ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html then nl2br for safe <br>. ?></p>
		<?php endif; ?>
		<form class="wprn-subscribe__form" method="post" action="#" novalidate="novalidate">
			<div class="wprn-subscribe__row">
				<div class="wprn-subscribe__field">
					<label class="wprn-subscribe__label" for="<?php echo esc_attr( $wprn_uid ); ?>-email">
						<?php echo esc_html__( 'Email', 'wp-resend-newsletter' ); ?>
						<span class="screen-reader-text"><?php echo esc_html__( '(required)', 'wp-resend-newsletter' ); ?></span>
					</label>
					<input
						class="wprn-subscribe__input"
						type="email"
						id="<?php echo esc_attr( $wprn_uid ); ?>-email"
						name="email"
						autocomplete="email"
						required="required"
						aria-required="true"
						placeholder="<?php echo esc_attr__( 'you@example.com', 'wp-resend-newsletter' ); ?>"
					/>
				</div>
				<button class="wprn-subscribe__button" type="submit">
					<?php echo esc_html( $wprn_button_label ); ?>
				</button>
			</div>
			<p class="wprn-subscribe__message" role="status" aria-live="polite" hidden="hidden"></p>
		</form>
	</div>
</div>
