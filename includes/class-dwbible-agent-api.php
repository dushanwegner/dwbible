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

    /**
     * Names an agent plausibly GUESSES for a parameter, per endpoint → the real name.
     *
     * Ignored, they were silent wrong answers: `language=de` searched the Latin,
     * found nothing and blamed the Clementine's spelling; `page=2` returned page one;
     * `psalms=hebrew` served a different psalm (quality loop tick 111). They are now
     * refused, naming the parameter meant. Anything NOT listed is still ignored, so a
     * cache-buster or a tracking tag passes — which is why `ref` (a referrer tag),
     * `version`/`v` (cache-busters) and `s` (WordPress's search) are not listed.
     *
     * Also the dwcache key's list for .json routes (dwbible.php): a key that dropped
     * these names would answer from the plain URL's cached entry and never refuse.
     */
    const AGENT_MISNAMED_PARAMS = [
        'bible-ref' => [
            'language' => 'lang', 'languages' => 'lang', 'lng' => 'lang', 'locale' => 'lang',
            'translation' => 'lang', 'translations' => 'lang', 'edition' => 'lang', 'bible' => 'lang',
            'query' => 'q', 'citation' => 'q', 'reference' => 'q', 'passage' => 'q', 'verse' => 'q', 'verses' => 'q',
            'psalms' => 'numbering', 'psalm' => 'numbering', 'psalm_numbering' => 'numbering',
            'numeration' => 'numbering', 'versification' => 'numbering',
            'clean' => 'typography',
        ],
        'bible-search' => [
            'language' => 'lang', 'languages' => 'lang', 'lng' => 'lang', 'locale' => 'lang',
            'translation' => 'lang', 'translations' => 'lang', 'edition' => 'lang', 'bible' => 'lang',
            'query' => 'q', 'search' => 'q', 'text' => 'q', 'term' => 'q', 'terms' => 'q', 'words' => 'q', 'phrase' => 'q',
            'max' => 'limit', 'count' => 'limit', 'size' => 'limit', 'per_page' => 'limit', 'perpage' => 'limit',
            'rows' => 'limit', 'num' => 'limit', 'results' => 'limit', 'max_results' => 'limit', 'top' => 'limit',
            'page' => 'offset', 'start' => 'offset', 'skip' => 'offset', 'from' => 'offset', 'cursor' => 'offset',
            'books' => 'book', 'book_name' => 'book', 'bookname' => 'book', 'in_book' => 'book',
        ],
    ];

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
    private static function agent_reject_unknown_langs( string $raw, array $unknown, string $suggestion = 'Pass one or more of la, en, de, es, fr, it — or "all".' ): void {
        if ( trim( $raw ) === '' || ! $unknown ) { return; }
        if ( self::agent_langs_were_asked( [], $raw ) ) { return; }
        $names = [];
        foreach ( self::json_datasets() as $slug => $meta ) { $names[] = $meta['language'] . ' (' . $meta['name'] . ')'; }
        self::agent_error( 400, 'UNSUPPORTED_LANGUAGE', 'This Bible is not held in: ' . implode( ', ', $unknown ) . '.', [
            'available'  => $names,
            'suggestion' => $suggestion,
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
     * The Kings/Samuel books carry TWO real names for the same book — modern
     * and Vulgate/Douay — and dwfactory entry 600/1558 (DW, 2026-09-25) is
     * explicit that the equivalence must be LEARNED, not hidden: "on 1 Samuel
     * note the Vulgate's I Regum, on 3 Kings note the modern 1 Kings." Kept
     * here rather than as a new field on book_names.json's citation table
     * (checked first — no book there carries more than the one name per
     * language today, Malachias/Malachi included, so widening that shape for
     * four rows would be a bigger, noisier change than this).
     *
     * @return string|null null for every book outside this pair.
     */
    private static function agent_kings_samuel_other_naming( string $key ): ?string {
        $other = [
            '1-kings-samuel' => 'The Vulgate\'s own name is "I Regum" ("1 Regum") — the first of its four books of Kings.',
            '2-kings-samuel' => 'The Vulgate\'s own name is "II Regum" ("2 Regum") — the second of its four books of Kings.',
            '3-kings'        => 'The modern name for this book is "1 Kings"; this site\'s Latin title follows the Vulgate\'s own four-book "Regum" division instead.',
            '4-kings'        => 'The modern name for this book is "2 Kings"; this site\'s Latin title follows the Vulgate\'s own four-book "Regum" division instead.',
        ];
        return $other[ $key ] ?? null;
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
        $block = [
            'key'   => $key,
            'slug'  => DwBible_Plugin::latin_slug_for_key( $key ),
            'title' => $dir['name'] ?? ( $names['la'] ?? $key ),
            'names' => $names,
            'osis'  => self::agent_osis_for_key( $key ),
        ];
        $other_naming = self::agent_kings_samuel_other_naming( $key );
        if ( $other_naming !== null ) {
            $block['otherNaming'] = $other_naming;
        }
        return $block;
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

    // ── Kings / Samuel ──────────────────────────────────────────────────────
    //
    // The orchestration half of dwfactory entry 600/1558 (DW, 2026-09-25);
    // DwBible_Kings_Samuel carries the candidate map and the existence check.
    // Called from serve_reference_json() before the ordinary book resolver
    // ever runs, so an ambiguous string can never reach it and come back
    // silently as whichever book's table happens to list it.

    /**
     * The sentence that answers a SINGLE surviving candidate: which book,
     * and which of the two conventions was read to get there. Never silent —
     * rule 3 of the dwfactory decision.
     */
    private static function agent_kings_samuel_read_as( string $raw, string $typed, string $survivor_key, int $ch, int $vf, int $vt ): string {
        $name = self::agent_book_names_table()[ $survivor_key ]['en'] ?? $survivor_key;
        $convention = in_array( $survivor_key, [ '1-kings-samuel', '2-kings-samuel' ], true )
            ? 'the Vulgate/Douay naming'
            : 'the modern naming';
        $cite = $name . ( $ch > 0 ? ' ' . $ch . ( $vf > 0 ? ':' . $vf . ( $vt > $vf ? '-' . $vt : '' ) : '' ) : '' );
        return "\"{$raw}\" was read as {$cite}: {$convention}, where \"{$typed}\" is {$name}.";
    }

    /**
     * A short preview of what a candidate's cited verse actually says — "the
     * opening words" of rule 4, so a reader recognises the passage by its
     * content rather than by a bare book key. Reads the Douay-Rheims (the
     * edition a reader meeting this ambiguity is most likely to hold);
     * chapter 1 verse 1 when no chapter/verse was cited at all, i.e. the
     * book's own true opening.
     */
    private static function agent_kings_samuel_opens_with( string $key, int $ch, int $vf ): string {
        $data = self::agent_chapter_file( 'bible', $key, $ch > 0 ? $ch : 1 );
        if ( $data === null || empty( $data['verses'] ) ) { return ''; }
        $want = $vf > 0 ? $vf : 1;
        foreach ( $data['verses'] as $v ) {
            if ( (int) $v['verse'] === $want ) {
                $words = preg_split( '/\s+/u', trim( (string) $v['text'] ) );
                if ( ! is_array( $words ) || ! $words ) { return ''; }
                $short = implode( ' ', array_slice( $words, 0, 6 ) );
                return $short . ( count( $words ) > 6 ? '…' : '' );
            }
        }
        return '';
    }

    /**
     * Rule 4: several survivors — disambiguate, modern reading first, each
     * candidate named with its opening words so the choice is obvious rather
     * than clerical. Never auto-picks the modern one; that is exactly the
     * failure this whole feature exists to stop.
     *
     * 300 Multiple Choices: not a malformed request (400) and not a missing
     * one (404) — the request is well-formed and names two real answers.
     */
    private static function agent_kings_samuel_disambiguate( string $raw, string $typed, array $survivors, int $ch, int $vf, int $vt ): void {
        $names = self::agent_book_names_table();
        $candidates = [];
        $queries    = [];
        foreach ( $survivors as $key ) {
            $name = $names[ $key ]['en'] ?? $key;
            $ref  = $ch > 0 ? ( ' ' . $ch . ( $vf > 0 ? ':' . $vf . ( $vt > $vf ? '-' . $vt : '' ) : '' ) ) : '';
            $candidates[] = [
                'book'      => self::agent_book_block( $key ),
                'chapter'   => $ch > 0 ? $ch : null,
                'verseFrom' => $vf > 0 ? $vf : null,
                'verseTo'   => $vf > 0 ? $vt : null,
                'citation'  => $name . $ref,
                'opensWith' => self::agent_kings_samuel_opens_with( $key, $ch, $vf ),
            ];
            $queries[] = 'q=' . rawurlencode( $key . $ref );
        }
        self::agent_error( 300, 'BOOK_AMBIGUOUS',
            "\"{$typed}\" names two different books here: a modern reader means {$names[ $survivors[0] ]['en']}, a Douay/Vulgate reader means "
            . ( $names[ $survivors[1] ]['en'] ?? $survivors[1] ) . '. Both are real; this site never guesses which was meant.',
            [
                'requested'  => $raw,
                'candidates' => $candidates,
                'suggestion' => 'Name the book directly to skip this: ' . implode( ' or ', $queries ) . '.',
            ]
        );
    }

    /**
     * Rule 5: no survivors — say which candidates were tried and how long
     * their chapters actually are. A bare VERSE_NOT_FOUND for one silently
     * assumed convention is the failure this whole feature exists to fix.
     */
    private static function agent_kings_samuel_refuse( string $raw, string $typed, array $candidates, array $counts, int $ch, int $vf ): void {
        $names = self::agent_book_names_table();
        $parts = [];
        $chapter_missing = false;
        foreach ( $candidates as $key ) {
            $book_counts = $counts[ $key ] ?? [];
            $name = $names[ $key ]['en'] ?? $key;
            if ( ! $book_counts || $ch > count( $book_counts ) ) {
                $parts[] = "{$name} has " . count( $book_counts ) . ' chapters';
                $chapter_missing = true;
            } else {
                $parts[] = "{$name} {$ch} has " . (int) $book_counts[ $ch - 1 ] . ' verses';
            }
        }
        $code = $chapter_missing ? 'CHAPTER_NOT_FOUND' : 'VERSE_NOT_FOUND';
        $what = $chapter_missing ? "Chapter {$ch}" : "Verse {$vf}";
        self::agent_error( 404, $code, "{$what} does not exist in \"{$typed}\" under either naming: " . implode( ', ', $parts ) . '.', [
            'requested'       => $raw,
            'candidatesTried' => array_map( static fn( $k ) => self::agent_book_block( $k ), $candidates ),
        ] );
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
    private static function agent_psalm_request( int $chapter, string $numbering, int $vf = 0, int $vt = 0 ): ?array {
        if ( $numbering === 'hebrew' ) {
            $v = self::psalm_vulgate_for_hebrew( $chapter );
            if ( $v === null ) { return null; }
            // THE VERSE MOVES TOO. Where the Vulgate joins or splits psalms, a Hebrew
            // verse lives at another Vulgate verse, sometimes in another psalm:
            // Hebrew 10:1 is Vulgate 9:22. Converting only the psalm number served
            // the TITLE of Psalm 9 for "Why, O Lord, dost thou stand afar off" and
            // refused Hebrew 116:10 outright (quality loop tick 98).
            $out_vf = $vf; $out_vt = $vt; $spans = null; $note = null;
            if ( $vf > 0 ) {
                [ $c1, $out_vf ] = self::psalm_vulgate_verse_for_hebrew( $chapter, $vf );
                $v = $c1;
                if ( $vt > 0 ) {
                    [ $c2, $out_vt ] = self::psalm_vulgate_verse_for_hebrew( $chapter, $vt );
                    if ( $c2 !== $c1 ) { $spans = [ $c1, $out_vf, $c2, $out_vt ]; }
                }
                if ( $out_vf !== $vf || $c1 !== self::psalm_vulgate_for_hebrew( $chapter ) || ( $vt > 0 && $out_vt !== $vt ) ) {
                    $note = "Hebrew Psalm {$chapter}:{$vf}" . ( $vt > $vf ? "-{$vt}" : '' ) . " was read as Vulgate Psalm {$c1}:{$out_vf}"
                          . ( $vt > $vf && $spans === null ? "-{$out_vt}" : '' ) . ': the Vulgate numbers these psalms differently.';
                }
            }
            $meta = self::psalm_numbering( $v );
            $meta['requested'] = [ 'system' => 'hebrew', 'number' => $chapter ] + ( $vf > 0 ? [ 'verse' => $vf . ( $vt > $vf ? "-{$vt}" : '' ) ] : [] );
            return [ 'chapter' => $v, 'vf' => $out_vf, 'vt' => $out_vt, 'meta' => $meta, 'spans' => $spans, 'note' => $note ];
        }
        return [ 'chapter' => $chapter, 'vf' => $vf, 'vt' => $vt, 'meta' => self::psalm_numbering( $chapter ), 'spans' => null, 'note' => null ];
    }

    /**
     * A Hebrew (Masoretic) psalm VERSE → [Vulgate chapter, Vulgate verse].
     *
     * Both systems count a psalm's title as its first verses, so inside most
     * psalms the verse number does not change — only where the Vulgate joins two
     * Hebrew psalms or splits one. The offsets are the lengths of the Hebrew
     * psalms, and they add up to this site's own verse counts: Vulgate 9 (39) =
     * Hebrew 9 (21) + 10 (18); Vulgate 113 (26) = Hebrew 114 (8) + 115 (18);
     * Hebrew 116 (19) = Vulgate 114 (9) + 115 (10); Hebrew 147 (20) = Vulgate
     * 146 (11) + 147 (9). tests/test-agent-api.sh pins one verse of each.
     *
     * @return array{0:int,1:int}
     */
    public static function psalm_hebrew_verse_for_vulgate( int $ch, int $v ): array {
        // The inverse of psalm_vulgate_verse_for_hebrew(), over the same offsets.
        if ( $ch <= 8 || $ch >= 148 ) { return [ $ch, $v ]; }
        if ( $ch === 9 )   { return $v <= 21 ? [ 9, $v ] : [ 10, $v - 21 ]; }
        if ( $ch <= 112 )  { return [ $ch + 1, $v ]; }
        if ( $ch === 113 ) { return $v <= 8 ? [ 114, $v ] : [ 115, $v - 8 ]; }
        if ( $ch === 114 ) { return [ 116, $v ]; }
        if ( $ch === 115 ) { return [ 116, $v + 9 ]; }
        if ( $ch <= 145 )  { return [ $ch + 1, $v ]; }
        if ( $ch === 146 ) { return [ 147, $v ]; }
        return [ 147, $v + 11 ]; // 147
    }

    public static function psalm_vulgate_verse_for_hebrew( int $hebrew, int $verse ): array {
        if ( $hebrew === 10 )                  { return [ 9, 21 + $verse ]; }
        if ( $hebrew === 115 )                 { return [ 113, 8 + $verse ]; }
        if ( $hebrew === 116 && $verse >= 10 ) { return [ 115, $verse - 9 ]; }
        if ( $hebrew === 147 && $verse >= 12 ) { return [ 147, $verse - 11 ]; }
        return [ (int) self::psalm_vulgate_for_hebrew( $hebrew ), $verse ];
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

    // ── Verse lists ─────────────────────────────────────────────────────────
    //
    // A citation may name several verses of one chapter that do not run together
    // — "Ps 112:1, 2, 9" is how this site's own calendar prints an introit. Such a
    // request is carried as an ORDERED LIST OF SPANS, [[1,1],[2,2],[9,9]], beside
    // the continuous $vf-$vt range that contains it: the range is what every
    // existence check and every URL works on, the list is what is read and cited.

    /** Move every span by a constant offset (the Malachias and Hebrew-psalm shims). */
    private static function agent_shift_spans( ?array $spans, int $delta ): ?array {
        if ( $spans === null || $delta === 0 ) { return $spans; }
        return array_map( static fn( array $s ): array => [ $s[0] + $delta, $s[1] + $delta ], $spans );
    }

    /** The spans as a citation prints them: "1, 2, 9", "11-12, 14". */
    private static function agent_verse_list_string( array $spans ): string {
        $parts = [];
        foreach ( $spans as $s ) {
            $parts[] = $s[0] . ( $s[1] > $s[0] ? '-' . $s[1] : '' );
        }
        return implode( ', ', $parts );
    }

    /**
     * The verse half of a citation: "", ":28", ":12-13", ":1, 2, 9" — or, for a
     * range that crosses a boundary, ":1-19:42", so the whole reads
     * "Ioannes 18:1-19:42": ONE citation, the way the lectionary prints it.
     * Two citations would make the reader work out that they are one passage.
     *
     * A list is printed BACK as a list — collapsing "112:1, 2, 9" to "112:1-9"
     * would cite six verses the reader never asked for.
     */
    private static function agent_verse_ref( int $vf, int $vt, ?array $spans, int $ch = 0, int $ch_to = 0 ): string {
        if ( $vf <= 0 ) { return ''; }
        if ( $ch > 0 && $ch_to > $ch ) { return ':' . $vf . '-' . $ch_to . ':' . $vt; }
        if ( $spans === null ) { return ':' . $vf . ( $vt > $vf ? '-' . $vt : '' ); }
        return ':' . self::agent_verse_list_string( $spans );
    }

    /** Every verse number the spans name, ascending, each once. */
    private static function agent_verse_numbers( array $spans ): array {
        $seen = [];
        foreach ( $spans as $s ) {
            for ( $n = $s[0]; $n <= $s[1]; $n++ ) { $seen[ $n ] = true; }
        }
        $nums = array_keys( $seen );
        sort( $nums );
        return $nums;
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

    /** One chapter file of one dataset, decoded; null when it is missing or empty. */
    private static function agent_chapter_file( string $dataset, string $key, int $ch ): ?array {
        $file = dwbible_data_dir() . $dataset . '/json/' . $key . '/' . $ch . '.json';
        if ( ! file_exists( $file ) ) { return null; }
        $data = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $data ) || empty( $data['verses'] ) ) { return null; }
        return $data;
    }

    /**
     * The verses of one passage in one dataset, from the chapter file(s).
     *
     * A passage that CROSSES A CHAPTER BOUNDARY is ASSEMBLED, not looked up: the
     * data is one file per chapter, so "Ioannes 18:1-19:42" is the TAIL of
     * chapter 18 (verse 1 to its end), the WHOLE of every chapter between, and
     * the HEAD of chapter 19 (verse 1 to 42). No file holds it; three reads and
     * one list do.
     *
     * A chapter this dataset has not got makes the WHOLE passage unreadable
     * here, and the language is dropped from the answer rather than served half
     * a Passion with nothing saying so — the caller turns "no language could
     * read it" into PASSAGE_UNAVAILABLE.
     *
     * @param ?array $spans A comma list's verse spans, [[from,to],…]; null for the
     *                      ordinary continuous $vf-$vt range. A verse is kept when
     *                      it falls in ANY span, so the chapter's own order and
     *                      uniqueness carry over free even if the spans overlap.
     *                      A list never crosses a chapter, so it is read as before.
     * @param int    $ch_to The chapter the passage ends in; 0 or $ch for one chapter.
     * @return array{translation:array,book:array,verses:array,total:int}|null
     */
    private static function agent_load_passage( string $dataset, string $key, int $ch, int $vf, int $vt, ?array $spans = null, int $ch_to = 0 ): ?array {
        $last  = $ch_to > $ch ? $ch_to : $ch;
        $out   = [];
        $total = 0;
        $meta  = null;
        for ( $c = $ch; $c <= $last; $c++ ) {
            $data = self::agent_chapter_file( $dataset, $key, $c );
            if ( $data === null ) { return null; }
            if ( $meta === null ) { $meta = $data['_meta'] ?? []; }
            $total += count( $data['verses'] );
            // Which verses of THIS chapter the passage holds. A middle chapter
            // is taken whole; PHP_INT_MAX is "to the end of it", so no chapter
            // length has to be looked up here.
            $from = ( $c === $ch )   ? $vf : 1;
            $to   = ( $c === $last ) ? $vt : PHP_INT_MAX;
            foreach ( $data['verses'] as $v ) {
                $n = (int) $v['verse'];
                if ( $spans !== null ) {
                    $wanted = false;
                    foreach ( $spans as $s ) {
                        if ( $n >= $s[0] && $n <= $s[1] ) { $wanted = true; break; }
                    }
                    if ( ! $wanted ) { continue; }
                } elseif ( $from > 0 && ( $n < $from || $n > $to ) ) {
                    continue;
                }
                $verse = [ 'verse' => $n, 'text' => (string) $v['text'] ];
                // A bare verse number stops being an address once a passage
                // holds two chapters — 18:5 and 19:5 are both "5". Each verse
                // carries its chapter THEN AND ONLY THEN, so nothing reading an
                // ordinary within-chapter answer meets a field it has never seen.
                if ( $last > $ch ) { $verse = [ 'chapter' => $c ] + $verse; }
                $out[] = $verse;
            }
        }
        return [
            'translation' => $meta['translation'] ?? [],
            'book'        => $meta['book'] ?? [],
            'verses'      => $out,
            'total'       => $total,
        ];
    }

    /** Two reading notes as one sentence run, or null when neither was made. */
    private static function agent_join_notes( ?string $first, ?string $second ): ?string {
        $parts = array_filter( [ $first, $second ], static fn( $n ) => $n !== null && $n !== '' );
        return $parts ? implode( ' ', $parts ) : null;
    }

    /**
     * Fold every $_GET key to lowercase, first-seen wins on a collision.
     *
     * Query parameter NAMES are case-sensitive by the HTTP spec, but an agent
     * guessing at this API's shape has no reason to know that — `Lang=de` and
     * `Typography=clean` are as plausible a guess as the lowercase form, and
     * unlike a truly MISNAMED parameter (AGENT_MISNAMED_PARAMS), a differently
     * cased REAL name should just work, not be refused. Without this, a
     * request naming `Lang=de` matched no known parameter, so `lang` silently
     * fell back to its default and — worse — dwcache's allowlist match is
     * also case-sensitive, so the request was served from whatever canonical,
     * lang-less entry was already cached (quality loop tick 198, measured live:
     * `?q=Colossians+3:17&Lang=de` served the cached la+en page verbatim).
     * Called before both the misnamed-guess check and every direct read, so
     * both see the same normalised keys.
     */
    private static function agent_normalize_get_keys( array $get ): array {
        $out = [];
        foreach ( $get as $key => $value ) {
            $lower = strtolower( (string) $key );
            if ( ! array_key_exists( $lower, $out ) ) {
                $out[ $lower ] = $value;
            }
        }
        return $out;
    }

    /**
     * Refuse an array-shaped value on a parameter that takes one — `?book[]=Genesis&book[]=Exodus`
     * (or any repeated `name[]=`) arrives as a PHP array, and every caller here does
     * `(string) $_GET[...]`, which silently turns the array into the literal word "Array":
     * `book[]=` answered "The book \"Array\" could not be recognised" (404, misdirecting a
     * malformed request as an unrecognised book name) and `q[]=` searched for the word
     * "Array" itself and returned 200 with a plausible-looking answer (quality loop tick 255).
     */
    private static function agent_reject_array_params( array $names ): void {
        foreach ( $names as $name ) {
            if ( isset( $_GET[ $name ] ) && ! is_scalar( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                self::agent_error( 400, 'UNSUPPORTED_PARAM', "{$name}= takes one value, not a list.", [
                    'suggestion' => "Pass {$name} once: ?{$name}=…",
                ] );
            }
        }
    }

    /** Refuse a guessed parameter name (see AGENT_MISNAMED_PARAMS), naming the real one. */
    private static function agent_reject_misnamed_params( string $endpoint ): void {
        $aliases = self::AGENT_MISNAMED_PARAMS[ $endpoint ] ?? [];
        foreach ( array_keys( $_GET ) as $name ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $real = $aliases[ strtolower( (string) $name ) ] ?? null;
            if ( $real === null || isset( $_GET[ $real ] ) ) { continue; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $value = (string) preg_replace( '/[^\p{L}\p{N} ,:.+\-]/u', '', (string) wp_unslash( is_scalar( $_GET[ $name ] ) ? $_GET[ $name ] : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $value = mb_substr( trim( $value ), 0, 40 );
            $advice = [
                'lang'       => 'The translation is lang=, one of la, en, de, es, fr, it' . ( $endpoint === 'bible-ref' ? ' (a comma list or "all" here)' : ' (one per search)' ) . ': lang=' . ( $value !== '' ? $value : 'de' ) . '.',
                'q'          => ( $endpoint === 'bible-search' ? 'The words to find are q=' : 'The citation is q=' ) . ': q=' . ( $value !== '' ? $value : '…' ) . '.',
                'limit'      => 'The number of hits is limit= (at most ' . self::AGENT_SEARCH_MAX_LIMIT . '): limit=' . ( ctype_digit( $value ) ? $value : '20' ) . '.',
                'offset'     => 'Paging is offset=, the 0-based position of the first hit — with the default limit of 20, the second page is offset=20. Each answer names its nextOffset.',
                'book'       => 'Narrow to one book with book=: book=' . ( $value !== '' ? $value : 'Iob' ) . '.',
                'numbering'  => 'A psalm number is read as Hebrew with numbering=hebrew (the default is vulgate).',
                'typography' => 'Drop the space before : ; ! ? with typography=clean.',
            ][ $real ];
            self::agent_error( 400, 'UNKNOWN_PARAM', "\"{$name}\" is not a parameter of /{$endpoint}.json, so it would not have been applied.", [
                'parameter'  => $real,
                'suggestion' => $advice,
            ] );
        }
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
     *              A range may cross a chapter boundary — "Ioannes 18:1-19:42",
     *              the Good Friday Passion — up to
     *              DwBible_Reference::MAX_CHAPTER_SPAN chapters; the passage is
     *              assembled out of the chapter files and cited as one.
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
        $_GET = self::agent_normalize_get_keys( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        self::agent_reject_misnamed_params( 'bible-ref' );
        self::agent_reject_array_params( [ 'q', 'lang', 'numbering', 'typography' ] );
        $raw = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = trim( $raw );
        if ( $raw === '' ) {
            self::agent_error( 400, 'MISSING_QUERY', 'Pass a citation as ?q=, e.g. /bible-ref.json?q=John+3:16&lang=la,en' );
        }

        // Printed forms the grammar cannot read ("Mt 5,1-12a", "Joh 3,16f") are rewritten
        // first; `$raw` stays what the reader wrote, and the rewrite is reported in readAs.
        $printed = DwBible_Reference::normalize_printed_forms( $raw );
        $parsed  = DwBible_Reference::parse_query( $printed['query'] );

        // KINGS / SAMUEL — dwfactory entry 600/1558, DW 2026-09-25: "1 Kings"
        // (and its twin "2 Kings") name one book under the modern convention
        // and a different one under the Vulgate/Douay this site also serves,
        // and the site must never silently pick one. Checked BEFORE the
        // ordinary resolver, which would otherwise answer straight out of
        // whichever table happens to list the string first. A single
        // survivor (most real citations, once the chapter/verse is checked)
        // answers normally and says which convention was read; several or
        // none stop here and never reach the resolver at all.
        $key     = null;
        $ks_note = null;
        $ks_candidates = DwBible_Kings_Samuel::candidates_for( $parsed['name'] );
        if ( $ks_candidates !== null ) {
            $ks_counts = DwBible_Plugin::verse_counts_by_book();
            $ks_ref    = $parsed['ref'] !== '' ? DwBible_Reference::parse_ref( $parsed['ref'] ) : null;
            $ks_ch     = $ks_ref['ch'] ?? 0;
            $ks_ch_to  = $ks_ref['chTo'] ?? $ks_ch;
            $ks_vf     = ( $ks_ref !== null && $ks_ref['verseGiven'] ) ? $ks_ref['vf'] : 0;
            $ks_vt     = ( $ks_ref !== null && $ks_ref['verseGiven'] ) ? $ks_ref['vt'] : 0;
            $survivors = DwBible_Kings_Samuel::surviving( $ks_candidates, $ks_counts, $ks_ch, $ks_ch_to, $ks_vf );
            if ( count( $survivors ) === 1 ) {
                $key     = $survivors[0];
                $ks_note = self::agent_kings_samuel_read_as( $raw, $parsed['name'], $key, $ks_ch, $ks_vf, $ks_vt );
            } elseif ( count( $survivors ) === 0 ) {
                self::agent_kings_samuel_refuse( $raw, $parsed['name'], $ks_candidates, $ks_counts, $ks_ch, $ks_vf );
            } else {
                self::agent_kings_samuel_disambiguate( $raw, $parsed['name'], $survivors, $ks_ch, $ks_vf, $ks_vt );
            }
        }
        if ( $key === null ) {
            $key = self::internal_key_from_any_book( $parsed['name'], 'latin' );
        }

        // A BARE RANGE — "Jude 20-21", "Philemon 4-6" — is how a one-chapter
        // book names several verses, and the shared grammar cannot split it:
        // with no colon it reads the name as "Jude 20-" and the number as 21.
        // Only tried when the ordinary parse found no book, so a citation the
        // grammar already understood ("Job 20:12-13") is never re-read here.
        $bare_range = null;
        if ( $key === null && preg_match( '/^(.*?)[\s.]*(\d+)\s*[-–—]\s*(\d+)\s*$/u', $printed['query'], $bm ) ) {
            $alt = self::internal_key_from_any_book( trim( $bm[1] ), 'latin' );
            if ( $alt !== null ) {
                $key        = $alt;
                $bare_range = [ (int) $bm[2], (int) $bm[3] ];
            }
        }

        // A COMMA LIST OF VERSES — "Ps 112:1, 2, 9", "Ps 44:11-12, 14" — is the form
        // this site's own calendar prints its Mass readings in, and the shared grammar
        // cannot split it either: anchored at both ends it reads the name as
        // "Ps 112:1, 2," and the number as 9. Like the bare range above, it is only
        // tried once the ordinary parse has found no book, so "Ps 44,11" (the German
        // chapter comma) and every other citation the grammar already understood is
        // never re-read here.
        $verse_spans  = null;
        $list_chapter = 0;
        if ( $key === null ) {
            $vl = DwBible_Reference::parse_verse_list( $printed['query'] );
            if ( $vl !== null ) {
                $alt = self::internal_key_from_any_book( $vl['name'], 'latin' );
                if ( $alt !== null ) {
                    $key          = $alt;
                    $verse_spans  = $vl['spans'];
                    $list_chapter = $vl['chapter'];
                }
            }
        }

        if ( $key === null ) {
            // BLAME THE RIGHT HALF. The shared citation grammar is anchored at
            // both ends, so a citation it cannot read — "Joh 3,16.18", a list of
            // separate passages — parses as the BOOK NAME "Joh 3," and then
            // fails the book LOOKUP, and the refusal said no book could be
            // recognised while listing "Joh" as a supported abbreviation in the
            // same sentence. A model reading that retries book spellings
            // forever, because the message points at the one part of the query
            // that was already correct.
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
                        // Advice for THIS shape of failure — a list, "ff.", a missing
                        // separator or a real cross-chapter range (tick 110).
                        'suggestion' => DwBible_Reference::citation_advice( $raw, (string) ( self::agent_book_names_table()[ $head ]['en'] ?? $head ) ),
                    ] );
            }
            self::agent_error( 404, 'BOOK_NOT_RECOGNISED', "No book in \"{$raw}\" could be recognised.", [
                'suggestion' => 'Book names resolve in Latin, English, German, Spanish, French and Italian, plus standard abbreviations (Gen, Ps, Mt, Jn, 1 Cor, Gal, Apoc). The full list: ' . site_url( '/bible-books.json' ),
            ] );
        }

        $counts   = DwBible_Plugin::verse_counts_by_book();
        $chapters = isset( $counts[ $key ] ) ? count( $counts[ $key ] ) : 0;
        $en_name  = self::agent_book_names_table()[ $key ]['en'] ?? $key;

        // $ch_to is the chapter the passage ENDS in. It equals $ch for everything
        // but a cross-chapter range, so every check below reads as it always did.
        $ch = 0; $vf = 0; $vt = 0; $ch_to = 0; $read_as = null;
        if ( $verse_spans !== null ) {
            // $vf/$vt are the SPAN THAT CONTAINS the list. Every existence check,
            // shim and URL below is written for one continuous range and keeps
            // working unchanged; the list only narrows which verses of that span
            // are actually read, cited and counted.
            $ch = $list_chapter;
            $vf = min( array_column( $verse_spans, 0 ) );
            $vt = max( array_column( $verse_spans, 1 ) );
            $ch_to = $ch;
        } elseif ( $bare_range !== null ) {
            $single = self::agent_single_chapter_verses( $chapters, $bare_range[0], $bare_range[1], $en_name );
            if ( $single === null ) {
                // In a book of many chapters "Genesis 1-3" could mean three
                // chapters or three verses of one. Neither is servable as
                // written, and guessing is how a reader is handed the wrong
                // passage without being told, so it is refused BY NAME.
                // The retry advice must be one of the two readings, not a third.
                // It used to offer "{book} a:b" "for verses" — for "Genesis 1-3"
                // that is Genesis 1:3, ONE verse, which a model following the
                // advice would present as the passage (quality loop tick 92).
                [ $ra, $rb ] = $bare_range;
                self::agent_error( 400, 'AMBIGUOUS_RANGE', "\"{$raw}\" could mean chapters {$ra}-{$rb} or verses {$ra}-{$rb} of one chapter.", [
                    'suggestion' => "Say which. Verses {$ra}-{$rb}: name the chapter, \"{$en_name} <chapter>:{$ra}-{$rb}\". Chapters {$ra}-{$rb}: one request per chapter, \"{$en_name} {$ra}\" to \"{$en_name} {$rb}\".",
                ] );
            }
            $ch = $single['chapter']; $vf = $single['from']; $vt = $single['to']; $read_as = $single['note'];
            $ch_to = $ch;
        } elseif ( $parsed['ref'] !== '' && ( $r = DwBible_Reference::parse_ref( $parsed['ref'] ) ) !== null ) {
            // One parser for the canonical ref string, shared with the router's
            // `?q=` resolver — including the rule that tells a range-end CHAPTER
            // ("18:1-19:42") from a range-end VERSE ("24:13-35").
            $ch          = $r['ch'];
            $verse_given = $r['verseGiven'];
            $vf          = $r['vf'];
            $vt          = $r['vt'];
            $ch_to       = $r['chTo'];

            // A chapter or verse of "0" is never a real address — every book
            // and every chapter starts at 1 — but every existence check below
            // is guarded by "> 0", written for the ORDINARY meaning of a zero
            // here: "not given". A typed "0" would fall through every one of
            // them unnoticed and come back as the whole book (chapter 0) or
            // the whole chapter (verse 0) instead of refused (quality loop
            // tick 200: "Ps 0:1" answered 200 with no chapter and no text;
            // "Genesis 1:0" answered 200 with the whole of chapter 1). The
            // range END has the same two zeros — "John 3:16-4:0" came back
            // well-formed and EMPTY, because the verse filter kept nothing.
            $zero_chapter = ( $ch === 0 || $ch_to === 0 );
            if ( $zero_chapter || ( $verse_given && ( $vf === 0 || $vt === 0 ) ) ) {
                self::agent_error( 404, $zero_chapter ? 'CHAPTER_NOT_FOUND' : 'VERSE_NOT_FOUND',
                    $zero_chapter ? "There is no chapter 0 in \"{$raw}\"." : "There is no verse 0 in \"{$raw}\".",
                    [ 'suggestion' => 'Chapters and verses are numbered from 1.' ] );
            }

            // The chapter a cross-chapter range ENDS in has to exist. Asked
            // BEFORE the ceiling below, so "Jn 18:1-99:42" is told that chapter
            // 99 is not there rather than that 82 chapters are too many — and is
            // never advised to ask for a chapter this book has not got.
            if ( $ch_to > $ch && $chapters > 0 && $ch_to > $chapters ) {
                self::agent_error( 404, 'CHAPTER_NOT_FOUND', "Chapter {$ch_to} does not exist in this book.", [
                    'suggestion' => "This book has {$chapters} chapters.",
                    'bookIndex'  => self::agent_json_url( 'latin', $key ),
                ] );
            }

            // THE SPAN CEILING. A citation may cross a chapter boundary; it may
            // not stand in for "read me the book". See
            // DwBible_Reference::MAX_CHAPTER_SPAN for why the number is five.
            if ( $ch_to - $ch + 1 > DwBible_Reference::MAX_CHAPTER_SPAN ) {
                $span = $ch_to - $ch + 1;
                self::agent_error( 400, 'RANGE_TOO_LONG',
                    "\"{$raw}\" spans {$span} chapters; one citation reads at most " . DwBible_Reference::MAX_CHAPTER_SPAN . '.',
                    [
                        'suggestion' => 'A range may cross a chapter boundary — "' . $en_name . ' ' . $ch . ':' . $vf . '-'
                                      . ( $ch + DwBible_Reference::MAX_CHAPTER_SPAN - 1 ) . ':…" reads in one request. Beyond that, ask for each part: '
                                      . "\"{$en_name} {$ch}\" to \"{$en_name} {$ch_to}\", one chapter at a time.",
                    ] );
            }

            // "Jude 3" — a bare number on a one-chapter book is its VERSE.
            if ( $vf === 0 ) {
                $single = self::agent_single_chapter_verses( $chapters, $ch, 0, $en_name );
                if ( $single !== null ) {
                    $ch = $single['chapter']; $vf = $single['from']; $vt = $single['to']; $read_as = $single['note'];
                    $ch_to = $ch;
                }
            }
        }

        // Malachias 4 — a chapter this text does not have, under a number every
        // printed Vulgate uses.
        // Only for a passage inside ONE chapter: the shim moves a whole chapter
        // by a constant delta, and a range that starts or ends outside chapter 4
        // has no single delta. A cross-chapter range reaching into the printed
        // "chapter 4" therefore gets no translation and is refused further down
        // — the dataset has no such chapter file, so nothing can read the
        // passage and the answer is PASSAGE_UNAVAILABLE, never half of it.
        $mal = $ch_to === $ch ? self::agent_malachias_shim( $key, $ch, $vf, $vt ) : null;
        if ( $mal !== null ) {
            // A verse list rides along on the same offset. Both shims that move a
            // passage — this one and the Hebrew psalm numbering below — shift every
            // verse of ONE chapter by a CONSTANT delta, so shifting the containing
            // span and shifting each item of the list are the same arithmetic.
            $delta       = $vf > 0 ? $mal['from'] - $vf : 0;
            $verse_spans = self::agent_shift_spans( $verse_spans, $delta );
            $ch = $mal['chapter']; $vf = $mal['from']; $vt = $mal['to']; $read_as = $mal['note'];
            $ch_to = $ch;
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
        if ( $ch_to === $ch && $vf > 0 && $vt > 0 && $vt < $vf ) {
            self::agent_error( 400, 'RANGE_REVERSED',
                "\"{$raw}\" asks for verses {$vf} to {$vt}, which runs backwards.",
                [ 'suggestion' => "A range goes low to high — \"{$ch}:{$vt}-{$vf}\" is probably what was meant." ] );
        }
        // The same rule one level up: "Jn 19:42-18:1" names its chapters the
        // wrong way round, which no clamp can rescue.
        if ( $ch > 0 && $ch_to > 0 && $ch_to < $ch ) {
            self::agent_error( 400, 'RANGE_REVERSED',
                "\"{$raw}\" asks for {$ch}:{$vf} to {$ch_to}:{$vt}, which runs backwards.",
                [ 'suggestion' => "A range goes low to high — \"{$ch_to}:{$vt}-{$ch}:{$vf}\" is probably what was meant." ] );
        }

        // Psalms: the reader may have typed the Hebrew number.
        $numbering  = self::agent_numbering_mode();
        $psalm_meta = null;
        if ( $key === 'psalms' && $ch > 0 && $ch_to > $ch ) {
            // A CROSS-CHAPTER psalm range converts END BY END. Where the Vulgate
            // joins or splits a Hebrew psalm the two ends move by different
            // amounts — Hebrew 116:1 is Vulgate 114:1 while Hebrew 116:19 is
            // Vulgate 115:10 — so the single delta the within-chapter path below
            // applies to the whole span cannot carry both. Two conversions, each
            // with the helper that already knows the offsets.
            if ( $numbering === 'hebrew' ) {
                if ( self::psalm_vulgate_for_hebrew( $ch ) === null || self::psalm_vulgate_for_hebrew( $ch_to ) === null ) {
                    self::agent_error( 404, 'CHAPTER_NOT_FOUND', "There is no Psalm {$ch}.", [ 'suggestion' => 'Psalms run 1-150.' ] );
                }
                [ $c1, $f1 ] = self::psalm_vulgate_verse_for_hebrew( $ch, $vf );
                [ $c2, $t2 ] = self::psalm_vulgate_verse_for_hebrew( $ch_to, $vt );
                $read_as = self::agent_join_notes( $read_as,
                    "Hebrew Psalms {$ch}:{$vf}-{$ch_to}:{$vt} were read as Vulgate {$c1}:{$f1}-{$c2}:{$t2}: the Vulgate numbers these psalms differently." );
                $ch = $c1; $vf = $f1; $ch_to = $c2; $vt = $t2;
            }
            $psalm_meta = self::psalm_numbering( $ch );
        } elseif ( $key === 'psalms' && $ch > 0 ) {
            $pr = self::agent_psalm_request( $ch, $numbering, $vf, $vt );
            if ( $pr === null ) {
                self::agent_error( 404, 'CHAPTER_NOT_FOUND', "There is no Psalm {$ch}.", [ 'suggestion' => 'Psalms run 1-150.' ] );
            }
            if ( $pr['spans'] !== null ) {
                [ $c1, $f1, $c2, $t2 ] = $pr['spans'];
                self::agent_error( 400, 'CITATION_NOT_UNDERSTOOD', "\"{$raw}\" runs across two Vulgate psalms: it is Vulgate {$c1}:{$f1}-end and {$c2}:1-{$t2}.", [
                    'suggestion' => "Ask for each part: \"Ps {$c1}:{$f1}-\" to the end of Vulgate {$c1}, and \"Ps {$c2}:1-{$t2}\" — both without numbering=hebrew, as they are already Vulgate numbers.",
                ] );
            }
            $verse_spans = self::agent_shift_spans( $verse_spans, $vf > 0 && $pr['vf'] > 0 ? $pr['vf'] - $vf : 0 );
            $ch         = $pr['chapter'];
            $ch_to      = $ch;
            $vf         = $pr['vf'];
            $vt         = $pr['vt'];
            $psalm_meta = $pr['meta'];
            if ( $pr['note'] !== null ) { $read_as = $read_as !== null ? $read_as . ' ' . $pr['note'] : $pr['note']; }
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
            // The verse a range ENDS on belongs to the chapter it ends IN, which
            // for a cross-chapter range is not the one it started in.
            $n_end = isset( $book_counts[ $ch_to - 1 ] ) ? (int) $book_counts[ $ch_to - 1 ] : $n;
            if ( $vf > $n ) {
                // Name the citation the READER typed as well as the verse we
                // looked for: after a translation ("Mal 4:9" → 3:27) an error
                // about "verse 27 of chapter 3" mentions two numbers they never
                // wrote, and reads like a bug in the answer rather than in the
                // question.
                self::agent_error( 404, 'VERSE_NOT_FOUND', "Verse {$vf} does not exist in chapter {$ch}.", array_filter( [
                    'requested'   => self::agent_join_notes( $printed['note'], $read_as ) !== null ? $raw : null,
                    'readAs'      => self::agent_join_notes( $printed['note'], $read_as ),
                    'suggestion'  => "This chapter has {$n} verses (1-{$n}).",
                    'chapterJson' => self::agent_json_url( 'latin', $key, $ch ),
                ] ) );
            }
            // A LIST NAMES EVERY VERSE IT WANTS, so a verse the chapter has not got
            // is refused BY NAME rather than dropped: "Ps 112:1, 2, 99" coming back
            // as verses 1 and 2 would be a well-formed answer silently missing the
            // one verse the reader wrote a number for. A range INSIDE the list that
            // merely runs past the end is clamped and said, as anywhere else here.
            if ( $verse_spans !== null ) {
                foreach ( $verse_spans as $span ) {
                    if ( $span[0] > $n ) {
                        self::agent_error( 404, 'VERSE_NOT_FOUND', "Verse {$span[0]} does not exist in chapter {$ch}.", array_filter( [
                            'requested'   => $raw,
                            'readAs'      => self::agent_join_notes( $printed['note'], $read_as ),
                            'suggestion'  => "\"{$raw}\" names verse {$span[0]}; this chapter has {$n} verses (1-{$n}).",
                            'chapterJson' => self::agent_json_url( 'latin', $key, $ch ),
                        ] ) );
                    }
                }
                $over = false;
                foreach ( $verse_spans as &$span ) {
                    if ( $span[1] > $n ) { $span[1] = $n; $over = true; }
                }
                unset( $span );
                if ( $over ) {
                    $vt      = $n;
                    $read_as = self::agent_join_notes( $read_as, "\"{$raw}\" was read as {$ch}:"
                             . self::agent_verse_list_string( $verse_spans ) . ": this chapter ends at verse {$n}." );
                }
            } elseif ( $vt > $n_end ) {
                // An over-long range is clamped rather than refused — the reader
                // named a real verse and meant to read to the end — but a clamp
                // nobody is told about is a silently different passage. The same
                // sentence serves a cross-chapter range; only the chapter it
                // names changes, because that is the chapter that ran out.
                $read_as = $ch_to > $ch
                    ? "\"{$raw}\" was read as {$ch}:{$vf}-{$ch_to}:{$n_end}: chapter {$ch_to} ends at verse {$n_end}."
                    : "\"{$raw}\" was read as {$ch}:{$vf}-{$n_end}: this chapter ends at verse {$n_end}.";
                $vt = $n_end;
            }
        }

        // THE ADDRESSES CAN ONLY SPAN A LIST. This site names a passage by ONE
        // continuous range — /latin/psalms/112/1-9.json, /en/biblia/psalmi/112:1-9/ —
        // so a discontinuous list has no address of its own and the URLs below hold
        // verses the citation does not name. The text and `verseNumbers` are the
        // list; a reader following a link otherwise finds more verses than they
        // asked for, with nothing to tell them which was which.
        if ( $verse_spans !== null && count( self::agent_verse_numbers( $verse_spans ) ) < $vt - $vf + 1 ) {
            $read_as = self::agent_join_notes( $read_as, "\"{$raw}\" names verses "
                     . self::agent_verse_list_string( $verse_spans ) . " of chapter {$ch}, and the text here is those verses only. "
                     . "The page and JSON addresses cover {$ch}:{$vf}-{$vt}, the smallest continuous range holding them, "
                     . 'because a passage on this site is addressed by one range.' );
        }

        $book      = self::agent_book_block( $key );
        $by_lang   = self::agent_dataset_by_lang();
        $lang_raw  = isset( $_GET['lang'] ) ? (string) wp_unslash( $_GET['lang'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $langs     = self::agent_parse_langs( $lang_raw, [ 'latin', 'bible' ], $lang_unknown );
        self::agent_reject_unknown_langs( $lang_raw, $lang_unknown );
        $clean     = self::agent_typography_mode() === 'clean';
        $site      = site_url();

        // AN ADDRESS HOLDS ONE CHAPTER. A page and a JSON file on this site are
        // /{book}/{ch}:{v}-{v} — one chapter — so a passage that crosses a
        // boundary has no address of its own. The links then open the part of it
        // that lives in the FIRST chapter, and readAs says which part that is and
        // where the rest is. The same shape as the verse-list note above: the
        // text is what was asked for, the links are as close as the site can get.
        $url_vt = $vt;
        if ( $ch_to > $ch ) {
            // To the end of the first chapter — or, for a book whose lengths we
            // do not carry, just the verse the passage starts at. Never $vt: that
            // would build an address naming verses of the WRONG chapter.
            $url_vt    = isset( $book_counts[ $ch - 1 ] ) ? (int) $book_counts[ $ch - 1 ] : $vf;
            $first_cut = $ch . ':' . $vf . ( $url_vt > $vf ? '-' . $url_vt : '' );
            $read_as   = self::agent_join_notes( $read_as, "\"{$raw}\" runs from {$ch}:{$vf} to {$ch_to}:{$vt}, and the text here is the whole of it. "
                       . "A passage on this site is addressed by one chapter, so the page and JSON links cover {$first_cut}, the part of it in chapter {$ch}; "
                       . 'the rest is in ' . ( $ch_to - $ch === 1 ? "chapter {$ch_to}" : 'chapters ' . ( $ch + 1 ) . "-{$ch_to}" )
                       . '. Every verse below carries the chapter it belongs to.' );
        }

        // Every address the passage has, in every language — the point of this endpoint.
        $urls = [ 'html' => [], 'json' => [] ];
        foreach ( [ 'en', 'de', 'es', 'fr', 'it' ] as $lang ) {
            $urls['html'][ $lang ] = self::agent_html_url( $lang, $key, $ch, $vf, $url_vt );
        }
        foreach ( $by_lang as $lang => $ds ) {
            $urls['json'][ $lang ] = self::agent_json_url( $ds, $key, $ch, $vf, $url_vt );
        }

        $citations = [];
        foreach ( $book['names'] as $lang => $name ) {
            $cite = $ch > 0 ? self::agent_cite_name( $key, (string) $lang, (string) $name ) : (string) $name;
            // Cited AS THE SOURCE PRINTS IT — "Ioannes 18:1-19:42", one citation,
            // never two — because that is the reading, and a reader handed two
            // citations has to work out for themselves that they are one passage.
            $citations[ $lang ] = $cite . ( $ch > 0 ? ' ' . $ch . self::agent_verse_ref( $vf, $vt, $verse_spans, $ch, $ch_to ) : '' );
        }

        $passages = [];
        if ( $ch > 0 ) {
            foreach ( $langs as $ds ) {
                $lang = array_search( $ds, $by_lang, true );
                $p    = self::agent_load_passage( $ds, $key, $ch, $vf, $vt, $verse_spans, $ch_to );
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
                'resolved'   => [ 'book' => $key, 'chapter' => $ch, 'chapterTo' => $ch_to > $ch ? $ch_to : null, 'verseFrom' => $vf ?: null, 'verseTo' => $vf ? $vt : null ],
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
                'readAs'     => self::agent_join_notes( self::agent_join_notes( $printed['note'], $read_as ), $ks_note ),
                'typography' => $clean ? 'clean' : 'source',
                'usage'      => 'q = any citation form, a comma list of verses in one chapter ("Ps 112:1, 2, 9") and a range across a chapter boundary '
                              . '("Ioannes 18:1-19:42", at most ' . DwBible_Reference::MAX_CHAPTER_SPAN . ' chapters) included; '
                              . 'lang = comma list of la,en,de,es,fr,it (or "all") for the text; '
                              . 'numbering=hebrew to read a Psalm number as Masoretic; typography=clean to drop the space before : ; ! ?',
            ],
            'ref' => [
                'book'         => $book,
                'chapter'      => $ch > 0 ? $ch : null,
                // Only a range that CROSSES A BOUNDARY fills this: the chapter
                // it ends in. null means the passage is inside `chapter`, so a
                // consumer that has never heard of it still reads every
                // within-chapter answer correctly — and one that has cannot
                // mistake verseTo for a verse of the opening chapter.
                'chapterTo'    => $ch_to > $ch ? $ch_to : null,
                'verseFrom'    => $vf > 0 ? $vf : null,
                'verseTo'      => $vf > 0 ? $vt : null,
                // Only a COMMA LIST fills this: every verse it names, in order.
                // verseFrom/verseTo bound them, and for a discontinuous list that
                // bound holds verses the citation does not name — so a consumer
                // reading only the two numbers gets a range, never a wrong verse,
                // and one reading this field gets exactly what was asked for.
                'verseNumbers' => $verse_spans !== null ? self::agent_verse_numbers( $verse_spans ) : null,
                'citation'     => $citations,
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
     *          case- and accent-insensitive; in Latin j/i are folded so "ejus" finds "eius";
     *          in English British/American spelling is folded so "neighbour" finds "neighbor").
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
        $_GET = self::agent_normalize_get_keys( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        self::agent_reject_misnamed_params( 'bible-search' );
        self::agent_reject_array_params( [ 'q', 'lang', 'book', 'limit', 'offset', 'numbering', 'typography' ] );
        // numbering= means something real on /bible-ref.json and every psalm URL, so an
        // agent that just asked for numbering=hebrew there will pass it here too — but a
        // search hit's own citation is always Vulgate (hebrewRef already carries the Hebrew
        // number beside it), and this endpoint never read the parameter to apply it. That
        // answered 200 with an identical hit whether numbering was hebrew, garbage, or
        // absent — the same silent-no-op shape tick 87 refused for a multi-language `lang=`
        // rather than honouring only part of the request (quality loop tick 321).
        if ( isset( $_GET['numbering'] ) && trim( (string) wp_unslash( $_GET['numbering'] ) ) !== '' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::agent_error( 400, 'UNSUPPORTED_PARAM', 'A search hit already carries both psalm numbers; numbering= has no effect here.', [
                'suggestion' => 'Every psalm hit\'s citation is Vulgate, with the Hebrew number already given in hebrewRef — no numbering= needed. To read one verse under numbering=hebrew, use /bible-ref.json.',
            ] );
        }
        // wp_check_invalid_utf8() (inside sanitize_text_field) wipes the WHOLE string to
        // '' the moment it holds even one invalid UTF-8 byte, not just the bad byte — a
        // truncated multi-byte sequence, a lone continuation byte, or an overlong encoding
        // pasted mid-word by a broken PDF extractor. Without this check that read back as
        // MISSING_QUERY ("Pass the words to find as ?q=…"), telling a caller who DID pass
        // bytes that they passed nothing — the same wrong-diagnosis shape as tick 93's
        // citation hint. Comparing against the unsanitized-but-unslashed value distinguishes
        // "sent nothing" from "sent bytes that are not valid UTF-8" (quality loop tick 267).
        $raw_unslashed = isset( $_GET['q'] ) ? trim( (string) wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw           = $raw_unslashed !== '' ? trim( sanitize_text_field( $raw_unslashed ) ) : '';
        if ( $raw === '' ) {
            if ( $raw_unslashed !== '' ) {
                self::agent_error( 400, 'INVALID_ENCODING', 'q contains bytes that are not valid UTF-8, so no words could be read from it.' );
            }
            self::agent_error( 400, 'MISSING_QUERY', 'Pass the words to find as ?q=, e.g. /bible-search.json?q=dilexerunt+tenebras&lang=la' );
        }

        $lang_raw = isset( $_GET['lang'] ) ? (string) wp_unslash( $_GET['lang'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $langs    = self::agent_parse_langs( $lang_raw, [ 'latin' ], $lang_unknown );
        self::agent_reject_unknown_langs( $lang_raw, $lang_unknown, 'Pass ONE of la, en, de, es, fr, it — a search covers one translation.' );
        // ONE TRANSLATION PER SEARCH. The parser is shared with the resolver, where
        // "la,en" and "all" mean something, and this endpoint used to take
        // $langs[0] and drop the rest in silence: "la,en" searched Latin only and
        // "all" searched Latin only, while the refusal above advertised both
        // (quality loop tick 87). A request this endpoint cannot honour as asked
        // is refused with the way to ask it — the rule `numbering=` already keeps.
        if ( count( $langs ) > 1 ) {
            $codes = [];
            foreach ( $langs as $ds ) { $codes[] = (string) array_search( $ds, self::agent_dataset_by_lang(), true ); }
            self::agent_error( 400, 'UNSUPPORTED_PARAM', 'A search covers one translation; lang named ' . count( $langs ) . ' (' . implode( ', ', $codes ) . ').', [
                'suggestion' => 'Ask once per language: lang=' . implode( ', then lang=', $codes ) . '. To read one verse in every language, use /bible-ref.json?lang=all.',
            ] );
        }
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
                    // A psalm hit is cited by its VULGATE number in every language,
                    // while most readers' Bibles use the Hebrew one: "Psalm 22:1"
                    // is "My God, my God…" to them. The resolver says so; a search
                    // hit said nothing (quality loop tick 99).
                    if ( $key === 'psalms' ) {
                        [ $hc, $hv ] = self::psalm_hebrew_verse_for_vulgate( $ch, $n );
                        $hits[ count( $hits ) - 1 ]['hebrewRef'] = "{$hc}:{$hv}";
                    }
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
                'content'     => "{$total} verse" . ( $total === 1 ? '' : 's' ) . ' matching "' . str_replace( '"', "'", $raw ) . '" in ' . $tname . ( $only_name ? " (in {$only_name})" : '' ),
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
                'noHitsBecause' => $total === 0 ? ( self::agent_search_citation_hint( $raw, $lang ) ?? self::agent_search_orthography_hint( $dataset ) ) : null,
                'usage'       => 'All words in q must occur in a verse (any order, accent-insensitive; Latin folds j→i; English folds British and American spelling, honour/honor; Spanish folds period and modern spelling, quando/cuando; Italian likewise, Davidde/Davide, figliuolo/figlio). '
                               . 'lang = one of la,en,de,es,fr,it; book = a book to narrow to; limit ≤ ' . self::AGENT_SEARCH_MAX_LIMIT . '; '
                               . 'offset = where to start, 0-based — when `truncated` is true, `nextOffset` is the offset to ask for next. '
                               . 'Each hit carries its own HTML page, its JSON, and a refJson that returns the verse in every language.',
            ],
            'hits' => $hits,
        ] );
    }

    /**
     * British and American spelling, folded to one form for English search.
     *
     * The Douay-Rheims text is MIXED: "neighbour" 182 times and "neighbor" 4 —
     * one of the four is Matthew 19:19 — "honour" 263 / "honor" 2, "labour" 246 /
     * "labor" 6 (Matthew 11:28), "fulfil" 52 / "fulfill" 2. So a search in either
     * spelling silently lost the other half, and a reader cannot know which one a
     * verse uses (quality loop tick 105).
     *
     * WHOLE STEMS, never a suffix rule: a generic our→or or re→er would turn
     * "four", "your" and "there" into different words. Each key is folded where
     * it occurs, so derived forms follow ("dishonour", "neighbours", "ploughshare").
     * Applied to the query AND the verse, so the direction does not matter; the
     * one US→UK pair (fulfill→fulfil) is chosen so "fulfilled" folds identically
     * from both spellings. strtr: one pass, longest key first.
     */
    private const AGENT_SEARCH_EN_SPELLING = [
        // -our / -or
        'saviour' => 'savior', 'honour' => 'honor', 'neighbour' => 'neighbor', 'labour' => 'labor',
        'favour' => 'favor', 'colour' => 'color', 'odour' => 'odor', 'splendour' => 'splendor',
        'valour' => 'valor', 'harbour' => 'harbor', 'rumour' => 'rumor', 'vapour' => 'vapor',
        'armour' => 'armor', 'clamour' => 'clamor', 'fervour' => 'fervor', 'ardour' => 'ardor',
        'rigour' => 'rigor', 'vigour' => 'vigor', 'humour' => 'humor', 'savour' => 'savor',
        'succour' => 'succor', 'dolour' => 'dolor', 'behaviour' => 'behavior', 'endeavour' => 'endeavor',
        'parlour' => 'parlor', 'candour' => 'candor', 'tumour' => 'tumor',
        // -re / -er
        'centre' => 'center', 'sepulchre' => 'sepulcher', 'sceptre' => 'scepter', 'mitre' => 'miter',
        'nitre' => 'niter', 'theatre' => 'theater', 'spectre' => 'specter', 'lustre' => 'luster',
        'meagre' => 'meager', 'sombre' => 'somber', 'fibre' => 'fiber',
        // -ence / -ense
        'defence' => 'defense', 'offence' => 'offense', 'pretence' => 'pretense', 'licence' => 'license',
        // doubled consonant
        'worshipp' => 'worship', 'travell' => 'travel', 'counsell' => 'counsel', 'marvell' => 'marvel',
        'quarrell' => 'quarrel', 'jewell' => 'jewel', 'levell' => 'level', 'fulfill' => 'fulfil',
        'skilful' => 'skillful', 'wilful' => 'willful',
        // single words
        'judgement' => 'judgment', 'acknowledgement' => 'acknowledgment', 'plough' => 'plow',
        'mould' => 'mold', 'grey' => 'gray', 'sulphur' => 'sulfur', 'practise' => 'practice',
    ];

    /**
     * Period and modern Spanish spelling, folded to one form for Spanish search.
     *
     * The Scío de San Miguel (1790s) spells as its century did, with a handful of
     * verses already modernised: "quando" in 1,846 verses and "cuando" in 6, "dixo"
     * 2,734 / "dijo" 5, "Christo" 556 / "Cristo" 0. A modern search found the few,
     * and because a few is not none, no hint said why (quality loop tick 117).
     *
     * Two kinds of key, both applied after accents are stripped, to query and verse:
     * DIGRAPHS modern Spanish never writes (ph, th, chr, qua, quo), so a general rule
     * is safe — "propheta", "philistheo", "thesoro", "sepulchro", "pasqua"; and
     * STEMS where the period letter is still an ordinary modern one (x, y), so only
     * named families fold — "dixo"/"bendixo", "debaxo"/"embaxada", "muger", "reyno".
     * Every corpus word each key touches was listed and is the same word modernised.
     * No generic x→j or y→i: "hoy", "ley" and "texto" are guarded.
     */
    private const AGENT_SEARCH_ES_SPELLING = [
        // digraphs modern Spanish does not write
        'ph' => 'f', 'th' => 't', 'chr' => 'cr', 'qua' => 'cua', 'quo' => 'cuo',
        // x → j families
        'dix' => 'dij', 'trax' => 'traj', 'dex' => 'dej', 'bax' => 'baj', 'exerci' => 'ejerci',
        'exempl' => 'ejempl', 'execut' => 'ejecut', 'xefe' => 'jefe', 'relox' => 'reloj',
        // other period stems and names
        'muger' => 'mujer', 'reyn' => 'rein', 'moyses' => 'moises', 'joseph' => 'jose',
        'jerusalem' => 'jerusalen', 'myster' => 'mister', 'martyr' => 'martir', 'hymn' => 'himn',
        'psalm' => 'salm',
        // "Jesu-Christo" is two words; "Jesucristo" one; "Jesus" and "Jesu" one name
        'jesucrist' => 'jesu crist', 'jesus' => 'jesu',
    ];

    /**
     * Period and modern Italian spelling, folded to one form for Italian search.
     *
     * The Martini (1780s) spells as its century did: "Davidde" in 471 verses and
     * "Davide" in one, "figliuolo" 2,235 / "figlio" 100, "sacrifizio"/"sagrifizio" 162 /
     * "sacrificio" 6, plurals in -j ("giudizj", "prodigj", "empj"). A modern search found
     * the few — "Davide" ONE verse, "elemosina" and "Figlio dell'uomo" none — and a few
     * gets no hint (quality loop tick 123).
     *
     * Applied after accents are stripped, to query and verse. J → I everywhere: modern
     * Italian does not write it, and in Martini it is the -j plural, the j between
     * vowels ("ajuto", "muojo", "gioja") or a name's initial ("Jesse", "Josue") — each
     * the same word the modern reader spells with i. The rest are named STEMS. Every one
     * of the 576 corpus words the map changes was listed: all 160 words it merges are
     * one word in its two spellings (and "pjena", "djranno" are slips it mends).
     */
    private const AGENT_SEARCH_IT_SPELLING = [
        'j' => 'i',
        // "figliuolo" is the period form of "figlio"; the apocopated "figliuol" is singular
        'figliuoli' => 'figli', 'figliuole' => 'figlie', 'figliuolo' => 'figlio',
        'figliuola' => 'figlia', 'figliuol' => 'figlio',
        'davidde' => 'davide',
        // -fizio → -ficio (sacrificio, beneficio, ufficio, edificio, artificio), sagr → sacr
        'fiz' => 'fic', 'sagr' => 'sacr',
        'maravigl' => 'meravigl', 'nimic' => 'nemic', 'limosin' => 'elemosin',
    ];

    /**
     * German's ASCII-keyboard fallback spelling, folded to match the accent-stripped
     * corpus form. search_normalize()'s accent map already turns ß into "ss" (so
     * "groß"/"gross" already met) and ü/ö/ä into bare u/o/a by STRIPPING the diaeresis
     * — but the standard German fallback for those three (Duden-documented, what a
     * reader without umlaut keys actually types) is not stripping, it is the digraph:
     * "ue"/"oe"/"ae". "über" found 4,035 verses, "ueber" found 0 (quality loop tick 273).
     * Applied after accents are stripped, so "über" is already "uber" and this only
     * needs to fold the two-letter query form down to the same single letter.
     */
    private const AGENT_SEARCH_DE_SPELLING = [
        'ue' => 'u', 'oe' => 'o', 'ae' => 'a',
    ];

    /**
     * Fold a string for matching: lower-case, accents stripped (the site's own
     * search_normalize map, æ→ae included), for Latin j→i so classical and
     * Clementine spellings meet ("eius" / "ejus"), and for English British and
     * American spelling (see AGENT_SEARCH_EN_SPELLING). Punctuation becomes space.
     * Invisible Unicode FORMAT characters (\p{Cf}: zero-width space, soft hyphen,
     * word joiner, byte-order mark — what a copy from a PDF or a mobile keyboard
     * pastes mid-word) are removed outright rather than folded to a space: a
     * reader cannot see them, so they must not split one word into two tokens.
     * Combining marks (\p{Mn}: an accent as its OWN codepoint rather than fused
     * into one with its base letter — quality loop tick 213) get the same
     * treatment for scripts the small accent map above does not cover: Hebrew
     * niqqud and Arabic tashkil have no precomposed form for NFC to produce, so
     * "בְּרֵאשִׁית" survived as four fragments split at each vowel point ('ב',
     * 'ר', 'אש', 'ית') instead of the one word a reader typed and sees.
     */
    private static function agent_search_normalize( string $s, string $lang ): string {
        $s = (string) preg_replace( '/[\p{Cf}\p{Mn}]/u', '', $s );
        $s = self::search_normalize( $s );
        if ( $lang === 'la' ) {
            $s = str_replace( 'j', 'i', $s );
        }
        if ( $lang === 'en' ) {
            $s = strtr( $s, self::AGENT_SEARCH_EN_SPELLING );
        }
        if ( $lang === 'es' ) {
            $s = strtr( $s, self::AGENT_SEARCH_ES_SPELLING );
        }
        if ( $lang === 'it' ) {
            $s = strtr( $s, self::AGENT_SEARCH_IT_SPELLING );
        }
        if ( $lang === 'de' ) {
            $s = strtr( $s, self::AGENT_SEARCH_DE_SPELLING );
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
    /**
     * When a search that found nothing was really a CITATION, say so.
     *
     * "John 3:16" sent here searches verse TEXT for the words "john", "3" and
     * "16", finds nothing, and the answer used to give the edition's
     * orthography hint — "the Douay-Rheims keeps early-modern English: thee,
     * thou…" — a wrong diagnosis a model then acts on, trying archaic
     * spellings of a reference (quality loop tick 93). A query the resolver's
     * own grammar reads as book + number is pointed at /bible-ref.json instead.
     * Runs only on an empty result, so a word search is never second-guessed.
     */
    private static function agent_search_citation_hint( string $raw, string $lang ): ?string {
        $parsed = DwBible_Reference::parse_query( $raw );
        if ( $parsed['ref'] === '' || self::internal_key_from_any_book( $parsed['name'], 'latin' ) === null ) {
            return null;
        }
        $url = site_url( '/bible-ref.json?q=' . rawurlencode( $raw ) . '&lang=' . rawurlencode( $lang !== '' ? $lang : 'la' ) );
        return "\"{$raw}\" is a citation, not words to find: this search matches verse text. Read the passage at {$url}";
    }

    private static function agent_search_orthography_hint( string $dataset ): ?string {
        $hints = [
            'latin'   => 'The Clementine Vulgate (1592) writes J for consonantal I and uses the æ/œ ligatures — "Jesu", "ejus", "cælum". This search folds j/i, æ/ae and œ/oe, so all of those match. What it cannot fold is oe where this edition writes ae: heaven here is "cælum" (175 verses) and never "coelum" (0), so "Cœli enarrant" finds nothing where "Cæli enarrant" finds the psalm.',
            'spanish' => 'The Scío de San Miguel (1790s) keeps 18th-century Spanish orthography. This search folds its common period spellings to modern ones — quando/cuando, qual/cual, Christo/Cristo, Jesu-Christo/Jesucristo, dixo/dijo, muger/mujer, reyno/reino, ph/f, th/t — so those match either way; a rarer period form may still need to be written as the edition writes it.',
            'italian' => 'The Martini (1780s) keeps 18th-century Italian. This search folds its common period spellings to modern ones — Davidde/Davide, figliuolo/figlio, sacrifizio/sacrificio, limosina/elemosina, nimico/nemico, maraviglia/meraviglia, and j for i (giudizj/giudizi, ajuto/aiuto) — so those match either way. What it cannot fold is an older word or verb form: "nol" for "non lo", "imperocché" for "poiché".',
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
