# WPContentAI – Design (Lauffähiges Grundgerüst)

Datum: 2026-05-21
Status: Freigegeben

## Zweck

WordPress-Plugin, das per Anthropic Claude API Inhalte **generiert** und
bestehende Inhalte **optimiert** (SEO, Lesbarkeit, Meta-Beschreibungen).
Bedienung über eine Gutenberg-Editor-Sidebar.

Dieses Dokument beschreibt das erste **lauffähige Grundgerüst**: Plugin
aktivierbar, Einstellungsseite, Gutenberg-Sidebar mit Buttons, REST-Endpoints.
Der Claude-Aufruf ist zunächst ein klar markierter Platzhalter (Stub).

## Architektur

```
WPContentAI/
├── wpcontentai.php          Plugin-Header, Konstanten, Bootstrap, Aktivierungshook
├── includes/
│   ├── class-settings.php   Admin-Menü, Einstellungsseite, API-Key + Modell-Option
│   ├── class-rest.php       REST-Endpoints /generate und /optimize
│   └── class-claude.php     Claude-API-Client (Grundgerüst: Stub-Antwort)
├── src/
│   ├── index.js             Registriert das Editor-Plugin
│   └── sidebar.js           Sidebar-Panel mit Buttons + API-Aufruf
├── build/                   npm-Build-Ausgabe (wird von WordPress geladen)
├── package.json             @wordpress/scripts (build / start)
├── .gitignore               node_modules, build optional
└── CLAUDE.md                Anleitung für Claude Code
```

### Komponenten

- **wpcontentai.php** – Plugin-Header, definiert Konstanten (`WPCONTENTAI_VERSION`,
  `WPCONTENTAI_PATH`, `WPCONTENTAI_URL`), lädt die `includes`-Klassen, registriert
  Hooks. Aktivierungshook setzt Default-Optionen.
- **class-settings.php** – Fügt Menüpunkt „WPContentAI" hinzu. Einstellungsseite
  mit zwei Feldern: Claude-API-Key (Passwortfeld) und Modell-Dropdown
  (Opus / Sonnet / Haiku). Speicherung über die Settings API in `wp_options`.
- **class-rest.php** – Registriert zwei REST-Routen unter Namespace
  `wpcontentai/v1`: `POST /generate` und `POST /optimize`. Prüft
  `current_user_can('edit_posts')` und Nonce. Gibt Ergebnis als JSON zurück.
- **class-claude.php** – Kapselt den API-Zugriff. Im Grundgerüst liefert
  `generate()` / `optimize()` eine Platzhalter-Antwort zurück. Die Stelle für
  den echten `wp_remote_post`-Aufruf an die Claude API ist mit `TODO` markiert.
- **src/** – Gutenberg-Editor-Plugin: registriert ein
  `PluginSidebar`-Panel mit den Buttons „Text generieren" und
  „Inhalt optimieren". Aufruf der REST-Endpoints über `apiFetch` (Nonce
  automatisch). Ergebnis wird in den Editor eingefügt.

## Datenfluss

1. Autor öffnet einen Beitrag → Gutenberg lädt das Editor-Plugin aus `build/`.
2. Sidebar-Panel zeigt Buttons „Text generieren" / „Inhalt optimieren".
3. Klick → `apiFetch` ruft `POST wpcontentai/v1/generate` bzw. `/optimize` auf.
4. `class-rest.php` prüft Rechte + Nonce, ruft `class-claude.php` auf.
5. `class-claude.php` gibt im Grundgerüst Platzhalter-Text zurück.
6. Antwort geht an die Sidebar → Text wird in den Editor eingefügt.

## Sicherheit & Fehlerbehandlung

- API-Key liegt ausschließlich serverseitig in `wp_options`, gelangt nie ins
  Frontend.
- Jeder REST-Call: `permission_callback` mit `current_user_can('edit_posts')`;
  Nonce über `apiFetch` / `wp_rest`.
- Direktaufruf der PHP-Dateien wird per `defined('ABSPATH')`-Guard verhindert.
- Fehlerfälle (kein API-Key gesetzt, API-Fehler) → `WP_Error` / JSON mit
  Meldung; die Sidebar zeigt den Hinweis sichtbar an.

## Einstellungsseite

- Menüpunkt „WPContentAI" auf oberster Ebene im Admin-Menü.
- Feld 1: Claude-API-Key (Typ Passwort).
- Feld 2: Modell-Dropdown – Optionen: `claude-opus-4-7`, `claude-sonnet-4-6`,
  `claude-haiku-4-5-20251001`.

## Tests

- Manuelle Abnahme: Plugin aktivieren ohne PHP-Fehler; Einstellungsseite
  speichert Key + Modell; Sidebar erscheint im Editor; Buttons rufen die
  REST-Endpoints auf und fügen Platzhalter-Text ein.
- REST-Berechtigung: Aufruf ohne Anmeldung / ohne Rechte wird abgewiesen.

## Bewusst NICHT im Umfang (YAGNI)

- Echte Claude-API-Anbindung (folgt nach dem Grundgerüst, Stelle ist markiert).
- Verschlüsselung des API-Keys über Standard-`wp_options` hinaus.
- Mehrere KI-Anbieter (nur Anthropic Claude).
- Frontend-/Nicht-Editor-Oberflächen.

## Build

- `npm install` – Abhängigkeiten (`@wordpress/scripts`).
- `npm run build` – erzeugt `build/` für den Produktivbetrieb.
- `npm run start` – Watch-Modus für die Entwicklung.
