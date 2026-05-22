# WPContentAI – Teilprojekt C: KI-Blöcke

Datum: 2026-05-22
Status: Freigegeben

## Einordnung

Drittes und letztes Teilprojekt der WPContentAI-Erweiterung:

- **A – Echte API-Anbindung:** abgeschlossen.
- **B – Beitrags-Wizard:** abgeschlossen.
- **C – KI-Blöcke (dieses Dokument):** vier eigene Gutenberg-Blöcke.

C baut auf A auf (echte Claude- und Gemini-Anbindung, `/image`-Endpoint) und
ist unabhängig von B.

## Zweck

Vier eigene Gutenberg-Blöcke, die der Autor punktuell in einen Beitrag einfügt,
um einzelne Stellen per KI zu erzeugen — ergänzend zum kompletten Wizard aus
Teilprojekt B.

## Die vier Blöcke

Jeder Block ist ein **Editor-Werkzeug**: Im Editor zeigt er ein Eingabefeld
(außer KI-Zusammenfassung) und einen Button. Nach erfolgreichem Generieren
**ersetzt sich der Block selbst** durch normale Gutenberg-Standard-Blöcke und
verschwindet. Der gespeicherte Beitrag enthält damit ausschließlich
Standard-Blöcke und hängt zur Anzeige nicht vom Plugin ab.

| Block | Slug | Eingabe | Ergebnis |
|-------|------|---------|----------|
| KI-Absatz | `wpcontentai/ki-absatz` | Prompt | ein `core/paragraph` |
| KI-Bild | `wpcontentai/ki-bild` | Prompt | ein `core/image` |
| KI-Überschrift + Abschnitt | `wpcontentai/ki-ueberschrift` | Prompt | `core/heading` (H2) + `core/paragraph` |
| KI-Zusammenfassung | `wpcontentai/ki-zusammenfassung` | — | ein `core/paragraph` |

- KI-Absatz und KI-Überschrift+Abschnitt erhalten zusätzlich zum Prompt den
  bestehenden Beitragstext als Kontext.
- KI-Zusammenfassung hat kein Prompt-Feld; sie liest den gesamten bestehenden
  Beitragstext und erzeugt daraus eine Zusammenfassung.
- KI-Bild arbeitet nur mit dem Prompt (kein Kontext).

## Ausgangslage

Nach Teilprojekt A und B:
- `WPContentAI_Claude` – `generate()`, `optimize()`, `outline()`, `compose()`;
  private `call_api()` und `decode_json()`.
- `WPContentAI_Gemini` – `generate( $prompt )` → Bild in der Mediathek.
- `WPContentAI_REST` – Endpoints `/generate`, `/optimize`, `/image`,
  `/outline`, `/compose` unter `wpcontentai/v1`.
- `src/index.js` – registriert das Editor-Plugin (Seitenleiste).
- `src/sidebar.js`, `src/wizard.js`, `src/tools.js`.

## Änderungen im Detail

### 1. `includes/class-claude.php` – Methode `block()`

Neue öffentliche Methode:

```
block( string $kind, string $prompt, string $context ) : array{heading:string,text:string}|WP_Error
```

- `$kind` ist `absatz`, `ueberschrift` oder `zusammenfassung`.
- Modus-abhängiger System-Prompt:
  - `absatz`: „Schreibe einen einzelnen, gut lesbaren Absatz auf Deutsch zum
    vom Nutzer gewünschten Inhalt. Berücksichtige den bestehenden Beitragstext
    als Kontext. Gib nur den Absatztext zurück."
  - `ueberschrift`: „Erzeuge eine Zwischenüberschrift und einen dazu passenden
    Absatz auf Deutsch. Berücksichtige den bestehenden Beitragstext als
    Kontext. Antworte AUSSCHLIESSLICH mit einem JSON-Objekt:
    {\"heading\": \"...\", \"text\": \"...\"}."
  - `zusammenfassung`: „Fasse den vom Nutzer gelieferten Beitragstext in einem
    kurzen Absatz auf Deutsch zusammen. Gib nur den Zusammenfassungstext
    zurück."
- Nutzer-Nachricht:
  - `absatz`/`ueberschrift`: „Gewünschter Inhalt: {prompt}. Bestehender
    Beitragstext als Kontext: {context}"
  - `zusammenfassung`: „Beitragstext: {context}"
- Rückgabe:
  - `absatz` / `zusammenfassung`: Klartext-Antwort →
    `array( 'heading' => '', 'text' => <antwort> )`.
  - `ueberschrift`: Antwort über `decode_json()` parsen; fehlen `heading` oder
    `text`, → `WP_Error` `wpcontentai_parse` (`status` 502). Sonst
    `array( 'heading' => <heading>, 'text' => <text> )`.
- Bei unbekanntem `$kind` → `WP_Error` `wpcontentai_bad_kind` (`status` 400).
- Fehler von `call_api()` werden durchgereicht.

`call_api()` und `decode_json()` werden unverändert wiederverwendet.

### 2. `includes/class-rest.php` – Endpoint `/block`

Neuer REST-Endpoint `POST wpcontentai/v1/block`:

- `permission_callback`: `current_user_can( 'edit_posts' )`.
- Parameter:
  - `kind` – string, required, `enum` = `['absatz','ueberschrift','zusammenfassung']`.
  - `prompt` – string, optional (Default `''`), `sanitize_textarea_field`.
  - `context` – string, optional (Default `''`), `sanitize_textarea_field`.
- Callback `handle_block()`: ruft `WPContentAI_Claude::block()`; bei Erfolg
  `WP_REST_Response` mit `array( 'heading' => ..., 'text' => ... )`, sonst
  `WP_Error`.
- Die bestehenden Endpoints bleiben unverändert.

### 3. `src/blocks/shared.js` – gemeinsame Editor-Komponente

Exportiert eine Komponente `AiBlockEdit`, die das Editor-UI aller vier Blöcke
kapselt. Props:

- `clientId` – Client-ID des Blocks (von Gutenberg an `edit` übergeben).
- `hasPrompt` – ob ein Prompt-Eingabefeld gezeigt wird.
- `label` – Beschriftung über dem Block.
- `buttonLabel` – Beschriftung des Generieren-Buttons.
- `generate` – `async ( prompt, context ) => Block[]` — liefert die zu
  erzeugenden Gutenberg-Blöcke (über `createBlock`).

Verhalten:
- Rendert über `useBlockProps()` einen Platzhalter mit `label`, optionalem
  `TextControl` (Prompt), Button, Fehler-`Notice` und `Spinner`.
- Liest den bestehenden Beitragstext über
  `useSelect( editorStore ).getEditedPostContent()` und reicht ihn als
  `context` an `generate`.
- Beim Klick: `generate()` aufrufen; bei Erfolg den eigenen Block per
  `replaceBlocks( clientId, <ergebnis> )` (aus `blockEditorStore`) ersetzen.
- Fehler → `Notice` im Block, Block bleibt bestehen und erneut bedienbar.

### 4. Die vier Block-Dateien

Je eine Datei in `src/blocks/`, die `registerBlockType` aufruft. Gemeinsame
Eigenschaften: `apiVersion: 3`, `save: () => null`, Editor-`edit` rendert
`<AiBlockEdit … />`.

- `ki-absatz.js` – `wpcontentai/ki-absatz`, Kategorie `text`.
  `generate`: `POST /block` mit `kind: 'absatz'`, Prompt, Kontext →
  `[ createBlock( 'core/paragraph', { content: text } ) ]`.
- `ki-bild.js` – `wpcontentai/ki-bild`, Kategorie `media`.
  `generate`: `POST /image` mit Prompt →
  `[ createBlock( 'core/image', { id, url } ) ]`.
- `ki-ueberschrift.js` – `wpcontentai/ki-ueberschrift`, Kategorie `text`.
  `generate`: `POST /block` mit `kind: 'ueberschrift'`, Prompt, Kontext →
  `[ createBlock( 'core/heading', { level: 2, content: heading } ),
     createBlock( 'core/paragraph', { content: text } ) ]`.
- `ki-zusammenfassung.js` – `wpcontentai/ki-zusammenfassung`, Kategorie `text`,
  `hasPrompt: false`. `generate`: `POST /block` mit `kind: 'zusammenfassung'`,
  leerem Prompt, Kontext → `[ createBlock( 'core/paragraph', { content: text } ) ]`.

### 5. `src/blocks/index.js` und `src/index.js`

- `src/blocks/index.js` – importiert die vier Block-Dateien, damit ihre
  `registerBlockType`-Aufrufe ausgeführt werden.
- `src/index.js` – bekommt zusätzlich `import './blocks';`, sonst unverändert.

## Datenfluss

1. Autor fügt einen KI-Block über den Block-Inserter ein.
2. Block zeigt Prompt-Feld (falls vorhanden) + Button.
3. Klick → `AiBlockEdit` ruft `generate()`:
   - Text-Blöcke → `POST /block` → `WPContentAI_Claude::block()` → Claude.
   - KI-Bild → `POST /image` → `WPContentAI_Gemini` → Gemini → Mediathek.
4. Ergebnis → `createBlock(...)` → `replaceBlocks( clientId, … )`: Der KI-Block
   verschwindet, an seiner Stelle stehen Standard-Blöcke.

## Sicherheit & Fehlerbehandlung

- `/block` mit `edit_posts`-Recht; `kind` über `enum` validiert.
- API-Keys serverseitig.
- Generierungsfehler → `Notice` im Block; der Block bleibt erhalten und kann
  erneut ausgelöst werden.
- Ungültiges JSON bei `ueberschrift` → `WP_Error` (über `decode_json()`).
- Ein nicht generierter KI-Block speichert nichts (`save: () => null`) — er
  verschwindet beim Speichern rückstandslos.

## Tests (manuell)

1. KI-Absatz einfügen, Prompt eingeben, generieren → Block wird durch einen
   Absatz ersetzt, der zum übrigen Beitrag passt.
2. KI-Bild einfügen, Prompt eingeben, generieren → Block wird durch ein Bild
   ersetzt; das Bild liegt in der Mediathek.
3. KI-Überschrift+Abschnitt einfügen, generieren → Block wird durch Überschrift
   + Absatz ersetzt.
4. KI-Zusammenfassung in einen Beitrag mit Inhalt einfügen, generieren → Block
   wird durch einen Zusammenfassungs-Absatz ersetzt.
5. Fehlerfall (kein API-Key) → Hinweis im Block, Block bleibt bestehen.
6. Nicht generierten KI-Block speichern → Beitrag enthält danach keinen
   KI-Block-Rest.

## Bewusst NICHT im Umfang (YAGNI)

- Erneutes Generieren nach der Umwandlung (der Block wandelt sich endgültig um).
- `block.json`-basierte Registrierung und serverseitiges Rendering — die Blöcke
  sind reine Editor-Werkzeuge ohne gespeicherten Inhalt.
- Eigene Block-Kategorie (es werden die Standard-Kategorien genutzt).
- Weitere Block-Typen über die vier vereinbarten hinaus.
