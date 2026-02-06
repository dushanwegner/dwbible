<?php
/*
* Plugin Name: The Bible
* Description: Provides /bible/ with links to books; renders selected book HTML using the site's template.
* Version: 1.26.02.06.01
* Author: Dushan Wegner
*/

if (!defined('ABSPATH')) exit;

if (!defined('THEBIBLE_VERSION')) {
    define('THEBIBLE_VERSION', '1.26.02.06.01');
}

// Load include classes before hooks are registered
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-votd-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-votd-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-meta.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-og-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-reference.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-qa.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-sync-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-text-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-export.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-front-meta.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-footer-renderer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-data-paths.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-index-loader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-mappings-loader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-osis-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-canonicalization.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-abbreviations-loader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-render-interlinear.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-router.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-selftest.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-autolink.php';

class TheBible_Plugin {
    use TheBible_Interlinear_Trait;
    use TheBible_Router_Trait;
    use TheBible_SelfTest_Trait;
    use TheBible_AutoLink_Trait;
    const QV_FLAG = 'thebible';
    const QV_BOOK = 'thebible_book';
    const QV_CHAPTER = 'thebible_ch';
    const QV_VFROM = 'thebible_vfrom';
    const QV_VTO = 'thebible_vto';
    const QV_SLUG = 'thebible_slug';
    const QV_OG   = 'thebible_og';
    const QV_SITEMAP = 'thebible_sitemap';
    const QV_SELFTEST = 'thebible_selftest';
    const QV_VOTD_RSS = 'thebible_votd_rss';

    private static $books = null; // array of [order, short_name, filename]
    private static $slug_map = null; // slug => array entry
    private static $abbr_maps = [];
    private static $book_map = null;
    private static $current_page_title = '';
    private static $max_chapters = [];
    private static $index_slug = null;
    private static $osis_mapping = null;

    /**
     * Plugin bootstrap: registers hooks, routes, widgets, admin pages, and test endpoints.
     */
    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_action('init', [__CLASS__, 'maybe_flush_rewrite_rules'], 20);
        add_action('init', ['TheBible_VOTD_Admin', 'register_votd_cpt']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_request']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_filter('upload_mimes', [__CLASS__, 'allow_font_uploads']);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'allow_font_filetype'], 10, 5);

        add_action('add_meta_boxes', ['TheBible_Admin_Meta', 'add_bible_meta_box']);
        add_action('save_post', ['TheBible_Admin_Meta', 'save_bible_meta'], 10, 2);

        add_filter('manage_posts_columns', ['TheBible_Admin_Meta', 'add_bible_column']);
        add_action('manage_posts_custom_column', ['TheBible_Admin_Meta', 'render_bible_column'], 10, 2);

        add_action('widgets_init', [__CLASS__, 'register_widgets']);
        // Admin list enhancements for Verse of the Day CPT
        add_filter('manage_edit-thebible_votd_columns', ['TheBible_VOTD_Admin', 'votd_columns']);
        add_filter('manage_edit-thebible_votd_sortable_columns', ['TheBible_VOTD_Admin', 'votd_sortable_columns']);
        add_action('manage_thebible_votd_posts_custom_column', ['TheBible_VOTD_Admin', 'render_votd_column'], 10, 2);
        add_action('restrict_manage_posts', ['TheBible_VOTD_Admin', 'votd_date_filter']);
        add_action('pre_get_posts', ['TheBible_VOTD_Admin', 'apply_votd_date_filter']);
        add_action('admin_notices', ['TheBible_VOTD_Admin', 'votd_condense_notice']);
        add_action('load-edit.php', ['TheBible_VOTD_Admin', 'handle_votd_condense_request']);
        add_filter('bulk_actions-edit-thebible_votd', ['TheBible_VOTD_Admin', 'votd_register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-thebible_votd', ['TheBible_VOTD_Admin', 'votd_handle_bulk_actions'], 10, 3);

        add_action('add_meta_boxes', ['TheBible_VOTD_Admin', 'add_votd_meta_box']);
        add_action('save_post', ['TheBible_VOTD_Admin', 'save_votd_meta'], 10, 2);
        add_action('save_post', ['TheBible_VOTD_Admin', 'flush_on_votd_save'], 10, 3);

        add_filter('the_content', [__CLASS__, 'filter_content_auto_link_bible_refs'], 20);

        add_filter('bulk_actions-edit-post', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('bulk_actions-edit-page', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('handle_bulk_actions-edit-post', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);

        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function maybe_flush_rewrite_rules() {
        $stored = get_option('thebible_rewrite_version', '');
        if (!is_string($stored)) {
            $stored = '';
        }
        if ($stored === THEBIBLE_VERSION) {
            return;
        }

        self::add_rewrite_rules();
        flush_rewrite_rules(false);
        update_option('thebible_rewrite_version', THEBIBLE_VERSION);
    }

    public static function add_settings_page() {
        self::admin_menu();
    }

    public static function enqueue_admin_assets($hook) {
        self::admin_enqueue($hook);
    }

    private static function ordered_book_slugs() {
        self::load_index();
        $out = [];
        if (!is_array(self::$books) || empty(self::$books)) {
            return $out;
        }
        $books = self::$books;
        usort($books, function($a, $b) {
            $ao = isset($a['order']) ? intval($a['order']) : 0;
            $bo = isset($b['order']) ? intval($b['order']) : 0;
            return $ao <=> $bo;
        });
        foreach ($books as $entry) {
            if (!is_array($entry) || empty($entry['short_name'])) continue;
            $slug = self::slugify($entry['short_name']);
            if ($slug === '') continue;
            $out[] = $slug;
        }
        return array_values(array_unique($out));
    }

    private static function max_chapter_for_book_slug($book_slug) {
        $book_slug = self::slugify($book_slug);
        if ($book_slug === '') return 0;
        if (isset(self::$max_chapters[$book_slug])) {
            return intval(self::$max_chapters[$book_slug]);
        }
        self::load_index();
        $entry = self::$slug_map[$book_slug] ?? null;
        if (!is_array($entry) || empty($entry['filename'])) {
            self::$max_chapters[$book_slug] = 0;
            return 0;
        }
        $file = self::html_dir() . $entry['filename'];
        if (!is_string($file) || $file === '' || !file_exists($file)) {
            self::$max_chapters[$book_slug] = 0;
            return 0;
        }
        $html = (string) @file_get_contents($file);
        if ($html === '') {
            self::$max_chapters[$book_slug] = 0;
            return 0;
        }
        $max = 0;
        if (preg_match_all('/\bid="' . preg_quote($book_slug, '/') . '-ch-(\d+)"/i', $html, $m)) {
            foreach ($m[1] as $num) {
                $n = intval($num);
                if ($n > $max) $max = $n;
            }
        }
        if ($max <= 0 && preg_match_all('/\bid="' . preg_quote($book_slug, '/') . '-(\d+)-(\d+)"/i', $html, $m2)) {
            foreach ($m2[1] as $num) {
                $n = intval($num);
                if ($n > $max) $max = $n;
            }
        }
        self::$max_chapters[$book_slug] = $max;
        return $max;
    }

    private static function u_strlen($s) {
        if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($arr) ? count($arr) : strlen((string)$s);
    }

    private static function u_substr($s, $start, $len = null) {
        if (function_exists('mb_substr')) return $len === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($arr)) return '';
        $slice = array_slice($arr, $start, $len === null ? null : $len);
        return implode('', $slice);
    }

    private static function inject_nav_helpers($html, $highlight_ids = [], $chapter_scroll_id = null, $book_label = '', $nav = null) {
        if (!is_string($html) || $html === '') return $html;

        // Ensure a stable anchor at the very top of the book content
        if (strpos($html, 'id="thebible-book-top"') === false && strpos($html, 'id=\"thebible-book-top\"') === false) {
            $html = '<a id="thebible-book-top"></a>' . $html;
        }

        // Prepend an up-arrow to the first chapters block linking back to the current Bible index
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        $bible_index = esc_url(trailingslashit(home_url('/' . $slug . '/')));
        $aria_label = ($slug === 'bibel') ? __('Back to German Bible', 'thebible') : __('Back to Bible', 'thebible');
        $chap_up = '<a class="thebible-up thebible-up-index" href="' . $bible_index . '" aria-label="' . esc_attr($aria_label) . '">&#8593;</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])chapters\1>~',
            '<p class="chapters">' . $chap_up,
            $html,
            1
        );

        // Prepend an up-arrow to verses blocks linking back to top of book, but skip the first (Chapter 1)
        $book_top = '#thebible-book-top';
        $vers_up = '<a class="thebible-up thebible-up-book" href="' . $book_top . '" aria-label="Back to book">&#8593;</a> ';
        $count = 0;
        $html = preg_replace_callback(
            '~<p\s+class=(["\"])verses\1>~',
            function($m) use (&$count, $vers_up) {
                $count++;
                if ($count === 1) {
                    // First verses list (chapter 1): no up-arrow
                    return $m[0];
                }
                return '<p class="verses">' . $vers_up;
            },
            $html
        );

        // Ensure each verse paragraph has a class for styling (IDs use slug-ch-verse)
        $html = preg_replace(
            '~<p\s+id=(["\"])([a-z0-9\-]+-\d+-\d+)\1>~i',
            '<p id="$2" class="verse">',
            $html
        );

        // Add sticky status bar at top (book + current chapter)
        $book_label = is_string($book_label) ? self::pretty_label($book_label) : '';
        $book_label_html = esc_html( $book_label );

        $vf = absint(get_query_var(self::QV_VFROM));

        // data-slug is used by frontend JS to resolve headings/verses; it must be canonical
        $book_slug_for_data = '';
        $initial_ch = 1;
        if (is_array($nav)) {
            $nav_book = $nav['book'] ?? '';
            $nav_ch = isset($nav['chapter']) ? absint($nav['chapter']) : 0;
            if (is_string($nav_book) && $nav_book !== '') {
                $book_slug_for_data = self::slugify($nav_book);
            }
            if ($nav_ch > 0) {
                $initial_ch = $nav_ch;
            }
        }
        if ($book_slug_for_data === '') {
            $book_slug_for_data = self::slugify($book_label);
        }
        $q_ch = absint(get_query_var(self::QV_CHAPTER));
        if ($q_ch > 0 && $initial_ch <= 1) {
            $initial_ch = $q_ch;
        }
        $book_slug_js = esc_js($book_slug_for_data);

        $prev_href = '#';
        $next_href = '#';
        $top_href = $bible_index;
        if (is_array($nav)) {
            $nav_book = $nav['book'] ?? '';
            $nav_ch = isset($nav['chapter']) ? absint($nav['chapter']) : 0;
            if (is_string($nav_book) && $nav_book !== '' && $nav_ch > 0) {
                $slug_for_urls = get_query_var(self::QV_SLUG);
                if (!is_string($slug_for_urls) || $slug_for_urls === '') { $slug_for_urls = 'bible'; }
                $is_combo = (strpos($slug_for_urls, '-') !== false);
                $url_dataset = $slug_for_urls;
                if ($is_combo) {
                    $parts = array_values(array_filter(array_map('trim', explode('-', $slug_for_urls))));
                    if (!empty($parts)) {
                        $url_dataset = $parts[0];
                    }
                }
                $ordered = self::ordered_book_slugs();
                $nav_book_for_order = $nav_book;
                $idx = array_search($nav_book_for_order, $ordered, true);
                if ($idx === false && !$is_combo && is_string($url_dataset) && $url_dataset !== '' && $url_dataset !== 'bible') {
                    // Single-language pages may still be using a canonical slug internally (e.g. job)
                    // while the dataset index uses a localized slug (e.g. hiob). Try mapping.
                    $mapped = self::url_book_slug_for_dataset($nav_book, $url_dataset);
                    if (is_string($mapped) && $mapped !== '') {
                        $nav_book_for_order = $mapped;
                        $idx = array_search($nav_book_for_order, $ordered, true);
                    }
                }
                if ($idx !== false && !empty($ordered)) {
                    $count_books = count($ordered);
                    $max_ch = self::max_chapter_for_book_slug($nav_book_for_order);
                    if ($nav_ch > 1) {
                        $prev_book = $nav_book_for_order;
                        $prev_ch = $nav_ch - 1;
                    } else {
                        $prev_book = $ordered[($idx - 1 + $count_books) % $count_books];
                        $prev_ch = self::max_chapter_for_book_slug($prev_book);
                        if ($prev_ch <= 0) { $prev_ch = 1; }
                    }
                    if ($max_ch > 0 && $nav_ch < $max_ch) {
                        $next_book = $nav_book_for_order;
                        $next_ch = $nav_ch + 1;
                    } else {
                        $next_book = $ordered[($idx + 1) % $count_books];
                        $next_ch = 1;
                    }

                    $prev_book_url = $prev_book;
                    $next_book_url = $next_book;
                    if ($is_combo && is_string($url_dataset) && $url_dataset !== '') {
                        $prev_book_url = self::url_book_slug_for_dataset($prev_book, $url_dataset);
                        $next_book_url = self::url_book_slug_for_dataset($next_book, $url_dataset);
                    }

                    $prev_href = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $prev_book_url . '/' . $prev_ch)));
                    $next_href = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $next_book_url . '/' . $next_ch)));
                }
            }
        }

        // Prepare data attributes for frontend JS (highlight targets / chapter scroll)
        $data_attrs = '';
        if ( is_array( $highlight_ids ) && ! empty( $highlight_ids ) ) {
            $ids_json = wp_json_encode( array_values( array_unique( $highlight_ids ) ) );
            $data_attrs .= ' data-highlight-ids=' . "'" . esc_attr( $ids_json ) . "'";
        } elseif ( is_string( $chapter_scroll_id ) && $chapter_scroll_id !== '' ) {
            $data_attrs .= ' data-chapter-scroll-id="' . esc_attr( $chapter_scroll_id ) . '"';
        }

        // Keep book label in the label span; chapter span should only contain the chapter number
        // to avoid visual duplication like "Job Job 31".
        $sticky_ch_text = (string)$initial_ch;

        $sticky = '<div class="thebible-sticky" data-slug="' . $book_slug_js . '"' . $data_attrs . '>'
                . '<div class="thebible-sticky__left">'
                . '<span class="thebible-sticky__label" data-label>' . $book_label_html . '</span> '
                . '<span class="thebible-sticky__chapter" data-ch>' . esc_html($sticky_ch_text) . '</span>'
                . '</div>'
                . '<div class="thebible-sticky__controls">'
                . '<a href="' . $prev_href . '" class="thebible-ctl thebible-ctl-prev" data-prev aria-label="Previous chapter">&#8592;</a>'
                . '<a href="' . $top_href . '" class="thebible-ctl thebible-ctl-top" data-top aria-label="Bible index">&#8593;</a>'
                . '<a href="' . $next_href . '" class="thebible-ctl thebible-ctl-next" data-next aria-label="Next chapter">&#8594;</a>'
                . '</div>'
                . '</div>';
        $html = $sticky . $html;

        return $html;
    }

    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
        // Clean up legacy options no longer used by the plugin.
        delete_option( 'thebible_custom_css' );
        delete_option( 'thebible_prod_domain' );
    }

    public static function add_rewrite_rules() {
        $slugs = self::base_slugs();
        foreach ($slugs as $slug) {
            $slug = trim($slug, "/ ");
            if ($slug === '') continue;
            // index
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}:{verse} or {chapter}:{from}-{to}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+):([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
        }
        // Verse of the Day RSS feed
        add_rewrite_rule('^bible-votd\.rss$', 'index.php?' . self::QV_VOTD_RSS . '=1', 'top');
        // Sitemaps: English, German, and Latin (use unique endpoints to avoid conflicts with other sitemap plugins)
        add_rewrite_rule('^bible-sitemap-bible\.xml$', 'index.php?' . self::QV_SITEMAP . '=bible&' . self::QV_SLUG . '=bible', 'top');
        add_rewrite_rule('^bible-sitemap-bibel\.xml$', 'index.php?' . self::QV_SITEMAP . '=bibel&' . self::QV_SLUG . '=bibel', 'top');
        add_rewrite_rule('^bible-sitemap-latin\.xml$', 'index.php?' . self::QV_SITEMAP . '=latin&' . self::QV_SLUG . '=latin', 'top');
    }

    public static function enqueue_assets() {
        // Enqueue styles and scripts only on plugin routes
        $is_bible = ! empty( get_query_var( self::QV_FLAG ) )
            || ! empty( get_query_var( self::QV_BOOK ) )
            || ! empty( get_query_var( self::QV_SLUG ) );
        if ( $is_bible ) {
            $css_url = plugins_url( 'assets/thebible.css', __FILE__ );
            wp_enqueue_style( 'thebible-styles', $css_url, [], '0.1.3' );

            // Enqueue theme script first (in the head) to prevent flash of unstyled content
            $theme_js_url = plugins_url( 'assets/thebible-theme.js', __FILE__ );
            wp_enqueue_script( 'thebible-theme', $theme_js_url, [], '0.1.0', false );
            
            // Main frontend script in the footer
            $js_url = plugins_url( 'assets/thebible-frontend.js', __FILE__ );
            wp_enqueue_script( 'thebible-frontend', $js_url, [], '0.1.0', true );
        }
    }

    public static function add_query_vars($vars) {
        $vars[] = self::QV_FLAG;
        $vars[] = self::QV_BOOK;
        $vars[] = self::QV_CHAPTER;
        $vars[] = self::QV_VFROM;
        $vars[] = self::QV_VTO;
        $vars[] = self::QV_SLUG;
        $vars[] = self::QV_OG;
        $vars[] = self::QV_SITEMAP;
        $vars[] = self::QV_SELFTEST;
        $vars[] = self::QV_VOTD_RSS;
        return $vars;
    }

    private static function votd_rss_available_language_slugs() {
        $list = get_option('thebible_slugs', 'bible,bibel');
        if (!is_string($list)) {
            $list = 'bible';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $list))));
        if (empty($parts)) {
            $parts = ['bible'];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = sanitize_key($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        if (empty($out)) {
            $out = ['bible'];
        }
        return $out;
    }

    private static function votd_rss_format_date($date_ymd) {
        if (!is_string($date_ymd) || $date_ymd === '') {
            return '';
        }
        $ts = strtotime($date_ymd . ' 00:00:00');
        if (!$ts) {
            return $date_ymd;
        }
        $mode = (string) get_option('thebible_votd_rss_date_format', 'site');
        if ($mode === 'de_numeric') {
            return date_i18n('j.n.Y', $ts);
        }
        if ($mode === 'ymd') {
            return date_i18n('Y-m-d', $ts);
        }
        return date_i18n(get_option('date_format'), $ts);
    }

    private static function votd_rss_build_verse_url($canonical_book_slug, $chapter, $vfrom, $vto, $lang_first, $lang_last) {
        $lang_first = sanitize_key($lang_first);
        $lang_last = sanitize_key($lang_last);
        if ($lang_first === '') {
            $lang_first = 'bible';
        }
        if ($lang_last === '') {
            $lang_last = $lang_first;
        }
        $link_slug = ($lang_last !== $lang_first) ? ($lang_first . '-' . $lang_last) : $lang_first;

        $book_for_url = self::resolve_book_for_dataset($canonical_book_slug, $lang_first);
        if (!is_string($book_for_url) || $book_for_url === '') {
            $book_for_url = $canonical_book_slug;
        }
        $book_slug_for_url = self::slugify($book_for_url);
        if (!is_string($book_slug_for_url) || $book_slug_for_url === '') {
            $book_slug_for_url = (string) $canonical_book_slug;
        }

        $path_ref = '/' . trim($link_slug, '/') . '/' . trim($book_slug_for_url, '/') . '/' . (int) $chapter . ':' . (int) $vfrom . ((int) $vto > (int) $vfrom ? ('-' . (int) $vto) : '');
        return home_url($path_ref);
    }

    public static function render_votd_rss() {
        $days = (int) get_option('thebible_votd_rss_days', 7);
        if ($days <= 0) {
            $days = 7;
        }
        if ($days > 31) {
            $days = 31;
        }

        $today = current_time('Y-m-d');
        $entries = [];
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime($today . ' -' . $i . ' day'));
            $ref = TheBible_VOTD_Admin::get_votd_for_date($d);
            if (is_array($ref)) {
                $entries[] = $ref;
            }
        }

        if (empty($entries)) {
            status_header(404);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No Verse of the Day available.';
            exit;
        }

        $available = self::votd_rss_available_language_slugs();
        $lang_first = sanitize_key((string) get_option('thebible_votd_rss_lang_first', 'bible'));
        $lang_last = sanitize_key((string) get_option('thebible_votd_rss_lang_last', ''));
        if (!in_array($lang_first, $available, true)) {
            $lang_first = $available[0];
        }
        if ($lang_last === '') {
            $lang_last = $lang_first;
        }
        if (!in_array($lang_last, $available, true)) {
            $lang_last = $lang_first;
        }
        $langs_to_show = ($lang_last !== $lang_first) ? [$lang_first, $lang_last] : [$lang_first];

        // Emit RSS
        status_header(200);
        nocache_headers();
        header('Content-Type: application/rss+xml; charset=UTF-8');

        $channel_title = (string) get_option('thebible_votd_rss_title', 'Verse of the Day');
        if (!is_string($channel_title) || $channel_title === '') {
            $channel_title = 'Verse of the Day';
        }

        $site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
        if (!is_string($site_name)) {
            $site_name = '';
        }

        $feed_title = $site_name !== '' ? ($site_name . ' — ' . $channel_title) : $channel_title;
        $feed_link = home_url('/bible-votd.rss');

        $ts_pub = time();
        if (isset($entries[0]['date']) && is_string($entries[0]['date']) && $entries[0]['date'] !== '') {
            $ts_pub_cand = strtotime($entries[0]['date'] . ' 00:00:00');
            if ($ts_pub_cand) {
                $ts_pub = $ts_pub_cand;
            }
        }

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<rss version="2.0">' . "\n";
        echo '  <channel>' . "\n";
        echo '    <title>' . esc_html($feed_title) . '</title>' . "\n";
        echo '    <link>' . esc_url($feed_link) . '</link>' . "\n";
        echo '    <description>' . esc_html($channel_title) . '</description>' . "\n";
        echo '    <language>' . esc_html(get_bloginfo('language')) . '</language>' . "\n";
        echo '    <lastBuildDate>' . esc_html(gmdate('r', $ts_pub)) . '</lastBuildDate>' . "\n";

        $tpl = (string) get_option('thebible_votd_rss_description_tpl', '{date} — {verse}');
        foreach ($entries as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $canonical = isset($ref['book_slug']) ? $ref['book_slug'] : '';
            $chapter   = isset($ref['chapter']) ? (int) $ref['chapter'] : 0;
            $vfrom     = isset($ref['vfrom']) ? (int) $ref['vfrom'] : 0;
            $vto       = isset($ref['vto']) ? (int) $ref['vto'] : 0;
            $date      = isset($ref['date']) ? $ref['date'] : '';
            $texts     = isset($ref['texts']) && is_array($ref['texts']) ? $ref['texts'] : [];

            if (!is_string($canonical) || $canonical === '' || $chapter <= 0 || $vfrom <= 0) {
                continue;
            }
            if ($vto <= 0 || $vto < $vfrom) {
                $vto = $vfrom;
            }

            // Title: always reference label based on English dataset
            $short_en = self::resolve_book_for_dataset($canonical, 'bible');
            if (!is_string($short_en) || $short_en === '') {
                $label = ucwords(str_replace('-', ' ', (string) $canonical));
            } else {
                $label = self::pretty_label($short_en);
            }
            $ref_str = $label . ' ' . $chapter . ':' . ($vfrom === $vto ? $vfrom : ($vfrom . '-' . $vto));

            $display_date = self::votd_rss_format_date($date);
            $url_ref = self::votd_rss_build_verse_url($canonical, $chapter, $vfrom, $vto, $lang_first, $lang_last);
            $image_url = add_query_arg(['thebible_og' => 1], $url_ref);

            $t1 = '';
            $t2 = '';
            if (isset($langs_to_show[0]) && isset($texts[$langs_to_show[0]]) && is_string($texts[$langs_to_show[0]])) {
                $t1 = self::clean_verse_text_for_output($texts[$langs_to_show[0]], false, '»', '«');
            }
            if (isset($langs_to_show[1]) && isset($texts[$langs_to_show[1]]) && is_string($texts[$langs_to_show[1]])) {
                $t2 = self::clean_verse_text_for_output($texts[$langs_to_show[1]], false, '»', '«');
            }
            $desc = strtr($tpl, [
                '{date}' => (string) $display_date,
                '{verse}' => (string) $ref_str,
                '{text1}' => (string) $t1,
                '{text2}' => (string) $t2,
                '{url}' => (string) $url_ref,
            ]);

            $ts_item = $date !== '' ? strtotime($date . ' 00:00:00') : time();
            if (!$ts_item) {
                $ts_item = time();
            }

            echo '    <item>' . "\n";
            echo '      <title>' . esc_html($ref_str) . '</title>' . "\n";
            echo '      <link>' . esc_url($url_ref) . '</link>' . "\n";
            echo '      <guid isPermaLink="false">' . esc_html($url_ref . '|votd|' . (string) $date) . '</guid>' . "\n";
            echo '      <pubDate>' . esc_html(gmdate('r', $ts_item)) . '</pubDate>' . "\n";
            echo '      <enclosure url="' . esc_url($image_url) . '" type="image/png" />' . "\n";
            echo '      <description><![CDATA[' . $desc . ']]></description>' . "\n";
            echo '    </item>' . "\n";
        }
        echo '  </channel>' . "\n";
        echo '</rss>';
        exit;
    }

    private static function data_root_dir() {
        return TheBible_Data_Paths::data_root_dir();
    }

    private static function html_dir() {
        return TheBible_Data_Paths::html_dir();
    }

    private static function text_dir() {
        return TheBible_Data_Paths::text_dir();
    }

    private static function index_csv_path() {
        return self::html_dir() . 'index.csv';
    }

    private static function load_index() {
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }

        // Cache index per slug; interlinear pages can switch slugs frequently.
        if (self::$books !== null && is_string(self::$index_slug) && self::$index_slug === $slug) {
            return;
        }
        self::$books = [];
        self::$slug_map = [];
        self::$index_slug = $slug;
        $csv = self::index_csv_path();
        $parsed = TheBible_Index_Loader::load_index($csv);
        if (is_array($parsed)) {
            if (isset($parsed['books']) && is_array($parsed['books'])) {
                self::$books = $parsed['books'];
            }
            if (isset($parsed['slug_map']) && is_array($parsed['slug_map'])) {
                self::$slug_map = $parsed['slug_map'];
            }
        }
    }

    public static function slugify($name) {
        $slug = strtolower($name);
        $slug = str_replace([' ', '__'], ['-', '-'], $slug);
        $slug = str_replace(['_', '\\', '/'], ['-', '-', '-'], $slug);
        $slug = preg_replace('/[^a-z0-9\-]+/', '', $slug);
        $slug = preg_replace('/\-+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function load_book_map() {
        if (self::$book_map !== null) {
            return;
        }
        self::$book_map = TheBible_Mappings_Loader::load_book_map();
        if (!is_array(self::$book_map)) {
            self::$book_map = [];
        }
    }

    private static function load_osis_mapping() {
        if (self::$osis_mapping !== null) {
            return;
        }
        self::$osis_mapping = TheBible_Mappings_Loader::load_osis_mapping();
        if (!is_array(self::$osis_mapping)) {
            self::$osis_mapping = [];
        }
    }

    private static function osis_for_dataset_book_slug($dataset_slug, $dataset_book_slug) {
        self::load_osis_mapping();
        return TheBible_Osis_Utils::osis_for_dataset_book_slug(self::$osis_mapping, $dataset_slug, $dataset_book_slug);
    }

    private static function dataset_book_slug_for_osis($dataset_slug, $osis) {
        self::load_osis_mapping();
        return TheBible_Osis_Utils::dataset_book_slug_for_osis(self::$osis_mapping, $dataset_slug, $osis);
    }

    public static function resolve_book_for_dataset($canonical_key, $dataset_slug) {
        if (!is_string($canonical_key) || $canonical_key === '') {
            return null;
        }
        if (!is_string($dataset_slug) || $dataset_slug === '') {
            return null;
        }
        self::load_book_map();
        if (!is_array(self::$book_map) || empty(self::$book_map)) {
            return null;
        }
        $key = strtolower($canonical_key);
        if (!isset(self::$book_map[$key]) || !is_array(self::$book_map[$key])) {
            return null;
        }
        $entry = self::$book_map[$key];
        if (!isset($entry[$dataset_slug]) || !is_string($entry[$dataset_slug]) || $entry[$dataset_slug] === '') {
            return null;
        }
        return $entry[$dataset_slug];
    }

    private static function url_book_slug_for_dataset($canonical_book_slug, $dataset_slug) {
        $canonical_book_slug = is_string($canonical_book_slug) ? self::slugify($canonical_book_slug) : '';
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        if ($canonical_book_slug === '' || $dataset_slug === '') {
            return '';
        }

        $short = self::resolve_book_for_dataset($canonical_book_slug, $dataset_slug);
        if (!is_string($short) || $short === '') {
            return $canonical_book_slug;
        }

        $s = self::slugify($short);
        return $s !== '' ? $s : $canonical_book_slug;
    }

    private static function canonicalize_key_from_dataset_book_slug($dataset_slug, $dataset_book_slug) {
        self::load_book_map();
        return TheBible_Canonicalization::canonicalize_key_from_dataset_book_slug(self::$book_map, $dataset_slug, $dataset_book_slug);
    }

    public static function list_canonical_books() {
        self::load_book_map();
        if (!is_array(self::$book_map) || empty(self::$book_map)) {
            return [];
        }
        $out = [];
        foreach (self::$book_map as $key => $val) {
            if (!is_string($key) || $key === '') continue;
            $out[] = $key;
        }
        sort($out);
        return $out;
    }

    private static function get_abbreviation_map($slug) {
        if (isset(self::$abbr_maps[$slug])) {
            return self::$abbr_maps[$slug];
        }
        $map = TheBible_Abbreviations_Loader::load_abbreviation_map($slug);
        if (!is_array($map)) {
            $map = [];
        }
        self::$abbr_maps[$slug] = $map;
        return $map;
    }

    public static function pretty_label($short_name) {
        if (!is_string($short_name)) return '';
        $label = $short_name;
        // Convert underscores to spaces by default
        $label = str_replace('_', ' ', $label);
        // Leading numeral becomes 'N. '
        $label = preg_replace('/^(\d+)\s+/', '$1. ', $label);
        // Specific compounds get a slash separator
        $label = preg_replace('/\bKings\s+Samuel\b/', 'Kings / Samuel', $label);
        $label = preg_replace('/\bEsdras\s+Nehemias\b/', 'Esdras / Nehemias', $label);
        // normalize whitespace
        $label = preg_replace('/\s+/', ' ', $label);
        return trim($label);
    }

    public static function register_votd_cpt() {
        $labels = [
            'name'                  => __('Verses of the Day', 'thebible'),
            'singular_name'         => __('Verse of the Day', 'thebible'),
            'add_new'               => __('Add New', 'thebible'),
            'add_new_item'          => __('Add New Verse of the Day', 'thebible'),
            'edit_item'             => __('Edit Verse of the Day', 'thebible'),
            'new_item'              => __('New Verse of the Day', 'thebible'),
            'view_item'             => __('View Verse of the Day', 'thebible'),
            'search_items'          => __('Search Verses of the Day', 'thebible'),
            'not_found'             => __('No verses of the day found.', 'thebible'),
            'not_found_in_trash'    => __('No verses of the day found in Trash.', 'thebible'),
            'all_items'             => __('Verses of the Day', 'thebible'),
            'menu_name'             => __('Verse of the Day', 'thebible'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => false,
            'show_in_admin_bar'  => false,
            'exclude_from_search'=> true,
            'publicly_queryable' => false,
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => [],
            'menu_position'      => null,
        ];

        register_post_type('thebible_votd', $args);
    }

    public static function register_widgets() {
        if (class_exists('WP_Widget')) {
            register_widget('TheBible_VOTD_Widget');
        }
    }

    private static function book_groups() {
        self::load_index();
        $ot = [];
        $nt = [];
        // Detect NT boundary dynamically by first occurrence of Matthew across locales
        $nt_slug_candidates = ['matthew','matthaeus'];
        $nt_start_order = null;
        foreach (self::$books as $b) {
            $slug = self::slugify($b['short_name']);
            if (in_array($slug, $nt_slug_candidates, true)) {
                $nt_start_order = intval($b['order']);
                break;
            }
        }
        foreach (self::$books as $b) {
            if ($nt_start_order !== null) {
                if (intval($b['order']) < $nt_start_order) $ot[] = $b; else $nt[] = $b;
            } else {
                // Fallback to legacy threshold
                if ($b['order'] <= 46) $ot[] = $b; else $nt[] = $b;
            }
        }
        return [$ot, $nt];
    }

    public static function handle_sitemap() {
        $map = get_query_var(self::QV_SITEMAP);
        if (!$map) return;

        $slug = get_query_var(self::QV_SLUG);
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            status_header(404);
            exit;
        }

        self::load_index();
        if (empty(self::$books)) {
            status_header(404);
            exit;
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $base_path = '/' . trim($slug, '/') . '/';
        $domain = rtrim( home_url(), '/' );

        $index_url = $domain . $base_path;
        echo '  <url><loc>' . esc_url($index_url) . '</loc></url>' . "\n";

        foreach (self::$books as $entry) {
            if (!is_array($entry) || empty($entry['short_name'])) continue;
            $book_slug = self::slugify($entry['short_name']);
            if ($book_slug === '') continue;
            // Book URL
            $book_url = $domain . $base_path . $book_slug . '/';
            echo '  <url><loc>' . esc_url($book_url) . '</loc></url>' . "\n";

            // Per-verse URLs: scan the book HTML for verse IDs like slug-CH-V
            $file = self::html_dir() . $entry['filename'];
            if (!file_exists($file)) {
                continue;
            }
            $html = @file_get_contents($file);
            if (!is_string($html) || $html === '') {
                continue;
            }
            $pattern = '/\bid="' . preg_quote($book_slug, '/') . '-(\d+)-(\d+)"/';
            if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                continue;
            }
            $seen = [];
            foreach ($matches as $m) {
                $ch = intval($m[1]);
                $v  = intval($m[2]);
                if ($ch <= 0 || $v <= 0) continue;
                $key = $ch . ':' . $v;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $loc = $domain . $base_path . $book_slug . '/' . $ch . ':' . $v;
                echo '  <url><loc>' . esc_url($loc) . '</loc></url>' . "\n";
            }
        }

        echo '</urlset>';
        exit;
    }

    public static function handle_template_redirect() {
        self::handle_request();
    }

    /**
     * Extract verse text for a given book slug + chapter/range from a dataset HTML file.
     */
    public static function extract_verse_text_from_html($html, $book_slug, $ch, $vf, $vt) {
        if (!is_string($html) || $html === '' || !is_string($book_slug) || $book_slug === '') {
            return '';
        }
        $ch = absint($ch);
        $vf = absint($vf);
        $vt = absint($vt);
        if ($ch <= 0 || $vf <= 0) return '';
        if ($vt <= 0 || $vt < $vf) { $vt = $vf; }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $parts = [];
        for ($i = $vf; $i <= $vt; $i++) {
            $id = $book_slug . '-' . $ch . '-' . $i;
            $nodes = $xp->query('//*[@id="' . $id . '"]');
            if ($nodes && $nodes->length) {
                $p = $nodes->item(0);
                $body = null;
                foreach ($p->getElementsByTagName('span') as $span) {
                    if ($span->hasAttribute('class') && strpos($span->getAttribute('class'), 'verse-body') !== false) { $body = $span; break; }
                }
                $txt = $body ? trim($body->textContent) : trim($p->textContent);
                $txt = self::normalize_whitespace($txt);
                if ($txt !== '') $parts[] = $txt;
            }
        }
        $combined = trim(implode(' ', $parts));
        return self::clean_verse_text_for_output($combined);
    }

    public static function get_book_entry_by_slug($slug) {
        self::load_index();
        $norm = self::slugify($slug);
        if (!is_string($norm) || $norm === '') return null;
        return self::$slug_map[$norm] ?? null;
    }

    public static function extract_verse_text($entry, $ch, $vf, $vt) {
        if (!$entry || !is_array($entry)) return '';
        $file = self::html_dir() . $entry['filename'];
        if (!file_exists($file)) return '';
        $html = file_get_contents($file);
        if (!$html) return '';

        $ch = absint($ch);
        $vf = absint($vf);
        $vt = absint($vt);
        if ($ch <= 0 || $vf <= 0) return '';
        if ($vt <= 0 || $vt < $vf) { $vt = $vf; }

        $book_slug = '';
        if (isset($entry['short_name']) && is_string($entry['short_name'])) {
            $book_slug = self::slugify($entry['short_name']);
        }
        if ($book_slug === '') return '';
        return self::extract_verse_text_from_html($html, $book_slug, $ch, $vf, $vt);
    }

    private static function extract_votd_texts_for_entry($entry) {
        if (!is_array($entry)) return [];
        $out = [];
        $datasets = ['bible', 'bibel'];
        foreach ($datasets as $dataset) {
            $short = self::resolve_book_for_dataset($entry['book_slug'], $dataset);
            if (!is_string($short) || $short === '') {
                continue;
            }
            $index_file = plugin_dir_path(__FILE__) . 'data/' . $dataset . '/html/index.csv';
            if (!file_exists($index_file)) {
                continue;
            }
            $filename = '';
            if (($fh = fopen($index_file, 'r')) !== false) {
                $header = fgetcsv($fh);
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 4) continue;
                    if ((string) $row[1] === (string) $short) {
                        $filename = (string) $row[3];
                        break;
                    }
                }
                fclose($fh);
            }
            if ($filename === '') {
                continue;
            }
            $html_path = plugin_dir_path(__FILE__) . 'data/' . $dataset . '/html/' . $filename;
            if (!file_exists($html_path)) {
                continue;
            }
            $html = (string) file_get_contents($html_path);
            if ($html === '') {
                continue;
            }
            $book_slug = self::slugify($short);
            $txt = self::extract_verse_text_from_html($html, $book_slug, (int) $entry['chapter'], (int) $entry['vfrom'], (int) $entry['vto']);
            if (is_string($txt) && $txt !== '') {
                $out[$dataset] = $txt;
            }
        }
        return $out;
    }

    private static function normalize_whitespace($s) {
        return TheBible_Text_Utils::normalize_whitespace($s);
    }

    /**
     * Public helper for widgets/OG/etc: normalize whitespace and clean quotation marks.
     */
    public static function clean_verse_text_for_output($s, $wrap_outer = false, $qL = '»', $qR = '«') {
        return TheBible_Text_Utils::clean_verse_text_for_output($s, $wrap_outer, $qL, $qR);
    }

    private static function render_index() {
        self::load_index();
        status_header(200);
        nocache_headers();
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $title = ($slug === 'bibel') ? 'Die Bibel' : (($slug === 'latin') ? 'Biblia Sacra' : 'The Bible');
        $content = self::build_index_html();
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        self::output_with_theme($title, $content, 'index');
    }

    private static function extract_chapter_from_html($html, $ch) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        // Find the chapter heading like <h2 id="book-CH">Chapter CH</h2>
        $chapter_node = $xp->query('//h2[contains(@id, "-' . $ch . '")]')->item(0);
        if (!$chapter_node) return null;
        $out = '';
        $node = $chapter_node;
        while ($node) {
            $out .= $doc->saveHTML($node);
            // Stop at next chapter heading or end of parent
            $next = $node->nextSibling;
            if ($next && $next->nodeName === 'h2' && strpos($next->getAttribute('id'), '-' . ($ch + 1)) !== false) {
                break;
            }
            $node = $next;
        }
        return $out;
    }

    private static function render_book($slug) {
        self::load_index();
        // Normalize incoming slug to match index keys (case-insensitive URLs)
        $norm = self::slugify($slug);
        $entry = ($norm !== '' && isset(self::$slug_map[$norm])) ? self::$slug_map[$norm] : null;
        if (!$entry) {
            self::render_404();
            return;
        }
        $file = self::html_dir() . $entry['filename'];
        if (!file_exists($file)) {
            self::render_404();
            return;
        }
        $html = file_get_contents($file);

        // Determine chapter (full-book rendering is disabled; default to chapter 1)
        $ch = absint(get_query_var(self::QV_CHAPTER));
        if ($ch <= 0) {
            $ch = 1;
            set_query_var(self::QV_CHAPTER, $ch);
        }

        // Single-chapter mode: extract only the requested chapter
        $chapter_html = self::extract_chapter_from_html($html, $ch);
        if ($chapter_html === null) {
            self::render_404();
            return;
        }
        $html = $chapter_html;

        // Build highlight/scroll targets from URL like /book/20:2-4 or /book/20
        $targets = [];
        $chapter_scroll_id = null;
        $vf_raw = get_query_var(self::QV_VFROM);
        $vt_raw = get_query_var(self::QV_VTO);
        $book_slug = self::slugify($entry['short_name']);

        $ref = TheBible_Reference::parse_chapter_and_range($ch, $vf_raw, $vt_raw);
        if (is_wp_error($ref)) {
            self::render_404();
            return;
        }

        if (!empty($ref['vf'])) {
            $targets = TheBible_Reference::highlight_ids_for_range($book_slug, $ref['ch'], $ref['vf'], $ref['vt']);
        } else {
            $chapter_scroll_id = TheBible_Reference::chapter_scroll_id($book_slug, $ref['ch']);
        }

        // Inject navigation helpers and optional highlight/scroll behavior
        $human = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : $entry['short_name'];
        $html = self::inject_nav_helpers($html, $targets, $chapter_scroll_id, $human, [
            'book' => $book_slug,
            'chapter' => $ch,
        ]);

        status_header(200);
        nocache_headers();
        $base_title = isset($entry['display_name']) && $entry['display_name'] !== ''
            ? $entry['display_name']
            : self::pretty_label($entry['short_name']);
        $title = $base_title;
        $slug_ctx = get_query_var(self::QV_SLUG);
        if (!is_string($slug_ctx) || $slug_ctx === '') { $slug_ctx = 'bible'; }

        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        if ($ch && $vf) {
            if (!$vt || $vt < $vf) { $vt = $vf; }
            $ref = $base_title . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));
            $snippet = self::extract_verse_text($entry, $ch, $vf, $vt);
            if (is_string($snippet) && $snippet !== '') {
                $snippet = wp_strip_all_tags($snippet);
                $snippet = preg_replace('/\s+/u', ' ', trim($snippet));
                if ($snippet !== '') {
                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                        $max = 80;
                        if (mb_strlen($snippet, 'UTF-8') > $max) {
                            $snippet = mb_substr($snippet, 0, $max, 'UTF-8') . '…';
                        }
                    } else {
                        if (strlen($snippet) > $max) {
                            $snippet = substr($snippet, 0, 80) . '…';
                        }
                    }
                    $title = $ref . ' (»' . $snippet . '«)';
                } else {
                    $title = $ref;
                }
            } else {
                $title = $ref;
            }
        } elseif ($ch) {
            $title = $base_title . ' ' . $ch;
        }
        $content = '<div class="thebible thebible-book">' . $html . '</div>';
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        self::output_with_theme($title, $content, 'book');
    }

    private static function render_404() {
        status_header(404);
        nocache_headers();
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">'
           . '<h1>Not Found</h1>'
           . '<p>The requested book could not be found.</p>'
           . '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    private static function build_index_html() {
        list($ot, $nt) = self::book_groups();
        $base = get_query_var(self::QV_SLUG);
        if (!is_string($base) || $base === '') { $base = 'bible'; }
        $home = home_url('/' . $base . '/');
        $out = '<div class="thebible thebible-index">';
        $out .= '<div class="thebible-groups">';
        $ot_label = ($base === 'bibel') ? 'Altes Testament' : 'Old Testament';
        $nt_label = ($base === 'bibel') ? 'Neues Testament' : 'New Testament';
        $out .= '<section class="thebible-group thebible-ot"><h2>' . esc_html($ot_label) . '</h2><ul>';
        foreach ($ot as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $label = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '<section class="thebible-group thebible-nt"><h2>' . esc_html($nt_label) . '</h2><ul>';
        foreach ($nt as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $label = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    private static function base_slugs() {
        $list = get_option('thebible_slugs', 'bible,bibel,latin');
        if (!is_string($list)) $list = 'bible';
        $parts = array_filter(array_map('trim', explode(',', $list)));
        if (empty($parts)) { $parts = ['bible']; }
        $parts = array_values(array_unique($parts));
        $datasets = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '' || strpos($p, '-') !== false) continue;
            $datasets[] = $p;
        }
        $datasets = array_values(array_unique($datasets));
        $combos = self::build_language_slug_combinations($datasets, 3);
        return array_values(array_unique(array_merge($parts, $combos)));
    }

    private static function is_bible_request() {
        $slug = get_query_var(self::QV_SLUG);
        $book = get_query_var(self::QV_BOOK);
        $flag = get_query_var(self::QV_FLAG);
        if (!empty($flag)) {
            return true;
        }
        if (is_string($slug) && $slug !== '') {
            $slug = trim($slug, "/ ");
            if ($slug === 'bible' || $slug === 'bibel' || $slug === 'latin' || strpos($slug, '-') !== false) {
                return true;
            }
        }
        if (is_string($book) && $book !== '') {
            return true;
        }
        return false;
    }

    private static function build_language_slug_combinations($datasets, $max_len = 3) {
        if (!is_array($datasets) || empty($datasets)) return [];
        $datasets = array_values(array_unique(array_filter(array_map('trim', $datasets))));
        $out = [];
        $n = count($datasets);
        if ($n < 2) return [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $out[] = $datasets[$i] . '-' . $datasets[$j];
            }
        }

        if ($max_len >= 3 && $n >= 3) {
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    for ($k = 0; $k < $n; $k++) {
                        $out[] = $datasets[$i] . '-' . $datasets[$j] . '-' . $datasets[$k];
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function filter_document_title($title) {
        if (!self::is_bible_request()) {
            return $title;
        }
        if (is_string(self::$current_page_title) && self::$current_page_title !== '') {
            return self::$current_page_title;
        }
        return $title;
    }

    public static function filter_document_title_parts($parts) {
        if (!self::is_bible_request()) {
            return $parts;
        }
        if (!is_array($parts)) {
            $parts = [];
        }
        if (is_string(self::$current_page_title) && self::$current_page_title !== '') {
            $parts['title'] = self::$current_page_title;
        }
        return $parts;
    }

    private static function output_with_theme($title, $content_html, $context = '') {
        // Allow theme override templates (e.g., dwtheme/thebible/...).
        // If a template is found, it is responsible for calling get_header/get_footer and echoing content.
        self::$current_page_title = is_string($title) ? $title : '';
        $context = is_string($context) ? $context : '';
        if ( function_exists('locate_template') ) {
            $thebible_title   = $title;        // available to template
            $thebible_content = $content_html; // available to template
            $thebible_context = $context;      // 'index' | 'book'
            $templates = [];
            if ($context === 'book') {
                $templates = [ 'thebible/single-book.php', 'thebible/thebible.php' ];
            } elseif ($context === 'index') {
                $templates = [ 'thebible/index.php', 'thebible/thebible.php' ];
            } else {
                $templates = [ 'thebible/thebible.php' ];
            }
            $found = locate_template( $templates, false, false );
            if ( $found ) {
                // Load the found template within current scope so our variables are available
                require $found;
                return;
            }
        }

        // Fallback: use plugin's built-in wrapper
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">';
        echo '<article class="thebible-article">';
        echo '<header class="entry-header mb-3"><h1 class="entry-title">' . esc_html($title) . '</h1></header>';
        echo '<div class="entry-content">' . $content_html . '</div>';
        echo '</article>';
        echo '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    public static function register_settings() {
        register_setting(
            'thebible_options',
            'thebible_slugs',
            [
                'type'              => 'string',
                'sanitize_callback' => function( $val ) {
                    // If this save does not provide the field (e.g. another settings tab),
                    // keep the existing value instead of resetting slugs.
                    if ( ! isset( $val ) || $val === '' ) {
                        $current = get_option( 'thebible_slugs', 'bible,bibel' );
                        return is_string( $current ) && $current !== '' ? $current : 'bible,bibel';
                    }

                    if ( ! is_string( $val ) ) return 'bible,bibel';
                    // normalize comma-separated list
                    $parts = array_filter( array_map( 'trim', explode( ',', $val ) ) );
                    // only allow known slugs for now
                    $known = [ 'bible', 'bibel' ];
                    $out = [];
                    foreach ( $parts as $p ) { if ( in_array( $p, $known, true ) ) $out[] = $p; }
                    if ( empty( $out ) ) $out = [ 'bible' ];
                    return implode( ',', array_unique( $out ) );
                },
                'default'           => 'bible,bibel',
            ]
        );

        register_setting('thebible_options', 'thebible_autolink_base_url', [
            'type'              => 'string',
            'sanitize_callback' => function ($v) {
                if (!isset($v) || $v === '') {
                    $c = (string) get_option('thebible_autolink_base_url', '');
                    return $c;
                }
                return is_string($v) ? esc_url_raw($v) : '';
            },
            'default'           => '',
        ]);

        register_setting('thebible_options', 'thebible_votd_rss_title', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_votd_rss_title', 'Verse of the Day'); return is_string($v) ? sanitize_text_field($v) : 'Verse of the Day'; }, 'default' => 'Verse of the Day' ]);
        register_setting('thebible_options', 'thebible_votd_rss_lang_first', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = (string) get_option('thebible_votd_rss_lang_first', 'bible'); return $c !== '' ? sanitize_key($c) : 'bible'; } return sanitize_key($v); }, 'default' => 'bible' ]);
        register_setting('thebible_options', 'thebible_votd_rss_lang_last', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_votd_rss_lang_last', ''); return is_string($v) ? sanitize_key($v) : ''; }, 'default' => '' ]);
        register_setting('thebible_options', 'thebible_votd_rss_date_format', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = (string) get_option('thebible_votd_rss_date_format', 'site'); return in_array($c, ['site','de_numeric','ymd'], true) ? $c : 'site'; } return in_array($v, ['site','de_numeric','ymd'], true) ? $v : 'site'; }, 'default' => 'site' ]);
        register_setting('thebible_options', 'thebible_votd_rss_description_tpl', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = (string) get_option('thebible_votd_rss_description_tpl', '{date} — {verse}'); return $c !== '' ? (string) $c : '{date} — {verse}'; } return is_string($v) ? (string) $v : '{date} — {verse}'; }, 'default' => '{date} — {verse}' ]);
        register_setting('thebible_options', 'thebible_votd_rss_days', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_votd_rss_days', 7); $n = absint($v); if ($n < 1) $n = 7; if ($n > 31) $n = 31; return $n; }, 'default' => 7 ]);

        register_setting('thebible_options', 'thebible_og_enabled', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_enabled', '1'); return $c === '0' ? '0' : '1'; } return $v === '0' ? '0' : '1'; }, 'default' => '1' ]);
        register_setting('thebible_options', 'thebible_og_width', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_width', 1200); $n = absint($v); return $n < 100 ? 1200 : $n; }, 'default' => 1200 ]);
        register_setting('thebible_options', 'thebible_og_height', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_height', 630); $n = absint($v); return $n < 100 ? 630 : $n; }, 'default' => 630 ]);
        register_setting('thebible_options', 'thebible_og_bg_color', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_bg_color', '#111111'); return is_string($c) && $c !== '' ? $c : '#111111'; } return is_string($v) ? $v : '#111111'; }, 'default' => '#111111' ]);
        register_setting('thebible_options', 'thebible_og_text_color', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_text_color', '#ffffff'); return is_string($c) && $c !== '' ? $c : '#ffffff'; } return is_string($v) ? $v : '#ffffff'; }, 'default' => '#ffffff' ]);
        register_setting('thebible_options', 'thebible_og_font_ttf', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_font_ttf', ''); return is_string($v) ? $v : ''; }, 'default' => '' ]);
        register_setting('thebible_options', 'thebible_og_font_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_font_url', ''); return is_string($v) ? esc_url_raw($v) : ''; }, 'default' => '' ]);
        // Back-compat size (still read as fallback)
        register_setting('thebible_options', 'thebible_og_font_size', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_font_size', 40); $n = absint($v); return $n < 8 ? 40 : $n; }, 'default' => 40 ]);
        // New: separate sizes for main text and reference
        register_setting('thebible_options', 'thebible_og_font_size_main', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_font_size_main', 40); $n = absint($v); return $n < 8 ? 40 : $n; }, 'default' => 40 ]);
        register_setting('thebible_options', 'thebible_og_font_size_ref', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_font_size_ref', 40); $n = absint($v); return $n < 8 ? 40 : $n; }, 'default' => 40 ]);
        // Minimum main size before truncation kicks in
        register_setting('thebible_options', 'thebible_og_min_font_size_main', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_min_font_size_main', 18); $n = absint($v); return $n < 8 ? 18 : $n; }, 'default' => 18 ]);
        // Layout & spacing
        // Specific paddings (defaults 50px). General padding deprecated.
        register_setting('thebible_options', 'thebible_og_padding_x', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_padding_x', 50); return absint($v); }, 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_padding_top', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_padding_top', 50); return absint($v); }, 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_padding_bottom', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_padding_bottom', 50); return absint($v); }, 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_min_gap', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_min_gap', 16); return absint($v); }, 'default' => 16 ]);
        // Main text line-height (as a factor, e.g., 1.35)
        register_setting('thebible_options', 'thebible_og_line_height_main', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_line_height_main', '1.35'); return is_string($c) && $c !== '' ? $c : '1.35'; } return is_string($v) ? trim($v) : '1.35'; }, 'default' => '1.35' ]);
        // Icon settings
        register_setting('thebible_options', 'thebible_og_icon_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_icon_url', ''); return is_string($v) ? esc_url_raw($v) : ''; }, 'default' => '' ]);
        // Simplified placement: always bottom; choose which side holds the logo; source uses the opposite
        register_setting('thebible_options', 'thebible_og_logo_side', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_logo_side', 'left'); return in_array($c, ['left','right'], true) ? $c : 'left'; } return in_array($v, ['left','right'], true) ? $v : 'left'; }, 'default' => 'left' ]);
        // Padding adjust for logo relative to general padding (can be negative)
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_logo_pad_adjust', 0); return intval($v); }, 'default' => 0 ]); // legacy single-axis
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust_x', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_logo_pad_adjust_x', 0); return intval($v); }, 'default' => 0 ]);
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust_y', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_logo_pad_adjust_y', 0); return intval($v); }, 'default' => 0 ]);
        register_setting('thebible_options', 'thebible_og_icon_max_w', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_icon_max_w', 160); $n = absint($v); return $n < 1 ? 160 : $n; }, 'default' => 160 ]);
        register_setting('thebible_options', 'thebible_og_background_image_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_background_image_url', ''); return is_string($v) ? $v : ''; }, 'default' => '' ]);
        // Quotation marks and reference position
        register_setting('thebible_options', 'thebible_og_quote_left', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_quote_left', '«'); return is_string($c) && $c !== '' ? $c : '«'; } return is_string($v) ? $v : '«'; }, 'default' => '«' ]);
        register_setting('thebible_options', 'thebible_og_quote_right', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_quote_right', '»'); return is_string($c) && $c !== '' ? $c : '»'; } return is_string($v) ? $v : '»'; }, 'default' => '»' ]);
        register_setting('thebible_options', 'thebible_og_ref_position', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_ref_position', 'bottom'); return in_array($c, ['top','bottom'], true) ? $c : 'bottom'; } return in_array($v, ['top','bottom'], true) ? $v : 'bottom'; }, 'default' => 'bottom' ]);
        register_setting('thebible_options', 'thebible_og_ref_align', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_ref_align', 'left'); return in_array($c, ['left','right'], true) ? $c : 'left'; } return in_array($v, ['left','right'], true) ? $v : 'left'; }, 'default' => 'left' ]);
    }

    public static function customize_register( $wp_customize ) {
        if ( ! class_exists('WP_Customize_Control') ) return;
        // Section for The Bible footer appearance
        $wp_customize->add_section('thebible_footer_section', [
            'title'       => __('Bible Footer CSS','thebible'),
            'priority'    => 160,
            'description' => __('Custom CSS applied to the footer area rendered by The Bible plugin (.thebible-footer, .thebible-footer-title).','thebible'),
        ]);
        // Setting: footer-specific CSS
        $wp_customize->add_setting('thebible_footer_css', [
            'type'              => 'option',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => function( $css ) { return is_string($css) ? $css : ''; },
            'default'           => '',
            'transport'         => 'refresh',
        ]);
        // Control: textarea for CSS
        $wp_customize->add_control('thebible_footer_css', [
            'section'  => 'thebible_footer_section',
            'label'    => __('Custom CSS for Bible Footer','thebible'),
            'type'     => 'textarea',
            'settings' => 'thebible_footer_css',
        ]);
    }

    public static function admin_menu() {
        // Top-level menu
        add_menu_page(
            'The Bible',
            'The Bible',
            'manage_options',
            'thebible',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-book-alt',
            58
        );

        // Sub-pages: main settings (default), OG image/layout, and per-Bible footers
        add_submenu_page(
            'thebible',
            'The Bible',
            'The Bible',
            'manage_options',
            'thebible',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'OG Image & Layout',
            'OG Image & Layout',
            'manage_options',
            'thebible_og',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'Footers',
            'Footers',
            'manage_options',
            'thebible_footers',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'Verse Importer',
            'Verse Importer',
            'manage_options',
            'thebible_import',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'Interlinear QA',
            'Interlinear QA',
            'manage_options',
            'thebible_interlinear_qa',
            [ 'TheBible_QA', 'render_interlinear_qa_page' ]
        );

        add_submenu_page(
            'thebible',
            'Sync Status',
            'Sync Status',
            'manage_options',
            'thebible_sync',
            [ 'TheBible_Sync_Report', 'render_sync_status_page' ]
        );
    }

    public static function admin_enqueue($hook) {
        TheBible_Admin_Utils::admin_enqueue($hook);
    }

    public static function allow_font_uploads($mimes) {
        return TheBible_Admin_Utils::allow_font_uploads($mimes);
    }

    public static function allow_font_filetype($data, $file, $filename, $mimes, $real_mime) {
        return TheBible_Admin_Utils::allow_font_filetype($data, $file, $filename, $mimes, $real_mime);
    }

    public static function render_settings_page() {
        TheBible_Admin_Settings::render_settings_page();
    }

    public static function handle_export_bible_txt() {
        TheBible_Admin_Export::handle_export_bible_txt();
    }

    public static function print_custom_css() {
        TheBible_Front_Meta::print_custom_css();
    }

    public static function print_og_meta() {
        TheBible_Front_Meta::print_og_meta();
    }

    private static function render_footer_html() {
        return TheBible_Footer_Renderer::render_footer_html(self::data_root_dir(), self::html_dir());
    }
}

TheBible_Plugin::init();
