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
     * The Bible reader's directional nav chevron — delegates to the ONE site-wide
     * chevron, dwtheme_chevron() (dwtheme/inc/chevron.php). No plugin-local SVG:
     * every prev/next/up control in the reader renders the same shared mark.
     * Falls back to a bare glyph only if the theme function is unavailable.
     *
     * @param string $dir 'right' (default) | 'left' | 'up'
     * @return string Inline chevron HTML.
     */
    private static function chevron($dir = 'right') {
        if (function_exists('dwtheme_chevron')) {
            return dwtheme_chevron($dir);
        }
        $g = ['left' => '&#8592;', 'up' => '&#8593;', 'right' => '&#8594;'];
        return $g[$dir] ?? $g['right'];
    }

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
    public static function inject($html, $highlight_ids = [], $chapter_scroll_id = null, $book_label = '', $nav = null, $lang_switcher = '', $book_subtitle = '') {
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
        // Lang switcher now lives inside the sticky bar so it stays reachable while scrolled.
        $lang_switcher = is_string($lang_switcher) ? $lang_switcher : '';
        // The edition line ("Biblia Sacra …") is the page-head SUBTITLE — it now
        // renders UNDER the title inside the sticky head (Kant canonical head:
        // loud title first, quiet subtitle below, one hairline), not as an eyebrow
        // stacked above the title.
        // The page-head subtitle: prefer the Latin book name (passed in), so the reader sees
        // "<vernacular title> / <Latin name>". Falls back to the edition line when there is no
        // distinct Latin subtitle (e.g. language-neutral names like "Genesis").
        $book_subtitle = is_string($book_subtitle) ? DwBible_Plugin::pretty_label($book_subtitle) : '';
        $edition_sub = ($book_subtitle !== '')
            ? '<p class="dwbible-edition-sub dwbible-edition-sub--latin">' . esc_html($book_subtitle) . '</p>'
            : self::build_edition_heading($slug, $bible_index);
        $sticky = self::build_sticky_bar($book_label, $nav, $nav_ctx, $highlight_ids, $chapter_scroll_id, $bible_index, $lang_switcher, $edition_sub);
        self::$last_nav_ctx = $nav_ctx;

        return $sticky . $html;
    }

    /**
     * Build an edition title heading above the chapter content.
     * Links back to the Bible index page.
     */
    private static function build_edition_heading($slug, $bible_index) {
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

        // Page-head SUBTITLE (under the title), not an eyebrow above it. Links
        // back to the Bible index.
        return '<p class="dwbible-edition-sub">'
            . '<a href="' . $bible_index . '">' . esc_html($title) . '</a>'
            . '</p>';
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
        $chap_up = '<a class="dwbible-up dwbible-up-index" href="' . $bible_index . '" aria-label="' . esc_attr($aria_label) . '">' . self::chevron('up') . '</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])chapters\1>~',
            '<p class="chapters">' . $chap_up,
            $html,
            1
        );

        // Verse-jumper rows removed — the per-chapter "1 2 3 4 5" anchor list adds
        // clutter above the reading; scrolling to a verse is easy enough. Strip the
        // whole <p class="verses">…</p> block wherever it appears.
        $html = preg_replace('~<p\s+class=(["\'])verses\1>.*?</p>~s', '', $html);

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

        // A combo slug (latin-bible) has no data folder of its own, so the book
        // order and the chapter counts asked under it came back empty and both
        // arrows stayed '#' on every interlinear chapter (q7734). They are read
        // from the 'bible' dataset instead: its book slugs are the internal keys
        // the interlinear passes in $nav['book'].
        $saved_slug = null;
        if ($is_combo) {
            $saved_slug = get_query_var(DwBible_Plugin::QV_SLUG);
            set_query_var(DwBible_Plugin::QV_SLUG, 'bible');
        }
        $order = self::book_order_and_counts($nav_book, $nav_ch, $is_combo, $url_dataset);
        if ($is_combo) {
            set_query_var(DwBible_Plugin::QV_SLUG, $saved_slug);
        }
        if ($order === null) {
            return $result;
        }
        [$nav_book_for_order, $max_ch, $prev_book, $prev_ch, $next_book, $next_ch] = $order;

        // Resolve URL-friendly book slugs for combo language pages
        $prev_book_url = $prev_book;
        $next_book_url = $next_book;
        if ($is_combo && is_string($url_dataset) && $url_dataset !== '') {
            if ($prev_book !== '') { $prev_book_url = self::call_plugin('url_book_slug_for_dataset', $prev_book, $url_dataset); }
            if ($next_book !== '') { $next_book_url = self::call_plugin('url_book_slug_for_dataset', $next_book, $url_dataset); }
        }

        // '' = there is none: the first chapter of the Bible has no previous
        // one and the last has no next (no wrap-around to the other end).
        $result['prev_href'] = $prev_book === '' ? '' : esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $prev_book_url . '/' . $prev_ch)));
        $result['next_href'] = $next_book === '' ? '' : esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $next_book_url . '/' . $next_ch)));
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
     * The book this chapter belongs to in the reading order, its chapter count,
     * and the previous and next chapter (book slug + number; '' and 0 where
     * there is none). Read under whatever dataset QV_SLUG names now.
     *
     * @return array|null [book, max_ch, prev_book, prev_ch, next_book, next_ch], null if the book is not in the order
     */
    private static function book_order_and_counts($nav_book, $nav_ch, $is_combo, $url_dataset) {
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
            return null;
        }

        $count_books = count($ordered);
        $max_ch = self::call_plugin('max_chapter_for_book_slug', $nav_book_for_order);

        // Previous chapter/book; none before the Bible's first book
        $prev_book = '';
        $prev_ch = 0;
        if ($nav_ch > 1) {
            $prev_book = $nav_book_for_order;
            $prev_ch = $nav_ch - 1;
        } elseif (true) {
            $prev_book = $ordered[($idx - 1 + $count_books) % $count_books];
            $prev_ch = self::call_plugin('max_chapter_for_book_slug', $prev_book);
            if ($prev_ch <= 0) {
                $prev_ch = 1;
            }
        }

        // Next chapter/book; none after the Bible's last book
        $next_book = '';
        $next_ch = 0;
        if ($max_ch > 0 && $nav_ch < $max_ch) {
            $next_book = $nav_book_for_order;
            $next_ch = $nav_ch + 1;
        } elseif ($idx < $count_books - 1) {
            $next_book = $ordered[$idx + 1];
            $next_ch = 1;
        }

        return [$nav_book_for_order, $max_ch, $prev_book, $prev_ch, $next_book, $next_ch];
    }

    /**
     * Build the sticky status bar HTML.
     */
    private static function build_sticky_bar($book_label, $nav, $nav_ctx, $highlight_ids, $chapter_scroll_id, $bible_index, $lang_switcher = '', $edition_sub = '') {
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

        // Book + chapter are plain labels — the handmade book/chapter dropdowns
        // were retired in favour of the side-rail (Old/New Testament + current
        // book) for book navigation, and the in-page chapter row + prev/next
        // chevrons for chapters. "More clicks, less confusion": one calm rail
        // beats a 73-entry overlay. No picker buttons, carets, or data-* lists.
        $ch_el = '<span class="dwbible-sticky__chapter" data-ch>' . esc_html($sticky_ch_text) . '</span>';

        $lang_switcher = is_string($lang_switcher) ? $lang_switcher : '';

        $book_el = '<span class="dwbible-sticky__label" data-label>' . $book_label_html . '</span>';

        // Left column = the page head: a loud title row (book + chapter) with the
        // quiet edition subtitle beneath it (Kant canonical head order).
        return '<div class="dwbible-sticky" data-slug="' . $book_slug_js . '"' . $data_attrs . '>'
            . '<div class="dwbible-sticky__left">'
            . '<div class="dwbible-sticky__titlerow">' . $book_el . ' ' . $ch_el . '</div>'
            . $edition_sub
            . '</div>'
            . $lang_switcher
            . '<div class="dwbible-sticky__controls">'
            . '<a' . self::arrow_href($nav_ctx['prev_href'], 'dwbible-ctl dwbible-ctl-prev') . ' data-prev aria-label="' . esc_attr__( 'Previous chapter', 'dwbible' ) . '">' . self::chevron('left') . '</a>'
            . '<a href="' . $nav_ctx['top_href'] . '" class="dwbible-ctl dwbible-ctl-top" data-top aria-label="' . esc_attr__( 'Bible index', 'dwbible' ) . '">' . self::chevron('up') . '</a>'
            . '<a' . self::arrow_href($nav_ctx['next_href'], 'dwbible-ctl dwbible-ctl-next') . ' data-next aria-label="' . esc_attr__( 'Next chapter', 'dwbible' ) . '">' . self::chevron('right') . '</a>'
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

        return '<nav class="pager pager--reader" aria-label="' . esc_attr__( 'Chapter navigation', 'dwbible' ) . '">'
            . '<a' . self::arrow_href($prev, 'pager__prev') . ' aria-label="' . esc_attr__( 'Previous chapter', 'dwbible' ) . '">' . self::chevron('left') . '</a>'
            . '<a' . self::arrow_href($next, 'pager__next') . ' aria-label="' . esc_attr__( 'Next chapter', 'dwbible' ) . '">' . self::chevron('right') . '</a>'
            . '</nav>';
    }

    /**
     * The href and class of one prev/next arrow. '' is an arrow with nowhere to
     * go (before the Bible's first chapter, after its last): it is drawn with no
     * href at all, so it is not a link, takes no focus and has no address to
     * follow, and it wears the disabled look it always had. '#' is the old
     * "not computed" value: disabled too, and the script may still manage it.
     */
    private static function arrow_href($href, $class) {
        if ($href === '') {
            return ' class="' . $class . ' is-disabled" aria-disabled="true"';
        }
        return ' href="' . $href . '" class="' . $class . ($href === '#' && strpos($class, 'pager__') === 0 ? ' is-disabled' : '') . '"';
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
