# WPContentAI Teilprojekt B – Beitrags-Wizard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Einen vierstufigen Beitrags-Wizard in die Editor-Seitenleiste einbauen, der aus einem Thema einen kompletten Beitrag (Titel, Überschriften, Absätze, Bilder) als Gutenberg-Blöcke erzeugt.

**Architecture:** Zweiphasiger Ablauf — `/outline` liefert eine JSON-Gliederung, `/compose` den vollständigen Beitrag als JSON-Blockliste. Die Seitenleiste löst Bild-Blöcke einzeln über den bestehenden `/image`-Endpoint auf und ersetzt damit Titel und Inhalt des Beitrags. Die JS-Seitenleiste wird in drei fokussierte Dateien aufgeteilt.

**Tech Stack:** PHP (WordPress HTTP/REST API), JavaScript/JSX (`@wordpress/scripts`, `@wordpress/blocks`, `@wordpress/block-editor`, `@wordpress/components`).

**Hinweis zu Tests:** Abnahme manuell (echte API-Aufrufe). Jede PHP-Task enthält eine Syntaxprüfung; die JS-Tasks werden in Task 5 gemeinsam gebaut und geprüft.

**PHP-Hinweis:** Falls `php` nicht im PATH liegt, `C:\xampp\php\php.exe` verwenden.

---

## Dateistruktur

- `includes/class-claude.php` – MODIFY: Methoden `outline()` + `compose()`, gemeinsame Hilfsmethoden
- `includes/class-rest.php` – MODIFY: Endpoints `/outline` + `/compose`
- `src/tools.js` – CREATE: „Inhalt optimieren" + „Bild einfügen"
- `src/wizard.js` – CREATE: vierstufiger Wizard
- `src/sidebar.js` – MODIFY: wird zur Schale (rendert Wizard + Tools)

---

### Task 1: Claude – outline() und compose()

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

	const ENDPOINT   = 'https://api.anthropic.com/v1/messages';
	const MAX_TOKENS = 8192;

	/**
	 * Erzeugt neuen Text zu einem Thema.
	 *
	 * @param string $prompt Themenbeschreibung des Autors.
	 * @return string|WP_Error
	 */
	public function generate( $prompt ) {
		$system = 'Du bist ein erfahrener Redakteur. Schreibe einen gut lesbaren, strukturierten Blogbeitrag auf Deutsch zum vom Nutzer genannten Thema. Gib nur den Beitragstext zurück, keine Vorbemerkungen.';
		return $this->call_api( $system, $prompt );
	}

	/**
	 * Optimiert vorhandenen Inhalt.
	 *
	 * @param string $content Bestehender Inhalt.
	 * @return string|WP_Error
	 */
	public function optimize( $content ) {
		$system = 'Du bist ein erfahrener Lektor. Verbessere den vom Nutzer gelieferten Text auf Deutsch hinsichtlich Lesbarkeit, Stil und Klarheit. Gib nur den überarbeiteten Text zurück.';
		return $this->call_api( $system, $content );
	}

	/**
	 * Erzeugt eine Gliederung (Titel + Abschnittsüberschriften) als JSON.
	 *
	 * @param string $topic       Thema.
	 * @param string $length      Länge.
	 * @param string $tone        Tonfall.
	 * @param string $headings    'mit' oder 'ohne'.
	 * @param int    $image_count Anzahl geplanter Bilder.
	 * @return array{title:string,sections:array}|WP_Error
	 */
	public function outline( $topic, $length, $tone, $headings, $image_count ) {
		$headings_rule = ( 'ohne' === $headings )
			? 'Das Feld "sections" muss eine leere Liste sein.'
			: 'Schlage 3 bis 6 Abschnittsüberschriften vor.';

		$system = 'Du bist ein erfahrener Redakteur. Erstelle die Gliederung für einen Blogbeitrag. '
			. 'Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne weiteren Text, in genau diesem Format: '
			. '{"title": "Titel", "sections": ["Überschrift 1", "Überschrift 2"]}. '
			. $headings_rule;

		$user = sprintf(
			'Thema: %s. Länge: %s. Tonfall: %s. Geplante Bilder: %d.',
			$topic,
			$length,
			$tone,
			$image_count
		);

		$text = $this->call_api( $system, $user );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$data = $this->decode_json( $text );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['title'] ) || ! isset( $data['sections'] ) || ! is_array( $data['sections'] ) ) {
			return new WP_Error(
				'wpcontentai_parse',
				'Die KI-Antwort konnte nicht verarbeitet werden.',
				array( 'status' => 502 )
			);
		}

		return array(
			'title'    => (string) $data['title'],
			'sections' => array_values( array_map( 'strval', $data['sections'] ) ),
		);
	}

	/**
	 * Erzeugt den vollständigen Beitrag als JSON-Blockliste.
	 *
	 * @param string       $topic       Thema.
	 * @param string       $length      Länge.
	 * @param string       $tone        Tonfall.
	 * @param int          $image_count Anzahl Bilder.
	 * @param array|string $outline     Bestätigte Gliederung aus outline().
	 * @return array{title:string,blocks:array}|WP_Error
	 */
	public function compose( $topic, $length, $tone, $image_count, $outline ) {
		$outline_json = is_string( $outline ) ? $outline : wp_json_encode( $outline );

		$system = 'Du bist ein erfahrener Redakteur. Schreibe den vollständigen Blogbeitrag auf Deutsch. '
			. 'Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne weiteren Text, in genau diesem Format: '
			. '{"title": "Titel", "blocks": [{"type": "heading", "text": "..."}, {"type": "paragraph", "text": "..."}, {"type": "image", "prompt": "..."}]}. '
			. 'Erlaubte Block-Typen: "heading" (Abschnittsüberschrift), "paragraph" (Textabsatz), '
			. '"image" (Bild; das Feld "prompt" beschreibt das gewünschte Bild auf Deutsch). '
			. sprintf( 'Füge genau %d Block(e) vom Typ "image" passend im Beitrag verteilt ein.', (int) $image_count );

		$user = sprintf(
			'Thema: %s. Länge: %s. Tonfall: %s. Halte dich an diese bestätigte Gliederung: %s',
			$topic,
			$length,
			$tone,
			$outline_json
		);

		$text = $this->call_api( $system, $user );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$data = $this->decode_json( $text );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['title'] ) || ! isset( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
			return new WP_Error(
				'wpcontentai_parse',
				'Die KI-Antwort konnte nicht verarbeitet werden.',
				array( 'status' => 502 )
			);
		}

		return array(
			'title'  => (string) $data['title'],
			'blocks' => $data['blocks'],
		);
	}

	/**
	 * Ruft die Claude API mit System- und Nutzer-Prompt auf.
	 *
	 * @param string $system System-Prompt.
	 * @param string $user   Nutzer-Eingabe.
	 * @return string|WP_Error Antworttext oder Fehler.
	 */
	private function call_api( $system, $user ) {
		$settings = WPContentAI_Settings::get();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error(
				'wpcontentai_no_key',
				'Kein Claude API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.',
				array( 'status' => 400 )
			);
		}

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
						'max_tokens' => self::MAX_TOKENS,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user,
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

	/**
	 * Dekodiert einen JSON-String von Claude und entfernt ggf. Markdown-Code-Zäune.
	 *
	 * @param string $text Rohtext der Antwort.
	 * @return array|WP_Error
	 */
	private function decode_json( $text ) {
		$trimmed = trim( $text );

		if ( 0 === strpos( $trimmed, '```' ) ) {
			$trimmed = preg_replace( '/^```[a-zA-Z]*\s*/', '', $trimmed );
			$trimmed = preg_replace( '/\s*```$/', '', $trimmed );
			$trimmed = trim( $trimmed );
		}

		$data = json_decode( $trimmed, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wpcontentai_parse',
				'Die KI-Antwort konnte nicht verarbeitet werden.',
				array( 'status' => 502 )
			);
		}

		return $data;
	}
}
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l includes/class-claude.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-claude.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Claude outline() und compose() für den Wizard"
```

---

### Task 2: REST – Endpoints /outline und /compose

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

		register_rest_route(
			self::NAMESPACE,
			'/outline',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'callback'            => array( $this, 'handle_outline' ),
				'args'                => $this->wizard_args( false ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/compose',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'callback'            => array( $this, 'handle_compose' ),
				'args'                => $this->wizard_args( true ),
			)
		);
	}

	/**
	 * Gemeinsame Argument-Definition für /outline und /compose.
	 *
	 * @param bool $is_compose true für /compose (mit outline-Feld statt headings).
	 * @return array
	 */
	private function wizard_args( $is_compose ) {
		$args = array(
			'topic'       => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'length'      => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tone'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'image_count' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		if ( $is_compose ) {
			$args['outline'] = array(
				'type'     => 'object',
				'required' => true,
			);
		} else {
			$args['headings'] = array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			);
		}

		return $args;
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
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_outline( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->outline(
			$request->get_param( 'topic' ),
			$request->get_param( 'length' ),
			$request->get_param( 'tone' ),
			$request->get_param( 'headings' ),
			(int) $request->get_param( 'image_count' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_compose( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->compose(
			$request->get_param( 'topic' ),
			$request->get_param( 'length' ),
			$request->get_param( 'tone' ),
			(int) $request->get_param( 'image_count' ),
			$request->get_param( 'outline' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
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

Als angemeldeter Admin `…/wp-json/wpcontentai/v1` aufrufen → fünf Routen
sichtbar: `/generate`, `/optimize`, `/image`, `/outline`, `/compose`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-rest.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: REST-Endpoints /outline und /compose"
```

---

### Task 3: Werkzeug-Komponente (src/tools.js)

**Files:**
- Create: `src/tools.js`

- [ ] **Step 1: `src/tools.js` anlegen**

Gesamter Dateiinhalt:

```js
import {
	PanelBody,
	Button,
	Notice,
	TextControl,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

/**
 * Werkzeug-Panel: vorhandenen Inhalt optimieren und einzelne Bilder einfügen.
 */
export default function Tools() {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ imagePrompt, setImagePrompt ] = useState( '' );

	const content = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { insertBlocks } = useDispatch( blockEditorStore );

	const optimize = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: '/wpcontentai/v1/optimize',
				method: 'POST',
				data: { input: content },
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
			insertBlocks(
				createBlock( 'core/image', {
					id: result.id,
					url: result.url,
				} )
			);
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	return (
		<PanelBody title="Werkzeuge" initialOpen={ false }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<Button variant="secondary" disabled={ busy } onClick={ optimize }>
				Inhalt optimieren
			</Button>
			<p />
			<TextControl
				__nextHasNoMarginBottom
				label="Bild-Prompt"
				value={ imagePrompt }
				onChange={ setImagePrompt }
			/>
			<Button
				variant="secondary"
				disabled={ busy || ! imagePrompt }
				onClick={ generateImage }
			>
				Bild einfügen
			</Button>
			{ busy && <Spinner /> }
		</PanelBody>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/tools.js
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Werkzeug-Komponente (Optimieren + Bild einfügen)"
```

(Der Build erfolgt gemeinsam in Task 5.)

---

### Task 4: Wizard-Komponente (src/wizard.js)

**Files:**
- Create: `src/wizard.js`

- [ ] **Step 1: `src/wizard.js` anlegen**

Gesamter Dateiinhalt:

```js
import {
	PanelBody,
	Button,
	Notice,
	TextControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

const LENGTHS = [
	{ label: 'Kurz', value: 'kurz' },
	{ label: 'Mittel', value: 'mittel' },
	{ label: 'Lang', value: 'lang' },
];
const TONES = [
	{ label: 'Sachlich', value: 'sachlich' },
	{ label: 'Locker', value: 'locker' },
	{ label: 'Werblich', value: 'werblich' },
	{ label: 'Fachlich', value: 'fachlich' },
];
const IMAGE_COUNTS = [
	{ label: '0', value: '0' },
	{ label: '1', value: '1' },
	{ label: '2', value: '2' },
	{ label: '3', value: '3' },
];
const HEADINGS = [
	{ label: 'Mit Überschriften', value: 'mit' },
	{ label: 'Ohne Überschriften', value: 'ohne' },
];

/**
 * Vierstufiger Wizard: Thema → Optionen → Gliederung → Erzeugen.
 */
export default function Wizard() {
	const [ step, setStep ] = useState( 1 );
	const [ topic, setTopic ] = useState( '' );
	const [ length, setLength ] = useState( 'mittel' );
	const [ tone, setTone ] = useState( 'sachlich' );
	const [ imageCount, setImageCount ] = useState( '1' );
	const [ headings, setHeadings ] = useState( 'mit' );
	const [ outline, setOutline ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ progress, setProgress ] = useState( '' );

	const postContent = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const postTitle = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { resetBlocks } = useDispatch( blockEditorStore );

	const loadOutline = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: '/wpcontentai/v1/outline',
				method: 'POST',
				data: {
					topic,
					length,
					tone,
					headings,
					image_count: parseInt( imageCount, 10 ),
				},
			} );
			setOutline( result );
			setStep( 3 );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
		}
		setBusy( false );
	};

	const composePost = async () => {
		if (
			( postTitle || postContent ) &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				'Vorhandener Titel und Inhalt werden ersetzt. Fortfahren?'
			)
		) {
			return;
		}
		setBusy( true );
		setError( '' );
		setStep( 4 );
		setProgress( 'Text wird erzeugt …' );
		try {
			const composed = await apiFetch( {
				path: '/wpcontentai/v1/compose',
				method: 'POST',
				data: {
					topic,
					length,
					tone,
					image_count: parseInt( imageCount, 10 ),
					outline,
				},
			} );

			const blocks = [];
			const imageTotal = composed.blocks.filter(
				( b ) => b.type === 'image'
			).length;
			let imageDone = 0;

			for ( const item of composed.blocks ) {
				if ( item.type === 'heading' ) {
					blocks.push(
						createBlock( 'core/heading', {
							level: 2,
							content: item.text || '',
						} )
					);
				} else if ( item.type === 'paragraph' ) {
					blocks.push(
						createBlock( 'core/paragraph', {
							content: item.text || '',
						} )
					);
				} else if ( item.type === 'image' ) {
					imageDone += 1;
					setProgress( `Bild ${ imageDone } von ${ imageTotal } …` );
					try {
						const img = await apiFetch( {
							path: '/wpcontentai/v1/image',
							method: 'POST',
							data: { prompt: item.prompt || '' },
						} );
						blocks.push(
							createBlock( 'core/image', {
								id: img.id,
								url: img.url,
							} )
						);
					} catch ( e ) {
						blocks.push(
							createBlock( 'core/paragraph', {
								content: '[Bild konnte nicht erzeugt werden]',
							} )
						);
					}
				}
			}

			editPost( { title: composed.title } );
			resetBlocks( blocks );
			setProgress( 'Beitrag fertig erzeugt.' );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
			setStep( 3 );
		}
		setBusy( false );
	};

	const restart = () => {
		setStep( 1 );
		setOutline( null );
		setProgress( '' );
		setError( '' );
	};

	return (
		<PanelBody title="Beitrags-Wizard">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ step === 1 && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						label="Thema des Beitrags"
						value={ topic }
						onChange={ setTopic }
					/>
					<Button
						variant="primary"
						disabled={ ! topic }
						onClick={ () => setStep( 2 ) }
					>
						Weiter
					</Button>
				</>
			) }

			{ step === 2 && (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label="Länge"
						value={ length }
						options={ LENGTHS }
						onChange={ setLength }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label="Tonfall"
						value={ tone }
						options={ TONES }
						onChange={ setTone }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label="Anzahl Bilder"
						value={ imageCount }
						options={ IMAGE_COUNTS }
						onChange={ setImageCount }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label="Überschriften"
						value={ headings }
						options={ HEADINGS }
						onChange={ setHeadings }
					/>
					<Button variant="secondary" onClick={ () => setStep( 1 ) }>
						Zurück
					</Button>{ ' ' }
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ loadOutline }
					>
						Gliederung erstellen
					</Button>
					{ busy && <Spinner /> }
				</>
			) }

			{ step === 3 && outline && (
				<>
					<p>
						<strong>{ outline.title }</strong>
					</p>
					{ outline.sections.length > 0 && (
						<ul>
							{ outline.sections.map( ( s, i ) => (
								<li key={ i }>{ s }</li>
							) ) }
						</ul>
					) }
					<p>Geplante Bilder: { imageCount }</p>
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ loadOutline }
					>
						Neu generieren
					</Button>{ ' ' }
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ composePost }
					>
						Passt, Beitrag erzeugen
					</Button>
					{ busy && <Spinner /> }
				</>
			) }

			{ step === 4 && (
				<>
					<p>{ progress }</p>
					{ busy && <Spinner /> }
					{ ! busy && (
						<Button variant="secondary" onClick={ restart }>
							Neuen Beitrag starten
						</Button>
					) }
				</>
			) }
		</PanelBody>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/wizard.js
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: vierstufige Wizard-Komponente"
```

(Der Build erfolgt gemeinsam in Task 5.)

---

### Task 5: Seitenleisten-Schale und Build

**Files:**
- Modify: `src/sidebar.js`

- [ ] **Step 1: `src/sidebar.js` vollständig ersetzen**

Gesamter neuer Dateiinhalt:

```js
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '@wordpress/edit-post';
import Wizard from './wizard';
import Tools from './tools';

const SIDEBAR_NAME = 'wpcontentai-sidebar';

export default function Sidebar() {
	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				WPContentAI
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title="WPContentAI">
				<Wizard />
				<Tools />
			</PluginSidebar>
		</>
	);
}
```

- [ ] **Step 2: Build erzeugen**

Run: `npm run build`
Expected: webpack kompiliert ohne Fehler; `build/index.js` und
`build/index.asset.php` werden aktualisiert. Schlägt der Build fehl (z. B.
JSX-Fehler in `wizard.js` oder `tools.js`), die Fehlermeldung melden und das
Problem in der betroffenen Datei beheben.

- [ ] **Step 3: Manuelle Verifikation**

Beitrag öffnen, WPContentAI-Seitenleiste öffnen. Panel „Beitrags-Wizard":
Thema eingeben → Weiter → Optionen wählen → „Gliederung erstellen" → Titel und
Überschriften erscheinen → „Passt, Beitrag erzeugen" → Fortschritt läuft → der
Beitrag wird mit Titel, Überschriften, Absätzen und Bildern gefüllt.
Panel „Werkzeuge": „Inhalt optimieren" funktioniert weiterhin.

- [ ] **Step 4: Commit**

```bash
git add src/sidebar.js build
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Seitenleiste als Schale für Wizard und Werkzeuge"
```

---

## Self-Review

- **Spec-Abdeckung:** Vierstufiger Wizard mit Thema/Optionen/Vorschau/Erzeugen
  (`wizard.js`, Task 4); Optionen Länge/Tonfall/Bildanzahl/Überschriften
  (Task 4); Phase 1 `/outline` und Phase 2 `/compose` (Tasks 1+2);
  JSON-Robustheit mit Code-Zaun-Entfernung (`decode_json`, Task 1);
  Bild-Auflösung über `/image` mit Fortschritt und Fehler-Fallback (Task 4);
  `resetBlocks` + Sicherheitsabfrage (Task 4); Werkzeug-Panel mit „Optimieren"
  (`tools.js`, Task 3); Seitenleisten-Schale (Task 5). Alle Spec-Abschnitte
  abgedeckt.
- **Typkonsistenz:** `outline()` liefert `{title, sections}`, `compose()`
  liefert `{title, blocks}`. `handle_outline` / `handle_compose` reichen diese
  unverändert als REST-Antwort durch. `wizard.js` liest `outline.title`,
  `outline.sections`, `composed.title`, `composed.blocks` mit den Block-Feldern
  `type`, `text`, `prompt` — konsistent mit dem in `compose()` vorgegebenen
  JSON-Format. Der `/image`-Endpoint liefert weiterhin `{id, url}`.
- **Methoden-Konsistenz:** `class-claude.php` wird vollständig ersetzt; die
  bisherige private Methode `request()` entfällt, `generate()` / `optimize()`
  rufen jetzt direkt `call_api()`. Kein toter Verweis.
- **Platzhalter-Scan:** keine offenen TBD/TODO. Der `eslint-disable`-Kommentar
  vor `window.confirm` ist beabsichtigt und kein Platzhalter.
