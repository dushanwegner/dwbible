<?php
/**
 * TheBible VOTD RSS Feed
 *
 * Handles Verse of the Day RSS feed generation: language resolution,
 * date formatting, verse URL building, and RSS XML output.
 *
 * Extracted from TheBible_Plugin to keep the main class focused.
 */

if (!defined('ABSPATH')) exit;

class TheBible_VOTD_RSS {

    /**
     * Get the list of available language slugs from plugin settings.
     *
     * @return string[] e.g. ['bible', 'bibel']
     */
    public static function available_language_slugs() {
        $list = get_option('thebible_slugs', 'bible,bibel');
        if (!is_string($list)) {
            $list = 'bible';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $list))));
        if (empty($parts)) {
            $parts = ['bible'];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = sanitize_key($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        if (empty($out)) {
            $out = ['bible'];
        }
        return $out;
    }

    /**
     * Format a YYYY-MM-DD date string according to the RSS date format setting.
     *
     * @param string $date_ymd Date in Y-m-d format.
     * @return string Formatted date, or original string on parse failure.
     */
    public static function format_date($date_ymd) {
        if (!is_string($date_ymd) || $date_ymd === '') {
            return '';
        }
        $ts = strtotime($date_ymd . ' 00:00:00');
        if (!$ts) {
            return $date_ymd;
        }
        $mode = (string) get_option('thebible_votd_rss_date_format', 'site');
        if ($mode === 'de_numeric') {
            return date_i18n('j.n.Y', $ts);
        }
        if ($mode === 'ymd') {
            return date_i18n('Y-m-d', $ts);
        }
        return date_i18n(get_option('date_format'), $ts);
    }

    /**
     * Build a verse URL for the RSS feed item.
     *
     * Resolves the book slug for the primary language dataset and builds
     * a combined-language URL if two languages are configured.
     *
     * @param string $canonical_book_slug Canonical book key (e.g. 'genesis').
     * @param int    $chapter             Chapter number.
     * @param int    $vfrom               First verse.
     * @param int    $vto                 Last verse.
     * @param string $lang_first          Primary language slug (e.g. 'bible').
     * @param string $lang_last           Secondary language slug (or same as primary).
     * @return string Absolute URL to the verse.
     */
    public static function build_verse_url($canonical_book_slug, $chapter, $vfrom, $vto, $lang_first, $lang_last) {
        $lang_first = sanitize_key($lang_first);
        $lang_last = sanitize_key($lang_last);
        if ($lang_first === '') {
            $lang_first = 'bible';
        }
        if ($lang_last === '') {
            $lang_last = $lang_first;
        }
        $link_slug = ($lang_last !== $lang_first) ? ($lang_first . '-' . $lang_last) : $lang_first;

        $book_for_url = TheBible_Plugin::resolve_book_for_dataset($canonical_book_slug, $lang_first);
        if (!is_string($book_for_url) || $book_for_url === '') {
            $book_for_url = $canonical_book_slug;
        }
        $book_slug_for_url = TheBible_Plugin::slugify($book_for_url);
        if (!is_string($book_slug_for_url) || $book_slug_for_url === '') {
            $book_slug_for_url = (string) $canonical_book_slug;
        }

        $path_ref = '/' . trim($link_slug, '/') . '/' . trim($book_slug_for_url, '/') . '/' . (int) $chapter . ':' . (int) $vfrom . ((int) $vto > (int) $vfrom ? ('-' . (int) $vto) : '');
        return home_url($path_ref);
    }

    /**
     * Render the VOTD RSS feed and exit.
     *
     * Fetches recent VOTD entries, resolves language settings, builds XML items,
     * and outputs the full RSS 2.0 document.
     */
    public static function render() {
        $days = (int) get_option('thebible_votd_rss_days', 7);
        if ($days <= 0) {
            $days = 7;
        }
        if ($days > 31) {
            $days = 31;
        }

        $today = current_time('Y-m-d');
        $entries = [];
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime($today . ' -' . $i . ' day'));
            $ref = TheBible_VOTD_Admin::get_votd_for_date($d);
            if (is_array($ref)) {
                $entries[] = $ref;
            }
        }

        if (empty($entries)) {
            status_header(404);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No Verse of the Day available.';
            exit;
        }

        $available = self::available_language_slugs();
        $lang_first = sanitize_key((string) get_option('thebible_votd_rss_lang_first', 'bible'));
        $lang_last = sanitize_key((string) get_option('thebible_votd_rss_lang_last', ''));
        if (!in_array($lang_first, $available, true)) {
            $lang_first = $available[0];
        }
        if ($lang_last === '') {
            $lang_last = $lang_first;
        }
        if (!in_array($lang_last, $available, true)) {
            $lang_last = $lang_first;
        }
        $langs_to_show = ($lang_last !== $lang_first) ? [$lang_first, $lang_last] : [$lang_first];

        // Emit RSS
        status_header(200);
        nocache_headers();
        header('Content-Type: application/rss+xml; charset=UTF-8');

        $channel_title = (string) get_option('thebible_votd_rss_title', 'Verse of the Day');
        if (!is_string($channel_title) || $channel_title === '') {
            $channel_title = 'Verse of the Day';
        }

        $site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
        if (!is_string($site_name)) {
            $site_name = '';
        }

        $feed_title = $site_name !== '' ? ($site_name . ' — ' . $channel_title) : $channel_title;
        $feed_link = home_url('/bible-votd.rss');

        $ts_pub = time();
        if (isset($entries[0]['date']) && is_string($entries[0]['date']) && $entries[0]['date'] !== '') {
            $ts_pub_cand = strtotime($entries[0]['date'] . ' 00:00:00');
            if ($ts_pub_cand) {
                $ts_pub = $ts_pub_cand;
            }
        }

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<rss version="2.0">' . "\n";
        echo '  <channel>' . "\n";
        echo '    <title>' . esc_html($feed_title) . '</title>' . "\n";
        echo '    <link>' . esc_url($feed_link) . '</link>' . "\n";
        echo '    <description>' . esc_html($channel_title) . '</description>' . "\n";
        echo '    <language>' . esc_html(get_bloginfo('language')) . '</language>' . "\n";
        echo '    <lastBuildDate>' . esc_html(gmdate('r', $ts_pub)) . '</lastBuildDate>' . "\n";

        $tpl = (string) get_option('thebible_votd_rss_description_tpl', '{date} — {verse}');
        foreach ($entries as $ref) {
            self::render_rss_item($ref, $langs_to_show, $lang_first, $lang_last, $tpl);
        }
        echo '  </channel>' . "\n";
        echo '</rss>';
        exit;
    }

    /**
     * Render a single RSS <item> element.
     */
    private static function render_rss_item($ref, $langs_to_show, $lang_first, $lang_last, $tpl) {
        if (!is_array($ref)) {
            return;
        }
        $canonical = isset($ref['book_slug']) ? $ref['book_slug'] : '';
        $chapter   = isset($ref['chapter']) ? (int) $ref['chapter'] : 0;
        $vfrom     = isset($ref['vfrom']) ? (int) $ref['vfrom'] : 0;
        $vto       = isset($ref['vto']) ? (int) $ref['vto'] : 0;
        $date      = isset($ref['date']) ? $ref['date'] : '';
        $texts     = isset($ref['texts']) && is_array($ref['texts']) ? $ref['texts'] : [];

        if (!is_string($canonical) || $canonical === '' || $chapter <= 0 || $vfrom <= 0) {
            return;
        }
        if ($vto <= 0 || $vto < $vfrom) {
            $vto = $vfrom;
        }

        // Title: always reference label based on English dataset
        $short_en = TheBible_Plugin::resolve_book_for_dataset($canonical, 'bible');
        if (!is_string($short_en) || $short_en === '') {
            $label = ucwords(str_replace('-', ' ', (string) $canonical));
        } else {
            $label = TheBible_Plugin::pretty_label($short_en);
        }
        $ref_str = $label . ' ' . $chapter . ':' . ($vfrom === $vto ? $vfrom : ($vfrom . '-' . $vto));

        $display_date = self::format_date($date);
        $url_ref = self::build_verse_url($canonical, $chapter, $vfrom, $vto, $lang_first, $lang_last);
        $image_url = add_query_arg(['thebible_og' => 1], $url_ref);

        $t1 = '';
        $t2 = '';
        if (isset($langs_to_show[0]) && isset($texts[$langs_to_show[0]]) && is_string($texts[$langs_to_show[0]])) {
            $t1 = TheBible_Plugin::clean_verse_text_for_output($texts[$langs_to_show[0]], false, '»', '«');
        }
        if (isset($langs_to_show[1]) && isset($texts[$langs_to_show[1]]) && is_string($texts[$langs_to_show[1]])) {
            $t2 = TheBible_Plugin::clean_verse_text_for_output($texts[$langs_to_show[1]], false, '»', '«');
        }
        $desc = strtr($tpl, [
            '{date}' => (string) $display_date,
            '{verse}' => (string) $ref_str,
            '{text1}' => (string) $t1,
            '{text2}' => (string) $t2,
            '{url}' => (string) $url_ref,
        ]);

        $ts_item = $date !== '' ? strtotime($date . ' 00:00:00') : time();
        if (!$ts_item) {
            $ts_item = time();
        }

        echo '    <item>' . "\n";
        echo '      <title>' . esc_html($ref_str) . '</title>' . "\n";
        echo '      <link>' . esc_url($url_ref) . '</link>' . "\n";
        echo '      <guid isPermaLink="false">' . esc_html($url_ref . '|votd|' . (string) $date) . '</guid>' . "\n";
        echo '      <pubDate>' . esc_html(gmdate('r', $ts_item)) . '</pubDate>' . "\n";
        echo '      <enclosure url="' . esc_url($image_url) . '" type="image/png" />' . "\n";
        echo '      <description><![CDATA[' . $desc . ']]></description>' . "\n";
        echo '    </item>' . "\n";
    }
}
