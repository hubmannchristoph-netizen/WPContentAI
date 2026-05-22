<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registriert die REST-Endpoints von WPContentAI.
 */
class WPContentAI_REST {

	const NAMESPACE = 'wpcontentai/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$text_args = array(
			'methods'             => 'POST',
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'input' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate',
			array_merge( $text_args, array( 'callback' => array( $this, 'handle_generate' ) ) )
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimize',
			array_merge( $text_args, array( 'callback' => array( $this, 'handle_optimize' ) ) )
		);

		register_rest_route(
			self::NAMESPACE,
			'/image',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'callback'            => array( $this, 'handle_image' ),
				'args'                => array(
					'prompt' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/outline',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'callback'            => array( $this, 'handle_outline' ),
				'args'                => $this->wizard_args( false ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/compose',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'callback'            => array( $this, 'handle_compose' ),
				'args'                => $this->wizard_args( true ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/block',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'callback'            => array( $this, 'handle_block' ),
				'args'                => array(
					'kind'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'absatz', 'ueberschrift', 'zusammenfassung' ),
					),
					'prompt'  => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Gemeinsame Argument-Definition für /outline und /compose.
	 *
	 * @param bool $is_compose true für /compose (mit outline-Feld statt headings).
	 * @return array
	 */
	private function wizard_args( $is_compose ) {
		$args = array(
			'topic'       => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'length'      => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tone'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'image_count' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		if ( $is_compose ) {
			$args['outline'] = array(
				'type'     => 'object',
				'required' => true,
			);
		} else {
			$args['headings'] = array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			);
		}

		return $args;
	}

	/**
	 * Rechteprüfung für die Text-Endpoints.
	 *
	 * @return bool
	 */
	public function check_edit_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Rechteprüfung für den Bild-Endpoint (lädt in die Mediathek).
	 *
	 * @return bool
	 */
	public function check_upload_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->generate( $request->get_param( 'input' ) );
		return $this->respond_text( $result );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_optimize( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->optimize( $request->get_param( 'input' ) );
		return $this->respond_text( $result );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_image( $request ) {
		$gemini = new WPContentAI_Gemini();
		$result = $gemini->generate( $request->get_param( 'prompt' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'id'  => $result['id'],
				'url' => $result['url'],
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_outline( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->outline(
			$request->get_param( 'topic' ),
			$request->get_param( 'length' ),
			$request->get_param( 'tone' ),
			$request->get_param( 'headings' ),
			(int) $request->get_param( 'image_count' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_compose( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->compose(
			$request->get_param( 'topic' ),
			$request->get_param( 'length' ),
			$request->get_param( 'tone' ),
			(int) $request->get_param( 'image_count' ),
			$request->get_param( 'outline' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_block( $request ) {
		$claude = new WPContentAI_Claude();
		$result = $claude->block(
			$request->get_param( 'kind' ),
			$request->get_param( 'prompt' ),
			$request->get_param( 'context' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'heading' => $result['heading'],
				'text'    => $result['text'],
			),
			200
		);
	}

	/**
	 * Wandelt ein Text-Ergebnis in eine REST-Antwort.
	 *
	 * @param string|WP_Error $result Ergebnis des Claude-Clients.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond_text( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'text' => $result ), 200 );
	}
}
