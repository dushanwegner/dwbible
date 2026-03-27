<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Admin_Export {
    public static function handle_export_bible_txt() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        if (!isset($_POST['dwbible_export_bible_nonce']) || !wp_verify_nonce($_POST['dwbible_export_bible_nonce'], 'dwbible_export_bible')) {
            wp_die('Invalid request');
        }
        $slug = isset($_POST['dwbible_export_bible_slug']) ? sanitize_text_field(wp_unslash($_POST['dwbible_export_bible_slug'])) : '';
        if ($slug !== 'bible' && $slug !== 'bibel') {
            wp_die('Unknown bible');
        }
        $root = dwbible_data_dir() . $slug . '/';
        $text_dir = trailingslashit($root) . 'text/';
        $html_dir = trailingslashit($root) . 'html/';
        $index = '';
        if (file_exists($text_dir . 'index.csv')) {
            $index = $text_dir . 'index.csv';
        } elseif (file_exists($html_dir . 'index.csv')) {
            $index = $html_dir . 'index.csv';
        }
        if ($index === '') {
            wp_die('Text data not available for export');
        }
        $fh = fopen($index, 'r');
        if (!$fh) {
            wp_die('Could not open text index');
        }
        $rows = [];
        $header = fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($fh);
        if (empty($rows)) {
            wp_die('No books found for export');
        }
        nocache_headers();
        status_header(200);
        header('Content-Type: text/plain; charset=UTF-8');
        $filename = 'dwbible-' . $slug . '.txt';
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        if (!$out) {
            wp_die('Could not open output stream');
        }
        $bible_name = ($slug === 'bibel') ? 'Deutsch (Menge)' : 'English (Douay-Rheims)';
        fwrite($out, $bible_name . "\n");
        fwrite($out, "schema|slug|book|chapter|verse|text\n");
        foreach ($rows as $row) {
            $short = isset($row[1]) ? (string) $row[1] : '';
            $file = isset($row[3]) ? (string) $row[3] : '';
            if ($file === '') {
                continue;
            }
            $book_key = DwBible_Plugin::slugify($short);
            // Prefer text/ source, falling back to same file under html/ if needed.
            $path_text = $text_dir . preg_replace('/\.html$/i', '.txt', $file);
            $path = file_exists($path_text) ? $path_text : trailingslashit(dirname($index)) . $file;
            if (!file_exists($path)) {
                continue;
            }
            $tfh = fopen($path, 'r');
            if (!$tfh) {
                continue;
            }
            $chapter = 0;
            while (($line = fgets($tfh)) !== false) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    if (strpos($trim, '## Chapter') === 0) {
                        if (preg_match('/^##\s+Chapter\s+(\d+)/i', $trim, $m)) {
                            $chapter = (int) $m[1];
                        }
                    }
                    continue;
                }
                if (!preg_match('/^(\d+):(\d+)\s+(.*)$/u', $trim, $m)) {
                    continue;
                }
                $v_ch = (int) $m[1];
                $v_vs = (int) $m[2];
                $text = trim($m[3]);
                $ch_out = $chapter > 0 ? $chapter : $v_ch;
                $line_out = $slug . '|' . $book_key . '|' . $ch_out . '|' . $v_vs . '|' . $text . "\n";
                fwrite($out, $line_out);
            }
            fclose($tfh);
        }
        fclose($out);
        exit;
    }
}
