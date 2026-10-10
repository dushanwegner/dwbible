<?php
/**
 * DwBible — the Kings/Samuel candidate-set resolver.
 *
 * WHAT   Recognises the small set of citation forms that name "the first/
 *        second book of Kings" ambiguously between the Vulgate/Douay
 *        numbering (1-2 Samuel, 3-4 Kings) and the modern one (1-2 Samuel,
 *        1-2 Kings), and narrows a candidate set of book keys down to
 *        whichever actually have the cited chapter and verse.
 * WHY    DW, 2026-09-25 (dwfactory entry 600, filed as item 1558): "1 Kings"
 *        is genuinely ambiguous — a modern reader means Solomon (3 Kings),
 *        a Douay/Vulgate reader means Samuel (1 Kings = 1 Samuel) — and the
 *        site must never silently pick one. Most citations are NOT actually
 *        ambiguous once the reference is checked: "1 Kings 17:45" can only
 *        be 1 Samuel, because modern 1 Kings 17 has 24 verses and 1 Samuel
 *        17 has 58. This class is the "eliminate by existence" half of
 *        that rule; the trait that calls it (class-dwbible-agent-api.php)
 *        is the half that turns one/several/no survivors into an answer.
 * USED BY class-dwbible-agent-api.php (serve_reference_json — the primary
 *        surface every acceptance case in dwfactory item 1558 is written
 *        against) and class-dwbible-router.php (maybe_redirect_query, the
 *        search box: a single survivor is followed there too).
 * TESTED BY tests/test-kings-samuel-candidates.php (no WordPress — this
 *        class has no WordPress dependency of its own) and
 *        tests/test-agent-api.sh (the HTTP surface built on top of it).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_Kings_Samuel {

    /**
     * Every string this site has measured as genuinely ambiguous between the
     * two numbering conventions (dwfactory entry 600/1558, DW 2026-09-25),
     * normalised (lower-case, single-spaced) -> candidate keys, MODERN
     * reading first: "that is the more likely intent of the reader this
     * product serves" — DW's own tiebreaker — but it is never auto-picked,
     * only offered first when both survive.
     *
     * Deliberately narrow. Every OTHER numbered form of these two books
     * ("3 Kings", "III Regum", "1 Samuel", "1 Samuelis" …) already names
     * ONE book only under either convention, and is untouched here — see
     * candidates_for() for how a Roman-numeral prefix folds onto this map
     * without widening it.
     */
    const AMBIGUOUS = [
        '1 kings' => [ '3-kings', '1-kings-samuel' ],
        '1 kgs'   => [ '3-kings', '1-kings-samuel' ],
        '1 regum' => [ '3-kings', '1-kings-samuel' ],
        '1 reg'   => [ '3-kings', '1-kings-samuel' ],
        '1 re'    => [ '3-kings', '1-kings-samuel' ],
        '1 reyes' => [ '3-kings', '1-kings-samuel' ],
        '1 rois'  => [ '3-kings', '1-kings-samuel' ],
        'regum i' => [ '3-kings', '1-kings-samuel' ],
        '2 kings' => [ '4-kings', '2-kings-samuel' ],
        '2 kgs'   => [ '4-kings', '2-kings-samuel' ],
        '2 regum' => [ '4-kings', '2-kings-samuel' ],
        '2 reg'   => [ '4-kings', '2-kings-samuel' ],
        '2 re'    => [ '4-kings', '2-kings-samuel' ],
        '2 reyes' => [ '4-kings', '2-kings-samuel' ],
        '2 rois'  => [ '4-kings', '2-kings-samuel' ],
        'regum ii' => [ '4-kings', '2-kings-samuel' ],
    ];

    /**
     * The spelled ordinal before Kings that the abbreviation tables and the
     * editions' own book lists hold: English "First Kings", "The First Book
     * of Kings"; Italian "Primo dei Re" (the Martini edition's name for
     * 1 Samuel); Spanish "Primero de los Reyes"; French "Premier des Rois".
     * Group 1 is the ordinal, group 2 the noun. Each pattern reads first to
     * fourth; only first and second are ambiguous (see unambiguous_key_for()
     * for third and fourth). German is not here: its edition names the books
     * the modern way ("1 Könige" is the book it calls so), so "Erstes Buch
     * der Könige" is no ambiguity and stays with the table (q7379).
     */
    private const SPELLED_ORDINAL = [
        '/^(?:the\s+)?(first|second|third|fourth)(?:\s+book\s+of)?\s+(kings|kgs)$/iu',
        '/^(primo|secondo|terzo|quarto)(?:\s+libro)?\s+(?:dei|di)\s+(re|reg)$/iu',
        '/^(?:libro\s+)?(primero|primer|segundo|tercero|tercer|cuarto)(?:\s+libro)?\s+(?:de\s+los|de)\s+(reyes)$/iu',
        '/^(premier|première|deuxième|second|seconde|troisième|quatrième)(?:\s+livre)?\s+(?:des|de)\s+(rois)$/iu',
    ];

    /** Ordinal word (lower case) -> its number, every language above. */
    private const ORDINAL_NUMBER = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4,
        'primo' => 1, 'secondo' => 2, 'terzo' => 3, 'quarto' => 4,
        'primero' => 1, 'primer' => 1, 'segundo' => 2, 'tercero' => 3, 'tercer' => 3, 'cuarto' => 4,
        'premier' => 1, 'première' => 1, 'deuxième' => 2, 'seconde' => 2, 'troisième' => 3, 'quatrième' => 4,
    ];

    /**
     * Nouns that, behind a 3 or a 4, name the Vulgate's third and fourth
     * book of Kings and nothing else (no modern convention has a "3 Kings").
     */
    private const THIRD_FOURTH_NOUN = '/^([34]) (re|reg|reyes|rois)$/u';

    /**
     * The raw string with a Roman "I"/"II" read as its digit and a spelled
     * ordinal read as its digit: "Primero de los Reyes" -> "1 reyes". A
     * string that is neither stays as it was.
     */
    private static function folded( string $raw ): string {
        $s = trim( $raw );
        if ( $s === '' ) { return ''; }
        $s = (string) preg_replace( '/\s+/u', ' ', $s );
        $s = (string) preg_replace_callback(
            '/^(I{1,2})(?:\.\s*|\s+)(?=\p{L})/u',
            static function ( $m ) { return ( strtolower( $m[1] ) === 'ii' ? '2 ' : '1 ' ); },
            $s
        );
        // A SPELLED ordinal is its digit: "First Kings" meets the same check
        // as "1 Kings" and is never silently read as one of the two books (q5130).
        foreach ( self::SPELLED_ORDINAL as $pattern ) {
            if ( preg_match( $pattern, $s, $m ) ) {
                $n = self::ORDINAL_NUMBER[ mb_strtolower( $m[1], 'UTF-8' ) ] ?? 0;
                if ( $n > 0 ) { return $n . ' ' . $m[2]; }
            }
        }
        return $s;
    }

    /**
     * Candidate keys for a raw book string, MODERN reading first, or null
     * when it is not one of the ambiguous forms above.
     *
     * A LEADING Roman "I"/"II" is read as "1"/"2" first — the same
     * conversion class-dwbible-router.php's internal_key_from_any_book()
     * applies generally for a numbered book — so "I Kings" and "II Regum"
     * reach the same map entry as their Arabic twin. "III"/"IV" are NOT
     * touched: they are the unambiguous 3rd/4th-book forms ("III Regum" is
     * only ever 3 Kings) and must never reach this map, so the pattern only
     * matches one or two I's followed by a word boundary.
     *
     * @return string[]|null
     */
    public static function candidates_for( string $raw ): ?array {
        $s = self::folded( $raw );
        if ( $s === '' ) { return null; }
        $key = mb_strtolower( $s, 'UTF-8' );
        return self::AMBIGUOUS[ $key ] ?? null;
    }

    /**
     * The one book a THIRD or FOURTH Kings name in Italian, Spanish or
     * French stands for ("Terzo dei Re", "Tercero de los Reyes", "3 Rois",
     * "Quatrième des Rois"), or null. These are the Vulgate's own numbers:
     * no convention reads them two ways, so there is nothing to disambiguate
     * and candidates_for() leaves them alone; this is only so that the names
     * the editions print are understood where the tables do not hold them.
     */
    public static function unambiguous_key_for( string $raw ): ?string {
        $s = mb_strtolower( self::folded( $raw ), 'UTF-8' );
        if ( preg_match( self::THIRD_FOURTH_NOUN, $s, $m ) ) {
            return $m[1] === '3' ? '3-kings' : '4-kings';
        }
        return null;
    }

    /**
     * Rule 2 of the dwfactory decision: "eliminate by existence" — drop any
     * candidate whose cited chapter (or, within it, verse) does not exist.
     * Order is preserved, so the caller keeps reading "modern first".
     *
     * A book-only or chapter-only query ($ch === 0, or $vf === 0) cannot be
     * narrowed this way — both candidate books exist — so every candidate
     * survives and the caller disambiguates.
     *
     * Only the START of a range is checked (the chapter it opens in, and
     * the first verse). A range whose END overruns the chapter is not a
     * reason to eliminate a book here: the surviving book's own existence
     * check further down class-dwbible-agent-api.php clamps that overrun
     * and says so, exactly as it does for any other book.
     *
     * @param string[]                      $candidates As from candidates_for().
     * @param array<string,array<int,int>>  $counts     DwBible_Plugin::verse_counts_by_book().
     * @param int $ch     0 = no chapter given.
     * @param int $ch_to  The chapter a cross-chapter range ends in; $ch when none.
     * @param int $vf     0 = no verse given.
     * @return string[] Surviving keys, in the same order as $candidates.
     */
    public static function surviving( array $candidates, array $counts, int $ch, int $ch_to, int $vf ): array {
        $out = [];
        foreach ( $candidates as $key ) {
            $book_counts = $counts[ $key ] ?? [];
            if ( $ch > 0 ) {
                if ( ! $book_counts || $ch > count( $book_counts ) ) { continue; }
                $last = $ch_to > $ch ? $ch_to : $ch;
                if ( $last > count( $book_counts ) ) { continue; }
                if ( $vf > 0 && $vf > (int) $book_counts[ $ch - 1 ] ) { continue; }
            }
            $out[] = $key;
        }
        return $out;
    }
}
