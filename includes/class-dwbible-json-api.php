<?php
/**
 * DwBible — JSON API Trait
 *
 * Serves pre-generated, self-documenting JSON files for AI consumption.
 * Each JSON file contains a _meta object describing what it is, which
 * translation/book/chapter it represents, and how to navigate to related
 * content — making the Catholic Bible fully accessible to AI agents.
 *
 * The API is documented in the site llms.txt: dwtheme owns /llms.txt and
 * dwbible contributes the API section via the 'dwtheme_llms_sections' filter
 * (section text authored in dwbibledata/data/llms.txt).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_JSON_API_Trait {

    /**
     * Serve a pre-generated JSON file based on current query vars.
     *
     * Route resolution:
     *   - No book, no chapter         → data/{slug}/json/index.json
     *   - Book, no chapter            → data/{slug}/json/{book}/index.json
     *   - Book + chapter              → data/{slug}/json/{book}/{chapter}.json
     *   - Book + chapter + verse(s)   → extracted from chapter file (dynamic)
     */
    private static function serve_json_file() {
        $slug = get_query_var( self::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) {
            $slug = 'bible';
        }

        $book    = get_query_var( self::QV_BOOK );
        $chapter = get_query_var( self::QV_CHAPTER );
        $vfrom   = get_query_var( self::QV_VFROM );
        $vto     = get_query_var( self::QV_VTO );

        // Sanitize inputs: only allow safe characters
        $slug    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $slug ) );
        $book    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $book ) );
        $chapter = preg_replace( '/[^0-9]/', '', $chapter );
        $vfrom   = absint( $vfrom );
        $vto     = absint( $vto );

        // Resolve user-supplied book slug to the canonical JSON directory key.
        //
        // Books may arrive in many forms — abbreviations ("gen", "jn", "1cor"),
        // dataset-specific names ("psalmen", "matthaeus"), or the canonical key
        // itself ("genesis"). We try three resolution strategies in order, each
        // more lenient than the last, so the JSON API matches the HTML router's
        // forgiving behaviour. AI agents in particular will guess slugs from
        // citations they see elsewhere, so accepting common variants is critical.
        if ( ! empty( $book ) ) {
            $base_test     = dwbible_data_dir() . $slug . '/json/';
            $original_book = $book;
            if ( ! is_dir( $base_test . $book ) ) {
                $resolved = null;

                // Strategy 0: any inbound form — the Latin canonical URL slug ("actus-apostolorum"),
                // the internal/English key, or a vernacular name — mapped to the internal data key
                // (the JSON dirs are keyed by it). Keeps the machine API in step with the Latin URLs.
                $ik = DwBible_Plugin::key_from_any_book_slug( $book );
                if ( is_string( $ik ) && $ik !== '' && is_dir( $base_test . $ik ) ) {
                    $resolved = $ik;
                }

                // Strategy 1: book_map.json reverse lookup (e.g. "psalmen" → "psalms").
                $canonical_key = self::resolve_json_book_slug( $book, $slug );
                if ( $canonical_key !== null && is_dir( $base_test . $canonical_key ) ) {
                    $resolved = $canonical_key;
                }

                // Strategy 2: rich HTML resolver. Handles abbreviations like "gen",
                // "jn", "matt", "1cor" via the dataset abbreviation maps + cross-
                // dataset fallbacks (L1–L6 in canonical_book_slug_from_url).
                if ( $resolved === null ) {
                    $dataset_slug = self::canonical_book_slug_from_url( $original_book, $slug );
                    if ( is_string( $dataset_slug ) && $dataset_slug !== '' ) {
                        if ( is_dir( $base_test . $dataset_slug ) ) {
                            $resolved = $dataset_slug;
                        } else {
                            // The HTML resolver returned a dataset URL slug that
                            // differs from the JSON dir key (e.g. "psalmen" vs
                            // "psalms"); translate it via book_map.json.
                            $mapped = self::resolve_json_book_slug( $dataset_slug, $slug );
                            if ( $mapped !== null && is_dir( $base_test . $mapped ) ) {
                                $resolved = $mapped;
                            }
                        }
                    }
                }

                if ( $resolved !== null ) {
                    $book = $resolved;
                }
            }
        }

        // Build file path
        $base = dwbible_data_dir() . $slug . '/json/';

        if ( ! empty( $book ) && ! empty( $chapter ) ) {
            // Chapter file: data/{slug}/json/{book}/{chapter}.json
            $file = $base . $book . '/' . $chapter . '.json';
        } elseif ( ! empty( $book ) ) {
            // Book index: data/{slug}/json/{book}/index.json
            $file = $base . $book . '/index.json';
        } else {
            // Translation index: data/{slug}/json/index.json
            $file = $base . 'index.json';
        }

        if ( ! file_exists( $file ) ) {
            self::serve_json_404( [
                'requested' => [
                    'slug'    => $slug,
                    'book'    => $book,
                    'chapter' => $chapter,
                    'verse'   => $vfrom,
                ],
            ] );
            exit;
        }

        // ── Single verse or verse range: extract from chapter JSON ──────
        if ( $vfrom > 0 && ! empty( $book ) && ! empty( $chapter ) ) {
            self::serve_verse_json( $file, $slug, $book, (int) $chapter, $vfrom, $vto );
            exit;
        }

        // Serve the static file with appropriate headers
        self::send_json_headers();
        readfile( $file );
        exit;
    }

    /**
     * Extract and serve a single verse or verse range from a chapter JSON file.
     *
     * Reads the pre-generated chapter file, filters to the requested verse(s),
     * and wraps them in a self-documenting response with navigation links.
     */
    private static function serve_verse_json( $chapter_file, $slug, $book, $chapter, $vfrom, $vto ) {
        $raw = file_get_contents( $chapter_file );
        if ( $raw === false ) {
            self::serve_json_404();
            exit;
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['verses'] ) ) {
            self::serve_json_404();
            exit;
        }

        // Determine range
        if ( $vto <= 0 || $vto < $vfrom ) {
            $vto = $vfrom; // single verse
        }

        // Filter verses
        $matched = [];
        foreach ( $data['verses'] as $v ) {
            $num = (int) $v['verse'];
            if ( $num >= $vfrom && $num <= $vto ) {
                $matched[] = $v;
            }
        }

        if ( empty( $matched ) ) {
            $total = count( $data['verses'] );
            status_header( 404 );
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'Access-Control-Allow-Origin: *' );
            echo json_encode( [
                'error'      => 'VERSE_NOT_FOUND',
                'message'    => "Verse {$vfrom} does not exist in this chapter.",
                'suggestion' => "This chapter has {$total} verses (1–{$total}).",
                'help'       => 'https://latinprayer.org/llms.txt',
            ], JSON_UNESCAPED_SLASHES );
            exit;
        }

        $is_range   = ( $vfrom !== $vto );
        $site_url   = site_url();
        $book_name  = $data['_meta']['book']['name'] ?? ucwords( str_replace( '-', ' ', $book ) );
        $bible_name = $data['_meta']['translation']['name'] ?? 'Bible';

        // Build verse reference string
        $ref = $is_range ? "{$book_name} {$chapter}:{$vfrom}-{$vto}" : "{$book_name} {$chapter}:{$vfrom}";

        // HTML URL for this chapter (from pre-generated JSON, or construct from slug/book/chapter)
        $chapter_html_url = $data['_meta']['navigation']['htmlUrl']
            ?? "{$site_url}/{$slug}/{$book}/{$chapter}/";

        // Build cross-references to same verse(s) in other translations (JSON + HTML)
        $cross_refs = [];
        $all_slugs  = [ 'bible', 'bibel', 'latin' ];
        $verse_path = $is_range ? "{$vfrom}-{$vto}.json" : "{$vfrom}.json";
        foreach ( $all_slugs as $ds ) {
            if ( $ds === $slug ) { continue; }
            $cross_refs[ $ds ] = "{$site_url}/{$ds}/{$book}/{$chapter}/{$verse_path}";
            // HTML URL for the chapter page with verse highlight
            $vq = $is_range
                ? "?dwbible_vfrom={$vfrom}&dwbible_vto={$vto}"
                : "?dwbible_vfrom={$vfrom}";
            $cross_refs[ "{$ds}HtmlUrl" ] = "{$site_url}/{$ds}/{$book}/{$chapter}/{$vq}";
        }

        // Build navigation links (JSON API + human-readable HTML)
        $total_verses = count( $data['verses'] );
        $verse_qs = $is_range
            ? "?dwbible_vfrom={$vfrom}&dwbible_vto={$vto}"
            : "?dwbible_vfrom={$vfrom}";
        $nav = [
            'htmlUrl'          => $chapter_html_url . $verse_qs,
            'chapterJson'      => "{$site_url}/{$slug}/{$book}/{$chapter}.json",
            'chapterHtmlUrl'   => $chapter_html_url,
            'bookIndex'        => $data['_meta']['navigation']['bookIndex'] ?? null,
            'bookHtmlUrl'      => $data['_meta']['navigation']['bookHtmlUrl'] ?? null,
            'translationIndex' => $data['_meta']['navigation']['translationIndex'] ?? null,
            'translationHtmlUrl' => $data['_meta']['navigation']['translationHtmlUrl'] ?? null,
        ];
        // Previous verse (or end of range)
        if ( $vfrom > 1 ) {
            $nav['previousVerse'] = "{$site_url}/{$slug}/{$book}/{$chapter}/" . ( $vfrom - 1 ) . '.json';
        }
        // Next verse
        $next_v = $is_range ? $vto + 1 : $vfrom + 1;
        if ( $next_v <= $total_verses ) {
            $nav['nextVerse'] = "{$site_url}/{$slug}/{$book}/{$chapter}/{$next_v}.json";
        }

        $response = [
            '_meta' => [
                'project'         => 'Latin Prayer',
                'projectUrl'      => $site_url,
                'apiDocs'         => $site_url . '/llms.txt',
                'content'         => "{$ref} ({$bible_name})",
                'translation'     => $data['_meta']['translation'] ?? [],
                'book'            => $data['_meta']['book'] ?? [],
                'chapter'         => $chapter,
                'verseRange'      => $is_range ? [ $vfrom, $vto ] : $vfrom,
                'totalChapterVerses' => $total_verses,
                'navigation'      => $nav,
                'crossReferences' => $cross_refs,
            ],
            'citation' => "{$ref} ({$bible_name})",
            'verses'   => $matched,
        ];

        // For single verse, also include top-level text for convenience
        if ( ! $is_range && count( $matched ) === 1 ) {
            $response['verse'] = $matched[0]['verse'];
            $response['text']  = $matched[0]['text'];
        }

        self::send_json_headers();
        echo json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * All Bible editions the JSON API can serve, keyed by URL slug.
     *
     * Single source of truth for the dataset list — used by the unified
     * index and by the 404 hints, so both always advertise the same
     * translations.
     */
    private static function json_datasets(): array {
        return [
            'latin'   => [ 'name' => 'Clementine Vulgate', 'language' => 'la', 'languageName' => 'Latin' ],
            'bible'   => [ 'name' => 'Douay-Rheims',       'language' => 'en', 'languageName' => 'English' ],
            'bibel'   => [ 'name' => 'Menge',              'language' => 'de', 'languageName' => 'German' ],
            'french'  => [ 'name' => 'Crampon',            'language' => 'fr', 'languageName' => 'French' ],
            'spanish' => [ 'name' => 'Straubinger',        'language' => 'es', 'languageName' => 'Spanish' ],
            'italian' => [ 'name' => 'Martini',            'language' => 'it', 'languageName' => 'Italian' ],
        ];
    }

    /**
     * Send standard JSON response headers.
     */
    private static function send_json_headers() {
        status_header( 200 );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Powered-By: Latin Prayer (latinprayer.org)' );
    }

    /**
     * Send a 404 JSON error response.
     *
     * @param array $context Optional ['requested' => [...]] context — included
     *                       in the response so AI agents can self-correct.
     */
    private static function serve_json_404( array $context = [] ) {
        status_header( 404 );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );

        // Advertise every edition the API serves (built from the shared
        // dataset list so the hints never lag behind new translations).
        $translation_slugs = [];
        foreach ( self::json_datasets() as $ds_slug => $ds_meta ) {
            $translation_slugs[ $ds_slug ] = $ds_meta['languageName'] . ' (' . $ds_meta['name'] . ')';
        }

        $site_url = site_url();
        $body = [
            'error'   => 'NOT_FOUND',
            'message' => 'The requested Bible content was not found.',
            'help'    => $site_url . '/llms.txt',
            'hints'   => [
                'unifiedIndex'    => $site_url . '/bible-index.json',
                'translationSlugs' => $translation_slugs,
                'urlPattern'      => '/{slug}/{book}/{chapter}/{verse}.json',
                'example'         => $site_url . '/bible/genesis/1/1.json',
                'tip'             => 'Book slugs accept canonical names (genesis, john, psalms), dataset names (psalmen, matthaeus), and standard abbreviations (gen, jn, ps, 1cor). Use the unified index to discover valid slugs in one fetch.',
            ],
        ];
        if ( ! empty( $context['requested'] ) && is_array( $context['requested'] ) ) {
            $body['requested'] = $context['requested'];
        }
        echo json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Serve a unified index: all 73 books with URLs for all six translations.
     *
     * One fetch resolves every book path across latin, bible, bibel, spanish,
     * french, and italian — including the divergent German slugs (e.g.
     * sprueche, matthaeus) and the Italian book-order offset.
     */
    private static function serve_unified_index() {
        // All six editions the site serves (la/en/de/fr/es/it). Any dataset whose
        // index.json is absent on disk is skipped below, so this list is safe even
        // if a translation isn't deployed yet.
        $datasets = self::json_datasets();

        // Load each translation's index.json (canonical slugs, JSON API URLs)
        $data_dir = dwbible_data_dir();
        $indexes  = [];
        foreach ( array_keys( $datasets ) as $ds ) {
            $file = $data_dir . $ds . '/json/index.json';
            if ( ! file_exists( $file ) ) { continue; }
            $parsed = json_decode( file_get_contents( $file ), true );
            if ( is_array( $parsed ) && ! empty( $parsed['books'] ) ) {
                $indexes[ $ds ] = $parsed['books'];
            }
        }

        // Load dataset-specific display names → HTML slugs, keyed by that dataset's
        // own order number (order → html slug). NOTE: order numbers are NOT
        // consistent across datasets (e.g. the Italian index offsets ~27 books by
        // +2), so we resolve the HTML slug via each dataset's OWN order below —
        // never a shared order.
        $html_slugs = [];
        foreach ( array_keys( $datasets ) as $ds ) {
            $csv_books = self::load_dataset_index( $ds );
            foreach ( $csv_books as $b ) {
                $order = intval( $b['order'] );
                $html_slugs[ $ds ][ $order ] = self::slugify( $b['short_name'] );
            }
        }

        // Build per-book lookup keyed by CANONICAL SLUG (order is unreliable across
        // datasets; the canonical slug is identical in all six). Track the canonical
        // (Latin) order per slug for stable sorting.
        $by_slug     = [];
        $slug_order  = [];
        foreach ( $indexes as $ds => $books ) {
            foreach ( $books as $b ) {
                $slug = isset( $b['slug'] ) ? (string) $b['slug'] : '';
                if ( $slug === '' ) { continue; }
                $by_slug[ $slug ][ $ds ] = $b;
                // The first dataset in $datasets is 'latin' → its order is canonical.
                if ( ! isset( $slug_order[ $slug ] ) ) {
                    $slug_order[ $slug ] = intval( $b['order'] );
                }
            }
        }
        // Sort books by canonical order.
        uksort( $by_slug, function ( $a, $b ) use ( $slug_order ) {
            return ( $slug_order[ $a ] ?? PHP_INT_MAX ) <=> ( $slug_order[ $b ] ?? PHP_INT_MAX );
        } );

        // Merge into unified book list.
        $site_url = site_url();
        $books = [];
        foreach ( $by_slug as $slug => $ds_books ) {
            // Prefer Latin as the canonical source of order/testament/totalChapters,
            // else fall back to whichever dataset is present first.
            $first = $ds_books['latin'] ?? reset( $ds_books );
            $entry = [
                'order'         => intval( $first['order'] ),
                'canonicalSlug' => $slug,
                'testament'     => $first['testament'],
                'totalChapters' => $first['totalChapters'],
                'translations'  => [],
            ];
            foreach ( $datasets as $ds => $meta ) {
                if ( ! isset( $ds_books[ $ds ] ) ) { continue; }
                $b = $ds_books[ $ds ];
                // Resolve the HTML slug via THIS dataset's own order number.
                $ds_order = intval( $b['order'] );
                $ds_slug  = isset( $html_slugs[ $ds ][ $ds_order ] ) ? $html_slugs[ $ds ][ $ds_order ] : $b['slug'];
                $entry['translations'][ $ds ] = [
                    'name'    => $b['name'],
                    'slug'    => $ds_slug,
                    'url'     => $site_url . '/' . $ds . '/' . $ds_slug . '/',
                    'jsonUrl' => $b['jsonUrl'],
                ];
            }
            $books[] = $entry;
        }

        // Only advertise the translations actually loaded (index.json present on disk).
        $loaded = array_intersect_key( $datasets, $indexes );
        $translation_count = count( $loaded );
        $response = [
            '_meta' => [
                'project'    => 'Latin Prayer',
                'projectUrl' => $site_url,
                'apiDocs'    => $site_url . '/llms.txt',
                'content'    => sprintf(
                    'Unified Bible index — %d books × %d translations',
                    count( $books ),
                    $translation_count
                ),
                'translations' => $loaded,
                'bookCount'  => count( $books ),
            ],
            'books' => $books,
        ];

        status_header( 200 );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Powered-By: Latin Prayer (latinprayer.org)' );
        echo json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Resolve a dataset-specific URL slug to the canonical book_map.json key
     * used as the JSON directory name.
     *
     * Example: "psalmen" (bibel URL slug) → "psalms" (JSON dir key).
     *
     * Uses book_map.json which maps canonical keys to dataset display names.
     * We build a reverse lookup: slugify(display_name) → canonical_key.
     *
     * @param string $url_slug The slug from the URL (e.g. "psalmen").
     * @param string $dataset  The dataset slug ("bible", "bibel", "latin").
     * @return string|null     The canonical key, or null if not found.
     */
    private static function resolve_json_book_slug( $url_slug, $dataset ) {
        static $reverse_maps = [];

        if ( ! isset( $reverse_maps[ $dataset ] ) ) {
            $reverse_maps[ $dataset ] = [];
            $book_map = DwBible_Mappings_Loader::load_book_map();
            $json_dir = dwbible_data_dir() . $dataset . '/json/';

            // First pass: add entries whose canonical key has a real JSON directory.
            // These are authoritative and won't be overwritten.
            foreach ( $book_map as $canonical_key => $datasets_map ) {
                if ( ! is_dir( $json_dir . $canonical_key ) ) { continue; }
                $reverse_maps[ $dataset ][ $canonical_key ] = $canonical_key;
                if ( isset( $datasets_map[ $dataset ] ) ) {
                    $ds_slug = self::slugify( $datasets_map[ $dataset ] );
                    if ( $ds_slug !== '' ) {
                        $reverse_maps[ $dataset ][ $ds_slug ] = $canonical_key;
                    }
                }
            }

            // Second pass: add aliases and cross-dataset names as fallbacks.
            // Never overwrite an existing entry (primary keys win).
            foreach ( $book_map as $canonical_key => $datasets_map ) {
                // Resolve alias keys to their target dir key
                $target = $canonical_key;
                if ( ! is_dir( $json_dir . $canonical_key ) ) {
                    // Alias: find the primary key that owns the same dataset display name
                    if ( isset( $datasets_map[ $dataset ] ) ) {
                        $ds_slug = self::slugify( $datasets_map[ $dataset ] );
                        $target = $reverse_maps[ $dataset ][ $ds_slug ] ?? $canonical_key;
                    }
                }
                // Add the alias key itself
                if ( ! isset( $reverse_maps[ $dataset ][ $canonical_key ] ) ) {
                    $reverse_maps[ $dataset ][ $canonical_key ] = $target;
                }
                // Add cross-dataset display names as fallback
                foreach ( $datasets_map as $ds => $display_name ) {
                    $alt_slug = self::slugify( $display_name );
                    if ( $alt_slug !== '' && ! isset( $reverse_maps[ $dataset ][ $alt_slug ] ) ) {
                        $reverse_maps[ $dataset ][ $alt_slug ] = $target;
                    }
                }
            }
        }

        return $reverse_maps[ $dataset ][ $url_slug ] ?? null;
    }
}
