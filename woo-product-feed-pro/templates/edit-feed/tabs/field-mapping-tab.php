<?php
/**
 * Field Mapping tab.
 *
 * The editing surface is the Vue field mapping builder (mounted on
 * `#field-mapping-builder-app`), which loads its payload from the
 * `adtribes/v1/field-mappings/{feed_id}` REST route and mirrors its state
 * into the hidden `feed_attributes` JSON field below. Saving stays a classic
 * `admin-post.php` form POST handled by
 * `Edit_Feed_Page::process_field_mapping_tab_form()` — there is no REST
 * write, exactly like the filters/rules tabs.
 *
 * @package AdTribes\PFP\Templates
 * @since   13.5.8
 */

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Classes\Admin_Pages\Edit_Feed_Page;
use AdTribes\PFP\Factories\Admin_Notice;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

$feed         = null;
$edit_feed    = false;
$feed_id      = '';
$channel_hash = '';
$project_hash = '';

$feed_id_param = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $feed_id_param ) {
    $feed = Product_Feed_Helper::get_product_feed( $feed_id_param );
    if ( $feed ) {
        $feed_id      = $feed->id;
        $channel_hash = $feed->channel_hash;
        $project_hash = $feed->legacy_project_hash;
        $edit_feed    = true;
    }
} else {
    /**
     * New feed flow: the general settings step stored the draft in the
     * temporary option; the Vue app loads it through the `new` sentinel.
     */
    $temp_feed_data = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
    $feed           = is_array( $temp_feed_data ) ? $temp_feed_data : array();
    $channel_hash   = $feed['channel_hash'] ?? '';
    $project_hash   = $feed['project_hash'] ?? '';
}

/**
 * Legacy parity: while a feed is being created, the footer says which step
 * saving advances to. `Edit_Feed_Page::get_next_tab()` is the same call
 * `process_form_submission()` uses to pick its redirect target, so the hint and
 * the Save button cannot name different steps.
 *
 * Gated on the absence of an `id` param rather than on `$edit_feed`, so this
 * agrees with the Vue app's own new-feed test. An `id` that no longer resolves
 * to a feed leaves `$edit_feed` false, and the two gates would otherwise
 * disagree - the hint would promise a next step under a Save Changes button.
 */
$next_step_label = '';
if ( '' === $feed_id_param ) {
    $edit_feed_page = Edit_Feed_Page::instance();
    $feed_tabs      = $edit_feed_page->get_tabs( $feed );
    $next_tab       = $edit_feed_page->get_next_tab( $feed, 'field_mapping' );

    $next_step_label = isset( $feed_tabs[ $next_tab ] ) ? $feed_tabs[ $next_tab ] : '';
}

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 7, $project_hash, $feed );
?>

<div class="woo-product-feed-pro-form-style-2">
    <?php
    // Display info message notice.
    $admin_notice = new Admin_Notice(
        sprintf(
            // translators: %s = link to learn static values.
            __(
                '<p>For the selected channel the attributes shown below are mandatory, please map them to your product attributes.
                We\'ve already pre-filled a lot of mappings so all you have to do is check those and map the ones that are left blank or add new ones by hitting the \'Add field mapping\' button.</p>
                <p><strong><i><a href="%s" target="_blank">Learn how to use static values</a></i></strong></p>',
                'woo-product-feed-pro'
            ),
            esc_url( Helper::get_utm_url( '/how-to-use-static-values-and-create-fake-content-for-your-product-feed', 'pfp', 'fieldmappingnotice', 'learnstaticvalues' ) )
        ),
        'info',
        false,
        true
    );
    $admin_notice->run();
    ?>

    <form class="adt-edit-feed-form" id="field_mapping" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
        <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
        <input type="hidden" name="action" value="edit_feed_form_process" />
        <input type="hidden" name="active_tab" value="field_mapping" />
        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed_id ); ?>" />
        <input type="hidden" name="channel_hash" id="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>" />
        <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>" />

        <!-- Hidden field the Vue app mirrors the feed_attributes JSON into. -->
        <input type="hidden" name="feed_attributes" id="field-mapping-data-field" value="" />

        <!-- Vue.js app container -->
        <div id="field-mapping-builder-app" data-next-step="<?php echo esc_attr( $next_step_label ); ?>"></div>

        <?php
        /*
         * The submit button is rendered by the Vue app, inside this form, so it
         * can sit in the app's own layout - under the group navigator on wide
         * screens, pinned to the bottom on narrow ones - and bind straight to
         * the validation state. Saving is unchanged: it is still a real submit
         * inside this form, posting to admin-post.php.
         */
        ?>
    </form>
</div>
