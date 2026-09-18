<?php
/**
 * Email-ready HTML: brand shell, shared canvas tokens, inline critical CSS.
 *
 * Honest limit: targets Gmail / Apple Mail; not pixel-perfect across all clients.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Infrastructure\ResendClient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders block-editor inner HTML into a Resend-safe branded email fragment.
 */
class EmailHtmlRenderer {

	/**
	 * Marker class on the outer email table shell.
	 */
	public const SHELL_CLASS = 'wprn-email-shell';

	/**
	 * Marker class on the content body cell (editor unwrap target).
	 */
	public const BODY_CLASS = 'wprn-email-body';

	/**
	 * Marker class on the brand footer cell.
	 */
	public const FOOTER_CLASS = 'wprn-email-footer';

	/**
	 * Marker class on the brand header cell.
	 */
	public const HEADER_CLASS = 'wprn-email-header';

	/**
	 * Content column max width (px) — article-like ~640.
	 */
	public const CONTENT_WIDTH = 640;

	/**
	 * Relative path to bundled logo PNG (email-safe; SVG often breaks).
	 */
	public const LOGO_RELATIVE = 'src/Assets/logo.png';

	/**
	 * Shared design tokens (editor canvas + inliner + brand shell).
	 *
	 * @return array{
	 *   font_family: string,
	 *   font_size: string,
	 *   line_height: string,
	 *   color: string,
	 *   heading_color: string,
	 *   link_color: string,
	 *   button_bg: string,
	 *   button_color: string,
	 *   quote_border: string,
	 *   quote_color: string,
	 *   separator_color: string,
	 *   page_bg: string,
	 *   card_bg: string,
	 *   muted_color: string,
	 *   accent_color: string,
	 *   content_width: int
	 * }
	 */
	public static function tokens(): array {
		return array(
			'font_family'     => '"Noto Sans TC", "PingFang TC", "Microsoft JhengHei", Arial, Helvetica, sans-serif',
			'font_size'       => '17px',
			'line_height'     => '1.7',
			'color'           => '#293132',
			'heading_color'   => '#293132',
			'link_color'      => '#3a5f66',
			'button_bg'       => '#293132',
			'button_color'    => '#ffffff',
			'quote_border'    => '#a0b2b9',
			'quote_color'     => '#4a5556',
			'separator_color' => '#e5e7e7',
			'page_bg'         => '#f6f6f6',
			'card_bg'         => '#ffffff',
			'muted_color'     => '#757575',
			'accent_color'    => '#FFDC73',
			'content_width'   => self::CONTENT_WIDTH,
		);
	}

	/**
	 * CSS rules keyed by element/selector used for inlining.
	 *
	 * @return array<string, string> selector => css declarations (no braces).
	 */
	public static function inline_rules(): array {
		$t = self::tokens();

		return array(
			'p'                      => sprintf(
				'margin:0 0 1.15em;font-family:%s;font-size:%s;line-height:%s;color:%s;',
				$t['font_family'],
				$t['font_size'],
				$t['line_height'],
				$t['color']
			),
			'h1'                     => sprintf(
				'margin:0 0 0.75em;font-family:%s;font-size:28px;line-height:1.35;font-weight:700;color:%s;',
				$t['font_family'],
				$t['heading_color']
			),
			'h2'                     => sprintf(
				'margin:1.4em 0 0.65em;font-family:%s;font-size:22px;line-height:1.4;font-weight:700;color:%s;',
				$t['font_family'],
				$t['heading_color']
			),
			'h3'                     => sprintf(
				'margin:1.2em 0 0.55em;font-family:%s;font-size:18px;line-height:1.4;font-weight:700;color:%s;',
				$t['font_family'],
				$t['heading_color']
			),
			'ul'                     => sprintf(
				'margin:0 0 1.15em;padding-left:1.4em;font-family:%s;font-size:%s;line-height:%s;color:%s;',
				$t['font_family'],
				$t['font_size'],
				$t['line_height'],
				$t['color']
			),
			'ol'                     => sprintf(
				'margin:0 0 1.15em;padding-left:1.4em;font-family:%s;font-size:%s;line-height:%s;color:%s;',
				$t['font_family'],
				$t['font_size'],
				$t['line_height'],
				$t['color']
			),
			'li'                     => sprintf(
				'margin:0 0 0.4em;font-family:%s;font-size:%s;line-height:%s;color:%s;',
				$t['font_family'],
				$t['font_size'],
				$t['line_height'],
				$t['color']
			),
			'blockquote'             => sprintf(
				'margin:0 0 1.15em;padding:0.5em 0 0.5em 1em;border-left:4px solid %s;font-family:%s;font-size:%s;line-height:%s;color:%s;',
				$t['quote_border'],
				$t['font_family'],
				$t['font_size'],
				$t['line_height'],
				$t['quote_color']
			),
			'hr'                     => sprintf(
				'border:none;border-top:1px solid %s;margin:1.6em 0;',
				$t['separator_color']
			),
			'img'                    => 'max-width:100%;height:auto;display:block;margin:0 0 1.15em;',
			'a'                      => sprintf( 'color:%s;text-decoration:underline;', $t['link_color'] ),
			'.wp-block-button__link' => sprintf(
				'display:inline-block;background-color:%s;color:%s;padding:12px 24px;border-radius:4px;text-decoration:none;font-family:%s;font-size:%s;font-weight:600;line-height:1.25;',
				$t['button_bg'],
				$t['button_color'],
				$t['font_family'],
				$t['font_size']
			),
			'.wp-block-buttons'      => 'margin:0 0 1.15em;',
			'.wp-block-button'       => 'margin:0 0 0.5em;',
		);
	}

	/**
	 * Editor canvas CSS mirroring email tokens.
	 *
	 * @return string
	 */
	public static function editor_canvas_css(): string {
		$t     = self::tokens();
		$rules = self::inline_rules();

		// Post-editor writing canvas typography (not a letter card).
		// Email shell width stays CONTENT_WIDTH for inbox HTML only.
		$parts   = array();
		$parts[] = sprintf(
			'.wprn-campaign-editor-app .editor-styles-wrapper{font-family:%s;font-size:%s;line-height:%s;color:%s;box-sizing:border-box;}',
			$t['font_family'],
			$t['font_size'],
			$t['line_height'],
			$t['color']
		);

		foreach ( $rules as $selector => $decls ) {
			$parts[] = sprintf(
				'.wprn-campaign-editor-app .editor-styles-wrapper %s{%s}',
				$selector,
				$decls
			);
		}

		return implode( '', $parts );
	}

	/**
	 * Absolute URL for the email logo (bundled PNG; filterable).
	 *
	 * @return string
	 */
	public static function logo_url(): string {
		$default = '';
		if ( defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' ) ) {
			$default = WP_RESEND_NEWSLETTER_PLUGIN_URL . self::LOGO_RELATIVE;
		}

		/**
		 * Filter the newsletter email logo URL.
		 *
		 * @param string $url Logo URL (PNG preferred for email clients).
		 */
		return (string) apply_filters( 'wprn_email_logo_url', $default );
	}

	/**
	 * Footer contact lines (blog name, home URL, admin email); filterable.
	 *
	 * @return array{name: string, url: string, email: string}
	 */
	public static function footer_contact(): array {
		$name  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$url   = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		$email = function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '';

		$contact = array(
			'name'  => $name,
			'url'   => $url,
			'email' => $email,
		);

		// Hook: wprn_email_footer_contact — override name/url/email strings.
		$filtered = apply_filters( 'wprn_email_footer_contact', $contact );

		return array(
			'name'  => (string) ( $filtered['name'] ?? $contact['name'] ),
			'url'   => (string) ( $filtered['url'] ?? $contact['url'] ),
			'email' => (string) ( $filtered['email'] ?? $contact['email'] ),
		);
	}

	/**
	 * Wrap + inline critical CSS. Empty input returns empty.
	 *
	 * @param string $inner_html           Block/serialised inner HTML (or previously wrapped HTML).
	 * @param bool   $include_unsubscribe  When false, omit Resend Broadcast unsubscribe placeholder (transactional mail).
	 * @return string Email-ready HTML fragment.
	 */
	public static function render( string $inner_html, bool $include_unsubscribe = true ): string {
		$trimmed = trim( $inner_html );
		if ( '' === $trimmed ) {
			return '';
		}

		$inner = self::unwrap( $trimmed );
		$inner = trim( $inner );
		if ( '' === $inner ) {
			return '';
		}

		$inlined = self::inline_css( $inner );
		return self::wrap_shell( $inlined, $include_unsubscribe );
	}

	/**
	 * Strip the email shell if present; otherwise return HTML unchanged.
	 * Returns only the body content cell so the editor stays body-only.
	 *
	 * @param string $html Possibly wrapped HTML.
	 * @return string Inner HTML for the block editor.
	 */
	public static function unwrap( string $html ): string {
		$trimmed = trim( $html );
		if ( '' === $trimmed ) {
			return '';
		}

		if ( false === stripos( $trimmed, self::SHELL_CLASS ) ) {
			return $trimmed;
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return $trimmed;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $trimmed,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $trimmed;
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . self::SHELL_CLASS . ' ")]' );
		if ( false === $nodes || 0 === $nodes->length ) {
			return $trimmed;
		}

		$shell_node = $nodes->item( 0 );
		if ( ! $shell_node instanceof \DOMElement ) {
			return $trimmed;
		}

		// Prefer the inner content cell marked wprn-email-body (excludes header/footer).
		$body_nodes = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " ' . self::BODY_CLASS . ' ")]', $shell_node );
		$target     = ( false !== $body_nodes && $body_nodes->length > 0 ) ? $body_nodes->item( 0 ) : $shell_node;

		$inner = '';
		if ( $target instanceof \DOMNode ) {
			foreach ( iterator_to_array( $target->childNodes ) as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$inner .= $dom->saveHTML( $child );
			}
		}

		$inner = trim( (string) $inner );
		return '' !== $inner ? $inner : $trimmed;
	}

	/**
	 * Prepare stored campaign HTML for the public archive/web view.
	 *
	 * Email clients need embedded <style>; browsers must not show that CSS as text
	 * after wp_kses_post strips the tags. Also drops orphan CSS prefixes left in
	 * Kit imports when style tags were already malformed or partially removed.
	 *
	 * @param string $html Stored body HTML (may include brand shell or Kit markup).
	 * @return string Inner HTML safe to pass through wp_kses_post for display.
	 */
	public static function for_web( string $html ): string {
		$inner = self::unwrap( $html );
		$inner = self::strip_embedded_assets( $inner );
		$inner = self::strip_orphan_css_prefix( $inner );
		return trim( $inner );
	}

	/**
	 * Remove style/script elements and MSO conditional comment wrappers.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function strip_embedded_assets( string $html ): string {
		$out  = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		$html = is_string( $out ) ? $out : $html;

		$out  = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$html = is_string( $out ) ? $out : $html;

		// Drop Outlook / email conditional comments that often wrap mobile CSS.
		$out  = preg_replace( '#<!--\s*\[if[^\]]*\]>[\s\S]*?<!\s*\[endif\]\s*-->#i', '', $html );
		$html = is_string( $out ) ? $out : $html;

		return $html;
	}

	/**
	 * If CSS rules sit as bare text before the first content tag, drop that prefix.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function strip_orphan_css_prefix( string $html ): string {
		$trimmed = ltrim( $html );
		if ( '' === $trimmed ) {
			return '';
		}

		// Already starts with a real tag — nothing to do unless it's a leftover style.
		if ( 0 === strncmp( $trimmed, '<', 1 ) ) {
			return $html;
		}

		// Bare text that looks like CSS (@media / selectors with braces).
		if ( ! preg_match( '/\A[\s\S]{0,8000}?(?:@media|@font-face|@keyframes|\.[a-zA-Z_-][\w-]*\s*\{|[a-zA-Z_-][\w-]*\s*\{\s*[a-zA-Z_-]+\s*:)/', $trimmed ) ) {
			return $html;
		}

		if ( preg_match( '/<(?:p|div|table|h[1-6]|ul|ol|li|img|a|section|article|span|br|strong|em|blockquote)\b/i', $trimmed, $m, PREG_OFFSET_CAPTURE ) ) {
			return substr( $trimmed, (int) $m[0][1] );
		}

		// Entire fragment is orphan CSS with no HTML content tags.
		if ( false !== strpos( $trimmed, '{' ) && false !== strpos( $trimmed, '}' ) && ! preg_match( '/<[a-zA-Z]/', $trimmed ) ) {
			return '';
		}

		return $html;
	}

	/**
	 * Apply token styles onto matching elements via DOMDocument.
	 *
	 * @param string $html Inner HTML.
	 * @return string
	 */
	public static function inline_css( string $html ): string {
		$trimmed = trim( $html );
		if ( '' === $trimmed || ! class_exists( '\DOMDocument' ) ) {
			return $trimmed;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument( '1.0', 'UTF-8' );
		$wrapped  = '<div id="wprn-inline-root">' . $trimmed . '</div>';
		$loaded   = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $wrapped,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $trimmed;
		}

		$root = $dom->getElementById( 'wprn-inline-root' );
		if ( ! $root instanceof \DOMElement ) {
			return $trimmed;
		}

		$rules = self::inline_rules();

		// Tag rules first, then class rules (class overrides / merges).
		foreach ( array( 'p', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'hr', 'img', 'a' ) as $tag ) {
			if ( ! isset( $rules[ $tag ] ) ) {
				continue;
			}
			$elements = $root->getElementsByTagName( $tag );
			$length   = $elements->length;
			for ( $i = 0; $i < $length; $i++ ) {
				$el = $elements->item( $i );
				if ( ! $el instanceof \DOMElement ) {
					continue;
				}
				// Button links get the button rule later; skip generic `a` if button class present.
				if ( 'a' === $tag && self::element_has_class( $el, 'wp-block-button__link' ) ) {
					continue;
				}
				self::merge_style( $el, $rules[ $tag ] );
			}
		}

		foreach ( array( 'wp-block-button__link', 'wp-block-buttons', 'wp-block-button' ) as $class_name ) {
			$key = '.' . $class_name;
			if ( ! isset( $rules[ $key ] ) ) {
				continue;
			}
			$xpath = new \DOMXPath( $dom );
			$found = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class_name . ' ")]', $root );
			if ( false === $found ) {
				continue;
			}
			foreach ( $found as $el ) {
				if ( $el instanceof \DOMElement ) {
					self::merge_style( $el, $rules[ $key ] );
				}
			}
		}

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$out .= $dom->saveHTML( $child );
		}

		return trim( $out );
	}

	/**
	 * Outer centered brand table shell (header + body + footer).
	 *
	 * @param string $inner_html           Already-inlined inner HTML.
	 * @param bool   $include_unsubscribe  When false, omit Broadcast unsubscribe placeholder (transactional).
	 * @return string
	 */
	public static function wrap_shell( string $inner_html, bool $include_unsubscribe = true ): string {
		$t      = self::tokens();
		$width  = (int) $t['content_width'];
		$header = self::render_header_row( $t );
		$footer = self::render_footer_row( $t, $include_unsubscribe );
		$body   = $inner_html;
		$unsub  = ResendClient::UNSUBSCRIBE_PLACEHOLDER;

		// Ensure Broadcast placeholder exists somewhere even if footer filter strips it.
		if ( $include_unsubscribe && ! str_contains( $footer, $unsub ) && ! str_contains( $body, $unsub ) ) {
			$footer .= sprintf(
				'<p style="margin:12px 0 0;font-family:%1$s;font-size:13px;line-height:1.5;"><a href="%2$s" style="color:%3$s;text-decoration:underline;">%4$s</a></p>',
				esc_attr( $t['font_family'] ),
				$unsub,
				esc_attr( $t['muted_color'] ),
				esc_html__( '取消訂閱', 'wp-resend-newsletter' )
			);
		}

		return sprintf(
			'<table class="%1$s" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="width:100%%;background-color:%2$s;margin:0;padding:0;">'
			. '<tr><td align="center" style="padding:28px 12px;">'
			. '<table class="wprn-email-card" role="presentation" cellpadding="0" cellspacing="0" border="0" width="%3$d" style="width:%3$dpx;max-width:100%%;background-color:%4$s;margin:0 auto;border-radius:4px;">'
			. '%5$s'
			. '<tr><td class="%6$s" style="padding:8px 32px 28px;font-family:%7$s;font-size:%8$s;line-height:%9$s;color:%10$s;">%11$s</td></tr>'
			. '%12$s'
			. '</table></td></tr></table>',
			esc_attr( self::SHELL_CLASS ),
			esc_attr( $t['page_bg'] ),
			$width,
			esc_attr( $t['card_bg'] ),
			$header,
			esc_attr( self::BODY_CLASS ),
			esc_attr( $t['font_family'] ),
			esc_attr( $t['font_size'] ),
			esc_attr( $t['line_height'] ),
			esc_attr( $t['color'] ),
			$body,
			$footer
		);
	}

	/**
	 * Brand header row: text title + subtitle (left-aligned).
	 *
	 * @param array<string, mixed> $t Tokens.
	 * @return string
	 */
	private static function render_header_row( array $t ): string {
		$contact = self::footer_contact();
		$home    = '' !== $contact['url'] ? $contact['url'] : ( function_exists( 'home_url' ) ? home_url( '/' ) : '#' );
		$title   = 'WordPress 開發週報';
		$sub     = 'By Oberon Lai.';

		$header_inner = sprintf(
			'<a href="%1$s" style="text-decoration:none;color:%2$s;">'
			. '<div style="font-family:%3$s;font-size:22px;line-height:1.35;font-weight:700;color:%2$s;margin:0 0 4px;">%4$s</div>'
			. '</a>'
			. '<div style="font-family:%3$s;font-size:13px;line-height:1.4;font-weight:400;color:%5$s;margin:0;">%6$s</div>',
			esc_url( $home ),
			esc_attr( $t['heading_color'] ),
			esc_attr( $t['font_family'] ),
			esc_html( $title ),
			esc_attr( $t['muted_color'] ),
			esc_html( $sub )
		);

		return sprintf(
			'<tr><td class="%1$s" align="left" style="padding:28px 32px 12px;border-bottom:1px solid %2$s;border-left:3px solid %3$s;text-align:left;">%4$s</td></tr>',
			esc_attr( self::HEADER_CLASS ),
			esc_attr( $t['separator_color'] ),
			esc_attr( $t['accent_color'] ),
			$header_inner
		);
	}

	/**
	 * Brand footer row: optional small logo, contact, unsubscribe.
	 *
	 * @param array<string, mixed> $t                     Tokens.
	 * @param bool                 $include_unsubscribe   Include Broadcast unsub placeholder.
	 * @return string
	 */
	private static function render_footer_row( array $t, bool $include_unsubscribe = true ): string {
		$contact = self::footer_contact();
		$logo    = self::logo_url();
		$unsub   = ResendClient::UNSUBSCRIBE_PLACEHOLDER;
		$lines   = array();

		if ( '' !== $logo ) {
			$lines[] = sprintf(
				'<img src="%1$s" width="71" height="35" alt="" style="display:block;border:0;margin:0 auto 12px;max-width:71px;height:auto;" />',
				esc_url( $logo )
			);
		}

		if ( '' !== $contact['name'] ) {
			$lines[] = sprintf(
				'<p style="margin:0 0 6px;font-family:%1$s;font-size:14px;line-height:1.5;color:%2$s;font-weight:600;">%3$s</p>',
				esc_attr( $t['font_family'] ),
				esc_attr( $t['color'] ),
				esc_html( $contact['name'] )
			);
		}

		if ( '' !== $contact['url'] ) {
			$lines[] = sprintf(
				'<p style="margin:0 0 6px;font-family:%1$s;font-size:13px;line-height:1.5;"><a href="%2$s" style="color:%3$s;text-decoration:underline;">%4$s</a></p>',
				esc_attr( $t['font_family'] ),
				esc_url( $contact['url'] ),
				esc_attr( $t['link_color'] ),
				esc_html( $contact['url'] )
			);
		}

		if ( '' !== $contact['email'] && is_email( $contact['email'] ) ) {
			$lines[] = sprintf(
				'<p style="margin:0 0 6px;font-family:%1$s;font-size:13px;line-height:1.5;"><a href="mailto:%2$s" style="color:%3$s;text-decoration:underline;">%4$s</a></p>',
				esc_attr( $t['font_family'] ),
				esc_attr( $contact['email'] ),
				esc_attr( $t['link_color'] ),
				esc_html( $contact['email'] )
			);
		}

		if ( $include_unsubscribe ) {
			$lines[] = sprintf(
				'<p style="margin:14px 0 0;font-family:%1$s;font-size:13px;line-height:1.5;color:%2$s;"><a href="%3$s" style="color:%2$s;text-decoration:underline;">%4$s</a></p>',
				esc_attr( $t['font_family'] ),
				esc_attr( $t['muted_color'] ),
				$unsub,
				esc_html__( '取消訂閱', 'wp-resend-newsletter' )
			);
		}

		return sprintf(
			'<tr><td class="%1$s" align="center" style="padding:24px 32px 28px;border-top:1px solid %2$s;background-color:%3$s;">%4$s</td></tr>',
			esc_attr( self::FOOTER_CLASS ),
			esc_attr( $t['separator_color'] ),
			esc_attr( $t['page_bg'] ),
			implode( '', $lines )
		);
	}

	/**
	 * Merge CSS declarations into an element's style attribute.
	 *
	 * @param \DOMElement $el    Element.
	 * @param string      $decls Declarations ending with optional semicolon.
	 * @return void
	 */
	private static function merge_style( \DOMElement $el, string $decls ): void {
		$existing = trim( $el->getAttribute( 'style' ) );
		$decls    = trim( $decls );
		if ( '' === $decls ) {
			return;
		}
		if ( '' !== $existing && ! str_ends_with( $existing, ';' ) ) {
			$existing .= ';';
		}
		$el->setAttribute( 'style', $existing . $decls );
	}

	/**
	 * Whether an element has a CSS class.
	 *
	 * @param \DOMElement $el         Element.
	 * @param string      $class_name Class name.
	 * @return bool
	 */
	private static function element_has_class( \DOMElement $el, string $class_name ): bool {
		$attr = ' ' . preg_replace( '/\s+/', ' ', trim( $el->getAttribute( 'class' ) ) ) . ' ';
		return false !== strpos( $attr, ' ' . $class_name . ' ' );
	}
}
