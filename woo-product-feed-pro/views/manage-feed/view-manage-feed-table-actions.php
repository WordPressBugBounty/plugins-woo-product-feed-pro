<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Classes\Product_Feed_Admin;

$actions = Product_Feed_Admin::get_product_feed_actions( $product_feed );
if ( ! empty( $actions ) ) :
    foreach ( $actions as $action_data ) :
        if ( ! empty( $action_data['url'] ) ) : ?>
            <a
                href="<?php echo esc_url( $action_data['url'] ); ?>"
                class="dashicons <?php echo esc_attr( $action_data['icon'] ); ?>"
                id="<?php echo esc_attr( $action_data['id'] . '_' . $product_feed->legacy_project_hash ); ?>"
                title="<?php echo esc_attr( $action_data['title'] ); ?>"
                style="display: inline-block;"
                target="_blank"
            >
            </a>
        <?php else : ?>
            <span
                class="dashicons <?php echo esc_attr( $action_data['icon'] ); ?>"
                id="<?php echo esc_attr( $action_data['id'] . '_' . $product_feed->legacy_project_hash ); ?>"
                title="<?php echo esc_attr( $action_data['title'] ); ?>"
                style="display: inline-block;"
            >
            </span>
        <?php endif;
    endforeach;
endif;
?>
