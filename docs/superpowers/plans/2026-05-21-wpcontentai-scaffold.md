# WPContentAI Grundgerüst Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein lauffähiges WordPress-Plugin-Grundgerüst „WPContentAI" mit Einstellungsseite, Gutenberg-Sidebar und REST-Endpoints; der Claude-Aufruf ist ein klar markierter Stub.

**Architecture:** Schlankes OOP-Plugin. `wpcontentai.php` lädt drei fokussierte Klassen aus `includes/`. Die Gutenberg-Sidebar wird mit `@wordpress/scripts` aus `src/` nach `build/` gebaut. REST-Endpoints unter `wpcontentai/v1` verbinden Editor und Claude-Client-Stub.

**Tech Stack:** PHP (WordPress Plugin API, Settings API, REST API), JavaScript/JSX (`@wordpress/scripts`, `@wordpress/plugins`, `@wordpress/edit-post`, `@wordpress/api-fetch`).

**Hinweis zu Tests:** Laut freigegebener Spec erfolgt die Abnahme manuell (Plugin aktivieren, Einstellungen speichern, Sidebar bedienen). Eine WP-PHPUnit-Testumgebung ist im Grundgerüst bewusst nicht enthalten. Jede Task enthält daher konkrete manuelle Verifikationsschritte statt automatisierter Unit-Tests.

---

## Dateistruktur

- `wpcontentai.php` – Plugin-Header, Konstanten, Bootstrap, Aktivierungshook
- `includes/class-settings.php` – Admin-Menü + Einstellungsseite (API-Key, Modell)
- `includes/class-claude.php` – Claude-API-Client (Stub im Grundgerüst)
- `includes/class-rest.php` – REST-Endpoints `/generate` und `/optimize`
- `src/index.js` – Einstiegspunkt, registriert das Editor-Plugin
- `src/sidebar.js` – Sidebar-Panel mit Buttons + REST-Aufruf
- `package.json` – Build-Skripte (`@wordpress/scripts`)
- `.gitignore` – `node_modules/`
- `CLAUDE.md` – Anleitung für Claude Code

---

### Task 1: Plugin-Bootstrap

**Files:**
- Create: `wpcontentai.php`
- Create: `.gitignore`

- [ ] **Step 1: `.gitignore` anlegen**

```
node_modules/
```

- [ ] **Step 2: `wpcontentai.php` anlegen**

```php
<?php
/**
 * Plugin Name:       WPContentAI
 * Description:       Generiert und optimiert Inhalte per Anthropic Claude API – direkt im Gutenberg-Editor.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            WPContentAI
 * License:           GPL-2.0-or-later
 * Text Domain:       wpcontentai
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCONTENTAI_VERSION', '0.1.0' );
define( 'WPCONTENTAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCONTENTAI_URL', plugin_dir_url( __FILE__ ) );

require_once WPCONTENTAI_PATH . 'includes/class-settings.php';
require_once WPCONTENTAI_PATH . 'includes/class-claude.php';
require_once WPCONTENTAI_PATH . 'includes/class-rest.php';

/**
 * Default-Optionen beim Aktivieren setzen.
 */
function wpcontentai_activate() {
	if ( false === get_option( 'wpcontentai_settings' ) ) {
		add_option(
			'wpcontentai_settings',
			array(
				'api_key' => '',
				'model'   => 'claude-sonnet-4-6',
			)
		);
	}
}
register_activation_hook( __FILE__, 'wpcontentai_activate' );

/**
 * Plugin-Komponenten initialisieren.
 */
function wpcontentai_init() {
	new WPContentAI_Settings();
	new WPContentAI_REST();
}
add_action( 'plugins_loaded', 'wpcontentai_init' );
```

- [ ] **Step 3: Manuelle Verifikation**

Da `class-settings.php` etc. noch fehlen, schlägt ein Aktivieren jetzt fehl – das ist erwartet. Nur prüfen: `php -l wpcontentai.php` meldet `No syntax errors`.

Run: `php -l wpcontentai.php`
Expected: `No syntax errors detected in wpcontentai.php`

- [ ] **Step 4: Commit**

```bash
git add wpcontentai.php .gitignore
git commit -m "feat: Plugin-Bootstrap und Konstanten"
```

---

### Task 2: Einstellungsseite

**Files:**
- Create: `includes/class-settings.php`

- [ ] **Step 1: `includes/class-settings.php` anlegen**

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
	 * @return array{api_key:string,model:string}
	 */
	public static function get() {
		$defaults = array(
			'api_key' => '',
			'model'   => 'claude-sonnet-4-6',
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
			'api_key' => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'model'   => in_array( $model, $allowed_models, true ) ? $model : 'claude-sonnet-4-6',
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

- [ ] **Step 3: Manuelle Verifikation in WordPress**

1. Plugin im Backend unter „Plugins" aktivieren – keine Fehlermeldung.
2. Neuer Menüpunkt „WPContentAI" erscheint.
3. API-Key + Modell eingeben, speichern → Werte bleiben nach Reload erhalten.

- [ ] **Step 4: Commit**

```bash
git add includes/class-settings.php
git commit -m "feat: Einstellungsseite mit API-Key und Modell"
```

---

### Task 3: Claude-Client (Stub)

**Files:**
- Create: `includes/class-claude.php`

- [ ] **Step 1: `includes/class-claude.php` anlegen**

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Anthropic Claude API.
 *
 * Im Grundgerüst liefern generate() und optimize() Platzhalter-Antworten.
 * Der echte API-Aufruf ist mit TODO markiert.
 */
class WPContentAI_Claude {

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
	 * Zentraler Einstiegspunkt für API-Aufrufe.
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
				'Kein Claude API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.'
			);
		}

		// TODO: Echten Claude-API-Aufruf einbauen.
		// $response = wp_remote_post(
		//     'https://api.anthropic.com/v1/messages',
		//     array(
		//         'headers' => array(
		//             'x-api-key'         => $settings['api_key'],
		//             'anthropic-version' => '2023-06-01',
		//             'content-type'      => 'application/json',
		//         ),
		//         'body'    => wp_json_encode( array(
		//             'model'      => $settings['model'],
		//             'max_tokens' => 1024,
		//             'messages'   => array(
		//                 array( 'role' => 'user', 'content' => $input ),
		//             ),
		//         ) ),
		//         'timeout' => 30,
		//     )
		// );
		// Antwort auswerten und Text zurückgeben.

		// Platzhalter-Antwort für das Grundgerüst:
		if ( 'optimize' === $mode ) {
			return "[WPContentAI Platzhalter] Optimierte Fassung von:\n\n" . $input;
		}
		return "[WPContentAI Platzhalter] Generierter Text zu: " . $input;
	}
}
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l includes/class-claude.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-claude.php
git commit -m "feat: Claude-Client-Stub mit markiertem API-TODO"
```

---

### Task 4: REST-Endpoints

**Files:**
- Create: `includes/class-rest.php`

- [ ] **Step 1: `includes/class-rest.php` anlegen**

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
		$args = array(
			'methods'             => 'POST',
			'permission_callback' => array( $this, 'check_permission' ),
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
			array_merge( $args, array( 'callback' => array( $this, 'handle_generate' ) ) )
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimize',
			array_merge( $args, array( 'callback' => array( $this, 'handle_optimize' ) ) )
		);
	}

	/**
	 * Nur angemeldete Nutzer mit Schreibrechten dürfen die Endpoints nutzen.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->generate( $request->get_param( 'input' ) );
		return $this->respond( $result );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_optimize( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->optimize( $request->get_param( 'input' ) );
		return $this->respond( $result );
	}

	/**
	 * Wandelt das Client-Ergebnis in eine REST-Antwort.
	 *
	 * @param string|WP_Error $result Ergebnis des Claude-Clients.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
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

1. Plugin ist aktiv. Im Browser als angemeldeter Admin aufrufen:
   `…/wp-json/wpcontentai/v1` zeigt die beiden Routen `/generate` und `/optimize`.
2. Abmelden → Aufruf von `/wp-json/wpcontentai/v1/generate` per POST liefert
   einen `rest_forbidden`-Fehler (401/403).

- [ ] **Step 4: Commit**

```bash
git add includes/class-rest.php
git commit -m "feat: REST-Endpoints generate und optimize"
```

---

### Task 5: Gutenberg-Sidebar

**Files:**
- Create: `package.json`
- Create: `src/index.js`
- Create: `src/sidebar.js`
- Modify: `wpcontentai.php` (Asset-Einbindung ergänzen)

- [ ] **Step 1: `package.json` anlegen**

```json
{
  "name": "wpcontentai",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start"
  },
  "devDependencies": {
    "@wordpress/scripts": "^30.0.0"
  }
}
```

- [ ] **Step 2: `src/sidebar.js` anlegen**

```js
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { PanelBody, Button, Spinner, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';

const SIDEBAR_NAME = 'wpcontentai-sidebar';

export default function Sidebar() {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const content = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const title = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );

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

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				WPContentAI
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title="WPContentAI">
				<PanelBody>
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
					{ busy && <Spinner /> }
				</PanelBody>
			</PluginSidebar>
		</>
	);
}
```

- [ ] **Step 3: `src/index.js` anlegen**

```js
import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './sidebar';

registerPlugin( 'wpcontentai', {
	render: Sidebar,
} );
```

- [ ] **Step 4: Asset-Einbindung in `wpcontentai.php` ergänzen**

Nach der Funktion `wpcontentai_init()` einfügen:

```php
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
```

- [ ] **Step 5: Build erzeugen**

Run: `npm install`
Expected: Abhängigkeiten installiert, `node_modules/` vorhanden.

Run: `npm run build`
Expected: `build/index.js` und `build/index.asset.php` werden erzeugt.

- [ ] **Step 6: Syntax prüfen**

Run: `php -l wpcontentai.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Manuelle Verifikation in WordPress**

1. Beitrag öffnen → im Editor-Menü (oben rechts, Stern/Plugin-Icon) erscheint
   „WPContentAI"; das Panel zeigt zwei Buttons.
2. „Text generieren" → Platzhalter-Text wird unten an den Inhalt angehängt.
3. „Inhalt optimieren" → Platzhalter mit der optimierten Fassung wird angehängt.
4. API-Key in den Einstellungen leeren → Button zeigt rote Fehlermeldung
   „Kein Claude API-Key hinterlegt".

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json src wpcontentai.php
git commit -m "feat: Gutenberg-Sidebar mit Generieren/Optimieren-Buttons"
```

---

### Task 6: CLAUDE.md

**Files:**
- Create: `CLAUDE.md`

- [ ] **Step 1: `CLAUDE.md` anlegen**

```markdown
# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Überblick

WPContentAI ist ein WordPress-Plugin, das Inhalte per Anthropic Claude API
generiert und optimiert. Bedienung über eine Gutenberg-Editor-Sidebar.

## Build-Befehle

- `npm install` – Abhängigkeiten installieren.
- `npm run build` – Sidebar aus `src/` nach `build/` bauen (Produktiv).
- `npm run start` – Watch-Modus für die Entwicklung.
- `php -l <datei>` – PHP-Syntaxprüfung einzelner Dateien.

WordPress lädt das Editor-Plugin nur, wenn `build/index.js` existiert –
nach Änderungen in `src/` immer `npm run build` ausführen.

## Architektur

Schlankes OOP-Plugin. `wpcontentai.php` ist der Einstiegspunkt: definiert
Konstanten, lädt die drei Klassen aus `includes/` und registriert Hooks.

- `includes/class-settings.php` – Admin-Menü + Einstellungsseite. Speichert
  API-Key und Modell in der Option `wpcontentai_settings`. `WPContentAI_Settings::get()`
  liefert die Einstellungen mit Defaults und ist die einzige Lesequelle dafür.
- `includes/class-claude.php` – Kapselt den Anthropic-API-Zugriff. Im Grundgerüst
  liefern `generate()` / `optimize()` Platzhalter-Text. Der echte
  `wp_remote_post`-Aufruf ist als auskommentierter `TODO`-Block vorhanden.
- `includes/class-rest.php` – REST-Endpoints unter `wpcontentai/v1`
  (`POST /generate`, `POST /optimize`). Prüft `edit_posts`-Rechte.

Datenfluss: Sidebar (`src/sidebar.js`) → `apiFetch` → REST-Endpoint →
`WPContentAI_Claude` → Antwort zurück in den Editor.

## Konventionen

- Jede PHP-Datei beginnt mit `defined( 'ABSPATH' ) || exit;`.
- Der API-Key bleibt serverseitig; er wird nie an das Frontend ausgegeben.
- Erlaubte Modelle sind in `WPContentAI_Settings::sanitize()` als Whitelist
  gepflegt – neue Modelle dort UND im Dropdown in `render_page()` ergänzen.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: CLAUDE.md für das Plugin"
```

---

## Self-Review

- **Spec-Abdeckung:** Bootstrap/Aktivierung (Task 1), Einstellungsseite mit
  API-Key + Modell-Dropdown (Task 2), Claude-Stub mit markiertem TODO (Task 3),
  REST-Endpoints mit Rechte-/Nonce-Prüfung (Task 4), Gutenberg-Sidebar +
  Asset-Loader + Build (Task 5), CLAUDE.md (Task 6). Alle Spec-Abschnitte abgedeckt.
- **Nonce:** `apiFetch` setzt den `wp_rest`-Nonce automatisch; zusätzlich
  schützt `permission_callback` jeden Endpoint – beides erfüllt die Spec.
- **Typkonsistenz:** `WPContentAI_Settings::get()`, `WPContentAI_Claude::generate()/optimize()`,
  `WPContentAI_REST` und der REST-Pfad `wpcontentai/v1` sind über alle Tasks
  identisch benannt.
