<?php
/**
 * Plugin Name:       Guzmandrade AI Chatbot
 * Plugin URI:        https://github.com/guzmandrade-dev/guzmandrade-ai-chatbot
 * Description:       AI-powered frontend chatbot with a knowledge base, Akismet spam protection, and Cloudflare Turnstile captcha. Leverages the WordPress 7.0 Connectors API and the PHP AI Client SDK — no custom AI provider code required.
 * Version:           1.2.0
 * Author:            Mauricio Andrade
 * Author URI:        https://profiles.wordpress.org/h4l9k/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guzmandrade-ai-chatbot
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'GAIC_VERSION', '1.1.2' );
define( 'GAIC_PLUGIN_FILE', __FILE__ );
define( 'GAIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GAIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GAIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-plugin.php';

register_activation_hook( __FILE__, array( 'GAIC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GAIC_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {

		GAIC_Plugin::get_instance();
	}
);
