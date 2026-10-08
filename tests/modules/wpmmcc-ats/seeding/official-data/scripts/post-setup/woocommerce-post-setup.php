<?php
/**
 * WooCommerce 数据填充后置配置脚本
 *
 * 在导入 WooCommerce 官方数据后执行，配置必要的页面和设置
 *
 * 执行方式:
 *   wp eval-file woocommerce-post-setup.php
 *
 * 配置内容:
 *   1. 创建/配置 Shop 页面
 *   2. 创建/配置 Cart 页面
 *   3. 创建/配置 Checkout 页面
 *   4. 创建/配置 My Account 页面
 *   5. 配置永久链接
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WooCommerce 后置配置                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查 WooCommerce 是否激活
if ( ! class_exists( 'WooCommerce' ) ) {
    echo "❌ WooCommerce 未激活，跳过配置\n";
    return;
}

$changes = array();

// =============================================================
// 1. Shop 页面
// =============================================================
echo "--- 1. Shop 页面 ---\n";

$shop_page_id = get_option( 'woocommerce_shop_page_id' );
$shop_page = $shop_page_id ? get_post( $shop_page_id ) : null;

if ( ! $shop_page || $shop_page->post_status !== 'publish' ) {
    // 查找是否已存在 shop 页面
    $existing = get_page_by_path( 'shop' );

    if ( $existing ) {
        $shop_page_id = $existing->ID;
        echo "  找到已存在的 Shop 页面: ID $shop_page_id\n";
    } else {
        // 创建新页面
        $shop_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'Shop',
            'post_name'    => 'shop',
            'post_status'  => 'publish',
            'post_content' => '',
        ) );
        echo "  ✓ 创建 Shop 页面: ID $shop_page_id\n";
        $changes[] = 'created_shop_page';
    }

    update_option( 'woocommerce_shop_page_id', $shop_page_id );
    echo "  ✓ 配置 woocommerce_shop_page_id = $shop_page_id\n";
    $changes[] = 'configured_shop_page';
} else {
    echo "  Shop 页面已配置: ID $shop_page_id\n";
}

// =============================================================
// 2. Cart 页面
// =============================================================
echo "\n--- 2. Cart 页面 ---\n";

$cart_page_id = get_option( 'woocommerce_cart_page_id' );
$cart_page = $cart_page_id ? get_post( $cart_page_id ) : null;

if ( ! $cart_page || $cart_page->post_status !== 'publish' ) {
    $existing = get_page_by_path( 'cart' );

    if ( $existing ) {
        $cart_page_id = $existing->ID;
        echo "  找到已存在的 Cart 页面: ID $cart_page_id\n";
    } else {
        $cart_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'Cart',
            'post_name'    => 'cart',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:woocommerce/cart -->
<div class="wp-block-woocommerce-cart is-loading"><!-- wp:woocommerce/filled-cart-block -->
<div class="wp-block-woocommerce-filled-cart-block"><!-- wp:woocommerce/cart-items-block -->
<div class="wp-block-woocommerce-cart-items-block"><!-- wp:woocommerce/cart-line-items-block -->
<div class="wp-block-woocommerce-cart-line-items-block"></div>
<!-- /wp:woocommerce/cart-line-items-block --></div>
<!-- /wp:woocommerce/cart-items-block --></div>
<!-- /wp:woocommerce/filled-cart-block --></div>
<!-- /wp:woocommerce/cart -->',
        ) );
        echo "  ✓ 创建 Cart 页面: ID $cart_page_id\n";
        $changes[] = 'created_cart_page';
    }

    update_option( 'woocommerce_cart_page_id', $cart_page_id );
    echo "  ✓ 配置 woocommerce_cart_page_id = $cart_page_id\n";
    $changes[] = 'configured_cart_page';
} else {
    echo "  Cart 页面已配置: ID $cart_page_id\n";
}

// =============================================================
// 3. Checkout 页面
// =============================================================
echo "\n--- 3. Checkout 页面 ---\n";

$checkout_page_id = get_option( 'woocommerce_checkout_page_id' );
$checkout_page = $checkout_page_id ? get_post( $checkout_page_id ) : null;

if ( ! $checkout_page || $checkout_page->post_status !== 'publish' ) {
    $existing = get_page_by_path( 'checkout' );

    if ( $existing ) {
        $checkout_page_id = $existing->ID;
        echo "  找到已存在的 Checkout 页面: ID $checkout_page_id\n";
    } else {
        $checkout_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'Checkout',
            'post_name'    => 'checkout',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout is-loading"></div>
<!-- /wp:woocommerce/checkout -->',
        ) );
        echo "  ✓ 创建 Checkout 页面: ID $checkout_page_id\n";
        $changes[] = 'created_checkout_page';
    }

    update_option( 'woocommerce_checkout_page_id', $checkout_page_id );
    echo "  ✓ 配置 woocommerce_checkout_page_id = $checkout_page_id\n";
    $changes[] = 'configured_checkout_page';
} else {
    echo "  Checkout 页面已配置: ID $checkout_page_id\n";
}

// =============================================================
// 4. My Account 页面
// =============================================================
echo "\n--- 4. My Account 页面 ---\n";

$myaccount_page_id = get_option( 'woocommerce_myaccount_page_id' );
$myaccount_page = $myaccount_page_id ? get_post( $myaccount_page_id ) : null;

if ( ! $myaccount_page || $myaccount_page->post_status !== 'publish' ) {
    $existing = get_page_by_path( 'my-account' );

    if ( $existing ) {
        $myaccount_page_id = $existing->ID;
        echo "  找到已存在的 My Account 页面: ID $myaccount_page_id\n";
    } else {
        $myaccount_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'My Account',
            'post_name'    => 'my-account',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:woocommerce/my-account -->
<div class="wp-block-woocommerce-my-account"></div>
<!-- /wp:woocommerce/my-account -->',
        ) );
        echo "  ✓ 创建 My Account 页面: ID $myaccount_page_id\n";
        $changes[] = 'created_myaccount_page';
    }

    update_option( 'woocommerce_myaccount_page_id', $myaccount_page_id );
    echo "  ✓ 配置 woocommerce_myaccount_page_id = $myaccount_page_id\n";
    $changes[] = 'configured_myaccount_page';
} else {
    echo "  My Account 页面已配置: ID $myaccount_page_id\n";
}

// =============================================================
// 5. 刷新永久链接
// =============================================================
echo "\n--- 5. 刷新永久链接 ---\n";
flush_rewrite_rules();
echo "  ✓ 永久链接已刷新\n";

// =============================================================
// 完成
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  配置完成                                                    ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ( empty( $changes ) ) {
    echo "║  无需更改，所有配置已就绪                                    ║\n";
} else {
    echo "║  执行了 " . count( $changes ) . " 项更改                                            ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 验证
echo "--- 验证 ---\n";
echo "Shop:      /shop/      -> " . get_permalink( get_option( 'woocommerce_shop_page_id' ) ) . "\n";
echo "Cart:      /cart/      -> " . get_permalink( get_option( 'woocommerce_cart_page_id' ) ) . "\n";
echo "Checkout:  /checkout/  -> " . get_permalink( get_option( 'woocommerce_checkout_page_id' ) ) . "\n";
echo "My Account:/my-account/-> " . get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) . "\n";
