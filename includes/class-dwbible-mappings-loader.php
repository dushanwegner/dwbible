<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Mappings_Loader {
    public static function load_book_map() {
        $file = dwbible_data_dir() . 'book_map.json';
        $map = [];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $map = $data;
                }
            }
        }
        return $map;
    }

    public static function load_osis_mapping() {
        $file = plugin_dir_path(__FILE__) . 'osis-mapping.json';
        $map = [];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $map = $data;
                }
            }
        }
        return $map;
    }
}
