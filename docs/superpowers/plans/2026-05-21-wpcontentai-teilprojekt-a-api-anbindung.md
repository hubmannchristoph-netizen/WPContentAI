# WPContentAI Teilprojekt A – Echte API-Anbindung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Den Claude-Stub durch echte API-Aufrufe ersetzen und Gemini-Bildgenerierung mit Mediathek-Upload ergänzen, sodass das Plugin Text und Bilder real erzeugt.

**Architecture:** Bestehendes schlankes OOP-Plugin. `class-claude.php` ruft jetzt die Anthropic API real auf. Eine neue Klasse `class-gemini.php` kapselt die Google-Gemini-Bild-API und den Mediathek-Upload. `class-rest.php` bekommt einen `/image`-Endpoint, `class-settings.php` ein Feld für den Gemini-Key, die Sidebar einen Testbild-Bereich.

**Tech Stack:** PHP (WordPress HTTP API `wp_remote_post`, Media-/Attachment-Funktionen, REST API), JavaScript/JSX (`@wordpress/scripts`, `@wordpress/blocks`, `@wordpress/block-editor`).

**Hinweis zu Tests:** Wie im Grundgerüst erfolgt die Abnahme manuell (echte API-Aufrufe lassen sich ohne gültige Keys nicht automatisiert prüfen). Jede Task enthält konkrete manuelle Verifikationsschritte und eine PHP-Syntaxprüfung.

**PHP-Hinweis:** Falls `php` nicht im PATH liegt, `C:\xampp\php\php.exe` verwenden.

---

## Dateistruktur

- `includes/class-settings.php` – MODIFY: Gemini-Key-Feld ergänzen
- `includes/class-claude.php` – MODIFY: echten Claude-Aufruf einbauen
- `includes/class-gemini.php` – CREATE: Gemini-Bild-Client + Mediathek-Upload
- `includes/class-rest.php` – MODIFY: `/image`-Endpoint ergänzen
- `wpcontentai.php` – MODIFY: `class-gemini.php` laden
- `src/sidebar.js` – MODIFY: Testbild-Bereich ergänzen

---

### Task 1: Gemini-Key in den Einstellungen

**Files:**
- Modify: `includes/class-settings.php`

- [ ] **Step 1: `includes/class-settings.php` vollständig ersetzen**

Gesamter neuer Dateiinhalt:

```php
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
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l includes/class-settings.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manuelle Verifikation**

Einstellungsseite „WPContentAI" öffnen → drittes Feld „Gemini API-Key" sichtbar.
Wert eintragen, speichern → bleibt nach Reload erhalten.

- [ ] **Step 4: Commit**

```bash
git add includes/class-settings.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Gemini-API-Key in den Einstellungen"
```

---

### Task 2: Echter Claude-Aufruf

**Files:**
- Modify: `includes/class-claude.php`

- [ ] **Step 1: `includes/class-claude.php` vollständig ersetzen**

Gesamter neuer Dateiinhalt:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Anthropic Claude API.
 */
class WPContentAI_Claude {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Erzeugt neuen Text zu einem Thema.
	 *
	 * @param string $prompt Themenbeschreibung des Autors.
	 * @return string|WP_Error
	 */
	public function generate( $prompt ) {
		return $this->request( 'generate', $prompt );
	}

	/**
	 * Optimiert vorhandenen Inhalt.
	 *
	 * @param string $content Bestehender Inhalt.
	 * @return string|WP_Error
	 */
	public function optimize( $content ) {
		return $this->request( 'optimize', $content );
	}

	/**
	 * Ruft die Claude API auf.
	 *
	 * @param string $mode  'generate' oder 'optimize'.
	 * @param string $input Eingabetext.
	 * @return string|WP_Error
	 */
	private function request( $mode, $input ) {
		$settings = WPContentAI_Settings::get();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error(
				'wpcontentai_no_key',
				'Kein Claude API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.',
				array( 'status' => 400 )
			);
		}

		$system = ( 'optimize' === $mode )
			? 'Du bist ein erfahrener Lektor. Verbessere den vom Nutzer gelieferten Text auf Deutsch hinsichtlich Lesbarkeit, Stil und Klarheit. Gib nur den überarbeiteten Text zurück.'
			: 'Du bist ein erfahrener Redakteur. Schreibe einen gut lesbaren, strukturierten Blogbeitrag auf Deutsch zum vom Nutzer genannten Thema. Gib nur den Beitragstext zurück, keine Vorbemerkungen.';

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'x-api-key'         => $settings['api_key'],
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $settings['model'],
						'max_tokens' => 2048,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $input,
							),
						),
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpcontentai_http',
				'Verbindung zur Claude API fehlgeschlagen: ' . $response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unbekannter API-Fehler.';
			return new WP_Error(
				'wpcontentai_api',
				'Claude API-Fehler: ' . $message,
				array( 'status' => 502 )
			);
		}

		if ( ! isset( $body['content'][0]['text'] ) ) {
			return new WP_Error(
				'wpcontentai_parse',
				'Antwort der Claude API konnte nicht gelesen werden.',
				array( 'status' => 502 )
			);
		}

		return $body['content'][0]['text'];
	}
}
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l includes/class-claude.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manuelle Verifikation**

Gültigen Claude-API-Key in den Einstellungen speichern. Beitrag öffnen, Titel
eingeben, in der Sidebar „Text generieren" klicken → echter, themenbezogener
Text wird angehängt (kein „[WPContentAI Platzhalter]" mehr). „Inhalt
optimieren" überarbeitet vorhandenen Text. Ohne Key → Fehlermeldung in der
Sidebar.

- [ ] **Step 4: Commit**

```bash
git add includes/class-claude.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: echten Claude-API-Aufruf einbauen"
```

---

### Task 3: Gemini-Bild-Client

**Files:**
- Create: `includes/class-gemini.php`
- Modify: `wpcontentai.php`

- [ ] **Step 1: `includes/class-gemini.php` anlegen**

Gesamter Dateiinhalt:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Google Gemini Bild-API.
 *
 * Erzeugt Bilder und legt sie als Anhang in der WordPress-Mediathek ab.
 */
class WPContentAI_Gemini {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent';

	/**
	 * Erzeugt ein Bild zu einem Prompt und speichert es in der Mediathek.
	 *
	 * @param string $prompt Bildbeschreibung.
	 * @return array{id:int,url:string}|WP_Error
	 */
	public function generate( $prompt ) {
		$settings = WPContentAI_Settings::get();

		if ( empty( $settings['gemini_key'] ) ) {
			return new WP_Error(
				'wpcontentai_no_gemini_key',
				'Kein Gemini API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.',
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'x-goog-api-key' => $settings['gemini_key'],
					'content-type'   => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'contents' => array(
							array(
								'parts' => array(
									array( 'text' => $prompt ),
								),
							),
						),
					)
				),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpcontentai_http',
				'Verbindung zur Gemini API fehlgeschlagen: ' . $response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unbekannter API-Fehler.';
			return new WP_Error(
				'wpcontentai_api',
				'Gemini API-Fehler: ' . $message,
				array( 'status' => 502 )
			);
		}

		$image = $this->extract_image( $body );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		return $this->save_to_media( $image['data'], $image['mime'], $prompt );
	}

	/**
	 * Liest die base64-Bilddaten aus der Gemini-Antwort.
	 *
	 * @param array $body Dekodierte API-Antwort.
	 * @return array{data:string,mime:string}|WP_Error
	 */
	private function extract_image( $body ) {
		$parts = isset( $body['candidates'][0]['content']['parts'] )
			? $body['candidates'][0]['content']['parts']
			: array();

		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData']['data'] ) ) {
				return array(
					'data' => $part['inlineData']['data'],
					'mime' => isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png',
				);
			}
		}

		return new WP_Error(
			'wpcontentai_no_image',
			'Die Gemini-Antwort enthielt kein Bild.',
			array( 'status' => 502 )
		);
	}

	/**
	 * Speichert die Bilddaten als Anhang in der Mediathek.
	 *
	 * @param string $base64 base64-kodierte Bilddaten.
	 * @param string $mime   MIME-Typ des Bildes.
	 * @param string $prompt Ursprünglicher Prompt (für den Titel).
	 * @return array{id:int,url:string}|WP_Error
	 */
	private function save_to_media( $base64, $mime, $prompt ) {
		$binary = base64_decode( $base64, true );
		if ( false === $binary ) {
			return new WP_Error(
				'wpcontentai_decode',
				'Bilddaten konnten nicht dekodiert werden.',
				array( 'status' => 502 )
			);
		}

		$ext = ( 'image/jpeg' === $mime ) ? 'jpg' : 'png';

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error(
				'wpcontentai_upload_dir',
				'Upload-Verzeichnis nicht verfügbar: ' . $uploads['error'],
				array( 'status' => 502 )
			);
		}

		$filename = wp_unique_filename( $uploads['path'], 'wpcontentai-' . time() . '.' . $ext );
		$filepath = trailingslashit( $uploads['path'] ) . $filename;

		if ( false === file_put_contents( $filepath, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error(
				'wpcontentai_write',
				'Bilddatei konnte nicht gespeichert werden.',
				array( 'status' => 502 )
			);
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => 'WPContentAI: ' . wp_trim_words( $prompt, 8, '' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $filepath );
		if ( is_wp_error( $attach_id ) || 0 === $attach_id ) {
			return new WP_Error(
				'wpcontentai_attach',
				'Anhang konnte nicht angelegt werden.',
				array( 'status' => 502 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $filepath );
		wp_update_attachment_metadata( $attach_id, $metadata );

		return array(
			'id'  => (int) $attach_id,
			'url' => wp_get_attachment_url( $attach_id ),
		);
	}
}
```

- [ ] **Step 2: `wpcontentai.php` – Gemini-Klasse laden**

In `wpcontentai.php` die vorhandene Zeile

```php
require_once WPCONTENTAI_PATH . 'includes/class-claude.php';
```

ersetzen durch:

```php
require_once WPCONTENTAI_PATH . 'includes/class-claude.php';
require_once WPCONTENTAI_PATH . 'includes/class-gemini.php';
```

(Die übrigen `require_once`-Zeilen bleiben unverändert.)

- [ ] **Step 3: Syntax prüfen**

Run: `php -l includes/class-gemini.php`
Expected: `No syntax errors detected`

Run: `php -l wpcontentai.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add includes/class-gemini.php wpcontentai.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Gemini-Bild-Client mit Mediathek-Upload"
```

---

### Task 4: REST-Endpoint /image

**Files:**
- Modify: `includes/class-rest.php`

- [ ] **Step 1: `includes/class-rest.php` vollständig ersetzen**

Gesamter neuer Dateiinhalt:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registriert die REST-Endpoints von WPContentAI.
 */
class WPContentAI_REST {

	const NAMESPACE = 'wpcontentai/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$text_args = array(
			'methods'             => 'POST',
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'input' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate',
			array_merge( $text_args, array( 'callback' => array( $this, 'handle_generate' ) ) )
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimize',
			array_merge( $text_args, array( 'callback' => array( $this, 'handle_optimize' ) ) )
		);

		register_rest_route(
			self::NAMESPACE,
			'/image',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'callback'            => array( $this, 'handle_image' ),
				'args'                => array(
					'prompt' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Rechteprüfung für die Text-Endpoints.
	 *
	 * @return bool
	 */
	public function check_edit_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Rechteprüfung für den Bild-Endpoint (lädt in die Mediathek).
	 *
	 * @return bool
	 */
	public function check_upload_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->generate( $request->get_param( 'input' ) );
		return $this->respond_text( $result );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_optimize( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->optimize( $request->get_param( 'input' ) );
		return $this->respond_text( $result );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_image( $request ) {
		$gemini = new WPContentAI_Gemini();
		$result = $gemini->generate( $request->get_param( 'prompt' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'id'  => $result['id'],
				'url' => $result['url'],
			),
			200
		);
	}

	/**
	 * Wandelt ein Text-Ergebnis in eine REST-Antwort.
	 *
	 * @param string|WP_Error $result Ergebnis des Claude-Clients.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond_text( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'text' => $result ), 200 );
	}
}
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l includes/class-rest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manuelle Verifikation**

Als angemeldeter Admin `…/wp-json/wpcontentai/v1` im Browser aufrufen → drei
Routen sichtbar: `/generate`, `/optimize`, `/image`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-rest.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: REST-Endpoint /image"
```

---

### Task 5: Testbild-Bereich in der Sidebar

**Files:**
- Modify: `src/sidebar.js`

- [ ] **Step 1: `src/sidebar.js` vollständig ersetzen**

Gesamter neuer Dateiinhalt:

```js
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import {
	PanelBody,
	Button,
	Spinner,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

const SIDEBAR_NAME = 'wpcontentai-sidebar';

export default function Sidebar() {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ imagePrompt, setImagePrompt ] = useState( '' );

	const content = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const title = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { insertBlocks } = useDispatch( blockEditorStore );

	const callApi = async ( route, input ) => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wpcontentai/v1/${ route }`,
				method: 'POST',
				data: { input },
			} );
			editPost( { content: `${ content }\n\n${ result.text }` } );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	const generateImage = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: '/wpcontentai/v1/image',
				method: 'POST',
				data: { prompt: imagePrompt },
			} );
			const block = createBlock( 'core/image', {
				id: result.id,
				url: result.url,
			} );
			insertBlocks( block );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				WPContentAI
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title="WPContentAI">
				<PanelBody title="Text">
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => callApi( 'generate', title ) }
					>
						Text generieren
					</Button>
					<p />
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => callApi( 'optimize', content ) }
					>
						Inhalt optimieren
					</Button>
				</PanelBody>
				<PanelBody title="Testbild generieren" initialOpen={ false }>
					<TextControl
						__nextHasNoMarginBottom
						label="Bild-Prompt"
						value={ imagePrompt }
						onChange={ setImagePrompt }
					/>
					<Button
						variant="primary"
						disabled={ busy || ! imagePrompt }
						onClick={ generateImage }
					>
						Bild generieren
					</Button>
				</PanelBody>
				{ busy && <Spinner /> }
			</PluginSidebar>
		</>
	);
}
```

- [ ] **Step 2: Build erzeugen**

Run: `npm run build`
Expected: webpack kompiliert ohne Fehler; `build/index.js` und
`build/index.asset.php` werden aktualisiert. Die `dependencies` in
`build/index.asset.php` enthalten jetzt zusätzlich `wp-block-editor` und
`wp-blocks`.

- [ ] **Step 3: Manuelle Verifikation**

Gültigen Gemini-API-Key in den Einstellungen speichern. Beitrag öffnen,
WPContentAI-Sidebar öffnen → Bereich „Testbild generieren". Einen Bild-Prompt
eingeben, „Bild generieren" klicken → ein Bild-Block mit dem erzeugten Bild
erscheint im Beitrag, und das Bild taucht in der Mediathek auf. Ohne Gemini-Key
→ Fehlermeldung in der Sidebar.

- [ ] **Step 4: Commit**

```bash
git add src/sidebar.js build
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Testbild-Bereich in der Sidebar"
```

---

## Self-Review

- **Spec-Abdeckung:** Gemini-Key in den Einstellungen (Task 1), echter
  Claude-Aufruf mit System-Prompts und HTTP-Status (Task 2), Gemini-Bild-Client
  mit Mediathek-Upload + `wpcontentai.php`-Ladezeile (Task 3), `/image`-Endpoint
  mit `upload_files`-Prüfung (Task 4), Testbild-Bereich in der Sidebar (Task 5).
  Alle Spec-Abschnitte abgedeckt.
- **Typkonsistenz:** `WPContentAI_Settings::get()` liefert nun
  `api_key` / `model` / `gemini_key`; `WPContentAI_Claude` nutzt `api_key` und
  `model`, `WPContentAI_Gemini` nutzt `gemini_key`. Die Gemini-Methode
  `generate()` liefert `array{id,url}` bzw. `WP_Error`; `handle_image()` liest
  genau `id` und `url`. Der REST-Namespace `wpcontentai/v1` bleibt unverändert.
- **Permission-Methoden:** `class-rest.php` benennt die Rechteprüfungen jetzt
  `check_edit_permission()` (Text) und `check_upload_permission()` (Bild) — die
  alte Methode `check_permission()` wird durch die vollständige Dateiersetzung
  entfernt, es bleibt kein toter Verweis.
- **Platzhalter-Scan:** keine offenen TBD/TODO; der frühere Stub-`TODO`-Block in
  `class-claude.php` wird durch die vollständige Dateiersetzung entfernt.
