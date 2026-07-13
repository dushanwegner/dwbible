<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_AutoLink_Trait {

    private static $unified_abbr = null;

    /** Set true when at least one reference is auto-linked on the page, so the
     *  footer prints the verse-preview modal assets only where they're needed. */
    public static $did_link = false;

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
                 // Optional verse: colon (spaces allowed, incl. unicode colon variants)
                 // OR a TIGHT comma (German/Romance "6,5" — no surrounding space, so an
                 // English list like "Genesis 1, 2" stays chapter-only). Range accepts
                 // hyphen plus the dash family (en/em/figure dash, minus): "5–7".
                 . '(?:(?:(?:\s|\x{00A0})*[:\x{2236}\x{FE55}\x{FF1A}](?:\s|\x{00A0})*|,)(\d+)(?:[-\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}](\d+))?)?'
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
        // A verse separator was present but the verse is invalid (e.g. "Gen 1:0") —
        // leave it as plain text rather than silently falling back to a chapter link.
        if (isset($m[3]) && $m[3] !== '' && $vf <= 0) return $m[0];

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

        // The URL must use the CANONICAL book key (e.g. "acts"), which every dataset's router
        // resolves — NOT the localized display name slugified (e.g. "apostelgeschichte", which
        // only a German reader recognizes and which NO dataset routes to → "Dataset X has no
        // matching book" → latin-only + a broken language switcher). Fall back to the slugified
        // short name only when the ref isn't in the canonical book map.
        $canon = self::canonicalize_key_from_dataset_book_slug($effective_slug, $short);
        $book_slug = (is_string($canon) && $canon !== '') ? $canon : self::slugify($short);
        if ($book_slug === '') return $m[0];
        // Emit the LATIN canonical URL slug (actus-apostolorum) so the link lands on the canonical
        // page instead of 301-hopping through the internal/English key.
        $book_slug = self::latin_slug_for_key($book_slug);

        // Latin-first: rewrite to interlinear URL with Latin as primary text.
        if (get_option('dwbible_autolink_latin_first', '0') === '1' && $effective_slug !== 'latin') {
            if ($canon !== null) {
                $latin_short = self::resolve_book_for_dataset($canon, 'latin');
                if (is_string($latin_short) && $latin_short !== '') {
                    $effective_slug = 'latin-' . $effective_slug;
                    $book_slug = self::latin_slug_for_key($canon); // Latin canonical URL slug
                }
            }
        }

        $base_url = get_option('dwbible_autolink_base_url', '');
        $origin = (is_string($base_url) && $base_url !== '') ? rtrim($base_url, '/') : home_url();
        // Canonical public form: /{lang}/biblia/{latin-book}/ — no 301 hop.
        // Language of the URL:
        //   - When a caller named a dataset ($preferred_slug — e.g.
        //     autolink_content_for_slug, or an explicit vernacular book name),
        //     follow THAT dataset's language so the link lands on the intended
        //     edition (bibel→de, italian→it, …).
        //   - When NO dataset was requested (the common case: readings/prayers
        //     autolinked from an English/Latin abbreviation), keep the reader in
        //     their CURRENT site language, so a reference opened from /it/ stays
        //     on the Italian edition instead of defaulting to /en/.
        // Fall back to the dataset language, then English.
        $lang = '';
        if ($preferred_slug === '' && function_exists('dwi18n_current')) {
            $lang = (string) dwi18n_current();
        }
        if ($lang === '' && function_exists('dwbible_i18n_lang_for_slug')) {
            $lang = (string) dwbible_i18n_lang_for_slug($effective_slug);
        }
        if ($lang === '') {
            $lang = function_exists('dwi18n_current') ? dwi18n_current() : 'en';
        }
        $base = $origin . '/' . $lang . '/' . DwBible_Plugin::CANONICAL_SECTION . '/' . $book_slug . '/';

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

        // Verse-preview modal hooks: a class + the JSON API URL for the passage
        // text, so a tap PREVIEWS the verses in place (the anchor's own href is
        // the "open in Bible" target + the no-JS fallback). Marks the page as
        // carrying a reference so the footer prints the modal assets.
        self::$did_link = true;
        $json_by_lang = ['en' => 'bible', 'de' => 'bibel', 'es' => 'spanish', 'fr' => 'french', 'it' => 'italian', 'la' => 'latin'];
        $json_slug = $json_by_lang[$lang] ?? 'bible';
        $vpart = ($vf > 0)
            ? ($ch . '/' . $vf . ($vt && $vt >= $vf ? '-' . $vt : ''))
            : (string) $ch;
        // Root-relative so the preview always fetches from the page's own origin
        // (robust across latinprayer.org / previews / any host).
        $json_url = '/' . $json_slug . '/' . $book_slug . '/' . $vpart . '.json';

        return $prefix_raw
            . '<a class="dwbible-ref" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"'
            . ' data-dwv-json="' . esc_url($json_url) . '" data-dwv-ref="' . esc_attr($ref_text) . '">'
            . esc_html($ref_text) . '</a>';
    }

    /**
     * Print the verse-preview modal (self-contained inline CSS + JS) in the
     * footer, only on pages that auto-linked a reference. Vanilla JS: a tap on
     * an `a.dwbible-ref` fetches its passage from the Bible JSON API and shows
     * it in a themed, dark-mode-elevated modal with a pinned "Open in Bible"
     * link + a "more to see" bottom fade — the web twin of the app's verse peek.
     */
    public static function print_modal_assets() {
        if (empty(self::$did_link)) {
            return;
        }
        $lang = function_exists('dwi18n_current') ? (string) dwi18n_current() : 'en';
        $open = ['en' => 'Open in Bible', 'de' => 'In der Bibel öffnen', 'es' => 'Abrir en la Biblia', 'fr' => 'Ouvrir dans la Bible', 'it' => 'Apri nella Bibbia', 'la' => 'Aperíre in Bíbliis'];
        $loading = ['en' => 'Loading…', 'de' => 'Wird geladen…', 'es' => 'Cargando…', 'fr' => 'Chargement…', 'it' => 'Caricamento…', 'la' => 'Onerátur…'];
        $error = ['en' => 'Could not load the passage.', 'de' => 'Konnte nicht geladen werden.', 'es' => 'No se pudo cargar.', 'fr' => 'Impossible de charger.', 'it' => 'Impossibile caricare.', 'la' => 'Legi non pótuit.'];
        $lopen = $open[$lang] ?? $open['en'];
        $lload = $loading[$lang] ?? $loading['en'];
        $lerr  = $error[$lang] ?? $error['en'];
        ?>
<style id="dwv-modal-css">
.dwv-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99998;opacity:0;transition:opacity .18s ease}
.dwv-backdrop.is-open{opacity:1}
.dwv-modal{position:fixed;z-index:99999;left:50%;top:50%;transform:translate(-50%,-50%) scale(.97);width:min(440px,calc(100% - 24px));max-height:calc(100dvh - 48px);display:flex;flex-direction:column;overflow:hidden;background:var(--bg);color:var(--ink);border:1px solid var(--rule);border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.28);opacity:0;transition:opacity .18s ease,transform .18s ease}
.dwv-modal.is-open{opacity:1;transform:translate(-50%,-50%) scale(1)}
html[data-theme="dark"] .dwv-modal,html[data-theme="night"] .dwv-modal{background:var(--bg-2)}
.dwv-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--rule);flex-shrink:0}
.dwv-title{margin:0;font-family:var(--font-sans);font-size:var(--fs-body,1rem);font-weight:var(--fw-medium,600);color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dwv-close{appearance:none;-webkit-appearance:none;background:none;border:0;color:var(--ink-soft);cursor:pointer;font-size:24px;line-height:1;padding:2px 6px;flex-shrink:0}
.dwv-body{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px 18px}
.dwv-body::after{content:"";position:sticky;bottom:0;display:block;height:26px;margin-top:-26px;background:linear-gradient(to bottom,transparent,var(--bg));pointer-events:none;opacity:0;transition:opacity .15s ease}
html[data-theme="dark"] .dwv-body::after,html[data-theme="night"] .dwv-body::after{background:linear-gradient(to bottom,transparent,var(--bg-2))}
.dwv-body[data-more="1"]::after{opacity:1}
.dwv-verse{display:flex;gap:8px;font-size:.85rem;line-height:1.5;margin-bottom:9px;font-family:var(--font-sans)}
.dwv-num{flex:0 0 auto;color:var(--ink-mute);font-size:.75rem;padding-top:.15em;font-variant-numeric:tabular-nums}
.dwv-status{margin:0;color:var(--ink-soft);font-size:.9rem}
.dwv-foot{flex-shrink:0;padding:12px 18px;border-top:1px solid var(--rule);background:inherit}
.dwv-modal .dwv-open{display:block;text-align:center;padding:11px 16px;border-radius:999px;background:var(--rubric);color:#fff;text-decoration:none;font-weight:var(--fw-medium,600);font-family:var(--font-sans)}
.dwv-modal .dwv-open:hover,.dwv-modal .dwv-open:focus-visible{color:#fff}
</style>
<script id="dwv-modal-js">
(function(){
  var L={open:<?php echo wp_json_encode($lopen); ?>,load:<?php echo wp_json_encode($lload); ?>,err:<?php echo wp_json_encode($lerr); ?>};
  var modal,backdrop,body,titleEl,openLink;
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function onKey(e){if(e.key==='Escape')close();}
  function fade(){if(body)body.setAttribute('data-more',(body.scrollTop+body.clientHeight<body.scrollHeight-2)?'1':'0');}
  function close(){if(!modal)return;modal.classList.remove('is-open');backdrop.classList.remove('is-open');document.documentElement.style.overflow='';document.removeEventListener('keydown',onKey);var m=modal,b=backdrop;modal=null;setTimeout(function(){m.remove();b.remove();},200);}
  function build(){
    backdrop=document.createElement('div');backdrop.className='dwv-backdrop';backdrop.addEventListener('click',close);
    modal=document.createElement('div');modal.className='dwv-modal';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');
    modal.innerHTML='<div class="dwv-head"><h2 class="dwv-title"></h2><button class="dwv-close" type="button" aria-label="Close">×</button></div><div class="dwv-body"><p class="dwv-status"></p></div><div class="dwv-foot"><a class="dwv-open" target="_blank" rel="noopener noreferrer"></a></div>';
    document.body.appendChild(backdrop);document.body.appendChild(modal);
    titleEl=modal.querySelector('.dwv-title');body=modal.querySelector('.dwv-body');openLink=modal.querySelector('.dwv-open');
    modal.querySelector('.dwv-close').addEventListener('click',close);
    body.addEventListener('scroll',fade,{passive:true});
    document.addEventListener('keydown',onKey);
    document.documentElement.style.overflow='hidden';
    requestAnimationFrame(function(){backdrop.classList.add('is-open');modal.classList.add('is-open');});
  }
  function open(a){
    if(modal)close();
    build();
    titleEl.textContent=a.getAttribute('data-dwv-ref')||'';
    openLink.href=a.href;openLink.textContent=L.open;
    body.innerHTML='<p class="dwv-status">'+esc(L.load)+'</p>';
    fetch(a.getAttribute('data-dwv-json'),{credentials:'omit'}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(d){
      if(!modal)return;var vs=(d&&d.verses)||[];
      if(!vs.length){body.innerHTML='<p class="dwv-status">'+esc(L.err)+'</p>';return;}
      var h='';for(var i=0;i<vs.length;i++){h+='<div class="dwv-verse"><span class="dwv-num">'+esc(vs[i].verse)+'</span><span>'+esc(vs[i].text)+'</span></div>';}
      body.innerHTML=h;fade();
    }).catch(function(){if(modal)body.innerHTML='<p class="dwv-status">'+esc(L.err)+'</p>';});
  }
  document.addEventListener('click',function(e){var t=e.target;var a=t&&t.closest?t.closest('a.dwbible-ref'):null;if(a){e.preventDefault();open(a);}});
})();
</script>
        <?php
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
