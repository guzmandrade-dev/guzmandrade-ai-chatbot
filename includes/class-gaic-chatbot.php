<?php
/**
 * Chatbot module — shortcode, REST endpoint, and frontend injection.
 *
 * @package Guzmandrade_AI_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the frontend chatbot: shortcode, REST API, asset enqueuing, and AI calls.
 */
class GAIC_Chatbot {

	/** REST API namespace. */
	const REST_NAMESPACE = 'gaic/v1';

	/**
	 * Plugin instance.
	 *
	 * @var GAIC_Plugin
	 */
	private GAIC_Plugin $plugin;

	/**
	 * Settings instance.
	 *
	 * @var GAIC_Settings
	 */
	private GAIC_Settings $settings;

	/**
	 * Knowledge base instance.
	 *
	 * @var GAIC_Knowledge_Base
	 */
	private GAIC_Knowledge_Base $knowledge_base;

	/**
	 * Spam instance.
	 *
	 * @var GAIC_Spam
	 */
	private GAIC_Spam $spam;

	/**
	 * Captcha instance.
	 *
	 * @var GAIC_Captcha
	 */
	private GAIC_Captcha $captcha;

	/**
	 * Lead capture instance.
	 *
	 * @var GAIC_Lead_Capture
	 */
	private GAIC_Lead_Capture $lead_capture;

	/**
	 * Citations instance.
	 *
	 * @var GAIC_Citations
	 */
	private GAIC_Citations $citations;

	/**
	 * Constructor.
	 *
	 * @param GAIC_Plugin         $plugin         Plugin instance.
	 * @param GAIC_Settings       $settings       Settings.
	 * @param GAIC_Knowledge_Base $knowledge_base Knowledge base.
	 * @param GAIC_Spam           $spam           Spam protection.
	 * @param GAIC_Captcha        $captcha        Captcha protection.
	 * @param GAIC_Lead_Capture   $lead_capture   Lead capture.
	 * @param GAIC_Citations      $citations      Citations.
	 */
	public function __construct(
		GAIC_Plugin $plugin,
		GAIC_Settings $settings,
		GAIC_Knowledge_Base $knowledge_base,
		GAIC_Spam $spam,
		GAIC_Captcha $captcha,
		GAIC_Lead_Capture $lead_capture,
		GAIC_Citations $citations
	) {
		$this->plugin         = $plugin;
		$this->settings       = $settings;
		$this->knowledge_base = $knowledge_base;
		$this->spam           = $spam;
		$this->captcha        = $captcha;
		$this->lead_capture   = $lead_capture;
		$this->citations      = $citations;

		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_inject_chatbot' ) );
	}

	// ── Shortcode ─────────────────────────────────────────────────────

	/**
	 * Registers the [gaic_chatbot] shortcode.
	 */
	public function register_shortcode(): void {
		add_shortcode( 'gaic_chatbot', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Renders the chatbot container via the shortcode.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string HTML markup.
	 */
	public function render_shortcode( $atts = array() ): string {
		// Force the assets to load on this page.
		$this->enqueue_frontend_assets();

		$atts = shortcode_atts(
			array(
				'position' => $this->settings->get( 'position', 'bottom-right' ),
			),
			$atts,
			'gaic_chatbot'
		);

		return sprintf(
			'<div class="gaic-chatbot-container" data-gaic-position="%s"></div>',
			esc_attr( $atts['position'] )
		);
	}

	// ── Frontend injection ─────────────────────────────────────────────

	/**
	 * Injects a site-wide floating chatbot when enabled.
	 */
	public function maybe_inject_chatbot(): void {
		if ( ! $this->settings->get( 'enabled', false ) ) {
			return;
		}

		// Don't inject on admin, login, or REST requests.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$this->enqueue_frontend_assets();

		$position = sanitize_html_class( (string) $this->settings->get( 'position', 'bottom-right' ) );
		printf(
			'<div class="gaic-chatbot-container" data-gaic-position="%s"></div>',
			esc_attr( $position )
		);
	}

	// ── Assets ─────────────────────────────────────────────────────────

	/**
	 * Registers (but does not enqueue) frontend assets.
	 */
	public function register_assets(): void {
		wp_register_style(
			'gaic-chatbot',
			GAIC_PLUGIN_URL . 'assets/css/chatbot.css',
			array(),
			GAIC_VERSION
		);

		wp_register_script(
			'gaic-chatbot',
			GAIC_PLUGIN_URL . 'assets/js/chatbot.js',
			array(),
			GAIC_VERSION,
			true
		);
	}

	/**
	 * Enqueues and localizes the frontend assets.
	 */
	private function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'gaic-chatbot' );
		wp_enqueue_script( 'gaic-chatbot' );

		// Enqueue Turnstile scripts if captcha is enabled.
		$this->captcha->enqueue_scripts();

		$user = wp_get_current_user();

		// Optional Gravatar for the assistant. get_avatar_url() mirrors the
		// comment-avatar behavior in Settings › Discussion: an email without
		// a Gravatar, or no email at all, falls back to the site's default
		// avatar.
		$avatar_email = sanitize_email( (string) $this->settings->get( 'avatar_email', '' ) );
		$avatar_url   = get_avatar_url( $avatar_email, array( 'size' => 64 ) );

		$data = array(
			'restUrl'        => esc_url_raw( rest_url( self::REST_NAMESPACE . '/chat' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'title'          => $this->settings->get( 'title', __( 'Chat with us', 'guzmandrade-ai-chatbot' ) ),
			'subtitle'       => $this->settings->get( 'subtitle', '' ),
			'welcomeMessage' => $this->settings->get( 'welcome_message', __( 'Hello! How can I help you today?', 'guzmandrade-ai-chatbot' ) ),
			'position'       => $this->settings->get( 'position', 'bottom-right' ),
			'userName'       => $user->exists() ? $user->display_name : '',
			'avatarUrl'      => $avatar_url ? esc_url_raw( $avatar_url ) : '',
			'captchaEnabled' => $this->captcha->is_enabled(),
			'captchaSiteKey' => $this->captcha->is_enabled() ? $this->captcha->get_site_key() : '',
			'strings'        => array(
				'placeholder'     => __( 'Type your message…', 'guzmandrade-ai-chatbot' ),
				'send'            => __( 'Send', 'guzmandrade-ai-chatbot' ),
				'openChat'        => __( 'Open chat', 'guzmandrade-ai-chatbot' ),
				'closeChat'       => __( 'Close chat', 'guzmandrade-ai-chatbot' ),
				'typing'          => __( 'Assistant is typing…', 'guzmandrade-ai-chatbot' ),
				'error'           => __( 'Something went wrong. Please try again.', 'guzmandrade-ai-chatbot' ),
				'spamBlocked'     => __( 'Your message was blocked. Please try again later.', 'guzmandrade-ai-chatbot' ),
				'aiUnavailable'   => __( 'The AI assistant is not configured. Please contact the site administrator.', 'guzmandrade-ai-chatbot' ),
				'captchaRequired' => __( 'Please complete the captcha challenge first.', 'guzmandrade-ai-chatbot' ),
				'captchaFailed'   => __( 'Captcha verification failed. Please try again.', 'guzmandrade-ai-chatbot' ),
				'sources'         => __( 'Sources', 'guzmandrade-ai-chatbot' ),
			),
		);

		wp_add_inline_script(
			'gaic-chatbot',
			'window.GAIC_DATA = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	// ── REST API ──────────────────────────────────────────────────────

	/**
	 * Registers the REST routes.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'chat_permission' ),
				'args'                => array(
					'message'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => array( $this, 'validate_message' ),
					),
					'history'       => array(
						'required'          => false,
						'type'              => 'array',
						'sanitize_callback' => array( $this, 'sanitize_history' ),
					),
					'captcha_token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback for the chat endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function chat_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		// Allow anonymous access — nonce is optional for guests.
		// Spam protection and captcha handle abuse.
		return true;
	}

	/**
	 * Validates that a message is not empty and within size limits.
	 *
	 * @param mixed $value Message value.
	 * @return bool
	 */
	public function validate_message( $value ): bool {
		$value = trim( (string) $value );
		return strlen( $value ) >= 1 && strlen( $value ) <= 4000;
	}

	/**
	 * Sanitizes the conversation history array.
	 *
	 * @param mixed $value Raw history.
	 * @return array<int,array{role:string,content:string}> Sanitized history.
	 */
	public function sanitize_history( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['content'] ) ) {
				continue;
			}
			$role    = in_array( $entry['role'], array( 'user', 'assistant' ), true ) ? $entry['role'] : 'user';
			$content = sanitize_textarea_field( (string) $entry['content'] );
			if ( ! empty( $content ) ) {
				$clean[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
		}

		// Keep only the last 10 messages to limit token usage.
		return array_slice( $clean, -10 );
	}

	/**
	 * Handles a chat request — the core of the plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat( WP_REST_Request $request ) {
		$message       = trim( (string) $request->get_param( 'message' ) );
		$history       = (array) $request->get_param( 'history' );
		$captcha_token = (string) $request->get_param( 'captcha_token' );

		// 1. Check AI availability.
		if ( ! $this->plugin->is_ai_available() ) {
			return new WP_Error(
				'gaic_ai_unavailable',
				__( 'The AI assistant is not configured.', 'guzmandrade-ai-chatbot' ),
				array( 'status' => 503 )
			);
		}

		// 2. Captcha check (if enabled).
		if ( $this->captcha->is_enabled() ) {
			$captcha_result = $this->captcha->verify( $captcha_token );
			if ( ! $captcha_result['valid'] ) {
				return new WP_Error(
					'gaic_captcha_failed',
					$captcha_result['reason'],
					array( 'status' => 403 )
				);
			}
		}

		// 3. Collect user data for spam check.
		$user      = wp_get_current_user();
		$user_data = array(
			'ip'         => $this->spam->get_client_ip(),
			'user_agent' => $this->spam->get_user_agent(),
			'email'      => $user->exists() ? $user->user_email : '',
			'name'       => $user->exists() ? $user->display_name : '',
		);

		// 4. Spam check.
		$spam_result = $this->spam->check( $message, $user_data );
		if ( $spam_result['spam'] ) {
			return new WP_Error(
				'gaic_spam_blocked',
				$spam_result['reason'],
				array( 'status' => 403 )
			);
		}

		// 5. Build the AI prompt.
		//
		// wp_ai_client_prompt() returns a WP_AI_Client_Prompt_Builder which uses
		// __call() to delegate snake_case method names to the SDK's camelCase
		// methods. We call the snake_case methods directly — method_exists()
		// returns false for magic methods, so dynamic dispatch helpers don't work.
		$sources = array();
		try {
			$system_prompt = (string) $this->settings->get( 'system_prompt', '' );
			$context_data  = $this->knowledge_base->get_context_with_sources( $message );
			$sources       = $context_data['sources'];
			$full_system   = trim( $system_prompt . "\n\n" . $context_data['context'] );

			// Append lead capture instructions if enabled.
			$lead_addition = $this->lead_capture->get_system_prompt_addition();
			if ( ! empty( $lead_addition ) ) {
				$full_system .= $lead_addition;
			}

			// Append citation instructions if enabled and sources were included.
			$citations_addition = $this->citations->get_system_prompt_addition( count( $sources ) );
			if ( ! empty( $citations_addition ) ) {
				$full_system .= $citations_addition;
			}

			$builder = wp_ai_client_prompt( $message );
			$builder = $this->apply_ai_config( $builder, $full_system );

			// Add conversation history if provided.
			if ( ! empty( $history ) ) {
				$builder = $this->add_history( $builder, $history );
			}

			// Register function declarations (lead capture and/or citations).
			$fn_declarations = array();
			if ( $this->lead_capture->is_enabled() ) {
				$fn_declaration = $this->lead_capture->get_function_declaration();
				if ( $fn_declaration ) {
					$fn_declarations[] = $fn_declaration;
				}
			}
			if ( ! empty( $sources ) ) {
				$fn_declaration = $this->citations->get_function_declaration();
				if ( $fn_declaration ) {
					$fn_declarations[] = $fn_declaration;
				}
			}
			if ( ! empty( $fn_declarations ) ) {
				$builder = $builder->using_function_declarations( ...$fn_declarations );
			}

			/**
			 * Filters the prompt builder before text generation.
			 *
			 * @since 0.1.0
			 *
			 * @param WP_AI_Client_Prompt_Builder $builder   The AI client prompt builder.
			 * @param string                      $message   The user's message.
			 * @param GAIC_Settings               $settings  Settings instance.
			 */
			$builder = apply_filters( 'gaic_prompt_builder', $builder, $message, $this->settings );

			// If any function declarations are registered (lead capture and/or
			// citations), we need to use generate_text_result() to check for
			// function calls. Otherwise we can use the simpler generate_text()
			// directly.
			$needs_function_calling = $this->lead_capture->is_enabled() || ( $this->citations->is_enabled() && ! empty( $sources ) );
			if ( $needs_function_calling ) {
				$reply = $this->generate_with_function_calling( $builder, $sources, $message, $history, $full_system );

				// Check if the reply is an API error string (some providers
				// return errors as text rather than WP_Error).
				if ( is_string( $reply ) && false !== stripos( $reply, 'The API only allows' ) ) {
					// The function calling cycle hit an API limitation. Fall
					// back to a plain generate_text() call without function
					// declarations to still give the user a response.
					$reply = $builder->generate_text();
				}
			} else {
				$reply = $builder->generate_text();
			}
		} catch ( Throwable $e ) {
			return new WP_Error(
				'gaic_ai_error',
				__( 'Failed to generate a response. Please try again.', 'guzmandrade-ai-chatbot' ),
				array( 'status' => 500 )
			);
		}//end try

		// generate_text() returns string|WP_Error.
		if ( is_wp_error( $reply ) ) {
			return new WP_Error(
				'gaic_ai_error',
				$reply->get_error_message() ? $reply->get_error_message() : __( 'Failed to generate a response. Please try again.', 'guzmandrade-ai-chatbot' ),
				array( 'status' => 500 )
			);
		}

		$reply = (string) $reply;

		// Detect the conversation-end sentinel and strip it.
		$close_chat = false;
		if ( false !== stripos( $reply, '[[CHAT_END]]' ) ) {
			$close_chat = true;
			$reply      = str_ireplace( '[[CHAT_END]]', '', $reply );
			$reply      = trim( $reply );
		}

		// Replace [n] citation markers with markdown links to the whitelisted
		// sources and collect the used sources for the frontend.
		$formatted = $this->citations->format_reply( $reply, $sources );

		return rest_ensure_response(
			array(
				'reply'      => $formatted['reply'],
				'success'    => true,
				'close_chat' => $close_chat,
				'sources'    => $formatted['sources'],
			)
		);
	}

	/**
	 * Generates text with function calling support.
	 *
	 * Uses generate_text_result() to get the full result, which may contain
	 * function calls. If a function call is found, executes it, feeds the
	 * response back, and generates again.
	 *
	 * The function call cycle happens entirely within a single request:
	 * 1. First generate_text_result() — AI may return a function call
	 * 2. We execute the function (save_lead or cite_sources)
	 * 3. We call with_function_response() and generate_text() again
	 * 4. The AI produces a natural text response for the user
	 *
	 * @param mixed                                            $builder     The prompt builder.
	 * @param array<int,array{id:int,title:string,url:string}> $sources    Whitelisted context sources for citations.
	 * @param string                                           $message     The user's message.
	 * @param array<int,array{role:string,content:string}>     $history     Conversation history.
	 * @param string                                           $full_system The full system instruction.
	 * @return string|WP_Error
	 */
	private function generate_with_function_calling( $builder, array $sources, string $message, array $history, string $full_system ) {
		$result = $builder->generate_text_result();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Check if the result contains function calls.
		$function_call = $this->extract_function_call( $result );

		if ( null === $function_call ) {
			// No function call — return the text directly.
			return $result->toText();
		}

		// Keep the first-pass text around as a fallback in case the second
		// generation fails after the function response.
		$first_text = '';
		try {
			$first_text = (string) $result->toText();
		} catch ( Throwable $e ) {
			$first_text = '';
		}

		// Execute the function call — dispatch by name to the right module.
		$function_name     = method_exists( $function_call, 'getName' ) ? $function_call->getName() : '';
		$function_response = null;
		if ( 'save_lead' === $function_name ) {
			$function_response = $this->lead_capture->handle_function_call( $function_call );
		} elseif ( 'cite_sources' === $function_name ) {
			$function_response = $this->citations->handle_function_call( $function_call, $sources );
		}

		if ( null === $function_response ) {
			// Could not handle the function call — return whatever text we got.
			try {
				return $result->toText();
			} catch ( Throwable $e ) {
				return '';
			}
		}

		// Feed the function response back and generate again. Some providers
		// (e.g. Ollama/GLM) reject a function response that shares a message
		// with other content, so the second request is rebuilt with the
		// proper message order: user text → history → model function call →
		// user message containing only the function response.
		$rebuilt = null;
		if ( class_exists( 'WordPress\AiClient\Messages\DTO\Message' ) ) {
			try {
				$message_class = 'WordPress\AiClient\Messages\DTO\Message';

				$user_msg = $message_class::fromArray(
					array(
						'role'  => 'user',
						'parts' => array(
							array(
								'type' => 'text',
								'text' => $message,
							),
						),
					)
				);

				$call_msg = $message_class::fromArray(
					array(
						'role'  => 'model',
						'parts' => array(
							array(
								'functionCall' => array(
									'id'   => $function_call->getId(),
									'name' => $function_call->getName(),
									'args' => $function_call->getArgs(),
								),
							),
						),
					)
				);

				$rebuilt  = wp_ai_client_prompt();
				$rebuilt  = $this->apply_ai_config( $rebuilt, $full_system );
				$messages = array_merge(
					array( $user_msg ),
					$this->history_to_messages( $history ),
					array( $call_msg )
				);
				$rebuilt  = $rebuilt->with_history( ...$messages );
				// Last message is the model function call, so this appends a
				// new user message containing only the function response.
				$rebuilt = $rebuilt->with_function_response( $function_response );
			} catch ( Throwable $e ) {
				$rebuilt = null;
			}//end try
		}//end if

		if ( null === $rebuilt ) {
			// Fallback for SDKs without message DTO support: append the
			// function response to the existing builder.
			$rebuilt = $builder->with_function_response( $function_response );
		}

		/**
		 * Filters the prompt builder after a function call response.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_AI_Client_Prompt_Builder $rebuilt The prompt builder.
		 */
		$rebuilt = apply_filters( 'gaic_after_function_call', $rebuilt );

		$final_text = $rebuilt->generate_text();

		// The second generation might return an error string (not WP_Error)
		// from the API if it rejects the function response format. Detect
		// known API error strings and provide a graceful fallback: for
		// save_lead the lead confirmation, for other functions (citations)
		// the model's first-pass text.
		$fallback = 'save_lead' === $function_name
			? __( 'I\'ve saved your information. Is there anything else I can help you with?', 'guzmandrade-ai-chatbot' )
			: $first_text;

		if ( is_wp_error( $final_text ) ) {
			return $fallback;
		}

		// Some providers return error messages as plain strings instead
		// of WP_Error. Detect common API error patterns.
		$final_text = (string) $final_text;
		if (
			false !== stripos( $final_text, 'The API only allows' )
			|| false !== stripos( $final_text, 'function response' )
			|| ( false !== stripos( $final_text, 'invalid' ) && strlen( $final_text ) < 200 && stripos( $final_text, 'error' ) )
		) {
			return $fallback;
		}

		return $final_text;
	}

	/**
	 * Extracts a function call from a GenerativeAiResult, if present.
	 *
	 * @param mixed $result The GenerativeAiResult.
	 * @return \WordPress\AiClient\Tools\DTO\FunctionCall|null
	 */
	private function extract_function_call( $result ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getCandidates' ) ) {
			return null;
		}

		$candidates = $result->getCandidates();
		if ( empty( $candidates ) ) {
			return null;
		}

		$message = $candidates[0]->getMessage();
		foreach ( $message->getParts() as $part ) {
			// Check if this part is a function call.
			if ( method_exists( $part, 'getFunctionCall' ) ) {
				$call = $part->getFunctionCall();
				if ( $call ) {
					return $call;
				}
			}

			// Also check via type — some SDK versions use different detection.
			if ( method_exists( $part, 'getType' ) ) {
				$type = $part->getType();
				if ( method_exists( $type, 'isFunctionCall' ) && $type->isFunctionCall() ) {
					$call = $part->getFunctionCall();
					if ( $call ) {
						return $call;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Adds conversation history to the prompt builder.
	 *
	 * Constructs Message DTOs from the history entries and passes them
	 * via with_history(), which accepts variadic Message arguments.
	 *
	 * @param WP_AI_Client_Prompt_Builder                  $builder Prompt builder.
	 * @param array<int,array{role:string,content:string}> $history  Conversation history.
	 * @return WP_AI_Client_Prompt_Builder Modified builder.
	 */
	private function add_history( $builder, array $history ) {
		$messages = $this->history_to_messages( $history );

		if ( ! empty( $messages ) ) {
			$builder = $builder->with_history( ...$messages );
		}

		return $builder;
	}

	/**
	 * Converts sanitized history entries into SDK Message DTOs.
	 *
	 * @param array<int,array{role:string,content:string}> $history Conversation history.
	 * @return array<int,\WordPress\AiClient\Messages\DTO\Message> Message DTOs.
	 */
	private function history_to_messages( array $history ): array {
		// The SDK's Message class may not be loaded in all environments.
		if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\Message' ) ) {
			return array();
		}

		$message_class = 'WordPress\AiClient\Messages\DTO\Message';

		$messages = array();
		foreach ( $history as $entry ) {
			// The SDK uses 'model' for assistant messages.
			$role = ( 'assistant' === $entry['role'] ) ? 'model' : 'user';

			// The AI API requires the first history message to be from
			// the user role. Skip any leading model messages (e.g. a
			// welcome greeting that might slip through).
			if ( empty( $messages ) && 'model' === $role ) {
				continue;
			}

			try {
				$messages[] = $message_class::fromArray(
					array(
						'role'  => $role,
						'parts' => array(
							array(
								'type' => 'text',
								'text' => $entry['content'],
							),
						),
					)
				);
			} catch ( Throwable $e ) {
				// Skip malformed history entries.
				continue;
			}
		}//end foreach

		return $messages;
	}

	/**
	 * Applies the common AI configuration (provider, model, system
	 * instruction, temperature, max tokens) to a prompt builder.
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder     The prompt builder.
	 * @param string                      $full_system The full system instruction.
	 * @return WP_AI_Client_Prompt_Builder Modified builder.
	 */
	private function apply_ai_config( $builder, string $full_system ) {
		// Apply provider selection if not "auto".
		$provider = (string) $this->settings->get( 'ai_provider', 'auto' );
		if ( ! empty( $provider ) && 'auto' !== $provider ) {
			$builder = $builder->using_provider( $provider );
		}

		// Apply specific model if configured.
		$model = (string) $this->settings->get( 'ai_model', '' );
		if ( ! empty( $model ) && ! empty( $provider ) && 'auto' !== $provider ) {
			$builder = $builder->using_model_preference( array( $provider, $model ) );
		}

		if ( ! empty( $full_system ) ) {
			$builder = $builder->using_system_instruction( $full_system );
		}

		$temperature = (float) $this->settings->get( 'temperature', 0.3 );
		$builder     = $builder->using_temperature( $temperature );

		$max_tokens = (int) $this->settings->get( 'max_tokens', 1000 );
		$builder    = $builder->using_max_tokens( $max_tokens );

		return $builder;
	}

	/**
	 * Returns the status of the chatbot — used by the frontend to check
	 * if AI is available before showing the widget.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_status(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ai_available'   => $this->plugin->is_ai_available(),
				'akismet_active' => $this->spam->is_akismet_active(),
				'captcha_active' => $this->captcha->is_available(),
				'enabled'        => (bool) $this->settings->get( 'enabled', false ),
			)
		);
	}
}
