<?php
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

$total_projects = Product_Feed_Helper::get_total_product_feed();

/**
 * Change default footer text, asking to review our plugin.
 *
 * @param string $default Default footer text.
 *
 * @return string Footer text asking to review our plugin.
 **/
function my_footer_text( $default ) {
    $rating_link = sprintf(
        /* translators: %s: WooCommerce Product Feed PRO plugin rating link */
        esc_html__( 'If you like our %1$s plugin please leave us a %2$s rating. Thanks in advance!', 'woo-product-feed-pro' ),
        '<strong>WooCommerce Product Feed PRO</strong>',
        '<a href="https://wordpress.org/support/plugin/woo-product-feed-pro/reviews?rate=5#new-post" target="_blank" class="woo-product-feed-pro-ratingRequest">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
    );
    return $rating_link;
}
add_filter( 'admin_footer_text', 'my_footer_text' );

/**
 * Create notification object and get message and message type as WooCommerce is inactive
 * also set variable allowed on 0 to disable submit button on step 1 of configuration
 */
$notifications_obj = new WooSEA_Get_Admin_Notifications();
if ( ! wp_next_scheduled( 'woosea_cron_hook' ) ) {
    $notifications_box = $notifications_obj->get_admin_notifications( '12', 'false' );
}

// create nonce
$nonce = wp_create_nonce( 'woosea_ajax_nonce' );

?>
<div class="wrap">
    <div class="woo-product-feed-pro-form-style-2">
        <tbody class="woo-product-feed-pro-body">
            <?php require_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'notices/view-upgrade-to-elite-notice.php'; ?>
            <?php
            if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <strong><?php esc_html_e( 'WARNING: Your WP-Cron is disabled', 'woo-product-feed-pro' ); ?></strong><br /></br />
                        We detected that your WP-cron has been disabled in your wp-config.php file. Our plugin heavily depends on the WP-cron being active otherwise it cannot update and generate your product feeds. <a href="https://adtribes.io/help-my-feed-processing-is-stuck/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=cron-warning&utm_content=notification" target="_blank"><strong>Please enable your WP-cron first</strong></a>.
                    </p>
                </div>
            <?php
            }

            /**
             * Request our plugin users to write a review
             */
            if ( 0 > $total_projects ) {
                $first_activation         = get_option( 'woosea_first_activation' );
                $notification_interaction = get_option( 'woosea_review_interaction' );
                $current_time             = time();
                $show_after               = 604800; // Show only after one week
                $is_active                = $current_time - $first_activation;
                $page                     = sanitize_text_field( basename( $_SERVER['REQUEST_URI'] ) );

                if ( ( $total_projects > 0 ) && ( $is_active > $show_after ) && ( $notification_interaction != 'yes' ) ) {
                    echo '<div class="notice notice-info review-notification">';
                    echo '<table><tr><td></td><td><font color="green" style="font-weight:normal";><p>Hey, I noticed you have been using our plugin, <u>Product Feed PRO for WooCommerce by AdTribes.io</u>, for over a week now and have created product feed projects with it - that\'s awesome! Could you please do our support volunteers and me a BIG favor and give it a <strong>5-star rating</strong> on WordPress? Just to help us spread the word and boost our motivation. We would greatly appreciate if you would do so :)<br/>~ Adtribes.io support team<br><ul><li><span class="ui-icon ui-icon-caret-1-e" style="display: inline-block;"></span><a href="https://wordpress.org/support/plugin/woo-product-feed-pro/reviews?rate=5#new-post" target="_blank" class="dismiss-review-notification">Ok, you deserve it</a></li><li><span class="ui-icon ui-icon-caret-1-e" style="display: inline-block;"></span><a href="#" class="dismiss-review-notification">Nope, maybe later</a></li><li><span class="ui-icon ui-icon-caret-1-e" style="display: inline-block;"></span><a href="#" class="dismiss-review-notification">I already did</a></li></ul></p></font></td></tr></table>';
                    echo '</div>';
                }
            }
            ?>

            <div class="woo-product-feed-pro-form-style-2-heading">
                <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a>
                <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
                <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
                <?php endif; ?>
                <h1 class="title"><?php esc_html_e( 'Manage feeds', 'woo-product-feed-pro' ); ?></h1>
            </div>
            <div class="woo-product-feed-pro-table-wrapper">
                <div class="woo-product-feed-pro-table-left">
                    <?php require_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'manage-feed/view-manage-feed-table.php'; ?>
                </div>
                <?php require_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'view-sidebar.php'; ?>
            </div>
        </tbody>
    </div>
</div>
