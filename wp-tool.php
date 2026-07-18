<?php
/**
 * wp-tool.php — token-protected WordPress admin helper.
 * Lives in public_html/app/, bootstraps WP from ../
 *
 * All requests require ?token=... (or JSON body "token").
 * Actions:
 *   GET  ?action=get_post&id=123          — raw post (qTranslate markers intact)
 *   GET  ?action=get_post&slug=foo&type=post
 *   POST ?action=create_post              — body: {title, content, slug?, status?, type?, meta?}
 *   POST ?action=update_post&id=123       — body: {title?, content?, status?, meta?}
 *   GET  ?action=get_product&id=123       — product core fields
 *   GET  ?action=find_products&s=foo      — search products by title
 *   POST ?action=create_product           — body: {name, regular_price, sku?, status?, description?, short_description?, virtual?}
 *   POST ?action=update_product&id=123    — body: {name?, regular_price?, status?, ...}
 */

define('WPT_TOKEN', '8ef67ed4b35854751f9d24e091342537f1c2999ba9d414b8');

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: array();
$token = isset($_GET['token']) ? $_GET['token'] : (isset($body['token']) ? $body['token'] : '');
if (!hash_equals(WPT_TOKEN, (string)$token)) {
    http_response_code(403);
    echo json_encode(array('error' => 'forbidden'));
    exit;
}

// Bootstrap WordPress without theme output.
// DOING_AJAX stops qTranslate from issuing a language redirect mid-bootstrap.
define('WP_USE_THEMES', false);
define('DOING_AJAX', true);
require_once __DIR__ . '/../wp-load.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

function wpt_out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function wpt_err($msg, $code = 400) { http_response_code($code); wpt_out(array('error' => $msg)); }

function wpt_purge_page_cache() {
    if (function_exists('WP_Optimize') && is_callable(array(WP_Optimize(), 'get_page_cache'))) {
        WP_Optimize()->get_page_cache()->purge();
    }
}

function wpt_post_payload($p) {
    return array(
        'id'      => $p->ID,
        'type'    => $p->post_type,
        'status'  => $p->post_status,
        'slug'    => $p->post_name,
        'title'   => $p->post_title,     // raw, qTranslate markers intact
        'content' => $p->post_content,   // raw
        'excerpt' => $p->post_excerpt,
        'parent'  => $p->post_parent,
        'template'=> get_page_template_slug($p->ID),
        'link'    => get_permalink($p->ID),
    );
}

switch ($action) {

case 'get_post': {
    if (!empty($_GET['id'])) {
        $p = get_post((int)$_GET['id']);
    } else {
        $type = !empty($_GET['type']) ? $_GET['type'] : 'post';
        $found = get_posts(array('name' => sanitize_title($_GET['slug'] ?? ''), 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1));
        $p = $found ? $found[0] : null;
    }
    if (!$p) wpt_err('not found', 404);
    $out = wpt_post_payload($p);
    $out['meta'] = array_map(function($v){ return count($v) === 1 ? $v[0] : $v; }, get_post_meta($p->ID));
    wpt_out($out);
}

case 'create_post': {
    if (empty($body['title']) || empty($body['content'])) wpt_err('title and content required');
    $args = array(
        'post_title'   => $body['title'],
        'post_content' => $body['content'],
        'post_status'  => isset($body['status']) ? $body['status'] : 'draft',
        'post_type'    => isset($body['type']) ? $body['type'] : 'post',
    );
    if (!empty($body['slug']))     $args['post_name']    = $body['slug'];
    if (!empty($body['excerpt']))  $args['post_excerpt'] = $body['excerpt'];
    if (!empty($body['template'])) $args['page_template']= $body['template'];
    if (isset($body['parent']))    $args['post_parent']  = (int)$body['parent'];
    $id = wp_insert_post($args, true);
    if (is_wp_error($id)) wpt_err($id->get_error_message(), 500);
    if (!empty($body['meta']) && is_array($body['meta'])) {
        foreach ($body['meta'] as $k => $v) update_post_meta($id, $k, $v);
    }
    if (!empty($body['categories']) && is_array($body['categories'])) {
        wp_set_post_categories($id, array_map('intval', $body['categories']));
    }
    wpt_out(wpt_post_payload(get_post($id)));
}

case 'update_post': {
    $id = (int)($_GET['id'] ?? ($body['id'] ?? 0));
    if (!$id || !get_post($id)) wpt_err('not found', 404);
    $args = array('ID' => $id);
    foreach (array('title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status', 'slug' => 'post_name', 'excerpt' => 'post_excerpt') as $in => $field) {
        if (isset($body[$in])) $args[$field] = $body[$in];
    }
    $res = wp_update_post($args, true);
    if (is_wp_error($res)) wpt_err($res->get_error_message(), 500);
    if (!empty($body['meta']) && is_array($body['meta'])) {
        foreach ($body['meta'] as $k => $v) update_post_meta($id, $k, $v);
    }
    wpt_purge_page_cache();
    wpt_out(wpt_post_payload(get_post($id)));
}

case 'purge_cache': {
    $done = array();
    if (class_exists('WPO_Page_Cache') && !empty($_GET['url'])) {
        WPO_Page_Cache::delete_cache_by_url($_GET['url'], true);
        $done[] = 'wpo_url:' . $_GET['url'];
    } elseif (function_exists('WP_Optimize') && is_callable(array(WP_Optimize(), 'get_page_cache'))) {
        WP_Optimize()->get_page_cache()->purge();
        $done[] = 'wpo_full';
    }
    wp_cache_flush();
    $done[] = 'object_cache';
    wpt_out(array('purged' => $done));
}

case 'get_product': {
    if (!function_exists('wc_get_product')) wpt_err('WooCommerce not loaded', 500);
    $prod = wc_get_product((int)($_GET['id'] ?? 0));
    if (!$prod) wpt_err('not found', 404);
    wpt_out(array(
        'id' => $prod->get_id(), 'name' => $prod->get_name(), 'slug' => $prod->get_slug(),
        'status' => $prod->get_status(), 'type' => $prod->get_type(), 'sku' => $prod->get_sku(),
        'regular_price' => $prod->get_regular_price(), 'sale_price' => $prod->get_sale_price(),
        'price' => $prod->get_price(), 'virtual' => $prod->is_virtual(),
        'sold_individually' => $prod->is_sold_individually(),
        'description' => $prod->get_description(), 'short_description' => $prod->get_short_description(),
        'permalink' => get_permalink($prod->get_id()),
        'buy_now' => 'https://nicolaecatrina.com/confirmare-comanda/?buy-now=' . $prod->get_id(),
        'currency' => get_woocommerce_currency(),
    ));
}

case 'find_products': {
    if (!function_exists('wc_get_products')) wpt_err('WooCommerce not loaded', 500);
    $found = get_posts(array('post_type' => 'product', 'post_status' => 'any', 's' => $_GET['s'] ?? '', 'numberposts' => 20));
    $out = array();
    foreach ($found as $p) {
        $prod = wc_get_product($p->ID);
        if (!$prod) continue;
        $out[] = array('id' => $prod->get_id(), 'name' => $prod->get_name(), 'status' => $prod->get_status(), 'price' => $prod->get_price(), 'sku' => $prod->get_sku());
    }
    wpt_out(array('products' => $out, 'currency' => get_woocommerce_currency()));
}

case 'create_product': {
    if (!class_exists('WC_Product_Simple')) wpt_err('WooCommerce not loaded', 500);
    if (empty($body['name']) || !isset($body['regular_price'])) wpt_err('name and regular_price required');
    $prod = new WC_Product_Simple();
    $prod->set_name($body['name']);
    $prod->set_regular_price((string)$body['regular_price']);
    $prod->set_status(isset($body['status']) ? $body['status'] : 'publish');
    $prod->set_virtual(isset($body['virtual']) ? (bool)$body['virtual'] : true);
    $prod->set_sold_individually(isset($body['sold_individually']) ? (bool)$body['sold_individually'] : true);
    if (!empty($body['sku']))               $prod->set_sku($body['sku']);
    if (!empty($body['description']))       $prod->set_description($body['description']);
    if (!empty($body['short_description'])) $prod->set_short_description($body['short_description']);
    $id = $prod->save();
    if (!$id) wpt_err('save failed', 500);
    wpt_out(array('id' => $id, 'name' => $prod->get_name(), 'price' => $prod->get_price(),
        'status' => $prod->get_status(), 'permalink' => get_permalink($id),
        'buy_now' => 'https://nicolaecatrina.com/confirmare-comanda/?buy-now=' . $id,
        'currency' => get_woocommerce_currency()));
}

case 'update_product': {
    if (!function_exists('wc_get_product')) wpt_err('WooCommerce not loaded', 500);
    $prod = wc_get_product((int)($_GET['id'] ?? ($body['id'] ?? 0)));
    if (!$prod) wpt_err('not found', 404);
    if (isset($body['name']))              $prod->set_name($body['name']);
    if (isset($body['regular_price']))     $prod->set_regular_price((string)$body['regular_price']);
    if (isset($body['sale_price']))        $prod->set_sale_price((string)$body['sale_price']);
    if (isset($body['status']))            $prod->set_status($body['status']);
    if (isset($body['description']))       $prod->set_description($body['description']);
    if (isset($body['short_description'])) $prod->set_short_description($body['short_description']);
    $prod->save();
    wpt_out(array('id' => $prod->get_id(), 'name' => $prod->get_name(), 'price' => $prod->get_price(), 'status' => $prod->get_status()));
}

default:
    wpt_err('unknown action');
}
