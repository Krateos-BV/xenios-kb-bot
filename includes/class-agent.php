<?php
/**
 * AI agent — deterministic gate routing + LLM resolver.
 *
 * Ported from the XeniaCloud Python support agent (agent.py) and stripped of all
 * product-specific logic (Jira escalation, Redis, contact wizard, sensitive-topic
 * fast-track, dual-model switching). What remains is a generic, knowledge-base
 * driven resolver suitable for any WordPress site.
 *
 * Gate order (deterministic, evaluated top to bottom):
 *   0. Per-IP rate limit          → polite "slow down" message
 *   1. Per-session message cap     → polite "new chat" message
 *   2. Off-topic check             → closed in chat
 *   3. Abuse / probe check         → closed in chat
 *   4. Resolver                    → LLM call against the knowledge base
 *
 * State (session history, message count, pinned language, rate-limit counter) is
 * stored in WordPress transients — no external store.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Xenios_KB_Bot_Agent {

	/** Session history / count / language transient TTL: 2 hours. */
	const SESSION_TTL = 7200;

	/** Hard cap on messages handled per session. */
	const MAX_MSG_PER_SESSION = 20;

	/** Rate limit: max messages per IP per window. */
	const RATE_LIMIT_MAX = 20;

	/** Rate-limit window in seconds (30 minutes). */
	const RATE_LIMIT_WINDOW = 1800;

	/** Transient key prefix. */
	const PREFIX = 'xenios_kb_bot_';

	/** Max NEW sessions the site will mint state for per budget window. */
	const MAX_NEW_SESSIONS_PER_WINDOW = 500;

	/** New-session budget window in seconds (30 minutes). */
	const SESSION_BUDGET_WINDOW = 1800;

	/** Shared, fixed key holding the new-session budget for the window. */
	const SESSION_BUDGET_KEY = self::PREFIX . 'session_budget';

	public function __construct() {
		// Stub — no construction-time state required.
	}

	public static function init() {
		// Stub — the agent is invoked on demand by the REST layer, not hooked.
	}

	// ── Main entry point ──────────────────────────────────────────────────────

	/**
	 * Process one chat turn and return a plain-text reply.
	 *
	 * @param string $message    The visitor's message.
	 * @param string $session_id Opaque per-conversation identifier.
	 * @param string $client_ip  The visitor's IP (used only for rate limiting).
	 * @return string Plain-text reply (the REST layer handles JSON wrapping).
	 */
	public function chat( string $message, string $session_id, string $client_ip ): string {
		$message = trim( $message );

		// ── 0. Per-IP rate limit ─────────────────────────────────────────────
		if ( ! self::check_rate_limit( $client_ip ) ) {
			// Deliberately does NOT pin the language: a throttled request must
			// not create session state, or a rejected flood would still mint
			// one transient per forged session ID.
			$lang = self::peek_lang( $session_id, $message );
			return self::rate_limit_message( $lang );
		}

		// ── 1. Per-session message cap ───────────────────────────────────────
		$count = self::increment_msg_count( $session_id );
		$lang  = self::ensure_lang( $session_id, $message );
		if ( $count > self::MAX_MSG_PER_SESSION ) {
			return self::cap_message( $lang );
		}

		$history   = self::get_history( $session_id );
		$history[] = array( 'role' => 'user', 'content' => $message );

		$pairs       = Xenios_KB_Bot_KB::get_pairs();
		$kb_keywords = self::kb_keywords( $pairs );

		// ── 2. Off-topic → close in chat ─────────────────────────────────────
		if ( self::is_off_topic( $message, $kb_keywords ) ) {
			return self::close( $session_id, $history, self::close_message( 'off-topic', $lang ) );
		}

		// ── 3. Abuse / probe → close in chat ─────────────────────────────────
		if ( self::is_abuse_probe( $message ) ) {
			return self::close( $session_id, $history, self::close_message( 'abuse-probe', $lang ) );
		}

		// ── 4. Resolver → LLM call ───────────────────────────────────────────
		$messages = array_merge(
			array( array( 'role' => 'system', 'content' => self::build_system_prompt( $pairs ) ) ),
			$history
		);
		$reply = self::call_llm( $messages, $lang );
		// The resolver never escalates, so scrub any "passed to the team" claims.
		$reply     = self::strip_premature_escalation( $reply, $lang );
		$history[] = array( 'role' => 'assistant', 'content' => $reply );
		self::save_history( $session_id, $history );

		return $reply;
	}

	// ── Gate helpers ──────────────────────────────────────────────────────────

	/**
	 * True if the message looks off-topic: it contains a known off-topic signal
	 * AND contains no vocabulary drawn from the configured knowledge base.
	 *
	 * @param string   $message
	 * @param string[] $kb_keywords Lower-case keyword set derived from the KB.
	 */
	private static function is_off_topic( string $message, array $kb_keywords ): bool {
		$off_topic_signals = array(
			'weather', 'weer', 'temperature', 'forecast', 'météo',
			'sport', 'football', 'voetbal', 'foot', 'soccer',
			'news', 'nieuws', 'actualité',
			'recipe', 'recept', 'recette', 'joke', 'grappig', 'blague',
			'movie', 'film', 'music', 'muziek', 'musique', 'song',
			'game', 'spel', 'jeu', 'horoscope', 'poem', 'gedicht', 'poème',
			'capital of', 'hoofdstad van', 'president', 'politics', 'politiek',
			'bitcoin', 'crypto', 'stock price', 'aandeel',
		);

		$msg = self::lower( $message );

		$has_off_topic = false;
		foreach ( $off_topic_signals as $signal ) {
			if ( strpos( $msg, $signal ) !== false ) {
				$has_off_topic = true;
				break;
			}
		}
		if ( ! $has_off_topic ) {
			return false;
		}

		foreach ( $kb_keywords as $keyword ) {
			if ( $keyword !== '' && strpos( $msg, $keyword ) !== false ) {
				return false; // on-topic signal present
			}
		}
		return true;
	}

	/**
	 * True if the message looks like an abuse attempt, probe, or meaningless input.
	 */
	private static function is_abuse_probe( string $message ): bool {
		if ( mb_strlen( trim( $message ) ) < 2 ) {
			return true;
		}
		if ( preg_match( '/<[a-zA-Z]/', $message ) ) {        // HTML / XSS tags
			return true;
		}
		if ( strpos( $message, '{{' ) !== false || strpos( $message, '}}' ) !== false ) {
			return true;                                       // template injection
		}
		if ( preg_match( '/^[\W\d\s]{1,10}$/u', $message ) ) { // only punctuation / digits / whitespace
			return true;
		}
		return false;
	}

	/**
	 * Naive language detection — NL / FR / EN. Used for static strings only; the
	 * resolver LLM does its own detection from the full conversation history.
	 */
	private static function detect_lang( string $message ): string {
		$nl_signals = array( 'ik', 'je', 'het', 'de', 'een', 'niet', 'kan', 'wil', 'hoe',
			'mijn', 'jij', 'wachtwoord', 'inloggen', 'graag', 'alsjeblieft' );
		$fr_signals = array( 'je', 'le', 'la', 'les', 'un', 'une', 'ne', 'pas', 'mon', 'ma',
			'comment', 'mot', 'passe', 'bonjour', 'puis', 'merci', 'pour' );

		$words = preg_split( '/\s+/', self::lower( $message ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return 'en';
		}
		$nl = 0;
		$fr = 0;
		foreach ( $words as $w ) {
			if ( in_array( $w, $nl_signals, true ) ) {
				$nl++;
			}
			if ( in_array( $w, $fr_signals, true ) ) {
				$fr++;
			}
		}
		if ( $fr >= 2 && $fr > $nl ) {
			return 'fr';
		}
		if ( $nl >= 2 && $nl >= $fr ) {
			return 'nl';
		}
		return 'en';
	}

	/**
	 * Post-filter: the resolver never escalates, so if the model claims it has
	 * "passed this to the team", replace the whole reply with safe resolver
	 * language. Mirrors the WEB-285 forbidden-phrase list (EN/NL/FR).
	 */
	private static function strip_premature_escalation( string $reply, string $lang ): string {
		$phrases = array(
			'en' => array(
				"i've passed this to the team", 'passed this to the team',
				'passed it to the team', 'passed to the team',
				'someone will be in touch', 'someone will get back to you',
				'our team will follow up', 'our team will contact you',
				"i've escalated this", 'i have escalated',
				'a colleague will contact you',
			),
			'nl' => array(
				'ik heb dit doorgegeven aan het team', 'doorgegeven aan het team',
				'iemand neemt contact met je op', 'iemand neemt contact met u op',
				'ons team neemt contact op', 'ons team volgt dit op',
				'ik heb dit geëscaleerd',
				'een collega neemt contact met je op',
			),
			'fr' => array(
				"j'ai transmis cela à l'équipe", "transmis à l'équipe",
				'quelqu\'un vous contactera', 'quelqu\'un vous recontactera',
				'notre équipe vous contactera', 'notre équipe vous suivra',
				"j'ai escaladé ceci", "j'ai escaladé ce problème",
				'un collègue vous contactera',
			),
		);

		$safe = array(
			'en' => "I'm looking into this — could you give me a bit more detail so I can help you further?",
			'nl' => 'Ik kijk hier naar — kun je me wat meer details geven zodat ik je verder kan helpen?',
			'fr' => 'Je me penche sur ce problème — pourriez-vous me donner un peu plus de détails pour que je puisse mieux vous aider ?',
		);

		$list        = isset( $phrases[ $lang ] ) ? $phrases[ $lang ] : $phrases['en'];
		$reply_lower = self::lower( $reply );
		foreach ( $list as $phrase ) {
			if ( strpos( $reply_lower, $phrase ) !== false ) {
				return isset( $safe[ $lang ] ) ? $safe[ $lang ] : $safe['en'];
			}
		}
		return $reply;
	}

	// ── System prompt + LLM call ──────────────────────────────────────────────

	/**
	 * Build a generic, knowledge-base-driven system prompt. No product-specific
	 * content — the supplied Q&A pairs are the sole source of truth.
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs
	 */
	private static function build_system_prompt( array $pairs ): string {
		$rules = array(
			'You are a helpful customer support assistant for this website.',
			'',
			'Answer the visitor using ONLY the knowledge base below. If the answer is '
				. 'not covered by the knowledge base, say plainly that you do not have that '
				. 'information and suggest they contact support directly. Never invent facts.',
			'',
			'Tone and formatting rules:',
			'- Be direct and warm. Answer the question first, then add context if needed.',
			'- Be honest about limitations. Do not overpromise.',
			'- Respond in the same language the visitor writes in.',
			'- NEVER use Markdown tables — they render as broken pipe characters in the '
				. 'chat widget. Use a numbered or bulleted list instead.',
			'- Do not claim you have escalated, forwarded, or passed the issue to a team. '
				. 'You cannot create tickets.',
			'',
			'Knowledge base:',
		);

		$prompt = implode( "\n", $rules ) . "\n";

		if ( empty( $pairs ) ) {
			$prompt .= "\n(The knowledge base is currently empty.)\n";
			return $prompt;
		}

		foreach ( $pairs as $pair ) {
			$q = isset( $pair['question'] ) ? trim( (string) $pair['question'] ) : '';
			$a = isset( $pair['answer'] ) ? trim( (string) $pair['answer'] ) : '';
			if ( $q === '' && $a === '' ) {
				continue;
			}
			$prompt .= "\nQ: " . $q . "\nA: " . $a . "\n";
		}

		return $prompt;
	}

	/**
	 * Call the configured OpenAI-compatible chat completion endpoint.
	 *
	 * @param array<int,array{role:string,content:string}> $messages
	 * @param string                                       $lang Detected language for fallbacks.
	 * @return string The model reply, or a graceful fallback on any failure.
	 */
	private static function call_llm( array $messages, string $lang ): string {
		$endpoint = trim( (string) get_option( 'xenios_kb_bot_llm_endpoint', '' ) );
		$key      = trim( (string) get_option( 'xenios_kb_bot_llm_key', '' ) );
		$model    = trim( (string) get_option( 'xenios_kb_bot_llm_model', '' ) );

		if ( $endpoint === '' || $model === '' ) {
			return self::llm_error_message( $lang );
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( $key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'model'    => $model,
						'messages' => $messages,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::llm_error_message( $lang );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return self::llm_error_message( $lang );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
			return self::llm_error_message( $lang );
		}

		$content = trim( (string) $data['choices'][0]['message']['content'] );
		if ( $content === '' ) {
			return self::llm_error_message( $lang );
		}

		return $content;
	}

	// ── Knowledge-base keyword extraction ─────────────────────────────────────

	/**
	 * Derive a lower-case keyword set from the knowledge base (question + answer
	 * tokens of 4+ characters). Used by is_off_topic() as the "on-topic" signal,
	 * replacing the Python agent's hard-coded product keyword list.
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs
	 * @return string[]
	 */
	private static function kb_keywords( array $pairs ): array {
		$blob = '';
		foreach ( $pairs as $pair ) {
			$blob .= ' ' . ( isset( $pair['question'] ) ? $pair['question'] : '' );
			$blob .= ' ' . ( isset( $pair['answer'] ) ? $pair['answer'] : '' );
		}
		$blob   = self::lower( $blob );
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', $blob, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return array();
		}
		$keywords = array();
		foreach ( $tokens as $token ) {
			if ( mb_strlen( $token ) >= 4 ) {
				$keywords[ $token ] = true;
			}
		}
		return array_keys( $keywords );
	}

	// ── Session state (WordPress transients) ──────────────────────────────────

	/**
	 * Build a transient key. The caller-supplied part is reduced to a
	 * fixed-length salted hash, so key material can never grow with input
	 * length and is not guessable across sites.
	 */
	private static function transient_key( string $bucket, string $id ): string {
		return self::PREFIX . $bucket . '_' . wp_hash( $id );
	}

	/**
	 * Reserve capacity for a session before any session-scoped transient is
	 * created.
	 *
	 * Sessions that already hold state always pass — only genuinely new ones
	 * consume budget. Once the window's budget is spent, this returns false and
	 * the REST layer answers with an error instead of minting more transients,
	 * which bounds how many rows a flood of forged session IDs can create.
	 */
	public static function reserve_session( string $session_id ): bool {
		if ( false !== get_transient( self::transient_key( 'count', $session_id ) ) ) {
			return true;
		}

		$now  = time();
		$data = get_transient( self::SESSION_BUDGET_KEY );

		if ( ! is_array( $data ) || empty( $data['expires'] ) || $now > (int) $data['expires'] ) {
			set_transient(
				self::SESSION_BUDGET_KEY,
				array( 'count' => 1, 'expires' => $now + self::SESSION_BUDGET_WINDOW ),
				self::SESSION_BUDGET_WINDOW
			);
			return true;
		}

		if ( (int) $data['count'] >= self::MAX_NEW_SESSIONS_PER_WINDOW ) {
			return false;
		}

		$data['count']++;
		set_transient( self::SESSION_BUDGET_KEY, $data, max( 1, (int) $data['expires'] - $now ) );
		return true;
	}

	/**
	 * @return array<int,array{role:string,content:string}>
	 */
	private static function get_history( string $session_id ): array {
		$history = get_transient( self::transient_key( 'hist', $session_id ) );
		return is_array( $history ) ? $history : array();
	}

	private static function save_history( string $session_id, array $history ): void {
		set_transient( self::transient_key( 'hist', $session_id ), $history, self::SESSION_TTL );
	}

	/**
	 * Clear all transient state for a session (history, message count, language).
	 * Public so the REST layer can wipe a session on DELETE.
	 */
	public static function clear_session( string $session_id ): void {
		delete_transient( self::transient_key( 'hist', $session_id ) );
		delete_transient( self::transient_key( 'count', $session_id ) );
		delete_transient( self::transient_key( 'lang', $session_id ) );
	}

	private static function increment_msg_count( string $session_id ): int {
		$key   = self::transient_key( 'count', $session_id );
		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, self::SESSION_TTL );
		return $count;
	}

	/**
	 * Detect the language from the FIRST message and pin it for the session
	 * (no mid-conversation switching).
	 */
	private static function ensure_lang( string $session_id, string $message ): string {
		$key  = self::transient_key( 'lang', $session_id );
		$lang = get_transient( $key );
		if ( ! $lang ) {
			$lang = self::detect_lang( $message );
			set_transient( $key, $lang, self::SESSION_TTL );
		}
		return $lang;
	}

	/**
	 * Language for a reply that must not create session state: reuse the pinned
	 * language when the session already has one, otherwise detect it in memory
	 * without persisting anything.
	 */
	private static function peek_lang( string $session_id, string $message ): string {
		$lang = get_transient( self::transient_key( 'lang', $session_id ) );
		return $lang ? (string) $lang : self::detect_lang( $message );
	}

	/**
	 * Fixed-window per-IP rate limit. Returns true if the request is allowed.
	 */
	private static function check_rate_limit( string $client_ip ): bool {
		$key  = self::transient_key( 'rl', $client_ip );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['expires'] ) || $now > (int) $data['expires'] ) {
			set_transient(
				$key,
				array( 'count' => 1, 'expires' => $now + self::RATE_LIMIT_WINDOW ),
				self::RATE_LIMIT_WINDOW
			);
			return true;
		}

		if ( (int) $data['count'] >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		$data['count']++;
		$ttl = max( 1, (int) $data['expires'] - $now );
		set_transient( $key, $data, $ttl );
		return true;
	}

	// ── Closing helpers + static localized strings ────────────────────────────

	/**
	 * Append an in-chat closing reply, persist history, and return it. No
	 * escalation, no ticket.
	 */
	private static function close( string $session_id, array $history, string $reply ): string {
		$history[] = array( 'role' => 'assistant', 'content' => $reply );
		self::save_history( $session_id, $history );
		return $reply;
	}

	private static function close_message( string $reason, string $lang ): string {
		$messages = array(
			'en' => array(
				'abuse-probe' => "I wasn't able to understand that. Please try again with your question.",
				'off-topic'   => 'I can only help with questions about this service. Is there anything else I can help you with?',
			),
			'nl' => array(
				'abuse-probe' => 'Ik kon dat niet begrijpen. Probeer je vraag opnieuw te stellen.',
				'off-topic'   => 'Ik kan alleen helpen met vragen over deze dienst. Kan ik je ergens anders mee helpen?',
			),
			'fr' => array(
				'abuse-probe' => "Je n'ai pas pu comprendre cela. Merci de reformuler votre question.",
				'off-topic'   => 'Je ne peux répondre qu\'aux questions concernant ce service. Puis-je vous aider avec autre chose ?',
			),
		);
		$set = isset( $messages[ $lang ] ) ? $messages[ $lang ] : $messages['en'];
		return isset( $set[ $reason ] ) ? $set[ $reason ] : $set['off-topic'];
	}

	private static function cap_message( string $lang ): string {
		$messages = array(
			'en' => "We've covered a lot here. To keep things manageable, please start a new chat or reach out to support directly.",
			'nl' => 'We hebben al veel besproken. Begin een nieuw gesprek of neem rechtstreeks contact op met support.',
			'fr' => 'Nous avons déjà abordé beaucoup de choses. Veuillez démarrer une nouvelle conversation ou contacter le support directement.',
		);
		return isset( $messages[ $lang ] ) ? $messages[ $lang ] : $messages['en'];
	}

	private static function rate_limit_message( string $lang ): string {
		$messages = array(
			'en' => "You've sent quite a few messages in a short time. Please wait a little while before sending more.",
			'nl' => 'Je hebt in korte tijd veel berichten verstuurd. Wacht even voordat je meer verstuurt.',
			'fr' => 'Vous avez envoyé beaucoup de messages en peu de temps. Veuillez patienter avant d\'en envoyer d\'autres.',
		);
		return isset( $messages[ $lang ] ) ? $messages[ $lang ] : $messages['en'];
	}

	private static function llm_error_message( string $lang ): string {
		$messages = array(
			'en' => "Sorry — I'm having trouble answering right now. Please try again in a moment.",
			'nl' => 'Sorry — ik kan op dit moment niet antwoorden. Probeer het zo opnieuw.',
			'fr' => 'Désolé — je rencontre un problème pour répondre. Veuillez réessayer dans un instant.',
		);
		return isset( $messages[ $lang ] ) ? $messages[ $lang ] : $messages['en'];
	}

	/**
	 * Unicode-aware lower-case helper.
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
