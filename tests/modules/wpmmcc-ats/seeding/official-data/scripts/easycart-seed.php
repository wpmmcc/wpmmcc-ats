<?php
/**
 * WP EasyCart Custom Table Seeder
 *
 * WP EasyCart 使用自定义表 ec_product 而非 WordPress posts
 * 此脚本直接插入产品数据到自定义表
 *
 * @package WPTSALL\DevTools\Seeding\OfficialData
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

global $wpdb;

// 检查 WP EasyCart 是否激活
if ( ! class_exists( 'ec_db' ) && ! function_exists( 'ec_admin' ) ) {
    echo "错误: WP EasyCart 未激活\n";
    exit( 1 );
}

// 检查表是否存在
$table = 'ec_product';
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
if ( ! $table_exists ) {
    echo "错误: ec_product 表不存在\n";
    exit( 1 );
}

// 检查是否已有数据
$existing = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
if ( $existing > 0 ) {
    echo "已有 $existing 个产品，跳过填充\n";
    exit( 0 );
}

echo "开始填充 WP EasyCart 产品数据...\n";

// 产品数据
$products = array(
    array(
        'model_number'       => 'WH-001',
        'title'              => 'Premium Wireless Headphones',
        'description'        => '<p>High-quality wireless headphones with noise cancellation. 30-hour battery life and premium sound quality.</p>',
        'price'              => 149.99,
        'list_price'         => 199.99,
        'stock_quantity'     => 100,
        'weight'             => 0.5,
        'activate_in_store'  => 1,
    ),
    array(
        'model_number'       => 'OC-002',
        'title'              => 'Ergonomic Office Chair',
        'description'        => '<p>Adjustable office chair with lumbar support. Perfect for long work sessions.</p>',
        'price'              => 299.99,
        'list_price'         => 349.99,
        'stock_quantity'     => 50,
        'weight'             => 15,
        'activate_in_store'  => 1,
    ),
    array(
        'model_number'       => 'FW-003',
        'title'              => 'Smart Fitness Watch',
        'description'        => '<p>Track your fitness goals with heart rate monitoring, GPS, and workout tracking.</p>',
        'price'              => 179.99,
        'list_price'         => 199.99,
        'stock_quantity'     => 75,
        'weight'             => 0.1,
        'activate_in_store'  => 1,
    ),
    array(
        'model_number'       => 'HUB-004',
        'title'              => 'USB-C Hub 7-in-1',
        'description'        => '<p>Expand your laptop connectivity with HDMI, USB-A, SD card, and more.</p>',
        'price'              => 49.99,
        'list_price'         => 59.99,
        'stock_quantity'     => 200,
        'weight'             => 0.2,
        'activate_in_store'  => 1,
    ),
    array(
        'model_number'       => 'KB-005',
        'title'              => 'Mechanical Gaming Keyboard',
        'description'        => '<p>RGB backlit mechanical keyboard with Cherry MX switches for gaming enthusiasts.</p>',
        'price'              => 129.99,
        'list_price'         => 149.99,
        'stock_quantity'     => 60,
        'weight'             => 1.2,
        'activate_in_store'  => 1,
    ),
);

$inserted = 0;
foreach ( $products as $product ) {
    $result = $wpdb->insert(
        $table,
        array(
            'model_number'      => $product['model_number'],
            'title'             => $product['title'],
            'description'       => $product['description'],
            'price'             => $product['price'],
            'list_price'        => $product['list_price'],
            'stock_quantity'    => $product['stock_quantity'],
            'weight'            => $product['weight'],
            'activate_in_store' => $product['activate_in_store'],
            'width'             => 1.0,
            'height'            => 1.0,
            'length'            => 1.0,
        ),
        array( '%s', '%s', '%s', '%f', '%f', '%d', '%f', '%d', '%f', '%f', '%f' )
    );

    if ( $result ) {
        $inserted++;
        echo "  ✓ 创建: {$product['title']} (ID: {$wpdb->insert_id})\n";
    } else {
        echo "  ✗ 失败: {$product['title']} - {$wpdb->last_error}\n";
    }
}

echo "\nWP EasyCart 填充完成: $inserted 个产品\n";
