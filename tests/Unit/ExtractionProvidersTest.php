<?php
/**
 * Tests for the entity-extraction providers and the primary/fallback dispatcher.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Core;

/**
 * Claude request shape, provider response handling, and the dispatcher's
 * fallback order and error contract.
 *
 * @covers ::ilsej_extract_entities
 * @covers ::ilsej_extract_entities_claude
 * @covers ::ilsej_extract_entities_openai
 * @covers ::ilsej_build_claude_extraction_request
 */
class ExtractionProvidersTest extends TestCase {

	/**
	 * Canned responses by endpoint URL: [status, body array, headers].
	 *
	 * @var array<string, array{0: int, 1: array<string, mixed>, 2?: array<string, string>}>
	 */
	private $responses = [];

	/**
	 * Requests sent, in order: [url, decoded body, headers, timeout].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: int}>
	 */
	private $calls = [];

	/**
	 * Set up WordPress HTTP stubs that answer from $this->responses.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->responses = [];
		$this->calls     = [];

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// These tests assert on request shape and dispatch, not on tag stripping.
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->calls[] = [ $url, json_decode( $args['body'], true ), $args['headers'], $args['timeout'] ];
				$canned        = $this->responses[ $url ] ?? [ 500, [ 'error' => [ 'message' => 'no canned response' ] ] ];
				return [
					'url'     => $url,
					'status'  => $canned[0],
					'body'    => wp_json_encode( $canned[1] ),
					'headers' => $canned[2] ?? [],
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['status'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, $name ) {
				return $response['headers'][ $name ] ?? '';
			}
		);
	}

	/**
	 * Tear down: clear the settings cache and perf data.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, null );
		unset( $GLOBALS['ilsej_perf_data'] );
		parent::tear_down();
	}

	/**
	 * Put settings straight into the static cache (skips DB read and decryption).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function settings( array $settings ): void {
		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, ILSEJ_Core::normalize_extraction_settings( $settings ) );
	}

	/**
	 * A Claude success body, with a thinking block before the answer.
	 *
	 * @param list<array<string, string>> $entities Entities.
	 * @return array<string, mixed>
	 */
	private function claude_body( array $entities ): array {
		return [
			'stop_reason' => 'end_turn',
			'content'     => [
				[
					'type'     => 'thinking',
					'thinking' => '',
				],
				[
					'type' => 'text',
					'text' => wp_json_encode( [ 'entities' => $entities ] ),
				],
			],
		];
	}

	/**
	 * An OpenAI success body.
	 *
	 * @param list<array<string, string>> $entities Entities.
	 * @return array<string, mixed>
	 */
	private function openai_body( array $entities ): array {
		return [
			'choices' => [
				[
					'finish_reason' => 'stop',
					'message'       => [ 'content' => wp_json_encode( [ 'entities' => $entities ] ) ],
				],
			],
		];
	}

	/**
	 * One sample entity.
	 *
	 * @param string $phrase Phrase.
	 * @return array<string, string>
	 */
	private function entity( string $phrase ): array {
		return [
			'phrase'         => $phrase,
			'context'        => 'about ' . $phrase . ' here',
			'expected_topic' => $phrase,
		];
	}

	/**
	 * The Claude body: system block with cache control, schema output, low effort,
	 * no sampling parameters, and the server-side fallback for Opus.
	 */
	public function test_claude_request_shape_for_opus(): void {
		$body = ilsej_build_claude_extraction_request( '<p>Article</p>', 'claude-opus-5-5' );

		$this->assertSame( 'claude-opus-5-5', $body['model'] );
		$this->assertSame( 'text', $body['system'][0]['type'] );
		$this->assertSame( [ 'type' => 'ephemeral' ], $body['system'][0]['cache_control'] );
		$this->assertSame( ilsej_extraction_instructions(), $body['system'][0]['text'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
		$this->assertStringContainsString( 'Article', $body['messages'][0]['content'] );
		$this->assertSame( 'low', $body['output_config']['effort'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertSame( [ 'entities' ], $body['output_config']['format']['schema']['required'] );
		$this->assertFalse( $body['output_config']['format']['schema']['additionalProperties'] );
		$this->assertFalse( $body['output_config']['format']['schema']['properties']['entities']['items']['additionalProperties'] );
		$this->assertSame( 'default', $body['fallbacks'] );
		$this->assertArrayNotHasKey( 'temperature', $body );
		$this->assertArrayNotHasKey( 'thinking', $body );
	}

	/**
	 * Sonnet takes the server-side fallback too; Haiku has none and must not send it.
	 */
	public function test_server_side_fallback_only_for_supported_models(): void {
		$this->assertSame( 'default', ilsej_build_claude_extraction_request( 'x', 'claude-sonnet-5-5' )['fallbacks'] );
		$this->assertArrayNotHasKey( 'fallbacks', ilsej_build_claude_extraction_request( 'x', 'claude-haiku-5-5' ) );
	}

	/**
	 * The OpenAI prompt carries the shared instructions plus the output-format section.
	 */
	public function test_openai_prompt_uses_shared_instructions(): void {
		$content = ilsej_build_extraction_request( '<p>Article</p>' )['messages'][0]['content'];
		$this->assertStringStartsWith( ilsej_extraction_instructions(), $content );
		$this->assertStringContainsString( ilsej_extraction_output_format(), $content );
		$this->assertSame( 'gpt-5.2', ilsej_build_extraction_request( 'x' )['model'] );
	}

	/**
	 * Default settings: Claude answers, the headers carry the key, version and beta.
	 */
	public function test_claude_primary_success(): void {
		$this->settings(
			[
				'anthropic_api_key' => 'sk-ant-test',
				'openai_api_key'    => 'sk-test',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [ 200, $this->claude_body( [ $this->entity( 'staking rewards' ) ] ) ];
		$GLOBALS['ilsej_perf_data']                      = [
			'extraction_time'     => 0,
			'extraction_provider' => '',
		];

		$entities = ilsej_extract_entities( '<p>Article</p>' );

		$this->assertSame( 'staking rewards', $entities[0]['phrase'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'sk-ant-test', $this->calls[0][2]['x-api-key'] );
		$this->assertSame( '2026-10-21', $this->calls[0][2]['anthropic-version'] );
		$this->assertSame( 'server-side-fallback-2026-07-01', $this->calls[0][2]['anthropic-beta'] );
		$this->assertSame( 'Claude (Anthropic)', $GLOBALS['ilsej_perf_data']['extraction_provider'] );
	}

	/**
	 * Upgraded site: Claude is primary but only the OpenAI key exists, so OpenAI
	 * answers with no Claude call at all.
	 */
	public function test_missing_claude_key_falls_through_to_openai(): void {
		$this->settings( [ 'openai_api_key' => 'sk-test' ] );
		$this->responses[ ILSEJ_OPENAI_CHAT_URL ] = [ 200, $this->openai_body( [ $this->entity( 'mining rigs' ) ] ) ];
		$GLOBALS['ilsej_perf_data']               = [];

		$entities = ilsej_extract_entities( 'content' );

		$this->assertSame( 'mining rigs', $entities[0]['phrase'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( ILSEJ_OPENAI_CHAT_URL, $this->calls[0][0] );
		$this->assertSame( 'OpenAI (fallback)', $GLOBALS['ilsej_perf_data']['extraction_provider'] );
	}

	/**
	 * A Claude HTTP error falls back to OpenAI, whose timeout is what remains of the budget.
	 */
	public function test_claude_error_falls_back_to_openai(): void {
		$this->settings(
			[
				'anthropic_api_key' => 'sk-ant-test',
				'openai_api_key'    => 'sk-test',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [
			529,
			[
				'error' => [
					'type'    => 'overloaded_error',
					'message' => 'Overloaded',
				],
			],
		];
		$this->responses[ ILSEJ_OPENAI_CHAT_URL ]        = [ 200, $this->openai_body( [ $this->entity( 'mining rigs' ) ] ) ];

		$entities = ilsej_extract_entities( 'content' );

		$this->assertSame( 'mining rigs', $entities[0]['phrase'] );
		$this->assertCount( 2, $this->calls );
		$this->assertSame( 60, $this->calls[0][3] );
		$this->assertLessThanOrEqual( 60, $this->calls[1][3] );
	}

	/**
	 * A refusal (after the API's own model fallback) is a failure, so the
	 * configured provider fallback runs.
	 */
	public function test_claude_refusal_falls_back(): void {
		$this->settings(
			[
				'anthropic_api_key' => 'sk-ant-test',
				'openai_api_key'    => 'sk-test',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [
			200,
			[
				'stop_reason'  => 'refusal',
				'stop_details' => [ 'category' => 'cyber' ],
				'content'      => [],
			],
		];
		$this->responses[ ILSEJ_OPENAI_CHAT_URL ]        = [ 200, $this->openai_body( [ $this->entity( 'ransomware groups' ) ] ) ];

		$this->assertSame( 'ransomware groups', ilsej_extract_entities( 'content' )[0]['phrase'] );
	}

	/**
	 * Both fail: exactly ONE placeholder naming both failures, so
	 * ilsej_internal_links_from_entities() stops before embedding.
	 */
	public function test_both_fail_returns_single_combined_placeholder(): void {
		$this->settings(
			[
				'anthropic_api_key' => 'sk-ant-test',
				'openai_api_key'    => 'sk-test',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [
			200,
			[
				'stop_reason' => 'max_tokens',
				'content'     => [],
			],
		];
		$this->responses[ ILSEJ_OPENAI_CHAT_URL ]        = [
			200,
			[
				'choices' => [
					[
						'finish_reason' => 'length',
						'message'       => [ 'content' => '' ],
					],
				],
			],
		];

		$entities = ilsej_extract_entities( 'content' );

		$this->assertCount( 1, $entities );
		$this->assertSame( 'api error', $entities[0]['context'] );
		$this->assertStringContainsString( 'Claude (Anthropic): Truncated', $entities[0]['phrase'] );
		$this->assertStringContainsString( 'OpenAI: Truncated', $entities[0]['phrase'] );
	}

	/**
	 * No key for any configured provider: one placeholder naming the missing
	 * keys, and no request is sent.
	 */
	public function test_no_keys_returns_placeholder_without_requests(): void {
		$this->settings( [] );

		$entities = ilsej_extract_entities( 'content' );

		$this->assertCount( 1, $entities );
		$this->assertSame( 'api error', $entities[0]['context'] );
		$this->assertSame( 'Claude (Anthropic): no API key | OpenAI: no API key', $entities[0]['phrase'] );
		$this->assertSame( [], $this->calls );
	}

	/**
	 * Missing primary key with fallback None is still reported, not silently empty.
	 */
	public function test_missing_primary_key_without_fallback_is_reported(): void {
		$this->settings(
			[
				'openai_api_key'      => 'sk-test',
				'extraction_fallback' => 'none',
			]
		);

		$entities = ilsej_extract_entities( 'content' );

		$this->assertSame( 'Claude (Anthropic): no API key', $entities[0]['phrase'] );
		$this->assertSame( [], $this->calls );
	}

	/**
	 * Fallback 'none': a failed primary returns its error and nothing else is called.
	 */
	public function test_fallback_none_calls_primary_only(): void {
		$this->settings(
			[
				'anthropic_api_key'   => 'sk-ant-test',
				'openai_api_key'      => 'sk-test',
				'extraction_fallback' => 'none',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [ 500, [ 'error' => [ 'type' => 'api_error' ] ] ];

		$entities = ilsej_extract_entities( 'content' );

		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'api error', $entities[0]['context'] );
	}

	/**
	 * OpenAI primary with Claude fallback runs in that order.
	 */
	public function test_openai_primary_claude_fallback_order(): void {
		$this->settings(
			[
				'anthropic_api_key'   => 'sk-ant-test',
				'openai_api_key'      => 'sk-test',
				'extraction_primary'  => 'openai',
				'extraction_fallback' => 'claude',
			]
		);
		$this->responses[ ILSEJ_OPENAI_CHAT_URL ]        = [ 500, [] ];
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [ 200, $this->claude_body( [ $this->entity( 'cold wallets' ) ] ) ];

		$this->assertSame( 'cold wallets', ilsej_extract_entities( 'content' )[0]['phrase'] );
		$this->assertSame( ILSEJ_OPENAI_CHAT_URL, $this->calls[0][0] );
		$this->assertSame( ILSEJ_ANTHROPIC_MESSAGES_URL, $this->calls[1][0] );
	}

	/**
	 * A valid empty answer is a success: no fallback call.
	 */
	public function test_valid_empty_answer_does_not_fall_back(): void {
		$this->settings(
			[
				'anthropic_api_key' => 'sk-ant-test',
				'openai_api_key'    => 'sk-test',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [ 200, $this->claude_body( [] ) ];

		$this->assertSame( [], ilsej_extract_entities( 'content' ) );
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * Authentication messages never reach the error text.
	 */
	public function test_claude_authentication_error_is_redacted(): void {
		$this->settings(
			[
				'anthropic_api_key'   => 'sk-ant-secret-value',
				'extraction_fallback' => 'none',
			]
		);
		$this->responses[ ILSEJ_ANTHROPIC_MESSAGES_URL ] = [
			401,
			[
				'error' => [
					'type'    => 'authentication_error',
					'message' => 'invalid x-api-key sk-ant-secret-value',
				],
			],
		];

		$phrase = ilsej_extract_entities( 'content' )[0]['phrase'];

		$this->assertStringContainsString( 'authentication_error', $phrase );
		$this->assertStringNotContainsString( 'secret-value', $phrase );
	}

	/**
	 * Key-shaped strings are masked in any provider message.
	 */
	public function test_redact_provider_message_masks_keys(): void {
		$this->assertSame(
			'Incorrect API key provided: [redacted]',
			ilsej_redact_provider_message( 'Incorrect API key provided: sk-***...abcd', 'sk-real' )
		);
		$this->assertSame( 'key [redacted] bad', ilsej_redact_provider_message( 'key plainkey123 bad', 'plainkey123' ) );
	}

	/**
	 * Provider order comes from normalized settings: unknown values become defaults,
	 * and a fallback equal to the primary is dropped.
	 */
	public function test_provider_order_normalization(): void {
		$this->assertSame( [ 'claude', 'openai' ], ilsej_extraction_provider_order( [] ) );
		$this->assertSame(
			[ 'openai' ],
			ilsej_extraction_provider_order(
				[
					'extraction_primary'  => 'openai',
					'extraction_fallback' => 'openai',
				]
			)
		);
		$this->assertSame(
			[ 'claude', 'openai' ],
			ilsej_extraction_provider_order(
				[
					'extraction_primary'  => 'bogus',
					'extraction_fallback' => 'bogus',
				]
			)
		);
	}
}
