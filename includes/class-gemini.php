<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Google Gemini Bild-API.
 *
 * Erzeugt Bilder und legt sie als Anhang in der WordPress-Mediathek ab.
 */
class WPContentAI_Gemini {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent';

	/**
	 * Erzeugt ein Bild zu einem Prompt und speichert es in der Mediathek.
	 *
	 * @param string $prompt Bildbeschreibung.
	 * @return array{id:int,url:string}|WP_Error
	 */
	public function generate( $prompt ) {
		$settings = WPContentAI_Settings::get();

		if ( empty( $settings['gemini_key'] ) ) {
			return new WP_Error(
				'wpcontentai_no_gemini_key',
				'Kein Gemini API-Key hinterlegt. Bitte unter „WPContentAI" eintragen.',
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'x-goog-api-key' => $settings['gemini_key'],
					'content-type'   => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'contents' => array(
							array(
								'parts' => array(
									array( 'text' => $prompt ),
								),
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
				'Verbindung zur Gemini API fehlgeschlagen: ' . $response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unbekannter API-Fehler.';
			return new WP_Error(
				'wpcontentai_api',
				'Gemini API-Fehler: ' . $message,
				array( 'status' => 502 )
			);
		}

		$image = $this->extract_image( $body );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		return $this->save_to_media( $image['data'], $image['mime'], $prompt );
	}

	/**
	 * Liest die base64-Bilddaten aus der Gemini-Antwort.
	 *
	 * @param array $body Dekodierte API-Antwort.
	 * @return array{data:string,mime:string}|WP_Error
	 */
	private function extract_image( $body ) {
		$parts = isset( $body['candidates'][0]['content']['parts'] )
			? $body['candidates'][0]['content']['parts']
			: array();

		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData']['data'] ) ) {
				return array(
					'data' => $part['inlineData']['data'],
					'mime' => isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png',
				);
			}
		}

		return new WP_Error(
			'wpcontentai_no_image',
			'Die Gemini-Antwort enthielt kein Bild.',
			array( 'status' => 502 )
		);
	}

	/**
	 * Speichert die Bilddaten als Anhang in der Mediathek.
	 *
	 * @param string $base64 base64-kodierte Bilddaten.
	 * @param string $mime   MIME-Typ des Bildes.
	 * @param string $prompt Ursprünglicher Prompt (für den Titel).
	 * @return array{id:int,url:string}|WP_Error
	 */
	private function save_to_media( $base64, $mime, $prompt ) {
		$binary = base64_decode( $base64, true );
		if ( false === $binary ) {
			return new WP_Error(
				'wpcontentai_decode',
				'Bilddaten konnten nicht dekodiert werden.',
				array( 'status' => 502 )
			);
		}

		$ext = ( 'image/jpeg' === $mime ) ? 'jpg' : 'png';

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error(
				'wpcontentai_upload_dir',
				'Upload-Verzeichnis nicht verfügbar: ' . $uploads['error'],
				array( 'status' => 502 )
			);
		}

		$filename = wp_unique_filename( $uploads['path'], 'wpcontentai-' . time() . '.' . $ext );
		$filepath = trailingslashit( $uploads['path'] ) . $filename;

		if ( false === file_put_contents( $filepath, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error(
				'wpcontentai_write',
				'Bilddatei konnte nicht gespeichert werden.',
				array( 'status' => 502 )
			);
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => 'WPContentAI: ' . wp_trim_words( $prompt, 8, '' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $filepath );
		if ( is_wp_error( $attach_id ) || 0 === $attach_id ) {
			return new WP_Error(
				'wpcontentai_attach',
				'Anhang konnte nicht angelegt werden.',
				array( 'status' => 502 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $filepath );
		wp_update_attachment_metadata( $attach_id, $metadata );

		return array(
			'id'  => (int) $attach_id,
			'url' => wp_get_attachment_url( $attach_id ),
		);
	}
}
