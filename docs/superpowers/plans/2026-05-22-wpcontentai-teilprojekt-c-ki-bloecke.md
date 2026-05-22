# WPContentAI Teilprojekt C – KI-Blöcke Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vier eigene Gutenberg-Blöcke (KI-Absatz, KI-Bild, KI-Überschrift+Abschnitt, KI-Zusammenfassung) ergänzen, die nach dem Generieren in Standard-Blöcke umwandeln.

**Architecture:** Ein neuer REST-Endpoint `/block` und eine Claude-Methode `block()` liefern den Inhalt. Die Blöcke werden rein in JavaScript registriert, teilen sich eine gemeinsame Editor-Komponente `AiBlockEdit` und ersetzen sich nach dem Generieren per `replaceBlocks` durch Standard-Blöcke (`save: () => null`).

**Tech Stack:** PHP (WordPress REST API), JavaScript/JSX (`@wordpress/scripts`, `@wordpress/blocks`, `@wordpress/block-editor`, `@wordpress/components`).

**Hinweis zu Tests:** Abnahme manuell (echte API-Aufrufe). PHP-Tasks enthalten eine Syntaxprüfung; die JS-Dateien werden in Task 5 gemeinsam gebaut und geprüft.

**PHP-Hinweis:** Falls `php` nicht im PATH liegt, `C:\xampp\php\php.exe` verwenden.

---

## Dateistruktur

- `includes/class-claude.php` – MODIFY: Methode `block()`
- `includes/class-rest.php` – MODIFY: Endpoint `/block`
- `src/blocks/shared.js` – CREATE: gemeinsame Editor-Komponente `AiBlockEdit`
- `src/blocks/ki-absatz.js` – CREATE
- `src/blocks/ki-bild.js` – CREATE
- `src/blocks/ki-ueberschrift.js` – CREATE
- `src/blocks/ki-zusammenfassung.js` – CREATE
- `src/blocks/index.js` – CREATE: importiert die vier Block-Dateien
- `src/index.js` – MODIFY: lädt zusätzlich `./blocks`

---

### Task 1: Claude – Methode block()

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
	 * Erzeugt Inhalt für einen einzelnen KI-Block.
	 *
	 * @param string $kind    'absatz', 'ueberschrift' oder 'zusammenfassung'.
	 * @param string $prompt  Prompt des Autors (leer bei 'zusammenfassung').
	 * @param string $context Bestehender Beitragstext als Kontext.
	 * @return array{heading:string,text:string}|WP_Error
	 */
	public function block( $kind, $prompt, $context ) {
		if ( 'absatz' === $kind ) {
			$system = 'Du bist ein erfahrener Redakteur. Schreibe einen einzelnen, gut lesbaren Absatz auf Deutsch zum vom Nutzer gewünschten Inhalt. Berücksichtige den bestehenden Beitragstext als Kontext. Gib nur den Absatztext zurück, keine Vorbemerkungen.';
			$user   = sprintf( 'Gewünschter Inhalt: %s. Bestehender Beitragstext als Kontext: %s', $prompt, $context );
			$text   = $this->call_api( $system, $user );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			return array(
				'heading' => '',
				'text'    => $text,
			);
		}

		if ( 'zusammenfassung' === $kind ) {
			$system = 'Du bist ein erfahrener Redakteur. Fasse den vom Nutzer gelieferten Beitragstext in einem kurzen Absatz auf Deutsch zusammen. Gib nur den Zusammenfassungstext zurück, keine Vorbemerkungen.';
			$user   = sprintf( 'Beitragstext: %s', $context );
			$text   = $this->call_api( $system, $user );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			return array(
				'heading' => '',
				'text'    => $text,
			);
		}

		if ( 'ueberschrift' === $kind ) {
			$system = 'Du bist ein erfahrener Redakteur. Erzeuge eine Zwischenüberschrift und einen dazu passenden Absatz auf Deutsch zum vom Nutzer gewünschten Inhalt. Berücksichtige den bestehenden Beitragstext als Kontext. '
				. 'Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne weiteren Text, in genau diesem Format: {"heading": "Überschrift", "text": "Absatztext"}.';
			$user = sprintf( 'Gewünschter Inhalt: %s. Bestehender Beitragstext als Kontext: %s', $prompt, $context );
			$text = $this->call_api( $system, $user );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			$data = $this->decode_json( $text );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( ! isset( $data['heading'] ) || ! isset( $data['text'] ) ) {
				return new WP_Error(
					'wpcontentai_parse',
					'Die KI-Antwort konnte nicht verarbeitet werden.',
					array( 'status' => 502 )
				);
			}
			return array(
				'heading' => (string) $data['heading'],
				'text'    => (string) $data['text'],
			);
		}

		return new WP_Error(
			'wpcontentai_bad_kind',
			'Unbekannter Block-Typ.',
			array( 'status' => 400 )
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
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: Claude block() für die KI-Blöcke"
```

---

### Task 2: REST – Endpoint /block

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

		register_rest_route(
			self::NAMESPACE,
			'/block',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'callback'            => array( $this, 'handle_block' ),
				'args'                => array(
					'kind'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'absatz', 'ueberschrift', 'zusammenfassung' ),
					),
					'prompt'  => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
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
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_block( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->block(
			$request->get_param( 'kind' ),
			$request->get_param( 'prompt' ),
			$request->get_param( 'context' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'heading' => $result['heading'],
				'text'    => $result['text'],
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

Als angemeldeter Admin `…/wp-json/wpcontentai/v1` aufrufen → sechs Routen
sichtbar: `/generate`, `/optimize`, `/image`, `/outline`, `/compose`, `/block`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-rest.php
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: REST-Endpoint /block"
```

---

### Task 3: Gemeinsame Block-Editor-Komponente

**Files:**
- Create: `src/blocks/shared.js`

- [ ] **Step 1: `src/blocks/shared.js` anlegen**

Gesamter Dateiinhalt:

```js
import { useBlockProps } from '@wordpress/block-editor';
import {
	Placeholder,
	TextControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Gemeinsames Editor-UI für die KI-Blöcke.
 *
 * Zeigt ein Eingabefeld (optional) und einen Button. Beim Generieren wird
 * `generate()` aufgerufen; bei Erfolg ersetzt sich der Block per
 * `replaceBlocks` durch die zurückgegebenen Standard-Blöcke.
 *
 * @param {Object}   props
 * @param {string}   props.clientId    Client-ID des Blocks.
 * @param {boolean}  props.hasPrompt   Ob ein Prompt-Eingabefeld gezeigt wird.
 * @param {string}   props.label       Beschriftung des Blocks.
 * @param {string}   props.buttonLabel Beschriftung des Buttons.
 * @param {Function} props.generate    async ( prompt, context ) => Block[].
 * @return {Element} Das Editor-Element.
 */
export default function AiBlockEdit( {
	clientId,
	hasPrompt,
	label,
	buttonLabel,
	generate,
} ) {
	const blockProps = useBlockProps();
	const [ prompt, setPrompt ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const context = useSelect(
		( select ) => select( editorStore ).getEditedPostContent(),
		[]
	);
	const { replaceBlocks } = useDispatch( blockEditorStore );

	const run = async () => {
		setBusy( true );
		setError( '' );
		try {
			const blocks = await generate( prompt, context );
			replaceBlocks( clientId, blocks );
		} catch ( e ) {
			setError( e.message || 'Unbekannter Fehler.' );
			setBusy( false );
		}
	};

	return (
		<div { ...blockProps }>
			<Placeholder label={ label }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ hasPrompt && (
					<TextControl
						__nextHasNoMarginBottom
						label="Prompt"
						value={ prompt }
						onChange={ setPrompt }
					/>
				) }
				<Button
					variant="primary"
					disabled={ busy || ( hasPrompt && ! prompt ) }
					onClick={ run }
				>
					{ buttonLabel }
				</Button>
				{ busy && <Spinner /> }
			</Placeholder>
		</div>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/blocks/shared.js
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: gemeinsame Editor-Komponente für KI-Blöcke"
```

(Der Build erfolgt gemeinsam in Task 5.)

---

### Task 4: Die vier KI-Blöcke

**Files:**
- Create: `src/blocks/ki-absatz.js`
- Create: `src/blocks/ki-bild.js`
- Create: `src/blocks/ki-ueberschrift.js`
- Create: `src/blocks/ki-zusammenfassung.js`
- Create: `src/blocks/index.js`

- [ ] **Step 1: `src/blocks/ki-absatz.js` anlegen**

```js
import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-absatz', {
	apiVersion: 3,
	title: 'KI-Absatz',
	category: 'text',
	icon: 'editor-paragraph',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt
			label="KI-Absatz"
			buttonLabel="Absatz erzeugen"
			generate={ async ( prompt, context ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/block',
					method: 'POST',
					data: { kind: 'absatz', prompt, context },
				} );
				return [
					createBlock( 'core/paragraph', { content: r.text } ),
				];
			} }
		/>
	),
	save: () => null,
} );
```

- [ ] **Step 2: `src/blocks/ki-bild.js` anlegen**

```js
import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-bild', {
	apiVersion: 3,
	title: 'KI-Bild',
	category: 'media',
	icon: 'format-image',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt
			label="KI-Bild"
			buttonLabel="Bild erzeugen"
			generate={ async ( prompt ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/image',
					method: 'POST',
					data: { prompt },
				} );
				return [
					createBlock( 'core/image', { id: r.id, url: r.url } ),
				];
			} }
		/>
	),
	save: () => null,
} );
```

- [ ] **Step 3: `src/blocks/ki-ueberschrift.js` anlegen**

```js
import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-ueberschrift', {
	apiVersion: 3,
	title: 'KI-Überschrift + Abschnitt',
	category: 'text',
	icon: 'heading',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt
			label="KI-Überschrift + Abschnitt"
			buttonLabel="Überschrift + Abschnitt erzeugen"
			generate={ async ( prompt, context ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/block',
					method: 'POST',
					data: { kind: 'ueberschrift', prompt, context },
				} );
				return [
					createBlock( 'core/heading', {
						level: 2,
						content: r.heading,
					} ),
					createBlock( 'core/paragraph', { content: r.text } ),
				];
			} }
		/>
	),
	save: () => null,
} );
```

- [ ] **Step 4: `src/blocks/ki-zusammenfassung.js` anlegen**

```js
import { registerBlockType, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import AiBlockEdit from './shared';

registerBlockType( 'wpcontentai/ki-zusammenfassung', {
	apiVersion: 3,
	title: 'KI-Zusammenfassung',
	category: 'text',
	icon: 'excerpt-view',
	edit: ( props ) => (
		<AiBlockEdit
			clientId={ props.clientId }
			hasPrompt={ false }
			label="KI-Zusammenfassung"
			buttonLabel="Zusammenfassung erzeugen"
			generate={ async ( prompt, context ) => {
				const r = await apiFetch( {
					path: '/wpcontentai/v1/block',
					method: 'POST',
					data: { kind: 'zusammenfassung', prompt: '', context },
				} );
				return [
					createBlock( 'core/paragraph', { content: r.text } ),
				];
			} }
		/>
	),
	save: () => null,
} );
```

- [ ] **Step 5: `src/blocks/index.js` anlegen**

```js
import './ki-absatz';
import './ki-bild';
import './ki-ueberschrift';
import './ki-zusammenfassung';
```

- [ ] **Step 6: Commit**

```bash
git add src/blocks/ki-absatz.js src/blocks/ki-bild.js src/blocks/ki-ueberschrift.js src/blocks/ki-zusammenfassung.js src/blocks/index.js
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: die vier KI-Blöcke"
```

(Der Build erfolgt gemeinsam in Task 5.)

---

### Task 5: Blöcke laden und Build

**Files:**
- Modify: `src/index.js`

- [ ] **Step 1: `src/index.js` vollständig ersetzen**

Gesamter neuer Dateiinhalt:

```js
import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './sidebar';
import './blocks';

registerPlugin( 'wpcontentai', {
	render: Sidebar,
} );
```

- [ ] **Step 2: Build erzeugen**

Run: `npm run build`
Expected: webpack kompiliert ohne Fehler; `build/index.js` und
`build/index.asset.php` werden aktualisiert. Schlägt der Build fehl (z. B.
JSX-Fehler in einer Block-Datei), die Fehlermeldung melden und das Problem in
der betroffenen Datei beheben.

- [ ] **Step 3: Manuelle Verifikation**

Beitrag öffnen, Block-Inserter öffnen → die vier Blöcke „KI-Absatz", „KI-Bild",
„KI-Überschrift + Abschnitt", „KI-Zusammenfassung" sind verfügbar. Einen Block
einfügen, Prompt eingeben (außer KI-Zusammenfassung), Button klicken → der
KI-Block wird durch das erzeugte Ergebnis (Standard-Blöcke) ersetzt. Die
WPContentAI-Seitenleiste (Wizard + Werkzeuge) funktioniert weiterhin.

- [ ] **Step 4: Commit**

```bash
git add src/index.js build
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -m "feat: KI-Blöcke im Editor laden"
```

---

## Self-Review

- **Spec-Abdeckung:** Claude-Methode `block()` mit den drei Modi und
  JSON-Parsing für `ueberschrift` (Task 1); REST-Endpoint `/block` mit
  `enum`-validiertem `kind` (Task 2); gemeinsame Komponente `AiBlockEdit` mit
  `replaceBlocks`-Umwandlung (Task 3); die vier Blöcke mit `save: () => null`
  und `registerBlockType` (Task 4); Laden der Blöcke + Build (Task 5). Alle
  Spec-Abschnitte abgedeckt.
- **Typkonsistenz:** `block()` liefert `{heading, text}`; `handle_block` reicht
  genau `heading` + `text` als REST-Antwort durch; die Block-Dateien lesen
  `r.text` (Absatz/Zusammenfassung) bzw. `r.heading` + `r.text`
  (Überschrift) — konsistent. KI-Bild nutzt `/image` → `{id, url}`. Die
  `kind`-Werte `absatz`/`ueberschrift`/`zusammenfassung` stimmen zwischen
  Block-Dateien, REST-`enum` und `block()` überein.
- **Komponenten-Schnittstelle:** `AiBlockEdit` erwartet
  `clientId`, `hasPrompt`, `label`, `buttonLabel`, `generate` — alle vier
  Block-Dateien übergeben genau diese Props. `generate` bekommt
  `( prompt, context )` und liefert ein Block-Array.
- **Platzhalter-Scan:** keine offenen TBD/TODO.
