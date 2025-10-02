<?php
// phpcs:disable
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Factories\Admin_Notice;
use AdTribes\PFP\Classes\Product_Feed_Attributes;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

/**
 * Create product attribute object
 */
$product_feed_attributes = new Product_Feed_Attributes();
$attribute_dropdown      = $product_feed_attributes->get_attributes();

$feed         = null;
$feed_id      = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
$edit_feed    = false;
if ( $feed_id ) {
    $feed = Product_Feed_Helper::get_product_feed( sanitize_text_field( $feed_id ) );
    if ( $feed ) {
        $feed_attributes = $feed->attributes;
        $channel_data    = $feed->channel;

        $project_hash = $feed->legacy_project_hash;
        $channel_hash = $feed->channel_hash;

        $edit_feed = true;
    }
} else {
    /**
     * The condition below is when the user is creating a new project.
     *
     * The user is redirected to the field mapping page after the general settings page.
     * Those settings are stored in the temporary option.
     * This is a legacy code that needs to be refactored.
     * For now, we will just add the necessary code to make it work.
     */
    $feed            = get_option( ADT_OPTION_TEMP_PRODUCT_FEED, array() );
    $channel_hash    = $feed['channel_hash'] ?? '';
    $project_hash    = $feed['project_hash'] ?? '';
    $channel_data    = '' !== $channel_hash ? Product_Feed_Helper::get_channel_from_legacy_channel_hash( $channel_hash ) : array();

    $feed_attributes = array();
    if ( !empty( $feed['attributes'] ) && is_array( $feed['attributes'] ) ) {
        $feed_attributes = $feed['attributes'];
    }
}

/**
 * Determine next step in configuration flow
 */
$step = isset( $channel_data['taxonomy'] ) && 'none' !== $channel_data['taxonomy'] ? 1 : 4;

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 7, $project_hash, $feed );

/**
 * Get main currency
 */
$currency = apply_filters( 'adt_product_feed_currency', get_woocommerce_currency() );

/**
 * Create channel attribute object
 */
$channel_attributes  = array();
$channel_data_fields = $channel_data['fields'] ?? '';
$channel_class_file  = ADT_PFP_CHANNEL_CLASS_ROOT_PATH . 'class-' . $channel_data_fields . '.php';
if (file_exists($channel_class_file)) {
    require $channel_class_file;
    $obj        = 'WooSEA_' . $channel_data_fields;
    $fields_obj = new $obj();
    $channel_attributes = $fields_obj->get_channel_attributes();
}
?>
<div id="dialog" title="Basic dialog">
    <p>
    <div id="dialogText"></div>
    </p>
</div>

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
        'html',
        false
    );
    $admin_notice->run();
    ?>

    <form class="adt-edit-feed-form" id="field_mapping" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
        <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
        <input type="hidden" name="action" value="edit_feed_form_process" />
        <input type="hidden" name="active_tab" value="field_mapping" />
        <input type="hidden" name="feed_id" value="<?php echo $feed->id ?? ''; ?>" />
        <table class="woo-product-feed-pro-table" id="woosea-fieldmapping-table" border="1">
            <thead>
                <tr>
                    <th></th>
                    <th><?php echo $channel_data['name'] ?? ''; ?> attributes</th>
                    <th><?php esc_html_e( 'Prefix', 'woo-product-feed-pro' ); ?></th>
                    <th><?php esc_html_e( 'Value', 'woo-product-feed-pro' ); ?></th>
                    <th><?php esc_html_e( 'Suffix', 'woo-product-feed-pro' ); ?></th>
                </tr>
            </thead>

            <tbody class="woo-product-feed-pro-body">
                <?php
                if ( ! empty( $channel_attributes ) ) {
                    if ( ! isset( $feed_attributes ) || empty( $feed_attributes ) ) {
                        $c = 0;
                        foreach ( $channel_attributes as $row_key => $row_value ) {
                            foreach ( $row_value as $row_k => $row_v ) {
                                if ( $row_v['format'] == 'required' ) {
                                ?>
                                    <tr class="rowCount <?php echo "$c"; ?>">
                                        <td><input type="hidden" name="attributes[<?php echo "$c"; ?>][rowCount]" value="<?php echo "$c"; ?>">
                                            <input type="checkbox" name="record" class="checkbox-field">
                                        </td>
                                        <td>
                                            <select name="attributes[<?php echo "$c"; ?>][attribute]" class="select-field woo-sea-select2">
                                                <?php
                                                foreach ( $channel_attributes as $key => $value ) {
                                                    echo "<optgroup label='$key'><strong>$key</strong>";

                                                    foreach ( $value as $k => $v ) {
                                                        if ( $v['feed_name'] == $row_v['feed_name'] ) {
                                                            if ( array_key_exists( 'name', $v ) ) {
                                                                $dialog_value = $v['feed_name'];
                                                                echo "<option value='$v[feed_name]' selected>$k ($v[name])</option>";
                                                            } else {
                                                                echo "<option value='$v[feed_name]' selected>$k</option>";
                                                            }
                                                        } elseif ( array_key_exists( 'name', $v ) ) {
                                                            echo "<option value='$v[feed_name]'>$k ($v[name])</option>";
                                                        } else {
                                                            echo "<option value='$v[feed_name]'>$k</option>";
                                                        }
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php
                                            if ( $row_v['feed_name'] == 'g:price' ) {
                                                echo "<input type='text' name='attributes[$c][prefix]' value='$currency ' class='input-field-medium'>";
                                            } else {
                                                echo "<input type='text' name='attributes[$c][prefix]' class='input-field-medium'>";
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <select name="attributes[<?php echo "$c"; ?>][mapfrom]" class="select-field woo-sea-select2">
                                                <option></option>
                                                <?php
                                                if ( ! empty( $attribute_dropdown ) ) :
                                                    foreach ( $attribute_dropdown as $group_name => $attribute ) :
                                                    ?>
                                                        <optgroup label='<?php echo esc_html( $group_name ); ?>'>
                                                        <?php
                                                        if ( ! empty( $attribute ) ) :
                                                            foreach ( $attribute as $attr => $attr_label ) :
                                                            ?>
                                                                <option 
                                                                    value="<?php echo esc_attr( $attr ); ?>"
                                                                    <?php echo array_key_exists( 'woo_suggest', $row_v ) && $row_v['woo_suggest'] == $attr ? 'selected' : ''; ?>
                                                                >
                                                                    <?php echo esc_html( $attr_label ); ?>
                                                                </option>
                                                                <?php
                                                            endforeach;
                                                        endif;
                                                    endforeach;
                                                endif;
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="attributes[<?php echo "$c"; ?>][suffix]" class="input-field-medium">
                                        </td>
                                    </tr>
                            <?php
                                    ++$c;
                                }
                            }
                        }
                    } else {
                        foreach ( $feed_attributes as $attribute_key => $attribute_array ) {
                            if ( isset( $feed_attributes[ $attribute_key ]['prefix'] ) ) {
                                $prefix = $feed_attributes[ $attribute_key ]['prefix'];
                            }
                            if ( isset( $feed_attributes[ $attribute_key ]['suffix'] ) ) {
                                $suffix = $feed_attributes[ $attribute_key ]['suffix'];
                            }
                            ?>
                            <tr class="rowCount <?php echo "$attribute_key"; ?>">
                                <td><input type="hidden" name="attributes[<?php echo "$attribute_key"; ?>][rowCount]" value="<?php echo "$attribute_key"; ?>">
                                    <input type="checkbox" name="record" class="checkbox-field">
                                </td>
                                <td>
                                    <select name="attributes[<?php echo "$attribute_key"; ?>][attribute]" class="select-field">
                                        <?php
                                        echo "<option value=\"$attribute_array[attribute]\">$attribute_array[attribute]</option>";
                                        ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="attributes[<?php echo "$attribute_key"; ?>][prefix]" class="input-field-medium" value="<?php echo "$prefix"; ?>">
                                </td>
                                <td>
                                    <?php
                                    if ( array_key_exists( 'static_value', $attribute_array ) ) {
                                        echo "<input type=\"text\" name=\"attributes[$attribute_key][mapfrom]\" class=\"input-field-midsmall\" value=\"$attribute_array[mapfrom]\"><input type=\"hidden\" name=\"attributes[$attribute_key][static_value]\" value=\"true\">";
                                    } else {
                                    ?>
                                        <select name="attributes[<?php echo "$attribute_key"; ?>][mapfrom]" class="select-field woo-sea-select2">
                                            <option></option>
                                            <?php
                                            if ( ! empty( $attribute_dropdown ) ) :
                                                foreach ( $attribute_dropdown as $group_name => $attribute ) :
                                                ?>
                                                    <optgroup label='<?php echo esc_html( $group_name ); ?>'>
                                                    <?php
                                                    if ( ! empty( $attribute ) ) :
                                                        foreach ( $attribute as $attr => $attr_label ) :
                                                        ?>
                                                            <option 
                                                                value="<?php echo esc_attr( $attr ); ?>"
                                                                <?php echo $feed_attributes[ $attribute_key ]['mapfrom'] === $attr ? 'selected' : ''; ?>
                                                            >
                                                                <?php echo esc_html( $attr_label ); ?>
                                                            </option>
                                                            <?php
                                                        endforeach;
                                                    endif;
                                                endforeach;
                                            endif;
                                            ?>
                                        </select>
                                    <?php
                                    }
                                    ?>
                                </td>
                                <td>
                                    <input type="text" name="attributes[<?php echo "$attribute_key"; ?>][suffix]" class="input-field-medium" value="<?php echo "$suffix"; ?>">
                                </td>
                            </tr>
                        <?php
                        }
                    }
                } else {
                ?>
                    <tr>
                        <td colspan='6' style="text-align: center;">
                            <?php esc_html_e( 'You haven\'t selected a channel for this feed yet.', 'woo-product-feed-pro' ); ?>
                        </td>
                    </tr>
                <?php
                }
                ?>
            </tbody>

            <tr>
                <td colspan="6">
                    <div class="adt-edit-feed-form-buttons adt-tw-flex adt-tw-gap-2 adt-tw-items-center">
                        <input type="hidden" id="channel_hash" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                        <?php if ( $edit_feed ) : ?>
                            <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                            <input type="hidden" name="addrow" id="addrow" value="1">
                            <button type="button" class="adt-button adt-button-sm delete-field-mapping">- <?php esc_attr_e( 'Delete', 'woo-product-feed-pro' ); ?></button>
                            <button type="button" class="adt-button adt-button-sm add-field-mapping">+ <?php esc_attr_e( 'Add field mapping', 'woo-product-feed-pro' ); ?></button>
                            <button type="button" class="adt-button adt-button-sm add-own-mapping">+ <?php esc_attr_e( 'Add custom field', 'woo-product-feed-pro' ); ?></button>
                            <button type="submit" class="adt-button adt-button-sm adt-button-primary" id="savebutton">
                                <?php esc_attr_e( 'Save Changes', 'woo-product-feed-pro' ); ?>
                            </button>
                        <?php else : ?>
                            <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                            <input type="hidden" name="addrow" id="addrow" value="1">
                            <button type="button" class="adt-button adt-button-sm delete-field-mapping">- <?php esc_attr_e( 'Delete', 'woo-product-feed-pro' ); ?></button>
                            <button type="button" class="adt-button adt-button-sm add-field-mapping">+ <?php esc_attr_e( 'Add field mapping', 'woo-product-feed-pro' ); ?></button>
                            <button type="button" class="adt-button adt-button-sm add-own-mapping">+<?php esc_attr_e( 'Add custom field', 'woo-product-feed-pro' ); ?></button>
                            <?php
                            // Check if channel has taxonomy
                            $has_taxonomy = isset($channel_data['taxonomy']) && $channel_data['taxonomy'] !== 'none';
                            $next_step = $has_taxonomy ? __('Category Mapping', 'woo-product-feed-pro') : __('Filters & Rules', 'woo-product-feed-pro');
                            ?>
                            <button type="submit" class="adt-button adt-button-sm adt-button-primary" id="savebutton">
                                <?php esc_attr_e( 'Save & Continue', 'woo-product-feed-pro' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
    </form>
</div>
