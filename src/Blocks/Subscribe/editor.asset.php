<?php
/**
 * Editor script dependencies (no webpack build).
 *
 * @package WpResendNewsletter
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
	),
	'version'      => defined( 'WP_RESEND_NEWSLETTER_VERSION' ) ? WP_RESEND_NEWSLETTER_VERSION : '0.1.0',
);
