<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Anthropic Claude API.
 *
 * Im Grundgerüst liefern generate() und optimize() Platzhalter-Antworten.
 * Der echte API-Aufruf ist mit TODO markiert.
 */
class WPContentAI_Claude {

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
	 * Zentraler Einstiegspunkt für API-Aufrufe.
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
				'Kein Claude API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.'
			);
		}

		// TODO: Echten Claude-API-Aufruf einbauen.
		// $response = wp_remote_post(
		//     'https://api.anthropic.com/v1/messages',
		//     array(
		//         'headers' => array(
		//             'x-api-key'         => $settings['api_key'],
		//             'anthropic-version' => '2023-06-01',
		//             'content-type'      => 'application/json',
		//         ),
		//         'body'    => wp_json_encode( array(
		//             'model'      => $settings['model'],
		//             'max_tokens' => 1024,
		//             'messages'   => array(
		//                 array( 'role' => 'user', 'content' => $input ),
		//             ),
		//         ) ),
		//         'timeout' => 30,
		//     )
		// );
		// Antwort auswerten und Text zurückgeben.

		// Platzhalter-Antwort für das Grundgerüst:
		if ( 'optimize' === $mode ) {
			return "[WPContentAI Platzhalter] Optimierte Fassung von:\n\n" . $input;
		}
		return "[WPContentAI Platzhalter] Generierter Text zu: " . $input;
	}
}
