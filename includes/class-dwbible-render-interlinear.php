<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_Interlinear_Trait {
    private static function dwbible_plugin_root_dir() {
        return trailingslashit(dirname(__FILE__, 2));
    }

    private static function get_book_entry_for_dataset($dataset_slug, $book_slug) {
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        $book_slug = is_string($book_slug) ? self::slugify($book_slug) : '';
        if ($dataset_slug === '' || $book_slug === '') return null;

        $data_base = dwbible_data_dir();
        $index_file = $data_base . $dataset_slug . '/html/index.csv';
        if (!file_exists($index_file)) {
            $old = $data_base . $dataset_slug . '_books_html/index.csv';
            if (file_exists($old)) {
                $index_file = $old;
            } else {
                return null;
            }
        }

        if (($fh = fopen($index_file, 'r')) === false) return null;
        $header = fgetcsv($fh);
        $found = null;
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || count($row) < 3) continue;
            $short = (string) $row[1];
            $slug = self::slugify($short);
            if ($slug === $book_slug) {
                $display = '';
                $filename = '';
                if (count($row) >= 4) {
                    $display = isset($row[2]) ? (string)$row[2] : '';
                    $filename = isset($row[3]) ? (string)$row[3] : (isset($row[2]) ? (string)$row[2] : '');
                } else {
                    $filename = (string)$row[2];
                }
                $found = [
                    'order' => intval($row[0]),
                    'short_name' => $short,
                    'display_name' => $display,
                    'filename' => $filename,
                ];
                break;
            }
        }
        fclose($fh);
        return $found;
    }

    private static function html_dir_for_dataset($dataset_slug) {
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        if ($dataset_slug === '') return null;
        $data_base = dwbible_data_dir();
        $root = $data_base . $dataset_slug . '/html/';
        if (is_dir($root)) return trailingslashit($root);
        $old = $data_base . $dataset_slug . '_books_html/';
        if (is_dir($old)) return trailingslashit($old);
        return null;
    }

    private static function bible_url_for_slug_and_canonical_book($slug, $canonical_book_slug, $ch = 0, $vf = 0, $vt = 0) {
        $slug = is_string($slug) ? trim($slug, "/ ") : '';
        if ($slug === '') {
            return '';
        }

        $canonical_book_slug = is_string($canonical_book_slug) ? self::slugify($canonical_book_slug) : '';
        if ($canonical_book_slug === '') {
            return '';
        }

        $url_dataset = $slug;
        if (strpos($slug, '-') !== false) {
            $parts = array_values(array_filter(array_map('trim', explode('-', $slug))));
            if (!empty($parts)) {
                $url_dataset = $parts[0];
            }
        }

        $url_book = $canonical_book_slug;
        if (is_string($url_dataset) && $url_dataset !== '') {
            $mapped = self::url_book_slug_for_dataset($canonical_book_slug, $url_dataset);
            if (is_string($mapped) && $mapped !== '') {
                $url_book = $mapped;
            }
        }

        $path = '/' . $slug . '/' . $url_book . '/';
        $ch = absint($ch);
        $vf = absint($vf);
        $vt = absint($vt);
        if ($ch > 0) {
            if ($vf > 0) {
                if ($vt <= 0 || $vt < $vf) { $vt = $vf; }
                $path .= $ch . ':' . $vf;
                if ($vt > $vf) {
                    $path .= '-' . $vt;
                }
            } else {
                $path .= $ch;
            }
        }

        return home_url($path);
    }

    private static function render_interlinear_language_switcher($canonical_book_slug, $datasets, $ch, $vf, $vt) {
        $canonical_book_slug = is_string($canonical_book_slug) ? self::slugify($canonical_book_slug) : '';
        if ($canonical_book_slug === '') {
            return '';
        }

        $slug_current = get_query_var(self::QV_SLUG);
        $slug_current = is_string($slug_current) ? trim($slug_current, "/ ") : '';
        if ($slug_current === '') {
            return '';
        }

        $known = ['bible' => 'English', 'bibel' => 'Deutsch', 'latin' => 'Latin'];
        $d1 = (is_array($datasets) && isset($datasets[0]) && is_string($datasets[0])) ? $datasets[0] : '';
        $d2 = (is_array($datasets) && isset($datasets[1]) && is_string($datasets[1])) ? $datasets[1] : '';
        $current_slug = is_string($slug_current) ? $slug_current : '';

        $html = '<div class="dwbible-language-switcher" data-language-switcher'
            . ' data-current-slug="' . esc_attr($current_slug) . '"'
            . ' data-current-first="' . esc_attr($d1) . '"'
            . ' data-current-second="' . esc_attr($d2) . '"'
            . '>';

        $html .= '<div class="dwbible-language-switcher__group dwbible-language-switcher__group--single" data-group="single">';
        $html .= '<span class="dwbible-language-switcher__label">Single:</span> ';
        foreach ($known as $slug => $label) {
            $target = $slug;
            $url = self::bible_url_for_slug_and_canonical_book($target, $canonical_book_slug, $ch, $vf, $vt);
            if (!is_string($url) || $url === '') {
                continue;
            }
            $is_current = ($current_slug === $target);
            $cls = 'dwbible-language-switcher__link dwbible-language-switcher__link--single dwbible-language-switcher__link--' . $slug;
            if ($is_current) { $cls .= ' is-current'; }
            $label_html = $is_current ? ('<strong>' . esc_html($label) . '</strong>') : esc_html($label);
            $html .= '<a class="' . esc_attr($cls) . '" data-target-slug="' . esc_attr($target) . '" href="' . esc_url($url) . '">' . $label_html . '</a> ';
        }
        $html .= '</div>';

        $html .= '<div class="dwbible-language-switcher__group dwbible-language-switcher__group--first" data-group="first">';
        $html .= '<span class="dwbible-language-switcher__label">First:</span> ';
        foreach ($known as $slug => $label) {
            $target = $d2 !== '' ? ($slug . '-' . $d2) : $slug;
            $url = self::bible_url_for_slug_and_canonical_book($target, $canonical_book_slug, $ch, $vf, $vt);
            if (!is_string($url) || $url === '') {
                continue;
            }
            $is_current = ($current_slug === $target);
            $cls = 'dwbible-language-switcher__link dwbible-language-switcher__link--first dwbible-language-switcher__link--first-' . $slug;
            if ($is_current) { $cls .= ' is-current'; }
            $label_html = $is_current ? ('<strong>' . esc_html($label) . '</strong>') : esc_html($label);
            $html .= '<a class="' . esc_attr($cls) . '" data-target-slug="' . esc_attr($target) . '" data-target-first="' . esc_attr($slug) . '" href="' . esc_url($url) . '">' . $label_html . '</a> ';
        }
        $html .= '</div>';

        if ($d1 !== '') {
            $html .= '<div class="dwbible-language-switcher__group dwbible-language-switcher__group--second" data-group="second">';
            $html .= '<span class="dwbible-language-switcher__label">Second:</span> ';
            foreach ($known as $slug => $label) {
                $target = $d1 . '-' . $slug;
                $url = self::bible_url_for_slug_and_canonical_book($target, $canonical_book_slug, $ch, $vf, $vt);
                if (!is_string($url) || $url === '') {
                    continue;
                }
                $is_current = ($current_slug === $target);
                $cls = 'dwbible-language-switcher__link dwbible-language-switcher__link--second dwbible-language-switcher__link--second-' . $slug;
                if ($is_current) { $cls .= ' is-current'; }
                $label_html = $is_current ? ('<strong>' . esc_html($label) . '</strong>') : esc_html($label);
                $html .= '<a class="' . esc_attr($cls) . '" data-target-slug="' . esc_attr($target) . '" data-target-second="' . esc_attr($slug) . '" href="' . esc_url($url) . '">' . $label_html . '</a> ';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function parse_verse_nodes_by_number($html, $book_slug, $ch) {
        $out = [];
        if (!is_string($html) || $html === '') return $out;
        if (!is_string($book_slug) || $book_slug === '') return $out;
        $ch = absint($ch);
        if ($ch <= 0) return $out;

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $prefix = $book_slug . '-' . $ch . '-';
        $nodes = $xp->query('//*[@id and starts-with(@id, "' . $prefix . '")]');
        if (!$nodes) return $out;
        foreach ($nodes as $n) {
            if (!$n->hasAttribute('id')) continue;
            $id = (string)$n->getAttribute('id');
            if (strpos($id, $prefix) !== 0) continue;
            $v = absint(substr($id, strlen($prefix)));
            if ($v <= 0) continue;
            $out[$v] = $n;
        }
        return [$doc, $out];
    }

    private static function strip_element_by_id($html, $id) {
        if (!is_string($html) || $html === '') return $html;
        if (!is_string($id) || $id === '') return $html;
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $nodes = $xp->query('//*[@id="' . $id . '"]');
        if ($nodes && $nodes->length) {
            $n = $nodes->item(0);
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return $html;
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function extract_nav_blocks_from_chapter_html($chapter_html) {
        if (!is_string($chapter_html) || $chapter_html === '') return '';
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $chapter_html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $out = '';
        $chapters = $xp->query('//p[contains(concat(" ", normalize-space(@class), " "), " chapters ")]');
        if ($chapters && $chapters->length) {
            $out .= $doc->saveHTML($chapters->item(0));
        }
        $verses = $xp->query('//p[contains(concat(" ", normalize-space(@class), " "), " verses ")]');
        if ($verses && $verses->length) {
            $out .= $doc->saveHTML($verses->item(0));
        }
        return $out;
    }

    private static function render_multilingual_book($url_book_slug, $slug_combo) {
        $url_book_slug = is_string($url_book_slug) ? $url_book_slug : '';
        $slug_combo = is_string($slug_combo) ? trim($slug_combo, "/ ") : '';
        $canonical_key = self::slugify($url_book_slug);
        if ($canonical_key === '' || $slug_combo === '') {
            self::render_404();
            return;
        }

        $datasets = array_values(array_filter(array_map('trim', explode('-', $slug_combo))));
        if (count($datasets) < 1 || count($datasets) > 3) {
            self::render_404();
            return;
        }

        // If the URL book slug is localized for the first dataset (e.g. /bibel-latin/hiob/...)
        // map it back to our canonical key (e.g. job) so other datasets can resolve properly.
        $first_dataset = $datasets[0] ?? '';
        if (is_string($first_dataset) && $first_dataset !== '' && $first_dataset !== 'latin') {
            $mapped_key = self::canonicalize_key_from_dataset_book_slug($first_dataset, $url_book_slug);
            if (is_string($mapped_key) && $mapped_key !== '') {
                $canonical_key = self::slugify($mapped_key);
            }
        }

        // OSIS-based canonicalization (English is the reference segmentation).
        $osis = self::osis_for_dataset_book_slug($first_dataset, $url_book_slug);
        if (is_string($osis) && $osis !== '') {
            $bible_ref = self::dataset_book_slug_for_osis('bible', $osis);
            if (is_string($bible_ref) && $bible_ref !== '') {
                $canonical_key = $bible_ref;
            }
        }

        $entries = [];
        $docs = [];
        $nodes_by_dataset = [];

        $notices = [];
        $active_datasets = [];
        foreach ($datasets as $dataset_idx => $dataset) {
            if (!is_string($dataset) || $dataset === '') {
                self::render_404();
                return;
            }

            $dataset_short = null;
            if (is_string($osis) && $osis !== '') {
                $dataset_short = self::dataset_book_slug_for_osis($dataset, $osis);
            }
            if (!is_string($dataset_short) || $dataset_short === '') {
                // Fall back to legacy canonical-slug book_map.json logic
                $dataset_short = self::resolve_book_for_dataset($canonical_key, $dataset);
                if (!is_string($dataset_short) || $dataset_short === '') {
                    $dataset_short = $canonical_key;
                }
            }

            $entry = self::get_book_entry_for_dataset($dataset, $dataset_short);
            if (!$entry) {
                // If the first dataset can't resolve, the page can't be rendered.
                if ($dataset_idx === 0) {
                    self::render_404();
                    return;
                }
                $notices[] = 'Dataset "' . esc_html($dataset) . '" has no matching book for this selection.';
                continue;
            }

            $dir = self::html_dir_for_dataset($dataset);
            if (!$dir) {
                self::render_404();
                return;
            }

            $file = $dir . $entry['filename'];
            if (!file_exists($file)) {
                if ($dataset_idx === 0) {
                    self::render_404();
                    return;
                }
                $notices[] = 'Dataset "' . esc_html($dataset) . '" is missing the source file for this book.';
                continue;
            }

            $entries[$dataset_idx] = $entry;
            $entries[$dataset_idx]['_dataset'] = $dataset;
            $html = (string) file_get_contents($file);
            $entries[$dataset_idx]['_raw_html'] = $html;
            $active_datasets[] = $dataset;
        }

        $ch = absint(get_query_var(self::QV_CHAPTER));
        if ($ch <= 0) {
            $ch = 1;
            set_query_var(self::QV_CHAPTER, $ch);
        }

        $nav_blocks = '';
        $first_dataset_book_slug = null;
        foreach ($datasets as $dataset_idx => $dataset) {
            if (!isset($entries[$dataset_idx]) || !is_array($entries[$dataset_idx]) || !isset($entries[$dataset_idx]['_raw_html'])) {
                continue;
            }
            $html = (string) $entries[$dataset_idx]['_raw_html'];
            $chapter_html = self::extract_chapter_from_html($html, $ch);
            if ($chapter_html === null) {
                $notices[] = 'Dataset "' . esc_html($dataset) . '" has no chapter ' . esc_html((string)$ch) . ' for this book.';
                // Catholic/common-sense guidance for known mismatches/containment.
                if (is_string($osis) && $osis === 'Dan' && $ch >= 13) {
                    if ($dataset === 'bibel') {
                        $add_ch = ($ch === 13) ? 1 : 2;
                        $notices[] = 'Hint: In German, try /bibel/zusaetze-daniel/' . esc_html((string)$add_ch) . '/ for the Additions to Daniel.';
                    } elseif ($dataset === 'latin') {
                        $notices[] = 'Hint: Latin Daniel in this dataset appears to omit Daniel 13–14. Try the English reference: /bible/daniel/' . esc_html((string)$ch) . '/.';
                    }
                }
                continue;
            }

            // Keep chapters/verses navigation blocks from the first dataset
            if ($dataset_idx === 0) {
                $nav_blocks = self::extract_nav_blocks_from_chapter_html($chapter_html);
                $first_dataset_book_slug = self::slugify($entries[$dataset_idx]['short_name'] ?? '');
            }

            // Remove the chapter heading node to avoid duplicate/unstyled chapter titles
            $dataset_book_slug = self::slugify($entries[$dataset_idx]['short_name']);
            $chapter_heading_id = $dataset_book_slug . '-ch-' . $ch;
            $chapter_html = self::strip_element_by_id($chapter_html, $chapter_heading_id);

            $parsed = self::parse_verse_nodes_by_number($chapter_html, $dataset_book_slug, $ch);
            if (!is_array($parsed) || count($parsed) !== 2) {
                $notices[] = 'Dataset "' . esc_html($dataset) . '" could not be parsed for chapter ' . esc_html((string)$ch) . '.';
                continue;
            }
            list($doc, $nodes) = $parsed;
            $docs[$dataset_idx] = $doc;
            $nodes_by_dataset[$dataset_idx] = $nodes;
        }

        $active_dataset_indices = array_keys($nodes_by_dataset);
        sort($active_dataset_indices);

        $verses = [];
        foreach ($active_dataset_indices as $dataset_idx) {
            $nodes = $nodes_by_dataset[$dataset_idx] ?? null;
            if (!is_array($nodes)) {
                continue;
            }
            $verses = array_merge($verses, array_keys($nodes));
        }
        $verses = array_values(array_unique($verses));
        sort($verses);

        if (is_string($nav_blocks) && $nav_blocks !== ''
            && is_string($first_dataset_book_slug) && $first_dataset_book_slug !== ''
            && $first_dataset_book_slug !== $canonical_key
        ) {
            $from = $first_dataset_book_slug . '-' . $ch . '-';
            $to = $canonical_key . '-' . $ch . '-';
            $nav_blocks = str_replace('#' . $from, '#' . $to, $nav_blocks);
            $nav_blocks = str_replace('#' . rawurlencode($from), '#' . rawurlencode($to), $nav_blocks);
        }

        if (empty($active_dataset_indices)) {
            // No dataset could provide the requested chapter.
            // Show a user-friendly error with book title, message, and back link.
            $slug = get_query_var(self::QV_SLUG);
            if ( ! is_string($slug) || $slug === '' ) { $slug = 'bible'; }
            $index_url = home_url('/' . $slug . '/');
            $book_url  = home_url('/' . $slug . '/' . $canonical_key . '/');

            // Try to get the book's display name from the first dataset
            $book_display = ucwords(str_replace('-', ' ', $canonical_key));
            if (!empty($entries) && is_array($entries)) {
                foreach ($entries as $entry) {
                    if (is_array($entry) && !empty($entry['display_name'])) {
                        $book_display = $entry['display_name'];
                        break;
                    }
                }
            }

            $page_title = esc_html($book_display) . ' ' . esc_html((string)$ch);
            $out = '<div class="dwbible dwbible-book">';
            $out .= '<h1>' . $page_title . '</h1>';
            $out .= '<p>This chapter is not available.</p>';
            if (!empty($notices)) {
                $out .= '<ul style="color:#888;font-size:.85rem;margin:1rem 0">';
                foreach ($notices as $msg) {
                    $out .= '<li>' . $msg . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '<p><a href="' . esc_url($book_url) . '">&larr; ' . esc_html($book_display) . '</a>';
            $out .= ' &middot; <a href="' . esc_url($index_url) . '">Bible Index</a></p>';
            $out .= '</div>';

            status_header(404);
            nocache_headers();
            self::output_with_theme($page_title, $out, 'book');
            return;
        }

        $datasets_attr = esc_attr(implode(',', $active_datasets));
        $out = '<div class="dwbible dwbible-book dwbible-interlinear"'
            . ' data-interlinear="1"'
            . ' data-book="' . esc_attr($canonical_key) . '"'
            . ' data-ch="' . esc_attr((string)$ch) . '"'
            . ' data-datasets="' . $datasets_attr . '"'
            . ' data-first-dataset="' . esc_attr((string)$datasets[0]) . '"'
            . '>';

        if (!empty($notices)) {
            $out .= '<div class="dwbible-interlinear-notices" data-interlinear-notices="1">';
            foreach ($notices as $msg) {
                $out .= '<p class="dwbible-interlinear-notice">' . $msg . '</p>';
            }
            $out .= '</div>';
        }
        if (is_string($nav_blocks) && $nav_blocks !== '') {
            $out .= $nav_blocks;
        }

        foreach ($verses as $v) {
            $primary_id_set = false;
            $out .= '<div class="dwbible-interlinear-verse dwbible-interlinear-verse--v' . esc_attr((string)$v) . '"'
                . ' data-verse="' . esc_attr((string)$v) . '"'
                . ' data-book="' . esc_attr($canonical_key) . '"'
                . ' data-ch="' . esc_attr((string)$ch) . '"'
                . '>';
            foreach ($active_dataset_indices as $idx) {
                $dataset = $datasets[$idx] ?? '';
                if (!is_string($dataset) || $dataset === '') {
                    continue;
                }
                $node = $nodes_by_dataset[$idx][$v] ?? null;
                if (!$node) {
                    continue;
                }
                $doc = $docs[$idx];
                $node = $doc->importNode($node, true);
                $class_suffix = chr(ord('a') + $idx);
                $node->setAttribute(
                    'class',
                    trim(
                        $node->getAttribute('class')
                        . ' dwbible-interlinear-' . $class_suffix
                        . ' dwbible-interlinear-' . $dataset
                        . ' dwbible-interlinear-entry'
                        . ' dwbible-interlinear-entry--pos-' . $class_suffix
                        . ' dwbible-interlinear-entry--dataset-' . $dataset
                        . ' dwbible-interlinear-entry--idx-' . $idx
                    )
                );
                $node->setAttribute('data-dataset', (string) $dataset);
                $node->setAttribute('data-line', (string) $class_suffix);
                $node->setAttribute('data-line-index', (string) $idx);
                if (!$primary_id_set) {
                    $id = $canonical_key . '-' . $ch . '-' . $v;
                    $node->setAttribute('id', $id);
                    $primary_id_set = true;
                } else {
                    if ($node->hasAttribute('id')) { $node->removeAttribute('id'); }
                }
                $out .= $doc->saveHTML($node);
            }
            $out .= '</div>';
        }

        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        $switcher = self::render_interlinear_language_switcher($canonical_key, $datasets, $ch, $vf, $vt);
        if (is_string($switcher) && $switcher !== '') {
            $out .= $switcher;
        }
        $out .= '</div>';

        // Build highlight/scroll targets (strict)
        $targets = [];
        $chapter_scroll_id = null;
        $vf_raw = get_query_var(self::QV_VFROM);
        $vt_raw = get_query_var(self::QV_VTO);
        $ref = DwBible_Reference::parse_chapter_and_range($ch, $vf_raw, $vt_raw);
        if (is_wp_error($ref)) {
            self::render_404();
            return;
        }
        if (!empty($ref['vf'])) {
            $targets = DwBible_Reference::highlight_ids_for_range($canonical_key, $ref['ch'], $ref['vf'], $ref['vt']);
        } else {
            $chapter_scroll_id = DwBible_Reference::chapter_scroll_id($canonical_key, $ref['ch']);
        }

        // Inject navigation helpers and sticky header for interlinear pages
        $first_entry = $entries[0] ?? null;
        $human = $first_entry && isset($first_entry['display_name']) && $first_entry['display_name'] !== ''
            ? $first_entry['display_name']
            : ($first_entry ? self::pretty_label($first_entry['short_name']) : '');
        $out = self::inject_nav_helpers($out, $targets, $chapter_scroll_id, $human, [
            'book' => $canonical_key,
            'chapter' => $ch,
        ]);

        status_header(200);
        nocache_headers();

        $first_entry = $entries[0] ?? null;
        $base_title = ($first_entry && isset($first_entry['display_name']) && $first_entry['display_name'] !== '')
            ? $first_entry['display_name']
            : ($first_entry ? self::pretty_label($first_entry['short_name']) : '');

        $title = trim($base_title . ' ' . $ch);
        if ($ch && !empty($ref['vf'])) {
            $vf = (int) $ref['vf'];
            $vt = (int) $ref['vt'];
            $title = trim($base_title . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt)));
        }

        // Insert bottom prev/next nav just before the language switcher
        if (DwBible_Nav_Helpers::$last_nav_ctx) {
            $bottom_nav = DwBible_Nav_Helpers::build_bottom_nav(DwBible_Nav_Helpers::$last_nav_ctx);
            $switcher_pos = strpos($out, '<div class="dwbible-language-switcher"');
            if ($switcher_pos !== false) {
                $out = substr_replace($out, $bottom_nav, $switcher_pos, 0);
            } else {
                $out .= $bottom_nav;
            }
        }
        // Hidden AI discovery hints — readable by AI agents that strip <head> tags
        $site_url   = home_url();
        $book_qv    = get_query_var( self::QV_BOOK );
        $chapter_qv = get_query_var( self::QV_CHAPTER );
        $slug_qv    = get_query_var( self::QV_SLUG );
        if ( ! is_string( $slug_qv ) || $slug_qv === '' ) { $slug_qv = 'bible'; }
        $ai_hints = '<div class="dwbible-ai-hints" style="display:none" aria-hidden="true">';
        $ai_hints .= 'Machine-readable data available: ';
        $ai_hints .= 'API documentation: ' . esc_url( $site_url . '/llms.txt' ) . ' — ';
        if ( $book_qv && $chapter_qv ) {
            $ai_hints .= 'This chapter as JSON: ' . esc_url( $site_url . '/' . $slug_qv . '/' . $book_qv . '/' . $chapter_qv . '.json' ) . ' — ';
        } elseif ( $book_qv ) {
            $ai_hints .= 'This book as JSON: ' . esc_url( $site_url . '/' . $slug_qv . '/' . $book_qv . '/index.json' ) . ' — ';
        }
        $ai_hints .= 'All books in all 3 translations: ' . esc_url( $site_url . '/bible-index.json' );
        $ai_hints .= '</div>';
        $out .= $ai_hints;

        $footer = self::render_footer_html();
        if ($footer !== '') { $out .= $footer; }
        self::output_with_theme($title, $out, 'book');
    }
}
