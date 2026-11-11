<?php
/**
 * Citations module — lets the AI cite the knowledge base sources it used.
 *
 * Sources included in the prompt context are numbered [1], [2], … The AI is
 * given a `cite_sources` function declaration (same function-calling mechanism
 * as lead capture). When the model calls it, the cited source IDs are resolved
 * against the whitelisted sources that were actually sent to the model, and
 * the final reply's [n] markers are replaced server-side with markdown links
 * to the source permalinks. The REST response also carries a `sources` array
 * so the frontend can render a sources list.
 *
 * Because links are built server-side from get_permalink() of the actual
 * posts, the model can never inject arbitrary URLs as citations.
 *
 * @package Guzmandrade_AI_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides citation references for chatbot responses via function calling.
 */
class GAIC_Citations {

	/**
	 * Settings instance.
	 *
	 * @var GAIC_Settings
	 */
	private GAIC_Settings $settings;

	/**
	 * Sources cited via the cite_sources function call in the current request.
	 *
	 * @var array<int,array{id:int,title:string,url:string}>
	 */
	private array $cited = array();

	/**
	 * Constructor.
	 *
	 * @param GAIC_Settings $settings Settings instance.
	 */
	public function __construct( GAIC_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns whether citation references are enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'citations_enabled', true );
	}

	/**
	 * Returns the FunctionDeclaration for the cite_sources tool.
	 *
	 * @return \WordPress\AiClient\Tools\DTO\FunctionDeclaration|null
	 */
	public function get_function_declaration(): ?\WordPress\AiClient\Tools\DTO\FunctionDeclaration {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		if ( ! class_exists( 'WordPress\AiClient\Tools\DTO\FunctionDeclaration' ) ) {
			return null;
		}

		$fn_class = 'WordPress\AiClient\Tools\DTO\FunctionDeclaration';

		return new $fn_class(
			'cite_sources',
			__( 'Cite the knowledge base sources used in your answer. Call this function whenever your answer uses information from one of the numbered context sources, passing the numbers of those sources. Only cite sources that appear in the context.', 'guzmandrade-ai-chatbot' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'sources' => array(
						'type'        => 'array',
						'description' => 'Numbers of the context sources you used, e.g. [1, 3].',
						'items'       => array( 'type' => 'integer' ),
					),
				),
				'required'   => array( 'sources' ),
			)
		);
	}

	/**
	 * Returns instructions to append to the system prompt when citations are
	 * enabled and sources were included in the context.
	 *
	 * @param int $source_count Number of numbered sources in the context.
	 * @return string
	 */
	public function get_system_prompt_addition( int $source_count ): string {
		if ( ! $this->is_enabled() || $source_count < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of context sources. */
			__( "\n\n## Citations\nThe knowledge base context above lists sources numbered [1] to [%d]. Whenever your answer uses information from a source, call the cite_sources function with that source's number, and also reference it in your text as [n] — for example: \"Refunds are processed within 5-7 business days [1].\" Only cite sources that appear in the context.", 'guzmandrade-ai-chatbot' ),
			$source_count
		);
	}

	/**
	 * Processes a cite_sources function call. Resolves the requested source
	 * numbers against the whitelisted sources and returns the function response.
	 *
	 * @param \WordPress\AiClient\Tools\DTO\FunctionCall       $call    The function call.
	 * @param array<int,array{id:int,title:string,url:string}> $sources Whitelisted sources from the context.
	 * @return \WordPress\AiClient\Tools\DTO\FunctionResponse|null
	 */
	public function handle_function_call( $call, array $sources ): ?\WordPress\AiClient\Tools\DTO\FunctionResponse {
		if ( ! $call || ! method_exists( $call, 'getName' ) || 'cite_sources' !== $call->getName() ) {
			return null;
		}

		if ( ! class_exists( 'WordPress\AiClient\Tools\DTO\FunctionResponse' ) ) {
			return null;
		}

		$by_id = array();
		foreach ( $sources as $source ) {
			$by_id[ $source['id'] ] = $source;
		}

		$args = $call->getArgs();
		$args = is_array( $args ) ? $args : array();

		$ids = array_filter( array_map( 'absint', (array) ( $args['sources'] ?? array() ) ) );

		$this->cited = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$this->cited[] = $by_id[ $id ];
			}
		}

		$response_class = 'WordPress\AiClient\Tools\DTO\FunctionResponse';

		return new $response_class(
			$call->getId(),
			$call->getName(),
			array(
				'cited'   => $this->cited,
				'message' => __( 'Sources recorded. Now write the final answer to the user, referencing the sources in your text as [n].', 'guzmandrade-ai-chatbot' ),
			)
		);
	}

	/**
	 * Replaces [n] citation markers in the reply with markdown links to the
	 * whitelisted sources, and returns the reply together with the used sources.
	 *
	 * The frontend renders [text](url) markdown as a safe link, so inline
	 * citations become clickable without any URL coming from the model.
	 *
	 * @param string                                           $reply   The AI reply.
	 * @param array<int,array{id:int,title:string,url:string}> $sources Whitelisted sources from the context.
	 * @return array{reply:string, sources:array<int,array{id:int,title:string,url:string}>}
	 */
	public function format_reply( string $reply, array $sources ): array {
		if ( ! $this->is_enabled() || empty( $sources ) ) {
			return array(
				'reply'   => $reply,
				'sources' => array(),
			);
		}

		$by_id = array();
		foreach ( $sources as $source ) {
			$by_id[ $source['id'] ] = $source;
		}

		$used  = array();
		$reply = (string) preg_replace_callback(
			'/\[(\d{1,2})\]/',
			static function ( array $m ) use ( $by_id, &$used ): string {
				$id = (int) $m[1];
				if ( ! isset( $by_id[ $id ] ) ) {
					return $m[0];
					// Not a known source — leave untouched.
				}
				$source      = $by_id[ $id ];
				$used[ $id ] = $source;
				return '[' . $source['title'] . '](' . $source['url'] . ')';
			},
			$reply
		);

		// Merge sources the model cited via the function call but did not
		// reference as [n] in the text.
		foreach ( $this->cited as $source ) {
			if ( ! isset( $used[ $source['id'] ] ) ) {
				$used[ $source['id'] ] = $source;
			}
		}

		ksort( $used );

		return array(
			'reply'   => $reply,
			'sources' => array_values( $used ),
		);
	}
}
