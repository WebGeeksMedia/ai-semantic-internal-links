<?php
/**
 * Entity-extraction providers: Claude (Anthropic) and OpenAI, with a primary and a fallback.
 *
 * The site admin picks which provider extracts first and which one runs when
 * the first fails (settings `extraction_primary`, `extraction_fallback`). Every
 * provider sends the same instructions (ilsej_extraction_instructions()) and is
 * parsed by the same parser (ilsej_parse_extraction_response()), so switching
 * providers changes the model, never the rules.
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seconds one bulk-suggestion request may spend on extraction, all attempts
 * together. The editor request still has to embed and query Pinecone after
 * extraction, and the settings page tells admins that 60-90 seconds of PHP
 * execution time is enough.
 */
const ILSEJ_EXTRACTION_BUDGET_SECONDS = 90;

/**
 * Longest single provider call, in seconds.
 */
const ILSEJ_EXTRACTION_ATTEMPT_TIMEOUT = 60;

/**
 * A fallback attempt starts only when at least this many seconds of the budget remain.
 */
const ILSEJ_EXTRACTION_MIN_FALLBACK_SECONDS = 15;

const ILSEJ_ANTHROPIC_MESSAGES_URL  = 'https://api.anthropic.com/v1/messages';
const ILSEJ_ANTHROPIC_MODELS_URL    = 'https://api.anthropic.com/v1/models';
const ILSEJ_ANTHROPIC_VERSION       = '2026-10-21';
const ILSEJ_ANTHROPIC_FALLBACK_BETA = 'server-side-fallback-2026-07-01';
const ILSEJ_OPENAI_CHAT_URL         = 'https://api.openai.com/v1/chat/completions';

/**
 * EXTRACT ENTITIES FROM ARTICLE CONTENT (PRIMARY PROVIDER, THEN FALLBACK)
 *
 * Identifies phrases in an article that make good internal-link anchors, for
 * "Bulk Auto Suggestion" mode. Tries the primary provider; when it fails (no
 * key, a network or HTTP error, a refusal, a truncated or unusable answer) and a
 * fallback is configured, tries the fallback with whatever remains of the time
 * budget. A valid answer with no entities is a success and does not fall back.
 *
 * Returns the entities on success. Otherwise returns exactly ONE placeholder
 * entity whose context is 'api error' and whose phrase lists each provider's
 * failure, a missing key included, so the log says why nothing was suggested:
 * ilsej_internal_links_from_entities() recognizes that single placeholder,
 * logs it and stops before any embedding or Pinecone call.
 *
 * @param string $content Article content (HTML or plain text).
 * @return list<array<string, string>> Entities with 'phrase', 'context' and usually 'expected_topic'.
 */
function ilsej_extract_entities( $content ) {
	$start    = microtime( true );
	$settings = ILSEJ_Core::get_settings();
	$order    = ilsej_extraction_provider_order( $settings );
	$errors   = [];

	foreach ( $order as $position => $provider ) {
		$remaining = ILSEJ_EXTRACTION_BUDGET_SECONDS - (int) ceil( microtime( true ) - $start );
		if ( $position > 0 && $remaining < ILSEJ_EXTRACTION_MIN_FALLBACK_SECONDS ) {
			$errors[] = ILSEJ_Core::EXTRACTION_PROVIDERS[ $provider ] . ': not tried, the extraction time budget was spent';
			break;
		}
		$timeout = max( 1, min( ILSEJ_EXTRACTION_ATTEMPT_TIMEOUT, $remaining ) );

		$result = 'claude' === $provider
			? ilsej_extract_entities_claude( (string) $content, $settings, $timeout )
			: ilsej_extract_entities_openai( (string) $content, $settings, $timeout );

		if ( $result['ok'] ) {
			ilsej_record_extraction_perf( $start, $provider, $position > 0 );
			return $result['entities'];
		}

		$errors[] = ILSEJ_Core::EXTRACTION_PROVIDERS[ $provider ] . ': ' . $result['error'];
	}

	ilsej_record_extraction_perf( $start, '', false );

	return [
		[
			'phrase'  => implode( ' | ', $errors ),
			'context' => 'api error',
		],
	];
}

/**
 * The providers to try, in order: the primary, then the fallback when it is set
 * and differs from the primary.
 *
 * @param array<string, mixed> $settings Plugin settings (already normalized by get_settings()).
 * @return list<string> Provider keys from ILSEJ_Core::EXTRACTION_PROVIDERS.
 */
function ilsej_extraction_provider_order( array $settings ): array {
	$normalized = ILSEJ_Core::normalize_extraction_settings( $settings );
	$order      = [ (string) $normalized['extraction_primary'] ];
	if ( 'none' !== $normalized['extraction_fallback'] ) {
		$order[] = (string) $normalized['extraction_fallback'];
	}
	return $order;
}

/**
 * Record extraction time and the provider that answered in the editor's perf data.
 *
 * @param float  $start       microtime(true) when extraction began.
 * @param string $provider    Provider key that answered, or '' when none did.
 * @param bool   $is_fallback Whether the answer came from the fallback provider.
 * @return void
 */
function ilsej_record_extraction_perf( float $start, string $provider, bool $is_fallback ): void {
	if ( ! isset( $GLOBALS['ilsej_perf_data'] ) || ! is_array( $GLOBALS['ilsej_perf_data'] ) ) {
		return;
	}
	$elapsed                                       = microtime( true ) - $start;
	$GLOBALS['ilsej_perf_data']['extraction_time'] = (float) ( $GLOBALS['ilsej_perf_data']['extraction_time'] ?? 0 ) + $elapsed;
	$GLOBALS['ilsej_perf_data']['extraction_provider'] = '' === $provider
		? 'failed'
		: ILSEJ_Core::EXTRACTION_PROVIDERS[ $provider ] . ( $is_fallback ? ' (fallback)' : '' );
}

/**
 * One provider attempt's outcome.
 *
 * @param bool                        $ok          Whether the attempt produced a usable answer.
 * @param list<array<string, string>> $entities    Parsed entities (only when $ok).
 * @param string                      $error       Redacted failure description (only when not $ok).
 * @return array{ok: bool, entities: list<array<string, string>>, error: string}
 */
function ilsej_extraction_result( bool $ok, array $entities = [], string $error = '' ): array {
	return [
		'ok'       => $ok,
		'entities' => $entities,
		'error'    => $error,
	];
}

/**
 * Remove credential material from a provider message before it is shown or logged.
 *
 * Error text reaches error_log(), and WP_DEBUG_LOG writes it to a plaintext file.
 * Some providers echo part of a rejected key back ("Incorrect API key provided:
 * sk-***...abcd"), so the key itself and anything shaped like a key are masked.
 *
 * @param string $message Provider message.
 * @param string $api_key The key that was sent.
 * @return string The message with credentials masked.
 */
function ilsej_redact_provider_message( string $message, string $api_key ): string {
	if ( '' !== $api_key ) {
		$message = str_replace( $api_key, '[redacted]', $message );
	}
	return (string) preg_replace( '/\bsk-[A-Za-z0-9_\-\*\.]{4,}/', '[redacted]', $message );
}

/**
 * First value of a response header, as a string.
 *
 * @param array<string, mixed>|WP_Error $response wp_remote_* response.
 * @param string                        $name     Header name.
 * @return string Header value, or '' when absent.
 */
function ilsej_response_header( $response, string $name ): string {
	$value = wp_remote_retrieve_header( $response, $name );
	return is_array( $value ) ? (string) reset( $value ) : (string) $value;
}

/**
 * JSON schema of the entity list, given to Claude as its output format.
 *
 * Mirrors what ilsej_parse_extraction_response() reads: a root object with a
 * required `entities` array of phrase/context/expected_topic objects.
 *
 * @return array<string, mixed> JSON schema.
 */
function ilsej_extraction_schema(): array {
	return [
		'type'                 => 'object',
		'properties'           => [
			'entities' => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'properties'           => [
						'phrase'         => [ 'type' => 'string' ],
						'context'        => [ 'type' => 'string' ],
						'expected_topic' => [ 'type' => 'string' ],
					],
					'required'             => [ 'phrase', 'context', 'expected_topic' ],
					'additionalProperties' => false,
				],
			],
		],
		'required'             => [ 'entities' ],
		'additionalProperties' => false,
	];
}

/**
 * BUILD THE CLAUDE MESSAGES API REQUEST BODY FOR ENTITY EXTRACTION
 *
 * - The instructions go in `system` as a cacheable block; only the article in
 *   the user turn changes between calls.
 * - Structured outputs (`output_config.format`) guarantee the JSON shape, so the
 *   output-format section the OpenAI prompt carries is not sent.
 * - Effort `low`: extraction is a reading task and runs on every bulk request.
 *   No `thinking` field (the models think adaptively by default) and no
 *   temperature/top_p/top_k (rejected by API version 2026-10-21).
 * - `fallbacks: "default"`: when a safety classifier declines (security-news
 *   articles can trip the cyber classifier) the API re-runs the request on
 *   another Claude model inside the same call. Sent only for models that
 *   support it; Claude Haiku 5.5 has no server-side fallback.
 *
 * @param string $content Article content (HTML or plain text).
 * @param string $model   Claude model id from ILSEJ_Core::CLAUDE_MODELS.
 * @return array<string, mixed> Request body for POST /v1/messages.
 */
function ilsej_build_claude_extraction_request( string $content, string $model ): array {
	$body = [
		'model'         => $model,
		// Room for the entity list of a long article plus low-effort thinking.
		'max_tokens'    => 16000,
		'system'        => [
			[
				'type'          => 'text',
				'text'          => ilsej_extraction_instructions(),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		],
		'messages'      => [
			[
				'role'    => 'user',
				'content' => "Article text:\n\n" . ilsej_prepare_extraction_content( $content ),
			],
		],
		'output_config' => [
			'effort' => 'low',
			'format' => [
				'type'   => 'json_schema',
				'schema' => ilsej_extraction_schema(),
			],
		],
	];

	if ( in_array( $model, ILSEJ_Core::CLAUDE_MODELS_WITH_SERVER_FALLBACK, true ) ) {
		$body['fallbacks'] = 'default';
	}

	return $body;
}

/**
 * EXTRACT ENTITIES WITH CLAUDE (ONE ATTEMPT)
 *
 * @param string               $content  Article content.
 * @param array<string, mixed> $settings Plugin settings.
 * @param int                  $timeout  Request timeout in seconds.
 * @return array{ok: bool, entities: list<array<string, string>>, error: string}
 */
function ilsej_extract_entities_claude( string $content, array $settings, int $timeout ): array {
	$api_key = (string) ( $settings['anthropic_api_key'] ?? '' );
	if ( '' === $api_key ) {
		return ilsej_extraction_result( false, [], 'no API key' );
	}

	$model   = (string) ( $settings['claude_model'] ?? ILSEJ_Core::DEFAULT_CLAUDE_MODEL );
	$payload = ilsej_build_claude_extraction_request( $content, $model );
	$headers = [
		'Content-Type'      => 'application/json',
		'x-api-key'         => $api_key,
		'anthropic-version' => ILSEJ_ANTHROPIC_VERSION,
	];
	if ( isset( $payload['fallbacks'] ) ) {
		$headers['anthropic-beta'] = ILSEJ_ANTHROPIC_FALLBACK_BETA;
	}

	$response = wp_remote_post(
		ILSEJ_ANTHROPIC_MESSAGES_URL,
		[
			'headers' => $headers,
			'body'    => (string) wp_json_encode( $payload ),
			'timeout' => $timeout,
		]
	);

	if ( is_wp_error( $response ) ) {
		return ilsej_extraction_result(
			false,
			[],
			ilsej_redact_provider_message( sprintf( 'WP_Error[%s]: %s', (string) $response->get_error_code(), $response->get_error_message() ), $api_key )
		);
	}

	$status   = (int) wp_remote_retrieve_response_code( $response );
	$req_id   = ilsej_response_header( $response, 'request-id' );
	$req_id   = '' !== $req_id ? $req_id : 'n/a';
	$body_raw = (string) wp_remote_retrieve_body( $response );
	$data     = json_decode( $body_raw, true );
	$data     = is_array( $data ) ? $data : [];

	if ( $status < 200 || $status >= 300 ) {
		$type    = isset( $data['error']['type'] ) ? (string) $data['error']['type'] : 'no_type';
		$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : substr( $body_raw, 0, 200 );
		// Authentication and permission messages describe the key; the type
		// already identifies the failure.
		if ( in_array( $type, [ 'authentication_error', 'permission_error' ], true ) ) {
			$message = '[redacted: describes the API key]';
		}
		return ilsej_extraction_result(
			false,
			[],
			ilsej_redact_provider_message( sprintf( 'HTTP %d [req=%s] %s: %s', $status, $req_id, $type, $message ), $api_key )
		);
	}

	$stop_reason = isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '';
	if ( 'refusal' === $stop_reason ) {
		$category = isset( $data['stop_details']['category'] ) ? (string) $data['stop_details']['category'] : 'unspecified';
		return ilsej_extraction_result( false, [], sprintf( 'Declined [req=%s] by a safety classifier (category: %s)', $req_id, $category ) );
	}
	if ( 'max_tokens' === $stop_reason ) {
		return ilsej_extraction_result( false, [], sprintf( 'Truncated [req=%s]: the response hit max_tokens before completing', $req_id ) );
	}

	// Thinking and fallback blocks can precede the answer; read the first text block.
	$text = null;
	foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
		if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) ) {
			$text = (string) $block['text'];
			break;
		}
	}

	$entities = null === $text ? null : ilsej_parse_extraction_response( $text );
	if ( null === $entities ) {
		return ilsej_extraction_result(
			false,
			[],
			ilsej_redact_provider_message( sprintf( 'HTTP %d [req=%s] unusable response: %s', $status, $req_id, substr( $body_raw, 0, 200 ) ), $api_key )
		);
	}

	return ilsej_extraction_result( true, $entities );
}

/**
 * EXTRACT ENTITIES WITH OPENAI (ONE ATTEMPT)
 *
 * The request is the one the plugin has always sent (gpt-5.2, low reasoning,
 * ilsej_build_extraction_request()); only the failure reporting changed, so the
 * dispatcher can tell a failure from an empty answer.
 *
 * @param string               $content  Article content.
 * @param array<string, mixed> $settings Plugin settings.
 * @param int                  $timeout  Request timeout in seconds.
 * @return array{ok: bool, entities: list<array<string, string>>, error: string}
 */
function ilsej_extract_entities_openai( string $content, array $settings, int $timeout ): array {
	$api_key = (string) ( $settings['openai_api_key'] ?? '' );
	if ( '' === $api_key ) {
		return ilsej_extraction_result( false, [], 'no API key' );
	}

	$response = wp_remote_post(
		ILSEJ_OPENAI_CHAT_URL,
		[
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body'    => (string) wp_json_encode( ilsej_build_extraction_request( $content ) ),
			'timeout' => $timeout,
		]
	);

	if ( is_wp_error( $response ) ) {
		return ilsej_extraction_result(
			false,
			[],
			ilsej_redact_provider_message( sprintf( 'WP_Error[%s]: %s', (string) $response->get_error_code(), $response->get_error_message() ), $api_key )
		);
	}

	$status   = (int) wp_remote_retrieve_response_code( $response );
	$req_id   = ilsej_response_header( $response, 'x-request-id' );
	$req_id   = '' !== $req_id ? $req_id : 'n/a';
	$body_raw = (string) wp_remote_retrieve_body( $response );
	$data     = json_decode( $body_raw, true );
	$data     = is_array( $data ) ? $data : [];

	if ( $status < 200 || $status >= 300 ) {
		$type    = isset( $data['error']['type'] ) ? (string) $data['error']['type'] : 'no_type';
		$code    = isset( $data['error']['code'] ) ? (string) $data['error']['code'] : 'no_code';
		$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : substr( $body_raw, 0, 200 );
		// OpenAI's invalid_api_key message echoes back a partial of the key that
		// was submitted; the type and code already identify the failure.
		if ( 'invalid_api_key' === $code || 'invalid_request_error' === $type ) {
			$message = '[redacted: may echo the submitted key]';
		}
		return ilsej_extraction_result(
			false,
			[],
			ilsej_redact_provider_message( sprintf( 'HTTP %d [req=%s] %s/%s: %s', $status, $req_id, $type, $code, $message ), $api_key )
		);
	}

	$choice        = isset( $data['choices'][0] ) && is_array( $data['choices'][0] ) ? $data['choices'][0] : [];
	$finish_reason = isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '';

	// Truncated: max_completion_tokens ran out before the JSON object closed.
	if ( 'length' === $finish_reason ) {
		return ilsej_extraction_result( false, [], sprintf( 'Truncated [req=%s]: the response hit max_completion_tokens before completing', $req_id ) );
	}
	if ( 'content_filter' === $finish_reason || ! empty( $choice['message']['refusal'] ) ) {
		return ilsej_extraction_result( false, [], sprintf( 'Declined [req=%s] by the content filter', $req_id ) );
	}

	$entities = isset( $choice['message']['content'] ) ? ilsej_parse_extraction_response( (string) $choice['message']['content'] ) : null;
	if ( null === $entities ) {
		return ilsej_extraction_result(
			false,
			[],
			ilsej_redact_provider_message( sprintf( 'HTTP %d [req=%s] unusable response: %s', $status, $req_id, substr( $body_raw, 0, 200 ) ), $api_key )
		);
	}

	return ilsej_extraction_result( true, $entities );
}

/**
 * VALIDATE AN ANTHROPIC API KEY
 *
 * Lists models, which needs a valid key and bills nothing.
 *
 * @param string $api_key The Anthropic API key to validate.
 * @return string|true Error message on failure, or true on success.
 */
function ilsej_anthropic_validate_key( $api_key ) {
	if ( empty( $api_key ) ) {
		return 'Anthropic API key is missing.';
	}

	$response = wp_remote_get(
		ILSEJ_ANTHROPIC_MODELS_URL,
		[
			'headers' => [
				'x-api-key'         => $api_key,
				'anthropic-version' => ILSEJ_ANTHROPIC_VERSION,
			],
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		return ilsej_redact_provider_message( $response->get_error_message(), (string) $api_key );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$type = is_array( $data ) && isset( $data['error']['type'] ) ? (string) $data['error']['type'] : '';
		if ( 'authentication_error' === $type ) {
			return 'Anthropic rejected this API key (authentication_error).';
		}
		if ( 'permission_error' === $type ) {
			return 'This Anthropic API key lacks permission to use the API (permission_error).';
		}
		$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
		return '' !== $message
			? ilsej_redact_provider_message( $message, (string) $api_key )
			: 'Anthropic validation failed (HTTP ' . $code . ')';
	}

	return true;
}
