<?php
/**
 * Admin settings page.
 *
 * Single settings screen under Settings → Xenios KB Bot with three sections:
 *   A. Knowledge Base   (unlimited Q&A editor template)
 *   B. Bot Settings     (name, welcome message, accent colour)
 *   C. LLM Provider     (endpoint, API key, model)
 *
 * All three sections live in ONE form that posts to admin-post.php
 * (action=xenios_kb_bot_save). The knowledge base template renders only the
 * Q&A fields — the surrounding <form>, nonce and submit button belong to this page.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Xenios_KB_Bot_Settings {

	const MENU_SLUG  = 'xenios-kb-bot';
	const NONCE_ACT  = 'xenios_kb_bot_settings';
	const NONCE_NAME = 'xenios_kb_bot_settings_nonce';

	const DEFAULT_ACCENT = '#0044cc';
	const DEFAULT_MODEL  = 'mistral-small-latest';

	public function __construct() {
		// Stub.
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_xenios_kb_bot_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu() {
		add_options_page(
			__( 'Xenios KB Bot', 'xenios-kb-bot' ),
			__( 'Xenios KB Bot', 'xenios-kb-bot' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS on the settings screen only.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'xenios-kb-bot-admin',
			XENIOS_KB_BOT_URL . 'admin/assets/admin.css',
			array(),
			XENIOS_KB_BOT_VERSION
		);
		wp_enqueue_script(
			'xenios-kb-bot-admin',
			XENIOS_KB_BOT_URL . 'admin/assets/admin.js',
			array(),
			XENIOS_KB_BOT_VERSION,
			true
		);
	}

	/**
	 * Render the settings page (one form, three sections).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bot_name = get_option( 'xenios_kb_bot_bot_name', 'KB Bot' );
		$welcome  = get_option( 'xenios_kb_bot_welcome', 'Hi! How can I help you today?' );
		$accent   = get_option( 'xenios_kb_bot_accent', self::DEFAULT_ACCENT );
		$endpoint = get_option( 'xenios_kb_bot_llm_endpoint', '' );
		$api_key  = get_option( 'xenios_kb_bot_llm_key', '' );
		$model    = get_option( 'xenios_kb_bot_llm_model', self::DEFAULT_MODEL );

		$template = XENIOS_KB_BOT_PATH . 'admin/settings.php';
		?>
		<div class="wrap xkb-settings">
			<h1><?php esc_html_e( 'Xenios KB Bot', 'xenios-kb-bot' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'xenios-kb-bot' ); ?></p>
				</div>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="xenios_kb_bot_save" />
				<?php wp_nonce_field( self::NONCE_ACT, self::NONCE_NAME ); ?>

				<h2 class="title"><?php esc_html_e( 'Knowledge Base', 'xenios-kb-bot' ); ?></h2>
				<?php
				if ( file_exists( $template ) ) {
					include $template;
				}
				?>

				<h2 class="title"><?php esc_html_e( 'Bot Settings', 'xenios-kb-bot' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="xkb-bot-name"><?php esc_html_e( 'Bot name', 'xenios-kb-bot' ); ?></label>
						</th>
						<td>
							<input name="xenios_kb_bot_bot_name" id="xkb-bot-name" type="text"
								class="regular-text" value="<?php echo esc_attr( $bot_name ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="xkb-welcome"><?php esc_html_e( 'Welcome message', 'xenios-kb-bot' ); ?></label>
						</th>
						<td>
							<textarea name="xenios_kb_bot_welcome" id="xkb-welcome" rows="2"
								class="large-text"><?php echo esc_textarea( $welcome ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="xkb-accent"><?php esc_html_e( 'Accent colour', 'xenios-kb-bot' ); ?></label>
						</th>
						<td>
							<input name="xenios_kb_bot_accent" id="xkb-accent" type="color"
								value="<?php echo esc_attr( $accent ); ?>" />
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'LLM Provider', 'xenios-kb-bot' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="xkb-endpoint"><?php esc_html_e( 'Endpoint URL', 'xenios-kb-bot' ); ?></label>
						</th>
						<td>
							<input name="xenios_kb_bot_llm_endpoint" id="xkb-endpoint" type="text"
								class="regular-text" value="<?php echo esc_attr( $endpoint ); ?>"
								placeholder="https://api.mistral.ai/v1/chat/completions" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="xkb-key"><?php esc_html_e( 'API key', 'xenios-kb-bot' ); ?></label>
						</th>
						<td>
							<input name="xenios_kb_bot_llm_key" id="xkb-key" type="password"
								class="regular-text" value="<?php echo esc_attr( $api_key ); ?>"
								placeholder="sk-..." autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="xkb-model"><?php esc_html_e( 'Model name', 'xenios-kb-bot' ); ?></label>
						</th>
						<td>
							<input name="xenios_kb_bot_llm_model" id="xkb-model" type="text"
								class="regular-text" value="<?php echo esc_attr( $model ); ?>"
								placeholder="<?php echo esc_attr( self::DEFAULT_MODEL ); ?>" />
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'xenios-kb-bot' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the settings POST: verify nonce, sanitize, persist, redirect back.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xenios-kb-bot' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACT ) ) {
			wp_die( esc_html__( 'Security check failed.', 'xenios-kb-bot' ) );
		}

		// ── Section A: Knowledge base pairs ──────────────────────────────────
		$pairs = array();
		if ( isset( $_POST['xenios_kb_bot_qa'] ) && is_array( $_POST['xenios_kb_bot_qa'] ) ) {
			// Sanitization happens inside Xenios_KB_Bot_KB::save().
			$raw = wp_unslash( $_POST['xenios_kb_bot_qa'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$pairs[] = array(
					'question' => isset( $entry['question'] ) ? $entry['question'] : '',
					'answer'   => isset( $entry['answer'] ) ? $entry['answer'] : '',
				);
			}
		}
		Xenios_KB_Bot_KB::save( $pairs );

		// ── Section B: Bot settings ──────────────────────────────────────────
		update_option(
			'xenios_kb_bot_bot_name',
			isset( $_POST['xenios_kb_bot_bot_name'] ) ? sanitize_text_field( wp_unslash( $_POST['xenios_kb_bot_bot_name'] ) ) : ''
		);
		update_option(
			'xenios_kb_bot_welcome',
			isset( $_POST['xenios_kb_bot_welcome'] ) ? sanitize_textarea_field( wp_unslash( $_POST['xenios_kb_bot_welcome'] ) ) : ''
		);
		$accent = isset( $_POST['xenios_kb_bot_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['xenios_kb_bot_accent'] ) ) : '';
		if ( ! $accent ) {
			$accent = self::DEFAULT_ACCENT;
		}
		update_option( 'xenios_kb_bot_accent', $accent );

		// ── Section C: LLM provider ──────────────────────────────────────────
		update_option(
			'xenios_kb_bot_llm_endpoint',
			isset( $_POST['xenios_kb_bot_llm_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['xenios_kb_bot_llm_endpoint'] ) ) : ''
		);
		update_option(
			'xenios_kb_bot_llm_key',
			isset( $_POST['xenios_kb_bot_llm_key'] ) ? sanitize_text_field( wp_unslash( $_POST['xenios_kb_bot_llm_key'] ) ) : ''
		);
		$model = isset( $_POST['xenios_kb_bot_llm_model'] ) ? sanitize_text_field( wp_unslash( $_POST['xenios_kb_bot_llm_model'] ) ) : '';
		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}
		update_option( 'xenios_kb_bot_llm_model', $model );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
