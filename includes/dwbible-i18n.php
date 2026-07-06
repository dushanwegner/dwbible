<?php
/**
 * dwi18n bridge for the Bible.
 *
 * WHAT:   Make the Bible live under the path-prefix scheme /{lang}/bible/{book}/{ch}[:v]/ while keeping dwbible's
 *         internal "interlinear combo slug" model (latin-bible / latin-bibel / …) untouched.
 * WHY:    dwbible historically encoded the language IN the URL slug. The site-wide scheme puts language in the FIRST
 *         path segment and uses a single neutral /bible/ slug; Latin is the always-on interlinear constant and the
 *         /{lang}/ prefix chooses the vernacular. Rather than rewrite dwbible's renderers, we bridge at three edges:
 *           1. inbound  — on a prefixed Bible request, force the dataset combo from dwi18n_current();
 *           2. outbound — rewrite every /{combo|single}/… HTML link dwbible emits to /{lang}/bible/…;
 *           3. legacy   — 301 the old slug URLs (latin-bibel, bibel, …) to /{lang}/bible/… (latin-only → 302).
 * NOTE:   Machine endpoints (.json / llms.txt / sitemaps) keep their dataset slugs — the documented AI surface is
 *         unchanged. All of this is a no-op when dwi18n is inactive (dwbible falls back to its old slug behaviour).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Front-end translation catalog (/languages/dwbible-{locale}.mo) — the index
// category pills, filter UI, and chapter/book nav aria. WP's locale follows the
// URL language via dwi18n; load explicitly on init for the resolved locale
// (load_plugin_textdomain on after_setup_theme does not stick under WP 6.7+ JIT).
// English is the source language.
add_action('init', function () {
    $locale = determine_locale();
    if ($locale === 'en_US') {
        return;
    }
    $mofile = dirname(__DIR__) . '/languages/dwbible-' . $locale . '.mo';
    if (is_readable($mofile)) {
        load_textdomain('dwbible', $mofile, $locale);
    }
}, 0);

/** Interlinear combo (Latin + vernacular) for a web language. */
function dwbible_i18n_combo_for_lang(string $lang): string {
    $m = ['en' => 'latin-bible', 'de' => 'latin-bibel', 'es' => 'latin-spanish', 'fr' => 'latin-french', 'it' => 'latin-italian'];
    return $m[$lang] ?? 'latin-bible';
}

/**
 * Web language implied by a Bible SECTION slug the user might type. The user's word for "Bible"
 * (or the translation's name) hints the language — the canonical URL is /{lang}/biblia/. Covers:
 * the native words (bibel de · bible en/fr · bibbia it · spanish/french/italian datasets), the
 * translation names (menge de · douay en · straubinger es · crampon fr · martini it), and the
 * Latin+vernacular combos. Returns '' for the canonical 'biblia' + 'latin' (→ negotiate: cookie,
 * browser, English). 'bible' → en and 'french' → fr are the pragmatic default for the en/fr "bible"
 * collision; a cookie/browser preference still wins on the negotiated hops.
 */
function dwbible_i18n_lang_for_slug(string $slug): string {
    $m = [
        'latin-bible' => 'en', 'bible'   => 'en', 'douay'      => 'en',
        'latin-bibel' => 'de', 'bibel'   => 'de', 'menge'      => 'de',
        'latin-spanish' => 'es', 'spanish' => 'es', 'straubinger' => 'es',
        'latin-french'  => 'fr', 'french'  => 'fr', 'crampon'   => 'fr',
        'latin-italian' => 'it', 'italian' => 'it', 'bibbia'    => 'it', 'martini' => 'it',
    ];
    return $m[$slug] ?? '';
}

/**
 * Bible SECTION slugs that redirect to the canonical /{lang}/biblia/ scheme — the canonical
 * 'biblia' itself (locale-less → negotiate a language), every dataset slug + combo, the native
 * words, and the translation names. Ordered longest-combo-first so the alternation is greedy-safe.
 */
function dwbible_i18n_legacy_slug_re(): string {
    return '#^/(latin-bible|latin-bibel|latin-spanish|latin-french|latin-italian|biblia|bible|bibel|bibbia|spanish|french|italian|latin|menge|douay|straubinger|crampon|martini)(/.*|/?)$#';
}

/* ── 3. Legacy redirect: raw old-slug HTML URLs → /{lang}/bible/… ─────────────────────────────────────────────
 * Runs before dwi18n's negotiated 302 (priority 0) so the move is one permanent hop. Machine endpoints (.json /
 * .txt / .xml) are left alone — they keep their dataset slug. Latin-only has no web language → negotiated 302.
 */
add_filter('do_parse_request', function ($do, $wp = null, $extra = null) {
    if (!function_exists('dwi18n_url_for') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return $do;
    }
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#\.(json|xml|txt)$#i', $path)) {
        return $do;
    }
    if (!preg_match(dwbible_i18n_legacy_slug_re(), $path, $m)) {
        return $do;
    }
    $slug = $m[1];
    $rest = ($m[2] === '' || $m[2] === '/') ? '/' : $m[2];
    $lang = dwbible_i18n_lang_for_slug($slug);
    if ($lang === '') {
        // latin-only: no web language → negotiate (302, visitor-dependent).
        $lang = function_exists('dwi18n_negotiate_lang') ? dwi18n_negotiate_lang() : 'en';
        $code = 302;
    } else {
        $code = 301;
    }
    wp_safe_redirect(dwi18n_url_for($lang, '/' . DwBible_Plugin::CANONICAL_SECTION . $rest), $code);
    exit;
}, -5, 3);

/* ── 1. Inbound: on a prefixed Bible request, pick the dataset combo from the language prefix ───────────────────
 * After dwi18n peels /de/, the request is /bible/…; the existing 'bible' rule sets dwbible_slug='bible'. Swap it to
 * the Latin+vernacular combo for the current language so the interlinear renderer shows the right pair and dwbible's
 * single→combo redirect doesn't fire. Machine formats keep their requested dataset slug.
 */
add_filter('request', function ($qv) {
    if (!function_exists('dwi18n_current') || empty($GLOBALS['dwi18n_had_prefix'])) {
        return $qv;
    }
    $is_bible = !empty($qv['dwbible']) || isset($qv['dwbible_slug']);
    $is_machine = !empty($qv['dwbible_format']) || !empty($qv['dwbible_sitemap']) || !empty($qv['dwbible_og']) || !empty($qv['dwbible_selftest']);
    if ($is_bible && !$is_machine) {
        $qv['dwbible_slug'] = dwbible_i18n_combo_for_lang(dwi18n_current());
    }
    return $qv;
}, 20);

/* ── 2. Outbound: rewrite dwbible's /{combo|single}/… HTML links to /{lang}/bible/… ────────────────────────────
 * dwbible builds all its HTML URLs as home_url('/'.$slug.'/…'); this turns them into the canonical prefix form (the
 * language taken from the slug, so the in-page edition switcher's links each point at their own language). Runs at
 * priority 9 — before dwi18n's prefix filter (10), which then sees an already-prefixed URL and leaves it.
 */
add_filter('home_url', function ($url, $path, $orig_scheme, $blog_id) {
    if (!function_exists('dwi18n_current') || is_admin()) {
        return $url;
    }
    $parsed = parse_url($url);
    if (!isset($parsed['host'])) {
        return $url;
    }
    $p = $parsed['path'] ?? '/';
    if (preg_match('#\.(json|xml|txt|rss|atom)$#i', $p)) {
        // Machine endpoints keep their dataset slug — EXCEPT a combo .json, which has no file (the JSON API is
        // single-language). Map it to the vernacular single dataset so the page's json-alternate link resolves.
        $combo_single = ['latin-bible' => 'bible', 'latin-bibel' => 'bibel', 'latin-spanish' => 'spanish', 'latin-french' => 'french', 'latin-italian' => 'italian'];
        if (preg_match('#^/(latin-bible|latin-bibel|latin-spanish|latin-french|latin-italian)(/.*)$#', $p, $mm)) {
            $fixed = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $fixed .= ':' . $parsed['port'];
            }
            $fixed .= '/' . $combo_single[$mm[1]] . $mm[2];
            if (isset($parsed['query'])) {
                $fixed .= '?' . $parsed['query'];
            }
            return $fixed;
        }
        return $url;
    }
    if (!preg_match(dwbible_i18n_legacy_slug_re(), $p, $m)) {
        return $url;
    }
    $lang = dwbible_i18n_lang_for_slug($m[1]);
    if ($lang === '') {
        return $url; // latin-only has no web URL form; leave untouched
    }
    $rest    = ($m[2] === '' || $m[2] === '/') ? '/' : $m[2];
    $sec = '/' . DwBible_Plugin::CANONICAL_SECTION;
    $newpath = ($rest === '/') ? '/' . $lang . $sec . '/' : '/' . $lang . $sec . rtrim($rest, '/') . '/';

    $res = $parsed['scheme'] . '://' . $parsed['host'];
    if (isset($parsed['port'])) {
        $res .= ':' . $parsed['port'];
    }
    $res .= $newpath;
    if (isset($parsed['query'])) {
        $res .= '?' . $parsed['query'];
    }
    if (isset($parsed['fragment'])) {
        $res .= '#' . $parsed['fragment'];
    }
    return $res;
}, 9, 4);
