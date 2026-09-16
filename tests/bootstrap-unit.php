<?php
/**
 * Unit-test bootstrap (no WordPress).
 *
 * @package Wp_Resend_Newsletter
 */

define( 'ABSPATH', '/tmp/' );

if ( ! defined( 'WP_RESEND_NEWSLETTER_PLUGIN_DIR' ) ) {
	define( 'WP_RESEND_NEWSLETTER_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' ) ) {
	define( 'WP_RESEND_NEWSLETTER_PLUGIN_URL', 'https://example.test/wp-content/plugins/wp-resend-newsletter/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal i18n stub for unit tests without WordPress.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.domainFound
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Minimal esc_html__ stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.domainFound
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Minimal esc_html stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Minimal esc_attr stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Minimal esc_url stub.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Minimal is_email stub.
	 *
	 * @param mixed $email Email.
	 * @return string|false
	 */
	function is_email( $email ) {
		if ( ! is_string( $email ) ) {
			return false;
		}
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Pass-through apply_filters stub.
	 *
	 * @param string $hook     Hook name.
	 * @param mixed  $value    Value.
	 * @param mixed  ...$args Extra args.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $hook, $args );
		return $value;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Minimal get_bloginfo stub.
	 *
	 * @param string $show Info key.
	 * @return string
	 */
	function get_bloginfo( string $show = '' ): string {
		$map = array(
			'name' => 'WP 開發日常',
			'url'  => 'https://oberonlai.blog',
		);
		return $map[ $show ] ?? '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal get_option stub.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		if ( 'admin_email' === $option ) {
			return 'm615926@gmail.com';
		}
		return $default;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Minimal home_url stub.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		$base = 'https://oberonlai.blog';
		if ( '' === $path || '/' === $path ) {
			return $base . '/';
		}
		return $base . '/' . ltrim( $path, '/' );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/tests/Support/WebhookSigning.php';

if ( ! defined( 'WPRN_RESEND_BATCH_MAX' ) ) {
	define( 'WPRN_RESEND_BATCH_MAX', 50 );
}
