<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_SelfTest_Trait {
    public static function render_selftest() {
        $results = [];

        $results[] = self::selftest_check('wp_loaded', function() {
            return function_exists('get_option');
        });

        $results[] = self::selftest_check('og_renderer_class_exists', function() {
            return class_exists('TheBible_OG_Image') && method_exists('TheBible_OG_Image', 'render');
        });

        $results[] = self::selftest_check('osis_mapping_json_valid', function() {
            $file = plugin_dir_path(__FILE__) . 'osis-mapping.json';
            if (!file_exists($file) || !is_readable($file)) {
                return new WP_Error('thebible_selftest_missing_osis_mapping', 'OSIS mapping JSON missing/unreadable.');
            }
            $raw = file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                return new WP_Error('thebible_selftest_empty_osis_mapping', 'OSIS mapping JSON empty.');
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['books']) || !is_array($data['books'])) {
                return new WP_Error('thebible_selftest_invalid_osis_mapping', 'OSIS mapping JSON invalid.');
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
            if (!class_exists('TheBible_Text_Utils')) {
                return new WP_Error('thebible_selftest_text_utils_missing', 'Text utils class missing (TheBible_Text_Utils).');
            }
            if (!method_exists('TheBible_Text_Utils', 'normalize_whitespace') || !method_exists('TheBible_Text_Utils', 'clean_verse_text_for_output')) {
                return new WP_Error('thebible_selftest_text_utils_incomplete', 'Text utils methods missing.');
            }

            $s = "Hello\xC2\xA0world";
            $norm = TheBible_Text_Utils::normalize_whitespace($s);
            if ($norm !== 'Hello world') {
                return new WP_Error('thebible_selftest_text_utils_norm_failed', 'normalize_whitespace did not normalize NBSP.');
            }

            $q = TheBible_Text_Utils::clean_verse_text_for_output('»Test', false, '»', '«');
            if (!is_string($q) || $q === '') {
                return new WP_Error('thebible_selftest_text_utils_quote_failed', 'clean_verse_text_for_output returned empty output.');
            }
            // After balancing/normalization, the output should contain either an inner or outer closing guillemet.
            if (strpos($q, '«') === false && strpos($q, '‹') === false) {
                return new WP_Error('thebible_selftest_text_utils_quote_failed', 'clean_verse_text_for_output did not synthesize a closing quote.');
            }

            return true;
        });

        $results[] = self::selftest_check('autolinker_cases', function() {
            if (!method_exists(__CLASS__, 'autolink_content_for_slug')) {
                return new WP_Error('thebible_selftest_autolink_missing', 'Auto-linker helper missing (autolink_content_for_slug).');
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
                return new WP_Error('thebible_selftest_autolink_failed', wp_json_encode($failures));
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
                'version' => defined('THEBIBLE_VERSION') ? THEBIBLE_VERSION : null,
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
            $res = is_callable($fn) ? $fn() : new WP_Error('thebible_selftest_not_callable', 'Selftest function not callable.');
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
                        'code' => 'thebible_selftest_failed',
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
                    'code' => 'thebible_selftest_exception',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}
