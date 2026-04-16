<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_SelfTest_Trait {
    public static function render_selftest() {
        $results = [];

        $results[] = self::selftest_check('wp_loaded', function() {
            return function_exists('get_option');
        });

        $results[] = self::selftest_check('og_renderer_class_exists', function() {
            return class_exists('DwBible_OG_Image') && method_exists('DwBible_OG_Image', 'render');
        });

        $results[] = self::selftest_check('osis_mapping_json_valid', function() {
            $file = plugin_dir_path(__FILE__) . 'osis-mapping.json';
            if (!file_exists($file) || !is_readable($file)) {
                return new WP_Error('dwbible_selftest_missing_osis_mapping', 'OSIS mapping JSON missing/unreadable.');
            }
            $raw = file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                return new WP_Error('dwbible_selftest_empty_osis_mapping', 'OSIS mapping JSON empty.');
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['books']) || !is_array($data['books'])) {
                return new WP_Error('dwbible_selftest_invalid_osis_mapping', 'OSIS mapping JSON invalid.');
            }
            return true;
        });

        $results[] = self::selftest_check('interlinear_renderer_present', function() {
            return method_exists(__CLASS__, 'render_multilingual_book');
        });

        $results[] = self::selftest_check('router_present', function() {
            return method_exists(__CLASS__, 'render_bible_page') && method_exists(__CLASS__, 'handle_request');
        });

        $results[] = self::selftest_check('text_utils_cases', function() {
            if (!class_exists('DwBible_Text_Utils')) {
                return new WP_Error('dwbible_selftest_text_utils_missing', 'Text utils class missing (DwBible_Text_Utils).');
            }
            if (!method_exists('DwBible_Text_Utils', 'normalize_whitespace') || !method_exists('DwBible_Text_Utils', 'clean_verse_text_for_output')) {
                return new WP_Error('dwbible_selftest_text_utils_incomplete', 'Text utils methods missing.');
            }

            $s = "Hello\xC2\xA0world";
            $norm = DwBible_Text_Utils::normalize_whitespace($s);
            if ($norm !== 'Hello world') {
                return new WP_Error('dwbible_selftest_text_utils_norm_failed', 'normalize_whitespace did not normalize NBSP.');
            }

            $q = DwBible_Text_Utils::clean_verse_text_for_output('»Test', false, '»', '«');
            if (!is_string($q) || $q === '') {
                return new WP_Error('dwbible_selftest_text_utils_quote_failed', 'clean_verse_text_for_output returned empty output.');
            }
            // After balancing/normalization, the output should contain either an inner or outer closing guillemet.
            if (strpos($q, '«') === false && strpos($q, '‹') === false) {
                return new WP_Error('dwbible_selftest_text_utils_quote_failed', 'clean_verse_text_for_output did not synthesize a closing quote.');
            }

            return true;
        });

        // ── Data consistency checks ─────────────────────────────────────
        // These would have caught the OSIS latin-slug 404 bug.

        $results[] = self::selftest_check('osis_dataset_consistency', function() {
            // Every "latin", "bible", "bibel[0]" value in osis-mapping.json
            // must exist as a slug in the corresponding dataset's index CSV.
            $osis = DwBible_Mappings_Loader::load_osis_mapping();
            if (empty($osis['books']) || !is_array($osis['books'])) {
                return new WP_Error('dwbible_selftest', 'OSIS mapping empty or invalid.');
            }

            $datasets = ['bible', 'bibel', 'latin'];
            $index_slugs = [];
            foreach ($datasets as $ds) {
                $csv = dwbible_data_dir() . $ds . '/html/index.csv';
                if (!file_exists($csv)) {
                    $csv = dwbible_data_dir() . $ds . '_books_html/index.csv';
                }
                if (!file_exists($csv)) continue;
                $fh = fopen($csv, 'r');
                if ($fh === false) continue;
                fgetcsv($fh); // skip header
                $slugs = [];
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 2) continue;
                    $slugs[] = DwBible_Plugin::slugify($row[1]);
                }
                fclose($fh);
                $index_slugs[$ds] = $slugs;
            }

            $failures = [];
            foreach ($osis['books'] as $code => $entry) {
                if (!is_array($entry)) continue;
                // Check bible
                if (isset($entry['bible'], $index_slugs['bible'])) {
                    $slug = DwBible_Plugin::slugify($entry['bible']);
                    if (!in_array($slug, $index_slugs['bible'], true)) {
                        $failures[] = "$code: bible='$slug' not in bible index";
                    }
                }
                // Check latin
                if (isset($entry['latin'], $index_slugs['latin'])) {
                    $slug = DwBible_Plugin::slugify($entry['latin']);
                    if (!in_array($slug, $index_slugs['latin'], true)) {
                        $failures[] = "$code: latin='$slug' not in latin index";
                    }
                }
                // Check bibel (first element must match the index)
                if (isset($entry['bibel'], $index_slugs['bibel']) && is_array($entry['bibel']) && !empty($entry['bibel'])) {
                    $slug = DwBible_Plugin::slugify($entry['bibel'][0]);
                    if (!in_array($slug, $index_slugs['bibel'], true)) {
                        $failures[] = "$code: bibel[0]='$slug' not in bibel index";
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_osis_mismatch', implode('; ', array_slice($failures, 0, 10)));
            }
            return true;
        });

        $results[] = self::selftest_check('interlinear_osis_resolution', function() {
            // Simulates the exact code path in render_multilingual_book():
            // osis_for_dataset_book_slug → dataset_book_slug_for_osis → get_book_entry_for_dataset
            // This is the path that broke when OSIS latin slugs were wrong.
            $osis = DwBible_Mappings_Loader::load_osis_mapping();
            if (empty($osis['books']) || !is_array($osis['books'])) {
                return new WP_Error('dwbible_selftest', 'OSIS mapping empty.');
            }

            $datasets = ['bible', 'bibel', 'latin'];
            $failures = [];

            foreach ($osis['books'] as $code => $entry) {
                if (!is_array($entry)) continue;
                foreach ($datasets as $ds) {
                    // Step 1: OSIS code → dataset slug (what render_multilingual_book does)
                    $resolved_slug = DwBible_Osis_Utils::dataset_book_slug_for_osis($osis, $ds, $code);
                    if (!is_string($resolved_slug) || $resolved_slug === '') continue;

                    // Step 2: resolved slug → book entry (must find the file)
                    $entry_result = self::get_book_entry_for_dataset($ds, $resolved_slug);
                    if (!$entry_result) {
                        $failures[] = "$code/$ds: OSIS resolved to '$resolved_slug' but get_book_entry_for_dataset() returned null";
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_osis_resolution', implode('; ', array_slice($failures, 0, 10)));
            }
            return true;
        });

        $results[] = self::selftest_check('book_map_consistency', function() {
            // Every book_map.json entry's dataset value must slugify to a
            // slug that exists in that dataset's index CSV.
            $book_map = DwBible_Mappings_Loader::load_book_map();
            if (empty($book_map) || !is_array($book_map)) {
                return true; // book_map.json is optional
            }

            $datasets = ['bible', 'bibel', 'latin'];
            $index_slugs = [];
            foreach ($datasets as $ds) {
                $csv = dwbible_data_dir() . $ds . '/html/index.csv';
                if (!file_exists($csv)) {
                    $csv = dwbible_data_dir() . $ds . '_books_html/index.csv';
                }
                if (!file_exists($csv)) continue;
                $fh = fopen($csv, 'r');
                if ($fh === false) continue;
                fgetcsv($fh);
                $slugs = [];
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 2) continue;
                    $slugs[] = DwBible_Plugin::slugify($row[1]);
                }
                fclose($fh);
                $index_slugs[$ds] = $slugs;
            }

            $failures = [];
            foreach ($book_map as $key => $map_entry) {
                if (!is_array($map_entry)) continue;
                foreach ($datasets as $ds) {
                    if (!isset($map_entry[$ds], $index_slugs[$ds])) continue;
                    $slug = DwBible_Plugin::slugify($map_entry[$ds]);
                    if ($slug !== '' && !in_array($slug, $index_slugs[$ds], true)) {
                        $failures[] = "$key: $ds='$slug' not in $ds index";
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_book_map_mismatch', implode('; ', array_slice($failures, 0, 10)));
            }
            return true;
        });

        $results[] = self::selftest_check('all_books_resolve_in_combos', function() {
            // Every book in every dataset's index must resolve via
            // canonical_book_slug_from_url() for relevant combo slugs.
            $combos = ['latin-bible', 'latin-bibel'];
            $datasets = ['bible', 'latin'];
            $failures = [];

            foreach ($datasets as $ds) {
                $csv = dwbible_data_dir() . $ds . '/html/index.csv';
                if (!file_exists($csv)) continue;
                $fh = fopen($csv, 'r');
                if ($fh === false) continue;
                fgetcsv($fh);
                $book_slugs = [];
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 2) continue;
                    $book_slugs[] = DwBible_Plugin::slugify($row[1]);
                }
                fclose($fh);

                foreach ($combos as $combo) {
                    foreach ($book_slugs as $slug) {
                        $result = self::canonical_book_slug_from_url($slug, $combo);
                        if ($result === null) {
                            $failures[] = "'$slug' via /$combo/ (from $ds index)";
                            if (count($failures) >= 10) break 3;
                        }
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_resolution', 'Unresolvable: ' . implode('; ', $failures));
            }
            return true;
        });

        $results[] = self::selftest_check('autolinker_cases', function() {
            if (!method_exists(__CLASS__, 'autolink_content_for_slug')) {
                return new WP_Error('dwbible_selftest_autolink_missing', 'Auto-linker helper missing (autolink_content_for_slug).');
            }

            $cases = [
                [
                    'name' => 'en_basic_single',
                    'slug' => 'bible',
                    'in' => 'See John 3:16.',
                    'must_contain' => ['href="', '>John 3:16</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'en_abbrev_short',
                    'slug' => 'bible',
                    'in' => 'Gen 1:1',
                    'must_contain' => ['>Gen 1:1</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'en_numeric_prefix',
                    'slug' => 'bible',
                    'in' => '1 Kings 2:3',
                    'must_contain' => ['>1 Kings 2:3</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_basic_single',
                    'slug' => 'bibel',
                    'in' => 'Siehe Matthäus 5:27.',
                    'must_contain' => ['href="', '>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_numeric_prefix_dot',
                    'slug' => 'bibel',
                    'in' => '1. Mose 1:1',
                    'must_contain' => ['>1. Mose 1:1</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_numeric_prefix_no_dot',
                    'slug' => 'bibel',
                    'in' => '1 Mose 1:1',
                    'must_contain' => ['>1 Mose 1:1</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_nbsp',
                    'slug' => 'bibel',
                    'in' => "Matthäus\xC2\xA05:27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_colon_ratio',
                    'slug' => 'bibel',
                    'in' => "Matthäus 5\xE2\x88\xB6 27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_colon_small',
                    'slug' => 'bibel',
                    'in' => "Matthäus 5\xEF\xB9\x95 27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_colon_fullwidth',
                    'slug' => 'bibel',
                    'in' => "Matthäus 5\xEF\xBC\x9A27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'range_dash',
                    'slug' => 'bible',
                    'in' => 'Romans 8:1-2',
                    'must_contain' => ['>Romans 8:1-2</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'multiple_refs',
                    'slug' => 'bible',
                    'in' => 'Gen 1:1 and Ex 3:14',
                    'must_contain' => ['>Gen 1:1</a>', '>Ex 3:14</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'dont_link_inside_anchor',
                    'slug' => 'bible',
                    'in' => '<a href="https://example.com">John 3:16</a> and John 3:16',
                    'must_contain' => ['<a href="https://example.com">John 3:16</a>', '>John 3:16</a>'],
                    'must_not_contain' => ['<a href="https://example.com"><a '],
                ],
                [
                    'name' => 'dont_link_inside_anchor_nested_markup',
                    'slug' => 'bible',
                    'in' => '<a href="https://example.com"><span>John 3:16</span></a> and John 3:16',
                    'must_contain' => ['<a href="https://example.com"><span>John 3:16</span></a>', '>John 3:16</a>'],
                    'must_not_contain' => ['<a href="https://example.com"><span><a '],
                ],
                [
                    'name' => 'dont_link_midword',
                    'slug' => 'bible',
                    'in' => 'NotAJohn 3:16 should not link.',
                    'must_contain' => ['NotAJohn 3:16 should not link.'],
                    'must_not_contain' => ['href="'],
                ],
                [
                    'name' => 'dont_link_invalid_chapter',
                    'slug' => 'bible',
                    'in' => 'Gen 0:1 should not link.',
                    'must_contain' => ['Gen 0:1 should not link.'],
                    'must_not_contain' => ['href="'],
                ],
                [
                    'name' => 'dont_link_invalid_verse',
                    'slug' => 'bible',
                    'in' => 'Gen 1:0 should not link.',
                    'must_contain' => ['Gen 1:0 should not link.'],
                    'must_not_contain' => ['href="'],
                ],
            ];

            $failures = [];
            foreach ($cases as $case) {
                $name = is_string($case['name'] ?? null) ? $case['name'] : '';
                $slug = is_string($case['slug'] ?? null) ? $case['slug'] : '';
                $in = is_string($case['in'] ?? null) ? $case['in'] : '';
                $out = self::autolink_content_for_slug($in, $slug);
                if (!is_string($out)) {
                    $failures[] = ['case' => $name, 'reason' => 'output_not_string'];
                    continue;
                }

                foreach (($case['must_contain'] ?? []) as $needle) {
                    if (!is_string($needle) || $needle === '') continue;
                    if (strpos($out, $needle) === false) {
                        $failures[] = ['case' => $name, 'reason' => 'missing_substring', 'needle' => $needle];
                    }
                }
                foreach (($case['must_not_contain'] ?? []) as $needle) {
                    if (!is_string($needle) || $needle === '') continue;
                    if (strpos($out, $needle) !== false) {
                        $failures[] = ['case' => $name, 'reason' => 'forbidden_substring', 'needle' => $needle];
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_autolink_failed', wp_json_encode($failures));
            }
            return true;
        });

        $ok = true;
        foreach ($results as $r) {
            if (!is_array($r) || empty($r['ok'])) {
                $ok = false;
                break;
            }
        }

        $payload = [
            'ok' => $ok,
            'timestamp' => gmdate('c'),
            'plugin' => [
                'version' => defined('DWBIBLE_VERSION') ? DWBIBLE_VERSION : null,
            ],
            'checks' => $results,
        ];

        if ($ok) {
            status_header(200);
        } else {
            status_header(500);
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($payload);
        exit;
    }

    private static function selftest_check($name, $fn) {
        $name = is_string($name) ? $name : '';
        try {
            $res = is_callable($fn) ? $fn() : new WP_Error('dwbible_selftest_not_callable', 'Selftest function not callable.');
            if (is_wp_error($res)) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'error' => [
                        'code' => $res->get_error_code(),
                        'message' => $res->get_error_message(),
                    ],
                ];
            }
            if ($res !== true) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'error' => [
                        'code' => 'dwbible_selftest_failed',
                        'message' => 'Check failed.',
                    ],
                ];
            }
            return [
                'name' => $name,
                'ok' => true,
            ];
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'ok' => false,
                'error' => [
                    'code' => 'dwbible_selftest_exception',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}
