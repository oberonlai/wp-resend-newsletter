<?php
/**
 * Gutenberg dynamic block: newsletter subscribe form.
 *
 * Layout follows everything-wp /frontend-page (Gutenberg Block).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Blocks\Subscribe;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `wp-resend-newsletter/subscribe`.
 */
class SubscribeBlock {

	/**
	 * Block name.
	 */
	public const BLOCK_NAME = 'wp-resend-newsletter/subscribe';

	/**
	 * Editor script handle (must match block.json editorScript).
	 */
	public const EDITOR_HANDLE = 'wprn-subscribe-editor';

	/**
	 * View (front-end) script handle (must match block.json viewScript).
	 */
	public const VIEW_HANDLE = 'wprn-subscribe-view';

	/**
	 * Register scripts and block type.
	 *
	 * @return void
	 */
	public static function register(): void {
		$version = defined( 'WP_RESEND_NEWSLETTER_VERSION' ) ? WP_RESEND_NEWSLETTER_VERSION : '0.1.0';
		$dir     = __DIR__;
		$url     = defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' )
			? WP_RESEND_NEWSLETTER_PLUGIN_URL . 'src/Blocks/Subscribe/'
			: plugins_url( '/', __FILE__ );

		$asset_file = $dir . '/editor.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: array(
				'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				'version'      => $version,
			);

		wp_register_script(
			self::EDITOR_HANDLE,
			$url . 'edit.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR_HANDLE, 'wp-resend-newsletter' );
		}

		wp_localize_script(
			self::EDITOR_HANDLE,
			'wprnSubscribeEditor',
			array(
				'letterImageUrl' => esc_url_raw( $url . 'assets/handwritten-letter.jpg' ),
			)
		);

		wp_register_script(
			self::VIEW_HANDLE,
			$url . 'view.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			self::VIEW_HANDLE,
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

		register_block_type( $dir );
	}
}
