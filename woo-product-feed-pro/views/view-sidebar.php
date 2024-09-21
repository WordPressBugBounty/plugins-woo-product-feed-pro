<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Helpers\Helper;

/**
 * PFP Sidebar template
 *
 * @since 13.3.5
 */
?>
<div class="woo-product-feed-pro-table-right">
    <?php if ( Helper::is_show_sidebar_upgrade_column() ) : ?>
    <table class="woo-product-feed-pro-table">
        <tr>
            <td><strong><?php esc_html_e( 'Upgrade To Elite', 'woo-product-feed-pro' ); ?></strong></td>
        </tr>
        <tr>
            <td>
                <?php esc_html_e( 'Upgrade to get more with Product Feed Elite.', 'woo-product-feed-pro' ); ?>
                <ul>
                    <li><strong>1.</strong> <?php esc_html_e( '100+ feed templates for platforms', 'woo-product-feed-pro' ); ?></li>
                    <li><strong>2.</strong> <?php esc_html_e( 'Unlimited products & feeds', 'woo-product-feed-pro' ); ?></li>
                    <li><strong>3.</strong> <?php esc_html_e( 'More products approved by Google, Facebook, & others', 'woo-product-feed-pro' ); ?></li>
                    <li><strong>4.</strong> <?php esc_html_e( 'Adds GTIN, MPN, EAN, Brand & more extra fields', 'woo-product-feed-pro' ); ?></li>
                    <li><strong>5.</strong> <?php esc_html_e( 'Advanced rules & filters', 'woo-product-feed-pro' ); ?></li>
                    <li><strong>6.</strong> <?php esc_html_e( '20+ official integrations (WPML, Aelia, Curcy & more!)', 'woo-product-feed-pro' ); ?></li>
                    <li><strong>7.</strong> <?php esc_html_e( 'Premium support – let us help you get your feeds live faster', 'woo-product-feed-pro' ); ?></li>
                </ul>
                <strong>
                    <a class="woo-product-feed-pro-sidebar-upgrade-button" href="https://adtribes.io/pricing/?utm_source=pfp&utm_medium=sidebar&utm_campaign=sidebarupgradebutton" target="_blank"><?php esc_html_e( 'Get Product Feed Elite', 'woo-product-feed-pro' ); ?> &rarr;</a>
                </strong>
            </td>
        </tr>
    </table><br />
    <?php endif; ?>

    <table class="woo-product-feed-pro-table">
        <tr>
            <td><strong><?php esc_html_e( 'Help & Resources', 'woo-product-feed-pro' ); ?></strong></td>
        </tr>
        <tr>
            <td>
                <ul class="woo-product-feed-pro-resources">
                    <li><strong><a href="https://adtribes.io/blog/?utm_source=pfp&utm_medium=sidebar&utm_campaign=tutorials" target="_blank"><?php esc_html_e( 'Feed Marketing Blog', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong><a href="https://adtribes.io/support/?utm_source=pfp&utm_medium=sidebar&utm_campaign=faq" target="_blank"><?php esc_html_e( 'Support', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong><a href="https://www.youtube.com/channel/UCXp1NsK-G_w0XzkfHW-NZCw" target="_blank"><?php esc_html_e( 'YouTube Channel', 'woo-product-feed-pro' ); ?></a></strong></li>
                </ul>
            </td>
        </tr>
    </table><br />

    <table class="woo-product-feed-pro-table">
        <tr>
            <td><strong><?php esc_html_e( 'Helpful Articles', 'woo-product-feed-pro' ); ?></strong></td>
        </tr>
        <tr>
            <td>
                <ul class="woo-product-feed-pro-helpful-articles-list">
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/setting-up-your-first-google-shopping-product-feed/?utm_source=pfp&utm_medium=sidebar&utm_campaign=first shopping feed" target="_blank"><?php esc_html_e( 'Create a Google Shopping feed', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/add-gtin-mpn-upc-ean-product-condition-optimised-title-and-brand-attributes/?utm_source=pfp&utm_medium=sidebar&utm_campaign=adding fields" target="_blank"><?php esc_html_e( 'Adding GTIN, Brand, MPN and more', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/help-my-feed-processing-is-stuck/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=feed stuck" target="_blank"><?php esc_html_e( 'Help, my feed is stuck!', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/feature-product-data-manipulation/?utm_source=pfp&utm_medium=manage-feed&utm_campaign=product_data_manipulation" target="_blank"><?php esc_html_e( 'Product data manipulation', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/how-to-create-filters-for-your-product-feed/?utm_source=pfp&utm_medium=sidebar&utm_campaign=how to create filters" target="_blank"><?php esc_html_e( 'How to create filters for your product feed', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/how-to-create-rules/?utm_source=pfp&utm_medium=sidebar&utm_campaign=how to create rules" target="_blank"><?php esc_html_e( 'How to set rules for your product feed', 'woo-product-feed-pro' ); ?></a></strong></li>
                    <li><strong>- <a href="https://adtribes.io/knowledge-base/woocommerce-structured-data-bug/?utm_source=pfp&utm_medium=sidebar&utm_campaign=structured data bug" target="_blank"><?php esc_html_e( 'WooCommerce structured data markup bug', 'woo-product-feed-pro' ); ?></a></strong></li>
                </ul>
            </td>
        </tr>
    </table><br />
</div>
