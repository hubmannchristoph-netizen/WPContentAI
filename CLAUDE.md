# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Überblick

WPContentAI ist ein WordPress-Plugin, das Inhalte per nativem WordPress-7.0-AI-Client
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

- `includes/class-claude.php` – Kapselt den Zugriff auf den WordPress-7.0-AI-Client für Textgenerierung (Claude).
- `includes/class-image.php` – Verwaltet die Bildgenerierung über den WordPress-7.0-AI-Client und speichert Bilder in der Mediathek.
- `includes/class-rest.php` – REST-Endpoints unter `wpcontentai/v1`
  (`POST /generate`, `POST /optimize`, `POST /image` etc.). Prüft Rechte.

Datenfluss: Sidebar (`src/sidebar.js`) → `apiFetch` → REST-Endpoint →
`WPContentAI_Claude` / `WPContentAI_Image` → WordPress Core-AI-Client (Konnektoren) → Antwort zurück in den Editor.

## Konventionen

- Jede PHP-Datei beginnt mit `defined( 'ABSPATH' ) || exit;`.
- Keine Einstellungsseite; Schlüssel und Modelle werden zentral in WordPress unter „Einstellungen → Konnektoren“ verwaltet.
