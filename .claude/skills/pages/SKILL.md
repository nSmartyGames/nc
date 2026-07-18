---
name: pages
description: Create/clone bilingual WordPress pages-posts and WooCommerce products on nicolaecatrina.com via wp-tool.php. Use when the user asks to "create a page/post", "clone last year's event page", "create woo products", or set up a new event (retreat, initiation, course) with payment links.
---

# Pages — WordPress post/page + WooCommerce product creation

Creates event pages on nicolaecatrina.com by cloning an existing bilingual template post,
and creates the WooCommerce products whose `buy-now` links the page embeds.

## Access

Server-side helper: `wp-tool.php`, deployed at `https://nicolaecatrina.com/app/wp-tool.php`
(source in project root, deploy with `bash update.sh wp-tool.php`).

Every request needs the token (hardcoded in wp-tool.php, also in `.deploy.prod.env` as `WPT_TOKEN`):

```
TOKEN=$(grep '^WPT_TOKEN=' .deploy.prod.env | cut -d= -f2)
```

The tool bootstraps WordPress with `DOING_AJAX` defined — without it qTranslate 302-redirects
every request to a language-prefixed URL that 404s. Do not remove that define.

## Endpoints

| Action | Method | Params/body |
|---|---|---|
| `get_post` | GET | `id=` or `slug=&type=post\|page` → raw title/content (qTranslate markers intact) + meta |
| `create_post` | POST | `{title, content, slug?, status?(draft), type?(post), meta?, excerpt?, categories?}` |
| `update_post` | POST | `?id=` + any of `{title, content, status, slug, excerpt, meta}` |
| `get_product` | GET | `id=` → prices, status, buy_now URL, currency |
| `find_products` | GET | `s=` search term → id/name/status/price list |
| `create_product` | POST | `{name, regular_price, sku?, status?(publish), description?, virtual?(true), sold_individually?(false)}` |
| `update_product` | POST | `?id=` + any of `{name, regular_price, sale_price, status, description}` |
| `purge_cache` | GET | optional `url=` (single page) — flushes WP-Optimize page cache + object cache |

The site runs WP-Optimize page caching: admins see fresh content, visitors see cache. WPO's own
purge-on-edit misses qTranslate's language-prefixed URLs (/ro/...). `update_post` auto-purges the
whole page cache, and mu-plugin `wp-content/mu-plugins/wpo-purge-on-save.php` (source
`wpo-purge-on-save.php` in project root, FTP-upload manually) does the same on every save from
wp-admin. Call `purge_cache` manually if visitors still report stale pages.

Example:

```bash
curl -s "https://nicolaecatrina.com/app/wp-tool.php?action=get_post&id=22892&token=$TOKEN"
curl -s -X POST "https://nicolaecatrina.com/app/wp-tool.php?action=create_product&token=$TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Event 2027 - early bird","regular_price":"839"}'
```

## Bilingual format (qTranslate-XT)

Post content is one blob: `[:en] ...english blocks... [:ro] ...romanian blocks... [:]`.
The `[:ro]` switch typically sits inside its own paragraph block. Titles may be plain
(single language) or use the same markers. Per-language slugs go in meta `_qts_slug_en` /
`_qts_slug_ro`.

Content is Gutenberg block HTML (`<!-- wp:heading -->` ... comments). Keep block comments
intact — edit only text inside them. When removing a list item, remove the whole
`<!-- wp:list-item --> ... <!-- /wp:list-item -->` envelope.

## Clone workflow (event page for a new year)

1. `get_post` the previous year's post → save raw content to scratchpad.
2. Create the WooCommerce products first (one per section × tier). Store currency is **RON** —
   `regular_price` is the **lei** amount even if the page displays €.
   Buy links: `https://nicolaecatrina.com/confirmare-comanda/?buy-now=<ID>` (EN) and
   `https://nicolaecatrina.com/ro/confirmare-comanda/?buy-now=<ID>` (RO).
3. Transform content with a python script using **exact-match replacements with asserts**
   (never regex over the whole blob): dates, prices, buy-now IDs, removed/added sections —
   in BOTH the `[:en]` and `[:ro]` halves. Assert at the end: no old year, no old product IDs,
   all new product IDs present, `[:en]`/`[:ro]`/`[:]` markers intact.
4. `create_post` as **draft** with meta `_qts_slug_en`/`_qts_slug_ro` + the layout meta copied
   from the source post (`site-sidebar-layout`, `site-content-layout`,
   `theme-transparent-header-meta`).
5. Verify: `get_post` the new ID, compare stored content byte-for-byte with what was sent.
6. Give the user the WP admin edit URL (`https://nicolaecatrina.com/wp-admin/post.php?post=<ID>&action=edit`)
   for review + publish.

## Conventions

- Prices shown on pages: `120€ (629 lei)` — both currencies; product charges the lei amount.
- Products: virtual, sold_individually=false, publish immediately (page stays draft).
- Placeholder text for unknown details: EN "to be completed", RO "urmează să fie afișat".
- Payment tier names: `early early bird`, `early bird`, `regular`.
- Posts are created as draft by default — publish only when the user says so.

## Reference — Yoga Frumusetii 2026 (created 2026-07-14)

- Post 48500 (draft), slug `yoga-frumusetii-2026`, cloned from post 22892 (yoga-of-beauty-2025).
- Products: in person 48491/48492/48493, online live 48494/48495/48496,
  replay session 48497/48498/48499 (629/839/1049 lei per tier),
  48530 "Ai participat live si vrei acces la inregistrari" (100 lei, RO section only).
