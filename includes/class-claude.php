<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Anthropic Claude API.
 */
class WPContentAI_Claude {

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

		$text = $this->call_api( $system, $user, true );
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
				array(
					'status'       => 502,
					'raw_response' => $text,
				)
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
			. 'Gestalte den Beitrag optisch ansprechend und abwechslungsreich. Verwende neben normalen Textabsätzen auch Listen, Zitate, Buttons/CTAs und Bildergalerien, wo es sinnvoll und passend ist. '
			. 'Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne weiteren Text, in genau diesem Format: '
			. '{"title": "Titel", "blocks": ['
			. '{"type": "heading", "text": "Abschnittsüberschrift"}, '
			. '{"type": "paragraph", "text": "Textabsatz"}, '
			. '{"type": "image", "prompt": "Bildbeschreibung auf Deutsch"}, '
			. '{"type": "list", "items": ["Punkt 1", "Punkt 2"]}, '
			. '{"type": "quote", "text": "Zitat-Inhalt", "citation": "Autor/Urheber (optional)"}, '
			. '{"type": "cta", "text": "Button-Beschriftung", "url": "Ziel-URL (optional)"}, '
			. '{"type": "gallery", "prompts": ["Bild 1 Beschreibung", "Bild 2 Beschreibung"]}'
			. ']}. '
			. 'Erlaubte Block-Typen: "heading", "paragraph", "image", "list", "quote", "cta", "gallery". '
			. sprintf( 'Füge genau %d Block(e) vom Typ "image" passend im Beitrag verteilt ein.', (int) $image_count );

		$user = sprintf(
			'Thema: %s. Länge: %s. Tonfall: %s. Halte dich an diese bestätigte Gliederung: %s',
			$topic,
			$length,
			$tone,
			$outline_json
		);

		$text = $this->call_api( $system, $user, true );
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
				array(
					'status'       => 502,
					'raw_response' => $text,
				)
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
			$text = $this->call_api( $system, $user, true );
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
					array(
						'status'       => 502,
						'raw_response' => $text,
					)
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
	 * Ruft den WordPress 7.0 AI-Client auf.
	 *
	 * @param string $system System-Prompt.
	 * @param string $user   Nutzer-Eingabe.
	 * @param bool   $json   JSON-Flag.
	 * @return string|WP_Error Antworttext oder Fehler.
	 */
	private function call_api( $system, $user, $json = false ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'wpcontentai_no_ai_client',
				'WPContentAI benötigt WordPress 7.0 mit aktiviertem KI-Client und ein KI-Provider-Plugin.',
				array( 'status' => 501 )
			);
		}

		if ( $json ) {
			// Zuerst mit as_json_response() versuchen
			$builder = wp_ai_client_prompt( $user )
				->using_system_instruction( $system )
				->using_max_tokens( self::MAX_TOKENS )
				->using_request_options(
					\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
						array( \WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => 120.0 )
					)
				)
				->as_json_response();

			$text = $builder->generate_text();

			// Fallback-Check: Falls ein Fehler auftritt, der Text leer ist oder kein valides JSON geliefert wurde
			$is_invalid_json = false;
			if ( ! is_wp_error( $text ) ) {
				$trimmed = trim( $text );
				if ( 0 === strpos( $trimmed, "\xEF\xBB\xBF" ) ) {
					$trimmed = substr( $trimmed, 3 );
				}
				$start_pos = strpos( $trimmed, '{' );
				$end_pos   = strrpos( $trimmed, '}' );
				if ( false !== $start_pos && false !== $end_pos && $end_pos > $start_pos ) {
					$trimmed = substr( $trimmed, $start_pos, $end_pos - $start_pos + 1 );
				}
				$trimmed = preg_replace( '/\/\*.*?\*\//s', '', $trimmed );
				$trimmed = preg_replace( '/(?<!:)\/\/.*$/m', '', $trimmed );
				$trimmed = preg_replace( '/,\s*([\]}])/m', '$1', $trimmed );
				$decoded = json_decode( $trimmed, true );
				if ( ! is_array( $decoded ) && json_last_error() === JSON_ERROR_CTRL_CHAR ) {
					$escaped = preg_replace_callback(
						'/"([^"\\\\]|\\\\.)*"/s',
						function( $matches ) {
							return str_replace( array( "\r\n", "\r", "\n" ), array( '\\n', '\\n', '\\n' ), $matches[0] );
						},
						$trimmed
					);
					$decoded = json_decode( $escaped, true );
				}
				if ( ! is_array( $decoded ) ) {
					$is_invalid_json = true;
				}
			}

			if ( is_wp_error( $text ) || empty( trim( $text ) ) || $is_invalid_json ) {
				// Fallback ohne as_json_response()
				$builder = wp_ai_client_prompt( $user )
					->using_system_instruction( $system )
					->using_max_tokens( self::MAX_TOKENS )
					->using_request_options(
						\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
							array( \WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => 120.0 )
						)
					);
				$text = $builder->generate_text();
			}

			return $text;
		}

		$builder = wp_ai_client_prompt( $user )
			->using_system_instruction( $system )
			->using_max_tokens( self::MAX_TOKENS )
			->using_request_options(
				\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
					array( \WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => 120.0 )
				)
			);

		return $builder->generate_text();
	}

	/**
	 * Dekodiert einen JSON-String von Claude und bereinigt diesen vorab.
	 *
	 * @param string $text Rohtext der Antwort.
	 * @return array|WP_Error
	 */
	private function decode_json( $text ) {
		// UTF-8 BOM entfernen, falls vorhanden
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}

		$trimmed = trim( $text );

		// Extrahiere das JSON-Objekt, falls zusätzlicher Text vorhanden ist
		$start_pos = strpos( $trimmed, '{' );
		$end_pos   = strrpos( $trimmed, '}' );

		if ( false !== $start_pos && false !== $end_pos && $end_pos > $start_pos ) {
			$trimmed = substr( $trimmed, $start_pos, $end_pos - $start_pos + 1 );
		}

		// 1. Kommentare entfernen
		$trimmed = preg_replace( '/\/\*.*?\*\//s', '', $trimmed );
		$trimmed = preg_replace( '/(?<!:)\/\/.*$/m', '', $trimmed );

		// 2. Trailing Commas entfernen (Kommas vor schließenden Klammern/Geschweiften Klammern)
		$trimmed = preg_replace( '/,\s*([\]}])/m', '$1', $trimmed );

		// 3. Dekodierungsversuch
		$data = json_decode( $trimmed, true );

		// 4. Fallback bei ungültigen Kontrollzeichen (z. B. unescapeten Zeilenumbrüchen in JSON-Strings)
		if ( ! is_array( $data ) && json_last_error() === JSON_ERROR_CTRL_CHAR ) {
			$escaped = preg_replace_callback(
				'/"([^"\\\\]|\\\\.)*"/s',
				function( $matches ) {
					return str_replace( array( "\r\n", "\r", "\n" ), array( '\\n', '\\n', '\\n' ), $matches[0] );
				},
				$trimmed
			);
			$data = json_decode( $escaped, true );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wpcontentai_parse',
				'Die KI-Antwort konnte nicht verarbeitet werden.',
				array(
					'status'       => 502,
					'raw_response' => $text,
					'json_error'   => json_last_error_msg(),
				)
			);
		}

		return $data;
	}
}
