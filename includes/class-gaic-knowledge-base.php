<?php
/**
 * Knowledge Base module — registers the CPT and retrieves context for the chatbot.
 *
 * The post content is where you write the information/instructions the chatbot
 * should know. The post title becomes the heading in the AI context. For example,
 * create an article titled "Return Policy" with the policy text in the content body.
 *
 * @package Guzmandrade_AI_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the gaic_article custom post type and provides context retrieval.
 */
class GAIC_Knowledge_Base {

	/** CPT slug. */
	const CPT_SLUG = 'gaic_article';

	/**
	 * Settings instance.
	 *
	 * @var GAIC_Settings
	 */
	private GAIC_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param GAIC_Settings $settings Settings instance.
	 */
	public function __construct( GAIC_Settings $settings ) {
		$this->settings = $settings;
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the gaic_article custom post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::CPT_SLUG,
			array(
				'labels'        => array(
					'name'               => __( 'Knowledge Base', 'guzmandrade-ai-chatbot' ),
					'singular_name'      => __( 'Knowledge Base Article', 'guzmandrade-ai-chatbot' ),
					'add_new'            => __( 'Add New Article', 'guzmandrade-ai-chatbot' ),
					'add_new_item'       => __( 'Add New Knowledge Base Article', 'guzmandrade-ai-chatbot' ),
					'edit_item'          => __( 'Edit Article', 'guzmandrade-ai-chatbot' ),
					'new_item'           => __( 'New Article', 'guzmandrade-ai-chatbot' ),
					'view_item'          => __( 'View Article', 'guzmandrade-ai-chatbot' ),
					'search_items'       => __( 'Search Knowledge Base', 'guzmandrade-ai-chatbot' ),
					'not_found'          => __( 'No articles found.', 'guzmandrade-ai-chatbot' ),
					'not_found_in_trash' => __( 'No articles in trash.', 'guzmandrade-ai-chatbot' ),
					'all_items'          => __( 'All Articles', 'guzmandrade-ai-chatbot' ),
					'menu_name'          => __( 'Knowledge Base', 'guzmandrade-ai-chatbot' ),
				),
				'public'        => true,
				'has_archive'   => true,
				'menu_icon'     => 'dashicons-book',
				'menu_position' => 25,
				'show_in_rest'  => true,
				'supports'      => array( 'title', 'editor', 'author', 'excerpt', 'custom-fields' ),
				'rewrite'       => array( 'slug' => 'knowledge-base' ),
			)
		);
	}

	/**
	 * Retrieves relevant context from the knowledge base for a given user message.
	 *
	 * @param string $message The user's chat message.
	 * @return string Formatted context string for the system prompt.
	 */
	public function get_context( string $message ): string {
		$data = $this->get_context_with_sources( $message );
		return $data['context'];
	}

	/**
	 * Retrieves relevant context plus the numbered source list for citations.
	 *
	 * Uses a simple keyword-matching approach: extracts significant words from the
	 * user's message and queries posts that contain them. For small knowledge bases,
	 * all entries are included. Every entry is numbered ([1], [2], …) so the AI
	 * can cite it, and the matching source list (id, title, permalink) is returned
	 * alongside the context string.
	 *
	 * @param string $message The user's chat message.
	 * @return array{context:string, sources:array<int,array{id:int,title:string,url:string}>}
	 */
	public function get_context_with_sources( string $message ): array {
		$types       = (array) $this->settings->get( 'kb_post_types', array( 'gaic_article' ) );
		$max_length  = (int) $this->settings->get( 'max_context_length', 4000 );
		$context     = '';
		$sources     = array();
		$used_length = 0;
		$next_id     = 1;

		// Always include KB articles first.
		$articles = $this->query_relevant_posts( $message, array( 'gaic_article' ), 5 );
		if ( ! empty( $articles ) ) {
			$context .= "## Knowledge Base Articles\n\n";
			foreach ( $articles as $post ) {
				$formatted = $this->format_post_as_context( $post, $next_id );
				if ( $used_length + strlen( $formatted['entry'] ) > $max_length ) {
					break;
				}
				$context            .= $formatted['entry'] . "\n\n";
				$used_length        += strlen( $formatted['entry'] );
				$sources[ $next_id ] = $formatted['source'];
				++$next_id;
			}
		}

		// Then include other post types (excluding gaic_article which was already done).
		$other_types = array_diff( $types, array( 'gaic_article' ) );
		if ( ! empty( $other_types ) ) {
			$posts = $this->query_relevant_posts( $message, $other_types, 5 );
			if ( ! empty( $posts ) ) {
				$context .= "## Site Content\n\n";
				foreach ( $posts as $post ) {
					$formatted = $this->format_post_as_context( $post, $next_id );
					if ( $used_length + strlen( $formatted['entry'] ) > $max_length ) {
						break;
					}
					$context            .= $formatted['entry'] . "\n\n";
					$used_length        += strlen( $formatted['entry'] );
					$sources[ $next_id ] = $formatted['source'];
					++$next_id;
				}
			}
		}

		if ( empty( trim( $context ) ) ) {
			return array(
				'context' => '',
				'sources' => array(),
			);
		}

		return array(
			'context' => "Use the following context from the knowledge base to answer the user's question. If the context does not contain relevant information, say you don't have that information. Each source is numbered ([1], [2], …) — cite sources by number.\n\n" . $context,
			'sources' => $sources,
		);
	}

	/**
	 * Queries posts relevant to the user message.
	 *
	 * Uses WP_Query with a keyword search (s parameter) as well as a fallback
	 * to recent posts when no keywords match.
	 *
	 * @param string   $message  User message.
	 * @param string[] $types    Post types to search.
	 * @param int      $limit    Max posts to return.
	 * @return WP_Post[] Array of post objects.
	 */
	private function query_relevant_posts( string $message, array $types, int $limit ): array {
		// For KB articles, if there are fewer than the limit, return all of them.
		$total = ( new WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		) )->found_posts;

		if ( $total <= $limit ) {
			// Small KB — include everything.
			$query = new WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			return $query->posts;
		}

		// Use keyword search for larger KBs.
		$keywords = $this->extract_keywords( $message );
		$search   = implode( ' ', $keywords );

		$query = new WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				's'              => $search,
				'orderby'        => 'relevance',
			)
		);

		if ( empty( $query->posts ) ) {
			// Fallback to recent posts if keyword search returns nothing.
			$query = new WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		return $query->posts;
	}

	/**
	 * Extracts significant keywords from a user message for search.
	 *
	 * @param string $message User message.
	 * @return string[] Keywords.
	 */
	private function extract_keywords( string $message ): array {
		// Remove common stop words and punctuation.
		$stop_words = array( 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'what', 'when', 'where', 'why', 'how', 'who', 'which', 'this', 'that', 'these', 'those', 'to', 'of', 'in', 'on', 'at', 'by', 'for', 'with', 'about', 'as', 'into', 'like', 'through', 'after', 'over', 'between', 'out', 'against', 'during', 'without', 'before', 'under', 'around', 'among', 'and', 'or', 'but', 'not', 'no', 'so', 'than', 'too', 'very', 'just', 'also', 'me', 'my', 'your', 'please', 'help', 'need', 'want', 'tell', 'give' );

		$words    = preg_split( '/\s+/', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', ' ', $message ) ) );
		$keywords = array_filter(
			(array) $words,
			function ( $word ) use ( $stop_words ) {
				return ! empty( $word ) && strlen( $word ) > 2 && ! in_array( $word, $stop_words, true );
			}
		);

		return array_slice( array_unique( $keywords ), 0, 10 );
	}

	/**
	 * Formats a post as context text for the AI system prompt.
	 *
	 * The post title becomes the heading, prefixed with its citation number,
	 * and the post content is included as the knowledge the AI should reference.
	 * The source list is appended to $sources by reference.
	 *
	 * @param WP_Post $post Post object.
	 * @param int     $id   Citation number for this source.
	 * @return array{entry:string, source:array{id:int,title:string,url:string}}
	 */
	private function format_post_as_context( WP_Post $post, int $id ): array {
		$content = wp_strip_all_tags( apply_filters( 'gaic_article_content', $post->post_content ) );
		// Truncate individual entries to keep total context manageable.
		$max_entry = 2000;
		if ( strlen( $content ) > $max_entry ) {
			$content = substr( $content, 0, $max_entry ) . '…';
		}

		$url = get_permalink( $post );

		return array(
			'entry'  => sprintf(
				"### [%d] %s\nSource: %s\n%s",
				$id,
				$post->post_title,
				$url ? $url : '',
				$content
			),
			'source' => array(
				'id'    => $id,
				'title' => $post->post_title,
				'url'   => $url ? $url : '',
			),
		);
	}
}
