<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_Router_Trait {
    public static function handle_request() {
        // Main request router; will be refactored later.

        // ── JSON API and llms.txt (AI access) ───────────────────────────
        $format = get_query_var( self::QV_FORMAT );
        if ( $format === 'json' ) {
            self::serve_json_file();
            exit;
        }
        if ( $format === 'llms' || $format === 'llms-full' ) {
            self::serve_llms_txt( $format );
            exit;
        }
        if ( $format === 'bible-index' ) {
            self::serve_unified_index();
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

        // Sitemaps must be checked before book — per-book sitemaps set both
        // QV_SITEMAP and QV_BOOK, so sitemap takes priority.
        $sitemap = get_query_var(self::QV_SITEMAP);
        if ($sitemap) {
            self::handle_sitemap();
            exit;
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
        $book_slug = get_query_var(self::QV_BOOK);
        if (!$book_slug) {
            self::render_index();
            return;
        }

        // Resolve canonical book slug for the current language dataset
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        set_query_var(self::QV_SLUG, $slug);

        // Canonicalize book slug based on the first dataset in the slug (e.g. latin-bible => latin)
        $canon_dataset = $slug;
        if (is_string($canon_dataset) && strpos($canon_dataset, '-') !== false) {
            $parts = array_values(array_filter(array_map('trim', explode('-', $canon_dataset))));
            if (!empty($parts)) {
                $canon_dataset = $parts[0];
            }
        }

        $canonical = self::canonical_book_slug_from_url($book_slug, $canon_dataset);
        if (!$canonical) {
            self::render_404();
            exit;
        }

        // If the URL slug differs from the canonical one, redirect
        if ($canonical !== $book_slug) {
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

            $canonical_url = home_url($path);
            $current = home_url(add_query_arg([]));
            if (trailingslashit($canonical_url) !== trailingslashit($current)) {
                wp_redirect($canonical_url, 301);
                exit;
            }
            $book_slug = $canonical;
            set_query_var(self::QV_BOOK, $book_slug);
        }

        // Redirect slash-form chapter/verse to canonical colon form.
        // e.g. /bible/luke/24/13-35 → /bible/luke/24:13-35
        $ch = get_query_var(self::QV_CHAPTER);
        $vf = get_query_var(self::QV_VFROM);
        if ($ch && $vf) {
            $uri = strtok(isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '', '?');
            // Slash form has /{ch}/[digit] in the URI; colon form has /{ch}:[digit].
            if (preg_match('#/' . preg_quote($ch, '#') . '/[0-9]#', $uri)) {
                $vt   = get_query_var(self::QV_VTO);
                $path = '/' . trim($slug, '/') . '/' . $book_slug . '/' . $ch . ':' . $vf;
                if ($vt && (int)$vt > (int)$vf) {
                    $path .= '-' . (int)$vt;
                }
                wp_redirect(home_url($path), 301);
                exit;
            }
        }

        // Always use multilingual renderer (1 dataset is the special case)
        self::render_multilingual_book($book_slug, $slug);
        exit; // prevent WP from continuing
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

        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
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
