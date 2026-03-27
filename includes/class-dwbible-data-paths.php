<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Data_Paths {
    public static function data_root_dir() {
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        set_query_var(DwBible_Plugin::QV_SLUG, $slug);
        $root = plugin_dir_path(__FILE__) . '../data/' . $slug . '/';
        if (is_dir($root)) {
            return $root;
        }
        return null;
    }

    public static function html_dir() {
        $root = self::data_root_dir();
        if ($root) {
            $h = trailingslashit($root) . 'html/';
            if (is_dir($h)) {
                return $h;
            }
        }
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        $old = plugin_dir_path(__FILE__) . '../data/' . $slug . '_books_html/';
        if (is_dir($old)) {
            return $old;
        }
        $fallback = plugin_dir_path(__FILE__) . '../data/bible_books_html/';
        return $fallback;
    }

    public static function text_dir() {
        $root = self::data_root_dir();
        if ($root) {
            $t = trailingslashit($root) . 'text/';
            if (is_dir($t)) {
                return $t;
            }
        }
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        $old = plugin_dir_path(__FILE__) . '../data/' . $slug . '_books_text/';
        if (is_dir($old)) {
            return $old;
        }
        $fallback = plugin_dir_path(__FILE__) . '../data/bible_books_text/';
        return $fallback;
    }
}
