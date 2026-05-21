# WPContentAI – Teilprojekt B: Beitrags-Wizard

Datum: 2026-05-21
Status: Freigegeben

## Einordnung

Zweites von drei Teilprojekten der WPContentAI-Erweiterung:

- **A – Echte API-Anbindung:** abgeschlossen.
- **B – Beitrags-Wizard (dieses Dokument):** baut die Editor-Seitenleiste zu
  einem geführten Generator aus.
- **C – KI-Blöcke:** vier eigene Gutenberg-Blöcke (später).

B baut auf A auf (echte Claude- und Gemini-Anbindung, `/image`-Endpoint).

## Zweck

Ein geführter Wizard in der Gutenberg-Seitenleiste, der aus einem Thema einen
kompletten Beitrag erzeugt: Titel, gegliederter Text und eingebettete Bilder —
als echte Gutenberg-Blöcke.

## Ausgangslage

Nach Teilprojekt A:
- `WPContentAI_Claude` – `generate()` / `optimize()`, echte Claude-Aufrufe.
- `WPContentAI_Gemini` – `generate( $prompt )` → Bild in der Mediathek.
- `WPContentAI_REST` – Endpoints `/generate`, `/optimize`, `/image` unter
  `wpcontentai/v1`.
- `src/sidebar.js` – Seitenleiste mit „Text generieren", „Inhalt optimieren"
  und „Testbild generieren".

## Wizard-Ablauf

Neues Panel „Beitrags-Wizard" mit vier Schritten:

1. **Thema** – ein Textfeld für das Thema des Beitrags.
2. **Optionen** – vier Einstellungen:
   - Länge: Kurz / Mittel / Lang
   - Tonfall: Sachlich / Locker / Werblich / Fachlich
   - Bildanzahl: 0 / 1 / 2 / 3
   - Überschriften: mit / ohne
3. **Gliederungs-Vorschau** – zeigt den von Claude vorgeschlagenen Titel und die
   Abschnittsüberschriften sowie die Anzahl geplanter Bilder. Zwei Aktionen:
   „Passt, Beitrag erzeugen" oder „Neu generieren" (ruft Phase 1 erneut auf).
   Die Gliederung ist nicht editierbar.
4. **Erzeugen** – Fortschrittsanzeige während der Erzeugung; danach ist der
   Beitrag im Editor fertig.

Schrittnavigation: „Weiter" / „Zurück". Schritt 1→2 erfordert ein nicht-leeres
Thema.

## Technischer Ablauf – zwei Phasen

### Phase 1 – Gliederung (`/outline`)

Neuer REST-Endpoint `POST wpcontentai/v1/outline`. Parameter:
`topic` (string), `length`, `tone`, `headings` (`'mit'`|`'ohne'`),
`image_count` (int 0–3).

`WPContentAI_Claude::outline()` baut daraus einen Prompt und weist Claude an,
**ausschließlich JSON** in genau diesem Format zu liefern:

```json
{
  "title": "Vorgeschlagener Titel",
  "sections": ["Überschrift 1", "Überschrift 2", "..."]
}
```

Bei `headings = 'ohne'` ist `sections` eine leere Liste. Die Antwort wird
geparst (siehe „JSON-Robustheit") und als `{ title, sections }` zurückgegeben.

### Phase 2 – Beitrag (`/compose`)

Neuer REST-Endpoint `POST wpcontentai/v1/compose`. Parameter:
`topic`, `length`, `tone`, `image_count`, plus `outline` (das bestätigte
JSON-Objekt aus Phase 1 als String oder Objekt).

`WPContentAI_Claude::compose()` weist Claude an, den vollständigen Beitrag als
**JSON-Blockliste** zu liefern:

```json
{
  "title": "Endgültiger Titel",
  "blocks": [
    { "type": "heading", "text": "..." },
    { "type": "paragraph", "text": "..." },
    { "type": "image", "prompt": "Bildbeschreibung für Gemini" }
  ]
}
```

- `heading` → Gutenberg `core/heading` (Ebene H2).
- `paragraph` → `core/paragraph`.
- `image` → die Seitenleiste löst den `prompt` über den bestehenden
  `/image`-Endpoint auf.
- Die Anzahl der `image`-Blöcke entspricht `image_count`; bei `image_count = 0`
  enthält `blocks` keine Bild-Einträge.

`/compose` ruft **nicht** selbst Gemini auf — es liefert nur die Blockliste mit
Bild-Prompts. So bleibt jeder Request kurz.

### Zusammensetzen in der Seitenleiste

Nach `/compose` geht die Seitenleiste die `blocks`-Liste durch:

1. `heading` / `paragraph` → sofort per `createBlock` erzeugen.
2. `image` → `POST /image` mit dem `prompt`; Ergebnis `{id,url}` →
   `createBlock( 'core/image', { id, url } )`. Schlägt ein Bild fehl, wird statt
   des Bildes ein `core/paragraph` mit dem Hinweis
   „[Bild konnte nicht erzeugt werden]" eingefügt und der Ablauf fortgesetzt.
3. Während des Durchlaufs Fortschrittstext anzeigen
   (z. B. „Bild 1 von 2 …").
4. Am Ende: `editPost( { title } )` und der Block-Editor-Store ersetzt den
   gesamten Inhalt (`resetBlocks` mit der neuen Blockliste).

### Sicherheitsabfrage

Bevor Schritt 4 den Inhalt ersetzt: Sind Titel **oder** Inhalt nicht leer, zeigt
die Seitenleiste eine Bestätigungsabfrage („Vorhandener Titel und Inhalt werden
ersetzt. Fortfahren?"). Erst nach Bestätigung wird ersetzt.

## JSON-Robustheit

Claude kann die JSON-Antwort in einen Markdown-Codeblock einfassen.
`WPContentAI_Claude` entfernt vor dem `json_decode` führende/abschließende
```` ``` ````-Zäune (auch ```` ```json ````). Liefert `json_decode` `null` oder
fehlen Pflichtfelder (`title`; `sections` bzw. `blocks`), → `WP_Error`
`wpcontentai_parse` mit `array( 'status' => 502 )` und der Meldung
„Die KI-Antwort konnte nicht verarbeitet werden."

## Dateistruktur

| Datei | Änderung |
|-------|----------|
| `includes/class-claude.php` | MODIFY: Methoden `outline()` und `compose()` ergänzen; gemeinsame JSON-Hilfsmethode |
| `includes/class-rest.php` | MODIFY: Endpoints `/outline` und `/compose` ergänzen |
| `src/sidebar.js` | MODIFY: wird zur Schale, rendert `<Wizard>` und `<Tools>` |
| `src/wizard.js` | CREATE: der 4-Schritt-Wizard mit Schritt-Status |
| `src/tools.js` | CREATE: „Inhalt optimieren" + „Bild einfügen", aus dem alten `sidebar.js` ausgelagert |

Der bisherige Button „Text generieren" entfällt; `optimize` und die
Bild-Funktion wandern unverändert nach `src/tools.js`.

Die Endpoints `/generate`, `/optimize`, `/image` und die Methoden
`generate()` / `optimize()` bleiben bestehen (`/generate` wird vom UI nicht mehr
genutzt, bleibt aber als API erhalten).

## Datenfluss

1. Schritt 1–2: Autor gibt Thema + Optionen ein.
2. Schritt 2→3: `POST /outline` → Claude → `{ title, sections }` → Anzeige.
3. „Neu generieren": `POST /outline` erneut.
4. „Beitrag erzeugen": ggf. Sicherheitsabfrage → `POST /compose` → Claude →
   `{ title, blocks }`.
5. Seitenleiste löst Bild-Blöcke über `POST /image` auf, baut alle Blöcke,
   ersetzt Titel und Inhalt im Editor.

## Sicherheit & Fehlerbehandlung

- API-Keys serverseitig; `/outline` und `/compose` mit `edit_posts`-Recht.
- Ungültiges JSON von Claude → `WP_Error` (siehe „JSON-Robustheit").
- Einzelne fehlgeschlagene Bilder brechen den Beitrag nicht ab.
- Fehler in Phase 1/2 → Meldung in der Seitenleiste, der Wizard bleibt im
  jeweiligen Schritt bedienbar.
- `defined( 'ABSPATH' )`-Guard in allen PHP-Dateien.

## Tests (manuell)

1. Thema + Optionen eingeben → Gliederung erscheint mit Titel und Überschriften.
2. „Neu generieren" → andere Gliederung.
3. „Beitrag erzeugen" → fertiger Beitrag mit Titel, Überschriften, Absätzen und
   der gewählten Anzahl Bilder; Bilder liegen in der Mediathek.
4. Option „Überschriften: ohne" → Beitrag ohne `heading`-Blöcke.
5. Option „Bildanzahl: 0" → Beitrag ohne Bilder.
6. Bei vorhandenem Inhalt erscheint vor dem Ersetzen die Sicherheitsabfrage.
7. „Inhalt optimieren" im Werkzeug-Panel funktioniert weiterhin.

## Bewusst NICHT im Umfang (YAGNI)

- Editierbare Gliederung in Schritt 3 (nur annehmen/neu).
- Eigene KI-Blöcke → Teilprojekt C.
- Mehrere Überschriftenebenen (H3+); es wird nur H2 erzeugt.
- Speichern/Wiederverwenden von Wizard-Voreinstellungen.
- Server-seitige Orchestrierung von Claude + Gemini in einem Request.
