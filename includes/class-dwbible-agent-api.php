<?php
/**
 * DwBible — Agent API Trait: the two endpoints an AI agent needs that the
 * per-URL JSON API cannot give it.
 *
 * WHAT
 *   /bible-ref.json?q=<citation>&lang=la,en          — the REFERENCE RESOLVER
 *   /bible-search.json?q=<words>&lang=la&book=&limit= — VERSE SEARCH
 *   plus the helpers both share with serve_verse_json(): psalm numbering,
 *   canonical HTML/JSON URLs for a passage, the citation-name table, OSIS ids,
 *   and the optional "clean" typography.
 *
 * WHY
 *   An agent restricted to URLs it has already seen cannot construct
 *   /latin/galatians/3/28.json from "Gal 3:28" — llms.txt admitted verse URLs
 *   were "constructed, not discovered". One fetch of /bible-ref.json?q=Gal+3:28
 *   now yields the text AND every linkable/fetchable URL for that passage. And
 *   a topical question ("the verse about loving darkness rather than light")
 *   had no way into the corpus at all; /bible-search.json is that way in.
 *
 * INPUT   query-string parameters (see each method's docblock)
 * OUTPUT  self-documenting JSON via send_json() (ETag, CORS, day-long cache)
 * DEPENDS ON  DwBible_Reference (the citation grammar), the router trait's book
 *   resolver (internal_key_from_any_book), dwbibledata/data/** (chapter JSON,
 *   book_names.json), includes/osis-mapping.json, dwi18n_url_for() when present.
 * TESTED BY   tests/test-agent-api.sh (HTTP, run against Local or prod)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_Agent_API_Trait {

    /** Hard ceiling on search hits per response; the default is 20. */
    const AGENT_SEARCH_MAX_LIMIT = 100;

    // ── Language / dataset bridging ─────────────────────────────────────────

    /**
     * language code → JSON dataset slug, derived from json_datasets() so the
     * two lists cannot disagree. la→latin, en→bible, de→bibel, es→spanish,
     * fr→french, it→italian.
     */
    private static function agent_dataset_by_lang(): array {
        $out = [];
        foreach ( self::json_datasets() as $slug => $meta ) {
            $out[ $meta['language'] ] = $slug;
        }
        return $out;
    }

    /**
     * Parse a `lang` parameter ("la,en", "latin", "de" …) into dataset slugs,
     * in the order given, unknown entries dropped, duplicates removed.
     * Accepts language codes AND dataset slugs so an agent that only knows the
     * llms.txt "translation slug" list is not wrong.
     */
    private static function agent_parse_langs( string $raw, array $default, ?array &$unknown = null ): array {
        $by_lang = self::agent_dataset_by_lang();
        $known   = array_keys( self::json_datasets() );
        $out     = [];
        $unknown = [];
        foreach ( preg_split( '/[\s,+]+/', strtolower( trim( $raw ) ) ) as $tok ) {
            if ( $tok === '' ) { continue; }
            if ( $tok === 'all' ) { $unknown = []; return $known; }
            $ds = $by_lang[ $tok ] ?? ( in_array( $tok, $known, true ) ? $tok : null );
            if ( $ds === null ) { $unknown[] = $tok; continue; }
            if ( ! in_array( $ds, $out, true ) ) { $out[] = $ds; }
        }
        return $out ?: $default;
    }

    /**
     * Refuse a `lang` nobody can serve, instead of quietly serving another one.
     *
     * An unknown BOOK was already a 404 while an unknown LANGUAGE was dropped on
     * the floor: `?lang=klingon` answered in Latin, correctly labelled but never
     * what was asked. Two kinds of unservable request, two different answers, in
     * one API. A request naming ONLY languages this site does not hold is one it
     * cannot honour, and it now says so. A mixed list ("en,klingon") still names
     * English, so it is served and the unusable token is reported instead.
     */
    private static function agent_reject_unknown_langs( string $raw, array $unknown ): void {
        if ( trim( $raw ) === '' || ! $unknown ) { return; }
        if ( self::agent_langs_were_asked( [], $raw ) ) { return; }
        $names = [];
        foreach ( self::json_datasets() as $slug => $meta ) { $names[] = $meta['language'] . ' (' . $meta['name'] . ')'; }
        self::agent_error( 400, 'UNSUPPORTED_LANGUAGE', 'This Bible is not held in: ' . implode( ', ', $unknown ) . '.', [
            'available'  => $names,
            'suggestion' => 'Pass one or more of la, en, de, es, fr, it — or "all".',
        ] );
    }

    /** Whether any token of the raw parameter named a language this site holds. */
    private static function agent_langs_were_asked( array $unused, string $raw ): bool {
        $by_lang = self::agent_dataset_by_lang();
        $known   = array_keys( self::json_datasets() );
        foreach ( preg_split( '/[\s,+]+/', strtolower( trim( $raw ) ) ) as $tok ) {
            if ( $tok === '' ) { continue; }
            $ds = $by_lang[ $tok ] ?? ( in_array( $tok, $known, true ) ? $tok : null );
            if ( $ds !== null ) { return true; }
        }
        return false;
    }

    /**
     * `limit`, read forgivingly but never nonsensically.
     *
     * absint() turned "-5" into 5 and "abc" into 0, and a clamp to the minimum
     * then made both mean "one hit" — a confident, arbitrary answer to a
     * malformed request. Anything that is not a positive whole number falls
     * back to the DEFAULT, which is what the caller would have got by not
     * sending the parameter at all; a number above the ceiling still clamps.
     */
    private static function agent_parse_limit( int $default ): int {
        if ( ! isset( $_GET['limit'] ) ) { return $default; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = trim( (string) wp_unslash( $_GET['limit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! preg_match( '/^[0-9]+$/', $raw ) || (int) $raw < 1 ) { return $default; }
        return min( self::AGENT_SEARCH_MAX_LIMIT, (int) $raw );
    }

    // ── Book identity ───────────────────────────────────────────────────────

    /**
     * The citation-name table (dwbibledata/data/book_names.json): one short
     * name per book per language, keyed by the canonical book key.
     * @return array<string,array<string,string>> key → ['la'=>…, 'en'=>…, …]
     */
    private static function agent_book_names_table(): array {
        static $cache = null;
        if ( $cache !== null ) { return $cache; }
        $cache = [];
        $file  = dwbible_data_dir() . 'book_names.json';
        if ( file_exists( $file ) ) {
            $d = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $d ) && ! empty( $d['books'] ) && is_array( $d['books'] ) ) {
                $cache = $d['books'];
            }
        }
        return $cache;
    }

    /**
     * The OSIS id of a book (Gen, Ps, John, Gal, 2Pet …) — the language-neutral
     * abbreviation every Bible toolchain understands. Read from
     * includes/osis-mapping.json, whose entries name the internal key under
     * `latin` (and `bible`).
     */
    private static function agent_osis_for_key( string $key ): ?string {
        static $map = null;
        if ( $map === null ) {
            $map  = [];
            $file = plugin_dir_path( __FILE__ ) . 'osis-mapping.json';
            $d    = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
            if ( is_array( $d ) && ! empty( $d['books'] ) ) {
                foreach ( $d['books'] as $osis => $entry ) {
                    if ( ! is_array( $entry ) ) { continue; }
                    foreach ( [ 'latin', 'bible' ] as $ds ) {
                        if ( ! empty( $entry[ $ds ] ) && is_string( $entry[ $ds ] ) ) {
                            $map[ $entry[ $ds ] ] = (string) $osis;
                        }
                    }
                }
            }
        }
        return $map[ $key ] ?? null;
    }

    /** One book's citation name in every language, for collaborators outside this trait. */
    public static function book_citation_names( string $key ): array {
        return self::agent_book_names_table()[ $key ] ?? [];
    }

    /**
     * A book key from a name THIS SITE PRINTS as a citation — the last resort
     * of the resolver.
     *
     * Every answer carries a citation per language, and a consumer's obvious
     * next move is to quote one back. That did not work: the matcher reads the
     * URL slugs and the search vocabulary, which for Italian follow Martini's
     * Vulgate naming ("Primo dei Re" = 1 Samuel), while the citations are
     * printed in modern Italian ("1 Samuele", "1 Corinzi"). Nineteen of the 73
     * Italian citations and two Spanish ones did not resolve — a closed loop
     * the site opened itself. The rule this restores is simple: EVERY NAME WE
     * PRINT, WE ACCEPT.
     *
     * Ambiguity refuses rather than guesses. Today no two books print the same
     * name (349 distinct across six languages, zero collisions), but a name
     * that ever came to mean two books must not silently pick one — that is the
     * failure shape this endpoint exists to avoid.
     */
    public static function key_from_citation_name( string $raw ): ?string {
        static $map = null;
        if ( $map === null ) {
            $map = [];
            foreach ( self::agent_book_names_table() as $key => $names ) {
                foreach ( (array) $names as $name ) {
                    if ( ! is_string( $name ) || trim( $name ) === '' ) { continue; }
                    $slug = DwBible_Plugin::slugify( $name );
                    if ( $slug === '' ) { continue; }
                    if ( ! array_key_exists( $slug, $map ) ) {
                        $map[ $slug ] = $key;
                    } elseif ( $map[ $slug ] !== $key ) {
                        $map[ $slug ] = false; // two books, one name: answer neither
                    }
                }
            }
        }
        $slug = DwBible_Plugin::slugify( $raw );
        if ( $slug === '' || ! isset( $map[ $slug ] ) || $map[ $slug ] === false ) { return null; }
        return $map[ $slug ];
    }

    /**
     * Everything an agent needs to identify a book, in one block: the canonical
     * key (what the JSON dirs are named), the Latin URL slug (what the HTML pages
     * are named), the citation name in every language, and the OSIS id.
     */
    private static function agent_book_block( string $key ): array {
        $names = self::agent_book_names_table()[ $key ] ?? [];
        $dir   = null;
        foreach ( DwBible_Plugin::book_directory() as $row ) {
            if ( $row['key'] === $key ) { $dir = $row; break; }
        }
        return [
            'key'   => $key,
            'slug'  => DwBible_Plugin::latin_slug_for_key( $key ),
            'title' => $dir['name'] ?? ( $names['la'] ?? $key ),
            'names' => $names,
            'osis'  => self::agent_osis_for_key( $key ),
        ];
    }

    // ── One-chapter books ───────────────────────────────────────────────────

    /**
     * Five books have exactly one chapter — Abdias, Philemon, 2 John, 3 John,
     * Jude — and every convention cites them BY VERSE ALONE: "Jude 3", never
     * "Jude 1:3". Read literally that is chapter 3, which does not exist, so
     * the only form anyone writes was the one form that failed.
     *
     * Given the book's chapters, rewrite a bare "N" or "N-M" into the chapter-1
     * verses it means, and say so — an interpretation a reader cannot see is
     * indistinguishable from a wrong answer.
     *
     * The whole book stays reachable as the bare name ("Jude"), and the
     * explicit chapter form ("Jude 1:3") is unaffected.
     *
     * @param int $chapters How many chapters the book has.
     * @return array{chapter:int,from:int,to:int,note:string}|null null = not a single-chapter book.
     */
    private static function agent_single_chapter_verses( int $chapters, int $n, int $to, string $book_name ): ?array {
        if ( $chapters !== 1 || $n <= 0 ) { return null; }
        $to = $to > $n ? $to : $n;
        $cited = $n . ( $to > $n ? "-{$to}" : '' );
        return [
            'chapter' => 1,
            'from'    => $n,
            'to'      => $to,
            'note'    => "\"{$book_name} {$cited}\" was read as {$book_name} 1:{$cited}: this book has a single chapter and is cited by verse. "
                       . "The whole book is \"{$book_name}\".",
        ];
    }

    // ── Malachias 4 ─────────────────────────────────────────────────────────

    /**
     * The one book whose chapter division differs from the printed Clementine.
     *
     * Our data (and dwlectionary, and the Nova Vulgata) carry the Elijah
     * prophecy as Malachias 3:19-24; every printed Vulgate and every
     * Douay-Rheims numbers the same six verses 4:1-6. A reader holding a 1962
     * missal asks for a chapter that does not exist here — so chapter 4 verse v
     * is translated to 3:(v+18), exactly as the HTML router has always done
     * (class-dwbible-router.php). A bare chapter 4 lands on 3:19, where that
     * chapter begins.
     *
     * Without this the resolver accepted "Mal 4:2", reported chapter 4 verse 2,
     * and returned an EMPTY text — a well-formed answer containing nothing,
     * which is the worst way to be wrong.
     *
     * @return array{chapter:int,from:int,to:int,note:string}|null null = not this case.
     */
    private static function agent_malachias_shim( string $key, int $ch, int $vf, int $vt ): ?array {
        if ( $key !== 'malachias' || $ch !== 4 ) { return null; }
        $from  = $vf > 0 ? $vf + 18 : 19;
        $to    = $vt > 0 ? $vt + 18 : ( $vf > 0 ? $vf + 18 : 24 );
        $cited = $vf > 0 ? ( 'Malachias 4:' . $vf . ( $vt > $vf ? "-{$vt}" : '' ) ) : 'Malachias 4';
        return [
            'chapter' => 3,
            'from'    => $from,
            'to'      => $to,
            'note'    => "\"{$cited}\" was read as Malachias 3:{$from}" . ( $to > $from ? "-{$to}" : '' ) . ': '
                       . 'this text carries the Elijah prophecy as 3:19-24, where printed Vulgates number it 4:1-6. Same six verses.',
        ];
    }

    // ── Psalm numbering ─────────────────────────────────────────────────────

    /**
     * The whole site is on the Vulgate (Septuagint) psalm numbering — all six
     * editions are aligned to the Latin spine — so Psalm 23 here is "Domini est
     * terra", not the Good Shepherd. This states, for a Vulgate psalm number,
     * which Hebrew (Masoretic) number a reader will know it by.
     *
     * The two systems differ between 9 and 147: the Vulgate joins Hebrew 9+10
     * and 114+115, and splits Hebrew 116 and 147.
     */
    public static function psalm_numbering( int $vulgate ): array {
        $n = $vulgate;
        // `hebrew` is always an integer — the Masoretic psalm this one is (or
        // begins) — so a consumer can compare numbers. Where the two systems do
        // not map one to one, `hebrewSpan` says exactly how.
        $span = null;
        if ( $n <= 8 || $n >= 148 )      { $hebrew = $n; }
        elseif ( $n === 9 )              { $hebrew = 9;   $span = 'Vulgate 9 is Hebrew 9 and 10 together'; }
        elseif ( $n <= 112 )             { $hebrew = $n + 1; }
        elseif ( $n === 113 )            { $hebrew = 114; $span = 'Vulgate 113 is Hebrew 114 and 115 together'; }
        elseif ( $n === 114 )            { $hebrew = 116; $span = 'Vulgate 114 is Hebrew 116:1-9 (the first part)'; }
        elseif ( $n === 115 )            { $hebrew = 116; $span = 'Vulgate 115 is Hebrew 116:10-19 (the second part)'; }
        elseif ( $n <= 145 )             { $hebrew = $n + 1; }
        elseif ( $n === 146 )            { $hebrew = 147; $span = 'Vulgate 146 is Hebrew 147:1-11 (the first part)'; }
        else /* 147 */                   { $hebrew = 147; $span = 'Vulgate 147 is Hebrew 147:12-20 (the second part)'; }
        $out = [
            'system'  => 'vulgate',
            'vulgate' => $n,
            'hebrew'  => $hebrew,
        ];
        if ( $span !== null ) { $out['hebrewSpan'] = $span; }
        $out['note'] = 'Chapter numbers in this API are Vulgate (Septuagint) numbering, in every translation. '
                     . '`hebrew` is the Masoretic number of the same psalm. Pass ?numbering=hebrew (on any psalm URL or /bible-ref.json) to cite by the Hebrew number.';
        return $out;
    }

    /**
     * The `numbering` request parameter: '' (absent), 'vulgate' or 'hebrew'.
     * Anything else is a 400 — a parameter that is silently dropped would hand
     * the reader a different psalm without a word.
     */
    private static function agent_numbering_mode(): string {
        $n = isset( $_GET['numbering'] ) ? strtolower( sanitize_key( wp_unslash( (string) $_GET['numbering'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $n === '' || $n === 'vulgate' || $n === 'hebrew' ) { return $n; }
        self::agent_error( 400, 'UNSUPPORTED_PARAM', "numbering=\"{$n}\" is not understood.", [
            'suggestion' => 'numbering=vulgate (the default, the site\'s own numbering) or numbering=hebrew (Masoretic).',
        ] );
        return '';
    }

    /**
     * Apply ?numbering=hebrew to a Psalms chapter number: the Vulgate chapter
     * that holds it, plus the psalmNumbering block that records what was asked.
     * Returns null for a Hebrew number no psalm has (the caller answers 404).
     *
     * @return array{chapter:int,meta:array}|null
     */
    private static function agent_psalm_request( int $chapter, string $numbering ): ?array {
        if ( $numbering === 'hebrew' ) {
            $v = self::psalm_vulgate_for_hebrew( $chapter );
            if ( $v === null ) { return null; }
            $meta = self::psalm_numbering( $v );
            $meta['requested'] = [ 'system' => 'hebrew', 'number' => $chapter ];
            return [ 'chapter' => $v, 'meta' => $meta ];
        }
        return [ 'chapter' => $chapter, 'meta' => self::psalm_numbering( $chapter ) ];
    }

    /**
     * The book name a CITATION uses in one language. One psalm is cited in the
     * singular — "Psalmus 22", "Psalm 22", "Salmo 22" — while the book is
     * "Psalmi"; every other book cites by its name unchanged.
     */
    public static function agent_cite_name( string $key, string $lang, string $fallback ): string {
        if ( $key === 'psalms' ) {
            $singular = [ 'la' => 'Psalmus', 'en' => 'Psalm', 'de' => 'Psalm', 'es' => 'Salmo', 'fr' => 'Psaume', 'it' => 'Salmo' ];
            return $singular[ $lang ] ?? $fallback;
        }
        return $fallback;
    }

    /**
     * Hebrew (Masoretic) psalm number → the Vulgate chapter that holds it (the
     * FIRST one where the Hebrew psalm is split across two). Returns null for a
     * number outside 1–150.
     */
    public static function psalm_vulgate_for_hebrew( int $hebrew ): ?int {
        $h = $hebrew;
        if ( $h < 1 || $h > 150 )        { return null; }
        if ( $h <= 8 || $h >= 148 )      { return $h; }
        if ( $h === 9 || $h === 10 )     { return 9; }
        if ( $h <= 113 )                 { return $h - 1; }
        if ( $h === 114 || $h === 115 )  { return 113; }
        if ( $h === 116 )                { return 114; }
        if ( $h <= 146 )                 { return $h - 1; }
        return 146; // 147
    }

    // ── URLs ────────────────────────────────────────────────────────────────

    /**
     * The canonical HTML page for a passage: /{lang}/biblia/{latin-slug}/{ch}:{v}/
     * — the address the site itself 301s everything to, so an agent handed this
     * URL fetches it in one hop. Latin has no web language of its own (it is on
     * every interlinear page), so `la` is shown on the English page.
     */
    public static function agent_html_url( string $lang, string $key, int $ch = 0, int $vf = 0, int $vt = 0 ): string {
        if ( $lang === 'la' || ! in_array( $lang, [ 'en', 'de', 'es', 'fr', 'it' ], true ) ) { $lang = 'en'; }
        $path = '/' . self::CANONICAL_SECTION . '/' . DwBible_Plugin::latin_slug_for_key( $key );
        if ( $ch > 0 ) {
            $path .= '/' . $ch;
            if ( $vf > 0 ) {
                $path .= ':' . $vf . ( $vt > $vf ? '-' . $vt : '' );
            }
        }
        if ( function_exists( 'dwi18n_url_for' ) ) {
            return dwi18n_url_for( $lang, $path );
        }
        return home_url( '/' . $lang . $path . '/' );
    }

    /** The JSON API address of a passage in one dataset. */
    private static function agent_json_url( string $dataset, string $key, int $ch = 0, int $vf = 0, int $vt = 0 ): string {
        $site = site_url();
        if ( $ch <= 0 ) { return "{$site}/{$dataset}/{$key}/index.json"; }
        if ( $vf <= 0 ) { return "{$site}/{$dataset}/{$key}/{$ch}.json"; }
        return "{$site}/{$dataset}/{$key}/{$ch}/{$vf}" . ( $vt > $vf ? "-{$vt}" : '' ) . '.json';
    }

    // ── Typography ──────────────────────────────────────────────────────────

    /**
     * The Clementine text (and Crampon's French) is set with a space before
     * : ; ! ? — the edition's own convention, kept byte-faithfully in the data.
     * An agent quoting into modern prose can ask for ?typography=clean and get
     * the space removed; the default stays `source`.
     */
    public static function agent_clean_typography( string $text ): string {
        return (string) preg_replace( '/[ \x{00A0}]+([:;!?])/u', '$1', $text );
    }

    /** The `typography` request parameter: 'clean' or 'source' (default). */
    private static function agent_typography_mode(): string {
        $t = isset( $_GET['typography'] ) ? strtolower( sanitize_key( wp_unslash( (string) $_GET['typography'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return $t === 'clean' ? 'clean' : 'source';
    }

    // ── Passage loading ─────────────────────────────────────────────────────

    /**
     * The verses of one passage in one dataset, from the chapter file.
     * @return array{translation:array,book:array,verses:array,total:int}|null
     */
    private static function agent_load_passage( string $dataset, string $key, int $ch, int $vf, int $vt ): ?array {
        $file = dwbible_data_dir() . $dataset . '/json/' . $key . '/' . $ch . '.json';
        if ( ! file_exists( $file ) ) { return null; }
        $data = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $data ) || empty( $data['verses'] ) ) { return null; }
        $out = [];
        foreach ( $data['verses'] as $v ) {
            $n = (int) $v['verse'];
            if ( $vf > 0 && ( $n < $vf || $n > $vt ) ) { continue; }
            $out[] = [ 'verse' => $n, 'text' => (string) $v['text'] ];
        }
        return [
            'translation' => $data['_meta']['translation'] ?? [],
            'book'        => $data['_meta']['book'] ?? [],
            'verses'      => $out,
            'total'       => count( $data['verses'] ),
        ];
    }

    // ── /bible-ref.json — the reference resolver ────────────────────────────

    /**
     * Resolve a free-text citation to a passage and every address it has.
     *
     * Parameters
     *   q          "Gal 3:28", "Galatians 3:28", "Ad Galatas 3,28", "Job 20:12-13",
     *              "Psalm 23", "Jn" — any book form the site's HTML router accepts
     *              (Latin, English, German, Spanish, French, Italian names and the
     *              usual abbreviations), chapter:verse or chapter,verse, ranges.
     *   lang       comma list of languages/datasets to return TEXT for
     *              (default "la,en"; "all" for all six). URLs come for all six always.
     *   numbering  "hebrew" to read a Psalm number as Masoretic (default vulgate).
     *   typography "clean" to strip the space before : ; ! ? (default source).
     *
     * Book-only queries return the book's addresses and no text; chapter-only
     * queries return the whole chapter. A verse the chapter has not got is a 404
     * with the chapter's length — never silently a different verse.
     */
    private static function serve_reference_json() {
        $raw = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = trim( $raw );
        if ( $raw === '' ) {
            self::agent_error( 400, 'MISSING_QUERY', 'Pass a citation as ?q=, e.g. /bible-ref.json?q=John+3:16&lang=la,en' );
        }

        $parsed = DwBible_Reference::parse_query( $raw );
        $key    = self::internal_key_from_any_book( $parsed['name'], 'latin' );

        // A BARE RANGE — "Jude 20-21", "Philemon 4-6" — is how a one-chapter
        // book names several verses, and the shared grammar cannot split it:
        // with no colon it reads the name as "Jude 20-" and the number as 21.
        // Only tried when the ordinary parse found no book, so a citation the
        // grammar already understood ("Job 20:12-13") is never re-read here.
        $bare_range = null;
        if ( $key === null && preg_match( '/^(.*?)[\s.]*(\d+)\s*[-–—]\s*(\d+)\s*$/u', $raw, $bm ) ) {
            $alt = self::internal_key_from_any_book( trim( $bm[1] ), 'latin' );
            if ( $alt !== null ) {
                $key        = $alt;
                $bare_range = [ (int) $bm[2], (int) $bm[3] ];
            }
        }

        if ( $key === null ) {
            // BLAME THE RIGHT HALF. The shared citation grammar is anchored at
            // both ends, so a cross-chapter range like "Mt 5:1-7:29" parses as
            // the BOOK NAME "Mt 5:1-7:" and then fails the book lookup — and
            // the refusal then said no book could be recognised while listing
            // "Mt" as a supported abbreviation in the same sentence. A model
            // reading that retries book spellings forever, because the message
            // points at the one part of the query that was already correct.
            $head = null;
            // The leading (?:[1-4]\s*)? is load-bearing: a third of the books
            // in this canon START with a digit — "1 Cor", "2 Pet", "3 Kings",
            // "1 Par" — and a name pattern that forbade digits sent every one
            // of them back to BOOK_NOT_RECOGNISED, which is the very error this
            // block exists to stop. Measured on a full year of Mass readings:
            // 13 of the 242 unresolvable references were numbered books.
            if ( preg_match( '/^\s*((?:[1-4]\s*)?[^\d]+?)\s*\d/u', $raw, $bm ) ) {
                $head = self::internal_key_from_any_book( trim( $bm[1] ), 'latin' );
            }
            if ( $head !== null ) {
                $names = self::book_citation_names( $head );
                self::agent_error( 400, 'CITATION_NOT_UNDERSTOOD',
                    "The book in \"{$raw}\" is " . ( $names['la'] ?? $head ) . ", but the chapter and verse part could not be read.",
                    [
                        'book'       => self::agent_book_block( $head ),
                        'suggestion' => 'A range must stay inside ONE chapter — "Mt 5:1-12", not "Mt 5:1-7:29". A passage spanning chapters is two or more requests, one per chapter; a whole chapter is "Mt 5".',
                    ] );
            }
            self::agent_error( 404, 'BOOK_NOT_RECOGNISED', "No book in \"{$raw}\" could be recognised.", [
                'suggestion' => 'Book names resolve in Latin, English, German, Spanish, French and Italian, plus standard abbreviations (Gen, Ps, Mt, Jn, 1 Cor, Gal, Apoc). The full list: ' . site_url( '/bible-books.json' ),
            ] );
        }

        $counts   = DwBible_Plugin::verse_counts_by_book();
        $chapters = isset( $counts[ $key ] ) ? count( $counts[ $key ] ) : 0;
        $en_name  = self::agent_book_names_table()[ $key ]['en'] ?? $key;

        $ch = 0; $vf = 0; $vt = 0; $read_as = null;
        if ( $bare_range !== null ) {
            $single = self::agent_single_chapter_verses( $chapters, $bare_range[0], $bare_range[1], $en_name );
            if ( $single === null ) {
                // In a book of many chapters "Genesis 1-3" could mean three
                // chapters or three verses of one. Neither is servable as
                // written, and guessing is how a reader is handed the wrong
                // passage without being told, so it is refused BY NAME.
                self::agent_error( 400, 'AMBIGUOUS_RANGE', "\"{$raw}\" could mean chapters {$bare_range[0]}-{$bare_range[1]} or verses of one chapter.", [
                    'suggestion' => "Say which: \"{$en_name} {$bare_range[0]}:{$bare_range[1]}\" for verses, \"{$en_name} {$bare_range[0]}\" for a chapter.",
                ] );
            }
            $ch = $single['chapter']; $vf = $single['from']; $vt = $single['to']; $read_as = $single['note'];
        } elseif ( $parsed['ref'] !== '' && preg_match( '/^(\d+)(?::(\d+)(?:-(\d+))?)?$/', $parsed['ref'], $m ) ) {
            $ch = (int) $m[1];
            $vf = isset( $m[2] ) && $m[2] !== '' ? (int) $m[2] : 0;
            $vt = isset( $m[3] ) && $m[3] !== '' ? (int) $m[3] : $vf;
            // "Jude 3" — a bare number on a one-chapter book is its VERSE.
            if ( $vf === 0 ) {
                $single = self::agent_single_chapter_verses( $chapters, $ch, 0, $en_name );
                if ( $single !== null ) {
                    $ch = $single['chapter']; $vf = $single['from']; $vt = $single['to']; $read_as = $single['note'];
                }
            }
        }

        // Malachias 4 — a chapter this text does not have, under a number every
        // printed Vulgate uses.
        $mal = self::agent_malachias_shim( $key, $ch, $vf, $vt );
        if ( $mal !== null ) {
            $ch = $mal['chapter']; $vf = $mal['from']; $vt = $mal['to']; $read_as = $mal['note'];
        }

        // A RANGE THAT RUNS BACKWARDS IS REFUSED, never answered empty.
        // "John 3:10-5" used to come back well-formed and carrying NOTHING:
        // the verse filter keeps n where vf <= n <= vt, which no verse can
        // satisfy, so `verses` was [] and `text` was "" — and the citation
        // printed "Ioannes 3:10", a single verse, because the dash is only
        // written when vt > vf. A well-formed answer carrying nothing is the
        // failure shape a model is most likely to paper over from memory,
        // which is why every other unservable request here is refused instead.
        // The shared DwBible_Reference::parse_chapter_and_range() has always
        // rejected this; the agent endpoint parses its own range and did not.
        if ( $vf > 0 && $vt > 0 && $vt < $vf ) {
            self::agent_error( 400, 'RANGE_REVERSED',
                "\"{$raw}\" asks for verses {$vf} to {$vt}, which runs backwards.",
                [ 'suggestion' => "A range goes low to high — \"{$ch}:{$vt}-{$vf}\" is probably what was meant." ] );
        }

        // Psalms: the reader may have typed the Hebrew number.
        $numbering  = self::agent_numbering_mode();
        $psalm_meta = null;
        if ( $key === 'psalms' && $ch > 0 ) {
            $pr = self::agent_psalm_request( $ch, $numbering );
            if ( $pr === null ) {
                self::agent_error( 404, 'CHAPTER_NOT_FOUND', "There is no Psalm {$ch}.", [ 'suggestion' => 'Psalms run 1-150.' ] );
            }
            $ch         = $pr['chapter'];
            $psalm_meta = $pr['meta'];
        }

        // Does the passage exist? Check against the Latin spine before reading anything.
        $book_counts = $counts[ $key ] ?? [];
        if ( $ch > 0 && $book_counts && $ch > count( $book_counts ) ) {
            self::agent_error( 404, 'CHAPTER_NOT_FOUND', "Chapter {$ch} does not exist in this book.", [
                'suggestion' => 'This book has ' . count( $book_counts ) . ' chapters.',
                'bookIndex'  => self::agent_json_url( 'latin', $key ),
            ] );
        }
        if ( $vf > 0 && $book_counts && isset( $book_counts[ $ch - 1 ] ) ) {
            $n = (int) $book_counts[ $ch - 1 ];
            if ( $vf > $n ) {
                // Name the citation the READER typed as well as the verse we
                // looked for: after a translation ("Mal 4:9" → 3:27) an error
                // about "verse 27 of chapter 3" mentions two numbers they never
                // wrote, and reads like a bug in the answer rather than in the
                // question.
                self::agent_error( 404, 'VERSE_NOT_FOUND', "Verse {$vf} does not exist in chapter {$ch}.", array_filter( [
                    'requested'   => $read_as !== null ? $raw : null,
                    'readAs'      => $read_as,
                    'suggestion'  => "This chapter has {$n} verses (1-{$n}).",
                    'chapterJson' => self::agent_json_url( 'latin', $key, $ch ),
                ] ) );
            }
            // An over-long range is clamped rather than refused — the reader
            // named a real verse and meant to read to the end — but a clamp
            // nobody is told about is a silently different passage.
            if ( $vt > $n ) {
                $read_as = "\"{$raw}\" was read as {$ch}:{$vf}-{$n}: this chapter ends at verse {$n}.";
                $vt = $n;
            }
        }

        $book      = self::agent_book_block( $key );
        $by_lang   = self::agent_dataset_by_lang();
        $lang_raw  = isset( $_GET['lang'] ) ? (string) wp_unslash( $_GET['lang'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $langs     = self::agent_parse_langs( $lang_raw, [ 'latin', 'bible' ], $lang_unknown );
        self::agent_reject_unknown_langs( $lang_raw, $lang_unknown );
        $clean     = self::agent_typography_mode() === 'clean';
        $site      = site_url();

        // Every address the passage has, in every language — the point of this endpoint.
        $urls = [ 'html' => [], 'json' => [] ];
        foreach ( [ 'en', 'de', 'es', 'fr', 'it' ] as $lang ) {
            $urls['html'][ $lang ] = self::agent_html_url( $lang, $key, $ch, $vf, $vt );
        }
        foreach ( $by_lang as $lang => $ds ) {
            $urls['json'][ $lang ] = self::agent_json_url( $ds, $key, $ch, $vf, $vt );
        }

        $citations = [];
        foreach ( $book['names'] as $lang => $name ) {
            $cite = $ch > 0 ? self::agent_cite_name( $key, (string) $lang, (string) $name ) : (string) $name;
            $citations[ $lang ] = $cite . ( $ch > 0 ? ' ' . $ch . ( $vf > 0 ? ':' . $vf . ( $vt > $vf ? '-' . $vt : '' ) : '' ) : '' );
        }

        $passages = [];
        if ( $ch > 0 ) {
            foreach ( $langs as $ds ) {
                $lang = array_search( $ds, $by_lang, true );
                $p    = self::agent_load_passage( $ds, $key, $ch, $vf, $vt );
                if ( $p === null ) { continue; }
                $texts = [];
                foreach ( $p['verses'] as &$v ) {
                    if ( $clean ) { $v['text'] = self::agent_clean_typography( $v['text'] ); }
                    $texts[] = $v['text'];
                }
                unset( $v );
                $tname = $p['translation']['name'] ?? $ds;
                $passages[ $lang ] = [
                    'citation'    => ( $citations[ $lang ] ?? $citations['en'] ?? '' ) . " ({$tname})",
                    'translation' => $p['translation'],
                    'text'        => implode( ' ', $texts ),
                    'verses'      => $p['verses'],
                    'htmlUrl'     => $urls['html'][ $lang === 'la' ? 'en' : $lang ],
                    'jsonUrl'     => $urls['json'][ $lang ],
                ];
            }
        }

        // A chapter was asked for and NOTHING could be read: that is a failure,
        // not an answer. Before this, a passage whose file could not be loaded
        // came back as a well-formed response with an empty `passages` object —
        // a shape a model can easily present as "the verse is blank", or fill
        // from its own memory. Say plainly that it could not be served.
        if ( $ch > 0 && ! $passages ) {
            self::agent_error( 404, 'PASSAGE_UNAVAILABLE', "{$raw} resolved to a passage this server could not read.", [
                'resolved'   => [ 'book' => $key, 'chapter' => $ch, 'verseFrom' => $vf ?: null, 'verseTo' => $vf ? $vt : null ],
                'suggestion' => 'The book index lists the chapters this text actually carries: ' . self::agent_json_url( 'latin', $key ),
            ] );
        }

        $ref_label = $citations['en'] ?? $key;
        $response = [
            '_meta' => [
                'project'    => 'Latin Prayer',
                'projectUrl' => $site,
                'apiDocs'    => $site . '/llms.txt',
                'content'    => "\"{$raw}\" resolved to {$ref_label}" . ( $passages ? ' — text in ' . implode( ', ', array_keys( $passages ) ) : '' ),
                'query'      => $raw,
                'readAs'     => $read_as,
                'typography' => $clean ? 'clean' : 'source',
                'usage'      => 'q = any citation form; lang = comma list of la,en,de,es,fr,it (or "all") for the text; '
                              . 'numbering=hebrew to read a Psalm number as Masoretic; typography=clean to drop the space before : ; ! ?',
            ],
            'ref' => [
                'book'      => $book,
                'chapter'   => $ch > 0 ? $ch : null,
                'verseFrom' => $vf > 0 ? $vf : null,
                'verseTo'   => $vf > 0 ? $vt : null,
                'citation'  => $citations,
            ],
            'urls'     => $urls,
            'passages' => (object) $passages,
        ];
        if ( $psalm_meta !== null ) {
            $response['_meta']['psalmNumbering'] = $psalm_meta;
        }
        self::send_json( $response );
    }

    // ── /bible-search.json — verse search ───────────────────────────────────

    /**
     * Search the verse text of one translation.
     *
     * Parameters
     *   q      the words to find; ALL must occur in a verse (order-free, substring,
     *          case- and accent-insensitive; in Latin j/i are folded so "ejus" finds "eius").
     *   lang   one language or dataset (default la).
     *   book   optional canonical key / any book name to narrow the scan.
     *   limit  hits to return (default 20, max 100); `total` counts them all.
     *   typography  "clean" as in /bible-ref.json.
     *
     * Scans the dataset's chapter files at request time (~35,800 verses in
     * 100-200 ms); responses are cacheable for a day like the rest of the API,
     * so a repeated question costs nothing. Canonical book order, then chapter,
     * then verse.
     */
    private static function serve_search_json() {
        $raw = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = trim( $raw );
        if ( $raw === '' ) {
            self::agent_error( 400, 'MISSING_QUERY', 'Pass the words to find as ?q=, e.g. /bible-search.json?q=dilexerunt+tenebras&lang=la' );
        }

        $lang_raw = isset( $_GET['lang'] ) ? (string) wp_unslash( $_GET['lang'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $langs    = self::agent_parse_langs( $lang_raw, [ 'latin' ], $lang_unknown );
        self::agent_reject_unknown_langs( $lang_raw, $lang_unknown );
        $dataset = $langs[0];
        $by_lang = self::agent_dataset_by_lang();
        $lang    = (string) array_search( $dataset, $by_lang, true );
        $meta_ds = self::json_datasets()[ $dataset ];

        $limit  = self::agent_parse_limit( 20 );
        // WHERE TO START. Without this the API reported a total it could never
        // deliver: 8,010 matches, 100 of them reachable, and `truncated: true`
        // saying there were more without any way to ask for them. Worse, an
        // agent guessing at `offset`/`page`/`cursor` got page one back in
        // silence and would have presented it as page two.
        $offset = 0;
        if ( isset( $_GET['offset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $raw_off = trim( (string) wp_unslash( $_GET['offset'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! preg_match( '/^[0-9]+$/', $raw_off ) ) {
                self::agent_error( 400, 'UNSUPPORTED_PARAM', "offset=\"{$raw_off}\" is not a whole number.", [
                    'suggestion' => 'offset is 0-based: offset=100 begins at the 101st match.',
                ] );
            }
            $offset = (int) $raw_off;
        }

        $only_key = null;
        $book_raw = isset( $_GET['book'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['book'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( trim( $book_raw ) !== '' ) {
            $only_key = self::internal_key_from_any_book( trim( $book_raw ), 'latin' );
            // A KEY THAT NAMES NO BOOK IS NOT A BOOK. internal_key_from_any_book()
            // returns a bare legacy slug when nothing maps it to a canonical key,
            // so "Buch Ester" came back as "ester" and this endpoint then searched
            // a book that does not exist: 200, total 0, and the raw slug echoed
            // back as `bookName` — indistinguishable to a reader from "that word
            // is not in Esther". 1.26.09.16.14 fixed exactly this shape for the
            // one input the citation-name map happened to cover; nine German
            // abbreviations THIS SITE PUBLISHES still land here (dwbibledata#21),
            // and on the search path a silent zero is worse than any refusal.
            if ( $only_key !== null && ! isset( DwBible_Plugin::verse_counts_by_book()[ $only_key ] ) ) {
                $only_key = null;
            }
            if ( $only_key === null ) {
                self::agent_error( 404, 'BOOK_NOT_RECOGNISED', "The book \"{$book_raw}\" could not be recognised.", [
                    'suggestion' => 'Use a canonical key (genesis, psalms, john …) or any language\'s book name. The list: ' . site_url( '/bible-books.json' ),
                ] );
            }
        }

        $tokens = array_values( array_filter( preg_split( '/\s+/', self::agent_search_normalize( $raw, $lang ) ) ) );
        if ( ! $tokens ) {
            self::agent_error( 400, 'EMPTY_QUERY', 'The query held no searchable words.' );
        }

        $clean  = self::agent_typography_mode() === 'clean';
        $base   = dwbible_data_dir() . $dataset . '/json/';
        $counts = DwBible_Plugin::verse_counts_by_book();
        $names  = self::agent_book_names_table();
        $tname  = $meta_ds['name'];

        $hits  = [];
        $total = 0;
        foreach ( DwBible_Plugin::book_directory() as $row ) {
            $key = $row['key'];
            if ( $only_key !== null && $key !== $only_key ) { continue; }
            $chapters = isset( $counts[ $key ] ) ? count( $counts[ $key ] ) : 0;
            $bname    = self::agent_cite_name( $key, $lang, (string) ( $names[ $key ][ $lang ] ?? $row['name'] ) );
            $slug     = $row['slug'];
            for ( $ch = 1; $ch <= $chapters; $ch++ ) {
                $file = $base . $key . '/' . $ch . '.json';
                if ( ! file_exists( $file ) ) { continue; }
                $data = json_decode( (string) file_get_contents( $file ), true );
                if ( ! is_array( $data ) || empty( $data['verses'] ) ) { continue; }
                foreach ( $data['verses'] as $v ) {
                    $hay = self::agent_search_normalize( (string) $v['text'], $lang );
                    foreach ( $tokens as $t ) {
                        if ( strpos( $hay, $t ) === false ) { continue 2; }
                    }
                    $total++;
                    if ( $total <= $offset ) { continue; }
                    if ( count( $hits ) >= $limit ) { continue; }
                    $n    = (int) $v['verse'];
                    $text = (string) $v['text'];
                    if ( $clean ) { $text = self::agent_clean_typography( $text ); }
                    $hits[] = [
                        'ref'      => "{$bname} {$ch}:{$n}",
                        'book'     => [ 'key' => $key, 'slug' => $slug, 'name' => $bname, 'osis' => self::agent_osis_for_key( $key ) ],
                        'chapter'  => $ch,
                        'verse'    => $n,
                        'text'     => $text,
                        'citation' => "{$bname} {$ch}:{$n} ({$tname})",
                        'htmlUrl'  => self::agent_html_url( $lang, $key, $ch, $n ),
                        'jsonUrl'  => self::agent_json_url( $dataset, $key, $ch, $n ),
                        'refJson'  => site_url( '/bible-ref.json?q=' . rawurlencode( "{$key} {$ch}:{$n}" ) . '&lang=all' ),
                    ];
                }
            }
        }

        $site      = site_url();
        $only_name = $only_key ? (string) ( $names[ $only_key ][ $lang ] ?? $only_key ) : null;
        self::send_json( [
            '_meta' => [
                'project'     => 'Latin Prayer',
                'projectUrl'  => $site,
                'apiDocs'     => $site . '/llms.txt',
                'content'     => "{$total} verse" . ( $total === 1 ? '' : 's' ) . " matching \"{$raw}\" in {$tname}" . ( $only_name ? " (in {$only_name})" : '' ),
                'query'       => $raw,
                'tokens'      => $tokens,
                'translation' => $meta_ds,
                'book'        => $only_key,
                'bookName'    => $only_name,
                'limit'       => $limit,
                'offset'      => $offset,
                'total'       => $total,
                'shown'       => count( $hits ),
                'truncated'   => ( $offset + count( $hits ) ) < $total,
                // The next request, already built — so continuing never depends
                // on guessing what this API calls its paging parameter.
                'nextOffset'  => ( $offset + count( $hits ) ) < $total ? $offset + count( $hits ) : null,
                'typography'  => $clean ? 'clean' : 'source',
                'noHitsBecause' => $total === 0 ? self::agent_search_orthography_hint( $dataset ) : null,
                'usage'       => 'All words in q must occur in a verse (any order, accent-insensitive; Latin folds j→i). '
                               . 'lang = one of la,en,de,es,fr,it; book = a book to narrow to; limit ≤ ' . self::AGENT_SEARCH_MAX_LIMIT . '; '
                               . 'offset = where to start, 0-based — when `truncated` is true, `nextOffset` is the offset to ask for next. '
                               . 'Each hit carries its own HTML page, its JSON, and a refJson that returns the verse in every language.',
            ],
            'hits' => $hits,
        ] );
    }

    /**
     * Fold a string for matching: lower-case, accents stripped (the site's own
     * search_normalize map, æ→ae included), and for Latin j→i so classical and
     * Clementine spellings meet ("eius" / "ejus"). Punctuation becomes space.
     */
    private static function agent_search_normalize( string $s, string $lang ): string {
        $s = self::search_normalize( $s );
        if ( $lang === 'la' ) {
            $s = str_replace( 'j', 'i', $s );
        }
        $s = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
        return trim( $s );
    }

    /**
     * Why a search of THIS edition may have found nothing.
     *
     * The editions are public domain, which means most of them are old, and a
     * reader searching a modern spelling gets a bare zero that reads like "this
     * Bible does not contain Christ". Scío is the sharp case: "Cristo" occurs
     * 0 times and "Christo" 556, because the Spanish was printed in the 1790s.
     * A dead end that explains itself is the difference between a gap and a
     * wrong conclusion — so the hint rides on the empty answer, where it is
     * needed, and nowhere else.
     *
     * Only editions whose orthography actually differs from today's carry one.
     */
    private static function agent_search_orthography_hint( string $dataset ): ?string {
        $hints = [
            'latin'   => 'The Clementine Vulgate (1592) writes J for consonantal I and uses the æ/œ ligatures — "Jesu", "ejus", "cælum". This search already folds j/i and æ/œ, so either spelling matches.',
            'spanish' => 'The Scío de San Miguel (1790s) keeps 18th-century Spanish orthography: "Christo" and "Jesu-Christo", never "Cristo" or "Jesucristo"; and x where modern Spanish writes j — "dixo" (2,635 verses; "dijo" is in 5), "muger" (728; "mujer" in none). Search the period spelling.',
            'italian' => 'The Martini (1780s) keeps 18th-century Italian: "Gesù Cristo" as two words, older verb and pronoun forms ("nol" for "non lo").',
            'bible'   => 'The Douay-Rheims keeps early-modern English: "thee", "thou", "hath", "shew". Search the form the edition uses.',
        ];
        return $hints[ $dataset ] ?? null;
    }

    /** A JSON error in the same shape the rest of the API uses, then stop. */
    private static function agent_error( int $status, string $code, string $message, array $extra = [] ) {
        status_header( $status );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        nocache_headers();
        echo wp_json_encode( array_merge( [
            'error'   => $code,
            'message' => $message,
        ], $extra, [
            'help' => site_url( '/llms.txt' ),
        ] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }
}
