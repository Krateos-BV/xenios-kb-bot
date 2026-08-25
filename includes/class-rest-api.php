<?php
/**
 * REST API endpoints for the chat widget.
 *
 * Routes (namespace xenios-kb-bot/v1):
 *   POST   /chat     → one chat turn, returns { response, session_id }
 *   DELETE /session  → clears a session's transient state, returns { cleared }
 *
 * Both routes are public (permission __return_true) but require a valid WordPress
 * REST nonce in the X-WP-Nonce header — this is the CSRF protection that replaces
 * the old in-memory support-token system.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Xenios_KB_Bot_REST {

	const NAMESPACE = 'xenios-kb-bot/v1';

	public function __construct() {
		// Stub.
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_chat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_session_delete' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /chat — process one chat turn.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_chat( WP_REST_Request $request ) {
		if ( ! self::verify_nonce( $request ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_nonce' ), 403 );
		}

		$message = trim( (string) wp_unslash( $request->get_param( 'message' ) ) );
		if ( '' === $message ) {
			return new WP_REST_Response( array( 'error' => 'empty_message' ), 400 );
		}

		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			$session_id = wp_generate_uuid4();
		}

		$client_ip = self::client_ip();

		$agent = new Xenios_KB_Bot_Agent();
		$reply = $agent->chat( $message, $session_id, $client_ip );

		return new WP_REST_Response(
			array(
				'response'   => $reply,
				'session_id' => $session_id,
			),
			200
		);
	}

	/**
	 * DELETE /session — clear a session's stored state.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_session_delete( WP_REST_Request $request ) {
		if ( ! self::verify_nonce( $request ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_nonce' ), 403 );
		}

		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_REST_Response( array( 'error' => 'missing_session_id' ), 400 );
		}

		Xenios_KB_Bot_Agent::clear_session( $session_id );

		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	/**
	 * Validate the REST nonce from the X-WP-Nonce header.
	 */
	private static function verify_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Best-effort client IP, used only for rate limiting.
	 */
	private static function client_ip(): string {
		// Behind Traefik, REMOTE_ADDR is the container gateway, so trust the
		// forwarded headers first. Traefik sets X-Forwarded-For / X-Real-IP.
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				// X-Forwarded-For can be a comma-separated list; take the first.
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
