<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Admin_Utils {
    public static function admin_enqueue($hook) {
        // Only enqueue on our settings pages (hook varies by WP version/menu title)
        // Match any hook containing 'dwbible'
        if (!is_string($hook) || strpos($hook, 'dwbible') === false) {
            return;
        }
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        // Enqueue our admin media picker script (depends on wp.media via jquery)
        wp_enqueue_script(
            'dwbible-admin-media',
            plugin_dir_url(__FILE__) . '../assets/admin-media.js',
            ['jquery'],
            '1.0.1',
            true
        );
    }

    public static function allow_font_uploads($mimes) {
        if (!is_array($mimes)) {
            $mimes = [];
        }
        // Common font MIME types
        $mimes['ttf'] = 'font/ttf';
        $mimes['otf'] = 'font/otf';
        $mimes['woff'] = 'font/woff';
        $mimes['woff2'] = 'font/woff2';
        // Some hosts map fonts as octet-stream; allow anyway to select in media library
        if (!isset($mimes['ttf'])) {
            $mimes['ttf'] = 'application/octet-stream';
        }
        if (!isset($mimes['otf'])) {
            $mimes['otf'] = 'application/octet-stream';
        }
        return $mimes;
    }

    public static function allow_font_filetype($data, $file, $filename, $mimes, $real_mime) {
        if (!current_user_can('manage_options')) {
            return $data;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['ttf','otf','woff','woff2'], true)) {
            $type = ($ext === 'otf') ? 'font/otf' : (($ext === 'ttf') ? 'font/ttf' : (($ext==='woff2')?'font/woff2':'font/woff'));
            return [ 'ext' => $ext, 'type' => $type, 'proper_filename' => $data['proper_filename'] ];
        }
        return $data;
    }
}
