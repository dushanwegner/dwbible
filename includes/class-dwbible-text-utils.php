<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Text_Utils {
    public static function normalize_whitespace($s) {
        // Replace various Unicode spaces/invisibles with normal space or remove, collapse, and trim
        $s = (string) $s;
        // Map a set of known invisibles to spaces or empty
        $map = [
            "\xC2\xA0" => ' ', // NBSP U+00A0
            "\xC2\xAD" => '',  // Soft hyphen U+00AD
            "\xE1\x9A\x80" => ' ', // OGHAM space mark U+1680
            "\xE2\x80\x80" => ' ', // En quad U+2000
            "\xE2\x80\x81" => ' ', // Em quad U+2001
            "\xE2\x80\x82" => ' ', // En space U+2002
            "\xE2\x80\x83" => ' ', // Em space U+2003
            "\xE2\x80\x84" => ' ', // Three-per-em space U+2004
            "\xE2\x80\x85" => ' ', // Four-per-em space U+2005
            "\xE2\x80\x86" => ' ', // Six-per-em space U+2006
            "\xE2\x80\x87" => ' ', // Figure space U+2007
            "\xE2\x80\x88" => ' ', // Punctuation space U+2008
            "\xE2\x80\x89" => ' ', // Thin space U+2009
            "\xE2\x80\x8A" => ' ', // Hair space U+200A
            "\xE2\x80\x8B" => '',  // Zero width space U+200B
            "\xE2\x80\x8C" => '',  // Zero width non-joiner U+200C
            "\xE2\x80\x8D" => '',  // Zero width joiner U+200D
            "\xE2\x80\x8E" => '',  // LRM U+200E
            "\xE2\x80\x8F" => '',  // RLM U+200F
            "\xE2\x80\xA8" => ' ', // Line separator U+2028
            "\xE2\x80\xA9" => ' ', // Paragraph separator U+2029
            "\xE2\x80\xAF" => ' ', // Narrow no-break space U+202F
            "\xE2\x81\xA0" => ' ', // Word joiner U+2060
            "\xEF\xBB\xBF" => '',  // BOM U+FEFF
        ];

        $s = strtr($s, $map);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }

    public static function clean_verse_text_for_output($s, $wrap_outer = false, $qL = '»', $qR = '«') {
        // Normalize whitespace and apply the quotation cleaner.
        $s = self::normalize_whitespace($s);
        return self::clean_verse_quotes($s, $wrap_outer, $qL, $qR);
    }

    private static function u_strlen($s) {
        if (function_exists('mb_strlen')) {
            return mb_strlen((string) $s, 'UTF-8');
        }
        $arr = preg_split('//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($arr) ? count($arr) : strlen((string) $s);
    }

    private static function u_substr($s, $start, $len = null) {
        $s = (string) $s;
        if (function_exists('mb_substr')) {
            return $len === null ? mb_substr($s, (int) $start, null, 'UTF-8') : mb_substr($s, (int) $start, (int) $len, 'UTF-8');
        }
        // Fallback: byte-based substr (not fully Unicode safe, but keeps behavior on hosts without mbstring)
        return $len === null ? substr($s, (int) $start) : substr($s, (int) $start, (int) $len);
    }

    private static function clean_verse_quotes($s, $wrap_outer = false, $qL = '»', $qR = '«') {
        // General quotation mark cleaner for verse text.
        // Rules:
        // - If the verse block contains both » and «, convert all of them
        //   to single inner guillemets › and ‹.
        // - If it has only opening-style » and no «, append a matching « at the end,
        //   then apply the above conversion.
        // - If it has only closing-style « and no », prepend a matching » at the start,
        //   then apply the above conversion.
        // - Final rule: if the cleaned text would begin with "»›" and end with "‹«",
        //   collapse those pairs to single outer quotes » and «.

        $s = (string) $s;
        if ($s === '') {
            return $s;
        }

        // Strip hidden/control/combining characters.
        $s = preg_replace('/[\p{C}\p{M}]+/u', '', $s);
        $s = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\s]/u', '', $s);

        $has_left  = (strpos($s, '«') !== false);
        $has_right = (strpos($s, '»') !== false);

        // When only a single side is present, synthesize the missing partner.
        if ($has_right && !$has_left) {
            $s .= '«';
            $has_left = true;
        } elseif ($has_left && !$has_right) {
            $s = '»' . $s;
            $has_right = true;
        }

        // If there is both » and «, normalize them to inner guillemets.
        if ($has_left && $has_right) {
            $s = str_replace(['«', '»'], ['‹', '›'], $s);
        }

        // Post-pass: collapse leading "»›" and trailing "‹«" to single outer quotes.
        $len = self::u_strlen($s);
        if ($len >= 2) {
            $starts = (self::u_substr($s, 0, 2) === '»›');
            $ends   = (self::u_substr($s, -2) === '‹«');
            if ($starts && $ends) {
                $s = '»' . self::u_substr($s, 2);
                $len = self::u_strlen($s);
                if ($len >= 2 && self::u_substr($s, -2) === '‹«') {
                    $s = self::u_substr($s, 0, $len - 2) . '«';
                }
            }
        }

        $s = trim($s);

        // Strip space+dashes right before a closing guillemet.
        $s = preg_replace('/\s*[–—]\s*([«‹»›])\s*$/u', '$1', $s);
        $s = preg_replace('/[–—]\s*$/u', '', $s);
        $s = trim($s);

        // Final safety / wrapping behavior
        $len = self::u_strlen($s);
        if ($len >= 4 && self::u_substr($s, 0, 2) === '»›' && self::u_substr($s, -2) === '‹«') {
            $s = '»' . self::u_substr($s, 2, $len - 4) . '«';
            $len = self::u_strlen($s);
        }
        if ($wrap_outer) {
            if ($len >= 2 && self::u_substr($s, 0, 1) === $qL && self::u_substr($s, -1) === $qR) {
                // no-op
            } elseif ($len >= 2 && self::u_substr($s, 0, 1) === '›' && self::u_substr($s, -1) === '‹') {
                $s = $qL . self::u_substr($s, 1, $len - 2) . $qR;
            } else {
                $s = $qL . $s . $qR;
            }
        }

        return $s;
    }
}
