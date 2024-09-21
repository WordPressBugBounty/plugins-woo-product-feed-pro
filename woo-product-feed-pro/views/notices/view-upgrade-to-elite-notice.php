<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Helpers\Helper;

if ( Helper::is_show_get_elite_notice() ) {
?>
    <div class="notice notice-info get_elite is-dismissible">
        <p>
            <strong><?php esc_html_e( 'Would you like to get more out of your product feeds? Upgrade to the Elite version of the plugin and you will get:', 'woo-product-feed-pro' ); ?></strong><br /></br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Priority support - we will help you to get your product feed(s) up-and-running;', 'woo-product-feed-pro' ); ?><br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'GTIN, Brand, MPN, EAN, Condition and more fields for your product feeds', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/add-gtin-mpn-upc-ean-product-condition-optimised-title-and-brand-attributes/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticeaddingfields" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Solve Googe Shopping price mismatch product disapprovals', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/woocommerce-structured-data-bug/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticestructureddatabug" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Advanced product data manipulation', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/feature-product-data-manipulation/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticeproductdatamanipulation" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'WPML support - including their currency switcher', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/wpml-support/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticewpmlsupport" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Aelia  & Curcy currency switcher support', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/aelia-currency-switcher-feature/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticeaeliasupport" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Polylang support', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/polylang-support-product-feeds/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticepolylangsupport" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br />
            <span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Facebook pixel feature', 'woo-product-feed-pro' ); ?> [<a href="https://adtribes.io/facebook-pixel-feature/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradenoticefacebookpixelfeature" target="_blank"><?php esc_html_e( 'Read more', 'woo-product-feed-pro' ); ?></a>];<br /><br />
            <a class="button button-pink" href="https://adtribes.io/pro-vs-elite/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=upgradetoelitenoticebutton" target="_blank"><?php esc_html_e( 'Upgrade To Elite', 'woo-product-feed-pro' ); ?></a>
        </p>
    </div>
<?php
}
?>
