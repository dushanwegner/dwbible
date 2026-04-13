<?php
/**
 * DwBible Navigation Helpers
 *
 * Injects navigation UI into book HTML: anchor points, up-arrows,
 * verse paragraph classes, prev/next chapter computation, and the
 * sticky status bar with chapter picker.
 *
 * Extracted from DwBible_Plugin::inject_nav_helpers() to keep the
 * main class focused on routing and orchestration.
 */

if (!defined('ABSPATH')) exit;

class DwBible_Nav_Helpers {

    /** Last computed nav context — available after inject() for bottom nav placement. */
    public static $last_nav_ctx = null;

    /**
     * Inject navigation helpers into rendered book HTML.
     *
     * Adds: top anchor, index up-arrow, chapter up-arrows, verse paragraph
     * classes, sticky status bar with prev/next navigation and chapter picker.
     *
     * @param string      $html              Raw book HTML.
     * @param array       $highlight_ids     Verse IDs to highlight (for verse-range URLs).
     * @param string|null $chapter_scroll_id Chapter heading ID to scroll to.
     * @param string      $book_label        Human-readable book name.
     * @param array|null  $nav               Navigation context: ['book' => slug, 'chapter' => int].
     * @return string Modified HTML with navigation elements prepended.
     */
    public static function inject($html, $highlight_ids = [], $chapter_scroll_id = null, $book_label = '', $nav = null, $lang_switcher = '') {
        if (!is_string($html) || $html === '') return $html;

        $html = self::inject_anchors_and_arrows($html);
        $html = self::inject_verse_classes($html);

        // Build sticky bar context
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        $bible_index = esc_url(trailingslashit(home_url('/' . $slug . '/')));

        $book_label = is_string($book_label) ? DwBible_Plugin::pretty_label($book_label) : '';
        $nav_ctx = self::compute_nav_urls($nav, $slug, $bible_index);
        $sticky = self::build_sticky_bar($book_label, $nav, $nav_ctx, $highlight_ids, $chapter_scroll_id, $bible_index);
        self::$last_nav_ctx = $nav_ctx;

        // Edition title above the sticky bar, with optional EN·DE lang switcher top-right
        $lang_switcher = is_string($lang_switcher) ? $lang_switcher : '';
        $edition_heading = self::build_edition_heading($slug, $bible_index, $lang_switcher);

        return $edition_heading . $sticky . $html;
    }

    /**
     * Build an edition title heading above the chapter content.
     * Links back to the Bible index page.
     */
    private static function build_edition_heading($slug, $bible_index, $lang_switcher = '') {
        // Map dataset slug (first part of combo slugs) to human-readable edition title
        $primary = $slug;
        if (strpos($primary, '-') !== false) {
            $parts = explode('-', $primary);
            $primary = $parts[0];
        }
        $editions = [
            'bible' => 'The Bible (Douay-Rheims)',
            'bibel' => 'Die Bibel (Menge)',
            'latin' => 'Biblia Sacra (Vulgata Clementina)',
        ];
        $title = $editions[$primary] ?? 'The Bible';

        $heading = '<h2 class="dwbible-edition-title">'
            . '<a href="' . $bible_index . '">' . esc_html($title) . '</a>'
            . '</h2>';

        // Wrap in a flex row so the lang switcher sits top-right of the title
        return '<div class="dwbible-edition-row">'
            . $heading
            . $lang_switcher
            . '</div>';
    }

    /**
     * Inject top anchor and up-arrows into chapters/verses blocks.
     */
    private static function inject_anchors_and_arrows($html) {
        // Stable anchor at the very top of the book content
        if (strpos($html, 'id="dwbible-book-top"') === false && strpos($html, 'id=\"dwbible-book-top\"') === false) {
            $html = '<a id="dwbible-book-top"></a>' . $html;
        }

        // Up-arrow in the first chapters block → links to Bible index
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        $bible_index = esc_url(trailingslashit(home_url('/' . $slug . '/')));
        $aria_label = ($slug === 'bibel') ? __('Back to German Bible', 'dwbible') : __('Back to Bible', 'dwbible');
        $chap_up = '<a class="dwbible-up dwbible-up-index" href="' . $bible_index . '" aria-label="' . esc_attr($aria_label) . '">&#8593;</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])chapters\1>~',
            '<p class="chapters">' . $chap_up,
            $html,
            1
        );

        // Up-arrows in verses blocks → link to top of book (skip first = Chapter 1)
        $book_top = '#dwbible-book-top';
        $vers_up = '<a class="dwbible-up dwbible-up-book" href="' . $book_top . '" aria-label="Back to book">&#8593;</a> ';
        $count = 0;
        $html = preg_replace_callback(
            '~<p\s+class=(["\"])verses\1>~',
            function($m) use (&$count, $vers_up) {
                $count++;
                if ($count === 1) {
                    return $m[0]; // Chapter 1: no up-arrow
                }
                return '<p class="verses">' . $vers_up;
            },
            $html
        );

        return $html;
    }

    /**
     * Add a "verse" class to each verse paragraph (identified by slug-ch-verse IDs).
     */
    private static function inject_verse_classes($html) {
        return preg_replace(
            '~<p\s+id=(["\"])([a-z0-9\-]+-\d+-\d+)\1>~i',
            '<p id="$2" class="verse">',
            $html
        );
    }

    /**
     * Compute prev/next/top URLs and max chapter count for the current book.
     *
     * @return array ['prev_href', 'next_href', 'top_href', 'max_ch', 'book_base_url']
     */
    private static function compute_nav_urls($nav, $slug, $bible_index) {
        $result = [
            'prev_href' => '#',
            'next_href' => '#',
            'top_href' => $bible_index,
            'max_ch' => 0,
            'book_base_url' => '',
        ];

        if (!is_array($nav)) {
            return $result;
        }

        $nav_book = $nav['book'] ?? '';
        $nav_ch = isset($nav['chapter']) ? absint($nav['chapter']) : 0;
        if (!is_string($nav_book) || $nav_book === '' || $nav_ch <= 0) {
            return $result;
        }

        $slug_for_urls = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug_for_urls) || $slug_for_urls === '') {
            $slug_for_urls = 'bible';
        }
        $is_combo = (strpos($slug_for_urls, '-') !== false);
        $url_dataset = $slug_for_urls;
        if ($is_combo) {
            $parts = array_values(array_filter(array_map('trim', explode('-', $slug_for_urls))));
            if (!empty($parts)) {
                $url_dataset = $parts[0];
            }
        }

        // Resolve ordered book list and find current book's position
        $ordered = self::call_plugin('ordered_book_slugs');
        $nav_book_for_order = $nav_book;
        $idx = array_search($nav_book_for_order, $ordered, true);

        // Try mapping for single-language pages with localized slugs
        if ($idx === false && !$is_combo && is_string($url_dataset) && $url_dataset !== '' && $url_dataset !== 'bible') {
            $mapped = self::call_plugin('url_book_slug_for_dataset', $nav_book, $url_dataset);
            if (is_string($mapped) && $mapped !== '') {
                $nav_book_for_order = $mapped;
                $idx = array_search($nav_book_for_order, $ordered, true);
            }
        }

        if ($idx === false || empty($ordered)) {
            return $result;
        }

        $count_books = count($ordered);
        $max_ch = self::call_plugin('max_chapter_for_book_slug', $nav_book_for_order);

        // Previous chapter/book
        if ($nav_ch > 1) {
            $prev_book = $nav_book_for_order;
            $prev_ch = $nav_ch - 1;
        } else {
            $prev_book = $ordered[($idx - 1 + $count_books) % $count_books];
            $prev_ch = self::call_plugin('max_chapter_for_book_slug', $prev_book);
            if ($prev_ch <= 0) {
                $prev_ch = 1;
            }
        }

        // Next chapter/book
        if ($max_ch > 0 && $nav_ch < $max_ch) {
            $next_book = $nav_book_for_order;
            $next_ch = $nav_ch + 1;
        } else {
            $next_book = $ordered[($idx + 1) % $count_books];
            $next_ch = 1;
        }

        // Resolve URL-friendly book slugs for combo language pages
        $prev_book_url = $prev_book;
        $next_book_url = $next_book;
        if ($is_combo && is_string($url_dataset) && $url_dataset !== '') {
            $prev_book_url = self::call_plugin('url_book_slug_for_dataset', $prev_book, $url_dataset);
            $next_book_url = self::call_plugin('url_book_slug_for_dataset', $next_book, $url_dataset);
        }

        $result['prev_href'] = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $prev_book_url . '/' . $prev_ch)));
        $result['next_href'] = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $next_book_url . '/' . $next_ch)));
        $result['max_ch'] = $max_ch;

        // Current book base URL (for chapter picker JS)
        $current_book_url = $nav_book_for_order;
        if ($is_combo && is_string($url_dataset) && $url_dataset !== '') {
            $cmapped = self::call_plugin('url_book_slug_for_dataset', $nav_book_for_order, $url_dataset);
            if (is_string($cmapped) && $cmapped !== '') {
                $current_book_url = $cmapped;
            }
        }
        $result['book_base_url'] = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $current_book_url)));

        return $result;
    }

    /**
     * Build the sticky status bar HTML.
     */
    private static function build_sticky_bar($book_label, $nav, $nav_ctx, $highlight_ids, $chapter_scroll_id, $bible_index) {
        $book_label_html = esc_html($book_label);

        // Resolve data-slug and initial chapter for frontend JS
        $book_slug_for_data = '';
        $initial_ch = 1;
        if (is_array($nav)) {
            $nav_book = $nav['book'] ?? '';
            $nav_ch = isset($nav['chapter']) ? absint($nav['chapter']) : 0;
            if (is_string($nav_book) && $nav_book !== '') {
                $book_slug_for_data = DwBible_Plugin::slugify($nav_book);
            }
            if ($nav_ch > 0) {
                $initial_ch = $nav_ch;
            }
        }
        if ($book_slug_for_data === '') {
            $book_slug_for_data = DwBible_Plugin::slugify($book_label);
        }
        $q_ch = absint(get_query_var(DwBible_Plugin::QV_CHAPTER));
        if ($q_ch > 0 && $initial_ch <= 1) {
            $initial_ch = $q_ch;
        }
        // esc_attr(), not esc_js() — this value goes into an HTML data-attribute, not a JS string literal
        $book_slug_js = esc_attr($book_slug_for_data);

        // Data attributes for highlight/scroll targets
        $data_attrs = '';
        if (is_array($highlight_ids) && !empty($highlight_ids)) {
            $ids_json = wp_json_encode(array_values(array_unique($highlight_ids)));
            $data_attrs .= ' data-highlight-ids=' . "'" . esc_attr($ids_json) . "'";
        } elseif (is_string($chapter_scroll_id) && $chapter_scroll_id !== '') {
            $data_attrs .= ' data-chapter-scroll-id="' . esc_attr($chapter_scroll_id) . '"';
        }

        $sticky_ch_text = (string) $initial_ch;
        $max_ch_val = $nav_ctx['max_ch'];
        $book_base_url = $nav_ctx['book_base_url'];

        // Chapter picker attributes (if multi-chapter book)
        $ch_picker_attrs = '';
        if ($max_ch_val > 1 && $book_base_url !== '') {
            $ch_picker_attrs = ' data-max-ch="' . intval($max_ch_val) . '" data-book-url="' . esc_attr($book_base_url) . '"';
        }

        // Chapter element: button (picker) or plain span
        $ch_el = ($max_ch_val > 1)
            ? '<button type="button" class="dwbible-ch-picker" data-ch aria-label="Select chapter"><span data-ch-num>' . esc_html($sticky_ch_text) . '</span> <span class="dwbible-ch-picker__caret">&#9662;</span></button>'
            : '<span class="dwbible-sticky__chapter" data-ch>' . esc_html($sticky_ch_text) . '</span>';

        return '<div class="dwbible-sticky" data-slug="' . $book_slug_js . '"' . $data_attrs . $ch_picker_attrs . '>'
            . '<div class="dwbible-sticky__left">'
            . '<span class="dwbible-sticky__label" data-label>' . $book_label_html . '</span> '
            . $ch_el
            . '</div>'
            . '<div class="dwbible-sticky__controls">'
            . '<a href="' . $nav_ctx['prev_href'] . '" class="dwbible-ctl dwbible-ctl-prev" data-prev aria-label="Previous chapter">&#8592;</a>'
            . '<a href="' . $nav_ctx['top_href'] . '" class="dwbible-ctl dwbible-ctl-top" data-top aria-label="Bible index">&#8593;</a>'
            . '<a href="' . $nav_ctx['next_href'] . '" class="dwbible-ctl dwbible-ctl-next" data-next aria-label="Next chapter">&#8594;</a>'
            . '</div>'
            . '</div>';
    }

    /**
     * Build bottom prev/next navigation bar — mirrors the sticky header arrows.
     * Public so render_book() can place it after the language switcher footer.
     */
    public static function build_bottom_nav($nav_ctx) {
        $prev = $nav_ctx['prev_href'] ?? '#';
        $next = $nav_ctx['next_href'] ?? '#';
        $prev_disabled = ($prev === '#') ? ' is-disabled' : '';
        $next_disabled = ($next === '#') ? ' is-disabled' : '';

        return '<div class="dwbible-bottom-nav">'
            . '<a href="' . $prev . '" class="dwbible-ctl dwbible-ctl-prev' . $prev_disabled . '" aria-label="Previous chapter">&#8592;</a>'
            . '<a href="' . $next . '" class="dwbible-ctl dwbible-ctl-next' . $next_disabled . '" aria-label="Next chapter">&#8594;</a>'
            . '</div>';
    }

    /**
     * Call private static methods on DwBible_Plugin via reflection.
     *
     * These methods are private in the main class but needed here for nav computation.
     * Using reflection keeps the main class API unchanged.
     *
     * @param string $method Method name on DwBible_Plugin.
     * @param mixed  ...$args Arguments to pass.
     * @return mixed Return value from the method.
     */
    private static function call_plugin($method, ...$args) {
        static $methods = [];
        if (!isset($methods[$method])) {
            $ref = new ReflectionMethod('DwBible_Plugin', $method);
            $ref->setAccessible(true);
            $methods[$method] = $ref;
        }
        return $methods[$method]->invoke(null, ...$args);
    }
}
