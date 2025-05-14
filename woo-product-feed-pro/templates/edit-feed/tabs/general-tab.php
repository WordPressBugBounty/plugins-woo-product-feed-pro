<?php
// phpcs:disable
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Classes\Product_Feed_Attributes;

$country = '';

/**
 * Get shipping zones
 */
$shipping_zones    = WC_Shipping_Zones::get_zones();
$nr_shipping_zones = count( $shipping_zones );

$feed         = null;
$project_hash = isset( $_GET['project_hash'] ) ? sanitize_text_field( $_GET['project_hash'] ) : '';
$feed_id      = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
$edit_feed    = false;
if ( $feed_id ) {
    $feed           = Product_Feed_Helper::get_product_feed( sanitize_text_field( $feed_id ) );
    if ( $feed ) {
        $country = $feed->get_legacy_country();
        $edit_feed = true;
    }
} else {
    $feed = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
}

/**
 * Get countries and channels
 */
$countries = Product_Feed_Attributes::get_channel_countries();
$channels  = Product_Feed_Attributes::get_channels( $country );

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 0, $project_hash, $feed );
?>

<div class="woo-product-feed-pro-form-style-2">
    <?php Helper::locate_admin_template( 'notices/upgrade-to-elite-notice.php', true ); ?>
    <form class="adt-edit-feed-form" id="general" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
        <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
        <input type="hidden" name="action" value="edit_feed_form_process" />
        <input type="hidden" name="active_tab" value="general" />
        <input type="hidden" name="feed_id" value="<?php echo $feed_id; ?>" />

        <div class="woo-product-feed-pro-table-wrapper">
            <div class="woo-product-feed-pro-table-left">

                <table class="woo-product-feed-pro-table">
                    <tbody class="woo-product-feed-pro-body">
                        <div id="projecterror"></div>
                        <tr>
                            <td width="30%"><span><?php esc_html_e( 'Project name', 'woo-product-feed-pro' ); ?>:<span class="required">*</span></span></td>
                            <td>
                                <div style="display: block;">
                                    <?php if ( $edit_feed ) : ?> 
                                        <input type="text" class="input-field" id="projectname" name="projectname" value="<?php echo esc_attr( $feed->title ); ?>" required/>
                                        <div id="projecterror"></div>
                                    <?php else : ?>
                                        <input type="text" class="input-field" id="projectname" name="projectname" value="<?php echo isset( $feed['projectname'] ) ? esc_attr( $feed['projectname'] ) : ''; ?>" required/>
                                        <div id="projecterror"></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php

                        /**
                         * Action hook to add content before the country field.
                         *
                         * @since 13.3.6
                         * @param array|Product_Feed|null $feed Product_Feed object or array of project data.
                         */
                        do_action( 'adt_general_feed_settings_before_country_field', $feed );
                        ?>
                        <tr>
                            <td><span><?php esc_html_e( 'Country', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <?php if ( $edit_feed ) : ?> 
                                <select name="countries" id="countries" class="select-field woo-sea-select2" disabled>
                                    <option value="<?php echo esc_attr( $country ); ?>" selected>
                                        <?php if ( ! empty( $country ) ) : ?>
                                            <?php echo esc_html( $country ); ?>
                                        <?php else : ?>
                                            <?php esc_html_e( 'Select a country', 'woo-product-feed-pro' ); ?>
                                        <?php endif; ?>
                                    </option>
                                </select>
                                <?php else : ?>
                                <select name="countries" id="countries" class="select-field woo-sea-select2">
                                    <option><?php esc_html_e( 'Select a country', 'woo-product-feed-pro' ); ?></option>
                                    <?php foreach ( $countries as $value ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php echo isset( $feed['countries'] ) && $feed['countries'] === $value ? 'selected' : ''; ?>>
                                            <?php echo esc_html( $value ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Channel', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <?php if ( $edit_feed ) : ?> 
                                <select name="channel_hash" id="channel_hash" class="select-field woo-sea-select2" disabled>
                                    <option value="<?php echo esc_html( $feed->channel_hash ); ?>" selected><?php echo esc_html( $feed->get_channel( 'name' ) ); ?></option>
                                </select>
                                <?php else : ?>
                                <select name="channel_hash" id="channel_hash" class="select-field woo-sea-select2">
                                    <?php
                                    $selected_channel = isset( $feed['channel_hash'] ) ? Product_Feed_Helper::get_channel_from_legacy_channel_hash( $feed['channel_hash'] ) : '';
                                    $selected_channel_name = isset( $selected_channel['name'] ) ? $selected_channel['name'] : 'Google Shopping';
                                    echo Product_Feed_Helper::print_channel_options( $channels, $selected_channel_name );
                                    ?>
                                </select>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="product_variations">
                            <td><span><?php esc_html_e( 'Include product variations', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <label class="woo-product-feed-pro-switch">
                                    <?php if ( $edit_feed ) : ?> 
                                        <input type="checkbox" id="variations" name="product_variations" class="checkbox-field" <?php echo $feed->include_product_variations ? 'checked' : ''; ?>>
                                    <?php else : ?>
                                        <input type="checkbox" id="variations" name="product_variations" class="checkbox-field" <?php echo isset( $feed['product_variations'] ) && 'on' === $feed['product_variations'] ? 'checked' : ''; ?>>
                                    <?php endif; ?>
                                    <div class="woo-product-feed-pro-slider round"></div>
                                </label>
                            </td>
                        </tr>
                        <tr id="default_variation">
                            <td><span><?php esc_html_e( 'And only include default product variation', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <label class="woo-product-feed-pro-switch">
                                    <?php if ( $edit_feed ) : ?> 
                                        <input type="checkbox" id="default_variations" name="default_variations" class="checkbox-field" <?php echo $feed->only_include_default_product_variation ? 'checked' : ''; ?>>
                                    <?php else : ?>
                                        <input type="checkbox" id="default_variations" name="default_variations" class="checkbox-field" <?php echo isset( $feed['default_variations'] ) && 'on' === $feed['default_variations'] ? 'checked' : ''; ?>>
                                    <?php endif; ?>
                                    <div class="woo-product-feed-pro-slider round"></div>
                                </label>
                            </td>
                        </tr>
                        <tr id="lowest_price_variation">
                            <td><span><?php esc_html_e( 'And only include lowest priced product variation(s)', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <label class="woo-product-feed-pro-switch">
                                    <?php if ( $edit_feed ) : ?> 
                                        <input type="checkbox" id="lowest_price_variations" name="lowest_price_variations" class="checkbox-field" <?php echo $feed->only_include_lowest_product_variation ? 'checked' : ''; ?>>
                                    <?php else : ?>
                                        <input type="checkbox" id="lowest_price_variations" name="lowest_price_variations" class="checkbox-field" <?php echo isset( $feed['lowest_price_variations'] ) && 'on' === $feed['lowest_price_variations'] ? 'checked' : ''; ?>>
                                    <?php endif; ?>
                                    <div class="woo-product-feed-pro-slider round"></div>
                                </label>
                            </td>
                        </tr>
                        <tr id="file">
                            <td><span><?php esc_html_e( 'File format', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <select name="fileformat" id="fileformat" class="select-field">
                                    <?php
                                    $format_arr = array( 'xml', 'csv', 'txt', 'tsv' );
                                    foreach ( $format_arr as $format ) :
                                        $selected = '';
                                        if ( $edit_feed ) {
                                            $selected = ( $format == $feed->file_format ) ? 'selected' : '';
                                        } else {
                                            $selected = isset( $feed['fileformat'] ) && $format == $feed['fileformat'] ? 'selected' : '';
                                        }
                                    ?>
                                        <option value="<?php echo esc_attr( $format ); ?>" <?php echo $selected; ?>><?php echo esc_html( strtoupper( $format ) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="delimiter">
                            <td><span><?php esc_html_e( 'Delimiter', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <select name="delimiter" class="select-field">
                                    <?php
                                    $delimiter_arr = array( ',', '|', ';', 'tab', '#' );
                                    foreach ( $delimiter_arr as $delimiter ) :
                                        $selected = '';
                                        if ( $edit_feed ) {
                                            $selected = ( $delimiter == $feed->delimiter ) ? 'selected' : '';
                                        } else {
                                            $selected = isset( $feed['delimiter'] ) && $delimiter == $feed['delimiter'] ? 'selected' : '';
                                        }
                                    ?>
                                        <option value="<?php echo esc_attr( $delimiter ); ?>" <?php echo $selected; ?>><?php echo esc_html( $delimiter ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td><span><?php esc_html_e( 'Refresh interval', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <select name="cron" class="select-field">
                                    <?php
                                    $refresh_arr = array(  
                                        '',
                                        'daily',
                                        'twicedaily',
                                        'hourly',
                                    );
                                    foreach ( $refresh_arr as $refresh_key ) :
                                        $selected = '';
                                        if ( $edit_feed ) {
                                            $selected = ( $refresh_key == $feed->refresh_interval ) ? 'selected' : '';
                                        } else {
                                            $selected = isset( $feed['cron'] ) && $refresh_key == $feed['cron'] ? 'selected' : '';
                                        }
                                    ?>
                                        <option value="<?php echo esc_attr( $refresh_key ); ?>" <?php echo $selected; ?>>
                                            <?php echo esc_html( Product_Feed_Helper::get_refresh_interval_label( $refresh_key ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <td><span><?php esc_html_e( 'Refresh only when products changed', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <?php if ( $edit_feed ) : ?> 
                                    <input name="products_changed" type="checkbox" class="checkbox-field" <?php echo $feed->refresh_only_when_product_changed ? 'checked' : ''; ?>>
                                <?php else : ?>
                                    <input name="products_changed" type="checkbox" class="checkbox-field" <?php echo isset( $feed['products_changed'] ) && 'on' === $feed['products_changed'] ? 'checked' : ''; ?>>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( Helper::get_utm_url( 'update-product-feed-products-changed-new-ones-added', 'pfp', 'googleanalytics-settings', 'total product orders lookback' ) ); ?>" target="_blank">
                                    Read our tutorial about this feature
                                </a>
                            </td>
                        </tr>

                        <tr>
                            <td><span><?php esc_html_e( 'Create a preview of the feed', 'woo-product-feed-pro' ); ?>:</span></td>
                            <td>
                                <?php if ( $edit_feed ) : ?> 
                                    <input name="preview_feed" type="checkbox" class="checkbox-field" <?php echo $feed->create_preview ? 'checked' : ''; ?>>
                                <?php else : ?>
                                    <input name="preview_feed" type="checkbox" class="checkbox-field" <?php echo isset( $feed['preview_feed'] ) && 'on' === $feed['preview_feed'] ? 'checked' : ''; ?>>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( Helper::get_utm_url( 'create-product-feed-preview', 'pfp', 'general-settings', 'create a preview of the feed' ) ); ?>" target="_blank">
                                    Read our tutorial about this feature
                                </a>
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <span>
                                    <?php esc_html_e( 'Remove products that did not have sales in the last days', 'woo-product-feed-pro' ); ?>: 
                                    <a href="<?php echo esc_url( Helper::get_utm_url( 'create-feed-performing-products', 'pfp', 'googleanalytics-settings', 'total product orders lookback' ) ); ?>" target="_blank">
                                        <?php esc_html_e( 'What does this do?', 'woo-product-feed-pro' ); ?>
                                    </a>
                                </span>
                            </td>
                            <td>
                                <?php if ( $edit_feed ) : ?> 
                                    <input type="number" class="input-field input-field-small" name="total_product_orders_lookback" min="0" value="<?php echo $feed->utm_total_product_orders_lookback; ?>" />
                                <?php else : ?>
                                    <input type="number" class="input-field input-field-small" name="total_product_orders_lookback" min="0" value="<?php echo isset( $feed['total_product_orders_lookback'] ) ? esc_attr( max(0, intval($feed['total_product_orders_lookback'])) ) : ''; ?>" />
                                <?php endif; ?>
                                <?php esc_html_e( 'days', 'woo-product-feed-pro' ); ?>
                            </td>
                        </tr>

                        <tr>
                            <td colspan="2">
                                <?php if ( $edit_feed ) : ?> 
                                    <input type="submit" value="<?php esc_attr_e( 'Save Changes', 'woo-product-feed-pro' ); ?>" />
                                <?php else : ?>
                                    <input type="submit" value="<?php esc_attr_e( 'Save & Continue', 'woo-product-feed-pro' ); ?>" />
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php //require_once ADT_PFP_VIEWS_ROOT_PATH . 'view-sidebar.php'; ?>
        </div>
    </form>
</div>
