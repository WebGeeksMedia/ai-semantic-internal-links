# Contributing to AI Semantic Internal Links

Thanks for helping. Bug reports, fixes and documentation improvements are all welcome.

## Before you start

- Search the existing issues first.
- For anything larger than a small fix, open an issue to discuss the change before you write code.
- Report security problems privately, as described in [SECURITY.md](SECURITY.md), never in a public issue.
- By contributing, you agree that your work is licensed under GPL-2.0-or-later, the license of this project.

## Development setup

Requirements: PHP 8.2 or later, Composer 2, Node.js 20.19 or later.

```bash
composer install
npm ci
```

## Checks

Every pull request must pass:

```bash
composer lint        # PHP syntax on 8.2 to 8.4 in CI
composer check-cs    # WordPress Coding Standards (phpcs.xml)
composer phpstan     # PHPStan level 8
composer test        # PHPUnit
npm run lint         # ESLint
```

CI also runs the WordPress.org Plugin Check on the built zip and a secret scan.

## Coding conventions

- Follow the WordPress Coding Standards; `composer fix-cs` fixes most formatting.
- Keep the `ilsej_` / `ILSEJ_` prefix for every global function, class, option, transient and hook. Renaming them would break existing installs.
- Sanitize input (`wp_unslash()` first, then `sanitize_text_field()`, `absint()` or `sanitize_key()`) and escape output (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`).
- Pair every AJAX handler's nonce check with a capability check.
- Use `$wpdb->prepare()` for any direct SQL.
- JavaScript: `const` or `let` (never `var`), tabs, single quotes, semicolons.
- Add or update PHPUnit tests for behavior changes. Use `example.com` or `example.org` for any URL in a test.

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Keep each pull request to one change, with a clear description of what and why.
3. Add a line under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for any user-visible change.
4. Make sure every check above passes.

Maintainers handle version bumps (`bash bump-version.sh X.Y.Z`), the readme changelog (`bash sync-changelog.sh`) and releases.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md).
