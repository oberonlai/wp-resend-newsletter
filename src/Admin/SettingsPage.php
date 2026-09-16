<?php
/**
 * Settings Page
 *
 * Handles the plugin settings page using WordPress Settings API.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

use WpResendNewsletter\Infrastructure\ResendClient;
use WpResendNewsletter\Rest\WebhooksController;
use WpResendNewsletter\Security\WebhookSecret;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SettingsPage class.
 *
 * Manages Resend credentials and from-address settings.
 */
class SettingsPage {

	/**
	 * Option group name.
	 */
	public const OPTION_GROUP = 'wprn_settings_group';

	/**
	 * Option name in wp_options.
	 */
	public const OPTION_NAME = 'wprn_settings';

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'wprn-settings';

	/**
	 * Required capability.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Clear API key admin-post action.
	 */
	public const CLEAR_KEY_ACTION = 'wprn_clear_api_key';

	/**
	 * Add the settings page under a top-level plugin menu.
	 *
	 * Menu IA is owned by {@see Menu}; this method remains as a thin
	 * backwards-compatible alias used by older callers/tests.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		Menu::add_menu_pages();
	}

	/**
	 * Register settings with WordPress Settings API.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
				'capability'        => self::CAPABILITY,
				'show_in_rest'      => false,
			)
		);

		// Keep secrets out of the autoloaded options cache on every request.
		add_action( 'add_option_' . self::OPTION_NAME, array( __CLASS__, 'disable_settings_autoload' ) );
		add_action( 'update_option_' . self::OPTION_NAME, array( __CLASS__, 'disable_settings_autoload' ) );

		add_settings_section(
			'wprn_main_section',
			__( 'Resend connection', 'wp-resend-newsletter' ),
			array( __CLASS__, 'render_section_description' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'from_email',
			__( 'From email', 'wp-resend-newsletter' ),
			array( __CLASS__, 'render_from_email_field' ),
			self::MENU_SLUG,
			'wprn_main_section',
			array(
				'label_for'   => 'wprn_from_email',
				'description' => __( 'Default From address (e.g. news@news.oberonlai.blog).', 'wp-resend-newsletter' ),
			)
		);

		add_settings_field(
			'from_name',
			__( 'From name', 'wp-resend-newsletter' ),
			array( __CLASS__, 'render_from_name_field' ),
			self::MENU_SLUG,
			'wprn_main_section',
			array(
				'label_for'   => 'wprn_from_name',
				'description' => __( 'Display name for outgoing mail.', 'wp-resend-newsletter' ),
			)
		);

		add_settings_field(
			'api_key',
			__( 'Resend API key', 'wp-resend-newsletter' ),
			array( __CLASS__, 'render_api_key_field' ),
			self::MENU_SLUG,
			'wprn_main_section',
			array(
				'label_for'   => 'wprn_api_key',
				'description' => __( 'Stored in options; never commit secrets to git. Prefer WPRN_RESEND_API_KEY in wp-config.php.', 'wp-resend-newsletter' ),
			)
		);

		add_settings_field(
			'segment_id',
			__( 'Resend segment ID', 'wp-resend-newsletter' ),
			array( __CLASS__, 'render_segment_id_field' ),
			self::MENU_SLUG,
			'wprn_main_section',
			array(
				'label_for'   => 'wprn_segment_id',
				'description' => __( 'Resend Segment used for Broadcast campaigns. Leave blank to auto-create on first send.', 'wp-resend-newsletter' ),
			)
		);

		add_settings_field(
			'webhook_secret',
			__( 'Webhook secret', 'wp-resend-newsletter' ),
			array( __CLASS__, 'render_webhook_secret_field' ),
			self::MENU_SLUG,
			'wprn_main_section',
			array(
				'label_for'   => 'wprn_webhook_secret',
				'description' => __( 'Signing secret from the Resend webhook endpoint (whsec_…). Prefer WPRN_RESEND_WEBHOOK_SECRET in wp-config.php.', 'wp-resend-newsletter' ),
			)
		);
	}

	/**
	 * Default option values (schema).
	 *
	 * @return array{from_email: string, from_name: string, api_key: string, segment_id: string, webhook_secret: string}
	 */
	public static function get_defaults(): array {
		return array(
			'from_email'     => '',
			'from_name'      => '',
			'api_key'        => '',
			'segment_id'     => '',
			'webhook_secret' => '',
		);
	}

	/**
	 * Get current option values with defaults.
	 *
	 * @return array{from_email: string, from_name: string, api_key: string, segment_id: string, webhook_secret: string}
	 */
	public static function get_options(): array {
		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, self::get_defaults() );
	}

	/**
	 * Render section description.
	 *
	 * @return void
	 */
	public static function render_section_description(): void {
		echo '<p>' . esc_html__( 'Configure the Resend API key and default from address. API keys must not be committed to version control.', 'wp-resend-newsletter' ) . '</p>';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				self::OPTION_GROUP . '_messages',
				self::OPTION_GROUP . '_message',
				__( 'Settings saved.', 'wp-resend-newsletter' ),
				'updated'
			);
		}

		if ( isset( $_GET['wprn_key_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				self::OPTION_GROUP . '_messages',
				'wprn_key_cleared',
				__( 'API key cleared.', 'wp-resend-newsletter' ),
				'updated'
			);
		}

		settings_errors( self::OPTION_GROUP . '_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'wp-resend-newsletter' ) );
				?>
			</form>
			<?php if ( ! ResendClient::is_api_key_from_constant() && '' !== self::get_options()['api_key'] ) : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1em;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_KEY_ACTION ); ?>" />
					<?php wp_nonce_field( self::CLEAR_KEY_ACTION ); ?>
					<?php submit_button( __( 'Clear stored API key', 'wp-resend-newsletter' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param mixed $input Raw input values.
	 * @return array{from_email: string, from_name: string, api_key: string, segment_id: string, webhook_secret: string}
	 */
	public static function sanitize_settings( $input ): array {
		// Defense in depth: options.php also checks capability; block direct calls.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::get_options();
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$current   = self::get_options();
		$sanitized = self::get_defaults();

		if ( isset( $input['from_email'] ) ) {
			$sanitized['from_email'] = sanitize_email( wp_unslash( (string) $input['from_email'] ) );
		}

		if ( isset( $input['from_name'] ) ) {
			$sanitized['from_name'] = sanitize_text_field( wp_unslash( (string) $input['from_name'] ) );
		}

		if ( isset( $input['segment_id'] ) ) {
			$sanitized['segment_id'] = sanitize_text_field( wp_unslash( (string) $input['segment_id'] ) );
		} else {
			$sanitized['segment_id'] = $current['segment_id'];
		}

		// Do not overwrite stored secrets with empty / masked placeholders.
		$new_key = isset( $input['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $input['api_key'] ) ) : '';
		if ( '' === $new_key ) {
			$sanitized['api_key'] = $current['api_key'];
		} else {
			$sanitized['api_key'] = $new_key;
		}

		$new_webhook = isset( $input['webhook_secret'] ) ? sanitize_text_field( wp_unslash( (string) $input['webhook_secret'] ) ) : '';
		if ( '' === $new_webhook ) {
			$sanitized['webhook_secret'] = $current['webhook_secret'];
		} else {
			$sanitized['webhook_secret'] = $new_webhook;
		}

		return $sanitized;
	}

	/**
	 * Handle clear API key admin-post request.
	 *
	 * @return void
	 */
	public static function handle_clear_api_key(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::CLEAR_KEY_ACTION ) ) {
			wp_die(
				esc_html__( 'Invalid nonce.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'wp-resend-newsletter' ),
				esc_html__( 'Forbidden', 'wp-resend-newsletter' ),
				array( 'response' => 403 )
			);
		}

		$options            = self::get_options();
		$options['api_key'] = '';
		update_option( self::OPTION_NAME, $options, false );

		wp_safe_redirect(
			add_query_arg(
				'wprn_key_cleared',
				'1',
				admin_url( 'admin.php?page=' . self::MENU_SLUG )
			)
		);
		exit;
	}

	/**
	 * Render from email field.
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public static function render_from_email_field( array $args ): void {
		$options = self::get_options();
		?>
		<input
			type="email"
			id="wprn_from_email"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[from_email]"
			value="<?php echo esc_attr( $options['from_email'] ); ?>"
			class="regular-text"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render from name field.
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public static function render_from_name_field( array $args ): void {
		$options = self::get_options();
		?>
		<input
			type="text"
			id="wprn_from_name"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[from_name]"
			value="<?php echo esc_attr( $options['from_name'] ); ?>"
			class="regular-text"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render API key field (masked; constant override is read-only hint).
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public static function render_api_key_field( array $args ): void {
		$options = self::get_options();

		if ( ResendClient::is_api_key_from_constant() ) {
			echo '<p class="description wprn-api-key-constant">';
			echo esc_html__( 'API key is defined in wp-config.php via WPRN_RESEND_API_KEY (read-only).', 'wp-resend-newsletter' );
			echo '</p>';
			return;
		}

		$has_key = '' !== $options['api_key'];
		$masked  = $has_key ? self::mask_api_key( $options['api_key'] ) : '';
		?>
		<?php if ( $has_key ) : ?>
			<p class="wprn-api-key-masked"><code><?php echo esc_html( $masked ); ?></code></p>
			<p class="description"><?php esc_html_e( 'Enter a new key to replace the stored value. Leave blank to keep the current key.', 'wp-resend-newsletter' ); ?></p>
		<?php endif; ?>
		<input
			type="password"
			id="wprn_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]"
			value=""
			class="regular-text"
			autocomplete="new-password"
			placeholder="<?php echo $has_key ? esc_attr__( '••••••••', 'wp-resend-newsletter' ) : ''; ?>"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render Resend segment ID field.
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public static function render_segment_id_field( array $args ): void {
		$options = self::get_options();
		?>
		<input
			type="text"
			id="wprn_segment_id"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[segment_id]"
			value="<?php echo esc_attr( $options['segment_id'] ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render webhook secret field (masked) and endpoint URL for Resend dashboard.
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public static function render_webhook_secret_field( array $args ): void {
		$options    = self::get_options();
		$has_secret = '' !== $options['webhook_secret'];
		$masked     = $has_secret ? self::mask_api_key( $options['webhook_secret'] ) : '';
		$endpoint   = WebhooksController::endpoint_url();
		?>
		<p class="wprn-webhook-url">
			<label for="wprn_webhook_url"><strong><?php esc_html_e( 'Webhook URL', 'wp-resend-newsletter' ); ?></strong></label><br />
			<code id="wprn_webhook_url"><?php echo esc_html( $endpoint ); ?></code>
		</p>
		<p class="description"><?php esc_html_e( 'Paste this URL into the Resend webhook endpoint settings. Subscribe to email.bounced, email.complained, email.opened, and email.clicked.', 'wp-resend-newsletter' ); ?></p>
		<p class="description wprn-tracking-note"><?php esc_html_e( 'Open and click events require domain open_tracking and click_tracking enabled in Resend (and a verified tracking subdomain).', 'wp-resend-newsletter' ); ?></p>
		<?php if ( WebhookSecret::is_from_constant() ) : ?>
			<p class="description wprn-webhook-secret-constant">
				<?php esc_html_e( 'Webhook secret is defined in wp-config.php via WPRN_RESEND_WEBHOOK_SECRET (read-only).', 'wp-resend-newsletter' ); ?>
			</p>
			<?php
			return;
		endif;
		?>
		<?php if ( $has_secret ) : ?>
			<p class="wprn-webhook-secret-masked"><code><?php echo esc_html( $masked ); ?></code></p>
			<p class="description"><?php esc_html_e( 'Enter a new secret to replace the stored value. Leave blank to keep the current secret.', 'wp-resend-newsletter' ); ?></p>
		<?php endif; ?>
		<input
			type="password"
			id="wprn_webhook_secret"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[webhook_secret]"
			value=""
			class="regular-text"
			autocomplete="new-password"
			placeholder="<?php echo $has_secret ? esc_attr__( '••••••••', 'wp-resend-newsletter' ) : ''; ?>"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}


	/**
	 * Ensure the settings option is not autoloaded (contains API secrets).
	 *
	 * @return void
	 */
	public static function disable_settings_autoload(): void {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::OPTION_NAME, false );
		}
	}

	/**
	 * Mask an API key for display (never echo full value).
	 *
	 * @param string $key Full API key.
	 * @return string
	 */
	public static function mask_api_key( string $key ): string {
		$length = strlen( $key );
		if ( $length <= 8 ) {
			return str_repeat( '•', $length );
		}

		return substr( $key, 0, 3 ) . str_repeat( '•', max( 4, $length - 7 ) ) . substr( $key, -4 );
	}
}
