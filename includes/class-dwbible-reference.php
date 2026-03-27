<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_Reference {
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
