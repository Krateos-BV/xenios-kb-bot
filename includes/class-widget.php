<?php
/**
 * Front-end chat widget: asset enqueue + footer markup.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Xenios_KB_Bot_Widget {

	public function __construct() {
		// Stub.
	}

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 100 );
	}

	/**
	 * Enqueue the widget stylesheet + script and pass config to the frontend.
	 */
	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'xenios-kb-bot-widget',
			XENIOS_KB_BOT_URL . 'assets/widget.css',
			array(),
			XENIOS_KB_BOT_VERSION
		);

		// Inject the accent colour as a CSS variable.
		$accent = sanitize_hex_color( get_option( 'xenios_kb_bot_accent', '#0044cc' ) );
		if ( ! $accent ) {
			$accent = '#0044cc';
		}
		wp_add_inline_style(
			'xenios-kb-bot-widget',
			':root{--xkb-accent:' . $accent . ';}'
		);

		// render() (below) prints the bubble/window markup on wp_footer at
		// priority 100 — AFTER core's wp_print_footer_scripts (priority 20)
		// has already printed and executed this script tag. Without `defer`,
		// the script runs before its own markup exists in the DOM, so every
		// getElementById() call below returns null and the widget silently
		// does nothing on click (XNT-21). `defer` makes execution wait until
		// the full document is parsed, independent of hook-priority ordering.
		wp_enqueue_script(
			'xenios-kb-bot-widget',
			XENIOS_KB_BOT_URL . 'assets/widget.js',
			array(),
			XENIOS_KB_BOT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'xenios-kb-bot-widget',
			'xenios_kb_bot_cfg',
			array(
				'rest_url' => esc_url_raw( rest_url( 'xenios-kb-bot/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'bot_name' => get_option( 'xenios_kb_bot_bot_name', 'KB Bot' ),
				'welcome'  => get_option( 'xenios_kb_bot_welcome', 'Hi! How can I help you today?' ),
				'accent'   => $accent,
			)
		);
	}

	/**
	 * Output the chat bubble + window markup in the footer.
	 */
	public static function render() {
		if ( is_admin() ) {
			return;
		}

		$bot_name = get_option( 'xenios_kb_bot_bot_name', 'KB Bot' );
		?>
		<div id="xkb-bubble" class="xkb-bubble" title="<?php esc_attr_e( 'Chat with us', 'xenios-kb-bot' ); ?>" role="button" tabindex="0">
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
			</svg>
		</div>

		<div id="xkb-window" class="xkb-window">
			<div class="xkb-resize-handle"></div>
			<div id="xkb-header" class="xkb-header">
				<span id="xkb-title" class="xkb-title"><?php echo esc_html( $bot_name ); ?></span>
				<div class="xkb-header-actions">
					<span class="xkb-header-note"><?php esc_html_e( 'AI-assisted', 'xenios-kb-bot' ); ?></span>
					<button id="xkb-minimize" class="xkb-minimize" title="<?php esc_attr_e( 'Minimise', 'xenios-kb-bot' ); ?>" aria-label="<?php esc_attr_e( 'Minimise', 'xenios-kb-bot' ); ?>">&minus;</button>
				</div>
			</div>
			<div id="xkb-messages" class="xkb-messages"></div>
			<div class="xkb-notice"><?php esc_html_e( 'AI-assisted support', 'xenios-kb-bot' ); ?></div>
			<div class="xkb-input-area">
				<input id="xkb-input" class="xkb-input" type="text" placeholder="<?php esc_attr_e( 'Type your message…', 'xenios-kb-bot' ); ?>" />
				<button id="xkb-send" class="xkb-send"><?php esc_html_e( 'Send', 'xenios-kb-bot' ); ?></button>
			</div>
			<div class="xkb-end-bar">
				<button id="xkb-end" class="xkb-end-btn"><?php esc_html_e( 'End chat', 'xenios-kb-bot' ); ?></button>
			</div>
		</div>
		<?php
	}
}
