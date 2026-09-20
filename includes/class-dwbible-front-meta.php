<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Front_Meta {
    public static function print_custom_css() {
        $is_bible = get_query_var(DwBible_Plugin::QV_FLAG);
        if (!$is_bible) {
            return;
        }
        $footer_css = get_option('dwbible_footer_css', '');
        $out = '';
        if (is_string($footer_css) && $footer_css !== '') {
            $out .= $footer_css . "\n";
        }
        if ($out !== '') {
            echo '<style id="dwbible-custom-css">' . $out . '</style>';
        }
    }

    // The Bible's social card is NOT written here. dwsocial is the site's one card emitter, and a
    // second one doubles og:title — which X resolves by honouring the first and ignoring the rest.
    // dwbible feeds that single emitter instead, through `dwsocial_site_card_page_facts`
    // (DwBible_Plugin::site_card_page_facts): the verse text as the description, and the verse's
    // drawn picture as `image`. Going through the card also keeps og:site_name, og:locale and the
    // locale alternates, which a card printed here would drop.
}
