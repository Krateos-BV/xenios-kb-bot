<?php
/**
 * REST API endpoints for the chat widget.
 *
 * Routes (namespace xenios-kb-bot/v1):
 *   POST   /chat     → one chat turn, returns { response, session_id }
 *   DELETE /session  → clears a session's transient state, returns { cleared }
 *   GET    /nonce    → a fresh REST nonce for the two routes above, returns { nonce }
 *
 * All routes are public (permission __return_true). /chat and /session require a
 * valid WordPress REST nonce in the X-WP-Nonce header, which the widget gets from
 * /nonce rather than from the page, so cached pages keep working.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Xenios_KB_Bot_REST {

	// Not `NAMESPACE`: using that reserved word as a constant name is
	// deprecated as of PHP 8.6.
	const REST_NAMESPACE = 'xenios-kb-bot/v1';

	public function __construct() {
		// Stub.
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_chat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_nonce' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
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

		$client_ip = self::client_ip();

		// Per-IP rate limit BEFORE the site-wide session budget. The other way
		// round, one client could spend the whole budget alone (every request
		// with no or a fresh ID is a new session, and a throttled session never
		// stores state, so resending it spends budget again) and lock every
		// other visitor out of the chat.
		$throttled = Xenios_KB_Bot_Agent::throttle_reply( $message, $session_id, $client_ip );
		if ( null !== $throttled ) {
			return new WP_REST_Response(
				array(
					'response'   => $throttled,
					'session_id' => $session_id,
				),
				200
			);
		}

		// Refuse to mint session state once the site-wide new-session budget for
		// this window is spent, so forged IDs cannot grow the options table
		// without bound.
		if ( ! Xenios_KB_Bot_Agent::reserve_session( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => 'session_capacity_reached' ), 429 );
		}

		$agent = new Xenios_KB_Bot_Agent();
		$reply = $agent->chat( $message, $session_id );

		return new WP_REST_Response(
			array(
				'response'   => $reply,
				'session_id' => $session_id,
			),
			200
		);
	}

	/**
	 * GET /nonce — a fresh REST nonce for the widget.
	 *
	 * A nonce printed into the page expires after a day, but full-page caches
	 * (WP Rocket, Cloudflare, host caches) keep serving that page far longer,
	 * and every chat request from it then failed with invalid_nonce. The widget
	 * asks here instead, so its nonce is always current however old the page.
	 *
	 * The widget calls this and the chat routes without cookies, so the nonce
	 * is always the logged-out one and verifies the same way for everybody.
	 * That nonce was already readable by anyone who loads a page; this route
	 * gives nothing new away. Abuse is bounded by the rate limits, not by it.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_nonce() {
		$response = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
		// A cached copy of this response would recreate the bug it exists to fix.
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
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
		 * With a chain of proxies (e.g. Cloudflare in front of Traefik), list
		 * every hop: the first untrusted address from the right of
		 * X-Forwarded-For is taken as the client.
		 *
		 * @param string[] $proxies Trusted proxy IP addresses.
		 */
		$trusted = array_map( 'strval', (array) apply_filters( 'xenios_kb_bot_trusted_proxies', array() ) );

		if ( ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Each proxy APPENDS the address it received the request from, so
			// only the right-hand end of the list is proxy-written; everything
			// to the left is whatever the client sent. Walk from the right past
			// our own trusted proxies and take the first address they did not
			// add — never the leftmost entry, which the client picks freely and
			// could rotate to get a fresh rate-limit bucket on every request.
			$hops = explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			foreach ( array_reverse( $hops ) as $hop ) {
				$hop = trim( $hop );
				if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
					// Anything past a malformed entry is unverifiable.
					break;
				}
				if ( ! in_array( $hop, $trusted, true ) ) {
					return $hop;
				}
			}
			return $remote;
		}

		// X-Real-IP is a single value a proxy sets (overwriting any client
		// copy), so it is only a fallback when no X-Forwarded-For was passed.
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_REAL_IP'] ) ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return $remote;
	}
}
