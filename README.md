# AI Semantic Internal Links

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL%20v2%2B-blue.svg)](LICENSE)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-1099AA?logo=php&logoColor=white)](https://phpstan.org/)

A WordPress plugin that suggests internal links by meaning. Each post becomes a vector embedding (Google Vertex AI), the vectors live in your own Pinecone index, and an extraction model (Claude or OpenAI) picks the phrases worth linking. Suggestions appear in the block editor and the Classic Editor; one click inserts the link.

## How it works

1. **Indexing:** each post's title and a short extract are embedded with Vertex AI `text-embedding-005` (768 dimensions) and stored in Pinecone with the post's title, URL, date, primary category, tags and post type.
2. **Suggestions:** Bulk Auto Suggestion sends the post to the extraction provider, which returns linkable phrases with their context; each phrase is embedded and matched against the index. Anchor mode searches for the text you selected and skips extraction.
3. **Anchor checks:** a suggestion is dropped when its anchor names something the target never mentions, or when a generic one-word anchor lacks supporting context.
4. **Background sync:** WP-Cron updates the index when posts are published, updated, unpublished or deleted.

## Features

- Suggestions by meaning, in both the block editor (Gutenberg) and the Classic Editor (TinyMCE)
- Primary and fallback extraction provider: Claude or OpenAI
- Anchor checks, tunable through the `ilsej_generic_anchor_blocklist`, `ilsej_anchor_stop_words` and `ilsej_anchor_named_tokens` filters
- `ilsej_get_related_posts()` for semantic related posts in your theme, with per-post caching
- Category, tag, post type and age filters, plus site-wide excluded categories and tags
- Minimum relevance score and a filter for URLs already linked in the post
- Bulk indexing with progress tracking and token-budgeted Vertex AI requests
- AES-256-CBC encrypted API keys, rate limiting, retries with backoff
- An hourly budget for logged-out embedding calls (`ilsej_public_search_hourly_budget`)

## Requirements

- WordPress 6.0 or later, PHP 8.2 or later
- A [Pinecone](https://www.pinecone.io/) index (768 dimensions, cosine metric)
- A Google Cloud service account with [Vertex AI](https://cloud.google.com/vertex-ai) enabled
- An [Anthropic](https://platform.claude.com/) API key, an [OpenAI](https://platform.openai.com/) API key, or both

## Installation

1. Download `ai-semantic-internal-links.zip` from the latest release, or build it with `bash build.sh`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the zip, then activate it.
3. Open **Internal Linking > Settings**: enter the Pinecone API key and index host, the Google Cloud service account JSON, and your Anthropic and/or OpenAI key; choose the primary and fallback extraction provider and the post types to index.
4. Open **Internal Linking > Articles Indexing** and index your existing content.
5. Open a post in the editor and ask for suggestions.

## Usage in a theme

```php
// Up to 6 related posts for the current post, with a similarity of 0.6 or more.
$related = function_exists( 'ilsej_get_related_posts' )
	? ilsej_get_related_posts( get_the_ID(), 6, 'post', 0.60 )
	: [];
```

## Privacy and external services

The plugin sends data to Google Vertex AI, Pinecone, Anthropic and OpenAI, and only after you enter that service's credentials. In short:

| Service | Data sent | When |
|---|---|---|
| Google Vertex AI | Post title and a short extract; phrases with context; selected text; search text your theme passes | Indexing, suggestions, theme calls |
| Pinecone | Vectors, post ID, title, URL, date, category, tags, post type | Indexing, updates, deletions, suggestions, related posts |
| Anthropic (Claude) | Post text reduced to basic structural tags, plus extraction instructions | Bulk Auto Suggestion, when Claude is primary or the fallback runs |
| OpenAI | Same as Anthropic | Bulk Auto Suggestion, when OpenAI is primary or the fallback runs |

When the primary extraction provider fails and a fallback is set, the post text goes to the fallback provider too. Full details, endpoints and links to each provider's terms and privacy policy are in the **External services** section of [readme.txt](readme.txt).

## Development

```bash
composer install
npm ci
composer lint        # PHP syntax (parallel-lint)
composer check-cs    # WordPress Coding Standards
composer phpstan     # PHPStan level 8
composer test        # PHPUnit
npm run lint         # ESLint
bash build.sh        # builds ai-semantic-internal-links.zip
```

The PHP prefix `ilsej_` / `ILSEJ_` (functions, classes, options, hooks) comes from the original plugin and stays for compatibility.

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Credits

Based on the original AI-powered internal linking plugin by Vahan Petrosyan, published by Search Engine Journal under GPL-2.0-or-later. Maintained by [WebGeeksMedia](https://webgeeksmedia.com/).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
