# Changelog

All notable changes to AI Semantic Internal Links are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.1] - 2026-11-28

### Changed
- The "Delete data on uninstall" warning toggle now lives in the enqueued
  admin script instead of an inline script tag.
- Admin notices about key decryption and OpenSSL show to administrators only.
- The readme states when vectors are deleted from Pinecone and lists the key
  checks made when credentials are saved.

### Fixed
- The key decryption notice linked to the wrong settings URL.

### Removed
- The one-time routine that edited the active plugins list to clear an old
  plugin folder name.

## [1.1.0] - 2026-11-27

### Changed
- Renamed to WebGeeksMedia – AI Semantic Internal Links for the WordPress.org
  directory. The plugin slug, folder, main file and text domain are now
  `webgeeksmedia-ai-semantic-internal-links`. Settings carry over because the
  option names are unchanged; a site running 1.0.0 should deactivate and
  delete the old folder after installing 1.1.0.

## [1.0.0] - 2026-11-27

### Added
- First public release.
- Semantic link suggestions in the block editor and the Classic Editor, from
  Google Vertex AI `text-embedding-005` embeddings stored in a Pinecone index.
- Claude or OpenAI phrase extraction, with a primary and an optional fallback
  provider, structured output for Claude, and one shared time budget for all
  attempts.
- Anchor checks: a named anchor must appear in the target's title or slug, a
  one-word lowercase anchor must appear in the target, and generic one-word
  anchors need supporting context. The filters `ilsej_generic_anchor_blocklist`,
  `ilsej_anchor_stop_words` and `ilsej_anchor_named_tokens` adjust them.
- `ilsej_get_related_posts()` for semantic related posts, with results cached
  per post.
- Bulk indexing with token-budgeted Vertex AI requests, background sync on
  publish, update and delete, and an optional list of extra page IDs.
- Category, tag, post type and age filters, plus site-wide excluded
  categories and tags.
- AES-256-CBC encryption for stored API keys, rate limiting with retries, and an
  hourly budget for logged-out embedding calls
  (`ilsej_public_search_hourly_budget`).
- An opt-in "Delete data on uninstall" setting.

### Credits
- Based on the original plugin by Vahan Petrosyan, published by Search Engine
  Journal under GPL-2.0-or-later.
