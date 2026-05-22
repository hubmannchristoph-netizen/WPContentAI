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
