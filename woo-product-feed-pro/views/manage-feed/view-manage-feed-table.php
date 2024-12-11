<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Factories\Product_Feed_Query;

$product_feeds_query = new Product_Feed_Query(
    apply_filters(
        'adt_manage_product_feed_query_args',
        array(
            'post_status'    => array( 'draft', 'publish' ),
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        )
    ),
    'edit'
);

?>
<table id="woosea_main_table" class="woo-product-feed-pro-table" data-pagename="manage_feed">
    <tr>
        <td><strong><?php esc_html_e( 'Active', 'woo-product-feed-pro' ); ?></strong></td>
        <td><strong><?php esc_html_e( 'Project name and channel', 'woo-product-feed-pro' ); ?></strong></td>
        <td><strong><?php esc_html_e( 'Format', 'woo-product-feed-pro' ); ?></strong></td>
        <td><strong><?php esc_html_e( 'Refresh interval', 'woo-product-feed-pro' ); ?></strong></td>
        <td><strong><?php esc_html_e( 'Status', 'woo-product-feed-pro' ); ?></strong></td>
        <td></td>
    </tr>
<?php
if ( $product_feeds_query->have_posts() ) :
foreach ( $product_feeds_query->get_posts() as $product_feed ) :
?>
    <form action="" method="post">
        <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
        <tr 
            class="woo-product-feed-pro-table-row <?php echo 'processing' === $product_feed->status ? 'processing' : ''; ?>" 
            data-project_hash="<?php echo esc_attr( $product_feed->legacy_project_hash ); ?>"
            data-id="<?php echo esc_attr( $product_feed->id ); ?>"
        >
            <td>
                <label class="woo-product-feed-pro-switch">
                    <input type="hidden" name="manage_record" value="<?php echo esc_attr( $product_feed->legacy_project_hash ); ?>">
                    <input type="checkbox" name="project_active[]" class="checkbox-field" value="<?php echo esc_html( $product_feed->legacy_project_hash ); ?>" <?php echo 'publish' === $product_feed->post_status ? 'checked="true"' : ''; ?>">
                    <div class="woo-product-feed-pro-slider round"></div>
                </label>
            </td>
            <td>
                <span><?php echo esc_html( $product_feed->title ); ?></span><br/>
                <span class="woo-product-feed-pro-channel">Channel: <?php echo esc_html( $product_feed->get_channel( 'name' ) ); ?></span>
            </td>
            <td><span><?php echo esc_html( $product_feed->file_format ); ?></span></td>
            <td><span><?php echo esc_html( Product_Feed_Helper::get_refresh_interval_label( $product_feed->refresh_interval ) ); ?></span></td>
            <td class="woo-product-feed-pro-feed-status">
                <?php if ( 'processing' === $product_feed->status ) : ?>
                    <span class="woo-product-feed-pro-blink_me" id="woosea_proc_<?php echo esc_attr( $product_feed->legacy_project_hash ); ?>">
                        <?php echo esc_html( $product_feed->status . ' (' . $product_feed->get_processing_percentage() . '%)' ); ?>
                    </span>
                <?php else : ?>
                    <span class="woo-product-feed-pro-blink_off_<?php echo esc_attr( $product_feed->legacy_project_hash ); ?>" id="woosea_proc_<?php echo esc_attr( $product_feed->legacy_project_hash ); ?>"><?php echo esc_html( $product_feed->status ); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <div class="actions">
                    <?php include WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/view-manage-feed-table-actions.php'; ?>
                </div>
            </td>
        </tr>
        <tr>
            <td id="manage_inline" colspan="8">
                <div>
                    <table class="woo-product-feed-pro-inline_manage">
                        <?php if ( in_array( $product_feed->status, array( 'ready', 'stopped', 'not run yet' ), true ) ) : ?>
                            <tr>
                                <td>
                                    <?php include WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/view-manage-feed-table-settings.php'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td>
                                <strong><?php esc_html_e( 'Feed URL', 'woo-product-feed-pro' ); ?></strong><br/>
                                <?php if ( ( 'publish' === $product_feed->post_status ) && ( 'not run yet' !== $product_feed->status ) ) : ?>
                                    <span class="dashicons dashicons-arrow-right" style="display: inline-block;"></span>
                                    <a href="<?php echo esc_url( $product_feed->get_file_url() ); ?>" target="_blank">
                                        <?php echo esc_html( $product_feed->get_file_url() ); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="dashicons dashicons-warning"></span>
                                    <?php echo esc_html__( 'Whoops, there is no active product feed for this project as the project has been disabled or did not run yet.', 'woo-product-feed-pro' ); ?>
                                <?php
                                endif;
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </form>
<?php endforeach; else : ?>
    <tr class="woo-product-feed-pro-table--empty">
        <td colspan="6">
            <span class="dashicons dashicons-warning"></span>
            <p>
                <?php
                printf(
                    // translators: %s: close <a> tag.
                    esc_html__( 'You haven\'t configured a product feed yet, %1$splease create one first%3$s or read our tutorial on %2$show to set up your very first Google Shopping product feed%3$s.', 'woo-product-feed-pro' ),
                    '<a href="admin.php?page=woo-product-feed-pro">',
                    '<a href="' . esc_url( Helper::get_utm_url( 'setting-up-your-first-google-shopping-product-feed', 'pfp', 'manage-feed', 'first shopping feed' ) ) . '" target="_blank">',
                    '</a>',
                );
                ?>
            </p>
        </td>
    </tr>
<?php endif; ?> 
</table>
