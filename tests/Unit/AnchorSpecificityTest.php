<?php
/**
 * Tests for the anchor-specificity gate (name guard plus three overlap guards).
 * All titles and URLs are synthetic examples.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Test suite for the anchor specificity gate and its helpers.
 *
 * @covers ::ilsej_is_anchor_relevant
 * @covers ::ilsej_anchor_stem
 * @covers ::ilsej_anchor_word_overlap
 * @covers ::ilsej_anchor_shared_prefix_len
 * @covers ::ilsej_detect_vs_target
 */
class AnchorSpecificityTest extends TestCase {

	/**
	 * Set up — pass-through apply_filters so default lists win.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
	}

	/**
	 * Light stemmer collapses common plural / -ies suffixes.
	 *
	 * @dataProvider provider_stem
	 *
	 * @param string $word     Input word.
	 * @param string $expected Expected stem.
	 * @return void
	 */
	public function test_stem( string $word, string $expected ): void {
		$this->assertSame( $expected, ilsej_anchor_stem( $word ) );
	}

	/**
	 * Data provider for test_stem.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provider_stem(): array {
		return [
			'currencies -> currency'   => [ 'currencies', 'currency' ],
			'strategies -> strategy'   => [ 'strategies', 'strategy' ],
			'derivatives -> derivativ' => [ 'derivatives', 'derivativ' ],
			'derivative unchanged'     => [ 'derivative', 'derivative' ],
			'platforms -> platform'    => [ 'platforms', 'platform' ],
			'miners -> miner'          => [ 'miners', 'miner' ],
			'us unchanged'             => [ 'us', 'us' ],
			'ai unchanged'             => [ 'ai', 'ai' ],
			'empty unchanged'          => [ '', '' ],
		];
	}

	/**
	 * Shared character-prefix length is computed correctly.
	 *
	 * @dataProvider provider_shared_prefix_len
	 *
	 * @param string $a        First string.
	 * @param string $b        Second string.
	 * @param int    $expected Expected shared prefix length.
	 * @return void
	 */
	public function test_shared_prefix_len( string $a, string $b, int $expected ): void {
		$this->assertSame( $expected, ilsej_anchor_shared_prefix_len( $a, $b ) );
	}

	/**
	 * Data provider for test_shared_prefix_len.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public function provider_shared_prefix_len(): array {
		return [
			'cryptocurrency vs crypto' => [ 'cryptocurrency', 'crypto', 6 ],
			'mining vs miner'          => [ 'mining', 'miner', 3 ],
			'apple vs banana'          => [ 'apple', 'banana', 0 ],
		];
	}

	/**
	 * Word-set overlap honors stem and prefix matches without double-counting.
	 *
	 * @dataProvider provider_word_overlap
	 *
	 * @param array<int, string> $a        First token list.
	 * @param array<int, string> $b        Second token list.
	 * @param int                $expected Expected overlap count.
	 * @return void
	 */
	public function test_word_overlap( array $a, array $b, int $expected ): void {
		$this->assertSame( $expected, ilsej_anchor_word_overlap( $a, $b ) );
	}

	/**
	 * Data provider for test_word_overlap.
	 *
	 * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: int}>
	 */
	public function provider_word_overlap(): array {
		return [
			'derivatives vs derivative (stem)'            => [ [ 'derivatives' ], [ 'derivative' ], 1 ],
			'cloud mining vs mining platforms (1 direct)' => [ [ 'cloud', 'mining' ], [ 'mining', 'platforms' ], 1 ],
			'cryptocurrency vs crypto (prefix)'           => [ [ 'cryptocurrency' ], [ 'crypto' ], 1 ],
			'crypto vs cryptocurrency+crypto (no double)' => [ [ 'crypto' ], [ 'cryptocurrency', 'crypto' ], 1 ],
			'no overlap'                                  => [ [ 'apple' ], [ 'banana' ], 0 ],
			'empty inputs'                                => [ [], [ 'foo' ], 0 ],
		];
	}

	/**
	 * Comparison-target detector splits "X vs Y" titles into left/right tokens.
	 *
	 * @dataProvider provider_detect_vs_target
	 *
	 * @param string                                                   $title    Target title.
	 * @param array{0: array<int, string>, 1: array<int, string>}|null $expected Expected detector output.
	 * @return void
	 */
	public function test_detect_vs_target( string $title, ?array $expected ): void {
		$this->assertEquals( $expected, ilsej_detect_vs_target( $title ) );
	}

	/**
	 * Data provider for test_detect_vs_target.
	 *
	 * @return array<string, array{0: string, 1: array{0: array<int, string>, 1: array<int, string>}|null}>
	 */
	public function provider_detect_vs_target(): array {
		return [
			'apple vs google with statistics' => [
				'Apple Pay vs Google Pay Statistics',
				[ [ 'apple', 'pay' ], [ 'google', 'pay' ] ],
			],
			'tiktok vs instagram'             => [
				'TikTok vs Instagram',
				[ [ 'tiktok' ], [ 'instagram' ] ],
			],
			'non-vs cloud mining'             => [ 'Cloud Mining Platforms Compared', null ],
			'non-vs derivatives'              => [ 'Crypto Derivatives: A Primer', null ],
			'empty title'                     => [ '', null ],
		];
	}

	/**
	 * Anchor-target combinations that the specificity gate must reject.
	 *
	 * @dataProvider provider_relevance_rejects
	 *
	 * @param string $phrase         Anchor phrase.
	 * @param string $expected_topic Expected target topic from OpenAI.
	 * @param string $context        Surrounding source-paragraph context.
	 * @param string $title          Target page title.
	 * @param string $url            Target page URL.
	 * @return void
	 */
	public function test_anchor_rejects( string $phrase, string $expected_topic, string $context, string $title, string $url ): void {
		$this->assertFalse(
			ilsej_is_anchor_relevant( $phrase, $expected_topic, $title, $url, $context ),
			sprintf( 'Expected REJECT for anchor=%s -> %s', $phrase, $title )
		);
	}

	/**
	 * Data provider for test_anchor_rejects.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
	 */
	public function provider_relevance_rejects(): array {
		return [
			'virtual hosted mining (multi-word 1-overlap)' => [
				'Virtual Hosted Mining',
				'virtual hosted mining',
				'Virtual Hosted Mining: a customer leases remote hardware',
				'Cloud Mining Platforms Compared',
				'https://example.com/cloud-mining-platforms-compared/',
			],
			'gateway-india (single-word generic)'          => [
				'India',
				'India',
				'Indian fintech market is growing rapidly',
				'Payment Gateway Growth in India 2026',
				'https://example.com/payment-gateway-growth-india-2026/',
			],
			'empty anchor'                                 => [ '', '', '', '', '' ],
			'stop-word-only anchor'                        => [ 'the', 'the', '', '', '' ],
			'two-word anchor with one overlap'             => [
				'wallet security',
				'',
				'wallet security is paramount',
				'Cloud Mining Platforms Compared',
				'/cloud-mining-platforms-compared/',
			],
			'2026 generic without context'                 => [
				'2026',
				'2026',
				'In 2026 the market expanded',
				'Cloud Mining Market Report 2026',
				'/cloud-mining-market-report-2026/',
			],
			'apple-pay-only anchor to vs page'             => [
				'Apple Pay statistics',
				'Apple Pay statistics',
				'explores the latest Apple Pay statistics',
				'Apple Pay vs Google Pay Comparison',
				'https://example.com/apple-pay-vs-google-pay-comparison/',
			],
			'google-pay-only anchor to vs page'            => [
				'Google Pay growth',
				'',
				'Google Pay growth has accelerated',
				'Apple Pay vs Google Pay Comparison',
				'/apple-pay-vs-google-pay-comparison/',
			],
			'tiktok-only anchor to vs page'                => [
				'TikTok engagement',
				'',
				'TikTok engagement metrics show growth',
				'TikTok vs Instagram Comparison',
				'/tiktok-vs-instagram-comparison/',
			],
			'name guard: exchange to a rival exchange story' => [
				'Upbit',
				'Upbit exchange',
				'trading volume on Upbit doubled',
				'Broker Considers a Bithumb Investment',
				'https://example.com/broker-bithumb-investment/',
			],
			'name guard: brand to another brand breach'    => [
				'Binance',
				'Binance security incident',
				'Binance said no funds were lost',
				'Coinbase Discloses a Data Incident',
				'/coinbase-data-incident/',
			],
			'name guard: capitalized single word off-topic' => [
				'Markdown',
				'Discord text formatting',
				'Discord supports Markdown for formatting',
				'Discord Soundboard Ideas',
				'https://example.org/discord-soundboard-ideas/',
			],
			'name guard: identifier must match exactly'    => [
				'CVE-2026-1111',
				'CVE-2026-1111 vulnerability',
				'tracked as CVE-2026-1111 by NVD',
				'Gateway Appliance Flaw CVE-2026-8452 Exploited',
				'/gateway-flaw-cve-2026-8452/',
			],
			'lowercase single word absent from target'     => [
				'bots',
				'Discord bots',
				'which is how bots and apps send messages',
				'Automated Messaging on LinkedIn: A Guide',
				'https://example.org/linkedin-automated-messaging/',
			],
			'lowercase coin name to another coin explainer' => [
				'bitcoin',
				'bitcoin price volatility',
				'Coins without a peg, such as bitcoin, trade',
				'Stablecoin Pegs Explained',
				'https://example.com/stablecoin-pegs-explained/',
			],
			'plural acronym absent from target'            => [
				'NFTs',
				'NFT collections',
				'collectors bought NFTs',
				'Stablecoin Pegs Explained',
				'https://example.com/stablecoin-pegs-explained/',
			],
			'name guard: brand-first phrase mid-sentence'  => [
				'Binance trading volume',
				'Binance exchange trading volume',
				'analysts said Binance trading volume fell sharply',
				'Coinbase Trading Volume Report',
				'/coinbase-trading-volume-report/',
			],
			'name guard: identifier is a whole token'      => [
				'CVE-2026-11',
				'CVE-2026-11 vulnerability',
				'tracked as CVE-2026-11 by NVD',
				'Researchers Detail CVE-2026-1111',
				'/cve-2026-1111-details/',
			],
			'name guard: unknown name to an unrelated flaw' => [
				'Zapscape',
				'Zapscape vulnerability',
				'a flaw researchers call Zapscape',
				'Gateway Appliance Flaw CVE-2026-8452 Exploited',
				'/gateway-flaw-cve-2026-8452/',
			],
		];
	}

	/**
	 * Anchor-target combinations that the specificity gate must accept.
	 *
	 * @dataProvider provider_relevance_accepts
	 *
	 * @param string $phrase         Anchor phrase.
	 * @param string $expected_topic Expected target topic from OpenAI.
	 * @param string $context        Surrounding source-paragraph context.
	 * @param string $title          Target page title.
	 * @param string $url            Target page URL.
	 * @return void
	 */
	public function test_anchor_accepts( string $phrase, string $expected_topic, string $context, string $title, string $url ): void {
		$this->assertTrue(
			ilsej_is_anchor_relevant( $phrase, $expected_topic, $title, $url, $context ),
			sprintf( 'Expected ACCEPT for anchor=%s -> %s', $phrase, $title )
		);
	}

	/**
	 * Data provider for test_anchor_accepts.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
	 */
	public function provider_relevance_accepts(): array {
		return [
			'crypto derivatives (stem + prefix)'     => [
				'cryptocurrency derivatives market',
				'crypto derivatives',
				'Inside the cryptocurrency derivatives market, traders hedge',
				'Crypto Derivatives: A Primer',
				'https://example.com/crypto-derivatives-primer/',
			],
			'cloud mining platforms (3-word direct)' => [
				'cloud mining platforms',
				'cloud mining',
				'Cloud mining platforms let users rent hash power',
				'Cloud Mining Platforms Compared',
				'https://example.com/cloud-mining-platforms-compared/',
			],
			'coinbase (single-word non-generic)'     => [
				'Coinbase',
				'Coinbase exchange',
				'Coinbase reported earnings',
				'Coinbase Company Profile',
				'/coinbase-profile/',
			],
			'india with strong context'              => [
				'India',
				'India digital payments overview',
				'India digital payments kept growing this year',
				'India Digital Payments Overview',
				'/india-digital-payments-overview/',
			],
			'liquid staking (multi-word 2-overlap)'  => [
				'liquid staking',
				'liquid staking',
				'Liquid staking grew rapidly',
				'Liquid Staking Basics',
				'/liquid-staking-basics/',
			],
			'both-entities anchor on vs page'        => [
				'Apple Pay vs Google Pay',
				'',
				'Apple Pay vs Google Pay shows different adoption',
				'Apple Pay vs Google Pay Comparison',
				'/apple-pay-vs-google-pay-comparison/',
			],
			'non-vs target unaffected by vs guard'   => [
				'cryptocurrency derivatives market',
				'crypto derivatives',
				'Inside the cryptocurrency derivatives market',
				'Crypto Derivatives: A Primer',
				'/crypto-derivatives-primer/',
			],
			'name guard: name in target title'       => [
				'small text in Discord',
				'Discord text formatting',
				'make small text in Discord messages',
				'Discord Text Formatting Tricks',
				'https://example.org/discord-text-formatting-tricks/',
			],
			'name guard: acronym in parentheses'     => [
				'decentralized finance (DeFi)',
				'decentralized finance basics',
				'across its decentralized finance (DeFi) apps',
				'Decentralized Finance (DeFi) Basics',
				'https://example.com/defi-basics/',
			],
			'name guard: dotted name found in slug'  => [
				'tokens on Pump.fun',
				'Pump.fun token launches',
				'memecoins launched as tokens on Pump.fun',
				'Launchpad Token Activity Cools',
				'https://example.com/pump-fun-launch-activity/',
			],
			'name guard: plural acronym is generic'  => [
				'real-world assets (RWAs)',
				'real world asset tokenization',
				'tokenized real-world assets (RWAs) on chain',
				'Tokenized Asset Market Overview: RWA Size &amp; TVL',
				'/tokenized-asset-market-overview/',
			],
			'lowercase plural to singular title'     => [
				'bots',
				'Discord bots',
				'add bots to your server',
				'Discord Bot Picks for Music Servers',
				'/discord-bot-picks/',
			],
			'lowercase hyphenated word squashed'     => [
				'e-commerce',
				'ecommerce growth',
				'growth in e-commerce sales',
				'Ecommerce Growth Figures',
				'/ecommerce-growth-figures/',
			],
			'plural acronym to singular title'       => [
				'NFTs',
				'NFT market',
				'sales of NFTs fell',
				'NFT Market Data Roundup',
				'/nft-market-data/',
			],
			'lowercase single word in target'        => [
				'staking',
				'crypto staking',
				'earn yield through staking on exchanges',
				'Staking Basics: Earning Yield on Crypto',
				'/staking-basics/',
			],
			'name guard: possessive name'            => [
				"SEC's lawsuit",
				'SEC lawsuit against exchange',
				"the SEC's lawsuit against the exchange",
				'SEC Lawsuit Against an Exchange: What Happened',
				'/sec-exchange-lawsuit/',
			],
			'name guard: month is not a name'        => [
				'crypto crash in March',
				'crypto market crash',
				'the crypto crash in March wiped out gains',
				'Crypto Market Crash Timeline',
				'/crypto-market-crash-timeline/',
			],
			'name guard: identifier spacing differs' => [
				'PS5 controller drift',
				'PS5 controller drift fix',
				'how to fix PS5 controller drift',
				'Fixing PS 5 Controller Drift at Home',
				'/ps-5-controller-drift-home-fix/',
			],
			'name guard: identifier matches exactly' => [
				'CVE-2026-8452',
				'CVE-2026-8452 exploitation',
				'attackers exploit CVE-2026-8452',
				'Gateway Appliance Flaw CVE-2026-8452 Exploited',
				'/gateway-flaw-cve-2026-8452/',
			],
		];
	}

	/**
	 * Name words picked from an anchor phrase.
	 *
	 * @dataProvider provider_named_tokens
	 *
	 * @param string   $phrase   Anchor phrase, original case.
	 * @param string[] $expected Expected name words.
	 * @return void
	 */
	public function test_named_tokens( string $phrase, array $expected ): void {
		$this->assertSame( $expected, ilsej_anchor_named_tokens( $phrase ) );
	}

	/**
	 * Data provider for test_named_tokens.
	 *
	 * @return array<string, array{0:string,1:list<string>}>
	 */
	public function provider_named_tokens(): array {
		return [
			'lowercase phrase has no names'           => [ 'staking rewards', [] ],
			'sentence-initial capital is not a name'  => [ 'Staking rewards', [] ],
			'single capitalized word'                 => [ 'Markdown', [ 'Markdown' ] ],
			'title case counts every capital'         => [ 'Microsoft Teams', [ 'Microsoft', 'Teams' ] ],
			'mid-phrase capital'                      => [ 'voice changer for Discord', [ 'Discord' ] ],
			'inner capital and parentheses'           => [ 'decentralized finance (DeFi)', [ 'DeFi' ] ],
			'identifier with digits'                  => [ 'patch for CVE-2026-8452', [ 'CVE-2026-8452' ] ],
			'bare number and plural acronym excluded' => [ 'kernel CVEs in 2026', [] ],
			'possessive dropped'                      => [ "SEC's lawsuit", [ 'SEC' ] ],
			'month mid-phrase is not a name'          => [ 'crash in March', [] ],
		];
	}

	/**
	 * A capitalized first word counts as a name only when the context shows the
	 * phrase sits mid-sentence; at the start of a sentence it stays ambiguous.
	 *
	 * @return void
	 */
	public function test_named_tokens_first_word_uses_context(): void {
		$this->assertSame( [ 'Binance' ], ilsej_anchor_named_tokens( 'Binance trading volume', 'analysts said Binance trading volume fell' ) );
		$this->assertSame( [], ilsej_anchor_named_tokens( 'Binance trading volume', 'Binance trading volume fell in May' ) );
		$this->assertSame( [], ilsej_anchor_named_tokens( 'Staking rewards', 'prices rose. Staking rewards also grew' ) );
	}

	/**
	 * VS target with no distinctive tokens (after stop-word filter both sides
	 * collapse to identical sets) must not crash and must return a bool.
	 *
	 * @return void
	 */
	public function test_vs_target_with_no_distinctive_tokens_does_not_crash(): void {
		$result = ilsej_is_anchor_relevant(
			'payment systems comparison',
			'',
			'Payment Systems vs Payment Gateways',
			'/payment-systems-vs-payment-gateways/',
			'Comparing the major payment systems available'
		);
		$this->assertIsBool( $result );
	}
}
