<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Front_Meta {
    public static function print_custom_css() {
        $is_bible = get_query_var(DwBible_Plugin::QV_FLAG);
        if (!$is_bible) {
            return;
        }
        $footer_css = get_option('dwbible_footer_css', '');
        $out = '';
        if (is_string($footer_css) && $footer_css !== '') {
            $out .= $footer_css . "\n";
        }
        if ($out !== '') {
            echo '<style id="dwbible-custom-css">' . $out . '</style>';
        }
    }

    public static function print_og_meta() {
        $flag = get_query_var(DwBible_Plugin::QV_FLAG);
        if (!$flag) {
            return;
        }
        $book = get_query_var(DwBible_Plugin::QV_BOOK);
        $ch = absint(get_query_var(DwBible_Plugin::QV_CHAPTER));
        $vf = absint(get_query_var(DwBible_Plugin::QV_VFROM));
        $vt = absint(get_query_var(DwBible_Plugin::QV_VTO));
        if (!$book || !$ch || !$vf) {
            return;
        }
        if (!$vt || $vt < $vf) {
            $vt = $vf;
        }

        $entry = DwBible_Plugin::get_book_entry_by_slug($book);
        if (!$entry) {
            return;
        }
        $label = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : DwBible_Plugin::pretty_label($entry['short_name']);
        $title = $label . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));

        $base_slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($base_slug) || $base_slug === '') {
            $base_slug = 'bible';
        }
        $path = '/' . trim($base_slug, '/') . '/' . trim($book, '/') . '/' . $ch . ':' . $vf . ($vt > $vf ? ('-' . $vt) : '');
        $url = home_url($path);
        $og_url = add_query_arg(DwBible_Plugin::QV_OG, '1', $url);
        $desc = DwBible_Plugin::extract_verse_text($entry, $ch, $vf, $vt);
        $desc = wp_strip_all_tags($desc);

        echo "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:image" content="' . esc_url($og_url) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($og_url) . '" />' . "\n";
    }
}
