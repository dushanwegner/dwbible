# AI Instructions: dwbible

## What it does

Renders the Latin Vulgate Bible on latinprayer.org in interlinear display
(two translations side by side). Owns all `/bible/`, `/bibel/`, `/latin/`,
`/latin-bible/`, `/latin-bibel/` URL namespaces, including routing,
rendering, JSON API, sitemaps, and OpenGraph meta.

## URL patterns

### Human-readable HTML pages

**Interlinear slugs** (two translations side by side):
- `latin-bibel` = Latin + German
- `latin-bible` = Latin + English

Single-language slugs (`/bible/`, `/bibel/`, `/latin/`) redirect 301 to
their interlinear counterpart. Use interlinear slugs for stable links.

**Patterns:**
```
/{slug}/{book}/                    book landing (chapter 1)
/{slug}/{book}/{chapter}/          full chapter
/{slug}/{book}/{chapter}:{verse}/  chapter with verse highlighted
/{slug}/{book}/{chapter}:{from}-{to}/  chapter with verse range highlighted
```

IMPORTANT: verse references use a **colon**, not a slash.
`/latin-bibel/ephesians/6:11/` is correct.
`/latin-bibel/ephesians/6/11/` is NOT a valid HTML page URL.

Book slugs in HTML URLs follow the first language in the combo:
- `latin-bibel` → German names (prediger, psalmen, matthaus, markus …)
- `latin-bible` → English names (ecclesiastes, psalms, matthew, mark …)
Cross-language names also resolve — the router tries all datasets.

**Working examples:**
```
https://latinprayer.org/latin-bibel/ephesians/6/         chapter
https://latinprayer.org/latin-bibel/ephesians/6:11/      verse highlighted
https://latinprayer.org/latin-bibel/ephesians/6:10-18/   range highlighted
https://latinprayer.org/latin-bible/john/3:16/           Latin+English verse
https://latinprayer.org/latin-bibel/prediger/1/          Ecclesiastes ch.1 (German slug)
https://latinprayer.org/latin-bibel/psalmen/23/          Psalm 23 (German slug)
```

### JSON API

Programmatic access — no HTML, verse text only.

```
/{slug}/index.json                           all books for that translation
/{slug}/{book}/index.json                    chapter list for a book
/{slug}/{book}/{chapter}.json                all verses in a chapter
/{slug}/{book}/{chapter}/{verse}.json        single verse
/{slug}/{book}/{chapter}/{from}-{to}.json    verse range
/bible-index.json                            all books × all translations
```

JSON slugs: `bible` (Douay-Rheims), `latin` (Clementine Vulgate), `bibel` (Menge).

## Key files

- `dwbible.php` — bootstrap, constants, rewrite rules, plugin init
- `includes/class-dwbible-router.php` — URL dispatch (`handle_request`)
- `includes/class-dwbible-render-interlinear.php` — interlinear page renderer
- `includes/class-dwbible-json-api.php` — JSON API endpoints + llms.txt serving
- `includes/class-dwbible-front-meta.php` — OpenGraph / JSON-LD meta
- `includes/class-dwbible-og-image.php` — dynamic OG image generation
- `includes/class-dwbible-data-paths.php` — resolves data directory paths
- `includes/class-dwbible-nav-helpers.php` — prev/next chapter navigation

## Data dependency

Requires the **dwbibledata** plugin. That plugin defines `DWBIBLEDATA_DIR`
and provides flat HTML + JSON files under `data/{dataset}/html/` and
`data/{dataset}/json/`. dwbible adds no DB tables — all content comes from
those files.

Datasets: `bible/`, `latin/`, `bibel/` (single-language). Interlinear pages
load and merge two datasets on the fly.

## How to test

```bash
# PHP syntax check
php -l dwbible.php includes/class-dwbible-router.php

# HTML page (must return 200)
curl -o /dev/null -sw "%{http_code}\n" https://latinprayer.org/latin-bibel/ephesians/6:11/

# JSON API
curl https://latinprayer.org/bible/genesis/1.json | python3 -m json.tool | head -20

# llms.txt (AI documentation)
curl https://latinprayer.org/llms.txt
```
