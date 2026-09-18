<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_Router_Trait {
    public static function handle_request() {
        // Main request router; will be refactored later.

        // ── JSON API (AI access) ─────────────────────────────────────────
        $format = get_query_var( self::QV_FORMAT );
        if ( $format === 'json' ) {
            self::serve_json_file();
            exit;
        }
        if ( $format === 'bible-index' ) {
            self::serve_unified_index();
            exit;
        }
        if ( $format === 'bible-books' ) {
            self::serve_book_vocabulary();
            exit;
        }
        if ( $format === 'bible-ref' ) {
            self::serve_reference_json();
            exit;
        }
        if ( $format === 'bible-search' ) {
            self::serve_search_json();
            exit;
        }

        $selftest = get_query_var(self::QV_SELFTEST);
        if (!empty($selftest)) {
            self::render_selftest();
            exit;
        }

        // Serve Open Graph image when requested
        $og = get_query_var(self::QV_OG);
        if ($og) {
            DwBible_OG_Image::render();
            exit;
        }

        // Serve the verse's QR code (the pryr.es short link) when requested.
        // Sits beside the OG image deliberately: same trigger shape, same
        // point in the router, and both are pictures OF this verse — one to
        // post, one to print.
        $qr = get_query_var(self::QV_QR);
        if ($qr) {
            DwBible_QR_Image::render();
            exit;
        }

        // Sitemaps must be checked before book — per-book sitemaps set both
        // QV_SITEMAP and QV_BOOK, so sitemap takes priority.
        $sitemap = get_query_var(self::QV_SITEMAP);
        if ($sitemap) {
            self::handle_sitemap();
            exit;
        }

        // HTML pages: single-language slugs always redirect to the Latin
        // interlinear combo so users see Latin alongside their language.
        // JSON, sitemap, OG-image and selftest paths exit above and never
        // reach this redirect.
        if (self::maybe_redirect_to_interlinear()) {
            return;
        }

        $book = get_query_var(self::QV_BOOK);
        if ($book) {
            if (self::maybe_redirect_external()) return;
            self::render_bible_page();
            exit;
        }
        $flag = get_query_var(self::QV_FLAG);
        if ($flag) {
            if (self::maybe_redirect_external()) return;
            self::render_index();
            exit; // prevent WP from continuing (e.g. home.php rendering widgets after </body>)
        }
    }

    /**
     * Redirect single-language HTML pages to the Latin interlinear combo.
     *
     *   /bible/...  → /latin-bible/...   (Latin + English)
     *   /bibel/...  → /latin-bibel/...   (Latin + German)
     *   /latin/...  → unchanged          (already Latin-only)
     *
     * The HTML pages are intentionally always interlinear so users see the
     * Latin original alongside their target language. The JSON API stays
     * single-language: /bible/genesis/1.json returns Douay-Rheims only,
     * /bibel/genesis/1.json returns Menge only — that path is dispatched
     * by handle_request() before this redirect ever runs.
     *
     * @return bool True if a redirect was issued (caller should stop), false otherwise.
     */
    private static function maybe_redirect_to_interlinear() {
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') return false;

        $map = [
            'bible'   => 'latin-bible',
            'bibel'   => 'latin-bibel',
            'spanish' => 'latin-spanish',
            'french'  => 'latin-french',
            'italian' => 'latin-italian',
        ];
        if (!isset($map[$slug])) return false;
        $target = $map[$slug];

        // A genuine .json request always sets QV_FORMAT and exits above, before
        // this redirect ever runs — but a ".json" guess that matches no JSON
        // rewrite rule (a bare /bible/{book}.json with no chapter, an invalid
        // book slug) falls through to the generic HTML book rule instead, with
        // the whole "{book}.json" string captured as the book slug and no
        // format set. Treating that as HTML and substituting the slug builds
        // .../latin-bible/{book}.json — still ending in .json, which the
        // dwi18n normalizer then bounces straight back to /bible/{book}.json,
        // forever. Bail here and let it fall through to book resolution below,
        // which 404s an unresolvable book instead of looping.
        $path_only = isset($_SERVER['REQUEST_URI']) ? strtok((string) $_SERVER['REQUEST_URI'], '?') : '';
        if (is_string($path_only) && preg_match('#\.json$#i', $path_only)) {
            return false;
        }

        // Preserve the rest of the URL after the slug.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($uri === '') return false;
        $path = strtok($uri, '?');
        $qs   = parse_url($uri, PHP_URL_QUERY);

        // Replace only the leading /{slug}/ (or trailing /{slug}) with /{target}/.
        $new_path = preg_replace(
            '#^/' . preg_quote($slug, '#') . '(/|$)#',
            '/' . $target . '$1',
            (string) $path
        );
        if (!is_string($new_path) || $new_path === '' || $new_path === $path) {
            return false;
        }

        // ?dwbible_vfrom=28 on a chapter URL MEANS /{ch}:28/ — fold it into the
        // path and drop it from the query, so the hop lands on the canonical
        // verse address instead of carrying the parameters to the final URL.
        if (is_string($qs) && $qs !== '' && strpos($qs, 'dwbible_v') !== false) {
            parse_str($qs, $qp);
            $vf = (int) ($qp['dwbible_vfrom'] ?? 0);
            $vt = (int) ($qp['dwbible_vto'] ?? 0);
            unset($qp['dwbible_vfrom'], $qp['dwbible_vto']);
            $qs = http_build_query($qp);
            if ($vf > 0 && preg_match('#^(/[^/]+/[^/:,]+/[0-9]+)/?$#', $new_path, $mm)) {
                $new_path = $mm[1] . ':' . $vf . ($vt > $vf ? '-' . $vt : '') . '/';
            }
        }

        // HTML routes use trailing slashes. Pre-append it here so the redirect
        // lands on the canonical URL in a single hop. Without this, WordPress's
        // redirect_canonical hook (priority 10) overrides our priority-1
        // wp_redirect() with its own trailing-slash redirect — leaving the
        // browser to chain through /bible/x → /bible/x/ → /latin-bible/x/.
        // Cloudflare and some browser caches handle that chain poorly (the user
        // sees a white page or what looks like a redirect loop). One-hop fixes it.
        if (substr($new_path, -1) !== '/') {
            $new_path .= '/';
        }

        $url = home_url($new_path);
        if (is_string($qs) && $qs !== '') {
            $url .= '?' . $qs;
        }

        wp_redirect($url, 301);
        // Must exit; otherwise redirect_canonical at priority 10 will overwrite
        // our Location header with its own trailing-slash variant of the original
        // URL, breaking the slug remap. (See note above.)
        exit;
    }

    /**
     * Redirect user-facing bible pages to an external domain if configured.
     *
     * @return bool True if a redirect was issued, false otherwise.
     */
    private static function maybe_redirect_external() {
        $base_url = get_option('dwbible_autolink_base_url', '');
        if (!is_string($base_url) || $base_url === '') {
            return false;
        }
        $base_url = rtrim($base_url, '/');

        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $book = get_query_var(self::QV_BOOK);
        $ch   = get_query_var(self::QV_CHAPTER);
        $vf   = get_query_var(self::QV_VFROM);
        $vt   = get_query_var(self::QV_VTO);

        $path = '/' . trim($slug, '/') . '/';
        if (is_string($book) && $book !== '') {
            $path .= trim($book, '/') . '/';
            if ($ch) {
                $path .= $ch;
                if ($vf) {
                    $path .= ':' . $vf;
                    if ($vt && (int)$vt > (int)$vf) {
                        $path .= '-' . $vt;
                    }
                }
            }
        }

        $external_url = $base_url . $path;
        wp_redirect($external_url, 301);
        exit;
    }

    public static function render_bible_page() {
        // ?format=json is a courtesy alias for the .json machine URL — 301 to
        // the JSON API rather than answering a JSON request with HTML. The
        // JSON API resolves any inbound book slug itself (abbreviations,
        // vernacular names, Latin canonical), so the raw query vars suffice.
        if ((($_GET['format'] ?? '') === 'json') && function_exists('dwbible_i18n_json_dataset_for_slug')) {
            $ds    = dwbible_i18n_json_dataset_for_slug((string) get_query_var(self::QV_SLUG));
            $jrest = '/index.json';
            $jb    = get_query_var(self::QV_BOOK);
            if (is_string($jb) && $jb !== '') {
                $jch = get_query_var(self::QV_CHAPTER);
                $jvf = get_query_var(self::QV_VFROM);
                $jvt = get_query_var(self::QV_VTO);
                if ($jch && $jvf) {
                    $jrest = '/' . $jb . '/' . $jch . '/' . (int) $jvf . (($jvt && (int) $jvt > (int) $jvf) ? '-' . (int) $jvt : '') . '.json';
                } elseif ($jch) {
                    $jrest = '/' . $jb . '/' . $jch . '.json';
                } else {
                    $jrest = '/' . $jb . '/index.json';
                }
            }
            wp_redirect(home_url('/' . $ds . $jrest), 301);
            exit;
        }

        $book_slug = get_query_var(self::QV_BOOK);
        if (!$book_slug) {
            self::render_index();
            return;
        }

        // Resolve canonical book slug for the current language dataset
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        set_query_var(self::QV_SLUG, $slug);

        // For combo slugs (e.g. latin-bibel) we used to strip down to the first
        // part, but that bypassed the resolver's own combo-recursion and broke
        // dataset-specific book names like "psalmen" or "matthaeus" inside any
        // combo whose first part isn't German. Pass the full slug instead — the
        // resolver tries each dataset in turn and returns the first hit.
        // Canonical Bible URLs are the LATIN book name ("Latin Prayer"): resolve any inbound slug
        // (Latin, the internal/English key, or a vernacular name) to the internal data key, then the
        // canonical URL slug is that key's Latin form. So /bible/acts/ + /bible/apostelgeschichte/
        // both 301 to /bible/actus-apostolorum/. Unknown slugs 404.
        $internal_key = self::internal_key_from_any_book($book_slug, $slug);
        if ($internal_key === null) {
            self::render_404();
            exit;
        }
        $canonical = DwBible_Plugin::latin_slug_for_key($internal_key);

        // MALACHIAS 4 — the one book whose chapter division differs from the
        // printed Clementine. Our data (and dwlectionary, and the Nova Vulgata)
        // carry the Elijah prophecy as 3:19-24; every printed Vulgate and every
        // Douay-Rheims numbers the same six verses 4:1-6. A reader holding a 1962
        // missal therefore asks for a chapter that does not exist here.
        //
        // Rather than 404 them, translate the citation: chapter 4 verse v is our
        // 3:(v+18). Done by rewriting the query vars BEFORE the canonical URL is
        // built, so the 301 below fires on its own — no second redirect path to
        // keep in step with this one. A verse past 4:6 maps past 3:24 and 404s,
        // which is correct: it does not exist in either numbering.
        if ($canonical === 'malachias' && (int) get_query_var(self::QV_CHAPTER) === 4) {
            $mal_vf = (int) get_query_var(self::QV_VFROM);
            $mal_vt = (int) get_query_var(self::QV_VTO);
            set_query_var(self::QV_CHAPTER, 3);
            // A bare /malachias/4/ lands on 3:19, where that chapter begins —
            // the content the reader asked for, not the top of our chapter 3.
            set_query_var(self::QV_VFROM, $mal_vf ? $mal_vf + 18 : 19);
            if ($mal_vt) {
                set_query_var(self::QV_VTO, $mal_vt + 18);
            }
        }

        // Build the FULL canonical URL — /{lang}/biblia/{latin-book}/{ch}:{v} — and 301 whenever the
        // current request differs in ANY way: the section word (bible/bibel/… → biblia), the book
        // slug (English/vernacular → Latin), or the verse separator (/ or , → :). The home_url filter
        // rewrites the internal /{combo}/… we build here into the /{lang}/biblia/… prefix form.
        $ch = get_query_var(self::QV_CHAPTER);
        $vf = get_query_var(self::QV_VFROM);
        $vt = get_query_var(self::QV_VTO);

        $path = '/' . trim($slug, '/') . '/' . $canonical . '/';
        if ($ch) {
            $path .= $ch;
            if ($vf) {
                $path .= ':' . $vf;
                if ($vt && $vt > $vf) {
                    $path .= '-' . $vt;
                }
            }
        }

        // Compare PATHS only and carry the query string across the redirect:
        // including the query in the comparison made every parameterized URL
        // (?utm_…, etc.) 301 to itself minus the query — stripping legitimate
        // parameters and costing robots an extra hop.
        $canonical_url = home_url($path);
        $request_parts = explode('?', add_query_arg([]), 2);
        $current       = home_url($request_parts[0]);
        $qs            = isset($request_parts[1]) ? (string) $request_parts[1] : '';
        // The verse-highlight parameters are already IN the canonical path as
        // /{ch}:{v}, so they leave the query rather than riding along as junk.
        if ($qs !== '' && strpos($qs, 'dwbible_v') !== false) {
            parse_str($qs, $qp);
            unset($qp['dwbible_vfrom'], $qp['dwbible_vto']);
            $qs = http_build_query($qp);
        }
        if (trailingslashit($canonical_url) !== trailingslashit($current)) {
            wp_redirect($canonical_url . ($qs !== '' ? '?' . $qs : ''), 301);
            exit;
        }
        $book_slug = $canonical;
        set_query_var(self::QV_BOOK, $book_slug);

        // Normalise the chapter/verse separator to the canonical COLON form. Accept the slash form
        // (/luke/24/13-35) and the comma form (German/Latin citation "1,2") and 301 them to colon.
        // e.g. /bible/luke/24/13-35 or /bible/luke/24,13-35 → /bible/luke/24:13-35
        $ch = get_query_var(self::QV_CHAPTER);
        $vf = get_query_var(self::QV_VFROM);
        if ($ch && $vf) {
            $uri = strtok(isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '', '?');
            // Canonical is /{ch}:[digit]. If the URI does NOT use a colon there (it used a slash or a
            // comma), redirect to the colon form.
            $has_colon = preg_match('#/' . preg_quote($ch, '#') . ':[0-9]#', $uri);
            if (!$has_colon) {
                $vt   = get_query_var(self::QV_VTO);
                $path = '/' . trim($slug, '/') . '/' . $book_slug . '/' . $ch . ':' . $vf;
                if ($vt && (int)$vt > (int)$vf) {
                    $path .= '-' . (int)$vt;
                }
                wp_redirect(home_url($path), 301);
                exit;
            }
        }

        // Phase 6 (claude.ai/design 2026-04-28): when no chapter is selected,
        // render the book-level table of contents instead of falling through
        // to the chapter-1 default. Verse-targeting URLs (e.g. /book/3:16)
        // set $vf and never reach this branch — they always go to the chapter
        // reader. Also bypass the TOC when an external-redirect base is set
        // so we don't paint a TOC just to redirect away.
        $ch = get_query_var(self::QV_CHAPTER);
        if ( ! $ch && ! get_query_var(self::QV_VFROM) ) {
            self::render_book_toc( $book_slug, $slug );
            exit;
        }

        // Always use multilingual renderer (1 dataset is the special case)
        self::render_multilingual_book($book_slug, $slug);
        exit; // prevent WP from continuing
    }

    /**
     * Resolve ANY written form of a book to its internal data key.
     *
     * One answer for "what book is this?", wherever the question arrives from:
     * a URL segment (/bible/apostelgeschichte/) or a reader's typed query
     * (the drawer's Bible search → `?q=`). Latin, the internal/English key, a
     * vernacular name, an abbreviation from ANY language ("apg", "joh", "1kor",
     * "mt") — the dataset-specific resolver is tried for the request's own
     * combo first, then for every web dataset, so a name resolves regardless of
     * the URL's locale (max "intuitive typing" flexibility). First hit wins.
     *
     * @param string $raw_book Book as written (slug, name, or abbreviation).
     * @param string $slug     Dataset/combo slug of the request ('bible', 'latin-bibel', …).
     * @return string|null Internal book key, or null when it is not a book we have.
     */
    private static function internal_key_from_any_book($raw_book, $slug) {
        if (!is_string($raw_book) || $raw_book === '') { return null; }

        $key = DwBible_Plugin::key_from_any_book_slug($raw_book);
        if ($key !== null) { return $key; }

        $legacy = self::canonical_book_slug_from_url($raw_book, $slug);
        if (!$legacy) {
            foreach (['bibel', 'bible', 'spanish', 'french', 'italian', 'latin'] as $ds) {
                $legacy = self::canonical_book_slug_from_url($raw_book, $ds);
                if ($legacy) { break; }
            }
        }
        // A legacy slug is only useful if it names a real book.
        if ($legacy) {
            $key = DwBible_Plugin::key_from_any_book_slug($legacy);
            if ($key !== null) { return $key; }
        }

        // LAST RESORT: a name this site itself prints as a citation. The
        // vernacular citation names are a different set from the URL slugs and
        // the search vocabulary, so quoting an answer's own citation back used
        // to fail for Italian and for two accented Spanish names.
        //
        // This runs even when a legacy slug WAS found, because a found slug is
        // not necessarily a key: the Spanish and Italian datasets name Esther's
        // directory "ester", which matches here and then maps to no key at all.
        // "Ester" therefore used to come back as the key "ester" — a book that
        // does not exist — and a search for it answered 0 hits, no error, with
        // the raw input echoed back as the book's name.
        $cited = self::key_from_citation_name($raw_book);
        if ($cited !== null) { return $cited; }

        // A NUMBERED BOOK WRITTEN WITH A SPACE — "1 Kor", "1 Co", "2 Tes": digit,
        // space, abbreviation, the form German, French and Spanish Bibles print.
        // The tables hold "1Kor" and "1. Kor", so 39 such abbreviations answered
        // "no book recognised" (tick 104). Retried compact, and ONLY when nothing
        // above recognised the spelling at all: a spaced form one table already
        // knows keeps the meaning it has. That is deliberate — "1 Re" is 1 Samuel
        // in Martini's naming and 3 Kings in modern Italian and Spanish, and which
        // one this site means is an open editorial decision (dwbibledata#17), not
        // something a normalisation step may settle by reaching a different table.
        if (!$legacy) {
            $spelled = trim($raw_book);

            // A ROMAN NUMERAL — "III Reg", "II Mach", "I Petr": how the Vulgate, the
            // Missal and Denzinger cite a numbered book. The tables hold Arabic
            // numerals, so 155 of the 208 Roman forms of their numbered abbreviations
            // answered "no book recognised" (tick 122). Read as its Arabic twin. Samuel
            // and Kings follow the tables' MODERN convention like every other form
            // ("I Kings" is 1 Kings, the Vulgate's 3 Regum — dwbibledata#17, DW
            // 2026-09-17), so no numeral is special.
            if (preg_match('/^(IV|I{1,3})(?:\.\s*|\s+)(?=\p{L})(.+)$/iu', $spelled, $m)) {
                $n = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4][strtolower($m[1])];
                return self::internal_key_from_any_book($n . ' ' . $m[2], $slug);
            }

            $compact = (string) preg_replace('/^(\d+)\.?\s+(?=\D)/u', '$1', $spelled);
            if ($compact !== $spelled) {
                return self::internal_key_from_any_book($compact, $slug);
            }
        }

        // Nothing named a book. A legacy slug that maps to no key is returned
        // as it always has been, for callers that treat it as an identifier.
        return $legacy ?: null;
    }

    /**
     * `?q=` — a reader's typed Bible search, answered before the index renders.
     *
     * The query comes from a Bible search box (the drawer's Bible row; a shared
     * link). It is not a new kind of lookup: the citation half is split off by
     * the shared grammar, the book half goes through the SAME resolver a typed
     * URL uses, and the answer is a redirect to the canonical page — so ranges,
     * the Malachias 4 shim and every abbreviation keep working with no second
     * implementation.
     *
     * Unresolvable (junk, or a prefix that fits several books) is NOT a 404 and
     * not a redirect: the caller renders the index, whose filter opens already
     * filled with `q` — the same question answered as a list of candidates.
     *
     * @return bool True if a redirect was issued (the caller must stop).
     */
    private static function maybe_redirect_query() {
        $raw = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (trim($raw) === '') { return false; }

        // A lookup is not a page: never store this response. `q` is also part of
        // dwcache's key (see the filter in dwbible.php) so this request can never
        // be ANSWERED with the plain index either — the two halves of the same
        // bug, which is exactly how the QR image once got stored as a verse page.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();

        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }

        // A printed form ("Mt 5,1-12a", "Joh 3,16f") lands on its verses here too.
        $parsed = DwBible_Reference::parse_query(DwBible_Reference::normalize_printed_forms($raw)['query']);
        $key    = self::internal_key_from_any_book($parsed['name'], $slug);
        if ($key === null) { return false; }

        // Only as far as the book can actually be followed. "John 3:16666" names
        // a real book and no such verse, so it goes to John 3; "John 99" to
        // John. The rule and its twin: assets/dwbible-search.js → fit(), which
        // is what the index rows and the rail's field show as you type. A link
        // that promises a verse the book has not got is a link to nowhere.
        $path = '/' . trim($slug, '/') . '/' . DwBible_Plugin::latin_slug_for_key($key) . '/';
        $ref  = self::fit_reference_to_book($key, $parsed['ref']);
        if ($ref !== '') { $path .= $ref . '/'; }

        // 302, not 301: the destination of a query is a lookup RESULT, not a
        // permanent alias of this URL — the same text may resolve elsewhere as
        // the data grows, and no cache should outlive that.
        wp_redirect(home_url($path), 302);
        exit;
    }

    /**
     * The largest part of a citation this book can actually be sent to.
     *
     * The PHP twin of assets/dwbible-search.js → fit(): a verse that does not
     * exist falls back to its chapter, a chapter that does not exist to the
     * book, and a half-finished range end is dropped. Two runtimes, one rule —
     * so what a reader is shown while typing is where they land on Enter.
     *
     * @param string $key The book's canonical key.
     * @param string $ref 'ch[:v[-v]]' as typed ('' for none).
     * @return string The ref to follow, '' for "just the book".
     */
    private static function fit_reference_to_book($key, $ref) {
        if ( ! is_string($ref) || $ref === '' ) { return ''; }
        if ( ! preg_match('/^(\d+)(?::(\d+)(?:-(\d+))?)?$/', $ref, $m) ) { return ''; }

        $ch = (int) $m[1];
        $v  = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
        $vt = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

        $counts = DwBible_Plugin::verse_counts_by_book();
        $book   = isset($counts[$key]) ? $counts[$key] : [];

        // A book we have no lengths for is trusted, exactly as in the browser.
        if ( ! $book ) { return $ref; }
        if ( $ch < 1 || $ch > count($book) ) { return ''; }

        $n = (int) $book[$ch - 1];
        if ( $v === null || $n <= 0 ) { return (string) $ch; }
        if ( $v < 1 || $v > $n ) { return (string) $ch; }
        if ( $vt === null || $vt > $n || $vt < $v ) { return $ch . ':' . $v; }
        return $ch . ':' . $v . '-' . $vt;
    }

    /**
     * Resolve a raw URL book segment to a canonical dataset book slug.
     *
     * FALLBACK HIERARCHY
     * ==================
     * Each level is tried in order; the first match wins. Levels are progressively
     * more lenient to handle user-entered URLs (typos, language mixing, shortcuts).
     * Intentionally diverges from "exact match only" to be helpful for users.
     *
     * L1  Direct slug    — slugify($raw_book) checked against the dataset index.
     *                      Handles: /latin/genesis/, accented names via WP transliteration,
     *                      any slug that exists verbatim in the index CSV.
     *
     * L2  Exact abbr key — after URL-decode + hyphens-to-spaces + trim + trailing-dot strip.
     *                      Handles: /bible/Matt/, /bible/Jn/, /bibel/Mt/
     *
     * L3  Period prefix  — "1. Cor" → "1 Cor" (digit-dot-space before book name).
     *                      Handles: /bible/1.Cor/, /bibel/1.Kor/
     *
     * L4  Compact prefix — "1cor" → "1 cor" (digit directly touching letter, no space).
     *                      Handles: /bible/1cor/, /latin/2tim/
     *                      Risk: near-zero — no real book name looks like this.
     *
     * L5  Cross-dataset  — try English 'bible' abbreviation map as fallback, then
     *                      translate the result to the target dataset via book_map.json.
     *                      Handles: /latin/Matthew/ (user uses English name on Latin page).
     *                      Risk: wrong book if the English abbreviation is ambiguous.
     *                      Mitigation: only applied after all dataset-specific lookups fail.
     *
     * L6  Prefix match   — key must be 3+ chars, single word, and must uniquely prefix
     *                      exactly one distinct book in the abbreviation map.
     *                      Handles: /bible/Gen/ → genesis, /bible/Apoc/ → apocalypse
     *                      Risk: silently picks the first match for ambiguous prefixes.
     *                      Mitigation: bail out immediately if more than one book matches.
     *
     * @param string $raw_book Raw book segment from the URL.
     * @param string $slug     Dataset slug ('bible', 'bibel', 'latin', or combo 'latin-bible').
     * @return string|null Canonical book slug for the dataset, or null if unresolvable.
     */
    private static function canonical_book_slug_from_url($raw_book, $slug) {
        if (!is_string($raw_book) || $raw_book === '') return null;

        // Combo slugs (e.g. latin-bible, latin-bibel): try each dataset in order.
        if (strpos($slug, '-') !== false) {
            $parts = array_values(array_filter(array_map('trim', explode('-', $slug))));
            foreach ($parts as $part) {
                $result = self::canonical_book_slug_from_url($raw_book, $part);
                if ($result !== null) return $result;
            }
            return null;
        }

        $known_single = ['bible', 'bibel', 'latin', 'spanish', 'french', 'italian'];
        if (!in_array($slug, $known_single, true)) {
            $slug = 'bible';
        }

        // Load the target dataset's index into self::$slug_map.
        // QV_SLUG must match so load_index() picks the right CSV.
        $prev_slug = get_query_var(self::QV_SLUG);
        set_query_var(self::QV_SLUG, $slug);
        self::load_index();
        set_query_var(self::QV_SLUG, $prev_slug);

        // ── L1: Direct slug match ─────────────────────────────────────────────
        // WP's sanitize_title handles accents, em-dashes, and common Unicode chars.
        $direct = self::slugify($raw_book);
        if ($direct !== '' && isset(self::$slug_map[$direct])) {
            return $direct;
        }

        // Normalise raw input once for all abbreviation lookups (L2–L6).
        $decoded = urldecode(str_replace('-', ' ', $raw_book));
        $norm    = (string) preg_replace('/\s+/u', ' ', trim((string) preg_replace('/\.\s*$/u', '', $decoded)));
        $key     = mb_strtolower($norm, 'UTF-8');

        $abbr = self::get_abbreviation_map($slug);

        // Helper: look up a key in $abbr and return the slugified book slug, or null.
        $from_abbr = static function (string $k) use ($abbr): ?string {
            if ($k === '' || empty($abbr) || !isset($abbr[$k])) return null;
            $s = DwBible_Plugin::slugify($abbr[$k]);
            return $s !== '' ? $s : null;
        };

        if (!empty($abbr) && $key !== '') {
            // ── L2: Exact abbreviation key ────────────────────────────────────
            if (($result = $from_abbr($key)) !== null) return $result;

            // ── L3: Period-prefix normalisation "1. X" → "1 X" ───────────────
            $key3 = mb_strtolower(
                (string) preg_replace('/\s+/u', ' ', trim((string) preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm))),
                'UTF-8'
            );
            if ($key3 !== $key && ($result = $from_abbr($key3)) !== null) return $result;

            // ── L4: Compact numeric prefix "1cor" → "1 cor" ───────────────────
            $key4 = (string) preg_replace('/^(\d+)([a-z])/u', '$1 $2', $key);
            if ($key4 !== $key && ($result = $from_abbr($key4)) !== null) return $result;

        } elseif (empty($abbr)) {
            // No abbreviation map for this dataset (e.g. latin).
            // Try resolving the raw slug via book_map.json canonical keys.
            $canonical_key = self::slugify($raw_book);
            if ($canonical_key !== '') {
                $mapped_short = self::resolve_book_for_dataset($canonical_key, $slug);
                if (is_string($mapped_short) && $mapped_short !== '') {
                    $mapped_slug = self::slugify($mapped_short);
                    if ($mapped_slug !== '' && isset(self::$slug_map[$mapped_slug])) {
                        return $mapped_slug;
                    }
                }
            }
        }

        // ── L5: Cross-dataset fallback to English 'bible' abbreviations ───────
        // Useful when a user types /latin/Matthew/ or /latin/1cor/ —
        // the English abbr map resolves the name, then book_map.json translates it
        // back to the canonical slug for the target dataset.
        if ($slug !== 'bible' && $key !== '') {
            $bible_abbr = self::get_abbreviation_map('bible');
            if (!empty($bible_abbr)) {
                // Build normalised key variants to try against the English map.
                $try_keys = array_values(array_unique(array_filter([
                    $key,
                    // L3 variant
                    mb_strtolower(
                        (string) preg_replace('/\s+/u', ' ', trim((string) preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm))),
                        'UTF-8'
                    ),
                    // L4 variant
                    (string) preg_replace('/^(\d+)([a-z])/u', '$1 $2', $key),
                ])));
                foreach ($try_keys as $try_key) {
                    if (!isset($bible_abbr[$try_key])) continue;
                    $en_short = $bible_abbr[$try_key];
                    // Translate English short name to the target dataset via book_map.json.
                    $mapped = self::resolve_book_for_dataset($en_short, $slug);
                    if (is_string($mapped) && $mapped !== '') {
                        $s = self::slugify($mapped);
                        if ($s !== '' && isset(self::$slug_map[$s])) return $s;
                    }
                    // If no mapping exists, try the English short directly as a slug
                    // (works for universal book names like "genesis" shared across datasets).
                    $s = self::slugify($en_short);
                    if ($s !== '' && isset(self::$slug_map[$s])) return $s;
                }
            }
        }

        // ── L6: Prefix match (last resort) ────────────────────────────────────
        // If the key uniquely prefixes exactly one book in the abbreviation map,
        // return that book. Single-word keys of 3+ chars only.
        // Bail immediately if more than one distinct book matches (ambiguous).
        $prefix_abbr = !empty($abbr) ? $abbr : (self::get_abbreviation_map('bible') ?: []);
        if ($prefix_abbr && $key !== '' && strpos($key, ' ') === false && mb_strlen($key, 'UTF-8') >= 3) {
            $matched_slug = null;
            $match_count  = 0;
            foreach ($prefix_abbr as $abbr_key => $abbr_short) {
                if ($abbr_key === $key) continue; // exact already tried in L2
                if (strpos($abbr_key, $key) === 0) {
                    $s = self::slugify($abbr_short);
                    if ($s !== '' && $s !== $matched_slug) {
                        $matched_slug = $s;
                        $match_count++;
                    }
                    if ($match_count > 1) break; // ambiguous — abort
                }
            }
            if ($match_count === 1 && $matched_slug !== null && isset(self::$slug_map[$matched_slug])) {
                return $matched_slug;
            }
        }

        return null;
    }
}
