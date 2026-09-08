<?php
/**
 * Plugin Name: Xenios KB Bot
 * Plugin URI:  https://github.com/Krateos-BV/xenios-kb-bot
 * Description: AI-powered chatbot driven entirely by your own knowledge base. Your knowledge base stays in your WordPress database — only the visitor's question and the knowledge base content needed to answer it are sent to the AI provider you configure with your own API key.
 * Version:     2026.09.08
 * Author:      Krateos BV
 * Author URI:  https://xeniacloud.eu
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: xenios-kb-bot
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'XENIOS_KB_BOT_VERSION', '2026.09.08' );
define( 'XENIOS_KB_BOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'XENIOS_KB_BOT_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
require_once XENIOS_KB_BOT_PATH . 'includes/class-kb.php';
require_once XENIOS_KB_BOT_PATH . 'includes/class-agent.php';
require_once XENIOS_KB_BOT_PATH . 'includes/class-rest-api.php';
require_once XENIOS_KB_BOT_PATH . 'includes/class-widget.php';
require_once XENIOS_KB_BOT_PATH . 'admin/class-settings.php';

add_action( 'plugins_loaded', function() {
    Xenios_KB_Bot_REST::init();
    Xenios_KB_Bot_Widget::init();
    Xenios_KB_Bot_Settings::init();
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=xenios-kb-bot' ) . '">' . __( 'Settings', 'xenios-kb-bot' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );
