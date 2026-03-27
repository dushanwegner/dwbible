<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Index_Loader {
    public static function load_index($csv_path) {
        $books = [];
        $slug_map = [];

        if (!is_string($csv_path) || $csv_path === '' || !file_exists($csv_path)) {
            return [
                'books' => $books,
                'slug_map' => $slug_map,
            ];
        }

        $fh = fopen($csv_path, 'r');
        if ($fh === false) {
            return [
                'books' => $books,
                'slug_map' => $slug_map,
            ];
        }

        // skip header
        $header = fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || count($row) < 3) {
                continue;
            }
            $order = intval($row[0]);
            $short = $row[1];
            $display = '';
            $filename = '';
            // New format: order, short_name, display_name, filename, ...
            if (count($row) >= 4) {
                $display = isset($row[2]) ? $row[2] : '';
                $filename = isset($row[3]) ? $row[3] : (isset($row[2]) ? $row[2] : '');
            } else {
                // Old format: order, short_name, filename, ...
                $filename = $row[2];
            }
            $entry = [
                'order' => $order,
                'short_name' => $short,
                'display_name' => $display,
                'filename' => $filename,
            ];
            $books[] = $entry;
            $slug = DwBible_Plugin::slugify($short);
            $slug_map[$slug] = $entry;
        }
        fclose($fh);

        return [
            'books' => $books,
            'slug_map' => $slug_map,
        ];
    }
}
