<?php
/**
 * Settings page under WooCommerce.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the API key, the model and where to send customers for anything beyond order status.
 */
class Parcelwise_Settings {

	const OPTION = 'parcelwise_settings';

	/** The most recent failed Claude API call, shown on the settings page. */
	const ERROR_OPTION = 'parcelwise_last_error';

	const MODELS = array(
		'claude-opus-5-5'   => 'Claude Opus 5.5',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5',
	);

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'api_key'         => '',
				'model'           => 'claude-opus-5-5',
				'support_contact' => '',
			)
		);
	}

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		// Shop managers can change these settings, not just administrators.
		add_filter(
			'option_page_capability_' . self::OPTION,
			function () {
				return 'manage_woocommerce';
			}
		);
	}

	public static function add_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Parcelwise', 'parcelwise' ),
			__( 'Parcelwise', 'parcelwise' ),
			'manage_woocommerce',
			'parcelwise',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_setting() {
		register_setting( self::OPTION, self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * Suggested wording for the site's privacy policy, shown in Settings > Privacy > Policy Guide.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = __( 'When you use the order status chat, the messages you type and the details of the order you ask about (status, items, totals, refunds, order date and tracking numbers) are sent to Anthropic to write the assistant\'s replies. Order details are only sent after you give the order number together with the email address or postcode used for the order. The conversation is kept on this site for one hour. Anthropic\'s privacy policy: https://www.anthropic.com/legal/privacy', 'parcelwise' );
		wp_add_privacy_policy_content( __( 'Parcelwise', 'parcelwise' ), wp_kses_post( wpautop( $text ) ) );
	}

	/**
	 * @param int    $http_code HTTP status of the failed call, 0 when it never got a response.
	 * @param string $detail    Error type from the API, or the connection error.
	 */
	public static function record_error( $http_code, $detail ) {
		update_option(
			self::ERROR_OPTION,
			array(
				'time'   => time(),
				'code'   => (int) $http_code,
				'detail' => (string) $detail,
			),
			false
		);
	}

	public static function sanitize( $input ) {
		// New settings deserve a clean slate: the old error was most likely about the old key.
		delete_option( self::ERROR_OPTION );
		$current = self::get();
		$key     = trim( sanitize_text_field( $input['api_key'] ?? '' ) );
		$model   = $input['model'] ?? '';

		return array(
			// An empty field keeps the saved key, so the key never has to be printed back into the page.
			'api_key'         => '' === $key ? $current['api_key'] : $key,
			'model'           => isset( self::MODELS[ $model ] ) ? $model : 'claude-opus-5-5',
			'support_contact' => sanitize_text_field( $input['support_contact'] ?? '' ),
		);
	}

	public static function render() {
		$settings   = self::get();
		$last_error = get_option( self::ERROR_OPTION );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Parcelwise', 'parcelwise' ); ?></h1>
			<?php if ( is_array( $last_error ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: 1: date and time, 2: HTTP status code, 3: error type such as authentication_error. */
							esc_html__( 'On %1$s the assistant could not reach Claude (HTTP %2$d, %3$s). Check your API key and your Anthropic account balance.', 'parcelwise' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_error['time'] ) ),
							(int) $last_error['code'],
							esc_html( $last_error['detail'] )
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Add the assistant to any page with the [parcelwise] shortcode.', 'parcelwise' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="parcelwise-api-key"><?php esc_html_e( 'Anthropic API key', 'parcelwise' ); ?></label></th>
						<td>
							<input type="password" id="parcelwise-api-key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="" autocomplete="off" class="regular-text"
								placeholder="<?php echo esc_attr( $settings['api_key'] ? __( 'Saved. Leave empty to keep it.', 'parcelwise' ) : 'sk-ant-...' ); ?>">
							<p class="description"><?php esc_html_e( 'The order details a customer asks about are sent to Anthropic to write the reply. Mention this in your privacy policy.', 'parcelwise' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="parcelwise-model"><?php esc_html_e( 'Model', 'parcelwise' ); ?></label></th>
						<td>
							<select id="parcelwise-model" name="<?php echo esc_attr( self::OPTION ); ?>[model]">
								<?php foreach ( self::MODELS as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Opus 5.5 gives the most reliable answers. Haiku 5.5 costs far less per message.', 'parcelwise' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="parcelwise-support"><?php esc_html_e( 'Support contact', 'parcelwise' ); ?></label></th>
						<td>
							<input type="text" id="parcelwise-support" name="<?php echo esc_attr( self::OPTION ); ?>[support_contact]" value="<?php echo esc_attr( $settings['support_contact'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Where the assistant sends customers for anything beyond order status, such as support@yourstore.com.', 'parcelwise' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
