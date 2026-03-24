<?php
/*
* Plugin Name: The Bible
* Description: Provides /bible/ with links to books; renders selected book HTML using the site's template.
* Version: 1.26.03.24.04
* Author: Dushan Wegner
*/

if (!defined('ABSPATH')) exit;

if (!defined('THEBIBLE_VERSION')) {
    define('THEBIBLE_VERSION', '1.26.03.24.04');
}

// Load include classes before hooks are registered
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-meta.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-og-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-reference.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-qa.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-sync-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-text-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-export.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-ai.php';
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
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-nav-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-json-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-jsonld.php';
class TheBible_Plugin {
    use TheBible_Interlinear_Trait;
    use TheBible_Router_Trait;
    use TheBible_SelfTest_Trait;
    use TheBible_AutoLink_Trait;
    use TheBible_JSON_API_Trait;
    const QV_FLAG = 'thebible';
    const QV_BOOK = 'thebible_book';
    const QV_CHAPTER = 'thebible_ch';
    const QV_VFROM = 'thebible_vfrom';
    const QV_VTO = 'thebible_vto';
    const QV_SLUG = 'thebible_slug';
    const QV_OG   = 'thebible_og';
    const QV_SITEMAP = 'thebible_sitemap';
    const QV_SELFTEST = 'thebible_selftest';
    const QV_FORMAT   = 'thebible_format';

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
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        // Priority 1: run before redirect_canonical (priority 10) which
        // would otherwise add a trailing slash to .json URLs.
        add_action('template_redirect', [__CLASS__, 'handle_request'], 1);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        // TODO: Delete this one-time VOTD cleanup block after it has run on production
        add_action('admin_init', [__CLASS__, 'one_time_delete_votd_data']);

        add_filter('upload_mimes', [__CLASS__, 'allow_font_uploads']);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'allow_font_filetype'], 10, 5);

        add_action('add_meta_boxes', ['TheBible_Admin_Meta', 'add_bible_meta_box']);
        add_action('save_post', ['TheBible_Admin_Meta', 'save_bible_meta'], 10, 2);

        add_filter('manage_posts_columns', ['TheBible_Admin_Meta', 'add_bible_column']);
        add_action('manage_posts_custom_column', ['TheBible_Admin_Meta', 'render_bible_column'], 10, 2);

        add_filter('the_content', [__CLASS__, 'filter_content_auto_link_bible_refs'], 20);

        add_filter('bulk_actions-edit-post', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('bulk_actions-edit-page', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('handle_bulk_actions-edit-post', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);

        // AI optimization: robots.txt directives for AI crawlers
        add_filter( 'robots_txt', [ __CLASS__, 'filter_robots_txt' ], 100, 2 );

        // AI optimization: JSON-LD structured data on Bible HTML pages
        add_action( 'wp_head', [ 'TheBible_JsonLd', 'print_jsonld' ] );

        // AI optimization: <link rel="alternate"> pointing to JSON on Bible pages
        add_action( 'wp_head', [ __CLASS__, 'print_json_alternate_link' ] );

        // Page-specific <title> for Bible pages (critical for AI crawlers and SEO).
        // Only use document_title_parts (not pre_get_document_title) so WP still
        // appends the site name via the 'site' part.
        add_filter( 'document_title_parts', [ __CLASS__, 'filter_document_title_parts' ], 20 );

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
        self::clear_sitemap_cache();
        update_option('thebible_rewrite_version', THEBIBLE_VERSION);
    }

    /**
     * One-time cleanup: delete all thebible_votd posts, post meta, and related options.
     * TODO: Remove this method (and its hook in init()) after it has run on production.
     */
    public static function one_time_delete_votd_data() {
        if (get_option('thebible_votd_cleanup_done')) {
            return;
        }

        global $wpdb;

        // Delete all VOTD post meta and posts in one sweep
        $post_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'thebible_votd')
        );

        $deleted = 0;
        foreach ($post_ids as $pid) {
            if (wp_delete_post((int) $pid, true)) {
                $deleted++;
            }
        }

        // Clean up VOTD-related options
        delete_option('thebible_votd_by_date');
        delete_option('thebible_votd_all');
        delete_option('thebible_votd_rss_title');
        delete_option('thebible_votd_rss_lang_first');
        delete_option('thebible_votd_rss_lang_last');
        delete_option('thebible_votd_rss_date_format');
        delete_option('thebible_votd_rss_description_tpl');
        delete_option('thebible_votd_rss_days');

        // Mark as done so this never runs again
        update_option('thebible_votd_cleanup_done', '1', false);

        if ($deleted > 0) {
            add_action('admin_notices', function () use ($deleted) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . 'VOTD cleanup: deleted ' . intval($deleted) . ' verse-of-the-day posts and related options.'
                    . '</p></div>';
            });
        }
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
        return TheBible_Nav_Helpers::inject($html, $highlight_ids, $chapter_scroll_id, $book_label, $nav);
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

        // ── JSON API routes (must come before HTML routes for priority) ──
        foreach ($slugs as $slug) {
            $slug = trim($slug, "/ ");
            if ($slug === '') continue;
            $qs = preg_quote($slug, '/');
            // /{slug}/index.json → translation index
            add_rewrite_rule(
                '^' . $qs . '/index\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug,
                'top'
            );
            // /{slug}/{book}/index.json → book index
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/index\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]',
                'top'
            );
            // /{slug}/{book}/{chapter}/{verse}.json → single verse
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+)/([0-9]+)\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]',
                'top'
            );
            // /{slug}/{book}/{chapter}/{from}-{to}.json → verse range
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+)/([0-9]+)-([0-9]+)\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]',
                'top'
            );
            // /{slug}/{book}/{chapter}.json → chapter data
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+)\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]',
                'top'
            );
        }
        // /llms.txt and /llms-full.txt — AI entry-point documents
        add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QV_FORMAT . '=llms&' . self::QV_FLAG . '=1', 'top' );
        add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::QV_FORMAT . '=llms-full&' . self::QV_FLAG . '=1', 'top' );
        // /bible-index.json — unified index: all books × all translations in one fetch
        add_rewrite_rule( '^bible-index\.json$', 'index.php?' . self::QV_FORMAT . '=bible-index&' . self::QV_FLAG . '=1', 'top' );

        // ── HTML routes ─────────────────────────────────────────────────
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
        // Sitemaps: per-book Bible (73 × 3 = 219), prayers, saints, and index
        // Pattern: /bible-sitemap-{slug}-{book}.xml → per-book sitemap
        add_rewrite_rule(
            '^bible-sitemap-(bible|bibel|latin)-([a-z0-9-]+)\.xml$',
            'index.php?' . self::QV_SITEMAP . '=$matches[1]&' . self::QV_SLUG . '=$matches[1]&' . self::QV_BOOK . '=$matches[2]',
            'top'
        );
        add_rewrite_rule('^sitemap-prayers\.xml$', 'index.php?' . self::QV_SITEMAP . '=prayers', 'top');
        add_rewrite_rule('^sitemap-saints\.xml$', 'index.php?' . self::QV_SITEMAP . '=saints', 'top');
        add_rewrite_rule('^sitemap-index\.xml$', 'index.php?' . self::QV_SITEMAP . '=index', 'top');
    }

    public static function enqueue_assets() {
        // Enqueue styles and scripts only on plugin routes
        $is_bible = ! empty( get_query_var( self::QV_FLAG ) )
            || ! empty( get_query_var( self::QV_BOOK ) )
            || ! empty( get_query_var( self::QV_SLUG ) );
        if ( $is_bible ) {
            $base_ver = defined( 'THEBIBLE_VERSION' ) ? (string) THEBIBLE_VERSION : '';

            $css_rel  = 'assets/thebible.css';
            $css_url  = plugins_url( $css_rel, __FILE__ );
            $css_path = plugin_dir_path( __FILE__ ) . $css_rel;
            $css_ver  = $base_ver;
            if ( is_string( $css_path ) && $css_path !== '' && file_exists( $css_path ) ) {
                $css_ver .= '.' . (string) filemtime( $css_path );
            }
            wp_enqueue_style( 'thebible-styles', $css_url, [], $css_ver );

            // Enqueue theme script first (in the head) to prevent flash of unstyled content
            $theme_rel  = 'assets/thebible-theme.js';
            $theme_js_url = plugins_url( $theme_rel, __FILE__ );
            $theme_js_path = plugin_dir_path( __FILE__ ) . $theme_rel;
            $theme_ver = $base_ver;
            if ( is_string( $theme_js_path ) && $theme_js_path !== '' && file_exists( $theme_js_path ) ) {
                $theme_ver .= '.' . (string) filemtime( $theme_js_path );
            }
            wp_enqueue_script( 'thebible-theme', $theme_js_url, [], $theme_ver, false );
            
            // Main frontend script in the footer
            $js_rel  = 'assets/thebible-frontend.js';
            $js_url  = plugins_url( $js_rel, __FILE__ );
            $js_path = plugin_dir_path( __FILE__ ) . $js_rel;
            $js_ver  = $base_ver;
            if ( is_string( $js_path ) && $js_path !== '' && file_exists( $js_path ) ) {
                $js_ver .= '.' . (string) filemtime( $js_path );
            }
            wp_enqueue_script( 'thebible-frontend', $js_url, [], $js_ver, true );
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
        $vars[] = self::QV_FORMAT;
        return $vars;
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

        // Dispatch to the right sitemap generator
        if ($map === 'index') {
            self::handle_sitemap_index();
            exit;
        }
        if ($map === 'prayers') {
            self::handle_sitemap_prayers();
            exit;
        }
        if ($map === 'saints') {
            self::handle_sitemap_saints();
            exit;
        }

        $slug = get_query_var(self::QV_SLUG);
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            status_header(404);
            exit;
        }

        // Per-book sitemap: /bible-sitemap-{slug}-{book}.xml
        $book_qv = get_query_var(self::QV_BOOK);
        if ( empty( $book_qv ) ) {
            status_header(404);
            exit;
        }
        $book_qv = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $book_qv ) );

        self::load_index();
        if (empty(self::$books)) {
            status_header(404);
            exit;
        }

        // Find the requested book in the index
        $entry = null;
        foreach (self::$books as $e) {
            if (!is_array($e) || empty($e['short_name'])) continue;
            if (self::slugify($e['short_name']) === $book_qv) {
                $entry = $e;
                break;
            }
        }
        if (!$entry) {
            status_header(404);
            exit;
        }

        $book_slug = self::slugify($entry['short_name']);
        $base_path = '/' . trim($slug, '/') . '/';
        $domain    = rtrim( home_url(), '/' );

        // Get lastmod from HTML file modification time
        $file    = self::html_dir() . $entry['filename'];
        $lastmod = file_exists($file) ? date('Y-m-d', filemtime($file)) : '';
        $lastmod_tag = $lastmod ? '    <lastmod>' . $lastmod . '</lastmod>' . "\n" : '';

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Book URL
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . esc_url($domain . $base_path . $book_slug . '/') . '</loc>' . "\n";
        $xml .= $lastmod_tag;
        $xml .= '    <priority>0.8</priority>' . "\n";
        $xml .= '  </url>' . "\n";

        // Scan HTML for chapter/verse IDs
        if (file_exists($file)) {
            $html = @file_get_contents($file);
            if (is_string($html) && $html !== '') {
                $pattern = '/\bid="' . preg_quote($book_slug, '/') . '-(\d+)-(\d+)"/';
                if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                    $chapters = [];
                    foreach ($matches as $m) {
                        $ch = intval($m[1]);
                        $v  = intval($m[2]);
                        if ($ch <= 0 || $v <= 0) continue;
                        $chapters[$ch][] = $v;
                    }

                    // Chapter-level entries
                    foreach ($chapters as $ch => $verses) {
                        $xml .= '  <url>' . "\n";
                        $xml .= '    <loc>' . esc_url($domain . $base_path . $book_slug . '/' . $ch) . '</loc>' . "\n";
                        $xml .= $lastmod_tag;
                        $xml .= '    <priority>0.7</priority>' . "\n";
                        $xml .= '  </url>' . "\n";
                    }

                    // Verse-level entries
                    foreach ($chapters as $ch => $verses) {
                        sort($verses);
                        $seen = [];
                        foreach ($verses as $v) {
                            if (isset($seen[$v])) continue;
                            $seen[$v] = true;
                            $xml .= '  <url>' . "\n";
                            $xml .= '    <loc>' . esc_url($domain . $base_path . $book_slug . '/' . $ch . ':' . $v) . '</loc>' . "\n";
                            $xml .= $lastmod_tag;
                            $xml .= '  </url>' . "\n";
                        }
                    }
                }
            }
        }

        $xml .= '</urlset>';

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');
        echo $xml;
        exit;
    }

    /**
     * Sitemap index — references all per-type sitemaps.
     */
    private static function handle_sitemap_index() {
        $domain = rtrim( home_url(), '/' );

        // Load book index to generate per-book sitemap references
        $data_dir = plugin_dir_path(__FILE__) . 'data/';
        $datasets = [ 'bible', 'bibel', 'latin' ];

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Per-book Bible sitemaps (73 books × 3 translations = 219)
        foreach ( $datasets as $ds ) {
            $csv_file = $data_dir . $ds . '/html/index.csv';
            if ( ! file_exists( $csv_file ) ) { continue; }
            $rows = array_map( 'str_getcsv', file( $csv_file ) );
            foreach ( $rows as $row ) {
                if ( empty( $row[1] ) ) { continue; }
                $book_slug = self::slugify( $row[1] );
                if ( $book_slug === '' ) { continue; }
                echo '  <sitemap>' . "\n";
                echo '    <loc>' . esc_url( $domain . '/bible-sitemap-' . $ds . '-' . $book_slug . '.xml' ) . '</loc>' . "\n";
                echo '  </sitemap>' . "\n";
            }
        }

        // Prayers and saints sitemaps
        echo '  <sitemap><loc>' . esc_url( $domain . '/sitemap-prayers.xml' ) . '</loc></sitemap>' . "\n";
        echo '  <sitemap><loc>' . esc_url( $domain . '/sitemap-saints.xml' ) . '</loc></sitemap>' . "\n";

        echo '</sitemapindex>';
        exit;
    }

    /**
     * Prayer sitemap — one entry per published prayer.
     */
    private static function handle_sitemap_prayers() {
        $domain = rtrim( home_url(), '/' );

        // Query published prayers
        $posts = get_posts( [
            'post_type'      => 'dw_prayer',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Prayer index page
        echo '  <url>' . "\n";
        echo '    <loc>' . esc_url( $domain . '/prayers/' ) . '</loc>' . "\n";
        echo '    <priority>0.9</priority>' . "\n";
        echo '  </url>' . "\n";

        foreach ( $posts as $post ) {
            $url     = get_permalink( $post );
            $lastmod = date( 'Y-m-d', strtotime( $post->post_modified_gmt ) );
            echo '  <url>' . "\n";
            echo '    <loc>' . esc_url( $url ) . '</loc>' . "\n";
            echo '    <lastmod>' . $lastmod . '</lastmod>' . "\n";
            echo '    <priority>0.7</priority>' . "\n";
            echo '  </url>' . "\n";
        }

        echo '</urlset>';
        exit;
    }

    /**
     * Saint sitemap — one entry per published saint.
     */
    private static function handle_sitemap_saints() {
        $domain = rtrim( home_url(), '/' );

        // Query published saints
        $posts = get_posts( [
            'post_type'      => 'dw_saint',
            'post_status'    => 'publish',
            'posts_per_page' => 1000,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600'); // saints change more often

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Saint archive page
        echo '  <url>' . "\n";
        echo '    <loc>' . esc_url( $domain . '/saints/' ) . '</loc>' . "\n";
        echo '    <priority>0.9</priority>' . "\n";
        echo '  </url>' . "\n";

        foreach ( $posts as $post ) {
            $url     = get_permalink( $post );
            $lastmod = date( 'Y-m-d', strtotime( $post->post_modified_gmt ) );
            echo '  <url>' . "\n";
            echo '    <loc>' . esc_url( $url ) . '</loc>' . "\n";
            echo '    <lastmod>' . $lastmod . '</lastmod>' . "\n";
            echo '    <priority>0.7</priority>' . "\n";
            echo '  </url>' . "\n";
        }

        echo '</urlset>';
        exit;
    }

    /**
     * Delete cached Bible sitemap XML files.
     * Called on version change and available from the AI admin page.
     */
    public static function clear_sitemap_cache() {
        $cache_dir = plugin_dir_path(__FILE__) . 'data/cache/';
        if ( ! is_dir( $cache_dir ) ) { return; }
        $files = glob( $cache_dir . 'sitemap-*.xml' );
        if ( $files ) {
            foreach ( $files as $f ) { @unlink( $f ); }
        }
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
        $content = self::build_index_html();
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        self::output_with_theme('The Bible', $content, 'index');
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
        // Insert bottom prev/next nav just before the language switcher (if present)
        if (TheBible_Nav_Helpers::$last_nav_ctx) {
            $bottom_nav = TheBible_Nav_Helpers::build_bottom_nav(TheBible_Nav_Helpers::$last_nav_ctx);
            $switcher_pos = strpos($html, '<div class="thebible-language-switcher"');
            if ($switcher_pos !== false) {
                $html = substr_replace($html, $bottom_nav, $switcher_pos, 0);
            } else {
                $html .= $bottom_nav;
            }
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

    /**
     * Book category definitions — order ranges and English labels.
     */
    private static function book_categories() {
        return [
            ['range' => [1, 5],   'testament' => 'ot', 'label' => 'Pentateuch'],
            ['range' => [6, 19],  'testament' => 'ot', 'label' => 'Historical Books'],
            ['range' => [20, 26], 'testament' => 'ot', 'label' => 'Wisdom Books'],
            ['range' => [27, 46], 'testament' => 'ot', 'label' => 'Prophets'],
            ['range' => [47, 50], 'testament' => 'nt', 'label' => 'Gospels'],
            ['range' => [51, 65], 'testament' => 'nt', 'label' => 'Acts & Letters'],
            ['range' => [66, 73], 'testament' => 'nt', 'label' => 'Catholic Epistles & Apocalypse'],
        ];
    }

    /**
     * Load index.csv for a specific dataset (bible, bibel, latin).
     */
    private static function load_dataset_index($dataset) {
        $csv = plugin_dir_path(__FILE__) . 'data/' . $dataset . '/html/index.csv';
        $parsed = TheBible_Index_Loader::load_index($csv);
        return is_array($parsed) && isset($parsed['books']) ? $parsed['books'] : [];
    }

    /**
     * Build the Bible homepage — categorized tile grid, bilingual (Douay-Rheims + modern).
     *
     * Shows the Douay-Rheims (bible) name as primary and the modern (latin dataset)
     * name as a subtitle when the two differ. Links go to /bible/<slug>/.
     */
    private static function build_index_html() {
        $categories = self::book_categories();

        // Determine which dataset we're on
        $current_slug = get_query_var(self::QV_SLUG);
        if ( ! is_string($current_slug) || $current_slug === '' ) {
            $current_slug = 'bible';
        }
        // For interlinear combos (e.g. bible-bibel), use the first dataset
        $primary_dataset = $current_slug;
        if ( strpos($primary_dataset, '-') !== false ) {
            $parts = explode('-', $primary_dataset);
            $primary_dataset = $parts[0];
        }

        // Load the primary dataset for this index page (determines slugs + display names)
        $primary_books = self::load_dataset_index($primary_dataset);

        // Load a secondary dataset for subtitle names (only when different)
        $secondary_dataset = ($primary_dataset === 'bible') ? 'latin' : 'bible';
        $secondary_books   = self::load_dataset_index($secondary_dataset);
        $secondary_names   = [];
        foreach ($secondary_books as $b) {
            $display = !empty($b['display_name']) ? $b['display_name'] : $b['short_name'];
            $secondary_names[intval($b['order'])] = $display;
        }

        $base_url = home_url('/' . $current_slug . '/');
        $out = '<div class="thebible thebible-index">';
        $out .= '<h1 class="thebible-index-title">The Bible</h1>';

        // Cross-links to all three translation indexes (HTML + JSON)
        $all_datasets = [
            'latin' => 'Latin (Clementine Vulgate)',
            'bible' => 'English (Douay-Rheims)',
            'bibel' => 'German (Menge)',
        ];
        $cross_links = [];
        foreach ($all_datasets as $ds => $label) {
            if ($ds === $primary_dataset) {
                $cross_links[] = '<strong>' . esc_html($label) . '</strong>';
            } else {
                $cross_links[] = '<a href="' . esc_url(home_url('/' . $ds . '/')) . '">' . esc_html($label) . '</a>';
            }
        }
        $out .= '<nav class="thebible-translation-nav" aria-label="Bible translations">';
        $out .= implode(' · ', $cross_links);
        $out .= '</nav>';

        $prev_testament = '';
        foreach ($categories as $cat) {
            // OT/NT divider
            if ($cat['testament'] !== $prev_testament && $prev_testament !== '') {
                $out .= '<hr class="thebible-testament-divider">';
            }
            $prev_testament = $cat['testament'];

            $out .= '<section class="thebible-category">';
            $out .= '<h3 class="thebible-category-label">' . esc_html($cat['label']) . '</h3>';
            $out .= '<div class="thebible-tiles">';

            foreach ($primary_books as $b) {
                $order = intval($b['order']);
                if ($order < $cat['range'][0] || $order > $cat['range'][1]) {
                    continue;
                }
                // Slug and display name from the primary dataset
                $book_slug = self::slugify($b['short_name']);
                $name      = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
                $alt_name  = isset($secondary_names[$order]) ? $secondary_names[$order] : '';
                $url       = trailingslashit($base_url) . $book_slug . '/';

                // Build tile with both names separated for AI text extraction
                $label = $name;
                if ($alt_name !== '' && $alt_name !== $name) {
                    $label .= ' / ' . $alt_name;
                }
                $out .= '<a href="' . esc_url($url) . '" class="thebible-tile" aria-label="' . esc_attr($label) . '">';
                $out .= '<span class="thebible-tile-name">' . esc_html($name) . '</span>';
                if ($alt_name !== '' && $alt_name !== $name) {
                    $out .= '<span class="thebible-tile-sep" aria-hidden="true"> / </span>';
                    $out .= '<span class="thebible-tile-alt">' . esc_html($alt_name) . '</span>';
                }
                $out .= '</a>';
            }

            $out .= '</div>';
            $out .= '</section>';
        }

        // Hidden AI discovery hints — invisible visually but readable by AI agents
        // that strip <head> tags (e.g. ChatGPT, Perplexity) during page fetches.
        $site_url = home_url();
        $out .= '<div class="thebible-ai-hints" style="display:none" aria-hidden="true">';
        $out .= 'Machine-readable data available: ';
        $out .= 'API documentation: ' . $site_url . '/llms.txt — ';
        $out .= 'This page as JSON: ' . $site_url . '/' . $current_slug . '/index.json — ';
        $out .= 'All books in all 3 translations (one fetch): ' . $site_url . '/bible-index.json — ';
        $out .= 'Prayers: ' . $site_url . '/prayers/index.json — ';
        $out .= 'Saints: ' . $site_url . '/saints/index.json';
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

    public static function filter_document_title($title) {
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
            // Append translation name for AI disambiguation
            // e.g. "Genesis 1" → "Genesis 1 (Douay-Rheims)"
            $slug = get_query_var(self::QV_SLUG);
            $translations = [
                'bible' => 'Douay-Rheims',
                'bibel' => 'Menge',
                'latin' => 'Vulgate',
            ];
            $suffix = '';
            if (is_string($slug) && isset($translations[$slug])) {
                $suffix = ' (' . $translations[$slug] . ')';
            }
            $parts['title'] = self::$current_page_title . $suffix;
            // Remove site name — the translation name is the identifier,
            // not the site. AI crawlers should see "John 3:16 (Douay-Rheims)".
            unset($parts['site']);
            unset($parts['tagline']);
        }
        return $parts;
    }

    /**
     * Append AI-friendly directives to the WordPress virtual robots.txt.
     *
     * Explicitly allows major AI crawlers and references Bible sitemaps
     * so AI agents can discover all available Bible content.
     */
    public static function filter_robots_txt( $output, $public ) {
        $site_url = site_url();

        $output .= "\n";
        $output .= "# ── AI Crawlers Welcome ────────────────────────────\n";
        $output .= "# All content is public domain. See /llms.txt for API docs.\n";
        $output .= "\n";

        // Retrieval bots (cite content in AI answers — always allow)
        $retrieval_bots = [
            'ChatGPT-User',      // OpenAI: user-requested page fetch
            'OAI-SearchBot',     // OpenAI: ChatGPT search results
            'Claude-User',       // Anthropic: user-requested fetch
            'Claude-SearchBot',  // Anthropic: search indexing
            'PerplexityBot',     // Perplexity: indexing
            'Perplexity-User',   // Perplexity: user retrieval
            'DuckAssistBot',     // DuckDuckGo AI
            'Applebot-Extended', // Siri / Apple Intelligence
            'Amazonbot',         // Amazon Alexa
        ];
        // Training bots (content enters model weights — allow for visibility)
        $training_bots = [
            'GPTBot',            // OpenAI model training
            'ClaudeBot',         // Anthropic model training
            'Google-Extended',   // Gemini training
            'GoogleOther',       // Google non-search crawling
            'anthropic-ai',      // Anthropic legacy
            'cohere-ai',         // Cohere models
            'meta-externalagent', // Meta AI
            'CCBot',             // Common Crawl (used by many AI trainers)
        ];

        $output .= "# AI retrieval bots (cite content in AI answers)\n";
        foreach ( $retrieval_bots as $bot ) {
            $output .= "User-agent: {$bot}\nAllow: /\n\n";
        }
        $output .= "# AI training bots (content enters model weights)\n";
        foreach ( $training_bots as $bot ) {
            $output .= "User-agent: {$bot}\nAllow: /\n\n";
        }

        $output .= "# ── Sitemaps ───────────────────────────────────────\n";
        $output .= "# Index references 219 per-book Bible sitemaps + prayers + saints\n";
        $output .= "Sitemap: {$site_url}/sitemap-index.xml\n";
        $output .= "Sitemap: {$site_url}/sitemap-prayers.xml\n";
        $output .= "Sitemap: {$site_url}/sitemap-saints.xml\n";

        return $output;
    }

    /**
     * Output <link rel="alternate"> pointing to the JSON version of the
     * current Bible page, so AI agents know structured data is available.
     */
    public static function print_json_alternate_link() {
        // Point AI agents to the machine-readable site documentation (all pages)
        echo '<link rel="help" type="text/plain" href="' . esc_url( home_url( '/llms.txt' ) ) . '" title="LLM documentation" />' . "\n";

        if ( ! self::is_bible_request() ) {
            return;
        }
        $slug = get_query_var( self::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) { $slug = 'bible'; }
        $book    = get_query_var( self::QV_BOOK );
        $chapter = get_query_var( self::QV_CHAPTER );

        // Build the JSON URL
        if ( ! empty( $book ) && ! empty( $chapter ) ) {
            $json_url = home_url( "/{$slug}/{$book}/{$chapter}.json" );
        } elseif ( ! empty( $book ) ) {
            $json_url = home_url( "/{$slug}/{$book}/index.json" );
        } else {
            $json_url = home_url( "/{$slug}/index.json" );
        }

        echo '<link rel="alternate" type="application/json" href="' . esc_url( $json_url ) . '" />' . "\n";
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
        // --- Special settings with custom sanitizers ---

        register_setting('thebible_options', 'thebible_slugs', [
            'type'              => 'string',
            'sanitize_callback' => function($val) {
                // Keep existing value when field not submitted (e.g. another settings tab)
                if (!isset($val) || $val === '') {
                    $current = get_option('thebible_slugs', 'bible,bibel');
                    return is_string($current) && $current !== '' ? $current : 'bible,bibel';
                }
                if (!is_string($val)) return 'bible,bibel';
                $parts = array_filter(array_map('trim', explode(',', $val)));
                $known = ['bible', 'bibel'];
                $out = [];
                foreach ($parts as $p) { if (in_array($p, $known, true)) $out[] = $p; }
                if (empty($out)) $out = ['bible'];
                return implode(',', array_unique($out));
            },
            'default' => 'bible,bibel',
        ]);

        register_setting('thebible_options', 'thebible_autolink_base_url', [
            'type'              => 'string',
            'sanitize_callback' => function($v) {
                if (!isset($v)) return (string) get_option('thebible_autolink_base_url', '');
                if (!is_string($v) || $v === '') return '';
                return esc_url_raw($v);
            },
            'default' => '',
        ]);

        register_setting('thebible_options', 'thebible_autolink_latin_first', [
            'type'              => 'string',
            'sanitize_callback' => function($v) { return ($v === '1') ? '1' : '0'; },
            'default'           => '0',
        ]);

        // --- Data-driven settings (OG Image) ---
        // Each: [option, wp_type, san_type, default, extra, null_only]
        //   san_type: string|text|key|url|toggle|enum|int|int_min|int_range|int_signed
        //   extra:    enum→[allowed], int_min→minimum, int_range→[min,max]
        //   null_only: true = only !isset triggers preserve (empty string is valid input)
        foreach (self::setting_field_definitions() as $def) {
            $option    = $def[0];
            $wp_type   = $def[1];
            $san_type  = $def[2];
            $default   = $def[3];
            $extra     = isset($def[4]) ? $def[4] : null;
            $null_only = !empty($def[5]);
            register_setting('thebible_options', $option, [
                'type'              => $wp_type,
                'sanitize_callback' => self::build_setting_sanitizer($option, $san_type, $default, $extra, $null_only),
                'default'           => $default,
            ]);
        }
    }

    /**
     * Setting field definitions for the data-driven register_settings() loop.
     *
     * Each entry: [option_name, wp_type, sanitize_type, default, extra, null_only]
     *
     * @return array[]
     */
    private static function setting_field_definitions() {
        return [
            // OG: general
            ['thebible_og_enabled',               'string',  'toggle',     '1'],
            ['thebible_og_width',                 'integer', 'int_min',    1200,               100],
            ['thebible_og_height',                'integer', 'int_min',    630,                100],
            ['thebible_og_bg_color',              'string',  'string',     '#111111'],
            ['thebible_og_text_color',            'string',  'string',     '#ffffff'],
            ['thebible_og_font_ttf',              'string',  'string',     '',                 null, true],
            ['thebible_og_font_url',              'string',  'url',        '',                 null, true],
            ['thebible_og_font_size',             'integer', 'int_min',    40,                 8],   // back-compat fallback
            ['thebible_og_font_size_main',        'integer', 'int_min',    40,                 8],
            ['thebible_og_font_size_ref',         'integer', 'int_min',    40,                 8],
            ['thebible_og_min_font_size_main',    'integer', 'int_min',    18,                 8],
            // OG: layout & spacing
            ['thebible_og_padding_x',             'integer', 'int',        50],
            ['thebible_og_padding_top',           'integer', 'int',        50],
            ['thebible_og_padding_bottom',        'integer', 'int',        50],
            ['thebible_og_min_gap',               'integer', 'int',        16],
            ['thebible_og_line_height_main',      'string',  'string',     '1.35'],
            // OG: icon & logo
            ['thebible_og_icon_url',              'string',  'url',        '',                 null, true],
            ['thebible_og_logo_side',             'string',  'enum',       'left',             ['left', 'right']],
            ['thebible_og_logo_pad_adjust',       'integer', 'int_signed', 0],   // legacy single-axis
            ['thebible_og_logo_pad_adjust_x',     'integer', 'int_signed', 0],
            ['thebible_og_logo_pad_adjust_y',     'integer', 'int_signed', 0],
            ['thebible_og_icon_max_w',            'integer', 'int_min',    160,                1],
            ['thebible_og_background_image_url',  'string',  'string',     '',                 null, true],
            // OG: quotation marks & reference
            ['thebible_og_quote_left',            'string',  'string',     '«'],
            ['thebible_og_quote_right',           'string',  'string',     '»'],
            ['thebible_og_ref_position',          'string',  'enum',       'bottom',           ['top', 'bottom']],
            ['thebible_og_ref_align',             'string',  'enum',       'left',             ['left', 'right']],
        ];
    }

    /**
     * Build a sanitize_callback closure for a data-driven setting.
     *
     * All sanitizers preserve the existing option value when the field
     * was not submitted (null). When $null_only is false, empty strings
     * also trigger preservation (for fields where '' is not valid input).
     *
     * @param string $option    Option name.
     * @param string $san_type  Sanitizer type (string|text|key|url|toggle|enum|int|int_min|int_range|int_signed).
     * @param mixed  $default   Default value.
     * @param mixed  $extra     Type-specific: enum→[allowed], int_min→minimum, int_range→[min,max].
     * @param bool   $null_only If true, only null triggers preserve (empty string is valid input).
     * @return callable
     */
    private static function build_setting_sanitizer($option, $san_type, $default, $extra, $null_only) {
        return function($v) use ($option, $san_type, $default, $extra, $null_only) {
            // Preserve existing value when field was not submitted
            $missing = !isset($v) || (!$null_only && $v === '');
            if ($missing) {
                $existing = get_option($option, $default);
                // Re-validate existing value for constrained types
                if ($san_type === 'toggle') return $existing === '0' ? '0' : '1';
                if ($san_type === 'enum')   return in_array($existing, $extra, true) ? $existing : $default;
                return is_int($default) ? (int) $existing : (string) $existing;
            }

            // Sanitize submitted value
            switch ($san_type) {
                case 'string':     return is_string($v) ? $v : (string) $default;
                case 'text':       return is_string($v) ? sanitize_text_field($v) : (string) $default;
                case 'key':        return sanitize_key($v);
                case 'url':        return is_string($v) ? esc_url_raw($v) : (string) $default;
                case 'toggle':     return $v === '0' ? '0' : '1';
                case 'enum':       return in_array($v, $extra, true) ? $v : (string) $default;
                case 'int':        return absint($v);
                case 'int_min':    $n = absint($v); return $n < $extra ? $default : $n;
                case 'int_range':  $n = absint($v); return ($n < $extra[0]) ? $default : min($n, $extra[1]);
                case 'int_signed': return intval($v);
                default:           return $v;
            }
        };
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
            'LP Bible',
            'LP Bible',
            'manage_options',
            'thebible',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-book-alt',
            36
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

        add_submenu_page(
            'thebible',
            'AI Accessibility',
            'AI Accessibility',
            'manage_options',
            'thebible_ai',
            [ 'TheBible_Admin_AI', 'render_page' ]
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
