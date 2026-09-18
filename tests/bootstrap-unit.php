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

/**
 * Shared state for WP-Cron stubs in unit tests.
 *
 * @var array{next: int|false, scheduled: list<array<string, mixed>>, can: bool}
 */
$GLOBALS['wprn_test_cron'] = array(
	'next'      => false,
	'scheduled' => array(),
	'can'       => true,
);

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Stub wp_next_scheduled.
	 *
	 * @param string $hook Hook name.
	 * @return int|false
	 */
	function wp_next_scheduled( string $hook ) {
		unset( $hook );
		return $GLOBALS['wprn_test_cron']['next'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Stub wp_schedule_event — records calls for assertions.
	 *
	 * @param int                  $timestamp  Timestamp.
	 * @param string               $recurrence Recurrence key.
	 * @param string               $hook       Hook name.
	 * @param array<string, mixed> $args       Args.
	 * @return bool
	 */
	function wp_schedule_event( $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$GLOBALS['wprn_test_cron']['scheduled'][] = array(
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
			'hook'       => $hook,
			'args'       => $args,
		);
		$GLOBALS['wprn_test_cron']['next'] = (int) $timestamp;
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub current_user_can.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	function current_user_can( string $cap ): bool {
		unset( $cap );
		return ! empty( $GLOBALS['wprn_test_cron']['can'] );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Stub current_time.
	 *
	 * @param string    $type Type.
	 * @param int|bool  $gmt  GMT flag.
	 * @return string
	 */
	function current_time( string $type, $gmt = 0 ): string {
		unset( $type, $gmt );
		return '2026-09-18 00:00:00';
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Stub delete_option.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		unset( $option );
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub do_action.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Args.
	 * @return void
	 */
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub wp_json_encode.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
