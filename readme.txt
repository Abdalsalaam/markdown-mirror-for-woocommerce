=== Product Markdown Mirror by AgentMint ===
Contributors: abdalsalaam
Tags: markdown, ai agents, products, machine readable, woocommerce
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve read-only Markdown mirrors of your WooCommerce product pages at {product-url}.md. No tracking, no store writes, honest by design.

== Description ==

Product Markdown Mirror serves a plain Markdown copy of each WooCommerce product page at the product URL plus a `.md` suffix. AI agents and crawlers that prefer token-light text get the same facts your product page shows, ordered so the decision-relevant data comes first: identifiers, specifications, price, availability, variants.

Example: `https://example.com/product/ceramic-dripper/` also serves `https://example.com/product/ceramic-dripper.md`.

**What each mirror contains**

* The product name and a one-line factual summary
* Identifiers: GTIN (WooCommerce's core field), SKU, brand
* Specifications: visible attributes, weight, dimensions
* Price with currency, sale end dates, and the store's tax display
* Availability, and per-variation lines for variable products
* The product short description (optional, on by default)
* A canonical link back to the product page and the real last-updated date

Sections with no data are omitted, never padded. Every mirror sends a canonical `Link` header pointing at your HTML page and an `X-Robots-Tag: noindex` header, so the mirror never competes with your product page in search. Each product page also gets one `rel="alternate"` link tag so agents can discover the mirror.

**Taxonomy mirrors (optional, off by default)**

Three separate toggles add `.md` mirrors for your archive pages: product categories (including hierarchical paths like `/product-category/clothing/shirts.md`), brands, and tags. Each term mirror carries the term name and description, subcategories with their own mirror links, and a paginated product list (100 per page at `/page/2.md`, `/page/3.md`, and so on, with previous/next links stated in the document; never silent truncation). Term mirrors follow archive visibility rules, so catalog-hidden products stay out, exactly as on the archive page itself. Because terms carry no honest modified date, term mirrors send no Last-Modified header and print no fabricated freshness date.

**What this plugin never does**

* It never writes to your products, your theme, or your database content. Mirrors are virtual URLs, generated on request and cached.
* It never sends anything anywhere. No telemetry, no analytics, no remote requests, no tracking of you or your shoppers.
* It never lets the mirror say something your page does not. There is no setting for "agent-only" content, on purpose: the mirror always reflects the same product data your shoppers see. Serving different content to agents than to humans is cloaking, and this plugin makes it structurally impossible.

**Requirements**

* WooCommerce 9.2 or newer
* Pretty permalinks (Settings, Permalinks, anything except Plain)

**For developers**

* `product_markdown_mirror_is_mirrored` - exclude products from mirroring
* `product_markdown_mirror_sections` - add or reorder document sections (for example shipping data your site actually holds)
* `product_markdown_mirror_document` - filter the final document
* `product_markdown_mirror_cache_max_age` - HTTP Cache-Control max-age (default 300 seconds)
* `product_markdown_mirror_cache_ttl` - server-side cache TTL (default one hour; invalidation hooks keep mirrors correct regardless)
* `product_markdown_mirror_max_variants` - variant lines cap (default 50, disclosed in output when applied)
* `product_markdown_mirror_conflicting_plugins` - the known list of other .md-serving plugins
* `product_markdown_mirror_term_is_mirrored` - exclude term archives from mirroring
* `product_markdown_mirror_term_sections` / `product_markdown_mirror_term_document` - extend or filter term mirror documents
* `product_markdown_mirror_term_page_size` - products per term mirror page (default 100)
* `product_markdown_mirror_term_cache_max_age` / `product_markdown_mirror_term_cache_ttl` - term mirror cache controls

== Frequently Asked Questions ==

= Where is my product's mirror? =

Take the product page URL, remove the trailing slash, and add `.md`. The product page's HTML head also carries a `link rel="alternate" type="text/markdown"` tag pointing at it.

= Does this plugin send my data anywhere? =

No. It makes zero remote requests. Everything is generated inside your site and served from your site.

= Will this improve my AI or search rankings? =

Honestly: nobody can promise that, and this plugin does not. No shopping agent documents fetching product Markdown today. The mirror is cheap to serve and standards-shaped; treat it as groundwork.

= Why does the mirror not show shipping or return details? =

WooCommerce holds no single honest answer for shipping cost or return terms at product level, and this plugin never invents data. Developers can add sections from data their site actually holds via the `product_markdown_mirror_sections` filter.

= Does it work with Plain permalinks? =

No. The `.md` URLs need pretty permalinks (Settings, Permalinks). Nearly all WooCommerce stores already use them.

= Will it conflict with my SEO plugin? =

No. The plugin emits no schema, changes no canonicals or titles, and adds exactly one link tag to product pages. If another active plugin also serves `.md` URLs, you get one dismissible notice asking you to keep only one.

= Are password-protected, draft, or hidden products mirrored? =

Password-protected and unpublished products are never mirrored (their mirror URLs return 404). Catalog-hidden products still have public pages in WooCommerce, so they mirror; use the `product_markdown_mirror_is_mirrored` filter to exclude specific products.

= How do I enable category, brand, or tag mirrors? =

WooCommerce, Markdown Mirror, section "Taxonomy mirrors": one checkbox per group. They are off by default on purpose; each group adds public URLs, and that should be your explicit choice. Brands appear only when your store has the brands taxonomy.

= How does pagination work on term mirrors? =

Each term mirror lists up to 100 products per page and links the next page (`.../your-category/page/2.md`). Every page states "page N of M", so nothing is ever silently cut off. Pages past the last one return 404.

= What happens when I uninstall? =

Everything the plugin stored is removed: settings, cached mirrors, notice dismissals, term cache versions, on every site of a multisite network. Nothing else is touched.

== Screenshots ==

1. Settings screen under WooCommerce with the honest-boundary note.
2. A product mirror served as text/markdown in the browser.

== Changelog ==

= 1.1.0 =
* New: optional taxonomy mirrors for product categories (hierarchical paths included), brands, and tags, each behind its own toggle, all off by default.
* New: paginated term product lists (100 per page, previous/next links, honest 404 past the last page).
* New: rel=alternate discovery links on term archive pages.
* New: precise term cache invalidation (term edits, parent changes, and product saves refresh the affected term mirrors only).
* Developer: six new filters for term mirrors (see the readme filter list).

= 1.0.0 =
* Initial release: product mirrors at {product-url}.md, variable-product support with disclosed caps, rel=alternate discovery, canonical and noindex headers, short-TTL caching with full invalidation, conflict detection, complete uninstall.

== Upgrade Notice ==

= 1.1.0 =
Adds optional category, brand, and tag mirrors (off by default; enable per group in settings). No behavior changes for existing product mirrors.

= 1.0.0 =
Initial release.
