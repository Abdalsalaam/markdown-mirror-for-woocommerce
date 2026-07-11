# CLAUDE.md - product-markdown-mirror operating rules

## What this is

Free WooCommerce plugin "Product Markdown Mirror by AgentMint" (slug `product-markdown-mirror`):
serves read-only Markdown mirrors of product pages at `{product-url}.md`. Spec:
https://agentmint.net/blueprints/product-markdown-mirror/. Target: WordPress.org submission that
passes plugin review, Plugin Check, PHPCS, security review, and E2E.

## Working rules

1. TDD: failing test first, implement, green, PHPCS clean, then commit. One task = one commit
   (small fix-up commits allowed).
2. Standards: PHPCS `WooCommerce-Core` ruleset (phpcs.xml.dist here), WordPress + WooCommerce coding
   standards, PHP 7.4 compatible syntax, i18n on every user-facing string (text domain
   `product-markdown-mirror`), docblocks on everything public.
3. Security: capability checks + nonces on any state change, sanitize early, escape late, no direct
   superglobal trust, `$wpdb->prepare()` if SQL ever appears (it should not need to), no remote calls.
4. Product rules that outrank convenience: read-only store access; virtual
   routes only (no file writes); the structural equivalence guard (no input may exist that makes the
   mirror diverge from the page); zero telemetry; honest 404s and honest copy (no outcome promises;
   the honest-boundary sentence stays in readme + settings); no marketing notices; no invented data,
   ever (a missing value means the section is omitted).
5. Style: namespace
   `AgentMint\ProductMarkdownMirror`, `includes/class-*.php`, `Main::instance()`, guarded lifecycle
   hooks, tabs per WPCS, Yoda conditions per ruleset. Prefix ALL globals/options/hooks/transients
   with `product_markdown_mirror_`.
6. Public copy (readme.txt, settings screen text): plain honest English, straight quotes and plain
   hyphens (no em/en dashes, ellipsis chars, curly quotes), no superlatives, no fabricated numbers,
   no internal planning references in shipped strings.
7. Commit messages: conventional style (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`) with the
   task ID, e.g. `feat(T-04): renderer core with equivalence-guarded sections`. Push after every
   commit (`origin main`). End commit messages with the Co-Authored-By trailer the harness specifies.
8. Never leave the repo red. If stopping mid-task: commit only green work.
9. Version-floor and WP.org-rule claims must be re-verified against official sources before the
   submission task - do not re-assert from memory.

## Commands (once scaffolded)

- `composer install` then `composer phpcs` / `composer phpcbf`
- `npx wp-env start` (WP + WooCommerce dev site), PHPUnit inside wp-env (see composer scripts / CI)
- E2E: Playwright via wp-env (tests/e2e, from T-14)
