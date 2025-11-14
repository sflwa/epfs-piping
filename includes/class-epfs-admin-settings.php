<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the admin settings page and log page for the plugin.
 *
 * @since 1.0.0
 */
class EPFS_Admin_Settings {

	const SETTINGS_KEY = 'epfs_piping_settings';
	const NONCE_ACTION = 'epfs_save_settings_action';
	const NONCE_NAME   = 'epfs_settings_nonce';

	/**
	 * Single instance of the class.
	 *
	 * @var EPFS_Admin_Settings
	 */
	private static $instance = null;

	/**
	 * Retrieves the one true instance of the class.
	 *
	 * @return EPFS_Admin_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'admin_init', array( self::$instance, 'handle_form_submission' ) );
		}
		return self::$instance;
	}

	/**
	 * Adds the settings page and log page to the WordPress admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		$menu_slug = 'epfs-piping';

		// 1. Top-Level Menu (Piping Log - Default Page).
		add_menu_page(
			esc_html__( 'FS Email Piping', 'epfs-piping' ),
			esc_html__( 'FS Email Piping', 'epfs-piping' ),
			'manage_options',
			$menu_slug,
			array( $this, 'render_log_page' ), // Default page is the log.
			'dashicons-email-alt', // Icon for email piping.
			60 // Position
		);

		// 2. Submenu: Piping Log (explicitly).
		add_submenu_page(
			$menu_slug,
			esc_html__( 'Piping Log', 'epfs-piping' ),
			esc_html__( 'Piping Log', 'epfs-piping' ),
			'manage_options',
			$menu_slug, // Same slug as parent makes it the default page.
			array( $this, 'render_log_page' )
		);

		// 3. Submenu: Settings.
		add_submenu_page(
			$menu_slug,
			esc_html__( 'Settings', 'epfs-piping' ),
			esc_html__( 'Settings', 'epfs-piping' ),
			'manage_options',
			'epfs-settings', // Separate slug for settings.
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Renders the Piping Log page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$log_entries = get_option( 'epfs_piping_log', array() );
		$log_entries = array_reverse( $log_entries ); // Show most recent first.

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FluentSupport Email Piping Log', 'epfs-piping' ); ?></h1>
			<p class="description"><?php esc_html_e( 'A record of successful email piping runs where one or more tickets were processed.', 'epfs-piping' ); ?></p>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Piping Date/Time', 'epfs-piping' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tickets Created (New)', 'epfs-piping' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tickets Updated (Replies)', 'epfs-piping' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Total Processed', 'epfs-piping' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $log_entries ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No piping entries recorded yet.', 'epfs-piping' ); ?></td>
						</tr>
					<?php else : ?>
						<?php
						foreach ( $log_entries as $entry ) :
							$created = absint( $entry['created_count'] );
							$updated = absint( $entry['updated_count'] );
							$total   = $created + $updated;
							?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['timestamp'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $created ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $updated ) ); ?></td>
								<td><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the plugin settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Piping for FluentSupport Settings', 'epfs-piping' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Configure the POP3 connection and FluentSupport API credentials.', 'epfs-piping' ); ?></p>

			<form method="post" action="">
				<?php
				wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
				?>

				<h2 class="title"><?php esc_html_e( 'FluentSupport REST API Credentials', 'epfs-piping' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="epfs_api_base_url"><?php esc_html_e( 'API Base URL', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_api_base_url" type="url" id="epfs_api_base_url" value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" class="regular-text" placeholder="https://yourdomain.com/wp-json/fluent-support/v2" />
							<p class="description"><?php esc_html_e( 'Your WordPress REST API base URL for FluentSupport.', 'epfs-piping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epfs_api_username"><?php esc_html_e( 'API Username', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_api_username" type="text" id="epfs_api_username" value="<?php echo esc_attr( $settings['api_username'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'WordPress Username with permissions to create tickets.', 'epfs-piping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epfs_api_password"><?php esc_html_e( 'API Application Password', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_api_password" type="password" id="epfs_api_password" value="<?php echo esc_attr( $this->decrypt_value( $settings['api_password'] ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'The Application Password generated in WordPress for the API Username.', 'epfs-piping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epfs_mailbox_id"><?php esc_html_e( 'Target Mailbox ID', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_mailbox_id" type="number" id="epfs_mailbox_id" value="<?php echo esc_attr( $settings['mailbox_id'] ); ?>" class="small-text" min="1" />
							<p class="description"><?php esc_html_e( 'The FluentSupport Mailbox ID where new tickets should be created.', 'epfs-piping' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'POP3 Mailbox Credentials', 'epfs-piping' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="epfs_pop3_host"><?php esc_html_e( 'POP3 Host', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_pop3_host" type="text" id="epfs_pop3_host" value="<?php echo esc_attr( $settings['pop3_host'] ); ?>" class="regular-text" placeholder="mail.example.com" />
							<p class="description"><?php esc_html_e( 'E.g., {mail.example.com:995/pop3/ssl/novalidate-cert}. See IMAP documentation for specific format.', 'epfs-piping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epfs_pop3_username"><?php esc_html_e( 'POP3 Username', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_pop3_username" type="text" id="epfs_pop3_username" value="<?php echo esc_attr( $settings['pop3_username'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="epfs_pop3_password"><?php esc_html_e( 'POP3 Password', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_pop3_password" type="password" id="epfs_pop3_password" value="<?php echo esc_attr( $this->decrypt_value( $settings['pop3_password'] ) ); ?>" class="regular-text" />
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Piping Schedule', 'epfs-piping' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="epfs_cron_interval"><?php esc_html_e( 'Piping Interval (Seconds)', 'epfs-piping' ); ?></label></th>
						<td>
							<input name="epfs_cron_interval" type="number" id="epfs_cron_interval" value="<?php echo esc_attr( $settings['cron_interval'] ); ?>" class="small-text" min="60" />
							<p class="description"><?php esc_html_e( 'How often (in seconds) the system should check the POP3 mailbox. Recommended 60-300 seconds.', 'epfs-piping' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( esc_html__( 'Save Settings', 'epfs-piping' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the saving of settings upon form submission.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_form_submission() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $_POST is superglobal.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! isset( $_POST['submit'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $_POST is superglobal.
		if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin notice is a safe string.
			add_settings_error( 'epfs_settings', 'nonce_fail', __( 'Security check failed. Please try again.', 'epfs-piping' ), 'error' );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save/Sanitize the data.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $_POST is superglobal.
		$new_settings = array(
			// API.
			'api_base_url'   => esc_url_raw( sanitize_text_field( wp_unslash( $_POST['epfs_api_base_url'] ) ) ),
			'api_username'   => sanitize_user( wp_unslash( $_POST['epfs_api_username'] ) ),
			'api_password'   => $this->encrypt_value( wp_unslash( $_POST['epfs_api_password'] ) ), // Encrypt immediately.
			'mailbox_id'     => absint( wp_unslash( $_POST['epfs_mailbox_id'] ) ),
			// POP3.
			'pop3_host'      => sanitize_text_field( wp_unslash( $_POST['epfs_pop3_host'] ) ),
			'pop3_username'  => sanitize_text_field( wp_unslash( $_POST['epfs_pop3_username'] ) ),
			'pop3_password'  => $this->encrypt_value( wp_unslash( $_POST['epfs_pop3_password'] ) ), // Encrypt immediately.
			// Cron.
			'cron_interval'  => absint( wp_unslash( $_POST['epfs_cron_interval'] ) ),
		);

		update_option( self::SETTINGS_KEY, $new_settings );
		
		// Update cron schedule immediately after saving.
		EPFS_POP3_Handler::get_instance()->setup_cron_schedule( $new_settings['cron_interval'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin notice is a safe string.
		add_settings_error( 'epfs_settings', 'settings_saved', __( 'Settings saved successfully. Cron schedule updated.', 'epfs-piping' ), 'success' );
	}

	/**
	 * Retrieves the current settings, decrypting passwords and setting defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'api_base_url'   => '',
			'api_username'   => '',
			'api_password'   => '', // Stored as encrypted.
			'mailbox_id'     => 1,
			'pop3_host'      => '',
			'pop3_username'  => '',
			'pop3_password'  => '', // Stored as encrypted.
			'cron_interval'  => 300, // Default 5 minutes.
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core WP.
		$settings = get_option( self::SETTINGS_KEY, $defaults );

		// Handle missing keys for backward compatibility/safety.
		$settings = wp_parse_args( $settings, $defaults );

		// NOTE: Passwords are not decrypted here for display on the form.
		// Decryption will happen in the POP3 Handler right before use.
		return $settings;
	}

	// --- Encryption / Decryption Helpers (Required for Secure Storage) ---

	/**
	 * Helper function to get a secret key for encryption.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_secret_key() {
		// Use a combination of WordPress salts for a secure key.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constants defined by core WP.
		return substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY ), 0, 32 ); // 32 bytes for AES-256.
	}

	/**
	 * Encrypts a value using OpenSSL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The string to encrypt.
	 * @return string The encrypted string.
	 */
	public function encrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core PHP.
		$ivlen = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv = openssl_random_pseudo_bytes( $ivlen );
		$key = $this->get_secret_key();
		
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core PHP.
		$ciphertext_raw = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$hmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
		
		// Store IV, HMAC, and raw ciphertext as a single base64 string.
		return base64_encode( $iv . $hmac . $ciphertext_raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for safe storage.
	}

	/**
	 * Decrypts a value using OpenSSL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The encrypted string.
	 * @return string The decrypted string.
	 */
	public function decrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for safe retrieval.
		$c = base64_decode( $value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core PHP.
		$ivlen = openssl_cipher_iv_length( 'aes-256-cbc' );
		$key = $this->get_secret_key();
		$sha2len = 32; // SHA-256 is 32 bytes.
		
		if ( strlen( $c ) < $ivlen + $sha2len ) {
			// Not a valid encrypted string from this method.
			return '';
		}
		
		// Extract components.
		$iv = substr( $c, 0, $ivlen );
		$hmac = substr( $c, $ivlen, $sha2len );
		$ciphertext_raw = substr( $c, $ivlen + $sha2len );
		
		// Decrypt.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core PHP.
		$original_plaintext = openssl_decrypt( $ciphertext_raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$calcmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
		
		// Compare HMACs to prevent tampering.
		if ( hash_equals( $hmac, $calcmac ) ) {
			return $original_plaintext;
		}

		return ''; // Decryption failed or data tampered with.
	}
}
