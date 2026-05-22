<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client für die Bild-Generierung über den WordPress AI-Client.
 *
 * Erzeugt Bilder und legt sie als Anhang in der WordPress-Mediathek ab.
 */
class WPContentAI_Image {

	/**
	 * Erzeugt ein Bild zu einem Prompt und speichert es in der Mediathek.
	 *
	 * @param string $prompt Bildbeschreibung.
	 * @return array{id:int,url:string}|WP_Error
	 */
	public function generate( $prompt ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'wpcontentai_no_ai_client',
				'WPContentAI benötigt WordPress 7.0 mit aktiviertem KI-Client und ein KI-Provider-Plugin.',
				array( 'status' => 501 )
			);
		}

		$file = wp_ai_client_prompt( $prompt )->generate_image();

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Bilddaten ermitteln
		$binary = '';
		$base64 = $file->getBase64Data();

		if ( ! empty( $base64 ) ) {
			$binary = base64_decode( $base64, true );
			if ( false === $binary ) {
				return new WP_Error(
					'wpcontentai_decode',
					'Bilddaten konnten nicht dekodiert werden.',
					array( 'status' => 502 )
				);
			}
		} elseif ( $file->isRemote() && $file->getUrl() ) {
			$response = wp_remote_get( $file->getUrl() );
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wpcontentai_image_fetch',
					'Bild konnte nicht heruntergeladen werden: ' . $response->get_error_message(),
					array( 'status' => 502 )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== (int) $code ) {
				return new WP_Error(
					'wpcontentai_image_fetch',
					'Bild-Download fehlgeschlagen mit Status-Code ' . $code,
					array( 'status' => 502 )
				);
			}

			$binary = wp_remote_retrieve_body( $response );
		} else {
			return new WP_Error(
				'wpcontentai_no_image',
				'Das Bild konnte nicht abgerufen werden.',
				array( 'status' => 502 )
			);
		}

		$mime = $file->getMimeType();

		return $this->save_to_media( $binary, $mime, $prompt );
	}

	/**
	 * Speichert die Bilddaten als Anhang in der Mediathek.
	 *
	 * @param string $binary Binäre Bilddaten.
	 * @param string $mime   MIME-Typ des Bildes.
	 * @param string $prompt Ursprünglicher Prompt (für den Titel).
	 * @return array{id:int,url:string}|WP_Error
	 */
	private function save_to_media( $binary, $mime, $prompt ) {
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
