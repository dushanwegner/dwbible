<?php
/**
 * Admin meta box for selecting Bible language per post.
 *
 * @package DwBible
 */

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Admin_Meta {
    /**
     * Add the meta box to all public post types.
     */
    public static function add_bible_meta_box() {
        $screens = get_post_types(['public' => true], 'names');
        foreach ($screens as $post_type) {
            add_meta_box(
                'dwbible_slug',
                __('Bible Version', 'dwbible'),
                [__CLASS__, 'render_bible_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the meta box UI.
     *
     * @param WP_Post $post Current post object.
     */
    public static function render_bible_meta_box($post) {
        wp_nonce_field('dwbible_meta_save', 'dwbible_meta_nonce');
        $current = get_post_meta($post->ID, 'dwbible_slug', true);
        if (!is_string($current) || $current === '') {
            $current = 'bible';
        }
        $options = [
            'bible' => __('English (Douay-Rheims)', 'dwbible'),
            'bibel' => __('Deutsch (Menge)', 'dwbible'),
            'latin' => __('Latin (Vulgata)', 'dwbible'),
        ];
        echo '<p><label for="dwbible_slug_field">' . esc_html__('Default Bible for ambiguous references (e.g. Gen, Dan, Mt). Language-specific names like Matthäus or Matthew are auto-detected.', 'dwbible') . '</label></p>';
        echo '<select name="dwbible_slug_field" id="dwbible_slug_field" class="widefat">';
        foreach ($options as $slug => $label) {
            echo '<option value="' . esc_attr($slug) . '"' . selected($current, $slug, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Save the meta box value.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public static function save_bible_meta($post_id, $post) {
        if (!isset($_POST['dwbible_meta_nonce']) || !wp_verify_nonce($_POST['dwbible_meta_nonce'], 'dwbible_meta_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['dwbible_slug_field'])) return;
        $val = sanitize_text_field(wp_unslash($_POST['dwbible_slug_field']));
        if ($val !== 'bible' && $val !== 'bibel' && $val !== 'latin') {
            delete_post_meta($post_id, 'dwbible_slug');
            return;
        }
        update_post_meta($post_id, 'dwbible_slug', $val);
    }

    /**
     * Add 'Bible' column to post list tables.
     *
     * @param array $columns Existing columns.
     * @return array Updated columns.
     */
    public static function add_bible_column($columns) {
        if (!is_array($columns)) return $columns;
        $columns['dwbible_slug'] = __('Bible', 'dwbible');
        return $columns;
    }

    /**
     * Render the 'Bible' column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function render_bible_column($column, $post_id) {
        if ($column !== 'dwbible_slug') return;
        $slug = get_post_meta($post_id, 'dwbible_slug', true);
        if ($slug === 'bibel') {
            echo esc_html__('Deutsch (Menge)', 'dwbible');
        } elseif ($slug === 'latin') {
            echo esc_html__('Latin (Vulgata)', 'dwbible');
        } elseif ($slug === 'bible') {
            echo esc_html__('English (Douay-Rheims)', 'dwbible');
        } else {
            echo esc_html($slug);
        }
    }
}
