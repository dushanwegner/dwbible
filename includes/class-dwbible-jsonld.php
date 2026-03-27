<?php
/**
 * DwBible — JSON-LD Structured Data
 *
 * Outputs Schema.org JSON-LD markup on Bible HTML pages so search engines
 * and AI agents understand the content type, hierarchical structure, and
 * relationships between translations. Uses @graph to combine multiple
 * schema types (BreadcrumbList + content type) in a single block.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_JsonLd {

    /**
     * Language code for each dataset slug.
     */
    private static $languages = [
        'bible' => 'en',
        'bibel' => 'de',
        'latin' => 'la',
    ];

    /**
     * Translation name for each dataset slug.
     */
    private static $translations = [
        'bible' => 'Douay-Rheims Bible',
        'bibel' => 'Menge-Bibel',
        'latin' => 'Clementine Vulgate',
    ];

    /**
     * Human-readable translation labels for breadcrumb root.
     */
    private static $index_labels = [
        'bible' => 'Bible (Douay-Rheims)',
        'bibel' => 'Bibel (Menge)',
        'latin' => 'Bible (Vulgate)',
    ];

    /**
     * Shared publisher object (reused across all page types).
     */
    private static function publisher() {
        return [
            '@type' => 'Organization',
            'name'  => 'Latin Prayer',
            'url'   => 'https://latinprayer.org',
        ];
    }

    /**
     * Print JSON-LD structured data on Bible pages.
     * Hooked to wp_head.
     */
    public static function print_jsonld() {
        $flag = get_query_var( DwBible_Plugin::QV_FLAG );
        if ( empty( $flag ) ) {
            return;
        }
        // Skip JSON/sitemap/OG requests
        $format = get_query_var( DwBible_Plugin::QV_FORMAT );
        if ( ! empty( $format ) ) {
            return;
        }

        $slug = get_query_var( DwBible_Plugin::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) { $slug = 'bible'; }

        // Only handle single-dataset slugs (not interlinear combos)
        if ( ! isset( self::$translations[ $slug ] ) ) {
            return;
        }

        $book    = get_query_var( DwBible_Plugin::QV_BOOK );
        $chapter = get_query_var( DwBible_Plugin::QV_CHAPTER );
        $lang    = self::$languages[ $slug ] ?? 'en';
        $bible   = self::$translations[ $slug ] ?? 'Bible';

        if ( ! empty( $book ) && ! empty( $chapter ) ) {
            $jsonld = self::chapter_jsonld( $slug, $book, (int) $chapter, $lang, $bible );
        } elseif ( ! empty( $book ) ) {
            $jsonld = self::book_jsonld( $slug, $book, $lang, $bible );
        } else {
            $jsonld = self::index_jsonld( $slug, $lang, $bible );
        }

        if ( $jsonld ) {
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
            echo "\n</script>\n";
        }
    }

    /**
     * Resolve a book slug to a proper display name.
     *
     * Tries the book_map.json first (dataset-specific name),
     * falls back to ucwords on the slug.
     */
    private static function book_display_name( $book_slug, $slug ) {
        static $book_map = null;
        if ( $book_map === null ) {
            $file = dwbible_data_dir() . 'book_map.json';
            $book_map = file_exists( $file )
                ? ( json_decode( file_get_contents( $file ), true ) ?: [] )
                : [];
        }

        // Try canonical key → dataset display name
        if ( isset( $book_map[ $book_slug ][ $slug ] ) ) {
            $name = $book_map[ $book_slug ][ $slug ];
            // Normalize separators (underscores + hyphens) and capitalize
            return ucwords( str_replace( [ '-', '_' ], ' ', $name ) );
        }
        // Fallback: humanize the slug
        return ucwords( str_replace( [ '-', '_' ], ' ', $book_slug ) );
    }

    /**
     * JSON-LD for a chapter page — @graph with BreadcrumbList + Chapter.
     */
    private static function chapter_jsonld( $slug, $book, $chapter, $lang, $bible ) {
        $book_name   = self::book_display_name( $book, $slug );
        $index_label = self::$index_labels[ $slug ] ?? $bible;

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [
                // Breadcrumb: Bible > Book > Chapter
                [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => [
                        [
                            '@type'    => 'ListItem',
                            'position' => 1,
                            'name'     => $index_label,
                            'item'     => home_url( "/{$slug}/" ),
                        ],
                        [
                            '@type'    => 'ListItem',
                            'position' => 2,
                            'name'     => $book_name,
                            'item'     => home_url( "/{$slug}/{$book}/" ),
                        ],
                        [
                            '@type'    => 'ListItem',
                            'position' => 3,
                            'name'     => "Chapter {$chapter}",
                        ],
                    ],
                ],
                // Main content
                [
                    '@type'      => 'Chapter',
                    'name'       => "{$book_name} {$chapter}",
                    'position'   => $chapter,
                    'inLanguage' => $lang,
                    'url'        => home_url( "/{$slug}/{$book}/{$chapter}" ),
                    'publisher'  => self::publisher(),
                    'isPartOf'   => [
                        '@type'      => 'Book',
                        'name'       => $book_name,
                        'url'        => home_url( "/{$slug}/{$book}/" ),
                        'inLanguage' => $lang,
                        'isPartOf'   => [
                            '@type' => 'Book',
                            'name'  => $bible,
                            'url'   => home_url( "/{$slug}/" ),
                        ],
                    ],
                    'encoding' => [
                        '@type'          => 'MediaObject',
                        'contentUrl'     => home_url( "/{$slug}/{$book}/{$chapter}.json" ),
                        'encodingFormat' => 'application/json',
                    ],
                ],
            ],
        ];
    }

    /**
     * JSON-LD for a book page — @graph with BreadcrumbList + Book.
     */
    private static function book_jsonld( $slug, $book, $lang, $bible ) {
        $book_name   = self::book_display_name( $book, $slug );
        $index_label = self::$index_labels[ $slug ] ?? $bible;

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => [
                        [
                            '@type'    => 'ListItem',
                            'position' => 1,
                            'name'     => $index_label,
                            'item'     => home_url( "/{$slug}/" ),
                        ],
                        [
                            '@type'    => 'ListItem',
                            'position' => 2,
                            'name'     => $book_name,
                        ],
                    ],
                ],
                [
                    '@type'      => 'Book',
                    'name'       => $book_name,
                    'inLanguage' => $lang,
                    'url'        => home_url( "/{$slug}/{$book}/" ),
                    'publisher'  => self::publisher(),
                    'isPartOf'   => [
                        '@type' => 'Book',
                        'name'  => $bible,
                        'url'   => home_url( "/{$slug}/" ),
                    ],
                    'encoding' => [
                        '@type'          => 'MediaObject',
                        'contentUrl'     => home_url( "/{$slug}/{$book}/index.json" ),
                        'encodingFormat' => 'application/json',
                    ],
                ],
            ],
        ];
    }

    /**
     * JSON-LD for the Bible index page — Book + WebPage.
     */
    private static function index_jsonld( $slug, $lang, $bible ) {
        return [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'WebPage',
                    'name'  => $bible,
                    'url'   => home_url( "/{$slug}/" ),
                    'inLanguage'  => $lang,
                    'description' => "Complete {$bible} — 73 books of the Catholic Bible online, with JSON API for AI agents.",
                    'publisher'   => self::publisher(),
                ],
                [
                    '@type'      => 'Book',
                    'name'       => $bible,
                    'inLanguage' => $lang,
                    'url'        => home_url( "/{$slug}/" ),
                    'genre'      => 'Religion',
                    'publisher'  => self::publisher(),
                    'about'      => [
                        '@type' => 'Thing',
                        'name'  => 'Catholic Bible',
                    ],
                    'encoding' => [
                        '@type'          => 'MediaObject',
                        'contentUrl'     => home_url( "/{$slug}/index.json" ),
                        'encodingFormat' => 'application/json',
                    ],
                ],
            ],
        ];
    }
}
