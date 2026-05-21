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
