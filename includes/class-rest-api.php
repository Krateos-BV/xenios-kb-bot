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
			// No ID yet: the server mints it. Client-supplied IDs are only ever
			// accepted back in the exact format we issued.
			$session_id = wp_generate_uuid4();
		} elseif ( ! self::is_valid_session_id( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_session_id' ), 400 );
		}

		// Refuse to mint session state once the site-wide new-session budget for
		// this window is spent, so forged IDs cannot grow the options table
		// without bound.
		if ( ! Xenios_KB_Bot_Agent::reserve_session( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => 'session_capacity_reached' ), 429 );
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
		if ( ! self::is_valid_session_id( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_session_id' ), 400 );
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
	 * Session IDs are UUID v4 as issued by wp_generate_uuid4(). Anything else is
	 * rejected before it can be used as transient key material.
	 */
	private static function is_valid_session_id( string $session_id ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$session_id
		);
	}

	/**
	 * Client IP, used only for rate limiting.
	 *
	 * REMOTE_ADDR is the only value a client cannot forge, so it is the default.
	 * Forwarded headers are consulted only when the request actually arrived
	 * from a proxy the site owner has declared trusted.
	 */
	private static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) )
			: '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$remote = '0.0.0.0';
		}

		/**
		 * Proxy addresses whose X-Forwarded-For / X-Real-IP headers may be
		 * trusted. Empty by default: on a direct connection those headers are
		 * attacker-controlled, and honouring them lets one client forge an
		 * unlimited number of rate-limit buckets.
		 *
		 * Sites behind a reverse proxy (Traefik, nginx, Cloudflare) should add
		 * that proxy's address:
		 *
		 *   add_filter( 'xenios_kb_bot_trusted_proxies', fn() => array( '10.0.0.1' ) );
		 *
		 * @param string[] $proxies Trusted proxy IP addresses.
		 */
		$trusted = array_map( 'strval', (array) apply_filters( 'xenios_kb_bot_trusted_proxies', array() ) );

		if ( ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}

		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			// X-Forwarded-For can be a comma-separated list; take the first.
			$ip = trim( explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return $remote;
	}
}
