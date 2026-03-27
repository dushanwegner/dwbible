<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Canonicalization {
    public static function canonicalize_key_from_dataset_book_slug($book_map, $dataset_slug, $dataset_book_slug) {
        if (!is_string($dataset_slug) || $dataset_slug === '') {
            return null;
        }
        if (!is_string($dataset_book_slug) || $dataset_book_slug === '') {
            return null;
        }
        if (!is_array($book_map) || empty($book_map)) {
            return null;
        }

        $dataset_slug = trim($dataset_slug);
        $dataset_book_slug = DwBible_Plugin::slugify($dataset_book_slug);
        if ($dataset_book_slug === '') {
            return null;
        }

        foreach ($book_map as $canon_key => $map_entry) {
            if (!is_string($canon_key) || $canon_key === '' || !is_array($map_entry)) {
                continue;
            }
            $mapped = $map_entry[$dataset_slug] ?? null;
            if (!is_string($mapped) || $mapped === '') {
                continue;
            }
            if (DwBible_Plugin::slugify($mapped) === $dataset_book_slug) {
                return (string) $canon_key;
            }
        }

        return null;
    }
}
