<?php
/**
 * WHAT:    The editorial notes of the Bible reader — Latin Prayer's own short lines where the
 *          Vulgate's order, numbering or naming differs from a modern Catholic Bible.
 * WHY:     q7766. The notes live in dwbibledata (data/notes.json, keyed to the Latin spine, a text
 *          per language); this class only reads them and renders one <aside> per line, at one of
 *          three places: before a verse, under the chapter head, under the book's masthead.
 *          A note is never inside a verse group, so copy, share and the verse tools (which read
 *          .verse-body only) never carry it.
 * INPUT:   an internal book key (esther, 3-kings, psalms), a chapter, the visitor's dwi18n language.
 * OUTPUT:  HTML strings; '' where a place has no note.
 * DATA:    dwbibledata/data/notes.json — its gate is tools/qc/editorial_notes.py there.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Notes {

    private static $doc = null;

    /** The parsed notes.json, or [] when the data plugin has none (an older dwbibledata). */
    private static function doc(): array {
        if (self::$doc === null) {
            $file = dwbible_data_dir() . 'notes.json';
            $raw = is_readable($file) ? file_get_contents($file) : false;
            $doc = $raw !== false ? json_decode($raw, true) : null;
            self::$doc = (is_array($doc) && isset($doc['notes']) && is_array($doc['notes'])) ? $doc : [];
        }
        return self::$doc;
    }

    private static function lang(): string {
        $lang = function_exists('dwi18n_current') ? (string) dwi18n_current() : 'en';
        return $lang !== '' ? $lang : 'en';
    }

    /**
     * The lines at one place: [['id','text','link'=>[ch,v]|null], …].
     * $place is 'verse' (then $verse is required), 'chapter' or 'book'.
     */
    public static function lines(string $book, string $place, int $ch = 0, int $verse = 0): array {
        $doc = self::doc();
        if (!$doc) {
            return [];
        }
        $lang = self::lang();
        $out = [];
        foreach ($doc['notes'] as $note) {
            $at = $note['at'] ?? [];
            if (($at['place'] ?? '') !== $place) {
                continue;
            }
            $each = isset($note['each']) && is_array($note['each']) ? $note['each'] : null;
            $nbook = (string) ($note['book'] ?? '');
            $key = null;
            if ($nbook === '*') {
                if ($each === null || !isset($each[$book])) {
                    continue;
                }
                $key = $book;
            } elseif ($nbook !== $book) {
                continue;
            } elseif ($place === 'verse') {
                if ((int) ($at['chapter'] ?? 0) !== $ch || (int) ($at['verse'] ?? 0) !== $verse) {
                    continue;
                }
            } elseif ($place === 'chapter') {
                if ($each !== null) {
                    if (!isset($each[(string) $ch])) {
                        continue;
                    }
                    $key = (string) $ch;
                } elseif ((int) ($at['chapter'] ?? 0) !== $ch) {
                    continue;
                }
            }
            $text = self::text_for($note, $lang);
            if ($text === '') {
                continue;
            }
            $row = ($key !== null && is_array($each[$key] ?? null)) ? $each[$key] : [];
            $out[] = [
                'id'   => (string) ($note['id'] ?? ''),
                'text' => self::fill($text, $key, $row, $lang),
                'link' => isset($note['link']['chapter'], $note['link']['verse'])
                    ? [(int) $note['link']['chapter'], (int) $note['link']['verse']] : null,
            ];
        }
        return $out;
    }

    /** The note's text in $lang, falling back to English, then to none. */
    private static function text_for(array $note, string $lang): string {
        foreach ([$lang, 'en'] as $l) {
            $t = $note['text'][$l]['t'] ?? '';
            if (is_string($t) && trim($t) !== '') {
                return $t;
            }
        }
        return '';
    }

    /** {vulgate} is the row's key unless the row names it; other slots come from the row. */
    private static function fill(string $text, $key, array $row, string $lang): string {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($key, $row, $lang) {
            $name = $m[1];
            $value = $row[$name] ?? ($name === 'vulgate' ? $key : null);
            if (is_array($value)) {
                $value = $value[$lang] ?? ($value['en'] ?? null);
            }
            return is_scalar($value) ? (string) $value : $m[0];
        }, $text);
    }

    /**
     * Every note a passage touches, in one language, for the JSON API: the book's notes, the
     * chapter notes of each chapter in the span, and the verse notes inside the asked verses
     * ($vf 0 = whole chapters). Each says it is ours, never the edition's.
     */
    public static function for_range(string $book, int $ch, int $ch_to, int $vf, int $vt, string $lang, string $edition): array {
        $doc = self::doc();
        if (!$doc || $ch <= 0) {
            return [];
        }
        $ch_to = max($ch, $ch_to);
        $label = (string) ($doc['_meta']['label'][$lang] ?? ($doc['_meta']['label']['en'] ?? 'Note'));
        $out = [];
        $add = function (array $note, $key, string $place, int $c = 0, int $v = 0) use (&$out, $lang, $label, $edition) {
            $text = self::text_for($note, $lang);
            if ($text === '') {
                return;
            }
            $row = ($key !== null && is_array($note['each'][$key] ?? null)) ? $note['each'][$key] : [];
            $tlang = isset($note['text'][$lang]['t']) ? $lang : 'en';
            $item = [
                'id'        => (string) ($note['id'] ?? ''),
                'place'     => $place,
                'chapter'   => $c ?: null,
                'verse'     => $v ?: null,
                'label'     => $label,
                'text'      => self::fill($text, $key, $row, $tlang),
                'lang'      => $tlang,
                'editorial' => "Latin Prayer's editorial note, not part of {$edition}",
            ];
            if (isset($note['link']['chapter'], $note['link']['verse'])) {
                $item['link'] = ['chapter' => (int) $note['link']['chapter'], 'verse' => (int) $note['link']['verse']];
            }
            $out[] = $item;
        };
        foreach ($doc['notes'] as $note) {
            $at = $note['at'] ?? [];
            $place = (string) ($at['place'] ?? '');
            $nbook = (string) ($note['book'] ?? '');
            $each = isset($note['each']) && is_array($note['each']) ? $note['each'] : null;
            if ($place === 'book') {
                if ($nbook === '*' ? isset($each[$book]) : $nbook === $book) {
                    $add($note, $nbook === '*' ? $book : null, 'book');
                }
                continue;
            }
            if ($nbook !== $book) {
                continue;
            }
            for ($c = $ch; $c <= $ch_to; $c++) {
                if ($place === 'chapter') {
                    if ($each !== null ? isset($each[(string) $c]) : (int) ($at['chapter'] ?? 0) === $c) {
                        $add($note, $each !== null ? (string) $c : null, 'chapter', $c);
                    }
                } elseif ($place === 'verse' && (int) ($at['chapter'] ?? 0) === $c) {
                    $v = (int) ($at['verse'] ?? 0);
                    $lo = ($vf > 0 && $c === $ch) ? $vf : 1;
                    $hi = ($vf > 0 && $c === $ch_to) ? $vt : PHP_INT_MAX;
                    if ($v >= $lo && $v <= $hi) {
                        $add($note, null, 'verse', $c, $v);
                    }
                }
            }
        }
        return $out;
    }

    public static function label(): string {
        $labels = self::doc()['_meta']['label'] ?? [];
        $lang = self::lang();
        return (string) ($labels[$lang] ?? ($labels['en'] ?? 'Note'));
    }

    /**
     * The HTML for a place. $href_for(ch, verse) gives a link's address; the link is set on the
     * reference ("10:4") where the text names it, else on nothing.
     */
    public static function render(string $book, string $place, int $ch = 0, int $verse = 0, ?callable $href_for = null): string {
        $html = '';
        foreach (self::lines($book, $place, $ch, $verse) as $line) {
            $body = esc_html($line['text']);
            if ($line['link'] && $href_for) {
                $ref = $line['link'][0] . ':' . $line['link'][1];
                $href = (string) $href_for($line['link'][0], $line['link'][1]);
                $at = strrpos($body, $ref);
                if ($href !== '' && $at !== false) {
                    $body = substr($body, 0, $at)
                        . '<a href="' . esc_url($href) . '">' . esc_html($ref) . '</a>'
                        . substr($body, $at + strlen($ref));
                }
            }
            $html .= '<aside class="dwbible-note dwbible-note--' . esc_attr($place) . '" role="note"'
                . ' lang="' . esc_attr(self::lang()) . '" data-note="' . esc_attr($line['id']) . '">'
                . '<span class="dwbible-note__label">' . esc_html(self::label()) . '</span>'
                . $body . '</aside>';
        }
        return $html;
    }
}
