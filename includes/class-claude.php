<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Anthropic Claude API.
 */
class WPContentAI_Claude {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Erzeugt neuen Text zu einem Thema.
	 *
	 * @param string $prompt Themenbeschreibung des Autors.
	 * @return string|WP_Error
	 */
	public function generate( $prompt ) {
		return $this->request( 'generate', $prompt );
	}

	/**
	 * Optimiert vorhandenen Inhalt.
	 *
	 * @param string $content Bestehender Inhalt.
	 * @return string|WP_Error
	 */
	public function optimize( $content ) {
		return $this->request( 'optimize', $content );
	}

	/**
	 * Ruft die Claude API auf.
	 *
	 * @param string $mode  'generate' oder 'optimize'.
	 * @param string $input Eingabetext.
	 * @return string|WP_Error
	 */
	private function request( $mode, $input ) {
		$settings = WPContentAI_Settings::get();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error(
				'wpcontentai_no_key',
				'Kein Claude API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.',
				array( 'status' => 400 )
			);
		}

		$system = ( 'optimize' === $mode )
			? 'Du bist ein erfahrener Lektor. Verbessere den vom Nutzer gelieferten Text auf Deutsch hinsichtlich Lesbarkeit, Stil und Klarheit. Gib nur den überarbeiteten Text zurück.'
			: 'Du bist ein erfahrener Redakteur. Schreibe einen gut lesbaren, strukturierten Blogbeitrag auf Deutsch zum vom Nutzer genannten Thema. Gib nur den Beitragstext zurück, keine Vorbemerkungen.';

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
						'max_tokens' => 2048,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $input,
							),
						),
					)
				),
				'timeout' => 30,
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
}
