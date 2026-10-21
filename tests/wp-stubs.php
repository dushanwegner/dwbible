<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The few WordPress functions the plugin's pure rendering code calls,
 *         as stubs, and the plugin itself loaded on top of them — so a test can
 *         call DwBible_Plugin's real methods (build_index_html and the like)
 *         with no WordPress, against dwbibledata's real tables.
 * HOW:    require this file; set $GLOBALS['dwtest_query_vars'] for get_query_var().
 *         The tables come from DWBIBLEDATA_DIR (env) or the sibling ../dwbibledata/.
 * RUN:    not a test by itself.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', '/stub/'); }

$dwtest_data = getenv('DWBIBLEDATA_DIR') ?: dirname(__DIR__, 2) . '/dwbibledata/';
$dwtest_data = rtrim($dwtest_data, '/') . '/';
if (!is_dir($dwtest_data . 'data')) { fwrite(STDERR, "no dwbibledata/data at {$dwtest_data}data\n"); exit(2); }
if (!defined('DWBIBLEDATA_DIR')) { define('DWBIBLEDATA_DIR', $dwtest_data); }

$GLOBALS['dwtest_query_vars'] = [];
foreach (['add_action', 'add_filter', 'register_activation_hook', 'register_deactivation_hook', 'add_shortcode', 'is_admin', 'wp_register_script', 'add_rewrite_rule'] as $f) {
    if (!function_exists($f)) { eval("function $f(...\$a) { return false; }"); }
}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://stub.test/'; }
function trailingslashit($s) { return rtrim((string) $s, '/') . '/'; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_attr__($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function home_url($p = '') { return 'https://stub.test' . $p; }
function sanitize_text_field($s) { return $s; }
function wp_unslash($s) { return $s; }
function get_query_var($n, $d = '') { return $GLOBALS['dwtest_query_vars'][$n] ?? $d; }
function set_query_var($n, $v) { $GLOBALS['dwtest_query_vars'][$n] = $v; }
function get_transient($k) { return false; }
function set_transient(...$a) { return true; }
// $GLOBALS['dwtest_options'] = ['name' => value] sets an option for a test.
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['dwtest_options'] ?? []) ? $GLOBALS['dwtest_options'][$k] : $d; }
function apply_filters($t, $v, ...$a) { return $v; }
function wp_cache_get(...$a) { return false; }
function wp_cache_set(...$a) { return true; }

require_once dirname(__DIR__) . '/dwbible.php';
