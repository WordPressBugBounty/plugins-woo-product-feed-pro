<?php
/**
 * AI Discovery admin page shell — rendered by the Vue app.
 *
 * @package AdTribes\PFP
 * @since   13.6.0
 */

use AdTribes\PFP\Helpers\Helper;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap adt-tw-wrapper adt-ucp-wrap">
    <div class="adt-container lg:adt-tw-px-8 sm:adt-tw-py-4 adt-tw-py-0">
        <?php Helper::locate_admin_template( 'header.php', true ); ?>
        <h1 class="adt-tw-text-2xl adt-tw-font-semibold adt-tw-text-gray-800 adt-tw-mb-6">
            <?php esc_html_e( 'AI Discovery', 'woo-product-feed-pro' ); ?>
        </h1>
        <div id="adt-ucp-app">
            <p><?php esc_html_e( 'Loading…', 'woo-product-feed-pro' ); ?></p>
        </div>
    </div>
</div>
