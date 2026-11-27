<?php
/**
 * GOOGLE VERTEX AI INTEGRATION
 *
 * This file contains helper functions for interacting with Google Cloud
 * Vertex AI API, specifically for generating text embeddings using the
 * text-embedding-005 model.
 *
 * WHAT IT DOES:
 * - Authenticates with Google Cloud using Service Account JSON credentials
 * - Generates OAuth2 access tokens for API requests
 * - Converts text to 768-dimensional vector embeddings
 * - Handles API communication with Google Vertex AI
 *
 * AUTHENTICATION:
 * - Uses Google Cloud Service Account JSON key
 * - Implements JWT (JSON Web Token) signing for OAuth2
 * - Generates short-lived access tokens (1 hour expiry)
 *
 * EMBEDDING MODEL:
 * - Model: text-embedding-005
 * - Dimensions: 768
 * - Task Type: RETRIEVAL_DOCUMENT (optimized for document search)
 * - Region: us-central1 (default, configurable)
 *
 * USAGE:
 * - ilsej_vertex_embed_article(): For indexing articles (includes title)
 * - ilsej_vertex_embed_query(): For querying/searching (query text only)
 *
 * ERROR HANDLING:
 * - Throws exceptions on authentication failures
 * - Returns false on API errors
 * - Logs errors to WordPress error log
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ========= TEXT NORMALIZATION ================================= */
/**
 * Normalize text for vector embedding.
 *
 * Ensures that the string being sent to Vertex AI is clean, plain text.
 * Strips HTML tags, decodes entities, and collapses whitespace.
 * This is crucial for maintaining parity between indexed documents and queries.
 *
 * @param mixed $text Text to normalize.
 * @return string Normalized plain text.
 */
function ilsej_normalize_text_for_embedding( $text ) {
	if ( ! is_string( $text ) ) {
		return '';
	}
	// 1. Remove all HTML tags.
	$text = wp_strip_all_tags( $text );
	// 2. Decode HTML entities (e.g. &#8217; to ', &nbsp; to space).
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	// 3. Normalize whitespace (collapse multiple spaces/newlines to single space).
	$text = preg_replace( '/\s+/', ' ', $text );
	if ( ! is_string( $text ) ) {
		return '';
	}
	return trim( $text );
}

/* ========= BASIC UTILITIES ================================= */
/**
 * Base64 URL-safe encode.
 *
 * @param mixed $data Data to encode.
 * @return string Base64 URL-safe encoded string.
 */
function ilsej_b64url( $data ): string {
	if ( ! is_string( $data ) ) {
		return '';
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * POST JSON to a URL using WordPress HTTP API.
 *
 * This function uses wp_remote_post for consistency with WordPress's
 * HTTP handling (proxies, SSL, timeouts, etc.)
 *
 * @param string             $url The URL to post to.
 * @param array<int, string> $headers Array of headers in format ['Header-Name: value', ...].
 * @param string             $body JSON body string.
 * @return array<string, mixed> Decoded JSON response.
 * @throws Exception On HTTP errors or invalid JSON.
 */
function ilsej_post_json( string $url, array $headers, string $body ): array {
	// Convert header array format from ['Header: value'] to ['Header' => 'value'].
	$wp_headers = [];
	foreach ( $headers as $header ) {
		$parts = explode( ': ', $header, 2 );
		if ( 2 === count( $parts ) ) {
			$wp_headers[ $parts[0] ] = $parts[1];
		}
	}

	$response = wp_remote_post(
		$url,
		[
			'headers' => $wp_headers,
			'body'    => $body,
			'timeout' => 60, // Increased timeout for AI API calls.
		]
	);

	if ( is_wp_error( $response ) ) {
		throw new Exception( 'Vertex AI API Connection Error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	$status = wp_remote_retrieve_response_code( $response );
	$raw    = wp_remote_retrieve_body( $response );

	if ( 200 !== $status ) {
		throw new Exception( 'Vertex AI API Error (' . (int) $status . '): ' . $raw ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	$json = json_decode( $raw, true );
	if ( ! is_array( $json ) ) {
		throw new Exception( 'Received an invalid JSON response from the API.' );
	}
	return $json;
}

/**
 * Obtain a Google Cloud Platform access token.
 *
 * Authenticates with Google Cloud using a Service Account JSON key,
 * generating a JWT-signed OAuth2 token. Caches the token in a transient
 * (encrypted) and in a static variable for the duration of the request.
 *
 * @param string $json_key      The Service Account JSON string.
 * @param bool   $force_refresh Whether to force a token refresh.
 * @return string The access token.
 * @throws Exception If the JSON key is missing or invalid, or if signing fails.
 */
function ilsej_gcp_access_token( string $json_key, bool $force_refresh = false ): string {
	static $static_token = null;
	if ( null !== $static_token && ! $force_refresh ) {
		return $static_token;
	}

	$transient_name = 'ilsej_gcp_token_' . md5( $json_key );
	if ( ! $force_refresh ) {
		$cached = get_transient( $transient_name );
		if ( is_string( $cached ) && '' !== $cached ) {
			// Decrypt the token using site salt.
			$salt = wp_salt( 'logged_in' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$data = base64_decode( $cached );
			if ( false !== $data ) { // @phpstan-ignore notIdentical.alwaysTrue
				$iv_size = openssl_cipher_iv_length( 'aes-256-cbc' );
				if ( false !== $iv_size ) { // @phpstan-ignore notIdentical.alwaysTrue
					$iv        = substr( $data, 0, $iv_size );
					$encrypted = substr( $data, $iv_size );
					$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $salt, 0, $iv );
					if ( $decrypted ) {
						$static_token = $decrypted;
						return $decrypted;
					}
				}
			}
		}
	}

	if ( empty( $json_key ) ) {
		throw new Exception( 'Google Cloud JSON key is missing. Please configure it in the plugin settings.' );
	}

	$sa = json_decode( $json_key, true );
	if ( ! is_array( $sa ) || ! isset( $sa['private_key'] ) || ! isset( $sa['client_email'] ) || ! isset( $sa['private_key_id'] ) ) {
		throw new Exception( "The Google Cloud JSON key is invalid. Ensure it's a complete Service Account JSON including private_key, client_email, and private_key_id." );
	}

	$now         = time();
	$header_data = wp_json_encode(
		[
			'alg' => 'RS256',
			'typ' => 'JWT',
			'kid' => $sa['private_key_id'],
		]
	);
	if ( false === $header_data ) {
		throw new Exception( 'Failed to encode JSON payload.' );
	}
	$header = ilsej_b64url( $header_data );

	$claim_data = wp_json_encode(
		[
			'iss'   => $sa['client_email'],
			'scope' => 'https://www.googleapis.com/auth/cloud-platform',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		]
	);
	if ( false === $claim_data ) {
		throw new Exception( 'Failed to encode JSON payload.' );
	}
	$claim   = ilsej_b64url( $claim_data );
	$to_sign = "$header.$claim";
	$sig     = '';
	if ( ! openssl_sign( $to_sign, $sig, (string) $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
		throw new Exception( 'Failed to sign security token (JWT). Please verify that the private_key in your JSON is correct.' );
	}
	$jwt = "$to_sign." . ilsej_b64url( $sig );

	$resp = ilsej_post_json(
		'https://oauth2.googleapis.com/token',
		[ 'Content-Type: application/x-www-form-urlencoded' ],
		http_build_query(
			[
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			]
		)
	);

	if ( empty( $resp['access_token'] ) ) {
		throw new Exception( 'Authentication failed: No access token returned from Google.' );
	}

	$token        = (string) $resp['access_token'];
	$static_token = $token;

	// Encrypt and store in transient.
	$salt    = wp_salt( 'logged_in' );
	$iv_size = openssl_cipher_iv_length( 'aes-256-cbc' );
	if ( false !== $iv_size ) { // @phpstan-ignore notIdentical.alwaysTrue
		$iv        = openssl_random_pseudo_bytes( $iv_size );
		$encrypted = openssl_encrypt( $token, 'aes-256-cbc', $salt, 0, $iv );
		if ( false !== $encrypted ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			set_transient( $transient_name, base64_encode( $iv . $encrypted ), 3540 ); // 59 minutes.
		}
	}

	return $token;
}

/**
 * VALIDATE GOOGLE CLOUD JSON KEY
 *
 * Attempts to obtain an access token using the provided JSON key.
 * If successful, the key is valid.
 *
 * @param string $json_key The Service Account JSON string.
 * @return true|string Returns true if valid, or error message string if invalid.
 */
function ilsej_vertex_validate_key( $json_key ) {
	try {
		if ( empty( $json_key ) ) {
			return 'Google Cloud JSON key is empty.';
		}
		$token = ilsej_gcp_access_token( $json_key );
		return true;
	} catch ( Exception $e ) {
		return $e->getMessage();
	}
}

/**
 * Generate a vector embedding for an article using Vertex AI.
 *
 * Sends the article text and title to the Vertex AI text-embedding-005 model
 * with RETRIEVAL_DOCUMENT task type, optimized for document indexing.
 *
 * @param string $text          The article body text.
 * @param string $title         The article title.
 * @param string $project_id    Google Cloud Project ID.
 * @param string $json_key      GCP Service Account JSON.
 * @param string $region        GCP Region.
 * @param bool   $force_refresh Whether to force OAuth token refresh.
 * @return array<int, float> Array of 768-dimensional embedding values.
 * @throws Exception If the API response is missing embedding data.
 */
function ilsej_vertex_embed_article(
	string $text,
	string $title,
	string $project_id,
	string $json_key = '',
	string $region = 'us-central1',
	bool $force_refresh = false
): array {
	if ( '' === $json_key ) {
		$json_key = '';
	}

	$token = ilsej_gcp_access_token( $json_key, $force_refresh );

	$url = sprintf(
		'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/' .
		'publishers/google/models/text-embedding-005:predict',
		$region,
		$project_id,
		$region
	);

	// Normalize text and title to decode HTML entities before embedding.
	// This ensures indexed documents use the same character representations as queries.
	$normalized_text  = ilsej_normalize_text_for_embedding( $text );
	$normalized_title = ilsej_normalize_text_for_embedding( $title );

	$payload = [
		'instances'  => [
			[
				'content'   => $normalized_text,
				'title'     => $normalized_title,
				'task_type' => 'RETRIEVAL_DOCUMENT',
			],
		],
		'parameters' => [ 'autoTruncate' => true ],
	];

	$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	if ( false === $body ) {
		throw new Exception( 'Failed to encode JSON payload.' );
	}

	$resp = ilsej_post_json(
		$url,
		[ "Authorization: Bearer $token", 'Content-Type: application/json' ],
		$body
	);

	if ( empty( $resp['predictions'][0]['embeddings']['values'] ) ) {
		throw new Exception( 'The API response did not contain the expected embedding data.' );
	}

	return $resp['predictions'][0]['embeddings']['values'];
}

/**
 * Generate a vector embedding for a search query using Vertex AI.
 *
 * Sends the query text to the Vertex AI text-embedding-005 model
 * with RETRIEVAL_QUERY task type, optimized for search queries.
 *
 * @param string $query         The search query text.
 * @param string $project_id    Google Cloud Project ID.
 * @param string $json_key      GCP Service Account JSON.
 * @param string $region        GCP Region.
 * @param bool   $force_refresh Whether to force OAuth token refresh.
 * @return array<int, float> Array of 768-dimensional embedding values.
 * @throws Exception If the API response is missing embedding data.
 */
function ilsej_vertex_embed_query(
	string $query,
	string $project_id,
	string $json_key = '',
	string $region = 'us-central1',
	bool $force_refresh = false
): array {
	$start_perf_time = microtime( true );
	if ( '' === $json_key ) {
		$json_key = '';
	}

	$token = ilsej_gcp_access_token( $json_key, $force_refresh );

	$url = sprintf(
		'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/' .
		'publishers/google/models/text-embedding-005:predict',
		$region,
		$project_id,
		$region
	);

	$payload = [
		'instances'  => [
			[
				'content'   => $query,
				'task_type' => 'RETRIEVAL_QUERY',
			],
		],
		'parameters' => [ 'autoTruncate' => true ],
	];

	$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	if ( false === $body ) {
		throw new Exception( 'Failed to encode JSON payload.' );
	}

	$resp = ilsej_post_json(
		$url,
		[ "Authorization: Bearer $token", 'Content-Type: application/json' ],
		$body
	);

	if ( empty( $resp['predictions'][0]['embeddings']['values'] ) ) {
		throw new Exception( 'The API response did not contain the expected embedding data.' );
	}

	if ( isset( $GLOBALS['ilsej_perf_data'] ) ) {
		$GLOBALS['ilsej_perf_data']['vertex_time']  += ( microtime( true ) - $start_perf_time );
		$GLOBALS['ilsej_perf_data']['vertex_method'] = 'single';
	}

	return $resp['predictions'][0]['embeddings']['values'];
}

/**
 * Batch embed multiple query texts using Vertex AI.
 *
 * Sends multiple texts in batched requests to the Vertex AI text-embedding-005
 * model with RETRIEVAL_QUERY task type. Chunks requests to stay within limits.
 *
 * @param array<int, string> $texts         Array of text strings to embed.
 * @param string             $project_id    Google Cloud Project ID.
 * @param string             $json_key      GCP Service Account JSON.
 * @param string             $region        GCP Region.
 * @param bool               $force_refresh Whether to force OAuth token refresh.
 * @return array<int, array<int, float>|null> Array of embeddings matching input order.
 * @throws Exception If a batch chunk fails.
 */
function ilsej_vertex_embed_batch(
	array $texts,
	string $project_id,
	string $json_key = '',
	string $region = 'us-central1',
	bool $force_refresh = false
): array {
	$start_perf_time = microtime( true );
	if ( empty( $texts ) ) {
		return [];
	}
	if ( '' === $json_key ) {
		$json_key = '';
	}

	$token = ilsej_gcp_access_token( $json_key, $force_refresh );

	$url = sprintf(
		'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/' .
		'publishers/google/models/text-embedding-005:predict',
		$region,
		$project_id,
		$region
	);

	$all_instances = [];
	foreach ( $texts as $text ) {
		$all_instances[] = [
			'content'   => (string) $text,
			'task_type' => 'RETRIEVAL_QUERY',
		];
	}

	$all_embeddings = [];

	foreach ( ilsej_vertex_chunk_by_token_budget( $all_instances ) as $instances ) {
		$payload = [
			'instances'  => $instances,
			'parameters' => [ 'autoTruncate' => true ],
		];

		try {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
			if ( false === $body ) {
				throw new Exception( 'Failed to encode JSON payload.' );
			}

			$resp = ilsej_post_json(
				$url,
				[ "Authorization: Bearer $token", 'Content-Type: application/json' ],
				$body
			);

			if ( empty( $resp['predictions'] ) || ! is_array( $resp['predictions'] ) ) {
				throw new Exception( 'Batch response format error: missing predictions.' );
			}

			$chunk_embeddings = [];
			foreach ( $resp['predictions'] as $prediction ) {
				$chunk_embeddings[] = $prediction['embeddings']['values'] ?? null;
			}

			// Force exactly one entry per INPUT item. This used to append one
			// entry per RETURNED prediction, so a short predictions array
			// (partial response, a silently dropped item) left $all_embeddings
			// shorter than the input and shifted every later chunk onto the
			// wrong text — callers index embeddings by input position. Same
			// silent-corruption class as the array_column bug fixed in 2.4.1.
			// The catch block below already pads nulls "so array alignment is
			// preserved"; the success path needs the identical guarantee.
			$expected         = count( $instances );
			$chunk_embeddings = array_slice( $chunk_embeddings, 0, $expected );
			$returned         = count( $chunk_embeddings );
			for ( $i = $returned; $i < $expected; $i++ ) {
				$chunk_embeddings[] = null;
			}

			foreach ( $chunk_embeddings as $chunk_embedding ) {
				$all_embeddings[] = $chunk_embedding;
			}
		} catch ( Exception $e ) {
			// Log error and fill this chunk with nulls to allow partial success.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'ILSEJ Vertex AI Batch Chunk Error: ' . $e->getMessage() );
			// Fill nulls for each item in this failed chunk so array alignment is preserved.
			$all_embeddings = array_merge( $all_embeddings, array_fill( 0, count( $instances ), null ) );
		}
	}

	if ( isset( $GLOBALS['ilsej_perf_data'] ) ) {
		$GLOBALS['ilsej_perf_data']['vertex_time']  += ( microtime( true ) - $start_perf_time );
		$GLOBALS['ilsej_perf_data']['vertex_method'] = 'batch';
	}

	return $all_embeddings;
}

/**
 * Split embedding instances into requests that fit Vertex AI's per-request limits.
 *
 * Vertex AI's text-embedding-005 accepts at most 250 instances and 20,000 input
 * tokens per request. Splitting by count alone failed on sites with long
 * articles: 50 posts at ~440 tokens each came to 21,798 tokens and the whole
 * batch was rejected (400). Tokens are estimated at 3 characters each, which overcounts English and
 * keeps the 15,000 budget safely under the real limit. An instance larger than
 * the budget still goes out on its own so autoTruncate can shorten it.
 *
 * @param array<int, array<string, string>> $instances Instances with 'content' and optional 'title'.
 * @return array<int, array<int, array<string, string>>> Requests, in input order.
 */
function ilsej_vertex_chunk_by_token_budget( array $instances ): array {
	$max_instances = 250;
	$token_budget  = 15000;

	$requests = [];
	$current  = [];
	$tokens   = 0;

	foreach ( $instances as $instance ) {
		$chars    = mb_strlen( $instance['content'] ?? '' ) + mb_strlen( $instance['title'] ?? '' );
		$estimate = (int) ceil( $chars / 3 );

		if ( ! empty( $current ) && ( $tokens + $estimate > $token_budget || count( $current ) >= $max_instances ) ) {
			$requests[] = $current;
			$current    = [];
			$tokens     = 0;
		}

		$current[] = $instance;
		$tokens   += $estimate;
	}

	if ( ! empty( $current ) ) {
		$requests[] = $current;
	}

	return $requests;
}

/**
 * Batch embed multiple documents (content + title) for indexing.
 *
 * @param array<int, array<string, string>> $documents     Array of ['content' => ..., 'title' => ...].
 * @param string                            $project_id    Google Cloud Project ID.
 * @param string                            $json_key      GCP Service Account JSON.
 * @param string                            $region        GCP Region.
 * @param bool                              $force_refresh Force OAuth token refresh.
 * @return array<int, array<int, float>|null> Array of embeddings, matching input order.
 * @throws Exception If batch processing fails.
 */
function ilsej_vertex_embed_documents_batch(
	array $documents,
	string $project_id,
	string $json_key = '',
	string $region = 'us-central1',
	bool $force_refresh = false
): array {
	if ( empty( $documents ) ) {
		return [];
	}
	if ( '' === $json_key ) {
		$json_key = '';
	}

	$token = ilsej_gcp_access_token( $json_key, $force_refresh );

	$url = sprintf(
		'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/' .
		'publishers/google/models/text-embedding-005:predict',
		$region,
		$project_id,
		$region
	);

	$all_instances = [];
	foreach ( $documents as $doc ) {
		// Normalize content and title to decode HTML entities before embedding.
		// This ensures indexed documents use the same character representations as queries.
		$all_instances[] = [
			'content'   => ilsej_normalize_text_for_embedding( (string) ( $doc['content'] ?? '' ) ),
			'title'     => ilsej_normalize_text_for_embedding( (string) ( $doc['title'] ?? '' ) ),
			'task_type' => 'RETRIEVAL_DOCUMENT',
		];
	}

	$all_embeddings = [];

	foreach ( ilsej_vertex_chunk_by_token_budget( $all_instances ) as $instances ) {
		$payload = [
			'instances'  => $instances,
			'parameters' => [ 'autoTruncate' => true ],
		];

		try {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
			if ( false === $body ) {
				throw new Exception( 'Failed to encode JSON payload.' );
			}

			$resp = ilsej_post_json(
				$url,
				[ "Authorization: Bearer $token", 'Content-Type: application/json' ],
				$body
			);

			if ( empty( $resp['predictions'] ) || ! is_array( $resp['predictions'] ) ) {
				throw new Exception( 'Batch response format error: missing predictions.' );
			}

			$chunk_embeddings = [];
			foreach ( $resp['predictions'] as $prediction ) {
				$chunk_embeddings[] = $prediction['embeddings']['values'] ?? null;
			}

			// Force exactly one entry per INPUT item. This used to append one
			// entry per RETURNED prediction, so a short predictions array
			// (partial response, a silently dropped item) left $all_embeddings
			// shorter than the input and shifted every later chunk onto the
			// wrong text — callers index embeddings by input position. Same
			// silent-corruption class as the array_column bug fixed in 2.4.1.
			// The catch block below already pads nulls "so array alignment is
			// preserved"; the success path needs the identical guarantee.
			$expected         = count( $instances );
			$chunk_embeddings = array_slice( $chunk_embeddings, 0, $expected );
			$returned         = count( $chunk_embeddings );
			for ( $i = $returned; $i < $expected; $i++ ) {
				$chunk_embeddings[] = null;
			}

			foreach ( $chunk_embeddings as $chunk_embedding ) {
				$all_embeddings[] = $chunk_embedding;
			}
		} catch ( Exception $e ) {
			throw new Exception( 'Document batch processing failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	return $all_embeddings;
}

/**
 * Upsert a single vector to Pinecone.
 *
 * Sends a vector with its metadata to the Pinecone index for storage.
 *
 * @param string               $id        The unique vector ID.
 * @param array<int, float>    $vector    The embedding values.
 * @param array<string, mixed> $metadata  Metadata to store with the vector.
 * @param string               $api_key   Pinecone API Key.
 * @param string               $index_url Pinecone Host URL.
 * @param string               $namespace Pinecone Namespace.
 * @return array<string, mixed> Pinecone response.
 * @throws Exception If communication with Pinecone fails or upsert count is zero.
 */
function ilsej_pinecone_upsert(
	string $id,
	array $vector,
	array $metadata,
	string $api_key,
	string $index_url,
	string $namespace = '' // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound
): array {
	// Ensure embedding values are floats (Pinecone requires numeric array).
	$values = array_map( 'floatval', array_values( $vector ) );

	$payload = [
		'vectors' => [
			[
				'id'       => (string) $id,
				'values'   => $values,
				'metadata' => $metadata,
			],
		],
	];

	// Add namespace only if provided.
	if ( '' !== $namespace ) {
		$payload['namespace'] = $namespace;
	}

	$endpoint = rtrim( $index_url, '/' ) . '/vectors/upsert';

	$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	if ( false === $body ) {
		throw new Exception( 'Failed to encode JSON payload.' );
	}

	$response = wp_remote_post(
		$endpoint,
		[
			'headers' => [
				'Api-Key'      => $api_key,
				'Content-Type' => 'application/json',
			],
			'body'    => $body,
			'timeout' => 30,
		]
	);
	if ( is_wp_error( $response ) ) {
		throw new Exception( 'Error communicating with Pinecone: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		throw new Exception( 'Pinecone returned an invalid JSON response.' );
	}

	// Pinecone REST API returns "upsertedCount" (camelCase).
	$upserted = $data['upsertedCount'] ?? $data['upserted_count'] ?? 0;
	if ( $upserted > 0 ) {
		return $data;
	}

	throw new Exception( 'Pinecone update failed: ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
}

/**
 * Batch upsert multiple vectors to Pinecone.
 *
 * @param array<int, array<string, mixed>> $vectors   Array of ['id' => ..., 'values' => ..., 'metadata' => ...].
 * @param string                           $api_key   Pinecone API Key.
 * @param string                           $index_url Pinecone Host URL.
 * @param string                           $namespace Pinecone Namespace.
 * @return array<string, mixed> Pinecone response.
 * @throws Exception If communication with Pinecone fails or upsert count is zero.
 */
function ilsej_pinecone_upsert_batch(
	array $vectors,
	string $api_key,
	string $index_url,
	string $namespace = '' // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound
): array {
	if ( empty( $vectors ) ) {
		return [ 'upserted_count' => 0 ];
	}

	// Pinecone suggests batching in chunks of 100 for optimal performance if vectors are large,
	// but here we might have up to 20-50, which is fine for a single request.

	$processed_vectors = [];
	foreach ( $vectors as $vec ) {
		$processed_vectors[] = [
			'id'       => (string) $vec['id'],
			'values'   => array_map( 'floatval', array_values( $vec['values'] ) ),
			'metadata' => $vec['metadata'],
		];
	}

	$payload = [ 'vectors' => $processed_vectors ];

	if ( '' !== $namespace ) {
		$payload['namespace'] = $namespace;
	}

	$endpoint = rtrim( $index_url, '/' ) . '/vectors/upsert';

	$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	if ( false === $body ) {
		throw new Exception( 'Failed to encode JSON payload.' );
	}

	$response = wp_remote_post(
		$endpoint,
		[
			'headers' => [
				'Api-Key'      => $api_key,
				'Content-Type' => 'application/json',
			],
			'body'    => $body,
			'timeout' => 60, // Increased timeout for batch.
		]
	);

	if ( is_wp_error( $response ) ) {
		throw new Exception( 'Error during Pinecone batch update: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		throw new Exception( 'Pinecone returned an invalid JSON response.' );
	}

	// Pinecone REST API returns "upsertedCount" (camelCase).
	$upserted = $data['upsertedCount'] ?? $data['upserted_count'] ?? 0;
	if ( $upserted > 0 ) {
		return $data;
	}

	throw new Exception( 'Pinecone batch update failed: ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
