# WPContentAI – Umstellung auf den WordPress-7.0-AI-Client

Datum: 2026-05-22
Status: Freigegeben

## Zweck

WPContentAI verwaltet bisher eigene API-Schlüssel (Claude, Gemini) und ruft die
externen APIs direkt per `wp_remote_post` auf. WordPress 7.0 bringt einen
Core-AI-Client mit zentraler Schlüsselverwaltung (Konnektoren). Diese Umstellung
ersetzt die Direktaufrufe durch den Core-AI-Client. Das Plugin verwaltet danach
**keine eigenen Schlüssel** mehr — sie kommen aus „Einstellungen → Konnektoren".

## Ausgangslage

Aktueller Stand (Grundgerüst + Teilprojekte A/B/C):
- `wpcontentai.php` – lädt vier Klassen, registriert Hooks, Aktivierungshook
  setzt die Option `wpcontentai_settings`.
- `includes/class-settings.php` – `WPContentAI_Settings`, Einstellungsseite mit
  Claude-API-Key, Modell, Gemini-API-Key.
- `includes/class-claude.php` – `WPContentAI_Claude`; `generate()`,
  `optimize()`, `outline()`, `compose()`, `block()`; privat `call_api()`
  (`wp_remote_post` an die Anthropic API) und `decode_json()`.
- `includes/class-gemini.php` – `WPContentAI_Gemini`; `generate( $prompt )`
  ruft die Gemini API, lädt das Bild in die Mediathek.
- `includes/class-rest.php` – `WPContentAI_REST`; Endpoints `/generate`,
  `/optimize`, `/image`, `/outline`, `/compose`, `/block`.
- `src/` – Sidebar, Wizard, Werkzeuge, KI-Blöcke (unverändert).

## Verifizierte WordPress-7.0-API

Geprüft in `wp-includes/ai-client/` der Zielinstallation (WordPress 7.0):

- `wp_ai_client_prompt( string $prompt ) : WP_AI_Client_Prompt_Builder` —
  Einstiegspunkt.
- `wp_supports_ai() : bool` — ob KI-Funktionen verfügbar sind.
- Builder-Methoden (snake_case, fluent):
  - `using_system_instruction( string )`
  - `using_max_tokens( int )`
  - `using_request_options( RequestOptions )` — u. a. Timeout
  - `as_json_response( ?array $schema = null )` — erzwingt JSON-Ausgabe
  - `generate_text() : string|WP_Error`
  - `generate_image() : File|WP_Error`
  - `is_supported_for_text_generation() : bool`
  - `is_supported_for_image_generation() : bool`
- Fehler werden als `WP_Error` zurückgegeben, mit `status` (HTTP-Code) in den
  Fehlerdaten.
- `File`-DTO (`WordPress\AiClient\Files\DTO\File`): `getBase64Data() : ?string`,
  `getUrl() : ?string`, `getMimeType() : string`, `isInline() : bool`,
  `isRemote() : bool`, `isImage() : bool`.
- `RequestOptions` (`WordPress\AiClient\Providers\Http\DTO\RequestOptions`):
  `RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 60.0 ) )`.

## Voraussetzungen (vom Betreiber erfüllt)

- WordPress 7.0.
- Provider-Plugins „AI Provider for Anthropic" und „AI Provider for Google"
  installiert.
- Claude- und Gemini-Schlüssel in „Einstellungen → Konnektoren" eingetragen.

## Änderungen im Detail

### 1. `includes/class-settings.php` – löschen

Die Datei wird entfernt. Die Klasse `WPContentAI_Settings` und die eigene
Einstellungsseite entfallen vollständig. Schlüssel und Modell werden nicht mehr
vom Plugin verwaltet.

### 2. `wpcontentai.php`

- Plugin-Header: `Requires at least: 7.0`.
- `require_once` für `includes/class-settings.php` entfernen.
- `require_once` für `includes/class-gemini.php` ersetzen durch
  `includes/class-image.php`.
- In `wpcontentai_init()` das `new WPContentAI_Settings()` entfernen.
- Aktivierungsfunktion `wpcontentai_activate()` und `register_activation_hook`
  entfernen (es gibt keine Option mehr zu setzen).
- Neue Funktion `wpcontentai_check_ai_client()` am `admin_notices`-Hook:
  Ist `function_exists( 'wp_ai_client_prompt' )` falsch, eine Admin-Notice
  ausgeben: „WPContentAI benötigt WordPress 7.0 mit aktiviertem KI-Client und
  ein KI-Provider-Plugin (Konnektoren)."

### 3. `includes/class-claude.php`

- `WPContentAI_Settings` wird nicht mehr verwendet; kein `api_key`, kein
  `model`, kein `ENDPOINT`.
- Konstante `MAX_TOKENS = 8192` bleibt.
- `call_api( string $system, string $user, bool $json = false ) : string|WP_Error`:
  - Wenn `! function_exists( 'wp_ai_client_prompt' )` → `WP_Error`
    `wpcontentai_no_ai_client` (`status` 501) mit Hinweis auf WordPress 7.0 /
    Provider-Plugin.
  - Builder aufbauen:
    ```
    $builder = wp_ai_client_prompt( $user )
        ->using_system_instruction( $system )
        ->using_max_tokens( self::MAX_TOKENS )
        ->using_request_options(
            \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
                array( \WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => 60.0 )
            )
        );
    if ( $json ) {
        $builder = $builder->as_json_response();
    }
    return $builder->generate_text();
    ```
  - `generate_text()` liefert `string` oder `WP_Error` (mit `status`) — der
    Fehler wird unverändert durchgereicht.
- `generate()` und `optimize()`: rufen `call_api( $system, $eingabe )` ohne
  JSON-Flag — unverändertes Verhalten.
- `outline()`, `compose()`, `block()` (Variante `ueberschrift`): rufen
  `call_api( $system, $user, true )` mit JSON-Flag. Der System-Prompt darf den
  bisherigen JSON-Format-Hinweis behalten (schadet nicht).
- `decode_json()` bleibt unverändert als Sicherheitsnetz (entfernt evtl.
  Markdown-Code-Zäune, dekodiert).
- Öffentliche Signaturen von `generate()`/`optimize()`/`outline()`/`compose()`/
  `block()` bleiben gleich → `class-rest.php` muss daran nichts ändern.

### 4. `includes/class-gemini.php` → `includes/class-image.php`

- Datei umbenannt, Klasse `WPContentAI_Gemini` → `WPContentAI_Image` (der
  Anbieter ist nicht mehr fest Gemini — der AI-Client wählt ihn).
- `generate( string $prompt ) : array{id:int,url:string}|WP_Error`:
  - Wenn `! function_exists( 'wp_ai_client_prompt' )` → `WP_Error`
    `wpcontentai_no_ai_client` (`status` 501).
  - `$file = wp_ai_client_prompt( $prompt )->generate_image();`
  - Ist `$file` ein `WP_Error` → zurückgeben.
  - Bilddaten ermitteln:
    - `$file->getBase64Data()` nicht leer → base64 dekodieren.
    - sonst `$file->isRemote()` und `$file->getUrl()` → Datei per
      `wp_remote_get()` herunterladen; HTTP-Fehler → `WP_Error`
      `wpcontentai_image_fetch` (`status` 502).
    - sonst → `WP_Error` `wpcontentai_no_image` (`status` 502).
  - MIME-Typ aus `$file->getMimeType()`.
  - Die bestehende Mediathek-Logik (Datei ins Upload-Verzeichnis schreiben,
    `wp_insert_attachment`, `wp_generate_attachment_metadata`,
    `wp_update_attachment_metadata`) wird übernommen — nur die Datenquelle
    ändert sich. Rückgabe weiterhin `array( 'id' => …, 'url' => … )`.
- Der direkte Gemini-API-Aufruf, der Gemini-Endpoint und die
  `gemini_key`-Prüfung entfallen.

### 5. `includes/class-rest.php`

- `handle_image()`: `new WPContentAI_Image()` statt `new WPContentAI_Gemini()`.
- Sonst unverändert. Alle sechs Endpoints und die Rechteprüfungen bleiben.

### 6. `CLAUDE.md`

Aktualisieren: keine eigene Einstellungsseite mehr; das Plugin nutzt den
WordPress-7.0-AI-Client; Voraussetzung sind WordPress 7.0 und die
KI-Provider-Plugins; Schlüssel werden in den Konnektoren verwaltet. Die
Architektur-Beschreibung und die Konventionen entsprechend anpassen
(`class-image.php` statt `class-settings.php`/`class-gemini.php`).

## Datenfluss (nach der Umstellung)

1. Editor (Sidebar / Wizard / KI-Blöcke) → REST-Endpoint.
2. Endpoint → `WPContentAI_Claude` bzw. `WPContentAI_Image`.
3. Diese rufen `wp_ai_client_prompt()` auf; der AI-Client wählt Provider/Modell
   automatisch und nutzt die in den Konnektoren hinterlegten Schlüssel.
4. Text → zurück in den Editor. Bild → `File`-Objekt → Mediathek-Anhang.

## Sicherheit & Fehlerbehandlung

- Keine API-Schlüssel mehr im Plugin oder in `wp_options` — komplett bei
  WordPress/Konnektoren.
- REST-Rechte unverändert: `edit_posts` für Text-Endpoints, `upload_files` für
  `/image`.
- Fehlt der AI-Client/ein Provider → `WP_Error` mit `status` und klarer
  Meldung; zusätzlich eine Admin-Notice.
- `WP_Error` aus dem AI-Client trägt bereits einen HTTP-`status`; er wird
  unverändert über die REST-Antwort an die Editor-Oberfläche durchgereicht.
- Timeout pro Anfrage 60 s (für lange `compose`-Aufrufe).

## Tests (manuell)

Voraussetzung: Konnektoren mit gültigem Claude- und Gemini-Schlüssel.

1. Plugin aktivieren — keine PHP-Fehler, kein Menüpunkt „WPContentAI" mehr.
2. Sidebar „Inhalt optimieren" liefert echten überarbeiteten Text.
3. Wizard erzeugt Gliederung und kompletten Beitrag (Titel, Überschriften,
   Absätze, Bilder).
4. KI-Blöcke (Absatz, Bild, Überschrift+Abschnitt, Zusammenfassung) erzeugen
   Inhalt; KI-Bild legt das Bild in der Mediathek ab.
5. Provider in den Konnektoren trennen → Editor zeigt eine klare Fehlermeldung,
   im Backend erscheint die Admin-Notice.

## Bewusst NICHT im Umfang (YAGNI)

- Eigene Modell-/Provider-Auswahl im Plugin (der AI-Client wählt automatisch).
- Migration bzw. Löschen der alten Option `wpcontentai_settings` (eine
  verwaiste Option stört nicht).
- Nutzung der Abilities-API oder weiterer AI-Client-Funktionen
  (Streaming, Tools, Embeddings).
- Rückwärtskompatibilität mit WordPress < 7.0.
