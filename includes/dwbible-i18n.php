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

/** Interlinear combo (Latin + vernacular) for a web language. */
function dwbible_i18n_combo_for_lang(string $lang): string {
    $m = ['en' => 'latin-bible', 'de' => 'latin-bibel', 'es' => 'latin-spanish', 'fr' => 'latin-french'];
    return $m[$lang] ?? 'latin-bible';
}

/** Web language for a dataset slug (single or combo); '' for latin-only / unknown. */
function dwbible_i18n_lang_for_slug(string $slug): string {
    $m = [
        'latin-bible' => 'en', 'bible'   => 'en',
        'latin-bibel' => 'de', 'bibel'   => 'de',
        'latin-spanish' => 'es', 'spanish' => 'es',
        'latin-french'  => 'fr', 'french'  => 'fr',
    ];
    return $m[$slug] ?? '';
}

/** The old Bible slugs (HTML) that must redirect to the prefix scheme. */
function dwbible_i18n_legacy_slug_re(): string {
    return '#^/(latin-bible|latin-bibel|latin-spanish|latin-french|bible|bibel|spanish|french|latin)(/.*|/?)$#';
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
    wp_safe_redirect(dwi18n_url_for($lang, '/bible' . $rest), $code);
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
        return $url; // machine endpoints keep their dataset slug
    }
    if (!preg_match(dwbible_i18n_legacy_slug_re(), $p, $m)) {
        return $url;
    }
    $lang = dwbible_i18n_lang_for_slug($m[1]);
    if ($lang === '') {
        return $url; // latin-only has no web URL form; leave untouched
    }
    $rest    = ($m[2] === '' || $m[2] === '/') ? '/' : $m[2];
    $newpath = ($rest === '/') ? '/' . $lang . '/bible/' : '/' . $lang . '/bible' . rtrim($rest, '/') . '/';

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
