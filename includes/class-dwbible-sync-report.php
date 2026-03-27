<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Sync_Report {
    private const OPTION_CACHE = 'dwbible_sync_report_cache';

    public static function render_sync_status_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $did_recompute = false;
        if (isset($_POST['dwbible_sync_recompute_nonce']) && wp_verify_nonce($_POST['dwbible_sync_recompute_nonce'], 'dwbible_sync_recompute')) {
            delete_option(self::OPTION_CACHE);
            $did_recompute = true;
        }

        $cache = get_option(self::OPTION_CACHE, null);
        if (!is_array($cache) || !isset($cache['data']) || !is_array($cache['data'])) {
            $cache = [
                'generated_at' => current_time('mysql'),
                'data' => self::build_report(),
            ];
            update_option(self::OPTION_CACHE, $cache, false);
        }

        $generated_at = isset($cache['generated_at']) && is_string($cache['generated_at']) ? $cache['generated_at'] : '';
        $data = $cache['data'];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Verse Sync Status', 'dwbible') . '</h1>';

        if ($did_recompute) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Recomputed sync report.', 'dwbible') . '</p></div>';
        }

        echo '<p>' . esc_html__('This report compares the available verse HTML coverage across the three datasets used by the plugin.', 'dwbible') . '</p>';

        echo '<form method="post" style="margin: 1em 0;">';
        wp_nonce_field('dwbible_sync_recompute', 'dwbible_sync_recompute_nonce');
        echo '<button type="submit" class="button">' . esc_html__('Recompute report', 'dwbible') . '</button>';
        if ($generated_at !== '') {
            echo '<span style="margin-left: .75em; opacity: .8;">' . esc_html(sprintf(__('Cached at %s', 'dwbible'), $generated_at)) . '</span>';
        }
        echo '</form>';

        $datasets = ['bible' => 'EN', 'bibel' => 'DE', 'latin' => 'LA'];

        echo '<table class="widefat striped" style="max-width: 100%;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Canonical book', 'dwbible') . '</th>';
        foreach ($datasets as $ds => $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '<th>' . esc_html__('Notes', 'dwbible') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($data as $canon_key => $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<tr>';
            echo '<td><code>' . esc_html($canon_key) . '</code></td>';

            $counts = [];
            foreach ($datasets as $ds => $_label) {
                $cell = isset($row[$ds]) && is_array($row[$ds]) ? $row[$ds] : [];
                $ok = !empty($cell['ok']);
                $chapters = isset($cell['chapters']) ? (int) $cell['chapters'] : 0;
                $verses = isset($cell['verses']) ? (int) $cell['verses'] : 0;
                $mapped = isset($cell['mapped']) && is_string($cell['mapped']) ? $cell['mapped'] : '';
                $filename = isset($cell['filename']) && is_string($cell['filename']) ? $cell['filename'] : '';

                if ($ok) {
                    $counts[$ds] = [$chapters, $verses];
                    echo '<td>';
                    echo '<div><strong>' . esc_html($mapped) . '</strong></div>';
                    echo '<div><small>' . esc_html(sprintf(__('Ch: %d, Verses: %d', 'dwbible'), $chapters, $verses)) . '</small></div>';
                    if ($filename !== '') {
                        echo '<div><small><code>' . esc_html($filename) . '</code></small></div>';
                    }
                    echo '</td>';
                } else {
                    echo '<td><span style="opacity:.7;">&mdash;</span></td>';
                }
            }

            $notes = [];
            $ref = $counts['bible'] ?? null;
            if ($ref) {
                foreach ($counts as $ds => $pair) {
                    if ($pair[0] !== $ref[0] || $pair[1] !== $ref[1]) {
                        $notes[] = $ds . ' differs';
                    }
                }
            }
            if (empty($counts)) {
                $notes[] = 'missing in all';
            }

            echo '<td><small>' . esc_html(implode(', ', $notes)) . '</small></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    private static function build_report() {
        $out = [];

        $datasets = ['bible', 'bibel', 'latin'];
        $book_map_path = plugin_dir_path(__FILE__) . '../data/book_map.json';
        $book_map = [];
        if (file_exists($book_map_path)) {
            $json = (string) file_get_contents($book_map_path);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $book_map = $decoded;
            }
        }

        foreach ($book_map as $canon_key => $map_entry) {
            if (!is_string($canon_key) || $canon_key === '' || !is_array($map_entry)) {
                continue;
            }

            $row = [];
            foreach ($datasets as $ds) {
                $row[$ds] = self::analyze_book_for_dataset($canon_key, $map_entry, $ds);
            }

            $out[$canon_key] = $row;
        }

        ksort($out);
        return $out;
    }

    private static function analyze_book_for_dataset($canon_key, $map_entry, $dataset) {
        $mapped = $map_entry[$dataset] ?? '';
        if (!is_string($mapped) || $mapped === '') {
            return [
                'ok' => false,
                'mapped' => '',
                'filename' => '',
                'chapters' => 0,
                'verses' => 0,
            ];
        }

        $index_file = plugin_dir_path(__FILE__) . '../data/' . $dataset . '/html/index.csv';
        if (!file_exists($index_file)) {
            return [
                'ok' => false,
                'mapped' => $mapped,
                'filename' => '',
                'chapters' => 0,
                'verses' => 0,
            ];
        }

        $filename = '';
        if (($fh = fopen($index_file, 'r')) !== false) {
            fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                if (!is_array($row) || count($row) < 4) {
                    continue;
                }
                if ((string) $row[1] === (string) $mapped) {
                    $filename = (string) $row[3];
                    break;
                }
            }
            fclose($fh);
        }

        if ($filename === '') {
            return [
                'ok' => false,
                'mapped' => $mapped,
                'filename' => '',
                'chapters' => 0,
                'verses' => 0,
            ];
        }

        $html_path = plugin_dir_path(__FILE__) . '../data/' . $dataset . '/html/' . $filename;
        if (!file_exists($html_path)) {
            return [
                'ok' => false,
                'mapped' => $mapped,
                'filename' => $filename,
                'chapters' => 0,
                'verses' => 0,
            ];
        }

        $html = (string) file_get_contents($html_path);
        if ($html === '') {
            return [
                'ok' => false,
                'mapped' => $mapped,
                'filename' => $filename,
                'chapters' => 0,
                'verses' => 0,
            ];
        }

        $book_slug = DwBible_Plugin::slugify($mapped);
        $chapters = 0;
        $verses = 0;

        if ($book_slug !== '') {
            if (preg_match_all('/\bid="' . preg_quote($book_slug, '/') . '-ch-(\d+)"/i', $html, $m)) {
                $nums = array_map('intval', $m[1]);
                $chapters = !empty($nums) ? max($nums) : 0;
            }

            if (preg_match_all('/\bid="' . preg_quote($book_slug, '/') . '-(\d+)-(\d+)"/i', $html, $m2)) {
                $verses = is_array($m2[0]) ? count($m2[0]) : 0;
                if ($chapters <= 0 && !empty($m2[1])) {
                    $chs = array_map('intval', $m2[1]);
                    $chapters = !empty($chs) ? max($chs) : 0;
                }
            }
        }

        return [
            'ok' => true,
            'mapped' => $mapped,
            'filename' => $filename,
            'chapters' => $chapters,
            'verses' => $verses,
        ];
    }
}
