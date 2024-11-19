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
$notifications_box = $notifications_obj->get_admin_notifications( '4', 'false' );

/**
 * Create product attribute object
 */
$product_feed_attributes = new Product_Feed_Attributes();
$attributes              = $product_feed_attributes->get_attributes();

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
        $feed_rules     = $feed->rules;
        $feed_filters   = $feed->filters;
        $channel_data   = $feed->channel;
        $manage_project = 'yes';

        $channel_hash = $feed->channel_hash;
        $project_hash = $feed->legacy_project_hash;

        $count_rules = 0;
        if ( ! empty( $feed_filters ) ) {
            $count_rules = count( $feed_filters );
        }

        $count_rules2 = 0;
        if ( ! empty( $feed_rules ) ) {
            $count_rules2 = count( $feed_rules );
        }
    }
} else {
    $feed         = Product_Feed_Admin::update_temp_product_feed( $_POST ?? array() );
    $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( sanitize_text_field( $_POST['channel_hash'] ) );

    $channel_hash = $feed['channel_hash'];
    $project_hash = $feed['project_hash'];

    $count_rules  = 0;
    $count_rules2 = 0;
}

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 4, $project_hash, $feed );
?>
    <div class="wrap">
        <div class="woo-product-feed-pro-form-style-2">
            <div class="woo-product-feed-pro-form-style-2-heading">
                <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a> 
                <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
                <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
                <?php endif; ?>
                <h1 class="title"><?php esc_html_e( 'Feed filters and rules', 'woo-product-feed-pro' ); ?></h1>
            </div>

            <div class="<?php echo esc_attr( $notifications_box['message_type'] ); ?>">
                <p><?php echo wp_kses_post( $notifications_box['message'] ); ?></p>
            </div>
            <form id="rulesandfilters" method="post">
            <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>

            <table class="woo-product-feed-pro-table" id="woosea-ajax-table" border="1">
                <thead>
                    <tr>
                        <th></th>
                        <th><?php esc_html_e( 'Type', 'woo-product-feed-pro' ); ?></th>
                        <th>
                            <?php
                            esc_html_e( 'IF', 'woo-product-feed-pro' );
                            echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                esc_html__(
                                    'Specify the condition under which this filter or rule will be applied. Choose an attribute or condition that will trigger this rule.',
                                    'woo-product-feed-pro'
                                )
                            );
                            ?>
                        </th>
                        <th>
                            <?php
                            esc_html_e( 'Condition', 'woo-product-feed-pro' );
                            echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                esc_html__(
                                    'Define the specific condition to be met. Options include equals, not equals, greater than, less than, etc., depending on the selected attribute.',
                                    'woo-product-feed-pro'
                                )
                            );
                            ?>
                        </th>
                        <th>
                            <?php
                            esc_html_e( 'Value', 'woo-product-feed-pro' );
                            echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                esc_html__(
                                    'Enter the value that the condition should match. This value will be compared against the attribute chosen in the IF field.',
                                    'woo-product-feed-pro'
                                )
                            );
                            ?>
                        </th>
                        <th>
                            <?php
                            esc_html_e( 'CS', 'woo-product-feed-pro' );
                            echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                esc_html__(
                                    'Enable this option if the condition should be case-sensitive. This means that \'Product\' and \'product\' will be treated as different values.',
                                    'woo-product-feed-pro'
                                )
                            );
                            ?>
                        </th>
                        <th>
                            <?php
                            esc_html_e( 'Then', 'woo-product-feed-pro' );
                            echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                esc_html__(
                                    'Specify the action to be taken if the condition is met. This could be including, excluding, or modifying a product attribute.',
                                    'woo-product-feed-pro'
                                )
                            );
                            ?>
                        </th>
                        <th>
                            <?php
                            esc_html_e( 'IS', 'woo-product-feed-pro' );
                            echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                esc_html__(
                                    'Define the result or value to be applied when the condition is met. This complements the action specified in the THEN field.',
                                    'woo-product-feed-pro'
                                )
                            );
                            ?>
                        </th>
                    </tr>
                </thead>
      
                <?php
                    print '<tbody class="woo-product-feed-pro-body">';
                    if ( isset( $feed_filters ) ) {
                        foreach ( $feed_filters as $rule_key => $rule_array ) {

                            if ( isset( $feed_filters[ $rule_key ]['criteria'] ) ) {
                                $criteria = $feed_filters[ $rule_key ]['criteria'];
                            } else {
                                $criteria = '';
                            }
                            ?>
                            <tr class="rowCount">
                                <td><input type="hidden" name="rules[<?php echo "$rule_key"; ?>][rowCount]" value="<?php echo "$rule_key"; ?>"><input type="checkbox" name="record" class="checkbox-field"></td>
                                <td><i><?php esc_html_e( 'Filter', 'woo-product-feed-pro' ); ?></i></td>
                                <td>
                                    <select name="rules[<?php echo "$rule_key"; ?>][attribute]" class="select-field woo-sea-select2">
                                        <option></option>
                                        <?php
                                        if ( ! empty( $attributes ) ) :
                                            foreach ( $attributes as $group_name => $attribute ) :
                                            ?>
                                                <optgroup label='<?php echo esc_html( $group_name ); ?>'>
                                                <?php
                                                if ( ! empty( $attribute ) ) :
                                                    foreach ( $attribute as $attr => $attr_label ) :
                                                    ?>
                                                        <option 
                                                            value="<?php echo esc_attr( $attr ); ?>"
                                                            <?php echo $feed_filters[ $rule_key ]['attribute'] === $attr ? 'selected' : ''; ?>
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
                                    <select name="rules[<?php echo "$rule_key"; ?>][condition]" class="select-field woo-sea-select2">
                                        <?php
                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == 'contains' ) ) {
                                            print '<option value="contains" selected>contains</option>';
                                        } else {
                                            print '<option value="contains">contains</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == 'containsnot' ) ) {
                                            echo "<option value=\"containsnot\" selected>doesn't contain</option>";
                                        } else {
                                            echo "<option value=\"containsnot\">doesn't contain</option>";
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == '=' ) ) {
                                            print '<option value="=" selected>is equal to</option>';
                                        } else {
                                            print '<option value="=">is equal to</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == '!=' ) ) {
                                            print '<option value="!=" selected>is not equal to</option>';
                                        } else {
                                            print '<option value="!=">is not equal to</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == '>' ) ) {
                                            print '<option value=">" selected>is greater than</option>';
                                        } else {
                                            print '<option value=">">is greater than</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == '>=' ) ) {
                                            print '<option value=">=" selected>is greater or equal to</option>';
                                        } else {
                                            print '<option value=">=">is greater or equal to</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == '<' ) ) {
                                            print '<option value="<" selected>is less than</option>';
                                        } else {
                                            print '<option value="<">is less than</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == '=<' ) ) {
                                            print '<option value="=<" selected>is less or equal to</option>';
                                        } else {
                                            print '<option value="=<">is less or equal to</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == 'empty' ) ) {
                                            print '<option value="empty" selected>is empty</option>';
                                        } else {
                                            print '<option value="empty">is empty</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['condition'] ) && ( $feed_filters[ $rule_key ]['condition'] == 'notempty' ) ) {
                                            print '<option value="notempty" selected>is not empty</option>';
                                        } else {
                                            print '<option value="notempty">is not empty</option>';
                                        }
                                        ?>
                                    </select>   
                                </td>
                                <td>
                                    <div style="display: block;">
                                        <input type="text" id="rulevalue" name="rules[<?php echo "$rule_key"; ?>][criteria]" class="input-field-large" value='<?php print $criteria; ?>'>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    if ( isset( $feed_filters[ $rule_key ]['cs'] ) ) {
                                        echo "<input type=\"checkbox\" name=\"rules[$rule_key][cs]\" class=\"checkbox-field\" alt=\"Case sensitive\" checked>";
                                    } else {
                                        echo "<input type=\"checkbox\" name=\"rules[$rule_key][cs]\" class=\"checkbox-field\" alt=\"Case sensitive\">";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <select name="rules[<?php echo "$rule_key"; ?>][than]" class="select-field">
                                        <optgroup label='Action'>Action:
                                        <?php
                                        if ( isset( $feed_filters[ $rule_key ]['than'] ) && ( $feed_filters[ $rule_key ]['than'] == 'exclude' ) ) {
                                            print '<option value="exclude" selected> Exclude</option>';
                                        } else {
                                            print '<option value="exclude"> Exclude</option>';
                                        }

                                        if ( isset( $feed_filters[ $rule_key ]['than'] ) && ( $feed_filters[ $rule_key ]['than'] == 'include_only' ) ) {
                                            print '<option value="include_only" selected> Include only</option>';
                                        } else {
                                            print '<option value="include_only"> Include only</option>';
                                        }
                                        ?>
                                        </optgroup>
                                    </select>
                                </td>
                                <td>&nbsp;</td>
                            </tr>
                        <?php
                        }
                    }

                    // RULES SECTION
                    if ( isset( $feed_rules ) ) {

                        foreach ( $feed_rules as $rule2_key => $rule2_array ) {

                            if ( isset( $feed_rules[ $rule2_key ]['criteria'] ) ) {
                                $criteria = $feed_rules[ $rule2_key ]['criteria'];
                            } else {
                                $criteria = '';
                            }
                            if ( isset( $feed_rules[ $rule2_key ]['newvalue'] ) ) {
                                $newvalue = $feed_rules[ $rule2_key ]['newvalue'];
                            } else {
                                $newvalue = '';
                            }
                            ?>
                                <tr class="rowCount">
                                    <td><input type="hidden" name="rules2[<?php echo "$rule2_key"; ?>][rowCount]" value="<?php echo "$rule2_key"; ?>"><input type="checkbox" name="record" class="checkbox-field"></td>
                                    <td><i><?php esc_html_e( 'Rule', 'woo-product-feed-pro' ); ?></i></td>
                                <td>
                                <select name="rules2[<?php echo "$rule2_key"; ?>][attribute]" class="select-field woo-sea-select2">
                                    <option></option>
                                    <?php
                                    if ( ! empty( $attributes ) ) :
                                        foreach ( $attributes as $group_name => $attribute ) :
                                        ?>
                                            <optgroup label='<?php echo esc_html( $group_name ); ?>'>
                                            <?php
                                            if ( ! empty( $attribute ) ) :
                                                foreach ( $attribute as $attr => $attr_label ) :
                                                ?>
                                                    <option 
                                                        value="<?php echo esc_attr( $attr ); ?>"
                                                        <?php echo $feed_rules[ $rule2_key ]['attribute'] === $attr ? 'selected' : ''; ?>
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
                                    <select name="rules2[<?php echo "$rule2_key"; ?>][condition]" class="select-field woo-sea-select2">
                                        <?php
                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'contains' ) ) {
                                            print '<option value="contains" selected>contains</option>';
                                        } else {
                                            print '<option value="contains">contains</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'containsnot' ) ) {
                                            echo "<option value=\"containsnot\" selected>doesn't contain</option>";
                                        } else {
                                            echo "<option value=\"containsnot\">doesn't contain</option>";
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == '=' ) ) {
                                            print '<option value="=" selected>is equal to</option>';
                                        } else {
                                            print '<option value="=">is equal to</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == '!=' ) ) {
                                            print '<option value="!=" selected>is not equal to</option>';
                                        } else {
                                            print '<option value="!=">is not equal to</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == '>' ) ) {
                                            print '<option value=">" selected>is greater than</option>';
                                        } else {
                                            print '<option value=">">is greater than</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == '>=' ) ) {
                                            print '<option value=">=" selected>is greater or equal to</option>';
                                        } else {
                                            print '<option value=">=">is greater or equal to</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == '<' ) ) {
                                            print '<option value="<" selected>is less than</option>';
                                        } else {
                                            print '<option value="<">is less than</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == '=<' ) ) {
                                            print '<option value="=<" selected>is less or equal to</option>';
                                        } else {
                                            print '<option value="=<">is less or equal to</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'empty' ) ) {
                                            print '<option value="empty" selected>is empty</option>';
                                        } else {
                                            print '<option value="empty">is empty</option>';
                                        }

                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'notempty' ) ) {
                                            print '<option value="notempty" selected>is not empty</option>';
                                        } else {
                                            print '<option value="notempty">is not empty</option>';
                                        }

                                        // Data manipulators
                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'multiply' ) ) {
                                            print '<option value="multiply" selected>multiply</option>';
                                        } else {
                                            print '<option value="multiply">multiply</option>';
                                        }
                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'divide' ) ) {
                                            print '<option value="divide" selected>divide</option>';
                                        } else {
                                            print '<option value="divide">divide</option>';
                                        }
                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'plus' ) ) {
                                            print '<option value="plus" selected>plus</option>';
                                        } else {
                                            print '<option value="plus">plus</option>';
                                        }
                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'minus' ) ) {
                                            print '<option value="minus" selected>minus</option>';
                                        } else {
                                            print '<option value="minus">minus</option>';
                                        }
                                        if ( isset( $feed_rules[ $rule2_key ]['condition'] ) && ( $feed_rules[ $rule2_key ]['condition'] == 'findreplace' ) ) {
                                            print '<option value="findreplace" selected>find and replace</option>';
                                        } else {
                                            print '<option value="findreplace">find and replace</option>';
                                        }
                                        ?>
                                    </select>   
                                </td>
                                <td>
                                    <div style="display: block;">
                                        <input type="text" id="rulevalue" name="rules2[<?php echo "$rule2_key"; ?>][criteria]" class="input-field-large" value='<?php print $criteria; ?>'>
                                    </div>
                                </td>
                                <?php
                                    $manipulators = array( 'multiply', 'divide', 'plus', 'minus' );
                                    if ( in_array( $feed_rules[ $rule2_key ]['condition'], $manipulators, true ) ) {
                                        print '<td colspan=3></td>';
                                    } else {
                                    ?>
                                    <td>
                                        <?php
                                        if ( isset( $feed_rules[ $rule2_key ]['cs'] ) ) {
                                            echo "<input type=\"checkbox\" name=\"rules2[$rule2_key][cs]\" class=\"checkbox-field\" alt=\"Case sensitive\" checked>";
                                        } else {
                                            echo "<input type=\"checkbox\" name=\"rules2[$rule2_key][cs]\" class=\"checkbox-field\" alt=\"Case sensitive\">";
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <select name="rules2[<?php echo "$rule2_key"; ?>][than_attribute]" class="select-field woo-sea-select2" style="width:300px;">
                                            <option></option>
                                            <?php
                                            if ( ! empty( $attributes ) ) :
                                                foreach ( $attributes as $group_name => $attribute ) :
                                                ?>
                                                    <optgroup label='<?php echo esc_html( $group_name ); ?>'>
                                                    <?php
                                                    if ( ! empty( $attribute ) ) :
                                                        foreach ( $attribute as $attr => $attr_label ) :
                                                        ?>
                                                            <option 
                                                                value="<?php echo esc_attr( $attr ); ?>"
                                                                <?php echo $feed_rules[ $rule2_key ]['than_attribute'] === $attr ? 'selected' : ''; ?>
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
                                    <td><input type="text" name="rules2[<?php echo "$rule2_key"; ?>][newvalue]" class="input-field-large" value="<?php echo "$newvalue"; ?>"></td>
                                <?php
                                    }
                                ?>
                            </tr>
                        <?php
                        }
                    }
                    print '</tbody>';
                ?>
                <tbody>
                <tr class="rules-buttons">
                    <td colspan="8">
                        <input type="hidden" id="channel_hash" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                        <?php if ( isset( $manage_project ) ) : ?>
                            <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                            <input type="hidden" name="woosea_page" value="filters_rules">
                            <input type="hidden" name="step" value="100">
                            <input type="button" class="delete-row" value="- Delete">&nbsp;<input type="button" class="add-filter" value="+ Add filter">&nbsp;<input type="button" class="add-rule" value="+ Add rule">&nbsp;<input type="submit" id="savebutton" value="Save">
                        <?php else : ?>
                            <input type="hidden" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                            <input type="hidden" name="step" value="5">
                            <input type="button" class="delete-row" value="- Delete">&nbsp;<input type="button" class="add-filter" value="+ Add filter">&nbsp;<input type="button" class="add-rule" value="+ Add rule">&nbsp;<input type="submit" id="savebutton" value="Continue">
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>
        </form>
    </div>
</div>
