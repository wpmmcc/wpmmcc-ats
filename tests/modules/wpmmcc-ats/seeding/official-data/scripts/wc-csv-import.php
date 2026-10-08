<?php
/**
 * WooCommerce CSV Import Script
 *
 * Usage: wp eval-file wc-csv-import.php --path=/usr/local/var/www
 */

$csv_file = dirname( __DIR__ ) . '/woocommerce/sample_products.csv';

if ( ! file_exists( $csv_file ) ) {
    WP_CLI::error( "CSV file not found: $csv_file" );
}

if ( ! class_exists( 'WooCommerce' ) ) {
    WP_CLI::error( 'WooCommerce is not active' );
}

// Include importer class
if ( ! class_exists( 'WC_Product_CSV_Importer' ) ) {
    include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
}

WP_CLI::log( "Importing from: $csv_file" );

// Get count before
$before = wp_count_posts( 'product' );
$before_count = isset( $before->publish ) ? (int) $before->publish : 0;
WP_CLI::log( "Products before: $before_count" );

// Column mapping for WooCommerce sample CSV
$mapping = array(
    'ID'                    => 'id',
    'Type'                  => 'type',
    'SKU'                   => 'sku',
    'Name'                  => 'name',
    'Published'             => 'published',
    'Is featured?'          => 'featured',
    'Visibility in catalog' => 'catalog_visibility',
    'Short description'     => 'short_description',
    'Description'           => 'description',
    'Tax status'            => 'tax_status',
    'Tax class'             => 'tax_class',
    'In stock?'             => 'stock_status',
    'Stock'                 => 'stock_quantity',
    'Regular price'         => 'regular_price',
    'Sale price'            => 'sale_price',
    'Categories'            => 'category_ids',
    'Tags'                  => 'tag_ids',
    'Images'                => 'images',
    'Weight (lbs)'          => 'weight',
    'Length (in)'           => 'length',
    'Width (in)'            => 'width',
    'Height (in)'           => 'height',
);

$params = array(
    'mapping'         => $mapping,
    'update_existing' => true,
    'parse'           => true,
);

try {
    $importer = new WC_Product_CSV_Importer( $csv_file, $params );
    $result   = $importer->import();

    $imported = count( $result['imported'] ?? array() );
    $updated  = count( $result['updated'] ?? array() );
    $skipped  = count( $result['skipped'] ?? array() );
    $failed   = count( $result['failed'] ?? array() );

    WP_CLI::log( "Imported: $imported, Updated: $updated, Skipped: $skipped, Failed: $failed" );

    // Get count after
    $after       = wp_count_posts( 'product' );
    $after_count = isset( $after->publish ) ? (int) $after->publish : 0;
    WP_CLI::log( "Products after: $after_count" );

    if ( $imported > 0 ) {
        WP_CLI::success( "Successfully imported $imported products" );
    } else {
        WP_CLI::warning( 'No new products imported' );
    }
} catch ( Exception $e ) {
    WP_CLI::error( $e->getMessage() );
}
