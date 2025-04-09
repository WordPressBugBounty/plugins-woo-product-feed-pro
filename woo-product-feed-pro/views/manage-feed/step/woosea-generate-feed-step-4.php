<?php
// phpcs:disable
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Factories\Admin_Notice;
use AdTribes\PFP\Classes\Product_Feed_Admin;
use AdTribes\PFP\Classes\Product_Feed_Attributes;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Classes\Filters;
use AdTribes\PFP\Classes\Rules;

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

// Instantiate the classes.
$filters_instance = new Filters();
$rules_instance   = new Rules();

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
                <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank"><img class="logo" src="<?php echo esc_attr( WOOCOMMERCESEA_PLUGIN_URL . '/images/adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>"></a> 
                <?php if ( Helper::is_show_logo_upgrade_button() ) : ?>
                <a href="<?php echo esc_url( Helper::get_utm_url( '', 'pfp', 'logo', 'adminpagelogo' ) ); ?>" target="_blank" class="logo-upgrade">Upgrade to Elite</a>
                <?php endif; ?>
                <h1 class="title"><?php esc_html_e( 'Feed filters and rules', 'woo-product-feed-pro' ); ?></h1>
            </div>

            <?php
            // Display info message notice.
            ob_start();
            include_once WOOCOMMERCESEA_VIEWS_ROOT_PATH . 'notices/view-feed-filter-rule-notice.php';
            $message = ob_get_clean();

            $admin_notice = new Admin_Notice(
                $message,
                'info',
                'html',
                false
            );
            $admin_notice->run();
            ?>

            <form id="rulesandfilters" method="post">
            <input type="hidden" id="feed_id" name="feed_id" value="<?php echo esc_attr( $feed->id ?? 0 ); ?>">
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
      
                <tbody class="woo-product-feed-pro-body">
                <?php
                // FILTERS SECTION
                if ( isset( $feed_filters ) && is_array( $feed_filters ) ) {
                    foreach ( $feed_filters as $rule_key => $filter_data ) {
                        // Use the template method to generate the filter row HTML
                        echo $filters_instance->get_filter_template( $rule_key, $attributes, $filter_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                }

                // RULES SECTION
                if ( isset( $feed_rules ) && is_array( $feed_rules ) ) {
                    foreach ( $feed_rules as $rule2_key => $rule_data ) {
                        // Use the template method to generate the rule row HTML
                        echo $rules_instance->get_rule_template( $rule2_key, $attributes, $rule_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                }
                ?>
                </tbody>
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
