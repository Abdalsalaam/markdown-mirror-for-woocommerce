![Markdown Mirror for WooCommerce](.wordpress.org/banner-772x250.png)

# Markdown Mirror for WooCommerce

Serve read-only Markdown mirrors of your WooCommerce product pages at `{product-url}.md`. No tracking, no store writes, honest by design.

AI agents and crawlers that prefer token-light text get the same facts your product page shows, ordered so the decision-relevant data comes first: identifiers, specifications, price, availability, variants.

Example: `https://example.com/product/ceramic-dripper/` also serves `https://example.com/product/ceramic-dripper.md`.

## What each mirror contains

- The product name and a one-line factual summary
- Identifiers: GTIN (WooCommerce's core field), SKU, brand
- Classification: categories as hierarchical paths and tags, each linked to its archive (or its `.md` mirror)
- Specifications: visible attributes, weight, dimensions
- Price with currency, sale end dates, and the store's tax display
- Availability as your store displays it, with per-variation lines for variable products
- Reviews: the real average rating and review count, only when reviews exist
- Images: main and gallery image URLs with their alt text
- The product short and full descriptions as plain text
- A canonical link back to the product page and the real last-updated date

Sections with no data are omitted, never padded. Every mirror sends a canonical `Link` header pointing at your HTML page and an `X-Robots-Tag: noindex` header, so the mirror never competes with your product page in search. Each product page gets one `rel="alternate"` link tag so agents can discover the mirror.

Product categories (including hierarchical paths), brands, and tags get `.md` mirrors of their archive pages too, each behind its own toggle, with paginated product lists (100 per page, previous/next links stated in the document).

## What this plugin never does

- It never writes to your products, your theme, or your database content. Mirrors are virtual URLs, generated on request and cached.
- It never sends anything anywhere. No telemetry, no analytics, no remote requests.
- It never lets the mirror say something your page does not. There is no setting for "agent-only" content, on purpose: serving different content to agents than to humans is cloaking, and this plugin makes it structurally impossible.

## Requirements

- WordPress 6.5 or newer
- WooCommerce 9.2 or newer
- PHP 7.4 or newer
- Pretty permalinks (Settings, Permalinks, anything except Plain)

## Settings

WooCommerce, Settings, Products, Markdown mirrors. Everything is on by default; every product mirror section and every taxonomy group has its own toggle. A Status row reports whether another active plugin also serves `.md` URLs.

## Installation

The plugin is under review for the WordPress.org plugin directory. Until it is published there, build the zip from this repository (see Development) and install it via Plugins, Add New, Upload Plugin.

## Development

```bash
composer install          # PHP dev dependencies (PHPCS, PHPUnit)
npm install               # wp-env + Playwright
npm run env:start         # WordPress + WooCommerce dev site (wp-env, Docker)
npm run test:php          # PHPUnit inside wp-env
npm run test:e2e          # Playwright E2E suite
composer phpcs            # WooCommerce-Core coding standards
npm run build             # dist/markdown-mirror-for-woocommerce.zip
```

Developer filters are documented in `readme.txt` (all prefixed `mdmirwc_`), covering per-product exclusion, custom document sections, cache tuning, and term mirror controls.

## License

GPLv2 or later. See `readme.txt` for the full plugin metadata.
