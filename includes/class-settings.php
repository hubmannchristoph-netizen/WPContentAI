<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Menü und Einstellungsseite für WPContentAI.
 */
class WPContentAI_Settings {

	const OPTION = 'wpcontentai_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Liefert die gespeicherten Einstellungen mit Defaults.
	 *
	 * @return array{api_key:string,model:string,gemini_key:string}
	 */
	public static function get() {
		$defaults = array(
			'api_key'    => '',
			'model'      => 'claude-sonnet-4-6',
			'gemini_key' => '',
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	public function add_menu() {
		add_menu_page(
			'WPContentAI',
			'WPContentAI',
			'manage_options',
			'wpcontentai',
			array( $this, 'render_page' ),
			'dashicons-edit'
		);
	}

	public function register_settings() {
		register_setting(
			'wpcontentai',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Säubert die Formulareingaben.
	 *
	 * @param array $input Rohdaten aus dem Formular.
	 * @return array
	 */
	public function sanitize( $input ) {
		$allowed_models = array( 'claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001' );
		$model          = isset( $input['model'] ) ? $input['model'] : 'claude-sonnet-4-6';

		return array(
			'api_key'    => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'model'      => in_array( $model, $allowed_models, true ) ? $model : 'claude-sonnet-4-6',
			'gemini_key' => isset( $input['gemini_key'] ) ? sanitize_text_field( $input['gemini_key'] ) : '',
		);
	}

	public function render_page() {
		$settings = self::get();
		?>
		<div class="wrap">
			<h1>WPContentAI</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wpcontentai' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpcontentai_api_key">Claude API-Key</label>
						</th>
						<td>
							<input type="password" id="wpcontentai_api_key"
								name="<?php echo esc_attr( self::OPTION ); ?>[api_key]"
								value="<?php echo esc_attr( $settings['api_key'] ); ?>"
								class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcontentai_model">Modell</label>
						</th>
						<td>
							<select id="wpcontentai_model"
								name="<?php echo esc_attr( self::OPTION ); ?>[model]">
								<?php
								$models = array(
									'claude-opus-4-7'            => 'Claude Opus 4.7',
									'claude-sonnet-4-6'          => 'Claude Sonnet 4.6',
									'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
								);
								foreach ( $models as $value => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $value ),
										selected( $settings['model'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcontentai_gemini_key">Gemini API-Key</label>
						</th>
						<td>
							<input type="password" id="wpcontentai_gemini_key"
								name="<?php echo esc_attr( self::OPTION ); ?>[gemini_key]"
								value="<?php echo esc_attr( $settings['gemini_key'] ); ?>"
								class="regular-text" autocomplete="off" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
