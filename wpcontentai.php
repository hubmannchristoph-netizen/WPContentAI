<?php
/**
 * Plugin Name:       WPContentAI
 * Description:       Generiert und optimiert Inhalte per Anthropic Claude API – direkt im Gutenberg-Editor.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            WPContentAI
 * License:           GPL-2.0-or-later
 * Text Domain:       wpcontentai
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCONTENTAI_VERSION', '0.1.0' );
define( 'WPCONTENTAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCONTENTAI_URL', plugin_dir_url( __FILE__ ) );

require_once WPCONTENTAI_PATH . 'includes/class-claude.php';
require_once WPCONTENTAI_PATH . 'includes/class-image.php';
require_once WPCONTENTAI_PATH . 'includes/class-rest.php';

/**
 * Prüft, ob der native WordPress 7.0 AI-Client verfügbar ist.
 */
function wpcontentai_check_ai_client() {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'WPContentAI benötigt WordPress 7.0 mit aktiviertem KI-Client und ein KI-Provider-Plugin (Konnektoren).', 'wpcontentai' ); ?></p>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'wpcontentai_check_ai_client' );

/**
 * Plugin-Komponenten initialisieren.
 */
function wpcontentai_init() {
	new WPContentAI_REST();
}
add_action( 'plugins_loaded', 'wpcontentai_init' );

/**
 * Lädt das Gutenberg-Editor-Plugin.
 */
function wpcontentai_enqueue_editor_assets() {
	$asset_file = WPCONTENTAI_PATH . 'build/index.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}
	$asset = include $asset_file;

	wp_enqueue_script(
		'wpcontentai-editor',
		WPCONTENTAI_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);
}
add_action( 'enqueue_block_editor_assets', 'wpcontentai_enqueue_editor_assets' );
