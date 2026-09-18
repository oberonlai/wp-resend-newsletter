<?php
/**
 * Admin datetime formatting helpers.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats plugin-stored GMT MySQL datetimes for wp-admin display.
 *
 * Campaign / subscriber / queue / delivery timestamps are written with
 * current_time( 'mysql', true ) (GMT). Use get_date_from_gmt() so lists
 * show the site timezone (e.g. Asia/Taipei), not raw GMT.
 */
final class AdminDate {

	/**
	 * Format a GMT MySQL datetime using the site date + time options.
	 *
	 * @param string $gmt_mysql GMT datetime from the database (Y-m-d H:i:s).
	 * @param string $empty     Placeholder when the value is empty.
	 * @return string
	 */
	public static function format_gmt( string $gmt_mysql, string $empty = '—' ): string {
		if ( '' === $gmt_mysql ) {
			return $empty;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$local  = get_date_from_gmt( $gmt_mysql, $format );

		return '' !== $local ? $local : $empty;
	}
}
