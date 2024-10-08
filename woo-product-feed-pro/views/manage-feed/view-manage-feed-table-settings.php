<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Classes\Product_Feed_Admin;

$settings = Product_Feed_Admin::get_product_feed_settings( $product_feed );
if ( ! empty( $settings ) ) : ?>
    <strong><?php esc_html_e( 'Change settings', 'woo-product-feed-pro' ); ?></strong><br/>
    <?php foreach ( $settings as $setting ) : ?>
    <span class="dashicons dashicons-arrow-right" style="display: inline-block;"></span>
    <a href="<?php echo esc_url( $setting['url'] ); ?>">
        <?php echo esc_html( $setting['title'] ); ?>
    </a>
    <br/>
    <?php
    endforeach;
endif;
?>
