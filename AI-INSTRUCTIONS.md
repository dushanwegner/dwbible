# AI Instructions: dwbible

## What it does
Renders the Latin Vulgate Bible with interlinear translations at `/bible/{book}/{chapter}/{verse}`. Provides navigation, cross-referencing, OpenGraph/JSON-LD SEO meta, a JSON API for programmatic access, and an admin metadata editor.

## Why it exists
Latin Bible with interlinear Latin/English/German display is the core academic feature of latinprayer.org. The plugin owns the entire `/bible/` URL namespace, routing, rendering, and data delivery.

## Key files
- `dwbible.php` — bootstrap, rewrite rules for `/bible/` URLs
- `includes/class-dwbible-router.php` — parses `/bible/{book}/{chapter}/{verse}` URLs, dispatches to renderer
- `includes/class-dwbible-renderer.php` — builds HTML for interlinear Bible display (verse-by-verse, with translation columns)
- `includes/class-dwbible-json-api.php` — REST endpoint `/wp-json/dwbible/v1/verse` for programmatic access
- `includes/class-dwbible-seo.php` — OpenGraph, JSON-LD Schema.org markup per verse/chapter
- `includes/class-dwbible-og-image.php` — dynamic OG image generation via GD
- `includes/class-dwbible-admin.php` — admin page for editing verse-level metadata (alt text, notes)
- `includes/class-dwbible-navigation.php` — prev/next chapter links, book index
- `includes/class-dwbible-linking.php` — auto-links Bible references in other content to `/bible/` URLs

## Data model
Text data lives in `dwbibledata` plugin (separate). dwbible adds:
- Post meta on `page` type: Bible-related page metadata
- Admin-editable metadata stored in custom table or options (check class-dwbible-admin.php)

REST: `GET /wp-json/dwbible/v1/verse?book=Gen&chapter=1&verse=1&lang=latin`

## How to test
```bash
# Syntax check key files
php -l dwbible.php includes/class-dwbible-router.php includes/class-dwbible-renderer.php

# URL test (site must be running)
curl http://latinprayer.local/bible/gen/1/1/

# JSON API test
curl "http://latinprayer.local/wp-json/dwbible/v1/verse?book=Gen&chapter=1&verse=1"
```

## Important conventions
- **Requires `dwbibledata` plugin** to be active — renders nothing without it
- URL structure: `/bible/{book-slug}/{chapter}/{verse}` — book slugs are lowercase abbreviations
- Rewrite rules must be flushed after activation
- Only active on: latinprayer.org

## Dependencies
- `dwbibledata` plugin (required — provides text files)
- PHP GD extension (for OG image generation)
