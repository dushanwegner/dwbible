<?php
/**
 * TheBible — JSON-LD Structured Data
 *
 * Outputs Schema.org JSON-LD markup on Bible HTML pages so search engines
 * and AI agents understand the content type and hierarchical structure.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_JsonLd {

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
     * Print JSON-LD structured data on Bible pages.
     * Hooked to wp_head.
     */
    public static function print_jsonld() {
        $flag = get_query_var( TheBible_Plugin::QV_FLAG );
        if ( empty( $flag ) ) {
            return;
        }
        // Skip JSON/sitemap/OG requests
        $format = get_query_var( TheBible_Plugin::QV_FORMAT );
        if ( ! empty( $format ) ) {
            return;
        }

        $slug = get_query_var( TheBible_Plugin::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) { $slug = 'bible'; }

        // Only handle single-dataset slugs (not interlinear combos)
        if ( ! isset( self::$translations[ $slug ] ) ) {
            return;
        }

        $book    = get_query_var( TheBible_Plugin::QV_BOOK );
        $chapter = get_query_var( TheBible_Plugin::QV_CHAPTER );
        $lang    = self::$languages[ $slug ] ?? 'en';
        $bible   = self::$translations[ $slug ] ?? 'Bible';

        if ( ! empty( $book ) && ! empty( $chapter ) ) {
            // Chapter page
            $jsonld = self::chapter_jsonld( $slug, $book, (int) $chapter, $lang, $bible );
        } elseif ( ! empty( $book ) ) {
            // Book page (defaults to chapter 1)
            $jsonld = self::book_jsonld( $slug, $book, $lang, $bible );
        } else {
            // Bible index page
            $jsonld = self::index_jsonld( $slug, $lang, $bible );
        }

        if ( $jsonld ) {
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
            echo "\n</script>\n";
        }
    }

    /**
     * JSON-LD for a chapter page.
     */
    private static function chapter_jsonld( $slug, $book, $chapter, $lang, $bible ) {
        $book_name = ucwords( str_replace( '-', ' ', $book ) );
        return [
            '@context'  => 'https://schema.org',
            '@type'     => 'Chapter',
            'name'      => "{$book_name} {$chapter}",
            'position'  => $chapter,
            'inLanguage' => $lang,
            'url'       => home_url( "/{$slug}/{$book}/{$chapter}" ),
            'isPartOf'  => [
                '@type' => 'Book',
                'name'  => $book_name,
                'url'   => home_url( "/{$slug}/{$book}/" ),
                'isPartOf' => [
                    '@type' => 'Book',
                    'name'  => $bible,
                    'url'   => home_url( "/{$slug}/" ),
                ],
            ],
            'encoding' => [
                '@type'      => 'MediaObject',
                'contentUrl' => home_url( "/{$slug}/{$book}/{$chapter}.json" ),
                'encodingFormat' => 'application/json',
            ],
        ];
    }

    /**
     * JSON-LD for a book page.
     */
    private static function book_jsonld( $slug, $book, $lang, $bible ) {
        $book_name = ucwords( str_replace( '-', ' ', $book ) );
        return [
            '@context'  => 'https://schema.org',
            '@type'     => 'Book',
            'name'      => $book_name,
            'inLanguage' => $lang,
            'url'       => home_url( "/{$slug}/{$book}/" ),
            'isPartOf'  => [
                '@type' => 'Book',
                'name'  => $bible,
                'url'   => home_url( "/{$slug}/" ),
            ],
            'encoding' => [
                '@type'      => 'MediaObject',
                'contentUrl' => home_url( "/{$slug}/{$book}/index.json" ),
                'encodingFormat' => 'application/json',
            ],
        ];
    }

    /**
     * JSON-LD for the Bible index page.
     */
    private static function index_jsonld( $slug, $lang, $bible ) {
        return [
            '@context'  => 'https://schema.org',
            '@type'     => 'Book',
            'name'      => $bible,
            'inLanguage' => $lang,
            'url'       => home_url( "/{$slug}/" ),
            'genre'     => 'Religion',
            'about'     => [
                '@type' => 'Thing',
                'name'  => 'Catholic Bible',
            ],
            'encoding' => [
                '@type'      => 'MediaObject',
                'contentUrl' => home_url( "/{$slug}/index.json" ),
                'encodingFormat' => 'application/json',
            ],
        ];
    }
}
