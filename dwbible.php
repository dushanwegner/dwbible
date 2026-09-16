<?php
/*
* Plugin Name: DW Bible
* Description: Provides /bible/ with links to books; renders selected book HTML using the site's template. Six languages: Vulgate (la), Douay-Rheims (en), Menge (de), Scío de San Miguel (es), Crampon (fr), Martini (it).
* Version: 1.26.09.16.16
* Author: Dushan Wegner
*/

if (!defined('ABSPATH')) exit;

if (!defined('DWBIBLE_VERSION')) {
    define('DWBIBLE_VERSION', '1.26.09.16.16');
}

// Load include classes before hooks are registered
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-admin-meta.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-og-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-qr-image.php';

/**
 * Tell dwcache that our image parameters change WHICH RESPONSE this is.
 *
 * dwcache builds its cache key from the URL with every query parameter outside
 * an allow-list stripped, so without this `/verse/?dwbible_qr=1` and `/verse/`
 * are the same key — and that breaks in both directions at once:
 *
 *   - the image gets STORED as the verse page (one download replaces the page
 *     with a picture for everybody), and
 *   - once the verse page is cached, a request for the image is SERVED that
 *     cached page instead, because dwcache answers at template_redirect:-9999,
 *     long before this plugin's router at :1.
 *
 * DONOTCACHEPAGE in the image handlers fixes only the first half — it stops the
 * write, not the read. Making these parameters part of the key is what
 * separates the two responses, and both halves are needed.
 *
 * Both failures were live on 2026-08-13: a scanned QR code landed on the verse
 * URL and was served the QR image back.
 */
add_filter('dwcache_allowed_query_params', function ($params, $url = '') {
    // The agent API's parameters change WHICH RESPONSE this is, exactly as the
    // image ones do: /bible-ref.json?q=Gal+3:28&lang=la and …&lang=all are two
    // answers, and ?typography=clean is a third. Measured live on 2026-09-16,
    // minutes after deploy: dwcache answered the &lang=la&typography=clean
    // request with the first requester's la+en/source body (x-dwcache: HIT).
    // Scoped to the .json routes so the five common names do not widen the key
    // of every HTML page on the site.
    if (is_string($url) && preg_match('#\.json(\?|$)#', $url)) {
        // EVERY parameter that changes WHICH answer this is belongs here, in the
        // same commit that introduces it. `offset` was added to the search in
        // 1.26.09.16.11 and not added here: dwcache stripped it from the key and
        // served the cached page ONE for every page, so an agent walking a
        // result set would have seen the same hits over and over while being
        // told thousands more remained. A cache-buster does not reveal it
        // either — an unknown parameter is stripped from the key as well.
        $params = array_merge((array) $params, array('lang', 'typography', 'numbering', 'book', 'limit', 'offset'));
    }
    return array_merge((array) $params, array(
        'dwbible_qr', 'dwbible_qr_download', 'dwbible_qr_range',
        'dwbible_og', 'dwbible_og_download', 'dwbible_og_nocache',
        // `q` — a typed Bible search (the drawer's Bible row). The SAME two
        // failures apply to it, and it was live for minutes on 2026-08-28:
        // `/biblia/?q=zzzz` was stored as the index, so every visitor got the
        // book list with someone else's query in the filter. In the key it is
        // its own response; and because a lookup is never worth storing (the
        // values are unbounded — a crawler could fill the disk with them), the
        // router also marks the request DONOTCACHEPAGE, which stops the write.
        // Key = the read half, DONOTCACHEPAGE = the write half. Both needed.
        'q',
    ));
}, 10, 2);
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-reference.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-qa.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-sync-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-text-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-admin-utils.php';
// The three admin screens, loaded only where they can be reached: their only
// callers are add_menu_page/add_submenu_page callbacks and one admin_post
// handler, all of which run with is_admin() true (admin-post.php included).
//
// class-dwbible-admin-utils.php above is NOT gated with them: it backs the
// upload_mimes and wp_check_filetype_and_ext filters, which fire wherever a
// file is uploaded — REST media uploads from the block editor included.
// Likewise class-dwbible-admin-meta.php, hooked to save_post.
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-admin-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-admin-export.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-admin-ai.php';
}
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-front-meta.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-footer-renderer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-data-paths.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-index-loader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-mappings-loader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-osis-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-canonicalization.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-abbreviations-loader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-render-interlinear.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-render-book-toc.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-router.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-selftest.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-autolink.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-nav-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-menu-search.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-json-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-agent-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dwbible-jsonld.php';
require_once plugin_dir_path(__FILE__) . 'includes/dwbible-i18n.php';
/**
 * Return the absolute path to the Bible data root directory.
 * Checks dwbibledata plugin first, falls back to dwbible/data/.
 */
function dwbible_data_dir(): string {
    if (defined('DWBIBLEDATA_DIR')) {
        $dir = DWBIBLEDATA_DIR . 'data/';
        if (is_dir($dir)) return $dir;
    }
    // Fallback: data/ inside this plugin (pre-split layout)
    return plugin_dir_path(__FILE__) . 'data/';
}

class DwBible_Plugin {
    use DwBible_Interlinear_Trait;
    use DwBible_Book_TOC_Trait;
    use DwBible_Router_Trait;
    use DwBible_SelfTest_Trait;
    use DwBible_AutoLink_Trait;
    use DwBible_JSON_API_Trait;
    use DwBible_Agent_API_Trait;
    const QV_FLAG = 'dwbible';
    const QV_BOOK = 'dwbible_book';
    const QV_CHAPTER = 'dwbible_ch';
    const QV_VFROM = 'dwbible_vfrom';
    const QV_VTO = 'dwbible_vto';
    const QV_SLUG = 'dwbible_slug';
    const QV_OG   = 'dwbible_og';
    /** Serves the verse's pryr.es QR code — see DwBible_QR_Image. */
    const QV_QR   = 'dwbible_qr';
    /** The canonical Bible SECTION slug in every language's URL — Latin, "Latin Prayer":
     *  /{lang}/biblia/{latin-book}/{ch}:{v}. Not a dataset; a route-only virtual slug. */
    const CANONICAL_SECTION = 'biblia';
    const QV_SITEMAP = 'dwbible_sitemap';
    const QV_SELFTEST = 'dwbible_selftest';
    const QV_FORMAT   = 'dwbible_format';

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


        // The Latin-only surface is deliberately unlisted — same note.
        add_action('wp_head', [__CLASS__, 'noindex_latin_only'], 1);

        // Bible text is static — keep dwcache entries indefinitely (flush manually on data updates).
        add_filter( 'dwcache_ttl', [__CLASS__, 'infinite_ttl_for_bible'], 10, 2 );

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        // The settings page renders a form posting this action to admin-post.php.
        // Nothing hooked it, so the "Download .txt" button answered 400 and the
        // feature was unreachable while still being advertised in the UI.
        add_action('admin_post_dwbible_export_bible', ['DwBible_Admin_Export', 'handle_export_bible_txt']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_filter('upload_mimes', [__CLASS__, 'allow_font_uploads']);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'allow_font_filetype'], 10, 5);

        add_action('add_meta_boxes', ['DwBible_Admin_Meta', 'add_bible_meta_box']);
        add_action('save_post', ['DwBible_Admin_Meta', 'save_bible_meta'], 10, 2);

        add_filter('manage_posts_columns', ['DwBible_Admin_Meta', 'add_bible_column']);
        add_action('manage_posts_custom_column', ['DwBible_Admin_Meta', 'render_bible_column'], 10, 2);

        add_filter('the_content', [__CLASS__, 'filter_content_auto_link_bible_refs'], 20);

        // Verse-preview modal: printed in the footer only on pages where the
        // autolinker linked a reference (it sets $did_link). Self-contained.
        add_action('wp_footer', [__CLASS__, 'print_modal_assets']);

        add_filter('bulk_actions-edit-post', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('bulk_actions-edit-page', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('handle_bulk_actions-edit-post', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);

        // AI optimization: robots.txt directives for AI crawlers
        add_filter( 'robots_txt', [ __CLASS__, 'filter_robots_txt' ], 100, 2 );

        // AI optimization: contribute the Bible/Prayers/Saints API documentation
        // to the site llms.txt (dwtheme owns /llms.txt and applies this filter).
        add_filter( 'dwtheme_llms_sections', [ __CLASS__, 'add_llms_api_section' ], 10, 2 );

        // AI optimization: JSON-LD structured data on Bible HTML pages
        add_action( 'wp_head', [ 'DwBible_JsonLd', 'print_jsonld' ] );

        // AI optimization: <link rel="alternate"> pointing to JSON on Bible pages
        add_action( 'wp_head', [ __CLASS__, 'print_json_alternate_link' ] );

        // Social cards: a Bible page's og:description is the passage (or what the
        // chapter/book is), not the site tagline. dwsocial's Site Card asks the
        // page for its facts through this filter.
        add_filter( 'dwsocial_site_card_page_facts', [ __CLASS__, 'site_card_page_facts' ] );

        // Page-specific <title> for Bible pages (critical for AI crawlers and SEO).
        // Only use document_title_parts (not pre_get_document_title) so WP still
        // appends the site name via the 'site' part.
        add_filter( 'document_title_parts', [ __CLASS__, 'filter_document_title_parts' ], 20 );

        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function maybe_flush_rewrite_rules() {
        $stored = get_option('dwbible_rewrite_version', '');
        if (!is_string($stored)) {
            $stored = '';
        }
        if ($stored === DWBIBLE_VERSION) {
            return;
        }

        // Reconcile the slugs option with the current canonical list before
        // re-registering routes. Sites first activated when only en/de
        // shipped have 'bible,bibel' or 'bible,bibel,latin' stored; this
        // adds spanish + french + italian in place so /latin-spanish/,
        // /latin-french/, /latin-italian/ start resolving without a manual
        // `wp option update`.
        self::reconcile_slugs_option();

        self::add_rewrite_rules();
        flush_rewrite_rules(false);
        self::clear_sitemap_cache();
        update_option('dwbible_rewrite_version', DWBIBLE_VERSION);
    }

    /**
     * Ensure the dwbible_slugs option contains every shipped single-language
     * dataset. Existing entries are preserved (and their order honoured by
     * base_slugs() / build_language_slug_combinations()), missing entries
     * are appended. Idempotent; cheap.
     */
    private static function reconcile_slugs_option() {
        $canonical = ['bible', 'bibel', 'latin', 'spanish', 'french', 'italian'];
        $current = get_option('dwbible_slugs', implode(',', $canonical));
        $current = is_string($current) ? $current : implode(',', $canonical);
        $parts = array_values(array_filter(array_map('trim', explode(',', $current))));
        $added = false;
        foreach ($canonical as $slug) {
            if (!in_array($slug, $parts, true)) {
                $parts[] = $slug;
                $added = true;
            }
        }
        if ($added) {
            update_option('dwbible_slugs', implode(',', array_values(array_unique($parts))));
        }
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

    private static function inject_nav_helpers($html, $highlight_ids = [], $chapter_scroll_id = null, $book_label = '', $nav = null, $lang_switcher = '', $book_subtitle = '') {
        return DwBible_Nav_Helpers::inject($html, $highlight_ids, $chapter_scroll_id, $book_label, $nav, $lang_switcher, $book_subtitle);
    }

    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
        // Clean up legacy options no longer used by the plugin.
        delete_option( 'dwbible_custom_css' );
        delete_option( 'dwbible_prod_domain' );
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
            // /{slug}/{book}/{chapter}/{verse}.json → single verse (slash form)
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+)/([0-9]+)\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]',
                'top'
            );
            // /{slug}/{book}/{chapter}/{from}-{to}.json → verse range (slash form)
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+)/([0-9]+)-([0-9]+)\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]',
                'top'
            );
            // /{slug}/{book}/{chapter}:{verse}.json → single verse (colon form)
            // The HTML pages use the colon form as their canonical URL, so AI
            // agents that simply append ".json" to a citation URL must succeed.
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+):([0-9]+)\.json$',
                'index.php?' . self::QV_FORMAT . '=json&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug . '&' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]',
                'top'
            );
            // /{slug}/{book}/{chapter}:{from}-{to}.json → verse range (colon form)
            add_rewrite_rule(
                '^' . $qs . '/([^/]+)/([0-9]+):([0-9]+)-([0-9]+)\.json$',
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
        // /bible-index.json — unified index: all books × all translations in one fetch
        add_rewrite_rule( '^bible-index\.json$', 'index.php?' . self::QV_FORMAT . '=bible-index&' . self::QV_FLAG . '=1', 'top' );
        // /bible-books.json — the SEARCH VOCABULARY alone (~4 KB): what the rail's
        // Bible search fetches once, so it can tell as the reader types whether
        // what they wrote names a book. Deliberately not bible-index.json, which
        // is 140 KB of names, URLs and chapter counts for a question that needs
        // none of them. Language-independent: every language's names are tokens
        // in the same list.
        add_rewrite_rule( '^bible-books\.json$', 'index.php?' . self::QV_FORMAT . '=bible-books&' . self::QV_FLAG . '=1', 'top' );
        // /bible-ref.json?q=Gal+3:28 — the REFERENCE RESOLVER: one fetch turns any
        // citation into the passage's text plus every HTML/JSON address it has, so
        // an agent that may only follow URLs it has seen can reach a verse.
        // /bible-search.json?q=dilexerunt+tenebras — VERSE SEARCH over one translation.
        // Both are answered by class-dwbible-agent-api.php.
        add_rewrite_rule( '^bible-ref\.json$',    'index.php?' . self::QV_FORMAT . '=bible-ref&' . self::QV_FLAG . '=1', 'top' );
        add_rewrite_rule( '^bible-search\.json$', 'index.php?' . self::QV_FORMAT . '=bible-search&' . self::QV_FLAG . '=1', 'top' );

        // ── HTML routes ─────────────────────────────────────────────────
        foreach ($slugs as $slug) {
            $slug = trim($slug, "/ ");
            if ($slug === '') continue;
            // index
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}:{verse} or {chapter}:{from}-{to}. The separator accepts a
            // COLON (canonical, English) OR a COMMA (German/Latin citation style: "Genesis 1,2").
            // The router 301s the comma form to the colon canonical.
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+)[:,]([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}/{verse} or {chapter}/{from}-{to} — slash form; router redirects to colon form
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+)/([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
        }
        // 'biblia' — the CANONICAL Bible section slug (Latin; "Latin Prayer"). It is NOT a dataset
        // (no data/biblia/); it only ROUTES. Requests always arrive prefixed (/{lang}/biblia/…) and
        // dwbible-i18n's request filter swaps dwbible_slug to that language's Latin+vernacular combo
        // before rendering (locale-less /biblia/… is 301'd to /{lang}/biblia/ first). HTML routes only.
        $bs = self::CANONICAL_SECTION; // 'biblia'
        add_rewrite_rule('^' . $bs . '/?$', 'index.php?' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $bs, 'top');
        add_rewrite_rule('^' . $bs . '/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $bs, 'top');
        add_rewrite_rule('^' . $bs . '/([^/]+)/([0-9]+)[:,]([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $bs, 'top');
        add_rewrite_rule('^' . $bs . '/([^/]+)/([0-9]+)/([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $bs, 'top');
        add_rewrite_rule('^' . $bs . '/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $bs, 'top');
        // Sitemaps: per-book Bible (one file per web-locale dataset × book), prayers, saints, index.
        // Pattern: /bible-sitemap-{slug}-{book}.xml → per-book sitemap. The language part is matched
        // permissively here; web_bible_datasets() in handle_sitemap() is the single gate that decides
        // which slugs are served (200) vs 404 — so this rule needs no flush when languages change.
        add_rewrite_rule(
            '^bible-sitemap-([a-z]+)-([a-z0-9-]+)\.xml$',
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
            $base_ver = defined( 'DWBIBLE_VERSION' ) ? (string) DWBIBLE_VERSION : '';

            $css_rel  = 'assets/dwbible.css';
            $css_url  = plugins_url( $css_rel, __FILE__ );
            $css_path = plugin_dir_path( __FILE__ ) . $css_rel;
            $css_ver  = $base_ver;
            if ( is_string( $css_path ) && $css_path !== '' && file_exists( $css_path ) ) {
                $css_ver .= '.' . (string) filemtime( $css_path );
            }
            // Depend on the dwtheme token kernel so the canonical design tokens
            // (palette + data-theme dark/night/sepia flips) are present and ordered
            // before dwbible's styles. dwbible rides the canonical `data-theme`
            // system set by the host site — it no longer runs its own theme JS
            // (the retired dwbible-theme.js drove a SECOND dark scheme on its own
            // localStorage key, fighting the canonical one).
            wp_enqueue_style( 'dwbible-styles', $css_url, [ 'dwtheme-tokens' ], $css_ver );

            // Main frontend script in the footer
            $js_rel  = 'assets/dwbible-frontend.js';
            $js_url  = plugins_url( $js_rel, __FILE__ );
            $js_path = plugin_dir_path( __FILE__ ) . $js_rel;
            $js_ver  = $base_ver;
            if ( is_string( $js_path ) && $js_path !== '' && file_exists( $js_path ) ) {
                $js_ver .= '.' . (string) filemtime( $js_path );
            }
            wp_enqueue_script( 'dwbible-frontend', $js_url, [], $js_ver, true );
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
        $vars[] = self::QV_QR;
        $vars[] = self::QV_SITEMAP;
        $vars[] = self::QV_SELFTEST;
        $vars[] = self::QV_FORMAT;
        return $vars;
    }

    private static function data_root_dir() {
        return DwBible_Data_Paths::data_root_dir();
    }

    private static function html_dir() {
        return DwBible_Data_Paths::html_dir();
    }

    private static function text_dir() {
        return DwBible_Data_Paths::text_dir();
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
        $parsed = DwBible_Index_Loader::load_index($csv);
        if (is_array($parsed)) {
            if (isset($parsed['books']) && is_array($parsed['books'])) {
                self::$books = $parsed['books'];
            }
            if (isset($parsed['slug_map']) && is_array($parsed['slug_map'])) {
                self::$slug_map = $parsed['slug_map'];
            }
        }
    }

    /**
     * A book name reduced to the ASCII form the URLs and lookups are built on.
     *
     * ─── Why the umlauts are spelled out and not stripped ─────────────────
     *
     * This used to be `strtolower()` plus "delete everything outside a-z0-9-",
     * which DROPPED accented letters rather than transliterating them:
     * `Sprüche` came out `sprche`, `Matthäus` `matthus`, `Génesis` `gnesis`.
     *
     * That was survivable only because it was wrong CONSISTENTLY — nearly every
     * caller slugifies both sides of a comparison, so an index entry and an
     * inbound URL segment were mangled identically and still matched. The
     * mangled forms are dead strings, not addresses: `/latin-bibel/sprche/`
     * 404s, while `/latin-bibel/sprueche/` serves — because the correct
     * transliterations live in DATA (book_map.json, abbreviations.de.json) and
     * were never produced here. Measured before this changed, on all five.
     *
     * So the old behaviour cost nothing visible and hid one real thing: any
     * caller that slugified a display name and compared it against that data
     * could never match, which is exactly what made book_map_consistency
     * unfixable until it stopped using this function.
     *
     * German convention decides the mapping — ä→ae, ö→oe, ü→ue, ß→ss — because
     * that is the convention the data already spells out (`Sprueche`,
     * `Matthaeus`, `1_Makkabaeer`). Those pairs are applied FIRST, before any
     * generic accent folding, or `ü` would become a bare `u` and disagree with
     * every one of those files. Everything else folds to its base letter, which
     * is right for the Romance datasets: `Génesis` → `genesis`, `Genèse` →
     * `genese`.
     *
     * Deliberately NOT remove_accents(): that function's German handling is
     * locale-dependent (`ä` becomes `a` or `ae` depending on the site language),
     * and a function whose output becomes an address may not change meaning
     * with a setting.
     */
    /**
     * `noindex` on the Latin-only surface. See the note above: the Vulgate is
     * on all five locale pages already, so letting this one be indexed would
     * have the site competing with itself for the same text. `follow` because
     * the links on it are ordinary Bible links worth crawling.
     */
    public static function noindex_latin_only() {
        if (get_query_var(self::QV_SLUG) === 'latin' && !get_query_var(self::QV_FORMAT)) {
            echo '<meta name="robots" content="noindex, follow">' . "\n";
        }
    }

    public static function slugify($name) {
        $slug = (string) $name;

        // German first — two-letter forms that generic folding would destroy.
        $slug = strtr($slug, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ẞ' => 'Ss',
        ]);

        // Everything else to its base letter. mb_strtolower because the plain
        // one is byte-wise and would leave an accented capital untouched.
        $slug = function_exists('mb_strtolower') ? mb_strtolower($slug, 'UTF-8') : strtolower($slug);
        $slug = strtr($slug, [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','å'=>'a','ā'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ø'=>'o','ō'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ū'=>'u',
            'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y','æ'=>'ae','œ'=>'oe',
        ]);

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
        self::$book_map = DwBible_Mappings_Loader::load_book_map();
        if (!is_array(self::$book_map)) {
            self::$book_map = [];
        }
    }

    private static function load_osis_mapping() {
        if (self::$osis_mapping !== null) {
            return;
        }
        self::$osis_mapping = DwBible_Mappings_Loader::load_osis_mapping();
        if (!is_array(self::$osis_mapping)) {
            self::$osis_mapping = [];
        }
    }

    private static function osis_for_dataset_book_slug($dataset_slug, $dataset_book_slug) {
        self::load_osis_mapping();
        return DwBible_Osis_Utils::osis_for_dataset_book_slug(self::$osis_mapping, $dataset_slug, $dataset_book_slug);
    }

    private static function dataset_book_slug_for_osis($dataset_slug, $osis) {
        self::load_osis_mapping();
        return DwBible_Osis_Utils::dataset_book_slug_for_osis(self::$osis_mapping, $dataset_slug, $osis);
    }

    /**
     * The Latin CANONICAL URL-slug layer. "Latin Prayer" — every Bible URL is the Latin book name
     * (actus-apostolorum, apocalypsis, ioannes), while the INTERNAL data key (acts, apocalypse,
     * john — the one the datasets + generated HTML verse IDs use) is unchanged. This map bridges the
     * two, built once from the Latin dataset's own display names. slug_to_key also carries the
     * internal keys as identity entries so a stale English URL still resolves (then 301s).
     *
     * @return array{key_to_slug: array<string,string>, slug_to_key: array<string,string>}
     */
    public static function latin_url_slug_map(): array {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = ['key_to_slug' => [], 'slug_to_key' => []];
        // Build straight from the raw Latin dataset index (NOT list_books_for_edition, which now
        // calls back into the Latin-slug layer — that would recurse). The Latin dataset's short_name
        // slugifies to the internal key; its display_name slugifies to the Latin URL slug.
        foreach ((array) self::load_dataset_index('latin') as $b) {
            if (!is_array($b) || empty($b['short_name'])) {
                continue;
            }
            $key  = self::slugify((string) $b['short_name']);
            $disp = !empty($b['display_name']) ? (string) $b['display_name'] : (string) $b['short_name'];
            if ($key === '' || $disp === '') {
                continue;
            }
            $ls = self::slugify($disp);
            if ($ls === '') {
                continue;
            }
            $map['key_to_slug'][$key] = $ls;
            $map['slug_to_key'][$ls]  = $key;
            $map['slug_to_key'][$key] = $key; // identity: the old English key still resolves
        }
        return $map;
    }

    /** Latin canonical URL slug for an internal book key (fallback: the key itself). */
    public static function latin_slug_for_key($key): string {
        $key = (string) $key;
        $m = self::latin_url_slug_map();
        return $m['key_to_slug'][$key] ?? $key;
    }

    /**
     * Resolve ANY inbound book slug — Latin canonical, the internal/English key, or a vernacular
     * name (Apostelgeschichte, Hechos) — to the internal canonical key, or null if unknown.
     */
    public static function key_from_any_book_slug($slug): ?string {
        $slug = self::slugify((string) $slug);
        if ($slug === '') {
            return null;
        }
        $m = self::latin_url_slug_map();
        if (isset($m['slug_to_key'][$slug])) {
            return $m['slug_to_key'][$slug]; // Latin slug OR internal key
        }
        foreach (['bibel', 'bible', 'spanish', 'french', 'italian', 'latin'] as $ds) {
            $k = self::canonicalize_key_from_dataset_book_slug($ds, $slug);
            if (is_string($k) && $k !== '') {
                return $k; // vernacular / dataset-localized name
            }
        }
        return null;
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
        if ($canonical_book_slug === '') {
            return '';
        }
        // Canonical Bible URLs are the LATIN book slug now — dataset-independent ("Latin Prayer":
        // every URL is the Vulgate name). Resolve whatever we're given (internal key, Latin slug,
        // or a dataset-localized name) to the internal key, then emit its Latin slug. $dataset_slug
        // is retained for signature/back-compat but no longer selects a per-dataset book slug.
        $key = self::key_from_any_book_slug($canonical_book_slug);
        if ($key === null || $key === '') {
            $key = $canonical_book_slug;
        }
        return self::latin_slug_for_key($key);
    }

    private static function canonicalize_key_from_dataset_book_slug($dataset_slug, $dataset_book_slug) {
        self::load_book_map();
        return DwBible_Canonicalization::canonicalize_key_from_dataset_book_slug(self::$book_map, $dataset_slug, $dataset_book_slug);
    }

    /**
     * Flat book list for the sticky-header book picker, in canonical order.
     * Each entry is ['n' => display name, 'u' => book URL in THIS edition].
     * Mirrors how the index builds tiles (display_name + slugify(short_name)),
     * so the picker and the directory agree on names and links.
     *
     * @param string $slug Current edition slug (e.g. 'latin-bibel', 'bible').
     * @return array
     */
    public static function list_books_for_edition($slug) {
        if (!is_string($slug) || $slug === '') return [];
        $primary = $slug;
        if (strpos($primary, '-') !== false) {
            $parts = explode('-', $primary);
            $primary = $parts[0];
        }
        $books = self::load_dataset_index($primary);
        if (!is_array($books) || empty($books)) return [];
        $base = trailingslashit(home_url('/' . trim($slug, '/') . '/'));

        // NT starts at Matthew/Matthaeus (locale-independent); fall back to the
        // canonical order threshold 46 (last OT book = 2 Machabees) if not found.
        $nt_start_order = null;
        foreach ($books as $b) {
            if (!is_array($b) || empty($b['short_name'])) continue;
            if (in_array(self::slugify($b['short_name']), ['matthew', 'matthaeus'], true)) {
                $nt_start_order = intval($b['order']);
                break;
            }
        }

        $out = [];
        foreach ($books as $b) {
            if (!is_array($b) || empty($b['short_name'])) continue;
            $name  = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $bslug = self::slugify($b['short_name']);
            // The URL slug is the LATIN canonical (dataset-independent): resolve the dataset's own
            // book slug to the internal key, then to its Latin form. 'n' (display name) stays in the
            // edition's language; 'slug'/'u' are Latin so the rail's current-book match ($cur_book is
            // the Latin URL slug) and every tile link land on the canonical URL, no 301 hop.
            $key    = self::key_from_any_book_slug($bslug);
            $latin  = self::latin_slug_for_key(($key === null || $key === '') ? $bslug : $key);
            $order = intval($b['order']);
            $is_nt = ($nt_start_order !== null) ? ($order >= $nt_start_order) : ($order > 46);
            // 'n' name, 'u' url, 'slug' url-slug, 'testament' ot|nt — the last two
            // let the side-rail identify the current book + its testament without
            // reaching into dwbible internals.
            $out[] = ['n' => $name, 'u' => $base . $latin . '/', 'slug' => $latin, 'testament' => $is_nt ? 'nt' : 'ot'];
        }
        return $out;
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
        $map = DwBible_Abbreviations_Loader::load_abbreviation_map($slug);
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

    /**
     * Dataset slugs that have a real, published WEB home — i.e. whose single-language
     * dataset maps to a language registered in dwi18n. These are the ONLY datasets we
     * advertise in the sitemap index, route sitemap files for, and emit URLs for; and
     * every URL we emit is the canonical /{lang}/bible/… form (no legacy-slug redirect).
     *
     * Single source of truth: base_slugs() ∩ the dwi18n registry. Adding a language to
     * dwi18n (registry.php) automatically enrolls its Bible into the sitemaps here — the
     * regex, the per-book handler's allow-list, and the index all read THIS one list, so
     * they can never drift. Adding `it` to the registry enrolled the Italian Bible here
     * with no code change, which is the design working.
     *
     * LATIN is omitted, and permanently — not "until it gets a home". A sitemap <loc>
     * is /{lang}/bible/…, so enrolling `latin` would require `la` to be a dwi18n
     * locale, and a /la/ interface would be Latin beside Latin: it contradicts the
     * interlinear model, where every page is the Vulgate PLUS a vernacular. There is
     * therefore no Latin-only HTML page for a sitemap to list, and pointing a Latin
     * sitemap at the shared interlinear URLs would publish the same addresses twice
     * across two sitemaps. `/bible-sitemap-latin-*.xml` answering 404 is correct.
     *
     * Latin is not thereby hidden: it is exposed as DATA, which is what it is —
     * /latin/{book}/index.json and friends, plus bible-index.json, which advertises it
     * under _meta.translations with a per-book jsonUrl and lang:null. See dwbible#3.
     *
     * @return array<string,string> dataset-slug => web-locale code (e.g. 'french' => 'fr')
     */
    public static function web_bible_datasets() {
        $out = [];
        if ( ! function_exists( 'dwbible_i18n_lang_for_slug' ) || ! function_exists( 'dwi18n_is_lang' ) ) {
            return $out; // dwi18n not loaded — handler will 404; nothing advertised.
        }
        foreach ( self::base_slugs() as $slug ) {
            if ( strpos( $slug, '-' ) !== false ) { continue; } // singles only, skip combos
            $lang = dwbible_i18n_lang_for_slug( $slug );
            if ( $lang !== '' && dwi18n_is_lang( $lang ) ) {
                $out[ $slug ] = $lang;
            }
        }
        return $out;
    }

    /**
     * Canonical Bible URL for a sitemap <loc>: /{lang}/bible/{rest}/ via dwi18n's URL
     * builder, so sitemaps list the final 200 destination directly — never a legacy
     * /{slug}/… URL that 301-redirects. $rel begins with '/bible/…' (no lang, no query).
     */
    private static function sitemap_loc( $lang, $rel ) {
        if ( function_exists( 'dwi18n_url_for' ) ) {
            return dwi18n_url_for( $lang, $rel );
        }
        return rtrim( home_url(), '/' ) . '/' . $lang . '/' . ltrim( $rel, '/' ) . '/';
    }

    /** Build one <url> node for a urlset sitemap (kills the book/chapter/verse copy-paste). */
    private static function sitemap_url_node( $loc, $lastmod_tag, $priority = '' ) {
        $node  = '  <url>' . "\n";
        $node .= '    <loc>' . esc_url( $loc ) . '</loc>' . "\n";
        $node .= $lastmod_tag;
        if ( $priority !== '' ) {
            $node .= '    <priority>' . $priority . '</priority>' . "\n";
        }
        $node .= '  </url>' . "\n";
        return $node;
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
        $web_datasets = self::web_bible_datasets();
        if (!isset($web_datasets[$slug])) {
            // Not a web-published dataset (unknown, or a homeless dataset like latin/italian).
            status_header(404);
            exit;
        }
        $lang = $web_datasets[$slug];

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
        // Canonical /{lang}/biblia/{latin-book}/ — map the dataset's book slug to the
        // canonical key then to the Latin URL slug, so the sitemap lists the final 200
        // destination directly (no /bible/→/biblia/ or english→latin 301 hop). Language
        // prefix is added by sitemap_loc().
        $canon_key  = self::canonicalize_key_from_dataset_book_slug($slug, $book_slug);
        $latin_book = self::latin_slug_for_key((is_string($canon_key) && $canon_key !== '') ? $canon_key : $book_slug);
        $book_rel   = '/' . self::CANONICAL_SECTION . '/' . $latin_book;

        // Get lastmod from HTML file modification time
        $file    = self::html_dir() . $entry['filename'];
        $lastmod = file_exists($file) ? date('Y-m-d', filemtime($file)) : '';
        $lastmod_tag = $lastmod ? '    <lastmod>' . $lastmod . '</lastmod>' . "\n" : '';

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Book URL
        $xml .= self::sitemap_url_node(self::sitemap_loc($lang, $book_rel), $lastmod_tag, '0.8');

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
                        $xml .= self::sitemap_url_node(self::sitemap_loc($lang, $book_rel . '/' . $ch), $lastmod_tag, '0.7');
                    }

                    // Verse-level entries
                    foreach ($chapters as $ch => $verses) {
                        sort($verses);
                        $seen = [];
                        foreach ($verses as $v) {
                            if (isset($seen[$v])) continue;
                            $seen[$v] = true;
                            $xml .= self::sitemap_url_node(self::sitemap_loc($lang, $book_rel . '/' . $ch . ':' . $v), $lastmod_tag);
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

        // Load book index to generate per-book sitemap references. Only datasets with a real web
        // home are advertised (see web_bible_datasets) — every referenced sitemap serves 200 and
        // every URL inside it is the canonical /{lang}/bible/… form.
        $data_dir = dwbible_data_dir();
        $datasets = array_keys( self::web_bible_datasets() );

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Per-book Bible sitemaps (one per web-locale dataset × book).
        foreach ( $datasets as $ds ) {
            $csv_file = $data_dir . $ds . '/html/index.csv';
            if ( ! file_exists( $csv_file ) ) { continue; }
            $rows = array_map( 'str_getcsv', file( $csv_file ) );
            foreach ( $rows as $i => $row ) {
                // Skip CSV header row and empty entries
                if ( $i === 0 && isset( $row[0] ) && $row[0] === 'order' ) { continue; }
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

    /**
     * Cached "first-verses" teaser for a passage in a given page language's
     * vernacular edition, plus the interlinear reader URL for the FULL passage.
     *
     * Used by the latinprayer.org homepage "Today's Mass" block to quote the
     * opening of each reading. The verse text is an IMMUTABLE function of
     * (language, book, chapter, verse-range) — a static file read — so the
     * result is memoised in a long-lived transient: dwbible touches the
     * filesystem once ever per passage+language, never on an ordinary
     * (cache-rebuilding) homepage render.
     *
     * @param string $slug          Book slug in any known spelling (Vulgate/EF/URL);
     *                              resolved to the internal key via key_from_any_book_slug().
     * @param int    $ch            Chapter number.
     * @param string $verses        Verse range like "19-23", or a single "19".
     * @param string $lang          Page language (de|en|es|fr|it|la); '' → dwi18n_current().
     * @param int    $teaser_verses How many opening verses to quote (default 3); CSS
     *                              line-clamps the visual length further.
     * @return array{text:string,url:string} Empty text when no vernacular edition has
     *                              the passage; url is still built when the book resolves.
     */
    public static function passage_teaser($slug, $ch, $verses, $lang = '', $teaser_verses = 3) {
        $out  = ['text' => '', 'url' => '', 'book' => ''];
        $slug = (string) $slug;
        $ch   = absint($ch);
        if ($slug === '' || $ch <= 0) return $out;

        if ($lang === '' && function_exists('dwi18n_current')) $lang = dwi18n_current();
        if (!is_string($lang) || $lang === '')                 $lang = 'en';

        // Full verse range of the reading (drives the reader URL).
        $vf = 0; $vt = 0;
        if (preg_match('/^(\d+)(?:-(\d+))?$/', (string) $verses, $m)) {
            $vf = (int) $m[1];
            $vt = (isset($m[2]) && (int) $m[2] >= $vf) ? (int) $m[2] : $vf;
        }

        $key = self::key_from_any_book_slug($slug);
        if ($key === null || $key === '') return $out;

        // Interlinear reader URL for the FULL passage — built even when the
        // vernacular text is absent (the citation still needs a target).
        $url_verses  = $vf > 0 ? ($ch . ':' . $vf . ($vt > $vf ? '-' . $vt : '')) : (string) $ch;
        $out['url']  = home_url('/' . rawurlencode($lang) . '/' . self::CANONICAL_SECTION
            . '/' . self::latin_slug_for_key($key) . '/' . $url_verses . '/');

        // Vernacular dataset (single-language edition) for this page language.
        if (!function_exists('dwbible_i18n_json_dataset_for_slug') || !function_exists('dwbible_i18n_combo_for_lang')) {
            return $out;
        }
        $dataset = dwbible_i18n_json_dataset_for_slug(dwbible_i18n_combo_for_lang($lang));
        if ($dataset === '' || $vf <= 0) return $out;

        // Quote only the opening verses; CSS clamps the visual length further.
        $last = min($vt, $vf + max(1, (int) $teaser_verses) - 1);

        $cache_key = 'dwbible_teaser_' . md5('v2|' . $dataset . '|' . $key . '|' . $ch . '|' . $vf . '-' . $last);
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            $out['text'] = isset($cached['text']) ? (string) $cached['text'] : '';
            $out['book'] = isset($cached['book']) ? (string) $cached['book'] : '';
            return $out;
        }

        // Pure file read of the vernacular chapter JSON — no query-var side effects.
        // Shape: { "_meta": { "book": { "name": "Römer" } },
        //          "verses": [ { "verse": int, "text": string, … }, … ] }.
        $text = '';
        $book = '';
        $file = dwbible_data_dir() . $dataset . '/json/' . $key . '/' . $ch . '.json';
        if (is_readable($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                if (isset($data['_meta']['book']['name'])) {
                    $book = (string) $data['_meta']['book']['name']; // full localized name
                }
                if (isset($data['verses']) && is_array($data['verses'])) {
                    $parts = [];
                    foreach ($data['verses'] as $v) {
                        $n = isset($v['verse']) ? (int) $v['verse'] : 0;
                        if ($n >= $vf && $n <= $last && isset($v['text']) && $v['text'] !== '') {
                            $parts[] = (string) $v['text'];
                        }
                    }
                    if ($parts) $text = self::clean_verse_text_for_output(implode(' ', $parts));
                }
            }
        }

        // Immutable content → cache long; cache a total miss briefly so a gap
        // doesn't re-hit the filesystem on every render.
        set_transient($cache_key, ['text' => $text, 'book' => $book],
            ($text === '' && $book === '') ? DAY_IN_SECONDS : MONTH_IN_SECONDS);
        $out['text'] = $text;
        $out['book'] = $book;
        return $out;
    }

    private static function normalize_whitespace($s) {
        return DwBible_Text_Utils::normalize_whitespace($s);
    }

    /**
     * Public helper for widgets/OG/etc: normalize whitespace and clean quotation marks.
     */
    public static function clean_verse_text_for_output($s, $wrap_outer = false, $qL = '»', $qR = '«') {
        return DwBible_Text_Utils::clean_verse_text_for_output($s, $wrap_outer, $qL, $qR);
    }

    private static function render_index() {
        // A typed query is answered before anything is drawn: `?q=` that names
        // one book redirects to it (see maybe_redirect_query); one that does not
        // falls through and the filter below opens holding it.
        if (self::maybe_redirect_query()) { return; }
        self::load_index();
        status_header(200);
        // The book list is static for a day — but a page rendered for a `?q=`
        // the resolver could not place is that reader's answer, not the index,
        // and nothing between here and them may keep it.
        if (isset($_GET['q']) && trim((string) wp_unslash($_GET['q'])) !== '') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            nocache_headers();
        } else {
            header('Cache-Control: public, max-age=86400');
        }
        $content = self::build_index_html();
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        // Translated: dwi18n switches WP's locale from the URL's language
        // segment, so the catalogue that already localises the book groups
        // ('Pentateuch' -> 'Pentateuco') carries this heading too.
        self::output_with_theme(__('The Bible', 'dwbible'), $content, 'index');
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

        $ref = DwBible_Reference::parse_chapter_and_range($ch, $vf_raw, $vt_raw);
        if (is_wp_error($ref)) {
            self::render_404();
            return;
        }

        if (!empty($ref['vf'])) {
            $targets = DwBible_Reference::highlight_ids_for_range($book_slug, $ref['ch'], $ref['vf'], $ref['vt']);
        } else {
            $chapter_scroll_id = DwBible_Reference::chapter_scroll_id($book_slug, $ref['ch']);
        }

        $lang_switcher = '';

        // Inject navigation helpers and optional highlight/scroll behavior
        $human = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : $entry['short_name'];
        $html = self::inject_nav_helpers($html, $targets, $chapter_scroll_id, $human, [
            'book' => $book_slug,
            'chapter' => $ch,
        ], $lang_switcher);

        status_header(200);
        header('Cache-Control: public, max-age=86400'); // verse content is static — cache 24h
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
        // Append bottom prev/next nav after the verse content
        if (DwBible_Nav_Helpers::$last_nav_ctx) {
            $html .= DwBible_Nav_Helpers::build_bottom_nav(DwBible_Nav_Helpers::$last_nav_ctx);
        }
        $content = '<div class="dwbible dwbible-book">' . $html . '</div>';
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
     * Testament headings (Latin) keyed by the 'testament' tag used in
     * book_categories().
     */
    private static function testament_meta() {
        return [
            'ot' => ['latin' => 'Vetus Testamentum'],
            'nt' => ['latin' => 'Novum Testamentum'],
        ];
    }

    /**
     * Normalize a string for client/server-consistent search matching:
     * lowercase + strip diacritics + æ/œ/ß expansions. Keeps spaces; callers
     * reduce to [a-z0-9] per token. Mirrors the JS norm() in the index filter.
     */
    private static function search_normalize($s) {
        $s = mb_strtolower((string) $s, 'UTF-8');
        $map = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a',
            'ç'=>'c','č'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'ñ'=>'n',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ō'=>'o','ø'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
            'ý'=>'y','ÿ'=>'y',
            'æ'=>'ae','œ'=>'oe','ß'=>'ss',
        ];
        return strtr($s, $map);
    }

    /**
     * THE BOOK DIRECTORY: every book once, in canonical order, with the name
     * and the URL slug that every localized index shows for it.
     *
     * Read from the LATIN dataset because that is what a localized index is
     * built from: `/de/biblia/` is internally `latin-bibel`, and
     * build_index_html takes the FIRST half of a combo as its primary — so the
     * row on the German, Spanish and Italian index alike reads "3 Regum". One
     * list therefore serves every language, and the vernacular name is the
     * gloss beside it (book_names_by_dataset).
     *
     * ⚠️ Keyed by the book's own identity, like the vocabulary and the verse
     * counts — never by a dataset's order number. Order is used only to SORT
     * this one dataset against itself, which is the one thing it is reliable
     * for.
     *
     * The slug is the CANONICAL one (latin_slug_for_key), not the dataset's own
     * filename slug. The two differ often enough to matter — the Latin dataset
     * files the first Gospel under `matthew`, and `/biblia/matthew/` 301s to
     * `/biblia/matthaeus/` — so a link built from the filename costs a redirect
     * on most books. A row built from this list lands in one hop.
     *
     * @return array<int,array{key:string,slug:string,name:string}>
     */
    public static function book_directory() {
        static $cache = null;
        if ( $cache !== null ) { return $cache; }

        $rows = [];
        foreach ( self::load_dataset_index('latin') as $b ) {
            $short = isset($b['short_name']) ? (string) $b['short_name'] : '';
            if ($short === '') { continue; }
            $key = self::key_from_any_book_slug(self::slugify($short));
            if ($key === null) { continue; }
            $rows[] = [
                'key'   => $key,
                'slug'  => self::latin_slug_for_key($key),
                'name'  => !empty($b['display_name']) ? (string) $b['display_name'] : self::pretty_label($short),
                'order' => intval($b['order']),
            ];
        }
        usort($rows, function ($a, $b) { return $a['order'] - $b['order']; });

        $out = [];
        foreach ($rows as $r) { unset($r['order']); $out[] = $r; }

        $cache = $out;
        return $cache;
    }

    /**
     * One dataset's display name for each book, BY CANONICAL KEY.
     *
     * The gloss beside a book name — "3 Regum · 1 Könige" — and the only safe
     * way to build it. Grouping a language's names by the dataset's own order
     * number files them under the WRONG books: the Italian index offsets ~27
     * of them, which is how "Malachias" came to be glossed "Aggeo".
     *
     * @param string $dataset bible | bibel | spanish | french | italian | latin
     * @return array<string,string> book key => that language's name
     */
    public static function book_names_by_dataset( $dataset ) {
        static $cache = [];
        $dataset = (string) $dataset;
        if ( isset($cache[$dataset]) ) { return $cache[$dataset]; }

        $out = [];
        foreach ( self::load_dataset_index($dataset) as $b ) {
            $short = isset($b['short_name']) ? (string) $b['short_name'] : '';
            if ($short === '') { continue; }
            $key = self::key_from_any_book_slug(self::slugify($short));
            if ($key === null) { continue; }
            $out[$key] = !empty($b['display_name']) ? (string) $b['display_name'] : self::pretty_label($short);
        }

        $cache[$dataset] = $out;
        return $out;
    }

    /**
     * Prefix-search tokens for one book name: the normalized [a-z0-9] form,
     * plus — for numbered books ("I Corinthios" / "1 Corinthians") — the form
     * with the leading number/roman-numeral token removed, so the epistle name
     * itself ("corinthios") is matchable from its first letter.
     */
    /**
     * HOW LONG EACH BOOK IS: verses per chapter, by canonical book key.
     *
     * The Latin spine is the versification the whole site is aligned to (all
     * six editions carry the same 35,808 verses), so the counts are read from
     * it — `data/latin/json/<book>/index.json`, which states a verseCount per
     * chapter. 73 small reads, ~16 ms, behind a day-long cache.
     *
     * It exists so the rail's Bible field can tell a citation that MEANS
     * something from one that only looks like one: "John 4:9999" has the shape
     * of a reference and names a real book, and there is no such verse.
     *
     * Keyed like the vocabulary — by the book's own identity, never by a
     * dataset's order number (see search_tokens_by_book for what that costs).
     *
     * @return array<string,array<int,int>> book key => [verses in ch1, ch2, …]
     */
    public static function verse_counts_by_book() {
        static $cache = null;
        if ( $cache !== null ) { return $cache; }

        $out = [];
        $dir = dwbible_data_dir() . 'latin/json/';
        $idx = $dir . 'index.json';
        if ( ! file_exists( $idx ) ) { $cache = $out; return $out; }

        $books = json_decode( (string) file_get_contents( $idx ), true );
        $books = is_array( $books ) && ! empty( $books['books'] ) ? $books['books'] : [];

        foreach ( $books as $b ) {
            $slug = isset( $b['slug'] ) ? (string) $b['slug'] : '';
            if ( $slug === '' ) { continue; }
            $key = self::key_from_any_book_slug( $slug );
            if ( $key === null ) { continue; }

            $file = $dir . $slug . '/index.json';
            if ( ! file_exists( $file ) ) { continue; }
            $book = json_decode( (string) file_get_contents( $file ), true );
            if ( ! is_array( $book ) || empty( $book['chapters'] ) ) { continue; }

            $counts = [];
            foreach ( $book['chapters'] as $ch ) {
                $n = isset( $ch['verseCount'] ) ? (int) $ch['verseCount'] : 0;
                if ( $n > 0 ) { $counts[] = $n; }
            }
            if ( ! $counts ) { continue; }

            // MALACHIAS 4 — the router answers it (the Elijah prophecy is 3:19-24
            // here and 4:1-6 in every printed Vulgate; see the shim in
            // class-dwbible-router). A reader holding a 1962 missal types the
            // chapter that does not exist in our data, and the field must not
            // call implausible what the site itself resolves. Six verses,
            // exactly the six the shim maps.
            if ( $slug === 'malachias' && count( $counts ) === 3 ) {
                $counts[] = 6;
            }

            $out[ $key ] = $counts;
        }

        $cache = $out;
        return $cache;
    }

    /**
     * THE SEARCH VOCABULARY: every prefix-token that names a book, by BOOK.
     *
     * One vocabulary, two surfaces. The Bible index embeds a book's tokens on
     * its row so the on-page filter runs in the browser; the rail's Bible
     * search fetches the whole set (/bible-books.json) so it can tell, as the
     * reader types, whether what they wrote names a book at all. A word that
     * finds a book in one of them must find it in the other, so neither builds
     * this list itself.
     *
     * Every configured language's name for the book, plus its slug, each
     * reduced to a normalized token (and a number-stripped twin for numbered
     * books) — plus the curated abbreviations that are NOT a prefix of any full
     * name, chiefly the Gospels (Mt / Mk·Mc / Lk·Lc / Jn).
     *
     * ⚠️ KEYED BY THE BOOK'S OWN IDENTITY, never by a dataset's order number.
     * The six indexes do NOT agree on order, and grouping names by order files
     * a language's name under a DIFFERENT book: the Italian "Malachia" landed
     * on 2 Machabees and the Italian "Aggeo" on Malachias, so the index filter
     * matched those — silently, for as long as the filter has existed. Every
     * name is resolved to its canonical key instead, which is the one thing all
     * six indexes agree on.
     *
     * @return array<string,string> canonical book key => space-separated tokens
     */
    public static function search_tokens_by_book() {
        static $cache = null;
        if ( $cache !== null ) { return $cache; }

        // Curated abbreviations that no full name has as a prefix, by canonical key.
        $extra_abbr = [
            'matthew' => ['mt'],
            'mark'    => ['mk', 'mc'],
            'luke'    => ['lk', 'lc'],
            'john'    => ['jn'],
        ];

        $names = [];
        foreach (['latin', 'bible', 'bibel', 'spanish', 'french', 'italian'] as $ds) {
            foreach (self::load_dataset_index($ds) as $b) {
                $short = isset($b['short_name']) ? (string) $b['short_name'] : '';
                if ($short === '') { continue; }
                $key = self::key_from_any_book_slug(self::slugify($short));
                if ($key === null) { continue; }
                $names[$key][] = $short;
                if (!empty($b['display_name'])) { $names[$key][] = (string) $b['display_name']; }
            }
        }

        $out = [];
        foreach ($names as $key => $list) {
            $tok = [];
            foreach ($list as $n) {
                foreach (self::search_tokens_for_name($n) as $tk) { $tok[$tk] = true; }
            }
            if (isset($extra_abbr[$key])) {
                foreach ($extra_abbr[$key] as $ab) { $tok[$ab] = true; }
            }
            $out[$key] = implode(' ', array_keys($tok));
        }

        $cache = $out;
        return $cache;
    }

    private static function search_tokens_for_name($name) {
        $n = self::search_normalize($name); // keeps spaces
        $tokens = [];
        $full = preg_replace('/[^a-z0-9]/', '', $n);
        if ($full !== '') { $tokens[] = $full; }
        // A leading number or roman numeral that is its OWN token is a book
        // NUMBER, not part of the name — strip it for a second token, so the
        // epistle is findable by its own first letter.
        //
        // The number may be followed by a DOT as well as a space: the English
        // index writes "1. John", and requiring whitespace alone left those
        // three epistles with no bare "john" token at all — typing "john" found
        // the Gospel and nothing else (dwbible#5). A roman numeral still needs
        // whitespace, because a dot cannot tell "I. Cor" from a word that
        // merely begins with those letters.
        // (Names like "Iudith"/"Iob" have neither, so their leading I stays.)
        $stripped = preg_replace('/^(?:\d+[.\s]+|[ivxlcdm]+\s+)/', '', $n);
        $stripped = preg_replace('/[^a-z0-9]/', '', $stripped);
        if ($stripped !== '' && $stripped !== $full) { $tokens[] = $stripped; }
        return $tokens;
    }

    /**
     * Load index.csv for a specific dataset (bible, bibel, latin).
     */
    private static function load_dataset_index($dataset) {
        $csv = dwbible_data_dir() . $dataset . '/html/index.csv';
        $parsed = DwBible_Index_Loader::load_index($csv);
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

        // The grey gloss under each book name is the edition's vernacular
        // companion to the Latin — German on /latin-bibel/, Spanish on
        // /latin-spanish/, English on /latin-bible/. For a vernacular-primary
        // edition (e.g. /bible/, /bibel/) the primary IS the vernacular, so the
        // gloss is the Latin. Latin-only (/latin/) has no second language → no
        // gloss. (Previously hard-coded to English, which left every non-English
        // edition showing English glosses.)
        if ($primary_dataset === 'latin') {
            $secondary_dataset = '';
            foreach (explode('-', $current_slug) as $part) {
                if ($part !== 'latin' && $part !== '') { $secondary_dataset = $part; break; }
            }
        } else {
            $secondary_dataset = 'latin';
        }
        $secondary_names = [];
        if ($secondary_dataset !== '') {
            foreach (self::load_dataset_index($secondary_dataset) as $b) {
                $display = !empty($b['display_name']) ? $b['display_name'] : $b['short_name'];
                $secondary_names[intval($b['order'])] = $display;
            }
        }

        $base_url   = home_url('/' . $current_slug . '/');
        $testaments = self::testament_meta();

        // The search vocabulary — every language's name for each book, its slug
        // and its curated abbreviations — built once, in the one place that
        // knows it (search_tokens_by_book), and embedded on each row so the
        // filter runs 100% client-side. The rail's Bible search fetches the
        // same vocabulary, so a word that finds a book here finds it there.
        $tokens_by_book = self::search_tokens_by_book();
        // …and how long each book is, so a row can refuse to promise a verse
        // that does not exist (the same question the rail's field asks).
        $verses_by_book = self::verse_counts_by_book();

        // ─── Translation model — "alongside the Latin" ──────────────────────
        // Every vernacular edition is an INTERLINEAR paired with the Latin
        // (/latin-bible = Latin+English, /latin-bibel = Latin+German, …);
        // "Latin only" (/latin) is the lone single-text option. Links go
        // straight to the canonical combo slug (no /bible/ → /latin-bible/
        // redirect hop). The active item is the vernacular half of the
        // current slug, or "Latin only" on /latin/.
        $switch = [
            'latin'   => ['label' => 'Latin only', 'slug' => 'latin'],
            'bible'   => ['label' => 'English',    'slug' => 'latin-bible'],
            'bibel'   => ['label' => 'German',     'slug' => 'latin-bibel'],
            'spanish' => ['label' => 'Spanish',    'slug' => 'latin-spanish'],
            'french'  => ['label' => 'French',     'slug' => 'latin-french'],
            'italian' => ['label' => 'Italian',    'slug' => 'latin-italian'],
        ];
        $active_lang = 'latin';
        foreach (explode('-', $current_slug) as $part) {
            if ($part !== 'latin' && $part !== '') { $active_lang = $part; break; }
        }
        if (!isset($switch[$active_lang])) { $active_lang = 'latin'; }

        // Subtitle = the Vulgate, plus the vernacular translation name and its
        // language code in parens (if any). Latin-only carries (LA).
        $translation_names = [
            'bible'   => ['name' => 'Douay-Rheims', 'code' => 'EN'],
            'bibel'   => ['name' => 'Menge',        'code' => 'DE'],
            'spanish' => ['name' => 'Scío de San Miguel', 'code' => 'ES'],
            'french'  => ['name' => 'Crampon',      'code' => 'FR'],
            'italian' => ['name' => 'Martini',      'code' => 'IT'],
        ];
        $subtitle = isset($translation_names[$active_lang])
            ? 'Vulgata · ' . $translation_names[$active_lang]['name'] . ' (' . $translation_names[$active_lang]['code'] . ')'
            : 'Vulgata Clementina (LA)';

        // `?q=` arrives from the drawer's Bible search (and from any shared
        // link). The router sends the reader here only when it could NOT resolve
        // the query to a single book — so the filter opens already filled and
        // they see the candidates rather than a 404.
        $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // ─── Page head: title · Vulgate+translation subtitle ───
        $out  = '<div class="dwbible dwbible-index">';
        $out .= '<header class="dwbible-index-head">';
        $out .= '<div class="dwbible-index-headrow">';
        $out .= '<div class="dwbible-index-headtext">';
        // The title IS the edition: the Vulgate + the active translation name
        // (Latin-only carries just the Vulgate). No separate subtitle row.
        $out .= '<h1 class="dwbible-index-title">' . esc_html($subtitle) . '</h1>';
        $out .= '</div>';
        // Subtle search toggle, flush right in the title row: reveals the book
        // filter on demand (kept out of the way until wanted).
        $out .= '<button type="button" class="dwbible-index-search-toggle" aria-label="' . esc_attr__( 'Filter books', 'dwbible' ) . '" aria-expanded="' . ( $q === '' ? 'false' : 'true' ) . '">';
        // The canonical wgnrstyle `search` glyph (24 grid, stroke 1.6) — the same
        // mark the drawer's Bible row carries, so one search means one shape.
        $out .= '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="M15.3 15.3L20 20"/></svg>';
        $out .= '</button>';
        $out .= '</div>'; // .dwbible-index-headrow
        $out .= '</header>';

        // ─── On-page filter (client-side; hidden until the title search toggle
        //     reveals it; see the script at the foot) ───
        // The placeholder teaches the three accepted forms by example — full
        // name, abbreviation, and a chapter:verse citation — so the citation
        // path is discoverable without a second line of instructions. It is
        // translated, so each language shows its own book name and separator
        // ("Matthäus, Mt oder Matthäus 5,41…").
        $out .= '<div class="dwbible-filter-wrap"' . ( $q === '' ? ' hidden' : '' ) . '>';
        $out .= '<input type="search" class="dwbible-filter" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Matthew, Mt, or Matthew 5:41…', 'dwbible' ) . '" aria-label="' . esc_attr__( 'Filter books by name, abbreviation, or reference', 'dwbible' ) . '" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" />';
        $out .= '<p class="dwbible-filter-empty" role="status" hidden>' . esc_html__( 'No books match that.', 'dwbible' ) . '</p>';
        $out .= '</div>';

        // ─── Testament sections, each holding its book groups ───
        $open_testament = '';
        foreach ($categories as $cat) {
            // Open a new testament section when the testament tag changes.
            if ($cat['testament'] !== $open_testament) {
                if ($open_testament !== '') {
                    $out .= '</section>'; // close previous .dwbible-testament
                }
                $open_testament = $cat['testament'];
                $t = isset($testaments[$open_testament]) ? $testaments[$open_testament] : null;
                // Stable anchor id (Latin, edition-independent) so the side-rail's
                // Old/New Testament links scroll to this block on the same index page.
                $testament_anchor = ($open_testament === 'nt') ? 'novum-testamentum' : 'vetus-testamentum';
                $out .= '<section class="dwbible-testament" id="' . esc_attr($testament_anchor) . '">';
                if ($t) {
                    $out .= '<header class="dwbible-testament-head">';
                    $out .= '<h2 class="dwbible-testament-title">' . esc_html($t['latin']) . '</h2>';
                    $out .= '</header>';
                }
            }

            // ─── Book group: name label + book list ───
            $out .= '<section class="dwbible-category">';
            $out .= '<div class="dwbible-category-label">';
            $out .= '<h3 class="lp-eyebrow dwbible-category-name lp-rowlist-head">' . esc_html( __( $cat['label'], 'dwbible' ) ) . '</h3>'; // phpcs:ignore WordPress.WP.I18n
            $out .= '</div>';
            $out .= '<div class="dwbible-tiles lp-rowlist">';

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

                // Suppress the alt-name when it differs from the primary
                // only by punctuation/whitespace ("1 Corinthians" vs
                // "1. Corinthians" or "1 John" vs "1.John"). The alt is
                // worth showing for genuine alternate names like
                // "Joshua / Josue" or "Sirach / Ecclesiasticus" — not
                // for trivial period vs space differences. Normalize:
                // lowercase + strip every non-alphanumeric character.
                $alt_meaningful = ( $alt_name !== '' && strtolower( preg_replace( '/[^a-z0-9]+/i', '', $alt_name ) ) !== strtolower( preg_replace( '/[^a-z0-9]+/i', '', $name ) ) );

                // Build tile with both names separated for AI text extraction
                $label = $name;
                if ( $alt_meaningful ) {
                    $label .= ' / ' . $alt_name;
                }

                // This book's slice of the shared vocabulary, plus the two names
                // this edition happens to show (they are already in it for every
                // configured language, but a display name can differ from the
                // dataset's own — so add them and let the set dedupe).
                $tok_set = [];
                $book_key = self::key_from_any_book_slug( $book_slug );
                $book_tok = ( $book_key !== null && isset($tokens_by_book[$book_key]) ) ? $tokens_by_book[$book_key] : '';
                foreach ( explode(' ', $book_tok) as $tk ) {
                    if ( $tk !== '' ) { $tok_set[$tk] = true; }
                }
                foreach ( [$name, $alt_name] as $cn ) {
                    if ( $cn === '' ) { continue; }
                    foreach ( self::search_tokens_for_name($cn) as $tk ) { $tok_set[$tk] = true; }
                }
                $data_search = implode(' ', array_keys($tok_set));

                // The row IS the canonical LP list primitive (.lp-row + slots);
                // .dwbible-tile* kept alongside for the book-search JS + the
                // vernacular-gloss overlay. --num gives the shared mono ordinal.
                $book_verses = ( $book_key !== null && isset($verses_by_book[$book_key]) ) ? implode(',', $verses_by_book[$book_key]) : '';

                $out .= '<a href="' . esc_url($url) . '" class="dwbible-tile lp-row lp-row--num" data-search="' . esc_attr($data_search) . '" data-verses="' . esc_attr($book_verses) . '" aria-label="' . esc_attr($label) . '">';
                $out .= '<span class="dwbible-tile-name lp-row__term">' . esc_html($name) . '</span>';
                if ( $alt_meaningful ) {
                    $out .= '<span class="dwbible-tile-alt lp-row__gloss">' . esc_html($alt_name) . '</span>';
                }
                $out .= '</a>';
            }

            $out .= '</div>';
            $out .= '</section>'; // .dwbible-category
        }
        if ($open_testament !== '') {
            $out .= '</section>'; // close final .dwbible-testament
        }

        // ─── Machine-readable footer line (visible; serves humans and AI agents) ───
        $site_url = home_url();
        $out .= '<p class="dwbible-index-api">';
        $out .= '<span class="lp-eyebrow lp-eyebrow--accent dwbible-index-api-label">' . esc_html__( 'Machine-readable', 'dwbible' ) . '</span> ';
        // The combo interlinear slugs (e.g. latin-bible) have no JSON twin —
        // only single datasets do — so point at the primary dataset's index.
        $out .= '<a href="' . esc_url($site_url . '/llms.txt') . '">/llms.txt</a>';
        $out .= ' · <a href="' . esc_url(home_url('/' . $primary_dataset . '/index.json')) . '">index.json</a>';
        $out .= ' · <a href="' . esc_url($site_url . '/bible-index.json') . '">bible-index.json</a>';
        $out .= '</p>';

        // ─── Client-side filter behaviour ───────────────────────────────────
        // Pure DOM filtering — prefix-matches the query against each tile's
        // data-search tokens, hides non-matching books and any group/testament
        // left empty. Never makes a request.
        //
        // A query may end in a CITATION ("Matthew 5:41", "Mt 5,41", "1 Cor 13",
        // "Io 3:16-18"): the name half filters the books exactly as before, and
        // the citation half is appended to each surviving row's href — so the
        // row leads straight to the verse instead of the book's table of
        // contents. Nothing about the citation is resolved here; the server
        // already owns /{book}/{ch}:{v}[-{v}] (canonicalisation, ranges, the
        // Malachias 4 shim, and 404s for what does not exist).
        //
        // The citation GRAMMAR is not written here: it comes from
        // assets/dwbible-search.js (window.DwBibleSearch), which is handed
        // DwBible_Reference::CITATION_PATTERN — the same string the server
        // parses `?q=` with. One grammar, three places that must agree.
        $js = <<<'JS'
<script>
(function(){
  var root = document.querySelector('.dwbible-index');
  if (!root) return;
  var input = root.querySelector('.dwbible-filter');
  if (!input) return;
  var groups = [].slice.call(root.querySelectorAll('.dwbible-category'));
  var testaments = [].slice.call(root.querySelectorAll('.dwbible-testament'));
  var empty = root.querySelector('.dwbible-filter-empty');

  // Each row, plus the pristine text/href to restore when no citation is typed.
  // The href is normalized to one trailing slash — a citation is appended to it.
  var tiles = [].slice.call(root.querySelectorAll('.dwbible-tile')).map(function(el){
    var nameEl = el.querySelector('.dwbible-tile-name');
    var href = el.getAttribute('href') || '';
    return {
      el: el,
      nameEl: nameEl,
      name: nameEl ? nameEl.textContent : '',
      href: href ? href.replace(/\/+$/, '') + '/' : '',
      label: el.getAttribute('aria-label') || '',
      tokens: (el.getAttribute('data-search') || '').split(' '),
      // How long this book is, chapter by chapter — so the row can decline to
      // promise a verse the book does not have.
      verses: (el.getAttribute('data-verses') || '').split(',')
        .map(function(n){ return parseInt(n, 10) || 0; })
        .filter(function(n){ return n > 0; }),
      ref: '' // the citation this row currently carries
    };
  });

  // The grammar — normalizing a typed word, and splitting
  // "<book> <chapter>[:<verse>[-<verse>]]" into its two halves — belongs to
  // assets/dwbible-search.js, which the rail's Bible field uses too. One
  // definition, so a word that finds a book in one field finds it in the other.
  var parseQuery = window.DwBibleSearch.parse;

  // With a citation the row names the VERSE it now leads to ("Matthaeus 5:41")
  // and its href carries the same reference; without one it is a book row again.
  // Writes only on a real change — otherwise every keystroke would rewrite three
  // attributes on all 73 rows for nothing.
  function decorate(t, ref){
    if (t.ref === ref) return;
    t.ref = ref;
    if (t.nameEl) { t.nameEl.textContent = ref ? t.name + ' ' + ref : t.name; }
    t.el.setAttribute('href', ref ? t.href + ref + '/' : t.href);
    t.el.setAttribute('aria-label', ref ? t.label + ' ' + ref : t.label);
  }

  function apply(){
    var parsed = parseQuery(input.value);
    var q = parsed.q;
    if (!q){
      tiles.forEach(function(t){ t.el.hidden = false; decorate(t, ''); });
      groups.forEach(function(g){ g.hidden = false; });
      testaments.forEach(function(s){ s.hidden = false; });
      if (empty) empty.hidden = true;
      return;
    }
    var any = false;
    tiles.forEach(function(t){
      var hit = false;
      for (var i = 0; i < t.tokens.length; i++){
        if (t.tokens[i] && t.tokens[i].lastIndexOf(q, 0) === 0){ hit = true; break; }
      }
      t.el.hidden = !hit;
      // Each row is decorated with as much of the citation as THIS book can
      // hold: "John 3:16666" leaves Ioannes reading "Ioannes 3", because a row
      // that promises a verse the book has not got is a link to nowhere.
      decorate(t, hit ? window.DwBibleSearch.fit(t.verses, parsed) : '');
      if (hit) any = true;
    });
    groups.forEach(function(g){
      g.hidden = !g.querySelector('.dwbible-tile:not([hidden])');
    });
    testaments.forEach(function(s){
      s.hidden = !s.querySelector('.dwbible-category:not([hidden])');
    });
    if (empty) empty.hidden = any;
  }

  input.addEventListener('input', apply);

  // Enter goes to the first row still standing — the point of typing a citation
  // is to land on the verse without reaching for the mouse.
  input.addEventListener('keydown', function(e){
    if (e.key !== 'Enter' || e.isComposing) return; // never steal an IME confirm
    for (var i = 0; i < tiles.length; i++){
      if (!tiles[i].el.hidden){
        e.preventDefault();
        window.location.href = tiles[i].el.getAttribute('href');
        return;
      }
    }
  });

  // Search toggle in the title: reveal/focus the filter; re-click hides + resets.
  var toggle = root.querySelector('.dwbible-index-search-toggle');
  var wrap = root.querySelector('.dwbible-filter-wrap');
  if (toggle && wrap){
    toggle.addEventListener('click', function(){
      if (wrap.hasAttribute('hidden')){
        wrap.removeAttribute('hidden');
        toggle.setAttribute('aria-expanded', 'true');
        input.focus();
      } else {
        wrap.setAttribute('hidden', '');
        toggle.setAttribute('aria-expanded', 'false');
        input.value = '';
        apply();
      }
    });
  }

  // Arriving with ?q= the server could not resolve to one book: the field is
  // already open and filled (see build_index_html), so run the filter over it.
  if (input.value) { apply(); }
})();
</script>
JS;
        $out .= $js;

        $out .= '</div>';
        return $out;
    }

    /**
     * Every dataset slug this install actually ships — single languages plus the
     * generated combos.
     *
     * Public because the SELF-TEST must derive its coverage from the same source
     * the router and the sitemap use. It hardcoded `['bible','bibel','latin']` in
     * three places and `['bible','latin']` in a fourth, so Spanish, French and
     * Italian — half the shipped product, ~84 MB of data, all six live — were
     * never checked at all (dwbible#15). A self-test that answers "I did not
     * look" as "yes" is worse than no self-test, and the way those three came to
     * be missing is precisely that the list was repeated rather than derived.
     *
     * @return string[] e.g. ['bible','bibel','spanish',…,'bible-latin',…]
     */
    public static function all_slugs() {
        return self::base_slugs();
    }

    private static function base_slugs() {
        $list = get_option('dwbible_slugs', 'bible,bibel,latin,spanish,french,italian');
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
            $known_single = ['bible', 'bibel', 'latin', 'spanish', 'french', 'italian'];
            if (in_array($slug, $known_single, true) || strpos($slug, '-') !== false) {
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

        // A dataset may not appear twice in one combo. An interlinear column set
        // is a set: `/bible-bible/` would print English beside English, and
        // `/bible-bible-bible/` three times. They were generated anyway -- 102 of
        // the 252 combos, and since each combo costs 12 rewrite rules, 1224 of
        // the 3560 rules in the `rewrite_rules` option, which is autoloaded and
        // unserialized on EVERY request.
        //
        // get_registered_slugs() in this same file already excludes self-pairs
        // (`if ( $a !== $b )`), so the plugin held both answers at once: routes
        // existed for slugs it did not consider registered.
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i) continue;
                $out[] = $datasets[$i] . '-' . $datasets[$j];
            }
        }

        if ($max_len >= 3 && $n >= 3) {
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    if ($j === $i) continue;
                    for ($k = 0; $k < $n; $k++) {
                        if ($k === $i || $k === $j) continue;
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
                'bible'   => 'Douay-Rheims',
                'bibel'   => 'Menge',
                'latin'   => 'Vulgate',
                'spanish' => 'Scío de San Miguel',
                'french'  => 'Crampon',
                'italian' => 'Martini',
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
     * Contribute the Bible/Prayers/Saints API documentation to the site
     * llms.txt. dwtheme owns the /llms.txt route and applies the
     * 'dwtheme_llms_sections' filter; the section text is authored in
     * dwbibledata/data/llms.txt (llms-full.txt for /llms-full.txt), so
     * robots discover the JSON API from the standard AI entry point.
     *
     * @param string[] $sections Markdown sections collected so far.
     * @param bool     $full     True when generating /llms-full.txt.
     * @return string[]
     */
    public static function add_llms_api_section( $sections, $full ) {
        $file = dwbible_data_dir() . ( $full ? 'llms-full.txt' : 'llms.txt' );
        if ( file_exists( $file ) ) {
            $sections[] = file_get_contents( $file );
        }
        return $sections;
    }

    /**
     * Append AI-friendly directives to the WordPress virtual robots.txt.
     *
     * Explicitly allows major AI crawlers and references Bible sitemaps
     * so AI agents can discover all available Bible content.
     */
    public static function filter_robots_txt( $output, $public ) {
        $site_url = site_url();

        // Crawl-delay: Bible has ~31k verse pages. Without throttling,
        // aggressive crawlers (Google, Bing) can saturate the server.
        // Inject Crawl-delay into the default User-agent: * block.
        // ── One `User-agent: *` group, not two ────────────────────────────
        //
        // The short-code rules go INTO the existing group rather than after it.
        // A second `User-agent: *` block is ambiguous — the spec has crawlers
        // pick the most specific matching group, and real parsers differ on
        // whether duplicate groups merge or the first one wins. One group
        // cannot be read two ways.
        //
        // /qr/XXXX and /q/XXXX are redirects for printed cards: each sends a
        // scanner to a page that IS indexed, so crawling the code yields
        // nothing indexable and spends budget on 46,656 possible addresses.
        // Nobody had been told to stay out, which is why a crawler enumerating
        // the space — one code per request, ~90 a day — was not misbehaving by
        // any rule we had published. It is published now.
        //
        // `Allow: /qr/$` keeps the bare address crawlable: that is where the
        // page explaining these codes belongs, and it is the one thing under
        // the prefix that is real content.
        $output = preg_replace(
            '/^(User-agent:\s*\*\s*\n)/mi',
            "$1Crawl-delay: 2\n"
            . "# Short codes are redirects, not content — the destination is indexed.\n"
            . "Disallow: /qr/\n"
            . "Disallow: /q/\n"
            . "Allow: /qr/$\n",
            $output,
            1
        );

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
        $book_sitemaps = 73 * count( self::web_bible_datasets() );
        $output .= "# Index references {$book_sitemaps} per-book Bible sitemaps + prayers + saints\n";
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
        // The JSON API is single-language, so an interlinear page (latin-bible,
        // /{lang}/biblia/…) points at its vernacular dataset — one hop, no 301.
        if ( function_exists( 'dwbible_i18n_json_dataset_for_slug' ) ) {
            $slug = dwbible_i18n_json_dataset_for_slug( $slug );
        }
        $book    = get_query_var( self::QV_BOOK );
        $chapter = get_query_var( self::QV_CHAPTER );
        $vfrom   = absint( get_query_var( self::QV_VFROM ) );
        $vto     = absint( get_query_var( self::QV_VTO ) );
        // The dataset dirs are keyed by the internal key; the page's URL carries
        // the Latin slug ("galatas"), which would cost the JSON API a redirect.
        $key = ! empty( $book ) ? self::key_from_any_book_slug( $book ) : null;
        if ( is_string( $key ) && $key !== '' ) { $book = $key; }

        // Build the JSON URL — for the verse(s) the page highlights, when it does.
        if ( ! empty( $book ) && ! empty( $chapter ) && $vfrom > 0 ) {
            $json_url = home_url( "/{$slug}/{$book}/{$chapter}/{$vfrom}" . ( $vto > $vfrom ? "-{$vto}" : '' ) . '.json' );
        } elseif ( ! empty( $book ) && ! empty( $chapter ) ) {
            $json_url = home_url( "/{$slug}/{$book}/{$chapter}.json" );
        } elseif ( ! empty( $book ) ) {
            $json_url = home_url( "/{$slug}/{$book}/index.json" );
        } else {
            $json_url = home_url( "/{$slug}/index.json" );
        }

        echo '<link rel="alternate" type="application/json" href="' . esc_url( $json_url ) . '" />' . "\n";
    }

    /**
     * What a Bible page says about itself on a social card (og:description).
     *
     * A verse page: the verse text in the page's vernacular (Latin when there is
     * none), clipped to card length. A chapter page: which chapter of which book,
     * in which two editions, how many verses. A book page: the book and its
     * length. The index: what the Bible section is. Before this every Bible page
     * carried the site tagline ("Catholic Prayer App") as its description.
     *
     * @param array $facts context/title/description/url from dwsocial.
     * @return array
     */
    public static function site_card_page_facts( $facts ) {
        if ( ! is_array( $facts ) || ! self::is_bible_request() ) { return $facts; }

        $slug    = get_query_var( self::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) { $slug = 'bible'; }
        $dataset = function_exists( 'dwbible_i18n_json_dataset_for_slug' ) ? dwbible_i18n_json_dataset_for_slug( $slug ) : 'bible';
        $sets    = self::json_datasets();
        $vern    = $sets[ $dataset ] ?? $sets['bible'];
        $latin   = $sets['latin'];
        $pair    = $latin['name'] . ' (' . $latin['languageName'] . ')'
                 . ( $dataset !== 'latin' ? ' with ' . $vern['name'] . ' (' . $vern['languageName'] . ')' : '' );

        $book    = (string) get_query_var( self::QV_BOOK );
        $chapter = absint( get_query_var( self::QV_CHAPTER ) );
        $vfrom   = absint( get_query_var( self::QV_VFROM ) );
        $vto     = absint( get_query_var( self::QV_VTO ) );
        if ( $vto < $vfrom ) { $vto = $vfrom; }
        $key     = $book !== '' ? self::key_from_any_book_slug( $book ) : null;

        if ( $key === null ) {
            $facts['description'] = 'The Catholic Bible, 73 books, every verse interlinear: ' . $pair . '.';
            return $facts;
        }

        $identity = self::agent_book_block( $key );
        $lang     = $vern['language'];
        $name     = $identity['names'][ $lang ] ?? $identity['title'];
        $counts   = self::verse_counts_by_book();
        $chapters = isset( $counts[ $key ] ) ? count( $counts[ $key ] ) : 0;

        if ( $chapter > 0 && $vfrom > 0 ) {
            $p = self::agent_load_passage( $dataset, $key, $chapter, $vfrom, $vto );
            if ( $p === null || ! $p['verses'] ) { $p = self::agent_load_passage( 'latin', $key, $chapter, $vfrom, $vto ); }
            $texts = [];
            foreach ( (array) ( $p['verses'] ?? [] ) as $v ) { $texts[] = $v['text']; }
            $text = trim( implode( ' ', $texts ) );
            if ( $text !== '' ) {
                if ( mb_strlen( $text ) > 280 ) { $text = rtrim( mb_substr( $text, 0, 277 ) ) . '…'; }
                $facts['description'] = $text;
                return $facts;
            }
        }
        if ( $chapter > 0 ) {
            $n = isset( $counts[ $key ][ $chapter - 1 ] ) ? (int) $counts[ $key ][ $chapter - 1 ] : 0;
            $facts['description'] = $name . ' ' . $chapter . ( $n ? ' — ' . $n . ' verses' : '' ) . ', ' . $pair . ', verse by verse.';
            return $facts;
        }
        $facts['description'] = $name . ( $chapters ? ' — ' . $chapters . ' chapters' : '' ) . ', ' . $pair . ', verse by verse.';
        return $facts;
    }

    private static function output_with_theme($title, $content_html, $context = '') {
        // Allow theme override templates (e.g., dwtheme/dwbible/...).
        // If a template is found, it is responsible for calling get_header/get_footer and echoing content.
        self::$current_page_title = is_string($title) ? $title : '';
        $context = is_string($context) ? $context : '';
        if ( function_exists('locate_template') ) {
            $dwbible_title   = $title;        // available to template
            $dwbible_content = $content_html; // available to template
            $dwbible_context = $context;      // 'index' | 'book'
            $templates = [];
            if ($context === 'book') {
                $templates = [ 'dwbible/single-book.php', 'dwbible/dwbible.php' ];
            } elseif ($context === 'index') {
                $templates = [ 'dwbible/index.php', 'dwbible/dwbible.php' ];
            } else {
                $templates = [ 'dwbible/dwbible.php' ];
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
        echo '<article class="dwbible-article">';
        echo '<header class="entry-header mb-3"><h1 class="entry-title">' . esc_html($title) . '</h1></header>';
        echo '<div class="entry-content">' . $content_html . '</div>';
        echo '</article>';
        echo '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    public static function register_settings() {
        // --- Special settings with custom sanitizers ---

        register_setting('dwbible_options', 'dwbible_slugs', [
            'type'              => 'string',
            'sanitize_callback' => function($val) {
                // Keep existing value when field not submitted (e.g. another settings tab)
                $fallback = 'bible,bibel,latin,spanish,french,italian';
                if (!isset($val) || $val === '') {
                    $current = get_option('dwbible_slugs', $fallback);
                    return is_string($current) && $current !== '' ? $current : $fallback;
                }
                if (!is_string($val)) return $fallback;
                $parts = array_filter(array_map('trim', explode(',', $val)));
                $known = ['bible', 'bibel', 'latin', 'spanish', 'french', 'italian'];
                $out = [];
                foreach ($parts as $p) { if (in_array($p, $known, true)) $out[] = $p; }
                if (empty($out)) $out = ['bible'];
                return implode(',', array_unique($out));
            },
            'default' => 'bible,bibel,latin,spanish,french,italian',
        ]);

        register_setting('dwbible_options', 'dwbible_autolink_base_url', [
            'type'              => 'string',
            'sanitize_callback' => function($v) {
                if (!isset($v)) return (string) get_option('dwbible_autolink_base_url', '');
                if (!is_string($v) || $v === '') return '';
                return esc_url_raw($v);
            },
            'default' => '',
        ]);

        register_setting('dwbible_options', 'dwbible_autolink_latin_first', [
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
            register_setting('dwbible_options', $option, [
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
            ['dwbible_og_enabled',               'string',  'toggle',     '1'],
            ['dwbible_og_width',                 'integer', 'int_min',    1200,               100],
            ['dwbible_og_height',                'integer', 'int_min',    630,                100],
            ['dwbible_og_bg_color',              'string',  'string',     '#111111'],
            ['dwbible_og_text_color',            'string',  'string',     '#ffffff'],
            ['dwbible_og_font_ttf',              'string',  'string',     '',                 null, true],
            ['dwbible_og_font_url',              'string',  'url',        '',                 null, true],
            ['dwbible_og_font_url_vern',         'string',  'url',        '',                 null, true],   // lighter-weight companion for the small vernacular line
            ['dwbible_og_font_size',             'integer', 'int_min',    40,                 8],   // back-compat fallback
            ['dwbible_og_font_size_main',        'integer', 'int_min',    40,                 8],
            ['dwbible_og_font_size_ref',         'integer', 'int_min',    40,                 8],
            ['dwbible_og_min_font_size_main',    'integer', 'int_min',    18,                 8],
            // OG: layout & spacing
            ['dwbible_og_padding_x',             'integer', 'int',        50],
            ['dwbible_og_padding_top',           'integer', 'int',        50],
            ['dwbible_og_padding_bottom',        'integer', 'int',        50],
            ['dwbible_og_min_gap',               'integer', 'int',        16],
            ['dwbible_og_line_height_main',      'string',  'string',     '1.35'],
            ['dwbible_og_line_height_vern',      'string',  'string',     ''],   // empty → derived (main + 0.2)
            // OG: icon & logo
            ['dwbible_og_icon_url',              'string',  'url',        '',                 null, true],
            ['dwbible_og_logo_side',             'string',  'enum',       'left',             ['left', 'right']],
            ['dwbible_og_logo_pad_adjust',       'integer', 'int_signed', 0],   // legacy single-axis
            ['dwbible_og_logo_pad_adjust_x',     'integer', 'int_signed', 0],
            ['dwbible_og_logo_pad_adjust_y',     'integer', 'int_signed', 0],
            ['dwbible_og_icon_max_w',            'integer', 'int_min',    160,                1],
            ['dwbible_og_background_image_url',  'string',  'string',     '',                 null, true],
            // OG: quotation marks & reference
            ['dwbible_og_quote_left',            'string',  'string',     '«'],
            ['dwbible_og_quote_right',           'string',  'string',     '»'],
            ['dwbible_og_ref_position',          'string',  'enum',       'bottom',           ['top', 'bottom']],
            ['dwbible_og_ref_align',             'string',  'enum',       'left',             ['left', 'right']],
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
        $wp_customize->add_section('dwbible_footer_section', [
            'title'       => __('Bible Footer CSS','dwbible'),
            'priority'    => 160,
            'description' => __('Custom CSS applied to the footer area rendered by The Bible plugin (.dwbible-footer, .dwbible-footer-title).','dwbible'),
        ]);
        // Setting: footer-specific CSS
        $wp_customize->add_setting('dwbible_footer_css', [
            'type'              => 'option',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => function( $css ) { return is_string($css) ? $css : ''; },
            'default'           => '',
            'transport'         => 'refresh',
        ]);
        // Control: textarea for CSS
        $wp_customize->add_control('dwbible_footer_css', [
            'section'  => 'dwbible_footer_section',
            'label'    => __('Custom CSS for Bible Footer','dwbible'),
            'type'     => 'textarea',
            'settings' => 'dwbible_footer_css',
        ]);
    }

    public static function admin_menu() {
        // Top-level menu
        add_menu_page(
            'LP Bible',
            'LP Bible',
            'manage_options',
            'dwbible',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-book-alt',
            36
        );

        // Sub-pages: main settings (default), OG image/layout, and per-Bible footers
        add_submenu_page(
            'dwbible',
            'The Bible',
            'The Bible',
            'manage_options',
            'dwbible',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'dwbible',
            'OG Image & Layout',
            'OG Image & Layout',
            'manage_options',
            'dwbible_og',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'dwbible',
            'Footers',
            'Footers',
            'manage_options',
            'dwbible_footers',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'dwbible',
            'Interlinear QA',
            'Interlinear QA',
            'manage_options',
            'dwbible_interlinear_qa',
            [ 'DwBible_QA', 'render_interlinear_qa_page' ]
        );

        add_submenu_page(
            'dwbible',
            'Sync Status',
            'Sync Status',
            'manage_options',
            'dwbible_sync',
            [ 'DwBible_Sync_Report', 'render_sync_status_page' ]
        );

        add_submenu_page(
            'dwbible',
            'AI Accessibility',
            'AI Accessibility',
            'manage_options',
            'dwbible_ai',
            [ 'DwBible_Admin_AI', 'render_page' ]
        );
    }

    public static function admin_enqueue($hook) {
        DwBible_Admin_Utils::admin_enqueue($hook);
    }

    public static function allow_font_uploads($mimes) {
        return DwBible_Admin_Utils::allow_font_uploads($mimes);
    }

    public static function allow_font_filetype($data, $file, $filename, $mimes, $real_mime) {
        return DwBible_Admin_Utils::allow_font_filetype($data, $file, $filename, $mimes, $real_mime);
    }

    public static function render_settings_page() {
        DwBible_Admin_Settings::render_settings_page();
    }

    public static function handle_export_bible_txt() {
        DwBible_Admin_Export::handle_export_bible_txt();
    }

    public static function print_custom_css() {
        DwBible_Front_Meta::print_custom_css();
    }

    public static function print_og_meta() {
        DwBible_Front_Meta::print_og_meta();
    }

    private static function render_footer_html() {
        return DwBible_Footer_Renderer::render_footer_html(self::data_root_dir(), self::html_dir());
    }

    /**
     * dwcache_ttl filter: return PHP_INT_MAX for bible URLs so cached pages
     * never expire by age — only manual cache flushes will regenerate them.
     */
    public static function infinite_ttl_for_bible( int $ttl, string $url ): int {
        $path = (string) parse_url( $url, PHP_URL_PATH );
        $slugs = self::get_registered_slugs();
        foreach ( $slugs as $slug ) {
            if ( strpos( $path, '/' . $slug . '/' ) !== false ) {
                return PHP_INT_MAX;
            }
        }
        return $ttl;
    }

    /** Return all registered bible slugs (e.g. ['bible','bibel','latin','latin-bibel',...]). */
    public static function get_registered_slugs(): array {
        $raw = get_option( 'dwbible_slugs', 'bible,bibel' );
        $base = array_filter( array_map( 'trim', explode( ',', is_string( $raw ) ? $raw : 'bible,bibel' ) ) );
        $combos = [];
        foreach ( $base as $a ) {
            foreach ( $base as $b ) {
                if ( $a !== $b ) { $combos[] = $a . '-' . $b; }
            }
        }
        return array_values( array_unique( array_merge( array_values( $base ), $combos ) ) );
    }
}

DwBible_Plugin::init();
