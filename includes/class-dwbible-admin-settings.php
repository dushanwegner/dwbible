<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Admin_Settings {
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'dwbible';

        $slugs_opt = get_option( 'dwbible_slugs', 'bible,bibel' );
        $active = array_filter( array_map( 'trim', explode( ',', is_string($slugs_opt)?$slugs_opt:'' ) ) );
        $known = [ 'bible' => 'English (Douay)', 'bibel' => 'Deutsch (Menge)' ];
        $og_enabled = get_option('dwbible_og_enabled','1');
        $og_w = intval(get_option('dwbible_og_width',1200));
        $og_h = intval(get_option('dwbible_og_height',630));
        $og_bg = (string) get_option('dwbible_og_bg_color','#111111');
        $og_fg = (string) get_option('dwbible_og_text_color','#ffffff');
        $og_font = (string) get_option('dwbible_og_font_ttf','');
        $og_font_url = (string) get_option('dwbible_og_font_url','');
        $og_size_legacy = intval(get_option('dwbible_og_font_size',40));
        $og_size_main = intval(get_option('dwbible_og_font_size_main', $og_size_legacy?:40));
        $og_size_ref  = intval(get_option('dwbible_og_font_size_ref',  $og_size_legacy?:40));
        $og_min_main  = intval(get_option('dwbible_og_min_font_size_main', 18));
        $og_img = (string) get_option('dwbible_og_background_image_url','');
        // Layout & icon options for settings UI
        $og_pad_x = intval(get_option('dwbible_og_padding_x', 50));
        $og_pad_top = intval(get_option('dwbible_og_padding_top', 50));
        $og_pad_bottom = intval(get_option('dwbible_og_padding_bottom', 50));
        $og_min_gap = intval(get_option('dwbible_og_min_gap', 16));
        $og_icon_url = (string) get_option('dwbible_og_icon_url','');
        $og_logo_side = (string) get_option('dwbible_og_logo_side','left');
        $og_logo_pad_adjust = intval(get_option('dwbible_og_logo_pad_adjust', 0));
        $og_logo_pad_adjust_x = intval(get_option('dwbible_og_logo_pad_adjust_x', $og_logo_pad_adjust));
        $og_logo_pad_adjust_y = intval(get_option('dwbible_og_logo_pad_adjust_y', 0));
        $og_icon_max_w = intval(get_option('dwbible_og_icon_max_w', 160));
        $og_line_main = (string) get_option('dwbible_og_line_height_main','1.35');
        $og_line_main_f = floatval($og_line_main ? $og_line_main : '1.35');
        $og_qL = (string) get_option('dwbible_og_quote_left','»');
        $og_qR = (string) get_option('dwbible_og_quote_right','«');
        $og_refpos = (string) get_option('dwbible_og_ref_position','bottom');
        $og_refalign = (string) get_option('dwbible_og_ref_align','left');

        $autolink_base_url = (string) get_option('dwbible_autolink_base_url', '');
        $autolink_latin_first = get_option('dwbible_autolink_latin_first', '0');

        // Handle footer save (all-at-once)
        if ( isset($_POST['dwbible_footer_nonce_all']) && wp_verify_nonce( $_POST['dwbible_footer_nonce_all'], 'dwbible_footer_save_all' ) && current_user_can('manage_options') ) {
            foreach ($known as $fs => $label) {
                $field = 'dwbible_footer_text_' . $fs;
                $ft = isset($_POST[$field]) ? (string) wp_unslash( $_POST[$field] ) : '';
                // New preferred location
                $root = dwbible_data_dir() . $fs . '/';
                $ok = is_dir($root) || wp_mkdir_p($root);
                if ( $ok ) {
                    @file_put_contents( trailingslashit($root) . 'copyright.md', $ft );
                } else {
                    // Legacy fallback
                    $dir = dwbible_data_dir() . $fs . '_books_html/';
                    if ( is_dir($dir) || wp_mkdir_p($dir) ) {
                        @file_put_contents( trailingslashit($dir) . 'copyright.txt', $ft );
                    }
                }
            }
            echo '<div class="updated notice"><p>Footers saved.</p></div>';
        }
        // Handle OG layout reset to safe defaults
        if ( isset($_POST['dwbible_og_reset_defaults_nonce']) && wp_verify_nonce($_POST['dwbible_og_reset_defaults_nonce'],'dwbible_og_reset_defaults') && current_user_can('manage_options') ) {
            update_option('dwbible_og_enabled', '1');
            update_option('dwbible_og_width', 1600);
            update_option('dwbible_og_height', 900);
            update_option('dwbible_og_bg_color', '#111111');
            update_option('dwbible_og_text_color', '#ffffff');
            update_option('dwbible_og_font_size', 60);
            update_option('dwbible_og_font_size_main', 60);
            update_option('dwbible_og_font_size_ref', 40);
            update_option('dwbible_og_min_font_size_main', 24);
            update_option('dwbible_og_padding_x', 60);
            update_option('dwbible_og_padding_top', 60);
            update_option('dwbible_og_padding_bottom', 60);
            update_option('dwbible_og_min_gap', 30);
            update_option('dwbible_og_line_height_main', '1.35');
            update_option('dwbible_og_logo_side', 'left');
            update_option('dwbible_og_logo_pad_adjust', 0);
            update_option('dwbible_og_logo_pad_adjust_x', 0);
            update_option('dwbible_og_logo_pad_adjust_y', 0);
            update_option('dwbible_og_icon_max_w', 200);
            update_option('dwbible_og_quote_left', '«');
            update_option('dwbible_og_quote_right', '»');
            update_option('dwbible_og_ref_position', 'bottom');
            update_option('dwbible_og_ref_align', 'left');
            // Note: font_url, icon_url, background_image_url are NOT reset to preserve user uploads
            $deleted = DwBible_OG_Image::og_cache_purge();
            echo '<div class="updated notice"><p>OG layout and typography reset to safe defaults (1600×900). Cache cleared (' . intval($deleted) . ' files removed).</p></div>';
        }
        // Handle cache purge
        if ( isset($_POST['dwbible_og_purge_cache_nonce']) && wp_verify_nonce($_POST['dwbible_og_purge_cache_nonce'],'dwbible_og_purge_cache') && current_user_can('manage_options') ) {
            $deleted = DwBible_OG_Image::og_cache_purge();
            echo '<div class="updated notice"><p>OG image cache cleared (' . intval($deleted) . ' files removed).</p></div>';
        }
        if ( isset($_POST['dwbible_regen_sitemaps_nonce']) && wp_verify_nonce($_POST['dwbible_regen_sitemaps_nonce'],'dwbible_regen_sitemaps') && current_user_can('manage_options') ) {
            $slugs = DwBible_Plugin::base_slugs();
            foreach ($slugs as $slug) {
                $slug = trim($slug, "/ ");
                if ($slug !== 'bible' && $slug !== 'bibel') continue;
                $path = ($slug === 'bible') ? '/bible-sitemap-bible.xml' : '/bible-sitemap-bibel.xml';
                $url = home_url($path);
                wp_remote_get($url, ['timeout' => 10]);
            }
            echo '<div class="updated notice"><p>Bible sitemaps refreshed. If generation is heavy, it may take a moment for all URLs to be crawled.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>The Bible</h1>

            <?php if ( $page === 'dwbible' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'dwbible_options' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label>Active bibles</label></th>
                            <td>
                                <?php foreach ( $known as $slug => $label ): $checked = in_array($slug, $active, true); ?>
                                    <label style="display:block;margin:.2em 0;">
                                        <input type="checkbox" name="dwbible_slugs_list[]" value="<?php echo esc_attr($slug); ?>" <?php checked( $checked ); ?>>
                                        <code>/<?php echo esc_html($slug); ?>/</code> — <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <input type="hidden" name="dwbible_slugs" id="dwbible_slugs" value="<?php echo esc_attr( implode(',', $active ) ); ?>">
                                <script>(function(){function sync(){var boxes=document.querySelectorAll('input[name="dwbible_slugs_list[]"]');var out=[];boxes.forEach(function(b){if(b.checked) out.push(b.value);});document.getElementById('dwbible_slugs').value=out.join(',');}document.addEventListener('change',function(e){if(e.target && e.target.name==='dwbible_slugs_list[]'){sync();}});document.addEventListener('DOMContentLoaded',sync);})();</script>
                                <p class="description">Select which bibles are publicly accessible. Others remain installed but routed pages are disabled.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="dwbible_autolink_base_url">Autolink base URL</label></th>
                            <td>
                                <input type="url" class="regular-text" name="dwbible_autolink_base_url" id="dwbible_autolink_base_url" value="<?php echo esc_attr($autolink_base_url); ?>" placeholder="https://bible.example.com">
                                <p class="description">Leave empty to link to this site. Set to a full URL (e.g. <code>https://bible.example.com</code>) to redirect bible pages and point autolinks to an external site.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="dwbible_autolink_latin_first">Latin interlinear</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dwbible_autolink_latin_first" id="dwbible_autolink_latin_first" value="1" <?php checked($autolink_latin_first === '1'); ?>>
                                    Link to Latin-first interlinear pages
                                </label>
                                <p class="description">When enabled, autolinks point to interlinear pages with Latin as the primary text (e.g. <code>/latin-bible/genesis/1:1</code>). The second language is auto-detected from the reference. Books without a Latin version link normally.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label>Sitemaps</label></th>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('dwbible_regen_sitemaps','dwbible_regen_sitemaps_nonce'); ?>
                                    <button type="submit" class="button">Refresh Bible sitemaps</button>
                                </form>
                                <?php
                                $active_slugs = $active;
                                $links = [];
                                if (in_array('bible', $active_slugs, true)) {
                                    $links[] = '<a href="' . esc_url( home_url('/bible-sitemap-bible.xml') ) . '" target="_blank" rel="noopener noreferrer">English sitemap</a>';
                                }
                                if (in_array('bibel', $active_slugs, true)) {
                                    $links[] = '<a href="' . esc_url( home_url('/bible-sitemap-bibel.xml') ) . '" target="_blank" rel="noopener noreferrer">German sitemap</a>';
                                }
                                ?>
                                <p class="description">Triggers regeneration of per-verse Bible sitemaps for active bibles by requesting their sitemap URLs on the server. <?php if (!empty($links)) { echo 'View: ' . implode(' | ', $links); } ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_export_bible_slug">Export Bible as .txt</label></th>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                                    <?php wp_nonce_field('dwbible_export_bible','dwbible_export_bible_nonce'); ?>
                                    <input type="hidden" name="action" value="dwbible_export_bible">
                                    <label for="dwbible_export_bible_slug">Bible:</label>
                                    <select name="dwbible_export_bible_slug" id="dwbible_export_bible_slug">
                                        <?php foreach ($known as $slug => $label): ?>
                                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button">Download .txt</button>
                                    <p class="description">Downloads a plain-text file with one verse per line in a machine-friendly format.</p>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php elseif ( $page === 'dwbible_og' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'dwbible_options' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>

                        <tr>
                            <th scope="row"><label>Quotation marks</label></th>
                            <td>
                                <p><strong>OG images and widgets always use fixed outer guillemets:</strong> opening &#187; and closing &#171;. These marks are not configurable.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_ref_position">Reference position</label></th>
                            <td>
                                <select name="dwbible_og_ref_position" id="dwbible_og_ref_position">
                                    <option value="bottom" <?php selected($og_refpos==='bottom'); ?>>Bottom</option>
                                    <option value="top" <?php selected($og_refpos==='top'); ?>>Top</option>
                                </select>
                                &nbsp;
                                <label for="dwbible_og_ref_align">Alignment</label>
                                <select name="dwbible_og_ref_align" id="dwbible_og_ref_align">
                                    <option value="left" <?php selected($og_refalign==='left'); ?>>Left</option>
                                    <option value="right" <?php selected($og_refalign==='right'); ?>>Right</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_enabled">Social image (Open Graph)</label></th>
                            <td>
                                <label><input type="checkbox" name="dwbible_og_enabled" id="dwbible_og_enabled" value="1" <?php checked($og_enabled==='1'); ?>> Enable dynamic image for verse URLs</label>
                                <p class="description">Generates a PNG for <code>og:image</code> when a URL includes chapter and verse, e.g. <code>/bible/john/3:16</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_width">Image size</label></th>
                            <td>
                                <input type="number" min="100" name="dwbible_og_width" id="dwbible_og_width" value="<?php echo esc_attr($og_w); ?>" style="width:7em;"> ×
                                <input type="number" min="100" name="dwbible_og_height" id="dwbible_og_height" value="<?php echo esc_attr($og_h); ?>" style="width:7em;"> px
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_bg_color">Colors</label></th>
                            <td>
                                <input type="text" name="dwbible_og_bg_color" id="dwbible_og_bg_color" value="<?php echo esc_attr($og_bg); ?>" placeholder="#111111" style="width:8em;"> background
                                <span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;border:1px solid #ccc;background:<?php echo esc_attr($og_bg); ?>"></span>
                                &nbsp; <input type="text" name="dwbible_og_text_color" id="dwbible_og_text_color" value="<?php echo esc_attr($og_fg); ?>" placeholder="#ffffff" style="width:8em;"> text
                                <span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;border:1px solid #ccc;background:<?php echo esc_attr($og_fg); ?>"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_font_ttf">Font</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Server path: <input type="text" name="dwbible_og_font_ttf" id="dwbible_og_font_ttf" value="<?php echo esc_attr($og_font); ?>" class="regular-text" placeholder="/path/to/font.ttf"></label>
                                </p>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Or uploaded URL: <input type="url" name="dwbible_og_font_url" id="dwbible_og_font_url" value="<?php echo esc_attr($og_font_url); ?>" class="regular-text" placeholder="https://.../yourfont.ttf"></label>
                                    <button type="button" class="button" id="dwbible_pick_font">Select/upload font</button>
                                </p>
                                <p class="description">TTF/OTF recommended. If path is invalid, the uploader URL will be mapped to a local file under Uploads. Without a valid font file, non‑ASCII quotes may fall back to straight quotes.</p>
                                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                                    <label>Max main size <input type="number" min="8" name="dwbible_og_font_size_main" id="dwbible_og_font_size_main" value="<?php echo esc_attr($og_size_main); ?>" style="width:6em;"></label>
                                    <label>Min main size <input type="number" min="8" name="dwbible_og_min_font_size_main" id="dwbible_og_min_font_size_main" value="<?php echo esc_attr($og_min_main); ?>" style="width:6em;"></label>
                                    <label>Max source size <input type="number" min="8" name="dwbible_og_font_size_ref" id="dwbible_og_font_size_ref" value="<?php echo esc_attr($og_size_ref); ?>" style="width:6em;"></label>
                                    <label>Line height (main) <input type="number" step="0.05" min="1" name="dwbible_og_line_height_main" id="dwbible_og_line_height_main" value="<?php echo esc_attr($og_line_main); ?>" style="width:6em;"></label>
                                </div>
                                <p class="description">Main text auto-shrinks between Max and Min. If still too long at Min, it is truncated with … Source uses up to its max size and wraps as needed.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Cache</label></th>
                            <td>
                                <form method="post" style="display:inline;margin-right:0.5em;">
                                    <?php wp_nonce_field('dwbible_og_purge_cache','dwbible_og_purge_cache_nonce'); ?>
                                    <button type="submit" class="button">Clear cached images</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('dwbible_og_reset_defaults','dwbible_og_reset_defaults_nonce'); ?>
                                    <button type="submit" class="button button-secondary">Reset layout to safe defaults</button>
                                </form>
                                <p class="description">Cached OG images are stored under Uploads/dwbible-og-cache and reused for identical requests. Clear the cache after changing design settings. Use the reset button if layout values became extreme and the verse/logo no longer show.</p>
                                <p class="description">For a one-off debug render that skips the cache, append <code>&dwbible_og_nocache=1</code> to a verse URL that already has <code>dwbible_og=1</code>, for example: <code>?dwbible_og=1&amp;dwbible_og_nocache=1</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_background_image_url">Background image</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <input type="url" name="dwbible_og_background_image_url" id="dwbible_og_background_image_url" value="<?php echo esc_attr($og_img); ?>" class="regular-text" placeholder="https://.../image.jpg">
                                    <button type="button" class="button" id="dwbible_pick_bg">Select/upload image</button>
                                </p>
                                <p class="description">Optional. If set, the image is used as a cover background with a dark overlay for readability.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Layout</label></th>
                            <td>
                                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                                    <label>Side padding <input type="number" min="0" name="dwbible_og_padding_x" id="dwbible_og_padding_x" value="<?php echo esc_attr($og_pad_x); ?>" style="width:6em;"> px</label>
                                    <label>Top padding <input type="number" min="0" name="dwbible_og_padding_top" id="dwbible_og_padding_top" value="<?php echo esc_attr($og_pad_top); ?>" style="width:6em;"> px</label>
                                    <label>Bottom padding <input type="number" min="0" name="dwbible_og_padding_bottom" id="dwbible_og_padding_bottom" value="<?php echo esc_attr($og_pad_bottom); ?>" style="width:6em;"> px</label>
                                    <label>Min gap text↔source <input type="number" min="0" name="dwbible_og_min_gap" id="dwbible_og_min_gap" value="<?php echo esc_attr($og_min_gap); ?>" style="width:6em;"> px</label>
                                </div>
                                <p class="description">Set exact paddings for sides, top, and bottom. The min gap enforces spacing between the main text and the bottom row.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwbible_og_icon_url">Icon</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Icon URL: <input type="url" name="dwbible_og_icon_url" id="dwbible_og_icon_url" value="<?php echo esc_attr($og_icon_url); ?>" class="regular-text" placeholder="https://.../icon.png"></label>
                                    <button type="button" class="button" id="dwbible_pick_icon">Select/upload image</button>
                                </p>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Logo side 
                                        <select name="dwbible_og_logo_side" id="dwbible_og_logo_side">
                                            <option value="left" <?php selected($og_logo_side==='left'); ?>>Left</option>
                                            <option value="right" <?php selected($og_logo_side==='right'); ?>>Right</option>
                                        </select>
                                    </label>
                                    &nbsp;
                                    <label>Logo padding X <input type="number" name="dwbible_og_logo_pad_adjust_x" id="dwbible_og_logo_pad_adjust_x" value="<?php echo esc_attr($og_logo_pad_adjust_x); ?>" style="width:6em;"> px</label>
                                    &nbsp;
                                    <label>Logo padding Y <input type="number" name="dwbible_og_logo_pad_adjust_y" id="dwbible_og_logo_pad_adjust_y" value="<?php echo esc_attr($og_logo_pad_adjust_y); ?>" style="width:6em;"> px</label>
                                    &nbsp;
                                    <label>Max width <input type="number" min="1" name="dwbible_og_icon_max_w" id="dwbible_og_icon_max_w" value="<?php echo esc_attr($og_icon_max_w); ?>" style="width:6em;"> px</label>
                                </p>
                                <p class="description">Logo and source are always at the bottom. Choose which side holds the logo; the source uses the other side. Logo padding X/Y shift the logo relative to side/bottom padding (can be negative). Use raster images such as PNG or JPEG; SVG and other vector formats are not supported by the image renderer.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php endif; // $page === 'dwbible' / 'dwbible_og' ?>

            <?php if ( $page === 'dwbible_footers' ) : ?>

            <h2>Per‑Bible footers</h2>
            <form method="post">
                <?php wp_nonce_field('dwbible_footer_save_all', 'dwbible_footer_nonce_all'); ?>
                <p class="description">Preferred location: <code>wp-content/plugins/dwbible/data/{slug}/copyright.md</code>. Legacy fallback: <code>data/{slug}_books_html/copyright.txt</code>.</p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($known as $slug => $label): ?>
                        <?php
                            // Load existing footer for display
                            $root = dwbible_data_dir() . $slug . '/';
                            $val = '';
                            if ( file_exists( $root . 'copyright.md' ) ) {
                                $val = (string) file_get_contents( $root . 'copyright.md' );
                            } else {
                                $legacy = dwbible_data_dir() . $slug . '_books_html/copyright.txt';
                                if ( file_exists( $legacy ) ) { $val = (string) file_get_contents( $legacy ); }
                            }
                        ?>
                        <tr>
                            <th scope="row"><label for="dwbible_footer_text_<?php echo esc_attr($slug); ?>"><?php echo esc_html('/' . $slug . '/ — ' . $label); ?></label></th>
                            <td>
                                <textarea name="dwbible_footer_text_<?php echo esc_attr($slug); ?>" id="dwbible_footer_text_<?php echo esc_attr($slug); ?>" class="large-text code" rows="6" style="font-family:monospace;"><?php echo esc_textarea( $val ); ?></textarea>
                                <p class="description">Markdown supported for links and headings; line breaks are preserved.</p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Save Footers'); ?>
            </form>

            <h2>CSS reference</h2>
            <div class="dwbible-css-reference" style="max-width:900px;">
                <p>Selectors you can target:</p>
                <ul style="list-style:disc;margin-left:1.2em;">
                    <li><code>.dwbible</code> wrapper on all plugin output</li>
                    <li><code>.dwbible-index</code> on /bible</li>
                    <li><code>.dwbible-book</code> around a rendered book</li>
                    <li><code>.chapters</code> list of chapter links on top of a book</li>
                    <li><code>.verses</code> blocks of verses</li>
                    <li><code>.verse</code> each verse paragraph (added at render time)</li>
                    <li><code>.verse-num</code> the verse number span within a verse paragraph</li>
                    <li><code>.verse-body</code> the verse text span within a verse paragraph</li>
                    <li><code>.verse-highlight</code> added when a verse is highlighted from a URL fragment</li>
                    <li><code>.dwbible-sticky</code> top status bar with chapter info and controls
                        <ul style="list-style:circle;margin-left:1.2em;">
                            <li><code>.dwbible-sticky__left</code>, <code>[data-label]</code>, <code>[data-ch]</code></li>
                            <li><code>.dwbible-sticky__controls</code> with <code>.dwbible-ctl</code> buttons (<code>[data-prev]</code>, <code>[data-top]</code>, <code>[data-next]</code>)</li>
                            <li><code>.dwbible-ch-picker</code> chapter number button (opens chapter grid)</li>
                            <li><code>.dwbible-ch-grid</code> chapter selection grid overlay</li>
                        </ul>
                    </li>
                    <li><code>.dwbible-up</code> small up-arrow links inserted before chapters/verses</li>
                </ul>
                <p>Anchors and IDs:</p>
                <ul style="list-style:disc;margin-left:1.2em;">
                    <li>At very top of each book: <code>#dwbible-book-top</code></li>
                    <li>Chapter headings: <code>h2[id^="{book-slug}-ch-"]</code>, e.g. <code>#sophonias-ch-3</code></li>
                    <li>Verse paragraphs: <code>p[id^="{book-slug}-"]</code> with pattern <code>{slug}-{chapter}-{verse}</code>, e.g. <code>#sophonias-3-5</code></li>
                </ul>
            </div>

            <?php endif; // $page === 'dwbible_footers' ?>
        </div>
        <?php
    }
}
