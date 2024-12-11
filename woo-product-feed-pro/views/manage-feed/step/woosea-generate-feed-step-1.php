<?php
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Factories\Admin_Notice;
use AdTribes\PFP\Classes\Product_Feed_Admin;
use AdTribes\PFP\Classes\Google_Product_Taxonomy_Fetcher;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Helpers\Helper;

/**
 * Change default footer text, asking to review our plugin.
 *
 * @return string Footer text asking to review our plugin.
 **/
function my_footer_text() {
    $rating_link = sprintf(
        /* translators: %s: WooCommerce Product Feed PRO plugin rating link */
        esc_html__( 'If you like our %1$s plugin please leave us a %2$s rating. Thanks in advance!', 'woo-product-feed-pro' ),
        '<strong>WooCommerce Product Feed PRO</strong>',
        '<a href="https://wordpress.org/support/plugin/woo-product-feed-pro/reviews?rate=5#new-post" target="_blank" class="woo-product-feed-pro-ratingRequest">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
    );
    return $rating_link;
}
add_filter( 'admin_footer_text', 'my_footer_text' );

delete_option( 'woosea_cat_mapping' );

/**
 * Update or get project configuration
 */
$nonce = wp_create_nonce( 'woosea_ajax_nonce' );

$google_product_taxonomy_fetcher = Google_Product_Taxonomy_Fetcher::instance();
$is_fetching                     = $google_product_taxonomy_fetcher->is_fetching();
$is_file_exists                  = $google_product_taxonomy_fetcher->is_file_exists();

/**
 * Update project configuration
 */
if ( array_key_exists( 'project_hash', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification
    $feed = Product_Feed_Helper::get_product_feed( sanitize_text_field( $_GET['project_hash'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
    if ( $feed->id ) {
        $feed_mappings = $feed->mappings;
        $channel_data  = $feed->channel;

        $channel_hash = $feed->channel_hash;
        $project_hash = $feed->legacy_project_hash;

        $count_mappings = count( $feed_mappings );
        $manage_project = 'yes';
    }
} elseif ( wp_verify_nonce( $_POST['_wpnonce'], 'woosea_ajax_nonce' ) ) {
    $feed         = Product_Feed_Admin::update_temp_product_feed( $_POST ?? array() );
    $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( sanitize_text_field( $_POST['channel_hash'] ) );

    $channel_hash = $feed['channel_hash'];
    $project_hash = $feed['project_hash'];

    $feed_mappings  = array();
    $count_mappings = 0;
}

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 1, $project_hash, $feed );
?>

<div class="wrap">
    <div class="woo-product-feed-pro-form-style-2">
        <div class="woo-product-feed-pro-form-style-2-heading">
            <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a> 
            <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
            <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
            <?php endif; ?>
            <h1 class="title"><?php esc_html_e( 'Category mapping', 'woo-product-feed-pro' ); ?></h1>
        </div>

        <?php
        // Display info message notice.
        $admin_notice = new Admin_Notice(
            sprintf(
                esc_html__( 'Map your products or categories to the categories of your selected channel. For some channels adding their categorisation in the product feed is mandatory. Even when category mappings are not mandatory it is likely your products will get better visibility and higher conversions when mappings have been added.', 'woo-product-feed-pro' )
            ),
            'info',
            'string',
            false
        );
        $admin_notice->run();

        // Display info google product taxonomy fetching message.
        if ( $is_fetching ) {
            // Display fetching message.
            $admin_notice = new Admin_Notice(
                sprintf(
                    esc_html__(
                        'Fetching Google Product Taxonomy: Please wait until the process is finished. Refresh this page to check the status.',
                        'woo-product-feed-pro'
                    )
                )
            );
            $admin_notice->run();
        } elseif ( ! $is_file_exists ) {
            // Display fetching message.
            $admin_notice = new Admin_Notice(
                sprintf(
                    esc_html__(
                        'Google Product Taxonomy is failed to fetch. Reattempt to fetch Google Product Taxonomy by deactivating and reactivating the plugin.',
                        'woo-product-feed-pro'
                    )
                )
            );
            $admin_notice->run();
        }
        ?>

        <div class="woo-product-feed-pro-table-wrapper">
            <div class="woo-product-feed-pro-table-left">
                <form action="" method="post" id="adt-pfp-category-mapping-form">
                    <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
                    <table id="woosea-ajax-mapping-table" class="woo-product-feed-pro-table" border="1">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Your category', 'woo-product-feed-pro' ); ?> <i>(<?php esc_html_e( 'Number of products', 'woo-product-feed-pro' ); ?>)</i></th>
                                <th><?php echo esc_html( $channel_data['name'] ); ?> <?php esc_html_e( 'category', 'woo-product-feed-pro' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody class="woo-product-feed-pro-body">
                           
                            <!-- Display mapping form. -->
                            <?php echo Product_Feed_Helper::get_hierarchical_categories_mapping( $feed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <tr>
                                <td colspan="3">
                                    <input type="hidden" id="channel_hash" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                                    <?php
                                    if ( isset( $manage_project ) ) {
                                    ?>
                                        <input type="hidden" name="project_update" id="project_update" value="yes" />
                                        <input type="hidden" id="project_hash" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                                        <input type="hidden" name="step" value="100">
                                        <input type="submit" value="Save mappings" />
                                    <?php
                                    } else {
                                    ?>
                                        <input type="hidden" id="project_hash" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                                        <input type="hidden" name="step" value="4">
                                        <input type="submit" value="Save mappings" />
                                    <?php
                                    }
                                    ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </form>
            </div>
            <?php require_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'view-sidebar.php'; ?>
        </div>
    </div>
</div>
