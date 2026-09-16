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
     * What this page is, for the structured data: the dataset it shows, its
     * language, the edition's name, and the label the breadcrumb root carries.
     *
     * DERIVED, not listed. Three hand-written maps used to hold this and they
     * covered only bible/bibel/latin — Spanish, French and Italian were never
     * added, and the interlinear combo slugs (which is what every canonical
     * page uses) matched none of them, so the pages a reader and a crawler
     * actually land on carried no Bible markup at all while the deliberately
     * noindexed /latin/ surface carried all of it.
     *
     * @return array{dataset:string,lang:string,name:string,label:string}|null
     */
    private static function edition_for_slug( string $slug ): ?array {
        $sets = DwBible_Plugin::json_datasets_public();
        // An interlinear combo, a language-code form or a translation alias all
        // resolve through the same bridge the JSON API uses.
        $dataset = isset( $sets[ $slug ] ) ? $slug
            : ( function_exists( 'dwbible_i18n_json_dataset_for_slug' ) ? dwbible_i18n_json_dataset_for_slug( $slug ) : '' );
        if ( ! isset( $sets[ $dataset ] ) ) { return null; }
        $meta = $sets[ $dataset ];
        return [
            'dataset' => $dataset,
            'lang'    => $meta['language'],
            'name'    => $meta['name'],
            'label'   => $meta['name'] . ' (' . $meta['languageName'] . ')',
        ];
    }

    /**
     * The address a crawler should be given for a Bible page.
     *
     * The canonical page of everything except the Latin-only surface is
     * /{lang}/biblia/{latin-slug}/…, so that is what the markup names; /latin/
     * is its own canonical and keeps its own URLs.
     */
    private static function page_url( string $dataset, string $lang, string $book = '', int $chapter = 0 ): string {
        if ( $dataset === 'latin' ) {
            $path = '/latin/' . ( $book !== '' ? $book . '/' : '' ) . ( $chapter > 0 ? $chapter . '/' : '' );
            return home_url( $path );
        }
        if ( $book === '' ) {
            return DwBible_Plugin::agent_html_url( $lang, 'genesis' ) === '' ? home_url( '/' ) : preg_replace( '#/[^/]+/$#', '/', DwBible_Plugin::agent_html_url( $lang, 'genesis' ) );
        }
        $key = DwBible_Plugin::key_from_any_book_slug( $book ) ?? $book;
        return DwBible_Plugin::agent_html_url( $lang, $key, $chapter );
    }

    /**
     * Shared publisher object (reused across all page types).
     */
    /** The breadcrumb root's label for a dataset. */
    private static function edition_label( string $dataset, string $fallback ): string {
        $e = self::edition_for_slug( $dataset );
        return $e ? $e['label'] : $fallback;
    }

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

        $edition = self::edition_for_slug( (string) $slug );
        if ( $edition === null ) {
            return;
        }

        $book    = get_query_var( DwBible_Plugin::QV_BOOK );
        $chapter = get_query_var( DwBible_Plugin::QV_CHAPTER );
        $slug    = $edition['dataset'];
        $lang    = $edition['lang'];
        $bible   = $edition['name'];

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
        // The CITATION name first — data/book_names.json is where one name per
        // book per language lives, and it is what every other surface quotes.
        // book_map holds slug-shaped forms, so the German page read "Matthaeus"
        // where the book is called Matthäus.
        $key  = DwBible_Plugin::key_from_any_book_slug( $book_slug );
        $edn  = self::edition_for_slug( (string) $slug );
        if ( $key !== null && $edn !== null ) {
            $names = DwBible_Plugin::book_citation_names( $key );
            if ( ! empty( $names[ $edn['lang'] ] ) ) {
                return (string) $names[ $edn['lang'] ];
            }
        }

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
        $index_label = self::edition_label( $slug, $bible );

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
                            'item'     => self::page_url( $slug, $lang ),
                        ],
                        [
                            '@type'    => 'ListItem',
                            'position' => 2,
                            'name'     => $book_name,
                            'item'     => self::page_url( $slug, $lang, $book ),
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
                    'url'        => self::page_url( $slug, $lang, $book, (int) $chapter ),
                    'publisher'  => self::publisher(),
                    'isPartOf'   => [
                        '@type'      => 'Book',
                        'name'       => $book_name,
                        'url'        => self::page_url( $slug, $lang, $book ),
                        'inLanguage' => $lang,
                        'isPartOf'   => [
                            '@type' => 'Book',
                            'name'  => $bible,
                            'url'   => self::page_url( $slug, $lang ),
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
        $index_label = self::edition_label( $slug, $bible );

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
                            'item'     => self::page_url( $slug, $lang ),
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
                    'url'        => self::page_url( $slug, $lang, $book ),
                    'publisher'  => self::publisher(),
                    'isPartOf'   => [
                        '@type' => 'Book',
                        'name'  => $bible,
                        'url'   => self::page_url( $slug, $lang ),
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
                    'url'   => self::page_url( $slug, $lang ),
                    'inLanguage'  => $lang,
                    'description' => "Complete {$bible} — 73 books of the Catholic Bible online, with JSON API for AI agents.",
                    'publisher'   => self::publisher(),
                ],
                [
                    '@type'      => 'Book',
                    'name'       => $bible,
                    'inLanguage' => $lang,
                    'url'        => self::page_url( $slug, $lang ),
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
