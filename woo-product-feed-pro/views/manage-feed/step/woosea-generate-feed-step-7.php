<?php
// phpcs:disable
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Classes\Product_Feed_Admin;
use AdTribes\PFP\Classes\Product_Feed_Attributes;
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
 * Create notification object
 */
$notifications_obj = new WooSEA_Get_Admin_Notifications();
$notifications_box = $notifications_obj->get_admin_notifications( '7', 'false' );

/**
 * Create product attribute object
 */
$product_feed_attributes = new Product_Feed_Attributes();
$attribute_dropdown      = $product_feed_attributes->get_attributes();

/**
 * Update or get project configuration
 */
$nonce = wp_create_nonce( 'woosea_ajax_nonce' );
/**
 * Update or get project configuration
 */
if ( array_key_exists( 'project_hash', $_GET ) ) {
    $feed = Product_Feed_Helper::get_product_feed( sanitize_text_field( $_GET['project_hash'] ) );
    if ( $feed->id ) {
        $feed_attributes = $feed->attributes;
        $channel_data    = $feed->channel;
        $count_mappings  = count( $feed_attributes );

        $project_hash = $feed->legacy_project_hash;
        $channel_hash = $feed->channel_hash;
    }
    $manage_project = 'yes';
} else {
    /**
     * The condition below is when the user is creating a new project.
     *
     * The user is redirected to the field mapping page after the general settings page.
     * Those settings are stored in the temporary option.
     * This is a legacy code that needs to be refactored.
     * For now, we will just add the necessary code to make it work.
     */
    $feed         = Product_Feed_Admin::update_temp_product_feed( $_POST ?? array() );
    $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( sanitize_text_field( $_POST['channel_hash'] ) );

    $channel_hash = $feed['channel_hash'];
    $project_hash = $feed['project_hash'];

    $feed_attributes = array();
}

/**
 * Determine next step in configuration flow
 */
$step = 4;
if ( $channel_data['taxonomy'] != 'none' ) {
    $step = 1;
}

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
require WOOCOMMERCESEA_CHANNEL_CLASS_ROOT_PATH . 'class-' . $channel_data['fields'] . '.php';
$obj        = 'WooSEA_' . $channel_data['fields'];
$fields_obj = new $obj();
$attributes = $fields_obj->get_channel_attributes();
?>
<div id="dialog" title="Basic dialog">
    <p>
    <div id="dialogText"></div>
    </p>
</div>

<div class="wrap">
    <div class="woo-product-feed-pro-form-style-2">
        <div class="woo-product-feed-pro-form-style-2-heading">
            <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a>
            <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
            <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
            <?php endif; ?>
            <h1 class="title"><?php esc_html_e( 'Field mapping', 'woo-product-feed-pro' ); ?></h1>
        </div>

        <div class="<?php echo esc_attr( $notifications_box['message_type'] ); ?>">
            <p><?php echo wp_kses_post( $notifications_box['message'] ); ?></p>
        </div>

        <form action="" id="fieldmapping" method="post">
            <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>
            <table class="woo-product-feed-pro-table" id="woosea-fieldmapping-table" border="1">
                <thead>
                    <tr>
                        <th></th>
                        <th>
                            <?php
                            echo "$channel_data[name] attributes";
                            ?>
                        </th>
                        <th><?php esc_html_e( 'Prefix', 'woo-product-feed-pro' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'woo-product-feed-pro' ); ?></th>
                        <th><?php esc_html_e( 'Suffix', 'woo-product-feed-pro' ); ?></th>
                    </tr>
                </thead>

                <tbody class="woo-product-feed-pro-body">
                    <?php
                    if ( ! isset( $count_mappings ) ) {
                        $c = 0;
                        foreach ( $attributes as $row_key => $row_value ) {
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
                                                foreach ( $attributes as $key => $value ) {
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
                    ?>
                </tbody>

                <tr>
                    <td colspan="6">
                        <input type="hidden" id="channel_hash" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                        <?php
                        if ( isset( $manage_project ) ) {
                        ?>
                            <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                            <input type="hidden" name="step" value="100">
                            <input type="hidden" name="addrow" id="addrow" value="1">
                            <input type="button" class="delete-field-mapping" value="- Delete">&nbsp;<input type="button" class="add-field-mapping" value="+ Add field mapping">&nbsp;<input type="button" class="add-own-mapping" value="+ Add custom field">&nbsp;<input type="submit" id="savebutton" value="Save" />

                        <?php
                        } else {
                        ?>
                            <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                            <input type="hidden" name="step" value="<?php echo "$step"; ?>">
                            <input type="hidden" name="addrow" id="addrow" value="1">
                            <input type="button" class="delete-field-mapping" value="- Delete">&nbsp;<input type="button" class="add-field-mapping" value="+ Add field mapping">&nbsp;<input type="button" class="add-own-mapping" value="+ Add custom field">&nbsp;<input type="submit" id="savebutton" value="Save" />
                        <?php
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </form>
    </div>
</div>
