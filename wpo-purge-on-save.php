<?php
/**
 * Plugin Name: WPO purge on save
 * Description: Flush the WP-Optimize page cache on every post/page/product save.
 *
 * qTranslate serves language-prefixed URLs (/ro/...) that WP-Optimize's own
 * single-URL purge on post edit misses, so visitors kept getting stale pages
 * after manual edits in wp-admin. Full purge is cheap on a site this size.
 * Deployed to wp-content/mu-plugins/; source lives in the nc project root.
 */

add_action('save_post', function ($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (get_post_status($post_id) === 'auto-draft') return;
    if (function_exists('WP_Optimize') && is_callable(array(WP_Optimize(), 'get_page_cache'))) {
        WP_Optimize()->get_page_cache()->purge();
    }
}, 100);
