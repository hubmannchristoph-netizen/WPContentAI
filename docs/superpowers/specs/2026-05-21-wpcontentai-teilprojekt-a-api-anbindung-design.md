# WPContentAI – Teilprojekt A: Echte API-Anbindung

Datum: 2026-05-21
Status: Freigegeben

## Einordnung

Die Erweiterung von WPContentAI ist in drei Teilprojekte zerlegt:

- **A – Echte API-Anbindung (dieses Dokument):** Fundament. Ersetzt den
  Claude-Stub durch echte Aufrufe, ergänzt Gemini-Bildgenerierung mit
  Mediathek-Upload.
- **B – Beitrags-Wizard:** baut die Sidebar zum konfigurierbaren Generator aus.
- **C – KI-Blöcke:** vier eigene Gutenberg-Blöcke.

B und C bauen auf A auf. Dieses Dokument beschreibt ausschließlich A.

## Zweck

Aus dem Grundgerüst ein funktionierendes Plugin machen: Text wird real per
Anthropic Claude API erzeugt bzw. optimiert; Bilder werden per Google Gemini
generiert und als Anhang in die WordPress-Mediathek geladen. Nach Teilprojekt A
ist das Plugin für Endnutzer ohne Konsole testbar.

## Ausgangslage

Vorhandenes Grundgerüst:
- `wpcontentai.php` – lädt drei Klassen aus `includes/`, registriert Hooks.
- `includes/class-settings.php` – `WPContentAI_Settings`, Option
  `wpcontentai_settings` mit `api_key` (Claude) und `model`.
- `includes/class-claude.php` – `WPContentAI_Claude`, `generate()` / `optimize()`
  liefern aktuell Platzhalter-Text; echter Aufruf als `TODO`-Block.
- `includes/class-rest.php` – `WPContentAI_REST`, Endpoints
  `POST wpcontentai/v1/generate` und `/optimize`.
- `src/sidebar.js` – Sidebar mit Buttons „Text generieren" / „Inhalt optimieren".

## Änderungen im Detail

### 1. `includes/class-claude.php` – echter Claude-Aufruf

Der Platzhalter-Block in `request()` wird durch einen echten `wp_remote_post`
ersetzt:

- Endpoint: `https://api.anthropic.com/v1/messages`.
- Header: `x-api-key`, `anthropic-version: 2023-06-01`, `content-type: application/json`.
- Body: `model` aus `WPContentAI_Settings::get()['model']`, `max_tokens` = 2048,
  `system` = modusabhängiger System-Prompt, `messages` = ein User-Eintrag mit
  dem Eingabetext.
- `timeout` = 30 Sekunden.

System-Prompts:
- `generate`: „Du bist ein erfahrener Redakteur. Schreibe einen gut lesbaren,
  strukturierten Blogbeitrag auf Deutsch zum vom Nutzer genannten Thema. Gib nur
  den Beitragstext zurück, keine Vorbemerkungen."
- `optimize`: „Du bist ein erfahrener Lektor. Verbessere den vom Nutzer
  gelieferten Text auf Deutsch hinsichtlich Lesbarkeit, Stil und Klarheit. Gib
  nur den überarbeiteten Text zurück."

Antwort auswerten:
- `is_wp_error( $response )` → `WP_Error` `wpcontentai_http` durchreichen.
- HTTP-Statuscode ≠ 200 → `WP_Error` `wpcontentai_api` mit der API-Fehlermeldung,
  Fehlerdaten `array( 'status' => 502 )`.
- Erfolg: Text aus `body.content[0].text` lesen und zurückgeben. Fehlt das Feld,
  → `WP_Error` `wpcontentai_parse`, `array( 'status' => 502 )`.

Die bestehende „kein API-Key"-Prüfung bleibt, bekommt aber zusätzlich
`array( 'status' => 400 )` in den Fehlerdaten.

### 2. `includes/class-gemini.php` – neue Klasse (Bildgenerierung)

Neue Datei mit Klasse `WPContentAI_Gemini`.

Öffentliche Methode `generate( $prompt )`:
- Liest `WPContentAI_Settings::get()['gemini_key']`. Leer → `WP_Error`
  `wpcontentai_no_gemini_key`, `array( 'status' => 400 )`.
- `wp_remote_post` an
  `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent`
  mit Header `x-goog-api-key`.
- Body: `contents` mit einem `parts`-Eintrag, der den Prompt enthält.
- `timeout` = 60 Sekunden.
- Antwort: Bilddaten aus `candidates[0].content.parts[].inlineData.data`
  (base64) und `mimeType` lesen.
- Bilddaten dekodieren und über die WordPress-Mediathek-Funktionen als Anhang
  speichern: Datei ins Upload-Verzeichnis schreiben (`wp_upload_dir`,
  `wp_unique_filename`), `wp_insert_attachment`, danach
  `wp_generate_attachment_metadata` + `wp_update_attachment_metadata`
  (dafür `wp-admin/includes/image.php` einbinden).
- Rückgabe bei Erfolg: `array( 'id' => <attachment_id>, 'url' => <url> )`.
- Fehlerfälle (HTTP-Fehler, kein Bild in der Antwort, fehlgeschlagener Upload)
  → jeweils `WP_Error` mit `array( 'status' => 502 )`.

### 3. `includes/class-settings.php` – Gemini-Key

- `get()`-Defaults um `'gemini_key' => ''` erweitern.
- `sanitize()` um `gemini_key` (mit `sanitize_text_field`) erweitern.
- `render_page()` bekommt eine dritte Tabellenzeile: Passwortfeld
  „Gemini API-Key".

### 4. `includes/class-rest.php` – Endpoint `/image`

- Neue Route `POST wpcontentai/v1/image`.
- Parameter `prompt` (string, required, `sanitize_textarea_field`).
- `permission_callback`: `current_user_can( 'upload_files' )`.
- Callback `handle_image()`: ruft `WPContentAI_Gemini::generate()`; bei Erfolg
  `WP_REST_Response` mit `array( 'id' => ..., 'url' => ... )`, sonst `WP_Error`.
- Die bestehenden Endpoints `/generate` und `/optimize` bleiben unverändert.

### 5. `wpcontentai.php` – Gemini-Klasse laden

- Zusätzliche Zeile `require_once WPCONTENTAI_PATH . 'includes/class-gemini.php';`
  bei den übrigen `require_once`-Aufrufen.

### 6. `src/sidebar.js` – Testbild-Bereich

- Neuer `PanelBody`-Abschnitt „Testbild generieren": ein `TextControl` für den
  Bild-Prompt und ein Button „Bild generieren".
- Button ruft `POST /wpcontentai/v1/image` mit `{ prompt }`.
- Bei Erfolg wird über `@wordpress/blocks` (`createBlock( 'core/image', ... )`)
  ein Bild-Block mit `id` und `url` erzeugt und über den Block-Editor-Store
  (`@wordpress/block-editor`, `insertBlocks`) in den Beitrag eingefügt.
- Fehler werden im bestehenden `Notice`-Bereich angezeigt.
- Die Buttons „Text generieren" / „Inhalt optimieren" bleiben unverändert.
- Nach der Änderung `npm run build` ausführen, `build/` committen.

## Datenfluss

- **Text:** Sidebar → `POST /generate` bzw. `/optimize` → `WPContentAI_Claude`
  → Claude API → Text → Editor.
- **Bild:** Sidebar → `POST /image` → `WPContentAI_Gemini` → Gemini API →
  base64-Bilddaten → Mediathek-Upload → `{ id, url }` → `core/image`-Block im
  Editor.

## Sicherheit & Fehlerbehandlung

- Beide API-Keys liegen ausschließlich serverseitig in `wp_options`.
- `/generate` und `/optimize`: `edit_posts`. `/image`: `upload_files`.
- Alle `WP_Error` tragen einen passenden HTTP-Status (400 bei fehlendem Key,
  502 bei API-/Verarbeitungsfehlern), damit die Sidebar saubere Meldungen zeigt.
- Timeouts: Text 30 s, Bild 60 s.
- `defined( 'ABSPATH' )`-Guard auch in `class-gemini.php`.

## Tests (manuell)

1. Claude-API-Key in den Einstellungen speichern → „Text generieren" liefert
   echten, themenbezogenen Text statt Platzhalter.
2. „Inhalt optimieren" überarbeitet vorhandenen Text real.
3. Gemini-API-Key speichern → „Testbild generieren" mit einem Prompt erzeugt ein
   Bild, das in der Mediathek auftaucht und als Bild-Block im Beitrag erscheint.
4. Jeweils ohne Key → klare Fehlermeldung in der Sidebar, kein Server-Fehler.

## Bewusst NICHT im Umfang (YAGNI)

- Konfigurierbarer Wizard (Länge, Tonfall, Bildanzahl) → Teilprojekt B.
- Eigene KI-Blöcke → Teilprojekt C.
- Streaming-Antworten, Mehrfachbilder pro Aufruf, Bild-Bearbeitung.
- Auswahl des Gemini-Bildmodells (fest `gemini-2.5-flash-image`).
