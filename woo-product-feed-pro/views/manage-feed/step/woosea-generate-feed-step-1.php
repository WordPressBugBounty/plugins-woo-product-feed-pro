<?php
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Classes\Product_Feed_Admin;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Helpers\Helper;

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

delete_option( 'woosea_cat_mapping' );

/**
 * Create notification object
 */
$notifications_obj = new WooSEA_Get_Admin_Notifications();
$notifications_box = $notifications_obj->get_admin_notifications( '1', 'false' );

/**
 * Update or get project configuration
 */
$nonce = wp_create_nonce( 'woosea_ajax_nonce' );

/**
 * Update project configuration
 */
if ( array_key_exists( 'project_hash', $_GET ) ) {
    $feed = Product_Feed_Helper::get_product_feed( sanitize_text_field( $_GET['project_hash'] ) );
    if ( $feed->id ) {
        $feed_mappings = $feed->mappings;
        $channel_data  = $feed->channel;

        $channel_hash = $feed->channel_hash;
        $project_hash = $feed->legacy_project_hash;

        $count_mappings = count( $feed_mappings );
        $manage_project = 'yes';
    }
} else {
    // Sanitize values in multi-dimensional POST array
    if ( is_array( $_POST ) ) {
        foreach ( $_POST as $p_key => $p_value ) {
            if ( is_array( $p_value ) ) {
                foreach ( $p_value as $pp_key => $pp_value ) {
                    if ( is_array( $pp_value ) ) {
                        foreach ( $pp_value as $ppp_key => $ppp_value ) {
                            $_POST[ $p_key ][ $pp_key ][ $ppp_key ] = sanitize_text_field( $ppp_value );
                        }
                    }
                }
            } else {
                $_POST[ $p_key ] = sanitize_text_field( $p_value );
            }
        }
    } else {
        $_POST = array();
    }
    $feed         = Product_Feed_Admin::update_temp_product_feed( $_POST );
    $channel_data = Product_Feed_Helper::get_channel_from_legacy_channel_hash( sanitize_text_field( $_POST['channel_hash'] ) );

    $channel_hash = $feed['channel_hash'];
    $project_hash = $feed['project_hash'];

    $feed_mappings  = array();
    $count_mappings = 0;

}

function woosea_hierarchical_term_tree( $category, $prev_mapped ) {
    $r = '';

    $args = array(
        'parent'        => $category,
        'hide_empty'    => false,
        'no_found_rows' => true,
    );

    $next          = get_terms( 'product_cat', $args );
    $nr_categories = count( $next );
    $yo            = 0;

    if ( $next ) {
        foreach ( $next as $sub_category ) {
            ++$yo;
            $x               = $sub_category->term_id;
            $woo_category    = $sub_category->name;
            $woo_category_id = $sub_category->term_id;

            $mapped_category     = '';
            $mapped_active_class = 'input-field-large';
            $woo_category        = preg_replace( '/&amp;/', '&', $woo_category );
            $woo_category        = preg_replace( '/"/', '&quot;', $woo_category );

            // Check if mapping is in place
            if ( ( array_key_exists( $x, $prev_mapped ) ) || ( array_key_exists( $woo_category, $prev_mapped ) ) ) {
                if ( array_key_exists( $x, $prev_mapped ) ) {
                    $mapped_category = $prev_mapped[ $x ];
                } elseif ( array_key_exists( $woo_category, $prev_mapped ) ) {
                    $mapped_category = $prev_mapped[ $x ];
                } else {
                    $mapped_category = $woo_category;
                }
                $mapped_active_class = 'input-field-large-active';
            }

            // These are main categories
            if ( $sub_category->parent == 0 ) {
                $args = array(
                    'parent'        => $sub_category->term_id,
                    'hide_empty'    => false,
                    'no_found_rows' => true,
                );

                $subcat     = get_terms( 'product_cat', $args );
                $nr_subcats = count( $subcat );

                $r .= '<tr class="catmapping">';
                $r .= "<td><input type=\"hidden\" name=\"mappings[$x][rowCount]\" value=\"$x\"><input type=\"hidden\" name=\"mappings[$x][categoryId]\" value=\"$woo_category_id\"><input type=\"hidden\" name=\"mappings[$x][criteria]\" class=\"input-field-large\" id=\"$woo_category_id\" value=\"$woo_category\">$woo_category ($sub_category->count)</td>";
                $r .= "<td><div id=\"the-basics-$x\"><input type=\"text\" name=\"mappings[$x][map_to_category]\" class=\"$mapped_active_class js-typeahead js-autosuggest autocomplete_$x\" value=\"$mapped_category\"></div></td>";
                if ( ( $yo == $nr_categories ) && ( $nr_subcats == 0 ) ) {
                    $r .= "<td><span class=\"copy_category_$x\" style=\"display: inline-block;\" title=\"Copy this category to all others\"></span></td>";
                } elseif ( $nr_subcats > 0 ) {
                    $r .= "<td><span class=\"dashicons dashicons-arrow-down copy_category_$x\" style=\"display: inline-block;\" title=\"Copy this category to subcategories\"></span><span class=\"dashicons dashicons-arrow-down-alt copy_category_$x\" style=\"display: inline-block;\" title=\"Copy this category to all others\"></span></td>";
                } else {
                    $r .= "<td><span class=\"dashicons dashicons-arrow-down-alt copy_category_$x\" style=\"display: inline-block;\" title=\"Copy this category to all others\"></span></td>";
                }
                $r .= '</tr>';
            } else {
                $r .= '<tr class="catmapping">';
                $r .= "<td><input type=\"hidden\" name=\"mappings[$x][rowCount]\" value=\"$x\"><input type=\"hidden\" name=\"mappings[$x][categoryId]\" value=\"$woo_category_id\"><input type=\"hidden\" name=\"mappings[$x][criteria]\" class=\"input-field-large\" id=\"$woo_category_id\" value=\"$woo_category\">-- $woo_category ($sub_category->count)</td>";
                $r .= "<td><div id=\"the-basics-$x\"><input type=\"text\" name=\"mappings[$x][map_to_category]\" class=\"$mapped_active_class js-typeahead js-autosuggest autocomplete_$x mother_$sub_category->parent\" value=\"$mapped_category\"></div></td>";
                $r .= "<td><span class=\"copy_category_$x\" style=\"display: inline-block;\" title=\"Copy this category to all others\"></span></td>";
                $r .= '</tr>';
            }
            $r .= $sub_category->term_id !== 0 ? woosea_hierarchical_term_tree( $sub_category->term_id, $prev_mapped ) : null;
        }
    }

    $allowed_tags = array(
        'tr'    => array(
            'class' => array(),
        ),
        'td'    => array(),
        'input' => array(
            'type'  => array(),
            'name'  => array(),
            'value' => array(),
            'class' => array(),
            'id'    => array(),
        ),
        'span'  => array(
            'class' => array(),
            'style' => array(),
            'title' => array(),
        ),
        'div'   => array(
            'id' => array(),
        ),
        '>'     => array(),
        '&'     => array(),
    );
    return wp_kses_normalize_entities( $r, $allowed_tags );
}

/**
 * Action hook to add content before the product feed manage page.
 *
 * @param int                      $step         Step number.
 * @param string                   $project_hash Project hash.
 * @param array|Product_Feed|null  $feed         Product_Feed object or array of project data.
 */
do_action( 'adt_before_product_feed_manage_page', 1, $project_hash, $feed );
?>

<div class="wrap">
    <div class="woo-product-feed-pro-form-style-2">
        <div class="woo-product-feed-pro-form-style-2-heading">
            <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a> 
            <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
            <a href="https://adtribes.io/?utm_source=pfp&utm_medium=logo&utm_campaign=adminpagelogo" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
            <?php endif; ?>
            <h1 class="title"><?php esc_html_e( 'Category mapping', 'woo-product-feed-pro' ); ?></h1>
        </div>

        <div class="<?php echo esc_attr( $notifications_box['message_type'] ); ?>">
            <p><?php echo wp_kses_post( $notifications_box['message'] ); ?></p>
        </div>

        <div class="woo-product-feed-pro-table-wrapper">
            <div class="woo-product-feed-pro-table-left">

                <table id="woosea-ajax-mapping-table" class="woo-product-feed-pro-table" border="1">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Your category', 'woo-product-feed-pro' ); ?> <i>(<?php esc_html_e( 'Number of products', 'woo-product-feed-pro' ); ?>)</i></th>
                            <th><?php echo "$channel_data[name]"; ?> <?php esc_html_e( 'category', 'woo-product-feed-pro' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody class="woo-product-feed-pro-body">
                        <?php
                        // Get already mapped categories
                        $prev_mapped = array();
                        if ( ! empty( $feed_mappings ) ) {
                            foreach ( $feed_mappings as $map_key => $map_value ) {
                                if ( strlen( $map_value['map_to_category'] ) > 0 ) {
                                    $map_value['criteria']                   = str_replace( '\\', '', $map_value['criteria'] );
                                    $prev_mapped[ $map_value['categoryId'] ] = $map_value['map_to_category'];
                                    // $prev_mapped[$map_value['criteria']] = $map_value['map_to_category'];
                                }
                            }
                        }

                        // Display mapping form
                        echo woosea_hierarchical_term_tree( 0, $prev_mapped );
                        ?>
                    </tbody>

                    <form action="" method="post">
                        <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>

                        <tr>
                            <td colspan="3">
                                <input type="hidden" id="channel_hash" name="channel_hash" value="<?php echo esc_attr( $channel_hash ); ?>">
                                <?php
                                if ( isset( $manage_project ) ) {
                                ?>
                                    <input type="hidden" name="project_update" id="project_update" value="yes" />
                                    <input type="hidden" id="project_hash" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                                    <input type="hidden" name="step" value="100">
                                    <input type="submit" value="Save mappings" />
                                <?php
                                } else {
                                ?>
                                    <input type="hidden" id="project_hash" name="project_hash" value="<?php echo esc_attr( $project_hash ); ?>">
                                    <input type="hidden" name="step" value="4">
                                    <input type="submit" value="Save mappings" />
                                <?php
                                }
                                ?>
                            </td>
                        </tr>

                    </form>

                </table>
            </div>
            <?php require_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'view-sidebar.php'; ?>
        </div>
    </div>
</div>
