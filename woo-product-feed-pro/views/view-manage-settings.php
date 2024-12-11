<?php
//phpcs:disable
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

$total_projects      = Product_Feed_Helper::get_total_product_feed();
$domain              = sanitize_text_field( $_SERVER['HTTP_HOST'] );
$plugin_settings     = get_option( 'plugin_settings' );
$directory_perm_xml  = '';
$directory_perm_csv  = '';
$directory_perm_txt  = '';
$directory_perm_tsv  = '';
$directory_perm_logs = '';
$elite_disable       = 'disabled';
$count_variation     = wp_count_posts( 'product_variation' );
$count_single        = wp_count_posts( 'product' );
$published_single    = $count_single->publish;
$published_variation = $count_variation->publish;
$published_products  = $published_single + $published_variation;
$product_numbers     = array(
    'Single products'    => $published_single,
    'Variation products' => $published_variation,
    'Total products'     => $published_products,
);

$versions = array(
    'PHP'                          => (float) phpversion(),
    'Wordpress'                    => get_bloginfo( 'version' ),
    'WooCommerce'                  => WC()->version,
    'WooCommerce Product Feed PRO' => WOOCOMMERCESEA_PLUGIN_VERSION,
);

$order_rows = '';

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

// we check if the page is visited by click on the tabs or on the menu button.
// then we get the active tab.
$active_tab = 'woosea_manage_settings';

$header_text = __( 'Plugin settings', 'woo-product-feed-pro' );
if ( isset( $_GET['tab'] ) ) {
    if ( $_GET['tab'] == 'woosea_manage_settings' ) {
        $active_tab  = 'woosea_manage_settings';
        $header_text = __( 'Plugin settings', 'woo-product-feed-pro' );
    } elseif ( $_GET['tab'] == 'woosea_system_check' ) {
        $active_tab  = 'woosea_system_check';
        $header_text = __( 'Plugin systems check', 'woo-product-feed-pro' );
    } else {
        $active_tab  = 'woosea_manage_attributes';
        $header_text = __( 'Attribute settings', 'woo-product-feed-pro' );
    }
}
?>

<div class="wrap">

    <div class="woo-product-feed-pro-form-style-2">

        <tbody class="woo-product-feed-pro-body">
            <div class="woo-product-feed-pro-form-style-2-heading">
                <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a>
                <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
                <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
                <?php endif; ?>
                <h1 class="title"><?php echo esc_html( $header_text ); ?></h1>
            </div>

            <?php
            /**
             * Request our plugin users to write a review
             */
            if ( $total_projects > 0 ) {
                $first_activation         = get_option( 'woosea_first_activation' );
                $notification_interaction = get_option( 'woosea_review_interaction' );
                $current_time             = time();
                $show_after               = 604800; // Show only after one week
                $is_active                = $current_time - $first_activation;
                $page                     = sanitize_text_field( basename( $_SERVER['REQUEST_URI'] ) );

                if ( ( $is_active > $show_after ) && ( $notification_interaction != 'yes' ) ) {
                    echo '<div class="notice notice-info review-notification">';
                    echo '<table><tr><td></td><td><font color="green" style="font-weight:normal";><p>Hey, I noticed you have been using our plugin, <u>Product Feed PRO for WooCommerce by AdTribes.io</u>, for over a week now and have created product feed projects with it - that\'s awesome! Could you please do our support volunteers and me a BIG favor and give it a <strong>5-star rating</strong> on WordPress? Just to help us spread the word and boost our motivation. We would greatly appreciate if you would do so :)<br/>~ Adtribes.io support team<br><ul><li><span class="ui-icon ui-icon-caret-1-e" style="display: inline-block;"></span><a href="https://wordpress.org/support/plugin/woo-product-feed-pro/reviews?rate=5#new-post" target="_blank" class="dismiss-review-notification">Ok, you deserve it</a></li><li><span class="ui-icon ui-icon-caret-1-e" style="display: inline-block;"></span><a href="#" class="dismiss-review-notification">Nope, maybe later</a></li><li><span class="ui-icon ui-icon-caret-1-e" style="display: inline-block;"></span><a href="#" class="dismiss-review-notification">I already did</a></li></ul></p></font></td></tr></table>';
                    echo '</div>';
                }
            }
            ?>

            <!-- WordPress provides the styling for tabs. -->
            <h2 class="nav-tab-wrapper woo-product-feed-pro-nav-tab-wrapper">
                <!-- when tab buttons are clicked we jump back to the same page but with a new parameter that represents the clicked tab. accordingly we make it active -->
                <a href="?page=woosea_manage_settings&tab=woosea_manage_settings" data-tab="general" class="nav-tab <?php echo $active_tab == 'woosea_manage_settings' ? esc_attr( 'nav-tab-active' ) : '';?>">
                    <?php _e( 'Plugin settings', 'woo-product-feed-pro' ); ?>
                </a>
                <a href="?page=woosea_manage_settings&tab=woosea_system_check" data-tab="system_check" class="nav-tab <?php echo $active_tab == 'woosea_system_check' ? esc_attr( 'nav-tab-active' ) : ''; ?>">
                    <?php _e( 'Plugin systems check', 'woo-product-feed-pro' ); ?>
                </a>
            </h2>

            <div class="woo-product-feed-pro-table-wrapper">
                <div class="woo-product-feed-pro-table-left">
                    <?php
                    if ( $active_tab == 'woosea_manage_settings' ) {
                    ?>
                        <table class="woo-product-feed-pro-table woo-product-feed-pro-table--manage-settings" data-pagename="manage_settings">
                            <tr>
                                <td><strong><?php _e( 'Plugin setting', 'woo-product-feed-pro' ); ?></strong></td>
                                <td><strong><?php _e( 'Off / On', 'woo-product-feed-pro' ); ?></strong></td>
                            </tr>

                            <form action="" method="post">
                                <?php wp_nonce_field( 'woosea_ajax_nonce' ); ?>

                                <tr>
                                    <td>
                                        <span><?php _e( 'Use parent variable product image for variations', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_mother_image" name="add_mother_image" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_mother_image' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span><?php _e( 'Add shipping costs for all countries to your feed (Google Shopping / Facebook only)', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_all_shipping" name="add_all_shipping" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_all_shipping' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span><?php _e( 'Remove all other shipping classes when free shipping criteria are met (Google Shopping / Facebook only)', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="free_shipping" name="free_shipping" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'free_shipping' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span><?php _e( 'Remove the free shipping zone from your feed (Google Shopping / Facebook only)', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="remove_free_shipping" name="remove_free_shipping" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'remove_free_shipping' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span><?php _e( 'Remove the local pickup shipping zone from your feed (Google Shopping / Facebook only)', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="local_pickup_shipping" name="local_pickup_shipping" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'local_pickup_shipping' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span><?php _e( 'Show only basis attributes in field mapping and filter/rule drop-downs', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_woosea_basic" name="add_woosea_basic" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_woosea_basic' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span><?php _e( 'Enable logging', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_woosea_logging" name="add_woosea_logging" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_woosea_logging' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr id="facebook_pixel" class="group" data-group="facebook_pixel">
                                    <td>
                                        <span>
                                            <?php _e( 'Add Facebook Pixel', 'woo-product-feed-pro' ); ?> 
                                            <a href="<?php echo esc_url( Helper::get_utm_url( 'facebook-pixel-feature', 'pfp', 'manage-settings', 'fbpixelsetting' ) ); ?>" target="_blank">
                                                (<?php _e( 'Read more about this', 'woo-product-feed-pro' ); ?>)
                                            </a>
                                        </span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_facebook_pixel" name="add_facebook_pixel" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_facebook_pixel' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr id="facebook_pixel_id" class="group-child <?php echo get_option( 'add_facebook_pixel' ) !== 'yes' ? 'hidden' : ''; ?>" data-group="facebook_pixel">
                                    <td colspan="2">
                                        <span><?php _e( 'Insert your Facebook Pixel ID', 'woo-product-feed-pro' ); ?></span>&nbsp;
                                        <input type="text" class="input-field-medium" id="fb_pixel_id" name="woosea_facebook_pixel_id" value="<?php echo get_option( 'woosea_facebook_pixel_id', '' ) ?>">&nbsp;
                                        <input type="button" class="adt-pfp-save-setting-button" id="save_facebook_pixel_id" value="Save">
                                        <p class="error-message hidden"></p>
                                    </td>
                                </tr>
                                <tr id="content_ids">
                                    <td colspan="2">
                                        <span><?php _e( 'Content IDS variable products Facebook Pixel', 'woo-product-feed-pro' ); ?></span>
                                        <?php $content_ids = get_option( 'add_facebook_pixel_content_ids', 'variation' ); ?>
                                        <select id="woosea_content_ids" name="add_facebook_pixel_content_ids" class="select-field adt-pfp-general-setting">
                                            <option value="variation" <?php echo $content_ids == 'variation' ? 'selected' : ''; ?>><?php _e( 'Variation product ID\'s', 'woo-product-feed-pro' ); ?></option>
                                            <option value="variable" <?php echo $content_ids == 'variable' ? 'selected' : ''; ?>><?php _e( 'Variable product ID', 'woo-product-feed-pro' ); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr id="remarketing" class="group" data-group="remarketing">
                                    <td>
                                        <span><?php _e( 'Add Google Dynamic Remarketing Pixel:', 'woo-product-feed-pro' ); ?></span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_remarketing" name="add_remarketing" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_remarketing' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr id="adwords_conversion_id" class="group-child <?php echo get_option( 'add_remarketing' ) !== 'yes' ? 'hidden' : ''; ?>" data-group="remarketing">
                                    <td colspan="2">
                                        <span><?php _e( 'Insert your Dynamic Remarketing Conversion tracking ID:', 'woo-product-feed-pro' ); ?></span>&nbsp;
                                        <input type="text" class="input-field-medium" id="adwords_conv_id" name="woosea_adwords_conversion_id" value="<?php echo get_option( 'woosea_adwords_conversion_id', '' ) ?>">&nbsp;
                                        <input type="button" class="adt-pfp-save-setting-button" id="save_conversion_id" value="Save">
                                        <p class="error-message hidden"></p>
                                    </td>
                                </tr>
                                <tr id="batch" class="group" data-group="batch">
                                    <td>
                                        <span>
                                            <?php _e( 'Change products per batch number', 'woo-product-feed-pro' ); ?> 
                                            <a href="<?php echo esc_url( Helper::get_utm_url( 'batch-size-configuration-product-feed', 'pfp', 'manage-settings', 'batchsizesetting' ) ); ?>" target="_blank">
                                                (<?php _e( 'Read more about this', 'woo-product-feed-pro' ); ?>)
                                            </a>
                                        </span>
                                    </td>
                                    <td>
                                        <label class="woo-product-feed-pro-switch">
                                            <input type="checkbox" id="add_batch" name="add_batch" class="checkbox-field adt-pfp-general-setting" <?php echo get_option( 'add_batch' ) == 'yes' ? 'checked' : ''; ?>>
                                            <div class="woo-product-feed-pro-slider round"></div>
                                        </label>
                                    </td>
                                </tr>
                                <tr id="woosea_batch_size" class="group-child <?php echo get_option( 'add_batch' ) !== 'yes' ? 'hidden' : ''; ?>" data-group="batch">
                                    <td colspan="2">
                                        <span><?php _e( 'Insert batch size:', 'woo-product-feed-pro' ); ?></span>&nbsp;
                                        <input type="text" class="input-field-medium" id="batch_size" name="woosea_batch_size" value="<?php echo get_option( 'woosea_batch_size', '' ) ?>">&nbsp;
                                        <input type="button" class="adt-pfp-save-setting-button" id="save_batch_size" value="Save">
                                        <p class="error-message hidden"></p>
                                    </td>
                                </tr>
                            </form>
                        </table>
                        <?php do_action( 'adt_after_manage_settings_table' ); ?>
                    <?php
                    } elseif ( $active_tab == 'woosea_system_check' ) {
                        // Check if the product feed directory is writeable
                        $upload_dir         = wp_upload_dir();
                        $external_base      = $upload_dir['basedir'];
                        $external_path      = $external_base . '/woo-product-feed-pro/';
                        $external_path_xml  = $external_base . '/woo-product-feed-pro/';
                        $external_path_csv  = $external_base . '/woo-product-feed-pro/';
                        $external_path_txt  = $external_base . '/woo-product-feed-pro/';
                        $external_path_tsv  = $external_base . '/woo-product-feed-pro/';
                        $external_path_logs = $external_base . '/woo-product-feed-pro/';
                        $test_file          = $external_path . '/tesfile.txt';
                        $test_file_xml      = $external_path . 'xml/tesfile.txt';
                        $test_file_csv      = $external_path . 'csv/tesfile.txt';
                        $test_file_txt      = $external_path . 'txt/tesfile.txt';
                        $test_file_tsv      = $external_path . 'tsv/tesfile.txt';
                        $test_file_logs     = $external_path . 'logs/tesfile.txt';

                        if ( is_writable( $external_path ) ) {
                            // Normal root category
                            $fp = @fopen( $test_file, 'w' );
                            @fwrite( $fp, 'Cats chase mice' );
                            @fclose( $fp );
                            if ( is_file( $test_file ) ) {
                                $directory_perm = 'True';
                            }

                            // XML subcategory
                            $fp = @fopen( $test_file_xml, 'w' );
                            if ( ! is_bool( $fp ) ) {
                                @fwrite( $fp, 'Cats chase mice' );
                                @fclose( $fp );
                                if ( is_file( $test_file_xml ) ) {
                                    $directory_perm_xml = 'True';
                                } else {
                                    $directory_perm_xml = 'False';
                                }
                            } else {
                                $directory_perm_xml = 'Unknown';
                            }

                            // CSV subcategory
                            $fp = @fopen( $test_file_csv, 'w' );
                            if ( ! is_bool( $fp ) ) {
                                @fwrite( $fp, 'Cats chase mice' );
                                @fclose( $fp );
                                if ( is_file( $test_file_csv ) ) {
                                    $directory_perm_csv = 'True';
                                } else {
                                    $directory_perm_csv = 'False';
                                }
                            } else {
                                $directory_perm_csv = 'Unknown';
                            }

                            // TXT subcategory
                            $fp = @fopen( $test_file_txt, 'w' );
                            if ( ! is_bool( $fp ) ) {
                                @fwrite( $fp, 'Cats chase mice' );
                                @fclose( $fp );
                                if ( is_file( $test_file_txt ) ) {
                                    $directory_perm_txt = 'True';
                                } else {
                                    $directory_perm_txt = 'False';
                                }
                            } else {
                                $directory_perm_txt = 'Unknown';
                            }
                            // TSV subcategory
                            $fp = @fopen( $test_file_tsv, 'w' );
                            if ( ! is_bool( $fp ) ) {
                                @fwrite( $fp, 'Cats chase mice' );
                                @fclose( $fp );
                                if ( is_file( $test_file_tsv ) ) {
                                    $directory_perm_tsv = 'True';
                                } else {
                                    $directory_perm_tsv = 'False';
                                }
                            } else {
                                $directory_perm_tsv = 'Uknown';
                            }

                            // Logs subcategory
                            $fp = @fopen( $test_file_logs, 'w' );
                            if ( ! is_bool( $fp ) ) {
                                @fwrite( $fp, 'Cats chase mice' );
                                @fclose( $fp );
                                if ( is_file( $test_file_logs ) ) {
                                    $directory_perm_logs = 'True';
                                } else {
                                    $directory_perm_logs = 'False';
                                }
                            } else {
                                $directory_perm_logs = 'Unknown';
                            }
                        } else {
                            $directory_perm = 'False';
                        }

                        // Check if the cron is enabled
                        $cron_enabled = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'False' : 'True';

                        print '<table class="woo-product-feed-pro-table">';
                        print '<tr><td><strong>System check</strong></td><td><strong>Status</strong></td></tr>';
                        echo "<tr><td>WP-Cron enabled</td><td><strong>$cron_enabled</strong></td></tr>";
                        echo "<tr><td>PHP-version</td><td>($versions[PHP])</td></tr>";
                        echo "<tr><td>Product feed directory writable</td><td>$directory_perm</td></tr>";
                        echo "<tr><td>Product feed XML directory writable</td><td>$directory_perm_xml</td></tr>";
                        echo "<tr><td>Product feed CSV directory writable</td><td>$directory_perm_csv</td></tr>";
                        echo "<tr><td>Product feed TXT directory writable</td><td>$directory_perm_txt</td></tr>";
                        echo "<tr><td>Product feed TSV directory writable</td><td>$directory_perm_tsv</td></tr>";
                        echo "<tr><td>Product feed LOGS directory writable</td><td>$directory_perm_logs</td></tr>";
                        print '<tr><td colspan="2">&nbsp;</td></tr>';
                        print '</table>';

                        // Display the debugging information.
                        $notifications_obj  = new \WooSEA_Get_Admin_Notifications();
                        $debug_info_content = $notifications_obj->woosea_debug_informations( $versions, $product_numbers, $order_rows );
                        $debug_info_title   = __( 'System Report', 'woo-product-feed-pro' );

                        print '<div class="woo-product-feed-pro-debug-info">';
                        print '<button class="button copy-product-feed-pro-debug-info" type="button" data-clipboard-target="#woo-product-feed-pro-debug-info">Copy to clipboard</button>';
                        echo "<h3>{$debug_info_title}</h3>";
                        print '<p>' . __( 'Copy the below text and paste to the support team when requested to help us debug any systems issues with your feeds.', 'woo-product-feed-pro' ) . '</p>';
                        echo "<pre id=\"woo-product-feed-pro-debug-info\">{$debug_info_content}</pre>";
                        print '</div>';
                    }
                    ?>
                </div>
                <?php require_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'view-sidebar.php'; ?>
            </div>
        </tbody>
    </div>
</div>
