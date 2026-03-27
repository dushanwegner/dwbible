<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_AutoLink_Trait {

    private static $unified_abbr = null;

    public static function register_strip_bibleserver_bulk($bulk_actions) {
        if (!is_array($bulk_actions)) return $bulk_actions;
        $bulk_actions['dwbible_strip_bibleserver'] = __('Strip BibleServer links', 'dwbible');
        return $bulk_actions;
    }

    private static function strip_bibleserver_links_from_content($content) {
        if (!is_string($content) || $content === '') return $content;
        $pattern_html = '~<a\s+[^>]*href=["\']https?://(?:www\.)?bibleserver\.com/[^"\']*["\'][^>]*>(.*?)</a>~is';
        $content = preg_replace($pattern_html, '$1', $content);

        $pattern_md = '~\[([^\]]+)\]\(\s*https?://(?:www\.)?bibleserver\.com/[^\s\)]+\s*\)~i';
        $content = preg_replace($pattern_md, '$1', $content);

        return $content;
    }

    public static function handle_strip_bibleserver_bulk($redirect_to, $doaction, $post_ids) {
        if (!is_array($post_ids)) {
            return $redirect_to;
        }

        if ($doaction === 'dwbible_strip_bibleserver') {
            $count = 0;
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_type === 'revision') continue;
                $old = $post->post_content;
                $new = self::strip_bibleserver_links_from_content($old);
                if ($new !== $old) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_content' => $new,
                    ]);
                    $count++;
                }
            }
            if ($count > 0) {
                $redirect_to = add_query_arg('dwbible_stripped_bibleserver', $count, $redirect_to);
            }
            return $redirect_to;
        }

        return $redirect_to;
    }

    /**
     * Build a unified abbreviation map from all active language datasets.
     *
     * Each key maps to an array of entries: [ ['short' => ..., 'slug' => ...], ... ]
     * Entries with count > 1 are ambiguous (abbreviation exists in multiple languages).
     */
    /**
     * Invalidate the cached unified abbreviation map.
     *
     * Called automatically when the dwbible_slugs option is saved so that
     * any dataset changes take effect without requiring a process restart.
     * Also available for manual cache-busting in tests or admin tools.
     */
    public static function reset_abbreviation_cache(): void {
        self::$unified_abbr = null;
    }

    private static function get_unified_abbreviation_map() {
        if (self::$unified_abbr !== null) {
            return self::$unified_abbr;
        }

        // Register cache-busting hook the first time the map is built.
        // Fires whenever the slugs setting changes (new dataset added/removed).
        static $hook_registered = false;
        if (!$hook_registered) {
            add_action('update_option_dwbible_slugs', [__CLASS__, 'reset_abbreviation_cache']);
            $hook_registered = true;
        }

        $list = get_option('dwbible_slugs', 'bible,bibel');
        if (!is_string($list) || $list === '') {
            $list = 'bible,bibel';
        }
        $slugs = array_values(array_filter(array_map('trim', explode(',', $list))));
        if (empty($slugs)) {
            $slugs = ['bible'];
        }
        // Only use base dataset slugs (no combo slugs like "bible-bibel").
        $slugs = array_values(array_filter($slugs, function ($s) {
            return strpos($s, '-') === false;
        }));

        $unified = [];
        foreach ($slugs as $dataset_slug) {
            $abbr = self::get_abbreviation_map($dataset_slug);
            if (!is_array($abbr) || empty($abbr)) {
                continue;
            }
            foreach ($abbr as $key => $short) {
                if (!is_string($key) || $key === '' || !is_string($short) || $short === '') {
                    continue;
                }
                $entry = ['short' => $short, 'slug' => $dataset_slug];
                if (!isset($unified[$key])) {
                    $unified[$key] = [$entry];
                } else {
                    // Only add if this slug isn't already represented for this key.
                    $dominated = false;
                    foreach ($unified[$key] as $existing) {
                        if ($existing['slug'] === $dataset_slug) {
                            $dominated = true;
                            break;
                        }
                    }
                    if (!$dominated) {
                        $unified[$key][] = $entry;
                    }
                }
            }
        }

        self::$unified_abbr = $unified;
        return $unified;
    }

    /**
     * WordPress content filter: auto-link bible references.
     *
     * Uses the per-post dwbible_slug meta only as a tiebreaker for
     * abbreviations that are ambiguous across languages (e.g. "Gen", "Mt").
     * Language-specific names like "Matthäus" or "Matthew" are auto-detected.
     */
    public static function filter_content_auto_link_bible_refs($content) {
        if (!is_string($content) || $content === '') return $content;
        if (is_feed() || is_admin()) return $content;

        $preferred = '';
        $post_id = get_the_ID();
        if ($post_id) {
            $meta = get_post_meta($post_id, 'dwbible_slug', true);
            if (is_string($meta) && $meta !== '') {
                $preferred = $meta;
            }
        }

        return self::autolink_content($content, $preferred);
    }

    /**
     * Auto-link bible references in content.
     *
     * @param string $content        HTML content to process.
     * @param string $preferred_slug Dataset slug used as tiebreaker for ambiguous abbreviations.
     * @return string Content with bible references wrapped in links.
     */
    public static function autolink_content($content, $preferred_slug = '') {
        if (!is_string($content) || $content === '') return $content;

        $unified = self::get_unified_abbreviation_map();
        if (empty($unified)) return $content;

        // Pattern: BookName Chapter  OR  BookName Chapter:Verse[-VerseTo]
        // The colon-verse part is optional to support chapter-only references.
        $pattern = '/(?<!\p{L})('
                 . '(?:[0-9]{1,2}\.?(?:\s|\x{00A0})*)?'
                 . '[\p{L}][\p{L}\p{M}\.]*'
                 . '(?:(?:\s|\x{00A0})+[\p{L}\p{M}\.]+)*'
                 . ')(?:\s|\x{00A0})*(\d+)'
                 . '(?:(?:\s|\x{00A0})*[:\x{2236}\x{FE55}\x{FF1A}](?:\s|\x{00A0})*(\d+)(?:-(\d+))?)?'
                 . '(?!\p{L})/u';

        $parts = preg_split('/(<a\s[^>]*>.*?<\/a>)/us', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        $result = '';
        foreach ($parts as $part) {
            if (preg_match('/^<a\s/i', $part)) {
                $result .= $part;
            } else {
                $normalized_part = preg_replace('/&(nbsp|NBSP);/u', "\xC2\xA0", $part);
                if ($normalized_part !== null) {
                    $normalized_part = preg_replace('/&#160;|&#x0*a0;/iu', "\xC2\xA0", $normalized_part);
                    $normalized_part = preg_replace('/&(thinsp|ensp|emsp);/iu', ' ', $normalized_part);
                    $normalized_part = preg_replace('/&#(8194|8195|8201);|&#x(2002|2003|2009);/iu', ' ', $normalized_part);
                }
                if (!is_string($normalized_part)) {
                    $normalized_part = $part;
                }

                $normalized_part = preg_replace('/[\x{202F}\x{2000}-\x{200A}\x{2060}]/u', "\xC2\xA0", $normalized_part);
                $normalized_part = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $normalized_part);
                $result .= preg_replace_callback(
                    $pattern,
                    function ($m) use ($unified, $preferred_slug) {
                        return self::process_bible_ref_match($m, $unified, $preferred_slug);
                    },
                    $normalized_part
                );
            }
        }

        return $result;
    }

    /**
     * Backwards-compatible entry point: auto-link content for a specific language slug.
     *
     * @param string $content HTML content.
     * @param string $slug    Dataset slug (e.g. 'bible', 'bibel').
     * @return string Content with bible references linked.
     */
    public static function autolink_content_for_slug($content, $slug) {
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        return self::autolink_content($content, $slug);
    }

    /**
     * Resolve a single bible-reference regex match to a link.
     *
     * @param array  $m              Regex match groups.
     * @param array  $unified        Unified abbreviation map.
     * @param string $preferred_slug Tiebreaker slug for ambiguous abbreviations.
     * @return string Original text or HTML link.
     */
    private static function process_bible_ref_match($m, $unified, $preferred_slug) {
        if (!isset($m[1], $m[2])) return $m[0];
        $book_raw = $m[1];
        $ch = (int)$m[2];
        $vf = (isset($m[3]) && $m[3] !== '') ? (int)$m[3] : 0;
        $vt = (isset($m[4]) && $m[4] !== '') ? (int)$m[4] : 0;
        if ($ch <= 0) return $m[0];

        $book_clean = str_replace("\xC2\xA0", ' ', (string)$book_raw);
        $book_clean = preg_replace('/\.\s*$/u', '', $book_clean);
        $book_clean = preg_replace('/\s+/u', ' ', trim($book_clean));

        $short = null;
        $effective_slug = null;
        $resolved_book_text = null;
        $matched_word_start_index = null;

        $words = preg_split('/\s+/u', (string)$book_clean);
        if (is_array($words)) {
            for ($i = 0; $i < count($words); $i++) {
                $candidate = implode(' ', array_slice($words, $i));
                if ($candidate === '') continue;

                $norm = preg_replace('/\s+/u', ' ', trim($candidate));
                $key = mb_strtolower($norm, 'UTF-8');

                $resolved = self::resolve_from_unified($unified, $key, $preferred_slug);
                if ($resolved !== null) {
                    $short = $resolved['short'];
                    $effective_slug = $resolved['slug'];
                    $resolved_book_text = $norm;
                    $matched_word_start_index = $i;
                    break;
                }

                // Try normalizing "1. Corinthians" → "1 Corinthians"
                $alt = preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm);
                $alt = preg_replace('/\s+/u', ' ', trim($alt));
                $alt_key = mb_strtolower($alt, 'UTF-8');
                if ($alt_key !== $key) {
                    $resolved = self::resolve_from_unified($unified, $alt_key, $preferred_slug);
                    if ($resolved !== null) {
                        $short = $resolved['short'];
                        $effective_slug = $resolved['slug'];
                        $resolved_book_text = $alt;
                        $matched_word_start_index = $i;
                        break;
                    }
                }
            }
        }

        if ($short === null || $effective_slug === null) {
            return $m[0];
        }

        $book_slug = self::slugify($short);
        if ($book_slug === '') return $m[0];

        // Latin-first: rewrite to interlinear URL with Latin as primary text.
        if (get_option('dwbible_autolink_latin_first', '0') === '1' && $effective_slug !== 'latin') {
            $canon = self::canonicalize_key_from_dataset_book_slug($effective_slug, $short);
            if ($canon !== null) {
                $latin_short = self::resolve_book_for_dataset($canon, 'latin');
                if (is_string($latin_short) && $latin_short !== '') {
                    $effective_slug = 'latin-' . $effective_slug;
                    $book_slug = $canon; // canonical key resolves in all dataset combos
                }
            }
        }

        $base_url = get_option('dwbible_autolink_base_url', '');
        $origin = (is_string($base_url) && $base_url !== '') ? rtrim($base_url, '/') : home_url();
        $base = $origin . '/' . trim($effective_slug, '/') . '/' . $book_slug . '/';

        if ($vf > 0) {
            $url = $base . $ch . ':' . $vf . ($vt && $vt >= $vf ? '-' . $vt : '');
        } else {
            $url = $base . $ch;
        }

        $book_display = $resolved_book_text ?: $book_clean;
        if ($vf > 0) {
            $ref_text = $book_display . ' ' . $ch . ':' . $vf . ($vt && $vt >= $vf ? '-' . $vt : '');
        } else {
            $ref_text = $book_display . ' ' . $ch;
        }

        $prefix_raw = '';
        if ($matched_word_start_index !== null && $matched_word_start_index > 0) {
            $raw_tokens = preg_split('/\s+/u', (string)$book_raw, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($raw_tokens)) {
                $book_word_count = count($words) - $matched_word_start_index;
                $prefix_count = max(0, count($raw_tokens) - $book_word_count);
                if ($prefix_count > 0) {
                    $prefix_raw = implode(' ', array_slice($raw_tokens, 0, $prefix_count));
                    if ($prefix_raw !== '') {
                        $prefix_raw .= ' ';
                    }
                }
            }
        }

        return $prefix_raw . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($ref_text) . '</a>';
    }

    /**
     * Look up an abbreviation key in the unified map, handling ambiguity.
     *
     * @param array  $unified        The unified abbreviation map.
     * @param string $key            Lowercase abbreviation key.
     * @param string $preferred_slug Tiebreaker slug for ambiguous entries.
     * @return array|null ['short' => ..., 'slug' => ...] or null if not found.
     */
    private static function resolve_from_unified($unified, $key, $preferred_slug) {
        if (!isset($unified[$key])) {
            return null;
        }
        $entries = $unified[$key];
        if (count($entries) === 1) {
            return $entries[0];
        }
        // Ambiguous: prefer the tiebreaker slug if it matches an entry.
        if (is_string($preferred_slug) && $preferred_slug !== '') {
            foreach ($entries as $e) {
                if ($e['slug'] === $preferred_slug) {
                    return $e;
                }
            }
        }
        // Fallback to first entry (order from dwbible_slugs setting).
        return $entries[0];
    }
}
