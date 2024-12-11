<?php
// phpcs:disable
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Factories\Admin_Notice;
use AdTribes\PFP\Classes\Product_Feed_Admin;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

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
 * Update or get project configuration
 */
$nonce = wp_create_nonce( 'woosea_ajax_nonce' );

/**
 * Get some channel configs for default utm_source
 * Update project configuration
 */
if ( array_key_exists( 'project_hash', $_GET ) ) {
    $feed = Product_Feed_Helper::get_product_feed( sanitize_text_field( $_GET['project_hash'] ) );
    if ( $feed->id ) {
        $channel_data   = $feed->get_channel();
        $manage_project = 'yes';

        $channel_hash = $feed->channel_hash;
        $project_hash = $feed->legacy_project_hash;

        $utm_source                    = $feed->utm_source;
        $utm_campaign                  = $feed->utm_campaign;
        $utm_enabled                   = $feed->utm_enabled;
        $utm_medium                    = $feed->utm_medium;
        $utm_term                      = $feed->utm_term;
        $utm_content                   = $feed->utm_content;
        $total_product_orders_lookback = $feed->utm_total_product_orders_lookback;
    }
} else {
    $feed         = Product_Feed_Admin::update_temp_product_feed( $_POST ?? array() );
    $channel_hash = $feed['channel_hash'];
    $project_hash = $feed['project_hash'];
    $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash );

    $utm_source                    = $channel_data['name'] ?? '';
    $utm_medium                    = 'cpc';
    $utm_campaign                  = $feed['projectname'];
    $utm_enabled                   = true;
    $utm_term                      = '';
    $utm_content                   = '';
    $total_product_orders_lookback = '';
}

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 5, $project_hash, $feed );
?>
    <div class="wrap">
        <div class="woo-product-feed-pro-form-style-2">
            <tbody class="woo-product-feed-pro-body">
                <div class="woo-product-feed-pro-form-style-2-heading">
                    <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a> 
                    <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
                    <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
                    <?php endif; ?>
                    <h1 class="title"><?php esc_html_e( 'Conversion & Google Analytics settings', 'woo-product-feed-pro' ); ?></h1>
                </div>

                <?php
                // Display info message notice.
                $admin_notice = new Admin_Notice(
                    '<p>' . __('<strong>Google Analytics UTM codes:</strong><br/>Adding Google Analytics UTM codes is not mandatory, it will however enable you to get detailed insights into how your products are performing in Google Analytics reporting and allow you to tweak and tune your campaign making it more profitable. We strongly advise you to add the Google Analytics tracking. When enabled the plugin will append the Google Analytics UTM parameters to your landingpage URL\'s.', 'woo-product-feed-pro') . '</p>',
                    'info',
                    'html',
                    false
                );
                $admin_notice->run();
                ?>
    
                <form id="googleanalytics" method="post">
                <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>

                    <table class="woo-product-feed-pro-table">
                        <tr>
                            <td><span><?php esc_html_e( 'Enable Google Analytics tracking', 'woo-product-feed-pro' ); ?>: </span></td>
                            <td>
                                <label class="woo-product-feed-pro-switch">
                                    <input type="checkbox" name="utm_on" class="checkbox-field" <?php echo $utm_enabled ? 'checked' : ''; ?>>
                                    <div class="woo-product-feed-pro-slider round"></div>
                                </label>    
                            </td>
                        </tr>           
                        <tr>
                            <td><span><?php esc_html_e( 'Google Analytics campaign source (utm_source)', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td><input type="text" class="input-field" name="utm_source" value="<?php echo esc_attr( $utm_source ); ?>" /></td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Google Analytics campaign medium (utm_medium)', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td><input type="text" class="input-field" name="utm_medium" value="<?php echo esc_attr( $utm_medium ); ?>" /></td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Google Analytics campaign name (utm_campaign)', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td><input type="text" class="input-field" name="utm_campaign" value="<?php echo esc_attr( $utm_campaign ); ?>" /></td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Google Analytics campaign term (utm_term)', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td><input type="hidden" name="utm_term" value="id"><input type="text" class="input-field" value="[productId]" disabled/> <i>(<?php esc_html_e( 'dynamically added Product ID', 'woo-product-feed-pro' ); ?>)</i></td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Google Analytics campaign content (utm_content)', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td><input type="text" class="input-field" name="utm_content" value="<?php echo esc_attr( $utm_content ); ?>" /></td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Remove products that did not have sales in the last days', 'woo-product-feed-pro' ); ?>: <a href="<?php echo esc_url( Helper::get_utm_url( 'create-feed-performing-products', 'pfp', 'googleanalytics-settings', 'total product orders lookback' ) ); ?>" target="_blank">What does this do?</a></span></td>
                            <td><input type="text" class="input-field" name="total_product_orders_lookback" value="<?php echo esc_attr( $total_product_orders_lookback ); ?>" /> days</td>
                        </tr>

                        <tr>
                            <td colspan="2">
                                <?php
                                if ( isset( $manage_project ) ) {
                                    ?>
                                    <input type="hidden" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                                    <input type="hidden" name="project_update" id="project_update" value="yes">
                                    <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                                    <input type="hidden" name="step" value="100">
                                    <input type="hidden" name="woosea_page" value="analytics">
                                    <input type="submit" id="savebutton" value="Save">
                                    <?php
                                } else {
                                ?>
                                    <input type="hidden" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                                    <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                                    <input type="hidden" name="step" value="101">
                                    <input type="hidden" name="woosea_page" value="analytics">
                                    <input type="submit" id="savebutton" value="Generate Product Feed">
                                <?php
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </form>
            </tbody>
        </div>
    </div>
