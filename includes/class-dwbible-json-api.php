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

        // Malachias 4 on a constructed URL: /latin/malachias/4/2.json 404'd while
        // the HTML page for the same citation resolved. One book, one rule, every
        // surface.
        $read_as = null;
        if ( $book === 'malachias' && (int) $chapter === 4 ) {
            $mal = self::agent_malachias_shim( 'malachias', 4, $vfrom, $vto );
            if ( $mal !== null ) {
                $chapter = (string) $mal['chapter'];
                if ( $vfrom > 0 ) { $vfrom = $mal['from']; $vto = $vto > 0 ? $mal['to'] : $vfrom; }
                $read_as = $mal['note'];
            }
        }

        // ?numbering=hebrew on a Psalms URL: the reader named the Masoretic psalm,
        // so serve the Vulgate chapter that holds it and SAY so — never a
        // different psalm in silence. An unknown value is a 400 (agent_numbering_mode).
        $numbering  = self::agent_numbering_mode();
        $psalm_meta = null;
        if ( $book === 'psalms' && $chapter !== '' ) {
            $pr = self::agent_psalm_request( (int) $chapter, $numbering );
            if ( $pr === null ) {
                self::agent_error( 404, 'CHAPTER_NOT_FOUND', "There is no Psalm {$chapter}.", [ 'suggestion' => 'Psalms run 1-150.' ] );
            }
            $chapter    = (string) $pr['chapter'];
            $psalm_meta = $pr['meta'];
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
            self::serve_verse_json( $file, $slug, $book, (int) $chapter, $vfrom, $vto, $psalm_meta, $read_as );
            exit;
        }

        // A psalm chapter asked for with a numbering parameter: the file is
        // decoded so the answer can state which numbering it honoured.
        if ( $psalm_meta !== null && $numbering !== '' ) {
            $data = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $data ) ) {
                $data['_meta']['psalmNumbering'] = $psalm_meta;
                self::send_json( $data );
                exit;
            }
        }

        // Serve the pre-generated file. This is the busiest path in the API — every
        // chapter and every index — so its validator is derived from the file's own
        // mtime and size rather than by hashing the bytes on each request.
        self::send_json_file( $file );
        exit;
    }

    /**
     * Serve a pre-generated JSON file, answering a conditional request when we can.
     *
     * The validator is `mtime-size`, which for a file written by the generator
     * changes whenever the content does — a regeneration rewrites the file, moving
     * the mtime even if the length is unchanged. That is why the file does not need
     * to be read to produce it, which matters: this path serves ~8.7 KB per chapter
     * and ~19.8 KB for the index.
     *
     * `Last-Modified` is sent alongside `ETag` so a client that only implements
     * `If-Modified-Since` is served too. Before this, neither header existed, so a
     * far-future `If-Modified-Since` still returned a full 200 body.
     *
     * @param string $file Absolute path to an existing JSON file.
     */
    private static function send_json_file( $file ) {
        $mtime = @filemtime( $file );
        $size  = @filesize( $file );

        if ( $mtime === false || $size === false ) {
            // Cannot validate it — serve it plainly rather than risk a wrong ETag.
            self::send_json_headers();
            readfile( $file );
            return;
        }

        $etag     = '"' . $mtime . '-' . $size . '"';
        $modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

        $inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
            ? (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] )
            : '';
        $ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
            ? (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
            : '';

        $fresh = false;
        if ( $inm !== '' ) {
            // If-None-Match takes precedence over If-Modified-Since (RFC 9110).
            foreach ( explode( ',', $inm ) as $candidate ) {
                $candidate = trim( $candidate );
                if ( str_starts_with( $candidate, 'W/' ) ) {
                    $candidate = substr( $candidate, 2 );
                }
                if ( $candidate === $etag || $candidate === '*' ) {
                    $fresh = true;
                    break;
                }
            }
        } elseif ( $ims !== '' ) {
            $since = strtotime( $ims );
            if ( $since !== false && $mtime <= $since ) {
                $fresh = true;
            }
        }

        self::send_json_headers();
        header( 'ETag: ' . $etag );
        header( 'Last-Modified: ' . $modified );

        if ( $fresh ) {
            status_header( 304 );
            header_remove( 'Content-Type' );
            return;
        }

        readfile( $file );
    }

    /**
     * Extract and serve a single verse or verse range from a chapter JSON file.
     *
     * Reads the pre-generated chapter file, filters to the requested verse(s),
     * and wraps them in a self-documenting response with navigation links.
     */
    private static function serve_verse_json( $chapter_file, $slug, $book, $chapter, $vfrom, $vto, $psalm_meta = null, $read_as = null ) {
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

        $lang       = (string) ( $data['_meta']['translation']['language'] ?? 'en' );
        $book_name  = self::agent_cite_name( (string) $book, $lang, (string) ( $data['_meta']['book']['name'] ?? ucwords( str_replace( '-', ' ', $book ) ) ) );
        $bible_name = $data['_meta']['translation']['name'] ?? 'Bible';
        $clean      = self::agent_typography_mode() === 'clean';

        // Filter verses. Each verse's citation is REBUILT from _meta.book.name
        // rather than copied from the file: the localizer (dwbibledata/tools/
        // localize_book_names.py) maintains _meta.book.name but not the per-verse
        // citations, so a Latin file could say "Iob" in its meta and "Job 20:12"
        // on its verses — two names for one book in one response.
        $matched = [];
        foreach ( $data['verses'] as $v ) {
            $num = (int) $v['verse'];
            if ( $num >= $vfrom && $num <= $vto ) {
                $text = (string) ( $v['text'] ?? '' );
                if ( $clean ) { $text = self::agent_clean_typography( $text ); }
                $matched[] = [
                    'verse'    => $num,
                    'text'     => $text,
                    'citation' => "{$book_name} {$chapter}:{$num} ({$bible_name})",
                ];
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

        // Build verse reference string
        $ref = $is_range ? "{$book_name} {$chapter}:{$vfrom}-{$vto}" : "{$book_name} {$chapter}:{$vfrom}";

        // Every HTML address is the CANONICAL page — /{lang}/biblia/{latin-slug}/{ch}:{v}/ —
        // which resolves in one hop. The dataset-slug form with ?dwbible_vfrom=
        // cost two 301s and kept the query on the final URL.
        $chapter_html_url = self::agent_html_url( $lang, (string) $book, (int) $chapter );
        $sets             = self::json_datasets();

        // Cross-references to the same verse(s) in the other translations (JSON + HTML)
        $cross_refs = [];
        $verse_path = $is_range ? "{$vfrom}-{$vto}.json" : "{$vfrom}.json";
        foreach ( array_keys( $sets ) as $ds ) {
            if ( $ds === $slug ) { continue; }
            $cross_refs[ $ds ] = "{$site_url}/{$ds}/{$book}/{$chapter}/{$verse_path}";
            $cross_refs[ "{$ds}HtmlUrl" ] = self::agent_html_url( (string) $sets[ $ds ]['language'], (string) $book, (int) $chapter, $vfrom, $vto );
        }

        // Navigation links (JSON API + human-readable HTML)
        $total_verses = count( $data['verses'] );
        $nav = [
            'htmlUrl'          => self::agent_html_url( $lang, (string) $book, (int) $chapter, $vfrom, $vto ),
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

        // The book block carries its identity in every language (citation names
        // from book_names.json) and its OSIS id, so a consumer never has to guess
        // how to cite the passage in another language or a standard abbreviation.
        $book_block = $data['_meta']['book'] ?? [];
        $identity   = self::agent_book_block( $book );
        $book_block['names'] = $identity['names'];
        $book_block['osis']  = $identity['osis'];

        // The canonical HTML page of exactly this passage (one hop, no redirect),
        // and this response's own address — the two things an agent links or refetches.
        $html_url = $nav['htmlUrl'];
        $json_url = self::agent_json_url( $slug, $book, $chapter, $vfrom, $vto );

        $meta = [
            'project'         => 'Latin Prayer',
            'projectUrl'      => $site_url,
            'apiDocs'         => $site_url . '/llms.txt',
            'content'         => "{$ref} ({$bible_name})",
            'translation'     => $data['_meta']['translation'] ?? [],
            'book'            => $book_block,
            'chapter'         => $chapter,
            'verseRange'      => $is_range ? [ $vfrom, $vto ] : $vfrom,
            'totalChapterVerses' => $total_verses,
            'typography'      => $clean ? 'clean' : 'source',
            'navigation'      => $nav,
            'crossReferences' => $cross_refs,
            'refJson'         => $site_url . '/bible-ref.json?q=' . rawurlencode( "{$book} {$chapter}:{$vfrom}" . ( $is_range ? "-{$vto}" : '' ) ) . '&lang=all',
        ];
        if ( $book === 'psalms' ) {
            $meta['psalmNumbering'] = $psalm_meta ?? self::psalm_numbering( (int) $chapter );
        }
        // A citation this API translated says so — the reader asked for
        // Malachias 4:2 and is being handed 3:20.
        if ( $read_as !== null ) { $meta['readAs'] = $read_as; }

        // Top-level convenience fields for single verses AND ranges alike: the
        // citation, the text (a range's verses joined by a space), the page, the
        // address. A range used to carry only the verses array, so a consumer had
        // to special-case it.
        $texts = [];
        foreach ( $matched as $mv ) { $texts[] = $mv['text']; }
        $response = [
            '_meta'    => $meta,
            'citation' => "{$ref} ({$bible_name})",
            'text'     => implode( ' ', $texts ),
            'htmlUrl'  => $html_url,
            'jsonUrl'  => $json_url,
            'verses'   => $matched,
        ];
        if ( ! $is_range && count( $matched ) === 1 ) {
            $response['verse'] = $matched[0]['verse'];
        }

        self::send_json( $response );
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
            'spanish' => [ 'name' => 'Scío de San Miguel', 'language' => 'es', 'languageName' => 'Spanish' ],
            'italian' => [ 'name' => 'Martini',            'language' => 'it', 'languageName' => 'Italian' ],
        ];
    }

    /**
     * Encode, answer a conditional request if we can, otherwise send the body.
     *
     * Every successful response goes through here so the validator is derived from
     * the exact bytes being sent. An ETag computed from the body cannot serve stale
     * content: if the content changed, the hash changed.
     *
     * WHY: these endpoints carried `Cache-Control: public, max-age=86400` but no
     * validator at all — no `ETag`, no `Last-Modified` — so a client that wanted to
     * revalidate could not. A far-future `If-Modified-Since` still returned a full
     * 200. Measured over 14 days: 3,455 successful JSON fetches, and Cloudflare
     * reports `cf-cache-status: DYNAMIC` on them, so the edge is not absorbing the
     * repeats either. Every app launch re-downloaded chapters it already had.
     *
     * A chapter body is ~8.7 KB and the index ~19.8 KB; a 304 is a few hundred.
     *
     * @param array $response The response payload.
     */
    private static function send_json( array $response ) {
        $body = json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $etag = '"' . md5( (string) $body ) . '"';

        // If-None-Match is a comma-separated list and entries may be weak (W/"…").
        // Compare on the opaque part so a weak validator still matches — these
        // responses are byte-identical when they match, so weak is safe here.
        $inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
            ? (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] )
            : '';
        if ( $inm !== '' ) {
            foreach ( explode( ',', $inm ) as $candidate ) {
                $candidate = trim( $candidate );
                if ( str_starts_with( $candidate, 'W/' ) ) {
                    $candidate = substr( $candidate, 2 );
                }
                if ( $candidate === $etag || $candidate === '*' ) {
                    self::send_json_headers();
                    // A 304 carries no body, and must not claim a length for one.
                    header( 'ETag: ' . $etag );
                    status_header( 304 );
                    header_remove( 'Content-Type' );
                    exit;
                }
            }
        }

        self::send_json_headers();
        header( 'ETag: ' . $etag );
        echo $body;
        exit;
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
    /**
     * /bible-books.json — the search VOCABULARY, and what a hit can be OFFERED as.
     *
     * Every prefix-token that names a book, in every configured language, plus
     * the two things a match has to carry to become a row the reader can tap:
     * the name the index shows for it and the slug it lives at. Still not
     * bible-index.json (140 KB, six translations' worth of URLs) — one name,
     * one slug, one gloss per language, ~16 KB.
     *
     * ⚠️ SIX PARALLEL ARRAYS, ONE ORDER. tokens[i], verses[i], names[i],
     * slugs[i] and every gloss[lang][i] are the same book, in CANONICAL BIBLE
     * ORDER — the order the index page shows, so a list of candidates reads
     * Iosue · Iudicum · Ruth rather than alphabetically by an internal key.
     *
     * Language-independent on purpose: every language's names are already
     * tokens in the same list, and `names`/`slugs` are the Latin spine that
     * every localized index is built from, so ONE cached file still serves all
     * locales. The vernacular name is the gloss, and all five ride along.
     * Cached like the rest of the API — it only changes when a dataset does.
     */
    private static function serve_book_vocabulary() {
        // All four maps are keyed by the book's canonical identity — never by a
        // dataset's order number — so they can be joined without shifting a
        // language's names onto the wrong books.
        $tokens = DwBible_Plugin::search_tokens_by_book();
        $verses = DwBible_Plugin::verse_counts_by_book();

        // Canonical order comes from the book directory. A book the vocabulary
        // knows but the directory does not still has to be FINDABLE — it just
        // cannot be offered as a row — so it rides along at the end with no
        // name and no slug rather than being dropped from the search.
        $order = [];
        $seen  = [];
        foreach ( DwBible_Plugin::book_directory() as $entry ) {
            $key = $entry['key'];
            if ( ! isset( $tokens[ $key ] ) || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $order[]      = $entry;
        }
        foreach ( array_keys( $tokens ) as $key ) {
            if ( isset( $seen[ $key ] ) ) { continue; }
            $order[] = [ 'key' => $key, 'slug' => '', 'name' => '' ];
        }

        // The five vernaculars. Latin is not among them: it is already `names`.
        $gloss_sets = [];
        foreach ( [ 'bible', 'bibel', 'spanish', 'french', 'italian' ] as $ds ) {
            $gloss_sets[ $ds ] = DwBible_Plugin::book_names_by_dataset( $ds );
        }

        $out_tokens = [];
        $out_verses = [];
        $out_names  = [];
        $out_slugs  = [];
        $out_gloss  = array_fill_keys( array_keys( $gloss_sets ), [] );

        foreach ( $order as $entry ) {
            $key          = $entry['key'];
            $out_tokens[] = $tokens[ $key ] ?? '';
            // Verses per chapter, comma-joined: the shortest honest way to say
            // "chapter 4 has 54 verses" 1,334 times. An empty string means the
            // numbers are unknown for that book, and the reader is not told a
            // citation is wrong on the strength of data we do not have.
            $out_verses[] = isset( $verses[ $key ] ) ? implode( ',', $verses[ $key ] ) : '';
            $out_names[]  = $entry['name'];
            $out_slugs[]  = $entry['slug'];
            foreach ( $gloss_sets as $ds => $names ) {
                // An empty gloss means "this language calls it what the spine
                // calls it, or has no name for it" — the row then shows one name.
                $g = $names[ $key ] ?? '';
                $out_gloss[ $ds ][] = ( $g !== '' && $g !== $entry['name'] ) ? $g : '';
            }
        }

        self::send_json_headers();
        echo wp_json_encode( [
            '_meta' => [
                'content' => 'Search vocabulary — every token that names a book (all languages), how long each book is, and the name/slug each book is offered under',
                'usage'   => 'Prefix-match a normalized query against any token; a hit means the query names a book. Parallel arrays, canonical Bible order: verses[i] is that book\'s verse count per chapter (so a citation can be checked for being POSSIBLE before it is followed), names[i] and slugs[i] are what every localized index shows and links to, and gloss.<dataset>[i] is that language\'s own name where it differs.',
                'books'   => count( $out_tokens ),
            ],
            'tokens' => $out_tokens,
            'verses' => $out_verses,
            'names'  => $out_names,
            'slugs'  => $out_slugs,
            'gloss'  => $out_gloss,
        ] );
    }

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
        // The locale a language-less dataset (latin) is published under.
        $default_lang = function_exists('dwi18n_default') ? dwi18n_default() : 'en';
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
                // ── Publish the addresses the site ACTUALLY serves ───────
                //
                // All three fields here used to be wrong in a way that only an
                // automated consumer would notice, which is the worst kind:
                //
                //   url      was /{dataset}/{ds_slug}/ — the pre-/biblia/ shape.
                //            Every one of them 301s now. This file is what an
                //            agent reads INSTEAD of crawling, so handing it a
                //            list of redirects is the one place being a hop
                //            behind is not harmless.
                //   slug     was the dataset's own HTML slug (`john`), but the
                //            book segment of a real URL is the LATIN slug
                //            (`ioannes`) in every language. Anything building a
                //            URL from it built the wrong one.
                //   jsonUrl  came from the source index.json with the host baked
                //            in as latinprayer.org, so on any other host the
                //            same object carried a local `url` beside a
                //            production `jsonUrl` — the two could not both be
                //            right, and a staging consumer silently reached
                //            production.
                //
                // The book segment is the Latin slug regardless of language, so
                // `slug` is the same in every translation — that repetition is
                // the truth about the URL shape, not redundancy to optimise away.
                $lang = function_exists('dwbible_i18n_lang_for_slug') ? dwbible_i18n_lang_for_slug($ds) : '';
                $latin_slug = self::latin_slug_for_key($slug);
                if ($latin_slug === '') { $latin_slug = $ds_slug; }
                $entry['translations'][ $ds ] = [
                    'name'    => $b['name'],
                    // Latin has no web locale by design — every page is Latin
                    // PLUS a vernacular, so there is no /la/ to point at. Null
                    // says that; the url below falls back to the default locale,
                    // where the Latin is on the page just the same.
                    'lang'    => $lang !== '' ? $lang : null,
                    'slug'    => $latin_slug,
                    'url'     => $site_url . '/' . ($lang !== '' ? $lang : $default_lang)
                                 . '/' . self::CANONICAL_SECTION . '/' . $latin_slug . '/',
                    'jsonUrl' => $site_url . '/' . $ds . '/' . $latin_slug . '/index.json',
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

        // Was an inline copy of send_json_headers() — the duplicate is why this
        // endpoint would have been missed by a change made only to the helper.
        self::send_json( $response );
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
