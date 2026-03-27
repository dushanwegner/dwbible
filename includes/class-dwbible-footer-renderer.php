<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Footer_Renderer {
    public static function render_footer_html($root, $html_dir) {
        // Prefer new markdown footer at dataset root, fallback to old copyright.txt in html dir
        $raw = '';
        if (is_string($root) && $root !== '') {
            $md = trailingslashit($root) . 'copyright.md';
            if (file_exists($md)) {
                $raw = (string) file_get_contents($md);
            }
        }
        if ($raw === '') {
            $txt_path = trailingslashit((string) $html_dir) . 'copyright.txt';
            if (file_exists($txt_path)) {
                $raw = (string) file_get_contents($txt_path);
            }
        }
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        // Very light Markdown to HTML: allow links and simple headings; escape everything else
        $safe = esc_html($raw);
        // Convert [text](url) style links
        $safe = preg_replace('/\[(.*?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $safe);
        // Split into lines and build blocks: headings or paragraphs
        $lines = preg_split('/\r?\n/', $safe);
        $blocks = [];
        $para = [];
        $flush_para = function() use (&$para, &$blocks) {
            if (!empty($para)) {
                // Join paragraph lines with spaces
                $text = trim(preg_replace('/\s+/', ' ', implode(' ', array_map('trim', $para))));
                if ($text !== '') {
                    $blocks[] = '<p>' . $text . '</p>';
                }
                $para = [];
            }
        };
        foreach ($lines as $ln) {
            if (preg_match('/^###\s+(.*)$/', $ln, $m)) {
                $flush_para();
                $blocks[] = '<h3 class="dwbible-footer-title">' . $m[1] . '</h3>';
                continue;
            }
            if (preg_match('/^##\s+(.*)$/', $ln, $m)) {
                $flush_para();
                $blocks[] = '<h2 class="dwbible-footer-title">' . $m[1] . '</h2>';
                continue;
            }
            if (preg_match('/^#\s+(.*)$/', $ln, $m)) {
                $flush_para();
                $blocks[] = '<h1 class="dwbible-footer-title">' . $m[1] . '</h1>';
                continue;
            }
            if (trim($ln) === '') {
                $flush_para();
                continue;
            }
            $para[] = $ln;
        }
        $flush_para();
        return '<footer class="dwbible-footer">' . implode('', $blocks) . '</footer>';
    }
}
