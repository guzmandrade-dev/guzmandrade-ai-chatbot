<?php
/**
 * Main plugin class — bootstraps all modules.
 *
 * @package Guzmandrade_AI_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core orchestrator for the Guzmandrade AI Chatbot plugin.
 */
final class GAIC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var ?GAIC_Plugin
	 */
	private static ?GAIC_Plugin $instance = null;

	/**
	 * Settings module.
	 *
	 * @var ?GAIC_Settings
	 */
	private ?GAIC_Settings $settings = null;

	/**
	 * Knowledge Base module.
	 *
	 * @var ?GAIC_Knowledge_Base
	 */
	private ?GAIC_Knowledge_Base $knowledge_base = null;

	/**
	 * Spam module.
	 *
	 * @var ?GAIC_Spam
	 */
	private ?GAIC_Spam $spam = null;

	/**
	 * Captcha module.
	 *
	 * @var ?GAIC_Captcha
	 */
	private ?GAIC_Captcha $captcha = null;

	/**
	 * Lead capture module.
	 *
	 * @var ?GAIC_Lead_Capture
	 */
	private ?GAIC_Lead_Capture $lead_capture = null;

	/**
	 * Citations module.
	 *
	 * @var ?GAIC_Citations
	 */
	private ?GAIC_Citations $citations = null;

	/**
	 * Chatbot module.
	 *
	 * @var ?GAIC_Chatbot
	 */
	private ?GAIC_Chatbot $chatbot = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return GAIC_Plugin
	 */
	public static function get_instance(): GAIC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wires up hooks and modules.
	 *
	 * Note: load_plugin_textdomain() is not called since WordPress 4.6
	 * loads translations for plugins automatically.
	 */
	private function __construct() {
		$this->require_files();
		$this->init_modules();
		$this->check_dependencies();
	}

	/**
	 * Includes module class files.
	 */
	private function require_files(): void {
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-settings.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-knowledge-base.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-spam.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-captcha.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-lead-capture.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-citations.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-chatbot.php';
	}

	/**
	 * Instantiates all modules.
	 */
	private function init_modules(): void {
		$this->settings       = new GAIC_Settings();
		$this->knowledge_base = new GAIC_Knowledge_Base( $this->settings );
		$this->spam           = new GAIC_Spam( $this->settings );
		$this->captcha        = new GAIC_Captcha( $this->settings );
		$this->lead_capture   = new GAIC_Lead_Capture( $this->settings );
		$this->citations      = new GAIC_Citations( $this->settings );
		$this->chatbot        = new GAIC_Chatbot( $this, $this->settings, $this->knowledge_base, $this->spam, $this->captcha, $this->lead_capture, $this->citations );
	}

	/**
	 * Checks that the runtime has the AI building blocks we depend on and
	 * surfaces an admin notice when something is missing.
	 */
	private function check_dependencies(): void {
		add_action( 'admin_notices', array( $this, 'render_dependency_notices' ) );
	}

	/**
	 * Renders admin notices for missing dependencies.
	 */
	public function render_dependency_notices(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: Link to the AI plugin. */
						__( 'Guzmandrade AI Chatbot needs the WordPress AI building blocks to function. Please install and activate the <a href="%s">AI plugin</a> or upgrade to WordPress 7.0+. Then configure an AI provider under <strong>Settings &rsaquo; Connectors</strong>.', 'guzmandrade-ai-chatbot' ),
						'https://wordpress.org/plugins/ai/'
					),
					array(
						'a'      => array( 'href' => array() ),
						'strong' => array(),
					)
				)
			);
		}

		if ( ! class_exists( 'Akismet' ) && $this->settings->get( 'spam_protection', true ) ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: Link to Akismet. */
						__( 'Guzmandrade AI Chatbot recommends <a href="%s">Akismet</a> for spam protection. Install and activate it to enable spam filtering on chat messages. Rate-limiting fallback is active in the meantime.', 'guzmandrade-ai-chatbot' ),
						'https://wordpress.org/plugins/akismet/'
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}

		if ( ! $this->captcha->is_available() && $this->settings->get( 'captcha_enabled', false ) ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: Link to the Turnstile plugin. */
						__( 'Guzmandrade AI Chatbot is configured to use captcha but the <a href="%s">Simple CAPTCHA with Cloudflare Turnstile</a> plugin is not active. Install and configure it, or disable captcha in the chatbot settings.', 'guzmandrade-ai-chatbot' ),
						'https://wordpress.org/plugins/simple-cloudflare-turnstile/'
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}
	}

	/**
	 * Returns whether the AI client SDK is available.
	 *
	 * @return bool
	 */
	public function is_ai_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	// ── Module accessors ──────────────────────────────────────────────

	/**
	 * Gets the settings module.
	 *
	 * @return GAIC_Settings
	 */
	public function settings(): GAIC_Settings {
		return $this->settings;
	}

	/**
	 * Gets the knowledge base module.
	 *
	 * @return GAIC_Knowledge_Base
	 */
	public function knowledge_base(): GAIC_Knowledge_Base {
		return $this->knowledge_base;
	}

	/**
	 * Gets the spam module.
	 *
	 * @return GAIC_Spam
	 */
	public function spam(): GAIC_Spam {
		return $this->spam;
	}

	/**
	 * Gets the captcha module.
	 *
	 * @return GAIC_Captcha
	 */
	public function captcha(): GAIC_Captcha {
		return $this->captcha;
	}

	/**
	 * Gets the lead capture module.
	 *
	 * @return GAIC_Lead_Capture
	 */
	public function lead_capture(): GAIC_Lead_Capture {
		return $this->lead_capture;
	}

	/**
	 * Gets the citations module.
	 *
	 * @return GAIC_Citations
	 */
	public function citations(): GAIC_Citations {
		return $this->citations;
	}

	// ── Activation / Deactivation ────────────────────────────────────

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-settings.php';
		require_once GAIC_PLUGIN_DIR . 'includes/class-gaic-knowledge-base.php';

		// Seed default settings.
		if ( false === get_option( 'gaic_settings' ) ) {
			add_option( 'gaic_settings', GAIC_Settings::get_defaults() );
		}

		// Register the CPT so rewrite rules are set up on activation.
		$kb = new GAIC_Knowledge_Base( new GAIC_Settings() );
		$kb->register_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
