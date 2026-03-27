<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Abbreviations_Loader {
    public static function load_abbreviation_map($slug) {
        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug === '') {
            return [];
        }
        $map = [];
        $lang = ($slug === 'bibel') ? 'de' : 'en';
        $file = dwbible_data_dir() . $slug . '/abbreviations.' . $lang . '.json';
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                error_log("DwBible: could not read abbreviation file: $file");
                return $map;
            }
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('DwBible: JSON parse error in ' . $file . ': ' . json_last_error_msg());
                return $map;
            }
            if (is_array($data) && !empty($data['books']) && is_array($data['books'])) {
                foreach ($data['books'] as $short => $variants) {
                    if (!is_array($variants)) {
                        continue;
                    }
                    foreach ($variants as $v) {
                        $key = trim(mb_strtolower((string) $v, 'UTF-8'));
                        if ($key === '') {
                            continue;
                        }
                        // First writer wins; avoid clobbering in case of collisions.
                        if (!isset($map[$key])) {
                            $map[$key] = (string) $short;
                        }
                    }
                }
            }
        }
        return $map;
    }
}
