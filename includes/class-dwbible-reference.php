<?php
/**
 * DW Bible — references: the chapter/verse grammar, in one place.
 *
 * WHAT   Parsing and formatting of a citation — both the URL form the router
 *        already owns (`/{book}/{ch}:{v}[-{v}]`) and the TYPED form a reader
 *        puts into a search box ("Matthew 5:41", "Mt 5,41", "1 Cor 13").
 * WHY    The typed grammar is read in two runtimes — PHP resolves `?q=` on the
 *        server, the book index filters in the browser — so the pattern lives
 *        here ONCE and the browser is handed the same string. Two copies of a
 *        grammar drift; one copy cannot.
 * USED BY dwbible.php (the index filter + its inline JS), class-dwbible-router
 *        (the `?q=` resolver).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_Reference {

    /**
     * The typed-citation grammar: "<book> <chapter>[:<verse>[-<verse>]]".
     *
     * Written WITHOUT delimiters because it is used by both PCRE (`/…/u`) and
     * the browser (`new RegExp(…)`) — keep it to syntax both understand.
     *
     *   1  book name    lazy, so only a TRAILING run of digits can be the
     *                   chapter — "1 Cor 13" keeps its leading book number
     *   2  chapter      required once the name is followed by digits
     *   3  verse        optional, and optionally still being typed ("Mt 5:")
     *   4  range end    optional ("Luke 24:13-35")
     *
     * Separators: `:` `,` `.` between chapter and verse (the Latin/German
     * citation comma included); hyphen or dash for a range.
     */
    const CITATION_PATTERN = '^(.*?)[\\s.]*(\\d+)\\s*(?:[:,.]\\s*(\\d+)?(?:\\s*[-–—]\\s*(\\d+)?)?)?\\s*$';

    /**
     * Split a typed query into its book half and its citation half.
     *
     * A query with no trailing chapter is all book name; a query whose name
     * half is empty ("5:41") is treated as all book name too, since a citation
     * with nothing to cite cannot be resolved.
     *
     * @param string $raw What the reader typed.
     * @return array{name:string,ref:string} ref is the canonical "ch[:v[-v]]" ('' if none).
     */
    public static function parse_query($raw) {
        $s = trim((string) $raw);
        if ($s === '') {
            return ['name' => '', 'ref' => ''];
        }
        if (!preg_match('/' . self::CITATION_PATTERN . '/u', $s, $m)) {
            return ['name' => $s, 'ref' => ''];
        }
        $name = isset($m[1]) ? trim($m[1]) : '';
        if ($name === '') {
            return ['name' => $s, 'ref' => ''];
        }
        $ref = $m[2];
        if (!empty($m[3])) {
            $ref .= ':' . $m[3];
            if (!empty($m[4])) { $ref .= '-' . $m[4]; }
        }
        return ['name' => $name, 'ref' => $ref];
    }

    /**
     * A chapter followed by a COMMA LIST of verses and verse ranges, inside one
     * chapter: "Ps 112:1, 2, 9", "Ps 44:11-12, 14", "Ps 88:12,15".
     *
     * This site's own calendar prints its Mass readings in exactly this form, and
     * CITATION_PATTERN above cannot read it: anchored at both ends, it swallows
     * everything but the last number into the book name ("Ps 112:1, 2," + "9"),
     * so the book lookup fails and the reader is told the chapter and verse could
     * not be read. A separate pattern is used rather than widening the shared one,
     * because CITATION_PATTERN is also compiled in the browser (the book index
     * filter) where a list has nothing to filter.
     *
     *   1  book name   lazy, as above, so "1 Cor" keeps its leading number
     *   2  head        the number before the chapter:verse separator
     *   3  list        the comma-separated verses/ranges after it
     *
     * The head is the CHAPTER. Deciding otherwise needs the book (a one-chapter
     * book cites by verse), which this class does not know — see the caller.
     *
     * Cross-chapter citations are deliberately NOT read here: ";" and "." separate
     * whole passages in a printed citation, and neither the list nor anything
     * downstream can hold two chapters. They keep the refusal citation_advice()
     * already writes for them.
     *
     * @param string $raw What the reader typed.
     * @return array{name:string,chapter:int,spans:array<int,array{0:int,1:int}>}|null
     *         null when the query is not a verse list.
     */
    public static function parse_verse_list($raw) {
        $s = trim((string) $raw);
        if ($s === '' || strpos($s, ',') === false) {
            return null;
        }
        $item = '\d+(?:\s*[-–—]\s*\d+)?';
        $re   = '/^(.*?)[\s.]*(\d+)\s*[:,.]\s*(' . $item . '(?:\s*,\s*' . $item . ')*)\s*$/u';
        if (!preg_match($re, $s, $m)) {
            return null;
        }
        $name = trim($m[1]);
        if ($name === '') {
            return null;
        }
        $spans = [];
        foreach (explode(',', $m[3]) as $part) {
            if (!preg_match('/^\s*(\d+)(?:\s*[-–—]\s*(\d+))?\s*$/u', $part, $p)) {
                return null;
            }
            $from = (int) $p[1];
            $to   = isset($p[2]) && $p[2] !== '' ? (int) $p[2] : $from;
            if ($from <= 0 || $to < $from) {
                return null;
            }
            $spans[] = [$from, $to];
        }
        return ['name' => $name, 'chapter' => (int) $m[2], 'spans' => $spans];
    }

    /**
     * Forms a lectionary or a German Bible PRINTS that the grammar above does not
     * read, rewritten into ones it does — and said so, so the reading is never silent.
     *
     *   "Mt 5,1-12a", "Ps 22,2b"  a half-verse letter. No edition here divides a verse,
     *                             so the whole verse is the honest answer.
     *   "Joh 3,16f", "3,16 f."    German f. = the verse and the one after it.
     *
     * Everything else is left alone for the grammar to accept or refuse; see
     * citation_advice() for what the refusal then says. A reader's own spelling of
     * the book is never touched: only a verse number (after `:` `,` `.` or a range
     * dash) may lose its letter.
     *
     * @return array{query:string, note:?string}
     */
    public static function normalize_printed_forms($raw) {
        $q     = trim((string) $raw);
        $notes = [];

        $halves = [];
        $q = (string) preg_replace_callback(
            '/([:,.]\s*|[-–—]\s*)(\d{1,3})([abc])(?!\p{L})/u',
            static function ($m) use (&$halves) { $halves[] = $m[2] . $m[3]; return $m[1] . $m[2]; },
            $q
        );
        if ($halves) {
            $notes[] = '"' . implode('", "', $halves) . '" ' . (count($halves) === 1 ? 'is half a verse' : 'are half-verses')
                     . '; no edition here divides a verse, so the whole verse is served.';
        }

        // "f." but not "ff.": exactly one following verse.
        if (preg_match('/([:,.]\s*)(\d{1,3})\s*f\.?\s*$/u', $q, $m) && !preg_match('/ff\.?\s*$/u', $q)) {
            $from = (int) $m[2];
            $q    = (string) preg_replace('/([:,.]\s*)(\d{1,3})\s*f\.?\s*$/u', '${1}' . $from . '-' . ($from + 1), $q);
            $notes[] = '"' . $from . 'f" is read as verses ' . $from . '-' . ($from + 1) . ' (f. = and the following verse).';
        }

        return ['query' => $q, 'note' => $notes ? implode(' ', $notes) : null];
    }

    /**
     * What to tell a reader whose citation named a book but no readable
     * chapter:verse — by the SHAPE of what they wrote. One sentence about
     * cross-chapter ranges used to answer every failure, so "Joh 3,16ff" and
     * "Joh 3 16" were told to keep a range inside one chapter.
     *
     * @param string $raw  the citation as the reader wrote it
     * @param string $book the book's name, for the example
     */
    public static function citation_advice($raw, $book) {
        $s = trim((string) $raw);
        if (preg_match('/\d\s*ff\.?\s*$/u', $s)) {
            return "\"ff.\" (and the following verses) names no last verse, so no passage can be chosen for it. Give the range: \"{$book} 3:16-21\".";
        }
        if (preg_match('/[;]|[:,]\s*\d+(?:\s*[-–—]\s*\d+)?\s*\.\s*\d/u', $s)) {
            return "This names several passages (\".\" and \";\" separate them in a printed citation), and one request reads one passage. Ask for each: \"{$book} 3:16\", then \"{$book} 3:18\".";
        }
        if (preg_match('/[:,.]\s*\d+\s*[-–—]\s*\d+\s*[:,.]\s*\d+/u', $s)) {
            return "A range must stay inside ONE chapter — \"{$book} 5:1-12\", not \"{$book} 5:1-7:29\". A passage spanning chapters is two or more requests, one per chapter; a whole chapter is \"{$book} 5\".";
        }
        if (preg_match('/\d+\s+\d+\s*$/u', $s)) {
            return "Chapter and verse need a separator between them: \"{$book} 3:16\" or \"{$book} 3,16\".";
        }
        return "Write chapter:verse, or chapter:verse-verse inside one chapter: \"{$book} 3:16\", \"{$book} 3:16-18\"; "
             . "a comma list of verses in one chapter reads too, \"{$book} 3:16, 18, 20-21\"; a whole chapter is \"{$book} 3\".";
    }

    public static function parse_chapter_and_range($ch, $vf, $vt) {
        $ch = absint($ch);
        $vf = absint($vf);
        $vt_raw = $vt;
        $vt = absint($vt);

        if ($ch <= 0) {
            return new WP_Error('dwbible_invalid_chapter', 'Invalid chapter.');
        }

        if (($vf === 0 || $vf === null) && $vt_raw !== null && $vt_raw !== '') {
            return new WP_Error('dwbible_invalid_range', 'Invalid verse range.');
        }

        if ($vf <= 0) {
            return [
                'ch' => $ch,
                'vf' => null,
                'vt' => null,
            ];
        }

        if ($vt_raw === null || $vt_raw === '' || $vt <= 0) {
            $vt = $vf;
        }

        if ($vt < $vf) {
            return new WP_Error('dwbible_invalid_range', 'Invalid verse range.');
        }

        return [
            'ch' => $ch,
            'vf' => $vf,
            'vt' => $vt,
        ];
    }

    public static function highlight_ids_for_range($book_slug, $ch, $vf, $vt) {
        $book_slug = is_string($book_slug) ? $book_slug : '';
        $book_slug = $book_slug !== '' ? DwBible_Plugin::slugify($book_slug) : '';
        $ch = absint($ch);
        $vf = absint($vf);
        $vt = absint($vt);

        if ($book_slug === '' || $ch <= 0 || $vf <= 0 || $vt < $vf) {
            return [];
        }

        $out = [];
        for ($i = $vf; $i <= $vt; $i++) {
            $out[] = $book_slug . '-' . $ch . '-' . $i;
        }
        return $out;
    }

    public static function chapter_scroll_id($book_slug, $ch) {
        $book_slug = is_string($book_slug) ? $book_slug : '';
        $book_slug = $book_slug !== '' ? DwBible_Plugin::slugify($book_slug) : '';
        $ch = absint($ch);
        if ($book_slug === '' || $ch <= 0) {
            return null;
        }
        return $book_slug . '-ch-' . $ch;
    }
}
