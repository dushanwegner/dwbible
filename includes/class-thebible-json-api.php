<?php
/**
 * TheBible — JSON API Trait
 *
 * Serves pre-generated, self-documenting JSON files for AI consumption.
 * Each JSON file contains a _meta object describing what it is, which
 * translation/book/chapter it represents, and how to navigate to related
 * content — making the Catholic Bible fully accessible to AI agents.
 *
 * Also serves llms.txt and llms-full.txt (the AI entry-point documents).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_JSON_API_Trait {

    /**
     * Serve a pre-generated JSON file based on current query vars.
     *
     * Route resolution:
     *   - No book, no chapter → data/{slug}/json/index.json
     *   - Book, no chapter    → data/{slug}/json/{book}/index.json
     *   - Book + chapter      → data/{slug}/json/{book}/{chapter}.json
     */
    private static function serve_json_file() {
        $slug = get_query_var( self::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) {
            $slug = 'bible';
        }

        $book    = get_query_var( self::QV_BOOK );
        $chapter = get_query_var( self::QV_CHAPTER );

        // Sanitize inputs: only allow safe characters
        $slug    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $slug ) );
        $book    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $book ) );
        $chapter = preg_replace( '/[^0-9]/', '', $chapter );

        // Resolve dataset-specific book slugs (e.g. "psalmen" → "psalms")
        // to the canonical key used in the JSON file directory structure.
        // The JSON dirs use book_map.json keys (genesis, psalms, matthew, ...)
        // while HTML URLs may use dataset-specific slugs (psalmen, matthaeus, ...).
        if ( ! empty( $book ) ) {
            $base_test = plugin_dir_path( __FILE__ ) . '../data/' . $slug . '/json/';
            if ( ! is_dir( $base_test . $book ) ) {
                $canonical_key = self::resolve_json_book_slug( $book, $slug );
                if ( $canonical_key !== null ) {
                    $book = $canonical_key;
                }
            }
        }

        // Build file path
        $base = plugin_dir_path( __FILE__ ) . '../data/' . $slug . '/json/';

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
            status_header( 404 );
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'Access-Control-Allow-Origin: *' );
            echo json_encode( [
                'error'   => 'Not found',
                'message' => 'The requested Bible content was not found.',
                'help'    => 'See https://latinprayer.org/llms.txt for API documentation.',
            ] );
            exit;
        }

        // Serve the static file with appropriate headers
        status_header( 200 );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Powered-By: Latin Prayer (latinprayer.org)' );
        readfile( $file );
        exit;
    }

    /**
     * Serve a unified index: all 73 books with URLs for all three translations.
     *
     * One fetch resolves every book path across latin, bible, and bibel —
     * including the divergent German slugs (e.g. sprueche, matthaeus).
     */
    private static function serve_unified_index() {
        $datasets = [
            'latin' => [ 'name' => 'Clementine Vulgate', 'language' => 'la', 'languageName' => 'Latin' ],
            'bible' => [ 'name' => 'Douay-Rheims',       'language' => 'en', 'languageName' => 'English' ],
            'bibel' => [ 'name' => 'Menge',              'language' => 'de', 'languageName' => 'German' ],
        ];

        // Load each translation's index.json (canonical slugs, JSON API URLs)
        $data_dir = plugin_dir_path( __FILE__ ) . '../data/';
        $indexes  = [];
        foreach ( array_keys( $datasets ) as $ds ) {
            $file = $data_dir . $ds . '/json/index.json';
            if ( ! file_exists( $file ) ) { continue; }
            $parsed = json_decode( file_get_contents( $file ), true );
            if ( is_array( $parsed ) && ! empty( $parsed['books'] ) ) {
                $indexes[ $ds ] = $parsed['books'];
            }
        }

        // Load dataset-specific display names → HTML slugs (may differ from canonical)
        $html_slugs = [];
        foreach ( array_keys( $datasets ) as $ds ) {
            $csv_books = self::load_dataset_index( $ds );
            foreach ( $csv_books as $b ) {
                $order = intval( $b['order'] );
                $html_slugs[ $ds ][ $order ] = self::slugify( $b['short_name'] );
            }
        }

        // Build per-book lookup keyed by order number for each dataset
        $by_order = [];
        foreach ( $indexes as $ds => $books ) {
            foreach ( $books as $b ) {
                $order = intval( $b['order'] );
                $by_order[ $order ][ $ds ] = $b;
            }
        }

        // Merge into unified book list
        $site_url = site_url();
        $books = [];
        ksort( $by_order );
        foreach ( $by_order as $order => $ds_books ) {
            $first = reset( $ds_books );
            $entry = [
                'order'         => $order,
                'canonicalSlug' => $first['slug'],
                'testament'     => $first['testament'],
                'totalChapters' => $first['totalChapters'],
                'translations'  => [],
            ];
            foreach ( $datasets as $ds => $meta ) {
                if ( ! isset( $ds_books[ $ds ] ) ) { continue; }
                $b = $ds_books[ $ds ];
                $ds_slug = isset( $html_slugs[ $ds ][ $order ] ) ? $html_slugs[ $ds ][ $order ] : $b['slug'];
                $entry['translations'][ $ds ] = [
                    'name'    => $b['name'],
                    'slug'    => $ds_slug,
                    'url'     => $site_url . '/' . $ds . '/' . $ds_slug . '/',
                    'jsonUrl' => $b['jsonUrl'],
                ];
            }
            $books[] = $entry;
        }

        $response = [
            '_meta' => [
                'project'    => 'Latin Prayer',
                'projectUrl' => $site_url,
                'apiDocs'    => $site_url . '/llms.txt',
                'content'    => 'Unified Bible index — 73 books × 3 translations',
                'translations' => $datasets,
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
     * Serve the llms.txt or llms-full.txt AI documentation file.
     *
     * @param string $variant 'llms' or 'llms-full'
     */
    private static function serve_llms_txt( $variant = 'llms' ) {
        $filename = ( $variant === 'llms-full' ) ? 'llms-full.txt' : 'llms.txt';
        $file = plugin_dir_path( __FILE__ ) . '../data/' . $filename;

        if ( ! file_exists( $file ) ) {
            status_header( 404 );
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo "Not found. See https://latinprayer.org/ for more information.\n";
            exit;
        }

        status_header( 200 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $file );
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
            $book_map = TheBible_Mappings_Loader::load_book_map();
            $json_dir = plugin_dir_path( __FILE__ ) . '../data/' . $dataset . '/json/';

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
