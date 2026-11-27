<?php
/**
 * Internal linking helper functions (extracted & simplified from original plugin).
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure Vertex AI helper functions are available.
if ( ! function_exists( 'ilsej_vertex_embed_query' ) ) {
	require_once ILSEJ_DIR . 'includes/google-vertex-ai.php';
}

// Entity-extraction providers (Claude, OpenAI) and the primary/fallback dispatcher.
require_once ILSEJ_DIR . 'includes/functions-extraction-providers.php';

/**
 * REDUCE ARTICLE MARKUP TO THE TAGS THE EXTRACTION PROMPT ACTUALLY READS
 *
 * The entity-extraction prompt treats <a> and <h1>-<h6> as load-bearing: phrases
 * must originate OUTSIDE those tags. Everything else in a rendered Gutenberg post
 * (block wrappers, figures, srcset lists, class/style/data attributes) is billed
 * as input tokens without informing a single extraction decision.
 *
 * So keep the structure the prompt reads, drop the rest. Attributes are stripped
 * wholesale — the prompt needs to know text sits inside an <a>, never where that
 * <a> points, and href URLs are among the most token-expensive noise in the body.
 *
 * Do NOT swap this for wp_strip_all_tags(): that erases the <a>/<h*> boundaries
 * and the model starts proposing anchors that duplicate existing links or sit
 * inside headings.
 *
 * @param string $content Rendered article HTML.
 * @return string Whitespace-collapsed HTML limited to structural tags.
 */
function ilsej_prepare_extraction_content( string $content ): string {
	// Elements whose inner text is never linkable prose — drop node and contents.
	$content = (string) preg_replace(
		'#<(script|style|svg|iframe|noscript)\b[^>]*>.*?</\1>#is',
		' ',
		$content
	);

	// Kept because the prompt reads them, or because dropping them silently
	// corrupts the text. Attributes are stripped from all of them.
	//
	// a and h1-h6 are what the prompt forbids sourcing phrases from. p, ul, ol
	// and li carry sentence and item boundaries. The table family matters more
	// than it looks: without cell tags a data grid collapses into run-on prose
	// ("Country USA 45% Canada 32%") that reads as a sentence. blockquote and
	// cite mark words the author is quoting rather than asserting — poor
	// anchor-source, and indistinguishable from the author's own prose once
	// unwrapped.
	//
	// strong and em are among the most frequent tags in editorial content and
	// cost a few hundred tokens per long post to keep. They earn it:
	// bold marks the definitional terms the prompt asks for, and a model reads
	// emphasis from context whether or not the prompt names it.
	//
	// Markdown was measured as an alternative — it carries emphasis for fewer
	// tokens than this allowlist — but league/html-to-markdown ships no table
	// converter, so every table flattens to run-on text. Upstream issue
	// ProgressPlanner/markdown-alternate#38, open since 2026-05-27 with no
	// response. Revisit if that lands.
	$allowed_html = [
		'a'          => [],
		'h1'         => [],
		'h2'         => [],
		'h3'         => [],
		'h4'         => [],
		'h5'         => [],
		'h6'         => [],
		'p'          => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'table'      => [],
		'thead'      => [],
		'tbody'      => [],
		'tr'         => [],
		'th'         => [],
		'td'         => [],
		'blockquote' => [],
		'cite'       => [],
		'strong'     => [],
		'em'         => [],
	];

	$content = wp_kses( $content, $allowed_html );

	return trim( (string) preg_replace( '/\s+/', ' ', $content ) );
}

/**
 * ENTITY-EXTRACTION INSTRUCTIONS SHARED BY EVERY PROVIDER
 *
 * The prompt has exactly one home. Claude receives it as the system prompt and
 * gets the output shape from a JSON schema (structured outputs); OpenAI receives
 * it followed by ilsej_extraction_output_format(). If each provider kept its own
 * copy, the two would drift and the fallback would quietly stop matching the
 * tuned primary.
 *
 * @return string Instructions in Markdown.
 */
function ilsej_extraction_instructions(): string {
	return <<<'PROMPT'
# ROLE
You are an expert SEO specialist and content strategist specializing in internal link architecture.

# TASK
Analyze the provided article text and extract a curated set of high-value phrases suitable for internal linking anchor text.

# SELECTION CRITERIA
Extract phrases that satisfy all of the following:
1. Represent key topics, significant entities, or core concepts discussed in the article
2. Indicate strong user intent, anticipate reader questions, or suggest logical follow-up actions
3. Possess high semantic value and accurately reflect the surrounding context
4. Function as clear, descriptive, and natural-sounding anchor text for internal links
5. Consist of meaningful multi-word phrases (avoid generic single words like "thing", "aspect", "process", or isolated brand names without context)
6. Include definitional terms: key concepts being defined or introduced, typically appearing as subjects before linking verbs such as "is", "are", "refers to", "means", "is defined as" (Example: In "Meta-analysis is a statistical method", extract "Meta-analysis")

# ANCHOR-TARGET RELEVANCE
Each phrase becomes the label of a link to another page, so it must name a specific topic that a separate article could cover.
- "proof of stake" is a good anchor because a page titled "Proof of Stake" would cover that topic
- "regulatory compliance" is only good if you expect a page specifically about regulatory compliance
- Generic contextual phrases ("this approach", "recent data", "market conditions") are not good anchors: they describe the current article, not a potential link target
- For each phrase, ask: "If I searched for this phrase, would I find a dedicated page about it?"

# EXTRACTION DENSITY
Extract a minimum of 1-2 phrase suggestions per 150 words of article content, distributed evenly throughout the text. Avoid clustering extractions in one section or selecting semantically similar phrases.

# SOURCE EXCLUSION
Take the `phrase` value only from text outside these HTML tags: <a>, <h1>, <h2>, <h3>, <h4>, <h5>, <h6>. Text inside them is already a link or a heading, so it cannot become a new anchor.
- The `context` field may include text from these tags when it naturally surrounds a valid phrase
- Valid phrases sit in paragraph body text, list items, table cells, or other non-heading, non-link elements

# FIELD REQUIREMENTS

## `phrase`
- Copy the exact character sequence from the source text
- Preserve original casing (do not convert uppercase or lowercase)
- Preserve all punctuation, apostrophes, and parentheses exactly as they appear
- Preserve plural and singular forms exactly (do not add or remove "s")
- Do not expand abbreviations or convert full terms to abbreviations
- Extract complete phrases only (extract "product feeds", not "product feed", when the source says "product feeds")

## `context`
- A word sequence from the article that contains the exact `phrase`
- At most 9 words in total, the phrase included
- The phrase, 1-4 words long, appears verbatim inside the context

## `expected_topic`
- A 2-5 word description of what a linked page about this phrase would cover
- Describes the target page's topic, not the current article's topic
- Used after matching to check that the suggested link target relates to this topic
- Example: phrase "proof of stake" → expected_topic "proof of stake consensus"
- Example: phrase "DeFi protocols" → expected_topic "decentralized finance"

# CHECK BEFORE ANSWERING
1. Every `phrase` comes from text outside <a>, <h1>, <h2>, <h3>, <h4>, <h5>, <h6> tags
2. Each `context` contains the exact `phrase` and has at most 9 words
3. Phrases are copied exactly: no case changes, no plural changes, no abbreviation changes
4. Each `expected_topic` describes the target page's topic, not the current article's topic
PROMPT;
}

/**
 * OUTPUT-FORMAT SECTION FOR PROVIDERS WITHOUT A SCHEMA
 *
 * The OpenAI request asks for JSON in words, so it needs the shape and an
 * example. Claude receives the same shape as a JSON schema instead.
 *
 * @return string Output-format instructions.
 */
function ilsej_extraction_output_format(): string {
	return <<<'PROMPT'
# OUTPUT FORMAT
Respond with a single valid JSON object and nothing else: no preamble, no explanation, no markdown code fences.
{
  "entities": [
    { "phrase": "exact phrase from text", "context": "max 9 words containing phrase", "expected_topic": "2-5 word target page topic" },
    { "phrase": "another key concept", "context": "max 9 words containing concept", "expected_topic": "2-5 word target topic" }
  ]
}

# EXAMPLE OUTPUT
{
  "entities": [
    { "phrase": "conversion rate optimization", "context": "strategies for conversion rate optimization in e-commerce", "expected_topic": "CRO strategies" },
    { "phrase": "E-E-A-T guidelines", "context": "comply with Google's E-E-A-T guidelines for content", "expected_topic": "Google quality guidelines" },
    { "phrase": "schema markup", "context": "implementing schema markup improves search visibility", "expected_topic": "structured data markup" }
  ]
}
PROMPT;
}

/**
 * BUILD THE OPENAI REQUEST BODY FOR ENTITY EXTRACTION
 *
 * @param string $content Article content (HTML or plain text).
 * @return array<string, mixed> Request body for the chat completions endpoint.
 */
function ilsej_build_extraction_request( string $content ): array {
	// Instructions lead the message so the static prefix stays prompt-cacheable;
	// only the per-article body is billed at full rate.
	return [
		'model'                 => 'gpt-5.2',
		'reasoning_effort'      => 'low',
		// Ceiling covers low-effort reasoning plus the entity JSON object.
		// Without it, reasoning tokens bill uncapped on every call.
		'max_completion_tokens' => 5000,
		'messages'              => [
			[
				'role'    => 'user',
				'content' => ilsej_extraction_instructions() . "\n\n" . ilsej_extraction_output_format() . "\n\narticle text: " . ilsej_prepare_extraction_content( $content ),
			],
		],
	];
}

/**
 * PARSE AN EXTRACTION RESPONSE INTO ENTITIES
 *
 * The single parser for every provider, so the shape checks and the banned-words
 * filter apply whichever provider answered. A provider path that parsed results
 * itself would silently skip the filter.
 *
 * Returns null for a structural failure, which makes the dispatcher try the
 * fallback provider: text that is not JSON, no `entities` list, or a non-empty
 * list in which no row has a usable phrase and context. A list that is empty,
 * or that only the banned-words filter emptied, is a valid answer and is
 * returned as [] (no fallback).
 *
 * @param string $content_json Raw message content from the model.
 * @return list<array<string, string>>|null Entities, or null when the payload
 *                                          is not a usable entity list.
 */
function ilsej_parse_extraction_response( string $content_json ) {
	// The model occasionally wraps JSON in a markdown fence despite instructions.
	$content_json = str_replace( [ '```json', '```' ], '', $content_json );

	// Check the JSON types before decoding to arrays: associative decoding turns
	// both {} and [] into an empty PHP array, so {"entities":{}} would pass as a
	// valid empty answer and block the fallback.
	$shape = json_decode( $content_json );
	if ( ! $shape instanceof stdClass || ! isset( $shape->entities ) || ! is_array( $shape->entities ) ) {
		return null;
	}

	$resp = json_decode( $content_json, true );
	if ( ! is_array( $resp ) || ! is_array( $resp['entities'] ?? null ) ) {
		return null;
	}

	$raw_count = count( $resp['entities'] );

	// Shape validation runs ALWAYS. It used to live inside the banned-words
	// branch below, which made it dead code: banned_words has no default in
	// ILSEJ_Core::get_settings() and no admin field, so on a default install the
	// branch never executed and malformed entities reached the caller. There
	// they hit $entity['phrase'] and array_column() unguarded, producing
	// "Undefined array key" warnings and — worse — silently misaligned
	// embeddings. Downstream code is entitled to assume phrase and context
	// exist as strings, so guarantee it here.
	$entities = [];
	foreach ( $resp['entities'] as $entity ) {
		if ( ! is_array( $entity ) || ! isset( $entity['phrase'], $entity['context'] ) ) {
			continue;
		}
		if ( ! is_string( $entity['phrase'] ) || ! is_string( $entity['context'] ) ) {
			continue;
		}
		if ( '' === trim( $entity['phrase'] ) || '' === trim( $entity['context'] ) ) {
			continue;
		}
		$clean = [
			'phrase'  => $entity['phrase'],
			'context' => $entity['context'],
		];
		if ( isset( $entity['expected_topic'] ) && is_string( $entity['expected_topic'] ) ) {
			$clean['expected_topic'] = $entity['expected_topic'];
		}
		$entities[] = $clean;
	}

	if ( $raw_count > 0 && [] === $entities ) {
		return null;
	}

	$settings     = ILSEJ_Core::get_settings();
	$banned_words = isset( $settings['banned_words'] ) && is_array( $settings['banned_words'] ) ? $settings['banned_words'] : [];

	if ( ! empty( $banned_words ) ) {
		$entities = array_values(
			array_filter(
				$entities,
				static function ( $entity ) use ( $banned_words ) {
					foreach ( $banned_words as $banned ) {
						if ( '' !== (string) $banned && false !== stripos( $entity['phrase'], (string) $banned ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	return $entities;
}




/**
 * Default hourly budget for logged-out vector searches, per site.
 *
 * A front-end search that calls ilsej_get_embeddings() for visitors should cache
 * each term and wait for a pause in typing, so normal reading uses few of these.
 * 600 leaves room for one busy client and for a traffic spike. The worst case for
 * a flood is 600 paid searches an hour, however many addresses it rotates through.
 * Sites can change the value with the ilsej_public_search_hourly_budget filter.
 */
const ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET = 600;

/**
 * GENERATE VECTOR EMBEDDING FOR TEXT
 *
 * This function converts text into a 768-dimensional vector using Google
 * Vertex AI's text-embedding-005 model. The vector represents the semantic
 * meaning of the text in a numerical format that can be compared with other
 * vectors to find similar content.
 *
 * HOW EMBEDDINGS WORK:
 * - Text is sent to Google Vertex AI API
 * - AI model analyzes semantic meaning, context, and relationships
 * - Returns array of 768 numbers (vector dimensions)
 * - Similar text produces similar vectors (measured by cosine similarity)
 *
 * WHY 768 DIMENSIONS:
 * - Google Vertex AI text-embedding-005 model uses 768 dimensions
 * - More dimensions = more nuanced semantic representation
 * - Balance between accuracy and computational efficiency
 *
 * USAGE:
 * - Converts user-selected phrases to vectors (for search)
 * - Converts AI-extracted entities to vectors (for search)
 * - Used before querying Pinecone to find similar articles
 *
 * ERROR HANDLING:
 * - Returns false on error (prevents fatal errors)
 * - Logs errors to WordPress error log
 * - Allows graceful degradation if API fails
 * - Also returns false, with no API call, when a logged-out caller finds the
 *   hourly budget spent (see ilsej_public_search_budget_allows())
 *
 * @param string $text          Text to convert to vector embedding.
 * @param bool   $force_refresh Whether to force refresh the embedding cache.
 * @return array<int, float>|false 768-dimensional vector array, or false on error.
 *
 * @throws Exception If the Vertex AI API call fails.
 */
function ilsej_get_embeddings( string $text, bool $force_refresh = false ) {
	if ( ! ilsej_public_search_budget_allows() ) {
		return false;
	}

	$settings = ILSEJ_Core::get_settings();

	// Get JSON key content directly from settings (no file needed).
	$json_key = isset( $settings['gcp_json_key'] ) ? $settings['gcp_json_key'] : '';

	try {
		$project_id = 'project';
		// Extract project ID from JSON key if available.
		if ( ! empty( $json_key ) ) {
			$json_arr = json_decode( $json_key, true );
			if ( null !== $json_arr && isset( $json_arr['project_id'] ) ) {
				$project_id = $json_arr['project_id'];
			}
		}
		return ilsej_vertex_embed_query( ilsej_normalize_text_for_embedding( $text ), $project_id, $json_key, 'us-central1', $force_refresh );
	} catch ( Exception $e ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'ILSEJ Vertex AI error: ' . $e->getMessage() );
		return false;
	}
}

/**
 * Spend one call from the hourly budget for logged-out vector searches.
 *
 * Every vector search pays twice, once for the Vertex AI embedding and once for
 * the Pinecone query. A theme's instant search can let anonymous readers reach
 * both, and a per-IP limit does not stop a client that rotates addresses. This
 * budget is the ceiling across all clients. The gate sits in
 * ilsej_get_embeddings() because every caller of ilsej_pinecone_query() gets its
 * vector there, so one count covers both charges.
 *
 * Logged-in users are not counted: the editor's link suggestions need a nonce and
 * a capability, and a flood of anonymous searches must not switch them off.
 *
 * The window is the clock hour in UTC. A sliding TTL would never expire under
 * steady traffic, so normal searches would add up until they hit the budget. The
 * read and write are not atomic, so concurrent requests can overrun the budget by
 * a few calls, which is fine for a cost ceiling.
 *
 * Filter `ilsej_public_search_hourly_budget` changes the limit per site. Zero
 * stops logged-out vector searches.
 *
 * @return bool True when the caller may make the paid calls.
 */
function ilsej_public_search_budget_allows(): bool {
	if ( is_user_logged_in() ) {
		return true;
	}

	$budget = (int) apply_filters( 'ilsej_public_search_hourly_budget', ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET );
	$key    = 'ilsej_public_search_' . gmdate( 'YmdH' );
	$used   = (int) get_transient( $key );

	if ( $used < $budget ) {
		set_transient( $key, $used + 1, 2 * HOUR_IN_SECONDS );
		return true;
	}

	// Log once per hour. A flood must not write a line per refused request.
	if ( $used === $budget ) {
		set_transient( $key, $used + 1, 2 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "ILSEJ public search budget of {$budget} calls reached; refusing logged-out vector searches until the next hour." );
	}

	return false;
}

// Note: ilsej_normalize_text_for_embedding() is now defined in google-vertex-ai.php.
// to ensure it's available before embedding functions are called.

/**
 * QUERY PINECONE VECTOR DATABASE FOR SIMILAR ARTICLES
 *
 * This is the core search function that finds articles semantically similar
 * to a given phrase. It uses vector similarity search to find content that
 * is related in meaning, even if it doesn't contain the exact same words.
 *
 * HOW VECTOR SEARCH WORKS:
 * 1. Input phrase is converted to vector (via ilsej_get_embeddings)
 * 2. Pinecone compares input vector with all stored article vectors
 * 3. Calculates cosine similarity (measures angle between vectors)
 * 4. Returns top K most similar articles (highest similarity scores)
 *
 * WHY VECTOR SEARCH IS POWERFUL:
 * - Finds semantically similar content, not just keyword matches
 * - Understands context and meaning
 * - Works across languages and synonyms
 * - More accurate than traditional keyword search
 *
 * FILTERING:
 * - Positive filters: Only return articles matching criteria (category, post type, year)
 * - Negative filters: Exclude articles (e.g., current post being edited)
 * - Filters are applied to metadata stored with each vector
 *
 * METADATA FILTERING:
 * - category: Filter by WordPress category slug
 * - post_type: Filter by WordPress post type
 * - publish_year: Filter by publication year (for freshness)
 *
 * NEGATIVE FILTERING:
 * - ID: Exclude specific post IDs (prevents self-linking)
 * - Applied after query to filter out unwanted results
 *
 * RESPONSE FORMAT:
 * Returns array of matches, each containing:
 * - id: Pinecone vector ID (WordPress post ID)
 * - score: Similarity score (0-1, higher = more similar)
 * - metadata: Post title, URL, category, publish date, etc.
 *
 * @param array<int, float>    $vector           768-dimensional vector to search for.
 * @param int                  $top_k            Number of results to return (default: 5).
 * @param array<string, mixed> $filters          Positive filters (category, post_type, publish_year).
 * @param array<string, mixed> $negative_filters Negative filters (ID to exclude).
 * @return array<string, mixed> Array of similar articles with metadata.
 */
function ilsej_pinecone_query( $vector, int $top_k = 5, $filters = [], $negative_filters = [] ) {
	$start_time = microtime( true );

	$settings = ILSEJ_Core::get_settings();
	$host     = $settings['pinecone_host'] ?? '';
	$key      = $settings['pinecone_api_key'] ?? '';

	if ( empty( $host ) || empty( $key ) ) {
		return [];
	}

	$endpoint = rtrim( $host, '/' ) . '/query';

	$args = [
		'vector'          => ( $vector ),
		'top_k'           => $top_k,
		'includeMetadata' => true,
	];

	// Apply positive filters.
	if ( ! empty( $filters ) ) {
		foreach ( $filters as $key_f => $filter ) {
			if ( ! empty( $filter ) ) {
				if ( is_array( $filter ) ) {
					// In case publish_year field is type of int in the pinecone uncomment this.
					// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
					// If ( $key_n === 'ID' ) { $filter_n = array_map( 'intval', $filter_n ); }.
					$args['filter'][ $key_f ] = [ '$in' => $filter ];
				} else {
					$args['filter'][ $key_f ] = $filter;
				}
			}
		}
	}
	// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
	// print_r($filters); die;
	// Apply negative filters in case you want to modify plugin code the way to exclude.
	if ( ! empty( $negative_filters ) ) {
		foreach ( $negative_filters as $key_n => $filter_n ) {
			if ( is_array( $filter_n ) ) {
				// In case publish_year field is type of int in the pinecone uncomment this.
				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// If ( $key_n === 'ID' ) { $filter_n = array_map( 'intval', $filter_n ); }.
				$args['filter'][ $key_n ] = [ '$nin' => $filter_n ];
			} else {
				$args['filter'][ $key_n ] = [ '$ne' => $filter_n ];
			}
		}
	}
	$response = wp_remote_post(
		$endpoint,
		[
			'headers' => [
				'Content-Type' => 'application/json',
				'Api-Key'      => $key,
			],
			'body'    => (string) wp_json_encode( $args ),
			'timeout' => 30,
		]
	);
	if ( is_wp_error( $response ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'ILSEJ Pinecone query error: ' . $response->get_error_message() );
		return [];
	}

	$data    = json_decode( wp_remote_retrieve_body( $response ), true );
	$matches = $data['matches'] ?? [];

	// Filter out matches by Pinecone native ID if negative_filters contains 'ID'.
	if ( ! empty( $negative_filters ) && isset( $negative_filters['ID'] ) ) {
		$exclude_ids = is_array( $negative_filters['ID'] ) ? $negative_filters['ID'] : [ $negative_filters['ID'] ];
		// Convert to strings for comparison (Pinecone IDs are strings).
		$exclude_ids = array_map( 'strval', $exclude_ids );
		$matches     = array_filter(
			$matches,
			// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound
			function ( $match ) use ( $exclude_ids ) {
				// Match ID is the Pinecone vector ID (which is the post ID).
				$match_id = isset( $match['id'] ) ? (string) $match['id'] : '';
				return ! in_array( $match_id, $exclude_ids, true );
			}
		);
		// Re-index array after filtering.
		$matches = array_values( $matches );
	}

	if ( isset( $GLOBALS['ilsej_perf_data'] ) ) {
		$GLOBALS['ilsej_perf_data']['pinecone_time'] += ( microtime( true ) - $start_time );
		++$GLOBALS['ilsej_perf_data']['pinecone_calls'];
	}

	return $matches;
}

/**
 * QUERY PINECONE BY VECTOR ID (NO RE-EMBEDDING NEEDED)
 *
 * Pinecone supports querying by a stored vector's ID instead of providing
 * a raw vector. This is useful for finding posts similar to an already-indexed
 * post without calling Vertex AI for a new embedding.
 *
 * @param string               $vector_id        The Pinecone vector ID (WordPress post ID as string).
 * @param int                  $top_k            Number of results to return (default: 15).
 * @param array<string, mixed> $filters          Positive filters (category, post_type, publish_year).
 * @param array<string, mixed> $negative_filters Negative filters (ID to exclude).
 * @return array<string, mixed> Array of similar articles with metadata.
 */
function ilsej_pinecone_query_by_id( string $vector_id, int $top_k = 15, array $filters = [], array $negative_filters = [] ): array {
	$start_time = microtime( true );

	$settings = ILSEJ_Core::get_settings();
	$host     = $settings['pinecone_host'] ?? '';
	$key      = $settings['pinecone_api_key'] ?? '';

	if ( empty( $host ) || empty( $key ) ) {
		return [];
	}

	$endpoint = rtrim( $host, '/' ) . '/query';

	$args = [
		'id'              => $vector_id,
		'top_k'           => $top_k,
		'includeMetadata' => true,
	];

	// Apply positive filters.
	if ( ! empty( $filters ) ) {
		foreach ( $filters as $key_f => $filter ) {
			if ( ! empty( $filter ) ) {
				if ( is_array( $filter ) ) {
					$args['filter'][ $key_f ] = [ '$in' => $filter ];
				} else {
					$args['filter'][ $key_f ] = $filter;
				}
			}
		}
	}

	// Apply negative filters.
	if ( ! empty( $negative_filters ) ) {
		foreach ( $negative_filters as $key_n => $filter_n ) {
			if ( is_array( $filter_n ) ) {
				$args['filter'][ $key_n ] = [ '$nin' => $filter_n ];
			} else {
				$args['filter'][ $key_n ] = [ '$ne' => $filter_n ];
			}
		}
	}

	$response = wp_remote_post(
		$endpoint,
		[
			'headers' => [
				'Content-Type' => 'application/json',
				'Api-Key'      => $key,
			],
			'body'    => (string) wp_json_encode( $args ),
			'timeout' => 30,
		]
	);

	if ( is_wp_error( $response ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'ILSEJ Pinecone query-by-id error: ' . $response->get_error_message() );
		return [];
	}

	$data    = json_decode( wp_remote_retrieve_body( $response ), true );
	$matches = $data['matches'] ?? [];

	// Always exclude the source vector (Pinecone returns it as ~1.0 match).
	$exclude_ids = [ $vector_id ];

	// Also exclude any IDs from negative_filters.
	if ( ! empty( $negative_filters ) && isset( $negative_filters['ID'] ) ) {
		$extra       = is_array( $negative_filters['ID'] ) ? $negative_filters['ID'] : [ $negative_filters['ID'] ];
		$exclude_ids = array_merge( $exclude_ids, array_map( 'strval', $extra ) );
	}

	$exclude_ids = array_unique( $exclude_ids );
	$matches     = array_filter(
		$matches,
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound
		function ( $match ) use ( $exclude_ids ) {
			$match_id = isset( $match['id'] ) ? (string) $match['id'] : '';
			return ! in_array( $match_id, $exclude_ids, true );
		}
	);
	$matches     = array_values( $matches );

	if ( isset( $GLOBALS['ilsej_perf_data'] ) ) {
		$GLOBALS['ilsej_perf_data']['pinecone_time'] += ( microtime( true ) - $start_time );
		++$GLOBALS['ilsej_perf_data']['pinecone_calls'];
	}

	return $matches; // @phpstan-ignore return.type
}

/**
 * GET SEMANTICALLY RELATED POSTS VIA PINECONE
 *
 * Public API function for themes/plugins. Returns posts most similar to the
 * given post based on Pinecone vector similarity. Results are cached for 7 days.
 *
 * @param int    $post_id   WordPress post ID to find related posts for.
 * @param int    $count     Maximum number of results (default: 14).
 * @param string $post_type Filter results by post type (default: 'post').
 * @param float  $min_score Minimum similarity score threshold (default: 0.60).
 * @return array<int, array{id: int, score: float, title: string, url: string}> Related posts or empty array.
 */
function ilsej_get_related_posts( int $post_id, int $count = 14, string $post_type = 'post', float $min_score = 0.60 ): array {
	// The cache holds the FULL candidate set; $count and $min_score are applied
	// after reading it. Caching the already-truncated list under a key that did
	// not include them let ilsej_get_related_posts( $id, 4 ) poison a later
	// ilsej_get_related_posts( $id, 14 ) down to four results. Putting them in
	// the key would have fixed that but broken
	// ILSEJ_Indexer::clear_related_transients(), which deletes by exact name and
	// cannot enumerate keys under an external object cache.
	$transient_key = 'ilsej_related_' . $post_id . '_' . $post_type;
	$cached        = get_transient( $transient_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? ilsej_filter_related( $cached, $count, $min_score ) : [];
	}

	// Fixed top_k so one cached set serves every $count a caller may ask for.
	$top_k   = 20;
	$filters = [ 'post_type' => $post_type ];
	$matches = ilsej_pinecone_query_by_id( (string) $post_id, $top_k, $filters );

	if ( empty( $matches ) ) {
		// ilsej_pinecone_query_by_id() returns [] for BOTH "no matches" and any
		// failure (is_wp_error, non-2xx, unindexed post) — the two are
		// indistinguishable here. Caching that for a week meant one Pinecone
		// blip killed related posts on a URL for seven days, and
		// clear_related_transients() only fires on re-index, so poisoning that
		// happened after indexing never self-healed. A short TTL still prevents
		// hammering on genuinely-empty posts (rare) while letting a transient
		// failure recover in minutes instead of days.
		set_transient( $transient_key, [], 15 * MINUTE_IN_SECONDS );
		return [];
	}

	$candidates = [];
	foreach ( $matches as $match ) {
		$candidates[] = [
			'id'    => (int) ( $match['id'] ?? 0 ),
			'score' => (float) ( $match['score'] ?? 0 ),
			'title' => $match['metadata']['title'] ?? '',
			'url'   => $match['metadata']['url'] ?? '',
		];
	}

	set_transient( $transient_key, $candidates, WEEK_IN_SECONDS );

	return ilsej_filter_related( $candidates, $count, $min_score );
}

/**
 * APPLY SCORE THRESHOLD AND COUNT LIMIT TO A CACHED RELATED-POSTS SET
 *
 * Kept separate from the cache write so the transient stores the full candidate
 * set. Callers asking for different $count / $min_score then share one cache
 * entry instead of overwriting each other's narrower results.
 *
 * @param array<int, array<string, mixed>> $candidates Cached candidate set.
 * @param int                              $count      Maximum results to return.
 * @param float                            $min_score  Minimum similarity score.
 * @return list<array{id: int, score: float, title: string, url: string}> Filtered, truncated results.
 */
function ilsej_filter_related( array $candidates, int $count, float $min_score ): array {
	$out = [];
	foreach ( $candidates as $c ) {
		$score = (float) ( $c['score'] ?? 0 );
		if ( $score < $min_score ) {
			continue;
		}
		// Re-assert the shape: the cache may hold entries written by an older
		// version of this function.
		$out[] = [
			'id'    => (int) ( $c['id'] ?? 0 ),
			'score' => $score,
			'title' => (string) ( $c['title'] ?? '' ),
			'url'   => (string) ( $c['url'] ?? '' ),
		];
		if ( count( $out ) >= $count ) {
			break;
		}
	}
	return $out;
}

/**
 * PERFORM MULTIPLE PINECONE QUERIES IN PARALLEL
 *
 * Uses WordPress's Requests library (via wp_remote_post and internal parallel handling if possible,
 * or specifically Requests::request_multiple) to execute multiple queries simultaneously.
 *
 * @param array<int, array<int, float>> $vectors          Array of 768-dimensional vectors to search for.
 * @param int                           $top_k            Number of results to return per query (default: 1).
 * @param array<string, mixed>          $filters          Positive filters (category, post_type, publish_year).
 * @param array<string, mixed>          $negative_filters Negative filters (ID to exclude).
 * @return array<int, list<array<string, mixed>>> Array of result sets, one per input vector.
 */
function ilsej_pinecone_query_parallel( array $vectors, int $top_k = 1, array $filters = [], array $negative_filters = [] ): array {
	$start_time = microtime( true );
	$settings   = ILSEJ_Core::get_settings();
	$host       = $settings['pinecone_host'] ?? '';
	$key        = $settings['pinecone_api_key'] ?? '';

	if ( empty( $host ) || empty( $key ) || empty( $vectors ) ) {
		return array_fill( 0, count( $vectors ), [] );
	}

	$endpoint = rtrim( $host, '/' ) . '/query';
	$requests = [];

	foreach ( $vectors as $index => $vector ) {
		$args = [
			'vector'          => $vector,
			'top_k'           => $top_k,
			'includeMetadata' => true,
		];

		if ( ! empty( $filters ) ) {
			foreach ( $filters as $kf => $vf ) {
				if ( ! empty( $vf ) ) {
					$args['filter'][ $kf ] = is_array( $vf ) ? [ '$in' => $vf ] : $vf;
				}
			}
		}

		if ( ! empty( $negative_filters ) ) {
			foreach ( $negative_filters as $kn => $vn ) {
				$args['filter'][ $kn ] = is_array( $vn ) ? [ '$nin' => $vn ] : [ '$ne' => $vn ];
			}
		}

		$requests[] = [
			'url'     => $endpoint,
			'type'    => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
				'Api-Key'      => $key,
			],
			'data'    => (string) wp_json_encode( $args ),
			'options' => [ 'timeout' => 30 ],
		];
	}

	// Use WP's built-in Requests::request_multiple for parallel execution.
	// Note: It returns an array indexed by the original keys.
	if ( class_exists( 'WpOrg\Requests\Requests' ) ) {
		$responses = \WpOrg\Requests\Requests::request_multiple( $requests );
	} else {
		$responses = \Requests::request_multiple( $requests );
	}

	$all_results = [];
	$exclude_ids = $negative_filters['ID'] ?? null;
	if ( $exclude_ids ) {
		$exclude_ids = is_array( $exclude_ids ) ? array_map( 'strval', $exclude_ids ) : [ (string) $exclude_ids ];
	}

	// CRITICAL: We iterate through $requests keys to ensure matching order.
	// foreach on $responses might iterate in completion order, which causes a "shift".
	foreach ( $requests as $index => $req ) {
		$response = $responses[ $index ] ?? null;

		if ( ! $response || is_wp_error( $response ) || ( intval( $response->status_code ?? 0 ) !== 200 ) ) {
			$all_results[ $index ] = [];
			continue;
		}

		$data    = json_decode( $response->body, true );
		$matches = $data['matches'] ?? [];

		if ( $exclude_ids ) {
			$matches = array_filter(
				$matches,
				// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound
				function ( $match ) use ( $exclude_ids ) {
					$match_id = isset( $match['id'] ) ? (string) $match['id'] : '';
					return ! in_array( $match_id, $exclude_ids, true );
				}
			);
			$matches = array_values( $matches );
		}
		$all_results[ $index ] = $matches;
	}

	if ( isset( $GLOBALS['ilsej_perf_data'] ) ) {
		$GLOBALS['ilsej_perf_data']['pinecone_time']  += ( microtime( true ) - $start_time );
		$GLOBALS['ilsej_perf_data']['pinecone_calls'] += count( $vectors );
	}

	return $all_results;
}

/**
 * Generic single-word anchor blocklist (geo / time / category / process noun).
 *
 * Single-word anchors that match this list trigger the dual specificity gate
 * in ilsej_is_anchor_relevant(): expected_topic must share >= 2 meaningful
 * words with the target AND source_context must share >= 1 meaningful word.
 *
 * @return list<string> Lowercase generic anchor terms.
 */
function ilsej_get_generic_anchor_blocklist(): array {
	$default = [
		// Geo.
		'us',
		'usa',
		'uk',
		'eu',
		'america',
		'europe',
		'asia',
		'africa',
		'australia',
		'canada',
		'brazil',
		'mexico',
		'germany',
		'france',
		'india',
		'china',
		'japan',
		'latin',
		'global',
		'worldwide',
		// Time.
		'2023',
		'2024',
		'2025',
		'2026',
		'2027',
		'q1',
		'q2',
		'q3',
		'q4',
		'january',
		'february',
		'march',
		'april',
		'may',
		'june',
		'july',
		'august',
		'september',
		'october',
		'november',
		'december',
		'month',
		'year',
		'quarter',
		// Category / process noun (high-frequency, low-specificity).
		'technology',
		'business',
		'software',
		'online',
		'digital',
		'stats',
		'data',
		'news',
		'article',
		'report',
		'study',
		'analysis',
		'research',
		'growth',
		'trend',
		'survey',
		'market',
		'industry',
		'price',
		'value',
		'volume',
		'revenue',
		'profit',
		'loss',
	];
	$list    = apply_filters( 'ilsej_generic_anchor_blocklist', $default );
	if ( ! is_array( $list ) ) {
		$list = $default;
	}
	$out = [];
	foreach ( $list as $word ) {
		if ( is_string( $word ) && '' !== $word ) {
			$out[] = strtolower( $word );
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Stop words used by the anchor specificity tokenizer.
 *
 * @return list<string>
 */
function ilsej_get_anchor_stop_words(): array {
	$default = [
		'the',
		'a',
		'an',
		'of',
		'in',
		'for',
		'and',
		'or',
		'to',
		'is',
		'are',
		'was',
		'were',
		'on',
		'at',
		'by',
		'with',
		'from',
		'as',
		'vs',
		'how',
		'what',
		'why',
		'key',
		'top',
		'best',
		'new',
		'you',
		'your',
		'its',
		'this',
		'that',
		'about',
		'into',
	];
	$list    = apply_filters( 'ilsej_anchor_stop_words', $default );
	if ( ! is_array( $list ) ) {
		$list = $default;
	}
	$out = [];
	foreach ( $list as $word ) {
		if ( is_string( $word ) && '' !== $word ) {
			$out[] = strtolower( $word );
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Tokenize text into meaningful words.
 *
 * Lowercases, splits on whitespace + common punctuation, drops stop words and
 * tokens of length <= 2.
 *
 * @param string $text Source text.
 * @return list<string> Token list.
 */
function ilsej_anchor_tokenize( string $text ): array {
	if ( '' === $text ) {
		return [];
	}
	$parts = preg_split( '#[\s\-_/.,;:!?\[\]()]+#u', strtolower( $text ) );
	if ( ! is_array( $parts ) ) {
		return [];
	}
	$stop_words = ilsej_get_anchor_stop_words();
	$out        = [];
	foreach ( $parts as $part ) {
		if ( '' === $part || strlen( $part ) <= 2 ) {
			continue;
		}
		if ( in_array( $part, $stop_words, true ) ) {
			continue;
		}
		$out[] = $part;
	}
	return $out;
}

/**
 * Extract the slug part of a URL (last non-empty path segment).
 *
 * @param string $url URL or path.
 * @return string Slug or empty string.
 */
function ilsej_anchor_slug_from_url( string $url ): string {
	if ( '' === $url ) {
		return '';
	}
	$bare  = rtrim( $url, '/' );
	$parts = explode( '/', $bare );
	return (string) end( $parts );
}

/**
 * Light stemming: collapse common plural suffixes.
 *
 * Handles 'derivatives' -> 'derivativ',
 * 'currencies' -> 'currency', 'platforms' -> 'platform'. Not full Porter stemming.
 *
 * @param string $word Word to stem.
 * @return string Stemmed form.
 */
function ilsej_anchor_stem( string $word ): string {
	if ( '' === $word ) {
		return $word;
	}
	$len = strlen( $word );
	if ( $len > 5 && 'ies' === substr( $word, -3 ) ) {
		return substr( $word, 0, -3 ) . 'y';
	}
	if ( $len > 4 && ( 'es' === substr( $word, -2 ) || 's' === substr( $word, -1 ) ) ) {
		if ( 'es' === substr( $word, -2 ) ) {
			return substr( $word, 0, -2 );
		}
		return substr( $word, 0, -1 );
	}
	return $word;
}

/**
 * Length of the shared character prefix of two strings.
 *
 * @param string $a First string.
 * @param string $b Second string.
 * @return int Shared prefix length.
 */
function ilsej_anchor_shared_prefix_len( string $a, string $b ): int {
	$n = 0;
	$m = min( strlen( $a ), strlen( $b ) );
	while ( $n < $m && $a[ $n ] === $b[ $n ] ) {
		++$n;
	}
	return $n;
}

/**
 * Count meaningful overlap between two token lists.
 *
 * Two words "match" if either (a) equal after stemming, or (b) share a >=
 * prefix_min character common prefix. Each token can match only one on the
 * other side, so duplicates do not inflate the count.
 *
 * @param array<int, string> $words_a    First token list.
 * @param array<int, string> $words_b    Second token list.
 * @param int                $prefix_min Minimum prefix length for prefix match (default 5).
 * @return int Overlap count.
 */
function ilsej_anchor_word_overlap( array $words_a, array $words_b, int $prefix_min = 5 ): int {
	$a_norm = [];
	foreach ( $words_a as $w ) {
		if ( '' === $w ) {
			continue;
		}
		$a_norm[ ilsej_anchor_stem( $w ) ] = $w;
	}
	$b_norm = [];
	foreach ( $words_b as $w ) {
		if ( '' === $w ) {
			continue;
		}
		$b_norm[ ilsej_anchor_stem( $w ) ] = $w;
	}

	$a_keys = array_keys( $a_norm );
	$b_keys = array_keys( $b_norm );
	$direct = array_values( array_intersect( $a_keys, $b_keys ) );

	$used_a         = array_fill_keys( $direct, true );
	$used_b         = array_fill_keys( $direct, true );
	$prefix_matches = 0;

	foreach ( $a_keys as $sa ) {
		if ( isset( $used_a[ $sa ] ) ) {
			continue;
		}
		foreach ( $b_keys as $sb ) {
			if ( isset( $used_b[ $sb ] ) ) {
				continue;
			}
			if ( strlen( $sa ) >= $prefix_min
				&& strlen( $sb ) >= $prefix_min
				&& ilsej_anchor_shared_prefix_len( $sa, $sb ) >= $prefix_min
			) {
				++$prefix_matches;
				$used_a[ $sa ] = true;
				$used_b[ $sb ] = true;
				break;
			}
		}
	}

	return count( $direct ) + $prefix_matches;
}

/**
 * Detect a "X vs Y" comparison target title.
 *
 * Returns [left_tokens, right_tokens] when the title looks like a comparison;
 * trailing tokens like 'statistics' / 'comparison' / 'market' are stripped from
 * the right side so they don't pollute the right-entity tokens.
 *
 * @param string $target_title Target page title.
 * @return array{0: list<string>, 1: list<string>}|null
 */
function ilsej_detect_vs_target( string $target_title ): ?array {
	if ( '' === $target_title ) {
		return null;
	}
	$lower      = strtolower( $target_title );
	$separators = [ ' vs. ', ' vs ', ' versus ', ' v. ', ' v ' ];
	$trailing   = [
		'statistics',
		'stats',
		'comparison',
		'compared',
		'market',
		'share',
		'analysis',
		'differences',
		'difference',
		'review',
		'guide',
		'breakdown',
		'overview',
		'showdown',
	];
	foreach ( $separators as $sep ) {
		$pos = strpos( $lower, $sep );
		if ( false === $pos ) {
			continue;
		}
		$left_raw     = (string) substr( $lower, 0, $pos ); // PHP 7.4 substr() can return false; '' tokenizes to nothing.
		$right_raw    = (string) substr( $lower, $pos + strlen( $sep ) );
		$right_tokens = ilsej_anchor_tokenize( $right_raw );
		while ( ! empty( $right_tokens ) && in_array( end( $right_tokens ), $trailing, true ) ) {
			array_pop( $right_tokens );
		}
		$left_tokens = ilsej_anchor_tokenize( $left_raw );
		return [ $left_tokens, $right_tokens ];
	}
	return null;
}

/**
 * Pick the words of an anchor phrase that name a specific thing.
 *
 * Reads the phrase in its original case (the extraction prompt copies phrases
 * verbatim). A word counts as a name when it mixes letters and digits
 * (CVE-2026-8452, PS5), has a capital after its first letter (iPhone, DeFi,
 * BlackHole, VB-Audio), or starts with a capital and is not just the
 * sentence-initial word of a longer phrase: it is past the first position,
 * the phrase is one word, two or more words of the phrase start with a
 * capital, or the source context shows the phrase sits mid-sentence. A
 * trailing possessive is dropped ("SEC's" -> "SEC"). Bare numbers, plural
 * acronyms (CVEs, ETFs: these name a category, not one entity), stop words
 * and words on the generic blocklist (months, places, "crypto") never count.
 *
 * Filter `ilsej_anchor_named_tokens` receives the list, the phrase and the
 * context, so a site can drop a name its titles spell differently (an alias
 * such as Bitcoin / BTC) without a plugin release.
 *
 * @param string $phrase  Anchor phrase as extracted, original case.
 * @param string $context Source context around the phrase (may be empty).
 * @return list<string> Name words, original case.
 */
function ilsej_anchor_named_tokens( string $phrase, string $context = '' ): array {
	$split = preg_split( '/\s+/u', trim( $phrase ) );
	if ( ! is_array( $split ) ) {
		return [];
	}
	$words = [];
	foreach ( $split as $raw ) {
		$word = trim( $raw, " \t()[]{},.;:!?\"'\u{201C}\u{201D}\u{2018}\u{2019}" );
		$word = (string) preg_replace( "/['\u{2019}]s$/u", '', $word );
		if ( '' !== $word ) {
			$words[] = $word;
		}
	}

	$capitalized = 0;
	foreach ( $words as $word ) {
		if ( 1 === preg_match( '/^\p{Lu}/u', $word ) ) {
			++$capitalized;
		}
	}

	$first_is_mid_sentence = false;
	$trimmed_phrase        = trim( $phrase );
	if ( '' !== $context && '' !== $trimmed_phrase ) {
		$offset = mb_strpos( $context, $trimmed_phrase );
		if ( false !== $offset && $offset > 0 ) {
			$before                = rtrim( mb_substr( $context, 0, $offset ) );
			$first_is_mid_sentence = '' !== $before && 1 !== preg_match( "/[.!?:;\"\u{201C}]$/u", $before );
		}
	}

	$excluded = array_merge( ilsej_get_anchor_stop_words(), ilsej_get_generic_anchor_blocklist() );
	$named    = [];
	foreach ( $words as $position => $word ) {
		if ( 1 === preg_match( '/^\d+$/', $word ) || 1 === preg_match( '/^\p{Lu}{2,}s$/u', $word ) ) {
			continue;
		}
		if ( 1 === preg_match( '/\d/', $word ) && 1 === preg_match( '/\p{L}/u', $word ) ) {
			$is_name = true;
		} elseif ( 1 === preg_match( '/^.+\p{Lu}/u', $word ) ) {
			$is_name = true;
		} elseif ( 1 === preg_match( '/^\p{Lu}/u', $word ) ) {
			$is_name = $position > 0 || 1 === count( $words ) || $capitalized >= 2 || $first_is_mid_sentence;
		} else {
			$is_name = false;
		}
		if ( $is_name && ! in_array( mb_strtolower( $word ), $excluded, true ) ) {
			$named[] = $word;
		}
	}

	$filtered = apply_filters( 'ilsej_anchor_named_tokens', $named, $phrase, $context );
	if ( ! is_array( $filtered ) ) {
		return $named;
	}
	return array_values( array_filter( $filtered, static fn( $t ) => is_string( $t ) && '' !== $t ) );
}

/**
 * Check whether one name word from an anchor appears in the target's title or slug.
 *
 * A word with digits is an identifier: it must appear as a whole token,
 * though spaces, hyphens and dots between its characters may differ
 * ("CVE-2026-8452" matches the slug "cve-2026-8452", "PS5" matches "PS 5",
 * "CVE-2026-11" does not match "CVE-2026-1111"). Other words match through
 * the gate's stemmed word overlap, or as a run of letters once spaces,
 * hyphens and dots are removed, so "Pump.fun" finds the slug "pump-fun".
 *
 * @param string $token Name word, original case.
 * @param string $title Target post title.
 * @param string $url   Target URL or slug.
 * @return bool True when the target mentions the name.
 */
function ilsej_anchor_named_token_in_target( string $token, string $title, string $url ): bool {
	$title_lower = mb_strtolower( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$slug_lower  = mb_strtolower( ilsej_anchor_slug_from_url( $url ) );
	$token_lower = mb_strtolower( $token );

	if ( 1 === preg_match( '/\d/', $token ) ) {
		$chars = preg_split( '//u', (string) preg_replace( '/[\s\-.]+/u', '', $token_lower ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) || empty( $chars ) ) {
			return true;
		}
		$pattern = '/(?<![\p{L}\p{N}])' . implode( '[\s\-.]*', array_map( static fn( $c ) => preg_quote( $c, '/' ), $chars ) ) . '(?![\p{L}\p{N}])/u';
		return 1 === preg_match( $pattern, $title_lower ) || 1 === preg_match( $pattern, $slug_lower );
	}

	$token_words = ilsej_anchor_tokenize( $token_lower );
	if ( empty( $token_words ) ) {
		return true;
	}
	$target_words = array_values(
		array_unique( array_merge( ilsej_anchor_tokenize( $title_lower ), ilsej_anchor_tokenize( $slug_lower ) ) )
	);
	if ( ilsej_anchor_word_overlap( $token_words, $target_words ) >= 1 ) {
		return true;
	}

	$squash = static fn( string $s ): string => (string) preg_replace( '/[\s\-.]+/u', '', $s );
	return str_contains( $squash( $title_lower . ' ' . $slug_lower ), $squash( $token_lower ) );
}

/**
 * Check whether a one-word lowercase anchor appears in the target's title or slug.
 *
 * Uses the name matcher (stemmed overlap, then letters with spaces, hyphens
 * and dots removed, so "e-commerce" finds "Ecommerce") and also accepts a
 * singular/plural pair the shared stemmer misses on short words: "bots" and
 * "Bot", "nfts" and "NFT", "phones" and "Phone". Kept local so guards 1 to 3
 * keep their stemming.
 *
 * @param string $word  Anchor word, lowercase.
 * @param string $title Target post title.
 * @param string $url   Target URL or slug.
 * @return bool True when the target mentions the word.
 */
function ilsej_anchor_single_word_in_target( string $word, string $title, string $url ): bool {
	if ( ilsej_anchor_named_token_in_target( $word, $title, $url ) ) {
		return true;
	}
	$forms        = static function ( string $w ): array {
		$out = [ $w ];
		if ( strlen( $w ) > 3 && str_ends_with( $w, 's' ) ) {
			$out[] = substr( $w, 0, -1 );
		}
		if ( strlen( $w ) > 4 && str_ends_with( $w, 'es' ) ) {
			$out[] = substr( $w, 0, -2 );
		}
		return $out;
	};
	$anchor_forms = $forms( mb_strtolower( $word ) );
	$target_text  = mb_strtolower( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' ' . ilsej_anchor_slug_from_url( $url ) );
	foreach ( ilsej_anchor_tokenize( $target_text ) as $token ) {
		if ( array_intersect( $anchor_forms, $forms( $token ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Check if an anchor phrase is relevant to the target page (specificity gate).
 *
 * A name guard runs first, then three overlap guards:
 *
 *   0. When the anchor names something (see ilsej_anchor_named_tokens()),
 *      the target title or slug must mention at least one of those names.
 *      Catches "Upbit" -> a Bithumb story, "Binance" -> a Coinbase story and
 *      one CVE -> another CVE.
 *
 * The three overlap guards:
 *
 *   1. Multi-word anchors require >= 2 meaningful word overlap (with stemming
 *      + 5-char prefix match) between (anchor + expected_topic) and
 *      (target_title + slug). Catches "Virtual Hosted Mining" -> "Best Cloud
 *      Mining Platforms" where only "mining" overlapped.
 *
 *   2. Multi-word anchors targeting an "X vs Y" page reject when the anchor
 *      matches only one side's distinctive tokens. Catches "Apple Pay
 *      statistics" -> "Apple Pay vs Google Pay Statistics".
 *
 *   3. Single-word anchors in the generic blocklist (geo / time / category /
 *      process noun) require BOTH expected_topic >= 2 overlap AND
 *      source_context >= 1 overlap with the target. Catches generic single
 *      words like "India" / "2026" linking to specific stats pages.
 *
 * Single-word non-generic anchors: a capitalized one (a name or brand) has
 * to clear guard 0; a lowercase one must appear in the target title or slug
 * (catches "bots" -> a LinkedIn automation guide, "bitcoin" -> a stablecoin
 * explainer).
 * Empty / stop-word-only anchors reject.
 *
 * @param string $phrase         The extracted anchor phrase.
 * @param string $expected_topic AI-generated expected target topic (may be empty).
 * @param string $target_title   Target page post title.
 * @param string $target_url     Target page URL or slug.
 * @param string $source_context Surrounding source-paragraph context (<=9 words).
 * @return bool True if the anchor passes the specificity gate.
 */
function ilsej_is_anchor_relevant(
	string $phrase,
	string $expected_topic,
	string $target_title,
	string $target_url,
	string $source_context = ''
): bool {
	$anchor_lower = strtolower( trim( $phrase ) );
	if ( '' === $anchor_lower ) {
		return false;
	}

	$anchor_words = ilsej_anchor_tokenize( $anchor_lower );
	if ( empty( $anchor_words ) ) {
		return false;
	}

	$named_tokens = ilsej_anchor_named_tokens( $phrase, $source_context );
	if ( ! empty( $named_tokens ) ) {
		$named_found = false;
		foreach ( $named_tokens as $named_token ) {
			if ( ilsej_anchor_named_token_in_target( $named_token, $target_title, $target_url ) ) {
				$named_found = true;
				break;
			}
		}
		if ( ! $named_found ) {
			return false;
		}
	}

	$raw_split         = preg_split( '/\s+/', $anchor_lower );
	$anchor_word_count = is_array( $raw_split ) ? count( array_filter( $raw_split, static fn( $p ) => '' !== $p ) ) : 0;

	$target_words = array_values(
		array_unique(
			array_merge(
				ilsej_anchor_tokenize( $target_title ),
				ilsej_anchor_tokenize( ilsej_anchor_slug_from_url( $target_url ) )
			)
		)
	);

	if ( $anchor_word_count >= 2 ) {
		$combined = array_values(
			array_unique(
				array_merge( $anchor_words, ilsej_anchor_tokenize( $expected_topic ) )
			)
		);

		$vs_parts = ilsej_detect_vs_target( $target_title );
		if ( null !== $vs_parts ) {
			[ $left_words, $right_words ] = $vs_parts;
			if ( ! empty( $left_words ) && ! empty( $right_words ) ) {
				$left_distinctive  = array_values( array_diff( $left_words, $right_words ) );
				$right_distinctive = array_values( array_diff( $right_words, $left_words ) );
				if ( ! empty( $left_distinctive ) && ! empty( $right_distinctive ) ) {
					$matches_left  = ilsej_anchor_word_overlap( $combined, $left_distinctive ) > 0;
					$matches_right = ilsej_anchor_word_overlap( $combined, $right_distinctive ) > 0;
					if ( $matches_left !== $matches_right ) {
						return false;
					}
				}
			}
		}

		return ilsej_anchor_word_overlap( $combined, $target_words ) >= 2;
	}

	$blocklist = ilsej_get_generic_anchor_blocklist();
	if ( ! in_array( $anchor_lower, $blocklist, true ) ) {
		// A capitalized or digit-bearing word was already checked by guard 0
		// (or exempted through the ilsej_anchor_named_tokens filter). A
		// lowercase common noun ("bots", "bitcoin") or a plural acronym that
		// guard 0 skips ("NFTs") must itself appear in the target title or slug.
		$single = trim( $phrase );
		if ( 1 === preg_match( '/\p{Lu}|\d/u', $single ) && 1 !== preg_match( '/^\p{Lu}{2,}s$/u', $single ) ) {
			return true;
		}
		return ilsej_anchor_single_word_in_target( $anchor_lower, $target_title, $target_url );
	}

	$expected_overlap = ilsej_anchor_word_overlap(
		ilsej_anchor_tokenize( $expected_topic ),
		$target_words
	);
	if ( $expected_overlap < 2 ) {
		return false;
	}

	$context_overlap = ilsej_anchor_word_overlap(
		ilsej_anchor_tokenize( $source_context ),
		$target_words
	);
	return $context_overlap >= 1;
}

/**
 * Merge plugin-level excluded category / tag slugs into a Pinecone negative-filters array.
 *
 * Caller-supplied filters are preserved; plugin settings are appended (deduped).
 * Pinecone metadata keys: 'category' and 'tags' (the keys the indexer writes).
 *
 * @param array<string, mixed> $negative_filters Existing negative filters.
 * @param array<string, mixed> $settings         Plugin settings.
 * @return array<string, mixed> Merged negative filters.
 */
function ilsej_apply_excluded_term_filters( array $negative_filters, array $settings ): array {
	$mapping = [
		'category' => 'excluded_categories',
		'tags'     => 'excluded_tags',
	];
	foreach ( $mapping as $pinecone_key => $setting_key ) {
		$slugs = $settings[ $setting_key ] ?? [];
		if ( ! is_array( $slugs ) || empty( $slugs ) ) {
			continue;
		}
		$existing                          = isset( $negative_filters[ $pinecone_key ] ) && is_array( $negative_filters[ $pinecone_key ] )
			? $negative_filters[ $pinecone_key ]
			: [];
		$negative_filters[ $pinecone_key ] = array_values( array_unique( array_merge( $existing, $slugs ) ) );
	}
	return $negative_filters;
}

/**
 * TURN EXTRACTED ENTITIES INTO INTERNAL-LINK SUGGESTIONS
 *
 * The half of ilsej_internal_links_by_content() that runs AFTER extraction:
 * embed each context, query Pinecone, assemble suggestions. Split out because
 * the Batch API path gets its entities back a day later from a batch result
 * file rather than from a live call, and needs to rejoin the pipeline here
 * without re-running extraction (and re-paying for it).
 *
 * @param list<array<string, string>> $entities         Extracted entities.
 * @param array<string, mixed>        $filters          Positive filters.
 * @param array<string, mixed>        $negative_filters Negative filters.
 * @return list<array<string, mixed>> Suggestions.
 *
 * @throws Exception If the batch embedding or Pinecone query fails.
 */
function ilsej_internal_links_from_entities( array $entities, $filters = [], $negative_filters = [] ): array {

	// Handle empty or error responses from entity extraction.
	if ( empty( $entities ) ) {
		return [];
	}

	// Check if the extraction returned an error pseudo-entity.
	// (This happens when OpenAI API fails and returns a placeholder error message).
	if ( 1 === count( $entities ) && isset( $entities[0]['context'] ) && 'api error' === $entities[0]['context'] ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'ILSEJ: Entity extraction returned API error: ' . ( $entities[0]['phrase'] ?? 'Unknown error' ) );
		return [];
	}

	$results = [];

	$settings   = ILSEJ_Core::get_settings();
	$json_key   = $settings['gcp_json_key'] ?? '';
	$project_id = 'project';
	if ( ! empty( $json_key ) ) {
		$json_arr = json_decode( $json_key, true );
		if ( null !== $json_arr && isset( $json_arr['project_id'] ) ) {
			$project_id = $json_arr['project_id'];
		}
	}

	$negative_filters = ilsej_apply_excluded_term_filters( $negative_filters, $settings );

	try {
		// Collect embeddings in one batch call.
		// User requested to use 'context' for bulk auto-suggestions embeddings.
		// to leverage higher semantic signal from surrounding words.
		// NOT array_column(): it silently DROPS rows whose key is missing and
		// re-indexes from 0, while the result loop below looks up
		// $embeddings[ $index ] by the ORIGINAL $entities index. One entity
		// without a context therefore shifts every later entity onto the wrong
		// embedding — anchor "beta" gets linked to whatever Pinecone returns
		// for "gamma". Plausible-looking, wrong, and silent. Mapping keeps
		// position, so a bad row costs one empty embedding instead of
		// corrupting the rest.
		$contexts            = array_map(
			static function ( $entity ) {
				return isset( $entity['context'] ) ? (string) $entity['context'] : '';
			},
			$entities
		);
		$normalized_contexts = array_map( 'ilsej_normalize_text_for_embedding', $contexts );
		$embeddings          = ilsej_vertex_embed_batch( $normalized_contexts, $project_id, $json_key, 'us-central1', true );

		// Execute all Pinecone queries in parallel.
		// Use topK=5 to provide fallback options when deduplicating by URL.
		$all_matches = ilsej_pinecone_query_parallel( array_values( array_filter( $embeddings ) ), 5, $filters, $negative_filters );

		$match_index = 0;
		$used_urls   = [];
		$min_score   = (float) ( $settings['min_relevance_score'] ?? 0.60 );
		foreach ( $entities as $index => $entity ) {
			$embedding = $embeddings[ $index ] ?? null;
			if ( ! $embedding ) {
				continue;
			}

			$matches = $all_matches[ $match_index++ ] ?? [];
			if ( ! empty( $matches ) ) {
				// Find the best match whose URL hasn't been used, meets minimum score,
				// AND whose title/slug is relevant to the anchor phrase.
				foreach ( $matches as $match ) {
					$score = (float) ( $match['score'] ?? 0 );
					if ( $score < $min_score ) {
						continue;
					}
					$url = $match['metadata']['url'] ?? '';
					if ( '' !== $url && in_array( $url, $used_urls, true ) ) {
						continue;
					}

					// Fix A: Post-match anchor-target relevance filter (specificity gate).
					$target_title   = $match['metadata']['title'] ?? '';
					$expected_topic = $entity['expected_topic'] ?? '';
					$source_context = $entity['context'] ?? '';
					if ( ! ilsej_is_anchor_relevant( $entity['phrase'], $expected_topic, $target_title, $url, $source_context ) ) {
						continue;
					}

					$used_urls[] = $url;
					$results[]   = [
						'keyword' => $entity['phrase'],
						// $source_context, not $entity['context'] — the same key
						// is read safely eight lines above and unsafely here,
						// which with WP_DEBUG_LOG now on would log an
						// "Undefined array key" per occurrence.
						'context' => $source_context,
						'api'     => $match,
					];
					break;
				}
			}
		}
	} catch ( Exception $e ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'ILSEJ Internal Links Batch Error: ' . $e->getMessage() );
	}

	return $results;
}

/**
 * GET INTERNAL LINK SUGGESTIONS BY CONTENT (BULK AUTO SUGGESTION MODE)
 *
 * This function is used in "Bulk Auto Suggestion" mode. It automatically
 * identifies key phrases in an article and finds relevant internal links
 * for each phrase using AI-powered entity extraction.
 *
 * HOW IT WORKS:
 * 1. Extracts key phrases from article using OpenAI (ilsej_extract_entities)
 *    - AI identifies 4-7 important concepts suitable for linking
 *    - Returns phrases with surrounding context
 * 2. For each extracted phrase:
 *    - Converts context to vector embedding (ilsej_get_embeddings)
 *    - Searches Pinecone for similar articles (ilsej_pinecone_query)
 *    - Returns best match for that phrase
 * 3. Combines all results into array with phrase -> article mappings
 *
 * USE CASE:
 * - User clicks "Bulk Auto Suggestion" in editor
 * - Plugin automatically finds linking opportunities throughout article
 * - User reviews suggestions and applies links with one click
 *
 * RESPONSE FORMAT:
 * Returns array of suggestions, each containing:
 * - keyword: The phrase to link (anchor text)
 * - context: Surrounding words used for vector search
 * - api: Pinecone match data (title, URL, metadata, similarity score)
 *
 * FILTERING:
 * - Applies category, post type, and publish year filters
 * - Excludes current post from suggestions (negative filter)
 *
 * @param string               $article_content  Full article content (HTML or text).
 * @param array<string, mixed> $filters          Positive filters (category, post_type, publish_year).
 * @param array<string, mixed> $negative_filters Negative filters (ID to exclude).
 * @return list<array<string, mixed>> Array of suggestions with keyword -> article mappings.
 *
 * @throws Exception If the batch embedding or Pinecone query fails.
 */
function ilsej_internal_links_by_content( string $article_content, $filters = [], $negative_filters = [] ): array {
	return ilsej_internal_links_from_entities(
		ilsej_extract_entities( $article_content ),
		$filters,
		$negative_filters
	);
}

/**
 * GET INTERNAL LINK SUGGESTIONS BY KEYWORD/PHRASE (ANCHOR TEXT MODE)
 *
 * This function is used when a user manually selects text in the editor
 * and wants to find internal links for that specific phrase. It's simpler
 * than bulk mode because it only processes one phrase at a time.
 *
 * HOW IT WORKS:
 * 1. User selects text in editor (e.g., "machine learning algorithms")
 * 2. Converts selected phrase to vector embedding (ilsej_get_embeddings)
 * 3. Searches Pinecone for semantically similar articles (ilsej_pinecone_query)
 * 4. Returns top N most similar articles (default: 5)
 *
 * USE CASE:
 * - User highlights phrase in editor
 * - Clicks "Suggest by Anchor Text" button
 * - Plugin shows list of relevant articles
 * - User selects article and applies link
 *
 * RESPONSE FORMAT:
 * Returns array of Pinecone matches, each containing:
 * - id: Pinecone vector ID (WordPress post ID)
 * - score: Similarity score (0-1, higher = more similar)
 * - metadata: Post title, URL, category, publish date, etc.
 *
 * SEMANTIC SEARCH:
 * - Finds articles with similar meaning, not just keyword matches
 * - Works with synonyms and related concepts
 * - More accurate than traditional keyword search
 *
 * FILTERING:
 * - Applies category, post type, and publish year filters
 * - Excludes current post from suggestions (negative filter)
 * - Limits results to top N matches (default: 5)
 *
 * @param string               $kw               User-selected keyword/phrase (anchor text).
 * @param int                  $limit            Maximum number of results to return (default: 5).
 * @param array<string, mixed> $filters          Positive filters (category, post_type, publish_year).
 * @param array<string, mixed> $negative_filters Negative filters (ID to exclude).
 * @return array<string, mixed> Array of similar articles with metadata.
 */
function ilsej_internal_link_by_kw( string $kw, int $limit = 5, $filters = [], $negative_filters = [] ): array {
	$embedding = ilsej_get_embeddings( $kw );
	if ( false === $embedding ) {
		return [];
	}

	$settings         = ILSEJ_Core::get_settings();
	$negative_filters = ilsej_apply_excluded_term_filters( $negative_filters, $settings );
	$matches          = ilsej_pinecone_query( $embedding, $limit, $filters, $negative_filters );
	$min_score        = (float) ( $settings['min_relevance_score'] ?? 0.60 );
	foreach ( $matches as $key => $m ) {
		if ( ( (float) ( $m['score'] ?? 0 ) ) < $min_score ) {
			unset( $matches[ $key ] );
		}
	}
	return $matches;
}

/**
 * VALIDATE PINECONE API KEY AND HOST
 *
 * @param string $api_key The Pinecone API key to validate.
 * @param string $host    The Pinecone host URL to validate.
 * @return array<string, string>|true Array with error details on failure, or true on success.
 */
function ilsej_pinecone_validate_key( $api_key, $host ) {
	if ( empty( $api_key ) || empty( $host ) ) {
		return [
			'type'    => 'both',
			'message' => 'Pinecone API key or Host URL is missing.',
		];
	}

	// --- STEP 1: GLOBAL KEY VALIDATION (Control Plane) ---.
	// This verifies if the API key is valid for *any* index in the account.
	$global_endpoint = 'https://api.pinecone.io/indexes';
	$global_response = wp_remote_get(
		$global_endpoint,
		[
			'headers' => [
				'Api-Key' => $api_key,
				'Accept'  => 'application/json',
			],
			'timeout' => 15,
		]
	);

	if ( ! is_wp_error( $global_response ) ) {
		$global_code = wp_remote_retrieve_response_code( $global_response );
		if ( 401 === $global_code ) {
			return [
				'type'    => 'key',
				'message' => 'Invalid Pinecone API Key (Unauthorized).',
			];
		}
	}

	// --- STEP 2: HOST VALIDATION (Data Plane) ---.
	// If the key is globally valid (or we couldn't verify it due to network issues),
	// we check if it works for the specific host provided.
	$host = rtrim( $host, '/' );

	// Ensure host has protocol.
	if ( strpos( $host, 'http' ) !== 0 ) {
		$host = 'https://' . $host;
	}

	$endpoint = $host . '/describe_index_stats';

	$response = wp_remote_post(
		$endpoint,
		[
			'headers' => [
				'Api-Key' => $api_key,
				'Accept'  => 'application/json',
			],
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		return [
			'type'    => 'host',
			'message' => 'Host connectivity error: ' . $response->get_error_message(),
		];
	}

	$code = wp_remote_retrieve_response_code( $response );

	// If we reach here and get 401, it means the key is valid BUT not for this specific host.
	// (e.g. key belongs to account A, but host is an index in account B).
	if ( 401 === $code ) {
		return [
			'type'    => 'host',
			'message' => 'The API Key is valid, but cannot access this Host/Index. Check if the Host URL matches the project of the API key.',
		];
	}

	if ( 200 !== $code ) {
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		return [
			'type'    => 'host',
			'message' => $data['message'] ?? 'Host validation failed (HTTP ' . $code . ')',
		];
	}

	return true;
}

/**
 * VALIDATE OPENAI API KEY
 *
 * @param string $api_key The OpenAI API key to validate.
 * @return string|true Error message string on failure, or true on success.
 */
function ilsej_openai_validate_key( $api_key ) {
	if ( empty( $api_key ) ) {
		return 'OpenAI API key is missing.';
	}

	$endpoint = 'https://api.openai.com/v1/models';

	$response = wp_remote_get(
		$endpoint,
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
			],
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response->get_error_message();
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		return isset( $data['error']['message'] )
			? ilsej_redact_provider_message( (string) $data['error']['message'], (string) $api_key )
			: 'OpenAI validation failed (HTTP ' . $code . ')';
	}

	return true;
}
