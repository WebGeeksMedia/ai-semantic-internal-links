=== WebGeeksMedia – AI Semantic Internal Links ===
Contributors: namaserajesh
Tags: internal linking, semantic search, related posts, vector search, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Internal link suggestions in the editor based on meaning, using Vertex AI embeddings, Pinecone vector search and Claude or OpenAI.

== Description ==

**AI Semantic Internal Links** suggests internal links inside the WordPress editor based on what a passage means, beyond plain keyword overlap. Each post becomes a 768-dimensional vector embedding (Google Vertex AI `text-embedding-005`), the vectors live in your own Pinecone index, and similarity search finds the posts that best match each phrase.

The plugin also exposes a Related Posts function, `ilsej_get_related_posts()`, that a theme can call to show related posts chosen by meaning instead of shared tags.

= How it works =

1. **Indexing:** each post's title and a short extract of its text are turned into a vector by Google Vertex AI and stored in Pinecone with the post's title, URL, date, primary category, tags and post type.
2. **Suggestions:** in the editor, Bulk Auto Suggestion asks an extraction model (Claude or OpenAI) to pick linkable phrases from the post, then finds the closest indexed posts for each phrase. Anchor mode skips the extraction model and searches for the text you selected.
3. **Editor UI:** suggestions appear in a sidebar (Gutenberg) or a panel (Classic Editor). One click inserts the link.
4. **Background sync:** WP-Cron updates the index when a post is published, updated, unpublished or deleted.

= Features =

* **Suggestions by meaning:** finds topically related posts even when they share no keywords, tags or categories.
* **Both editors:** Classic Editor (TinyMCE) and the block editor (Gutenberg).
* **Anchor checks:** drops suggestions whose anchor names a brand, product or identifier the target post never mentions, and generic one-word anchors that do not match the target.
* **Related Posts function:** `ilsej_get_related_posts()` returns related posts through a Pinecone query by post ID, cached per post.
* **Index status:** a live "X of Y posts indexed" bar on the Articles Indexing page.
* **Filters:** limit suggestions by category, tag, post type or age; exclude whole categories or tags in Settings.
* **Relevance threshold:** a minimum similarity score hides weak matches.
* **Duplicate filter:** hides suggestions for URLs already linked in the post.
* **Primary and fallback extraction provider:** choose Claude or OpenAI as the primary, the other (or none) as the fallback.
* **Encrypted API keys:** stored with AES-256-CBC, keyed to your site's salts.
* **Rate limiting and retries:** a rate limiter for indexing requests and retries with exponential backoff.
* **Bulk indexing:** index an existing archive in batches with progress tracking.

= What you need =

* A [Pinecone](https://www.pinecone.io/) account and index (768 dimensions, cosine metric).
* A [Google Cloud](https://cloud.google.com/vertex-ai) service account with Vertex AI enabled, for embeddings.
* An [Anthropic](https://platform.claude.com/) API key, an [OpenAI](https://platform.openai.com/) API key, or both, for phrase extraction.

Each service bills you directly under your own account. The plugin adds no fees of its own.

= Credits =

AI Semantic Internal Links is based on the original AI-powered internal linking plugin by Vahan Petrosyan, published by Search Engine Journal under the GPL-2.0-or-later license. Thank you to Vahan for the original design and code.

== External services ==

This plugin connects to the four third-party services below. It sends nothing to any of them until you enter that service's credentials in **Internal Linking > Settings**. Each request goes from your server to the service, never from the visitor's browser.

= Google Vertex AI (Google Cloud) =

Used to turn text into vector embeddings.

* **What is sent:** when a post is indexed, its title and a short extract (the SEO meta description if Yoast SEO is active, the excerpt, the first paragraph, each H2 heading with the paragraph after it, and the last sentence); short phrases with their surrounding sentence when an editor asks for suggestions; the selected text in anchor mode; and any search text your theme passes to `ilsej_get_embeddings()`. To authenticate, the plugin signs a token request with your service account key and sends it to Google's OAuth endpoint.
* **When:** on bulk indexing, when a post is published or updated (through WP-Cron), when an editor asks for suggestions, and when your theme calls the embedding or search functions.
* **Endpoints:** `https://oauth2.googleapis.com/token` and `https://<region>-aiplatform.googleapis.com/`.
* Google Cloud terms: https://cloud.google.com/terms
* Google Cloud Privacy Notice: https://cloud.google.com/terms/cloud-privacy-notice

= Pinecone =

Used to store the vectors and search them.

* **What is sent:** each indexed post's vector, its post ID, and metadata (title, URL, publish date and year, primary category, tags, post type); search vectors and filters when suggestions or related posts are requested; delete requests when a post is unpublished, deleted or leaves an indexed post type. When you save your API key, the plugin checks it against Pinecone's index list.
* **When:** on indexing, on post updates and deletions, when an editor asks for suggestions, and when your theme calls `ilsej_get_related_posts()` (results are cached).
* **Endpoints:** your index host (`https://<your-index>.svc.<region>.pinecone.io`) and `https://api.pinecone.io/`.
* Pinecone terms of service: https://www.pinecone.io/legal/
* Pinecone privacy policy: https://www.pinecone.io/privacy/

= Anthropic (Claude) =

Used to extract linkable phrases from a post when Claude is the primary or fallback extraction provider.

* **What is sent:** the post's text with formatting reduced to basic structural tags (headings, paragraphs, lists, tables, quotes, links and emphasis; scripts, styles, images and attributes are removed), plus the extraction instructions. When you save your API key, the plugin checks it against Anthropic's model list.
* **When:** only when an editor clicks Bulk Auto Suggestion in the editor and Claude is the primary provider, or Claude is the fallback and the primary failed.
* **Endpoints:** `https://api.anthropic.com/v1/messages` and `https://api.anthropic.com/v1/models`.
* Anthropic commercial terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic privacy policy: https://www.anthropic.com/legal/privacy

= OpenAI =

Used to extract linkable phrases from a post when OpenAI is the primary or fallback extraction provider.

* **What is sent:** the same reduced post text and extraction instructions as above. When you save your API key, the plugin checks it against OpenAI's model list.
* **When:** only when an editor clicks Bulk Auto Suggestion and OpenAI is the primary provider, or OpenAI is the fallback and the primary failed.
* **Endpoints:** `https://api.openai.com/v1/chat/completions` and `https://api.openai.com/v1/models`.
* OpenAI terms of use: https://openai.com/policies/terms-of-use/
* OpenAI privacy policy: https://openai.com/policies/privacy-policy/

= About the fallback provider =

When a fallback provider is set and the primary fails (no key, an error, a timeout, a refusal or an unusable answer), the same post text is sent to the fallback provider as well. Set the fallback to "None" if a post should only ever reach one extraction provider.

== Installation ==

1. Upload the `webgeeksmedia-ai-semantic-internal-links` folder to `/wp-content/plugins/`, or install the zip through **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Internal Linking > Settings**.
4. Enter your Pinecone API key and index host URL.
5. Paste or load your Google Cloud service account JSON key.
6. Enter an Anthropic API key, an OpenAI API key or both, then choose the primary and fallback extraction provider.
7. Choose the post types to index, then go to **Internal Linking > Articles Indexing** and index your existing content.
8. Open any post in the editor to see link suggestions.

== Frequently Asked Questions ==

= What credentials do I need? =

* A Pinecone API key and index host URL (vector storage).
* A Google Cloud service account JSON key with Vertex AI access (embeddings).
* An Anthropic API key, an OpenAI API key or both (phrase extraction).

= What does it cost? =

The plugin is free. Each service bills you under your own account according to its pricing. Embeddings are created once per post and again when the post changes. The extraction provider is called only when an editor clicks Bulk Auto Suggestion. Check each provider's current pricing and free allowances before indexing a large archive.

= What data leaves my site? =

See the External services section above. In short: post titles, short text extracts and metadata go to Google Vertex AI and Pinecone; the reduced post text goes to the extraction provider you choose, and also to the fallback provider when the primary fails. API keys are stored encrypted in your database. Review each provider's data policies before indexing sensitive content.

= How is this different from tag-based related posts? =

Tag-based related posts need shared tags or categories. This plugin compares meaning, so a post about "sourdough starter" can match one about "fermenting bread dough" with no tags in common.

= Does it slow down my site? =

Indexing runs in WP-Cron and in the admin. Suggestions run only in the editor. Visitors are affected only if your theme calls `ilsej_get_related_posts()` or `ilsej_get_embeddings()` on the front end; related posts are cached per post. Logged-out calls to `ilsej_get_embeddings()` share an hourly budget (600 by default, filter `ilsej_public_search_hourly_budget`) so a flood of searches cannot run up your bill.

= Does it work with the block editor? =

Yes. It works in the block editor (Gutenberg) and in the Classic Editor (TinyMCE).

= What happens when I publish a post? =

A WP-Cron event creates the post's embedding and stores it in Pinecone shortly after publishing. The new post then appears in suggestions and related posts.

= Can I index custom post types or single pages? =

Yes. Choose the post types to index in Settings (posts and pages by default). To index a few key pages without indexing every page, list their IDs in the extra page IDs field.

= Can I tune which anchors are accepted? =

Yes. The filters `ilsej_generic_anchor_blocklist`, `ilsej_anchor_stop_words` and `ilsej_anchor_named_tokens` adjust the anchor checks.

= Will my data be deleted if I uninstall the plugin? =

Only if you turn on "Delete data on uninstall" in Settings (off by default). Vectors in Pinecone are never deleted by the plugin; remove them from the Pinecone console.

== Changelog ==

= 1.1.0 =

* Renamed to WebGeeksMedia – AI Semantic Internal Links for the WordPress.org
  directory. The plugin slug, folder, main file and text domain are now
  `webgeeksmedia-ai-semantic-internal-links`. Settings carry over because the
  option names are unchanged; a site running 1.0.0 should deactivate and
  delete the old folder after installing 1.1.0.

= 1.0.0 =

* First public release.
* Semantic link suggestions in the block editor and the Classic Editor, from
  Google Vertex AI `text-embedding-005` embeddings stored in a Pinecone index.
* Claude or OpenAI phrase extraction, with a primary and an optional fallback
  provider, structured output for Claude, and one shared time budget for all
  attempts.
* Anchor checks: a named anchor must appear in the target's title or slug, a
  one-word lowercase anchor must appear in the target, and generic one-word
  anchors need supporting context. The filters `ilsej_generic_anchor_blocklist`,
  `ilsej_anchor_stop_words` and `ilsej_anchor_named_tokens` adjust them.
* `ilsej_get_related_posts()` for semantic related posts, with results cached
  per post.
* Bulk indexing with token-budgeted Vertex AI requests, background sync on
  publish, update and delete, and an optional list of extra page IDs.
* Category, tag, post type and age filters, plus site-wide excluded
  categories and tags.
* AES-256-CBC encryption for stored API keys, rate limiting with retries, and an
  hourly budget for logged-out embedding calls
  (`ilsej_public_search_hourly_budget`).
* An opt-in "Delete data on uninstall" setting.
* Based on the original plugin by Vahan Petrosyan, published by Search Engine
  Journal under GPL-2.0-or-later.

