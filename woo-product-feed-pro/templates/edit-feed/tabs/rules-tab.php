<?php
if ( ! defined( 'ABSPATH' ) ) {
    die( 'You are not allowed to call this page directly.' );
}

// Get feed data - either from existing feed or temp data.
$feed_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="tab-content-header">
    <h1><?php esc_html_e( 'Rules Builder', 'woo-product-feed-pro' ); ?></h1>
    <p><?php esc_html_e( 'Create complex rules by combining different conditions', 'woo-product-feed-pro' ); ?></p>
</div>

<div class="tab-content-body adt-edit-feed-tab-content-body-rules adt-tw-max-w-screen-2xl">
    <form class="adt-edit-feed-form" id="adt-edit-feed-form-rules" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
        <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
        <input type="hidden" name="action" value="edit_feed_form_process" />
        <input type="hidden" name="active_tab" value="rules" />
        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed_id ); ?>" />
        
        <!-- Hidden field for Vue.js rules data -->
        <input type="hidden" name="rules" id="rules-data-field" value="" />
        
        <!-- Vue.js app container -->
        <div id="filters-rules-builder-app" data-type="rules"></div>
        
        <!-- Form submission button -->
        <div class="adt-tw-mt-6 adt-tw-flex adt-tw-gap-4">
            <button type="submit" class="adt-button adt-button-primary" id="rules-submit-button">
                <?php if ( $feed_id ) : ?>
                    <?php esc_html_e( 'Save Rules', 'woo-product-feed-pro' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Save & Continue', 'woo-product-feed-pro' ); ?>
                <?php endif; ?>
            </button>
            
            <!-- Validation status indicator -->
            <div id="validation-status" class="adt-tw-flex adt-tw-items-center adt-tw-text-sm adt-tw-hidden">
                <span class="adt-tw-icon-[lucide--alert-circle] adt-tw-size-4 adt-tw-mr-2 adt-tw-text-red-500"></span>
                <span class="adt-tw-text-red-600">Please fill in all required fields</span>
            </div>
        </div>
    </form>
</div>
