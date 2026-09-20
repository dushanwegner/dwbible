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
     * The typed-citation grammar: "<book> <chapter>[:<verse>[-[<chapter>:]<verse>]]".
     *
     * Written WITHOUT delimiters because it is used by both PCRE (`/…/u`) and
     * the browser (`new RegExp(…)`) — keep it to syntax both understand.
     *
     *   1  book name    lazy, so only a TRAILING run of digits can be the
     *                   chapter — "1 Cor 13" keeps its leading book number
     *   2  chapter      required once the name is followed by digits
     *   3  separator    the one the reader chose, captured so the END of a
     *                   range can be required to use the SAME one (below)
     *   4  verse        optional, and optionally still being typed ("Mt 5:")
     *   5  range end    optional — a VERSE of chapter 2 ("Luke 24:13-35"),
     *                   unless group 6 follows, in which case it is a CHAPTER
     *   6  end verse    optional; its presence is what makes 5 a chapter, so
     *                   "26:36-27:60" ends at 27:60 and "24:13-35" at 24:35
     *
     * Separators: `:` `,` `.` between chapter and verse (the Latin/German
     * citation comma included); hyphen or dash for a range.
     *
     * A RANGE MAY CROSS A CHAPTER BOUNDARY (dwbible issue 21) — "Mt 26:36-27:60",
     * "Io 18,1-19,42" — because that is how the four Passions, the Vigil's
     * Gen 1:1-2:3 and much of the breviary are printed, and refusing the form
     * truncated three of the four Holy Week Passions at the chapter break,
     * each stopping before the crucifixion. Two rules keep the addition from
     * eating forms that already meant something else:
     *
     *   `\3`      the end separator must be the SAME character the citation
     *             opened with. A printed citation uses one convention
     *             throughout, and without this "Ps 44:11-12, 14" — a verse
     *             LIST this site's own calendar prints — would read as
     *             "44:11 to 12:14", silently serving the wrong chapter.
     *   `(?!\.)`  and it may not be "." on either side: between two digits "."
     *             is this site's German verse-list separator (see
     *             split_printed_locations()), so "3.16-18.21" stays a list of
     *             passages, not a range from 3:16 to 18:21. citation_advice()
     *             names the fix for anyone who meant the range.
     */
    const CITATION_PATTERN = '^(.*?)[\\s.]*(\\d+)\\s*(?:([:,.])\\s*(\\d+)?(?:\\s*[-–—]\\s*(?:(\\d+)(?:\\s*(?!\\.)\\3\\s*(\\d+))?)?)?)?\\s*$';

    /**
     * How many chapters one citation may span, first and last included.
     *
     * FIVE. A citation that crosses a chapter boundary is a real, printed form
     * — the four Holy Week Passions (Mt 26:36-27:60, Mk 14:32-15:46,
     * Lk 22:39-23:53, Jn 18:1-19:42), the Vigil's Gen 1:1-2:3 and Ex 14:24-15:1
     * — and every one of those is TWO chapters. Nothing this site's own
     * lectionary prints is longer. Five leaves room for the longest thing
     * anyone plausibly cites as one passage (a short book read straight
     * through: Ruth 1:1-4:22, Ionas 1:1-4:11, both four) and stops there,
     * because beyond that a citation is no longer naming a passage but asking
     * for a book — "Gen 1:1-50:26" would assemble fifty chapter files per
     * language, which is a download dressed up as a reference. The refusal
     * says the number, so a reader who meant it can ask chapter by chapter.
     */
    const MAX_CHAPTER_SPAN = 5;

    /**
     * A canonical Roman numeral only — no "IIII" for 4, no "VX" for 5. The
     * Vulgate/Denzinger apparatus this grammar is built to read always writes
     * the subtractive form, so a non-canonical run of M/D/C/L/X/V/I is not a
     * numeral this site recognises as a chapter (see normalize_roman_chapter()).
     */
    const ROMAN_NUMERAL = '(?=[MDCLXVI])M{0,3}(?:CM|CD|D?C{0,3})(?:XC|XL|L?X{0,3})(?:IX|IV|V?I{0,3})';

    /**
     * Drop invisible Unicode FORMAT characters (\p{Cf}: zero-width space, soft
     * hyphen, word joiner, byte-order mark — what a copy from a PDF or a mobile
     * keyboard pastes mid-citation). A reader cannot see them, so one sitting
     * between a chapter's digits and its colon, or inside a verse number, must
     * not be read as an unparseable citation (quality loop tick 194; the same
     * class agent_search_normalize() already strips for bible_search, tick 183).
     */
    private static function strip_format_chars($s) {
        return (string) preg_replace('/\p{Cf}/u', '', (string) $s);
    }

    /**
     * Rewrite non-ASCII DECIMAL digits (Arabic-Indic ٠-9, extended
     * Arabic-Indic, Devanagari, fullwidth, …) to plain 0-9 before any grammar
     * sees them. `\d` under PCRE's `/u` already MATCHES these — Unicode
     * category Nd — so a citation like "John ٣:١٦" IS parsed as book "John",
     * chapter "٣", verse "١٦"; the bug was one step later, where a downstream
     * `preg_match` without `/u` and a plain `(int)` cast (both ASCII-only)
     * silently read a non-ASCII digit string as "no chapter given" instead of
     * refusing — a citation with a real chapter and verse quietly answered
     * with the whole book (quality loop tick 206). Superscript digits
     * (category No, "³") are NOT decimal digits and are deliberately left
     * alone: they read as footnote-style verse markers inside running text,
     * not citation syntax, so guessing a chapter/verse from them would be
     * the wrong kind of "fix".
     */
    private static function normalize_unicode_digits($s) {
        return (string) preg_replace_callback('/\p{Nd}/u', static function ($m) {
            $v = IntlChar::charDigitValue($m[0]);
            return $v >= 0 ? (string) $v : $m[0];
        }, (string) $s);
    }

    /**
     * Rewrite the FULLWIDTH forms of the three chapter:verse separators —
     * ： ， ． (U+FF1A/FF0C/FF0E) — to their plain ASCII counterparts. A CJK
     * input method leaves these behind when a citation is typed without
     * switching out of fullwidth punctuation mode first; CITATION_PATTERN's
     * separator class is ASCII-only (`[:,.]`), so the book still resolved
     * ("John" reads the same either way) but the chapter and verse after a
     * fullwidth colon went unread — the same class of gap as the fullwidth
     * DIGITS normalize_unicode_digits() above already folds (quality loop
     * tick 206), one punctuation step further (tick 230).
     */
    private static function normalize_unicode_punctuation($s) {
        return strtr((string) $s, ["\u{FF1A}" => ':', "\u{FF0C}" => ',', "\u{FF0E}" => '.']);
    }

    /**
     * Drop the punctuation a citation is WRAPPED or FOLLOWED by when quoted out
     * of running prose — "(John 3:16)", "\"John 3:16\"", a sentence-ending
     * "John 3:16." — none of which is part of the citation grammar itself.
     *
     * Without this, CITATION_PATTERN's lazy book-name capture backtracks past
     * the real chapter:verse to find a position from which the TRAILING junk
     * alone satisfies the final `$`: for "John 3:16." it swallows the colon
     * into the book name and reads the trailing "." as an EMPTY verse
     * separator, so "John 3:16." parses as book "John 3:", chapter 16, no
     * verse — silently wrong rather than refused (quality loop tick 248).
     * Stripping first removes the ambiguity instead of asking the grammar to
     * resolve it.
     *
     * Trailing `:` is deliberately NOT stripped — a bare trailing separator
     * ("John 3:", still being typed) already parses as chapter-only, its own
     * documented behaviour, and stripping it here would not change that.
     */
    private static function strip_wrapping_punctuation($s) {
        $s = preg_replace('/^[\s"\'\x{2018}\x{201C}\x{00AB}(\[{]+/u', '', (string) $s);
        $s = preg_replace('/[\s"\'\x{2019}\x{201D}\x{00BB})\]}.,;!?]+$/u', '', (string) $s);
        return (string) $s;
    }

    /**
     * Drop a TRAILING superscript/subscript footnote-style marker — "John
     * 3:16³", "Song³", "1 Cor ³" — the exact case normalize_unicode_digits()
     * above declines to read as a digit (superscript is Unicode category No,
     * not Nd, and guessing a chapter/verse from it would be the wrong kind of
     * fix, quality loop tick 206). Declining to READ it is right; leaving it
     * glued to the string is not: on a book whose canonical key or URL slug
     * happens to already equal its short name ("John", "Genesis") the
     * downstream slugify() strips it anyway (its cleanup regex removes any
     * non-ASCII byte), so those books quietly still worked — but every book
     * reached only through the exact-match abbreviation table (every NUMBERED
     * book — "1 Cor", "2 Thess" — plus any other name not itself a slug, e.g.
     * "Song") failed BOOK_NOT_RECOGNISED, and a real chapter:verse with a
     * trailing footnote number ("John 3:16³") failed CITATION_NOT_UNDERSTOOD
     * even though the book resolved fine — a citation quoted straight out of
     * running text, footnote and all, should not need the marker hand-deleted
     * first (quality loop tick 290). Stripped before CITATION_PATTERN sees the
     * string, so the marker never reaches book lookup by either path.
     */
    private static function strip_trailing_superscript_footnote($s) {
        return (string) preg_replace(
            '/(?:[\s:,.]*[\x{00B2}\x{00B3}\x{00B9}\x{2070}\x{2074}-\x{2079}\x{2080}-\x{2089}]+)+\s*$/u',
            '',
            (string) $s
        );
    }

    /**
     * "Jo. III, 16", "Matth. V, 3" — the Vulgate/Denzinger apparatus's own
     * citation form: a Roman numeral CHAPTER. Distinct from the Roman
     * numeral BOOK-COUNT prefix ("III Reg.", quality loop tick 122, read in
     * class-dwbible-router.php's internal_key_from_any_book()): that numeral
     * sits BEFORE the book name and is followed by another letter (the name
     * continuing), never by a separator+digit or the end of the string, so
     * it can never satisfy the pattern below. CITATION_PATTERN's chapter is
     * `\d+` only, so "John III, 16" never even reaches a book lookup with a
     * chapter split off — the whole string fails as an unrecognised book
     * name (quality loop tick 284). Read here, before the shared grammar,
     * and rewritten to its Arabic form.
     *
     * @return array{query:string,numeral:string,value:int}|null
     */
    private static function normalize_roman_chapter($s) {
        if (!preg_match('/^(.*\p{L})[\s.]+(' . self::ROMAN_NUMERAL . ')(\s*[:,.]\s*\d+(?:\s*[-–—]\s*\d+)?)?\s*$/iu', (string) $s, $m)) {
            return null;
        }
        return [
            'query'   => trim($m[1]) . ' ' . self::roman_to_int($m[2]) . (isset($m[3]) ? $m[3] : ''),
            'numeral' => $m[2],
            'value'   => self::roman_to_int($m[2]),
        ];
    }

    /** Canonical Roman numeral (validated by ROMAN_NUMERAL already) to int. */
    private static function roman_to_int($roman) {
        $vals  = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $roman = strtoupper($roman);
        $total = 0;
        $seen  = 0;
        for ($i = strlen($roman) - 1; $i >= 0; $i--) {
            $v     = $vals[$roman[$i]];
            $total += ($v < $seen) ? -$v : $v;
            $seen  = max($seen, $v);
        }
        return $total;
    }

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
        $s = trim(self::strip_format_chars($raw));
        $s = self::strip_wrapping_punctuation($s);
        $s = self::strip_trailing_superscript_footnote($s);
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
        // isset()+!=='' , not empty(): a verse or range-end of "0" is a STRING
        // "0", which empty() reads as absent (PHP's classic falsy-string trap)
        // and would silently drop, same bug as the chapter/verse "0" handling
        // in class-dwbible-agent-api.php (quality loop tick 200).
        if (isset($m[4]) && $m[4] !== '') {
            $ref .= ':' . $m[4];
            if (isset($m[5]) && $m[5] !== '') {
                // The range END. With a verse of its own (group 6) the number
                // is the CHAPTER the passage ends in and the ref keeps both —
                // "26:36-27:60"; without one it is a verse of the chapter
                // already named — "24:13-35". Whatever separator the reader
                // used, the ref is always written with ":" : it is this site's
                // own canonical form, and parse_ref() reads exactly it back.
                $ref .= '-' . (isset($m[6]) && $m[6] !== '' ? $m[5] . ':' . $m[6] : $m[5]);
            }
        } elseif (isset($m[5]) && $m[5] !== '') {
            // A range-END with no range-START ("John 3:-5") is malformed, not
            // a whole-chapter request: the "-5" would otherwise be silently
            // dropped and the reader who asked for one verse gets the whole
            // chapter back with no readAs note explaining why (quality loop
            // tick 218).
            return ['name' => $s, 'ref' => ''];
        }
        return ['name' => $name, 'ref' => $ref];
    }

    /**
     * The canonical ref string parse_query() writes, read back into numbers.
     *
     *   "5"            whole chapter                 ch 5,  no verse
     *   "5:1"          one verse                     5:1  - 5:1
     *   "24:13-35"     a range inside one chapter    24:13 - 24:35
     *   "18:1-19:42"   a range ACROSS chapters       18:1 - 19:42
     *
     * THE RANGE END IS A CHAPTER ONLY WHEN IT CARRIES A VERSE OF ITS OWN. That
     * is the whole distinction, and it is the same one the grammar makes: a
     * lone number after the dash is a verse of the chapter already named, a
     * number with ":" and another number after it is a new chapter. So "24:35"
     * ends in chapter 24 and "19:42" ends in chapter 19, with nothing to guess.
     *
     * One parser, because two callers need the same answer: /bible-ref.json
     * (which assembles the passage) and the router's `?q=` resolver (which
     * follows it as far as an HTML page can go).
     *
     * @param string $ref 'ch[:v[-[ch:]v]]'.
     * @return array{ch:int,vf:int,chTo:int,vt:int,verseGiven:bool}|null null when not a ref.
     */
    public static function parse_ref($ref) {
        if (!is_string($ref) || $ref === '') {
            return null;
        }
        if (!preg_match('/^(\d+)(?::(\d+)(?:-(?:(\d+):)?(\d+))?)?$/', $ref, $m)) {
            return null;
        }
        // isset()+!=='' , never empty(): "0" is a falsy string, and a typed
        // chapter or verse of 0 must reach the caller as the number it is so
        // the caller can REFUSE it — dropping it here answers the whole
        // chapter instead (the trap documented in parse_query() above).
        $verse_given = isset($m[2]) && $m[2] !== '';
        $vf          = $verse_given ? (int) $m[2] : 0;
        $vt          = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : $vf;
        return [
            'ch'         => (int) $m[1],
            'vf'         => $vf,
            'chTo'       => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $m[1],
            'vt'         => $vt,
            'verseGiven' => $verse_given,
        ];
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
        $s = trim(self::strip_format_chars($raw));
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
        // Sorted ascending and deduplicated: the text is always assembled in the
        // chapter's own order (agent_load_passage walks the chapter file forward),
        // so a list typed out of order or with a repeat ("112:9, 1, 2", "112:1, 1, 2")
        // must be cited back the way it is actually delivered — never a citation
        // naming an order or a repeat the passage does not carry.
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $seen  = [];
        $dedup = [];
        foreach ($spans as $span) {
            $sig = $span[0] . '-' . $span[1];
            if (isset($seen[$sig])) { continue; }
            $seen[$sig] = true;
            $dedup[] = $span;
        }
        return ['name' => $name, 'chapter' => (int) $m[2], 'spans' => $dedup];
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
        $q     = self::normalize_unicode_punctuation(self::normalize_unicode_digits(trim((string) $raw)));
        $notes = [];

        $roman = self::normalize_roman_chapter($q);
        if ($roman !== null) {
            $q       = $roman['query'];
            $notes[] = '"' . $roman['numeral'] . '" is read as chapter ' . $roman['value'] . '.';
        }

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

        // "f." or the Latin "sq." (sequens, and the following verse) but not
        // their plural "ff."/"sqq.": exactly one following verse.
        if (preg_match('/([:,.]\s*)(\d{1,3})\s*(f|sq)\.?\s*$/u', $q, $m) && !preg_match('/(?:ff|sqq)\.?\s*$/u', $q)) {
            $from   = (int) $m[2];
            $suffix = $m[3];
            $q      = (string) preg_replace('/([:,.]\s*)(\d{1,3})\s*(?:f|sq)\.?\s*$/u', '${1}' . $from . '-' . ($from + 1), $q);
            $notes[] = '"' . $from . $suffix . '" is read as verses ' . $from . '-' . ($from + 1) . ' (' . $suffix . '. = and the following verse).';
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
        if (preg_match('/\d\s*(ff|sqq)\.?\s*$/u', $s, $m)) {
            return "\"{$m[1]}.\" (and the following verses) names no last verse, so no passage can be chosen for it. Give the range: \"{$book} 3:16-21\".";
        }
        // A comma or "and" that is followed by a LETTER WORD names a second
        // book, not a same-chapter verse continuation — a verse-list segment
        // ("18", "20-21") is always pure digits. "Ps 23:1, John 3:16" must not
        // be told to write a comma list of verses; it named two books (tick 254).
        $names_second_book = false;
        foreach (preg_split('/\s*,\s*|\s+and\s+/iu', $s) as $i => $segment) {
            if ($i > 0 && preg_match('/\p{L}{2,}/u', $segment)) {
                $names_second_book = true;
                break;
            }
        }
        if ($names_second_book || preg_match('/[;]|[:,]\s*\d+(?:\s*[-–—]\s*\d+)?\s*\.\s*\d/u', $s)) {
            $locs = self::split_printed_locations($s);
            if (count($locs) >= 2) {
                $examples = array_map(static function ($loc) use ($book) { return "\"{$book} {$loc}\""; }, array_slice($locs, 0, 2));
                return "This names several passages (\".\" and \";\" separate them in a printed citation), and one request reads one passage. Ask for each: " . implode(', then ', $examples) . '.';
            }
            return "This names several passages (\".\" and \";\" separate them in a printed citation), and one request reads one passage. Ask for each location separately, one request per passage.";
        }
        // A cross-chapter range is READ now (dwbible issue 21), so reaching here
        // means only one thing: it was written with "." on both sides, and "."
        // between two digits is this grammar's verse-list separator, not a
        // chapter:verse one. Name the fix rather than the old prohibition — the
        // reader wrote a form this site serves, in the one punctuation it cannot
        // tell apart from a list.
        if (preg_match('/[:,.]\s*\d+\s*[-–—]\s*\d+\s*[:,.]\s*\d+/u', $s)) {
            return "A cross-chapter range reads here, but not with \".\" — between two digits \".\" separates verses of one chapter. "
                 . "Write it with \":\" or \",\": \"{$book} 5:1-7:29\", \"{$book} 5,1-7,29\". At most " . self::MAX_CHAPTER_SPAN . " chapters.";
        }
        if (preg_match('/\d+\s+\d+\s*$/u', $s)) {
            return "Chapter and verse need a separator between them: \"{$book} 3:16\" or \"{$book} 3,16\".";
        }
        return "Write chapter:verse, or chapter:verse-verse: \"{$book} 3:16\", \"{$book} 3:16-18\"; "
             . "a range may cross a chapter boundary, \"{$book} 3:16-4:3\" (at most " . self::MAX_CHAPTER_SPAN . " chapters); "
             . "a comma list of verses in one chapter reads too, \"{$book} 3:16, 18, 20-21\"; a whole chapter is \"{$book} 3\".";
    }

    /**
     * The reader's own chapter:verse locations out of a "several passages"
     * citation, so citation_advice() can name THEM rather than a fixed example.
     * ";" starts a new location (its own chapter, if given); a "." between two
     * digits is the German verse-list separator and stays in the same chapter.
     *
     * @return string[] each "chapter:verse", in the order they were written
     */
    private static function split_printed_locations($s) {
        if (!preg_match('/\d.*/us', $s, $m)) {
            return [];
        }
        $chapter = null;
        $locs    = [];
        foreach (preg_split('/\s*;\s*|(?<=\d)\.\s*(?=\d)/u', $m[0]) as $part) {
            $part = trim($part, " \t.,");
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,3})\s*[:,]\s*(\d{1,3})/u', $part, $mm)) {
                $chapter = (int) $mm[1];
                $locs[]  = $chapter . ':' . (int) $mm[2];
            } elseif ($chapter !== null && preg_match('/^(\d{1,3})/u', $part, $mm)) {
                $locs[] = $chapter . ':' . (int) $mm[1];
            }
        }
        return $locs;
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
