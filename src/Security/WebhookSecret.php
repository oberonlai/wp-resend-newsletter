<?php
/**
 * Resolve Resend webhook signing secret (settings and/or constant).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Security;

use WpResendNewsletter\Admin\SettingsPage;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook signing secret resolution — never log the returned value.
 */
final class WebhookSecret {

	/**
	 * Constant that overrides the stored webhook secret.
	 */
	public const CONSTANT = 'WPRN_RESEND_WEBHOOK_SECRET';

	/**
	 * Resolve the active webhook secret (constant wins over option).
	 *
	 * @return string Empty string when not configured (fail closed).
	 */
	public static function resolve(): string {
		if ( defined( self::CONSTANT ) ) {
			$value = constant( self::CONSTANT );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$options = SettingsPage::get_options();
		return (string) $options['webhook_secret'];
	}

	/**
	 * Whether a usable secret is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::resolve();
	}

	/**
	 * Whether the secret comes from the wp-config constant.
	 *
	 * @return bool
	 */
	public static function is_from_constant(): bool {
		if ( ! defined( self::CONSTANT ) ) {
			return false;
		}
		$value = constant( self::CONSTANT );
		return is_string( $value ) && '' !== $value;
	}
}
