<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Osis_Utils {
    public static function osis_for_dataset_book_slug($osis_mapping, $dataset_slug, $dataset_book_slug) {
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        $dataset_book_slug = is_string($dataset_book_slug) ? DwBible_Plugin::slugify($dataset_book_slug) : '';
        if ($dataset_slug === '' || $dataset_book_slug === '') {
            return null;
        }
        if (!is_array($osis_mapping) || empty($osis_mapping['books']) || !is_array($osis_mapping['books'])) {
            return null;
        }

        foreach ($osis_mapping['books'] as $osis => $entry) {
            if (!is_string($osis) || $osis === '' || !is_array($entry)) {
                continue;
            }
            if ($dataset_slug === 'bibel') {
                $list = $entry['bibel'] ?? null;
                if (is_array($list)) {
                    foreach ($list as $s) {
                        if (is_string($s) && DwBible_Plugin::slugify($s) === $dataset_book_slug) {
                            return $osis;
                        }
                    }
                }
                continue;
            }
            $mapped = $entry[$dataset_slug] ?? null;
            if (is_string($mapped) && $mapped !== '' && DwBible_Plugin::slugify($mapped) === $dataset_book_slug) {
                return $osis;
            }
        }
        return null;
    }

    public static function dataset_book_slug_for_osis($osis_mapping, $dataset_slug, $osis) {
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        $osis = is_string($osis) ? trim($osis) : '';
        if ($dataset_slug === '' || $osis === '') {
            return null;
        }
        if (!is_array($osis_mapping) || empty($osis_mapping['books']) || !is_array($osis_mapping['books'])) {
            return null;
        }
        $entry = $osis_mapping['books'][$osis] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        if ($dataset_slug === 'bibel') {
            $list = $entry['bibel'] ?? null;
            if (is_array($list) && !empty($list)) {
                $first = $list[0] ?? null;
                return is_string($first) && $first !== '' ? DwBible_Plugin::slugify($first) : null;
            }
            return null;
        }
        $mapped = $entry[$dataset_slug] ?? null;
        if (!is_string($mapped) || $mapped === '') {
            return null;
        }
        $s = DwBible_Plugin::slugify($mapped);
        return $s !== '' ? $s : null;
    }
}
