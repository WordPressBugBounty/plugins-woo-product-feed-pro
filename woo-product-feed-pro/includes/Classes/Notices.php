<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Factories\Vite_App;

/**
 * Notices class.
 *
 * @since 13.4.5
 */
class Notices extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Property that houses all admin notices data.
     *
     * @since 13.4.5
     * @access private
     * @var array
     */
    private $_notices = array();

    /**
     * Schedule all notice crons.
     *
     * @since 13.4.5
     * @access public
     */
    public function schedule_cron_notices() {
        $notices = $this->_get_cron_notices();

        foreach ( $notices as $key => $notice ) {
            $this->_schedule_single_notice_cron( $key, $notice['option'], $notice['days'] );
        }
    }

    /**
     * Get notices that needs to be scheduled via cron.
     *
     * @since 13.4.5
     * @access private
     */
    private function _get_cron_notices() {
        return apply_filters(
            'adt_pfp_cron_notices_data',
            array(
                'review_request' => array(
                    'option' => 'adt_pfp_show_review_request_notice',
                    'days'   => 10,
                ),
            )
        );
    }

    /**
     * Schedule a single notice cron.
     *
     * @since 1.2
     * @access private
     *
     * @param string $key    Notice key.
     * @param string $option Notice option.
     * @param int    $days   Number of days delay.
     */
    private function _schedule_single_notice_cron( $key, $option, $days ) {
        // Backwards compatibility for old cron notices.
        // If the user has already interacted with the notice, we don't need to show it again.
        // Legacy option: "woosea_review_interaction".
        if ( 'review_request' === $key && 'yes' === get_option( 'woosea_review_interaction' ) ) {
            $this->update_notice_option( $key, 'dismissed' );
            return;
        }

        if ( wp_next_scheduled( 'adt_pfp_cron_notices', array( $key ) ) || get_option( $option, 'snooze' ) !== 'snooze' ) {
            return;
        }

        wp_schedule_single_event( time() + ( DAY_IN_SECONDS * $days ), 'adt_pfp_cron_notices', array( $key ) );
    }

    /**
     * Trigger cron notices.
     *
     * @since 13.4.5
     * @access public
     *
     * @param string $key Notice key.
     */
    public function trigger_cron_notices( $key ) {
        $notices = $this->_get_cron_notices();
        $notice  = isset( $notices[ $key ] ) ? $notices[ $key ] : array();

        if ( ! isset( $notice['option'] ) || get_option( $notice['option'] ) === 'dismissed' ) {
            return;
        }

        update_option( $notice['option'], 'yes' );
    }

    /**
     * Enqueue admin notice styles and scripts.
     *
     * @since 13.4.5
     * @access public
     */
    public function enqueue_admin_notice_scripts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_pfp_screen = Helper::is_plugin_page();

        foreach ( $this->get_all_admin_notice_options() as $notice_key => $notice_option ) {
            $notice_data = $this->_notices[ $notice_key ] ?? array();

            // enqueue scripts only on eligible screens.
            if ( ! $is_pfp_screen && ! ( isset( $notice_data['show_admin_wide'] ) && $notice_data['show_admin_wide'] ) ) {
                continue;
            }

            if ( get_option( $notice_option ) !== 'yes' ) {
                continue;
            }

            $vite = new Vite_App(
                'adt-pfp-notices',
                'src/vanilla/notices/index.ts',
                array( 'jquery', 'wp-i18n' ),
            );
            $vite->enqueue();
            break;
        }
    }

    /**
     * Display notices.
     *
     * @since 13.4.5
     * @access public
     */
    public function display_notices() {
        // only run when current user is atleast an administrator.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_pfp_screen = Helper::is_plugin_page();

        // initialize notices.
        $this->get_all_admin_notices();

        foreach ( $this->get_all_admin_notice_options() as $notice_key => $notice_option ) {

            $notice_data = $this->_notices[ $notice_key ] ?? array();

            // display only on eligible screens.
            if ( ! $is_pfp_screen && ! ( isset( $notice_data['show_admin_wide'] ) && $notice_data['show_admin_wide'] ) ) {
                continue;
            }

            if ( ! $notice_option || get_option( $notice_option ) !== 'yes' ) {
                continue;
            }

            $this->print_admin_notice_content( $notice_key, $notice_option );
        }
    }

    /**
     * Get all admin notices.
     *
     * @since 13.4.5
     * @access public
     *
     * @return array List of all admin notices data.
     */
    public function get_all_admin_notices() {
        // skip if notices are already loaded.
        if ( ! empty( $this->_notices ) ) {
            return apply_filters( 'adt_pfp_get_all_admin_notices', $this->_notices );
        }

        foreach ( $this->get_all_admin_notice_options() as $notice_key => $notice_option ) {

            // skip if notice is already dismissed.
            if ( ! $notice_option || get_option( $notice_option ) !== 'yes' ) {
                continue;
            }

            switch ( $notice_key ) {

                case 'review_request':
                    $this->_notices['review_request'] = $this->_get_review_request_notice_data();
                    break;

                default:
                    $temp = apply_filters( 'adt_pfp_get_admin_notice_data', null, $notice_key );

                    if ( is_array( $temp ) && ! empty( $temp ) ) {
                        $this->_notices[ $notice_key ] = $temp;
                    }
                    break;
            }

            // add notice security nonce value.
            if ( isset( $this->_notices[ $notice_key ] ) ) {
                $this->_notices[ $notice_key ]['nonce'] = wp_create_nonce( 'adt_pfp_dismiss_notice_' . $notice_key );
            }
        }

        return apply_filters( 'adt_pfp_get_all_admin_notices', array_filter( $this->_notices ) );
    }

    /**
     * Get review request notice data .
     *
     * @since 13.4.5
     * @access private
     * @return array Review request notice data .
     */
    private function _get_review_request_notice_data() {
        return array(
            'slug'           => 'review_request',
            'id'             => 'adt_pfp_show_review_request_notice',
            'is_dismissable' => true,
            'type'           => 'info',
            'heading'        => '',
            'content'        => array(
                "Hey, I noticed you have been using <strong>Product Feed PRO for WooCommerce</strong> for some time - that's awesome! Could you please do me a BIG favor and give it a 5-star rating on WordPress to help us spread the word and boost our motivation?",
            ),
            'actions'        => array(
                array(
                    'key'         => 'primary',
                    'link'        => 'https://wordpress.org/support/plugin/woo-product-feed-pro/reviews/?filter=5#new-post',
                    'is_external' => true,
                    'text'        => __( 'Ok, you deserve it', 'woo-product-feed-pro' ),
                ),
                array(
                    'key'      => 'snooze',
                    'response' => 'snooze',
                    'link'     => '',
                    'text'     => __( 'Nope, maybe later', 'woo-product-feed-pro' ),
                ),
                array(
                    'key'      => 'dismissed',
                    'response' => 'dismissed',
                    'link'     => '',
                    'text'     => __( 'I already did', 'woo-product-feed-pro' ),
                ),
            ),
        );
    }

    /**
     * Get all PFP admin notice options.
     *
     * @since 13.4.5
     * @access public
     *
     * @return array List of PFP admin notice options.
     */
    public function get_all_admin_notice_options() {
        return apply_filters(
            'adt_pfp_admin_notice_option_names',
            array(
                'review_request' => 'adt_pfp_show_review_request_notice',
            )
        );
    }

    /**
     * Display upgrade notice.
     *
     * @since 13.4.5
     * @access public
     *
     * @param string $notice_key    Notice key.
     * @param string $notice_option Notice show option name.
     * @param bool   $on_settings   Toggle if showing on settings page or not.
     */
    public function print_admin_notice_content( $notice_key, $notice_option, $on_settings = false ) {
        $notice_class  = $on_settings ? 'adt-pfp-settings-notice' : 'notice';
        $notice_class .= sprintf( ' adt-pfp-%s-notice', str_replace( '_', '-', $notice_key ) );

        if ( isset( $this->get_all_admin_notices()[ $notice_key ] ) ) {

            $notice = $this->get_all_admin_notices()[ $notice_key ] ?? null;

            // don't display notice when data is not set.
            if ( ! $notice ) {
                return;
            }

            // display custom view file for review request notice.
            if ( 'review_request' === $notice_key ) {
                Helper::locate_admin_template(
                    'notices/review-request.php',
                    true,
                    true,
                    array(
                        'notice'       => $notice,
                        'notice_class' => $notice_class,
                    )
                );
                return;
            }

            // display default notice.
            Helper::locate_admin_template(
                'notices/admin-notice.php',
                true,
                true,
                array(
                    'notice'       => $notice,
                    'notice_class' => $notice_class,
                )
            );
        }
    }

    /**
     * Update notice option.
     *
     * @since 13.4.5
     * @access private
     *
     * @param string $notice_key Notice key.
     * @param string $value      Option value.
     */
    public function update_notice_option( $notice_key, $value ) {
        $notice_options = $this->get_all_admin_notice_options();
        $option         = isset( $notice_options[ $notice_key ] ) ? $notice_options[ $notice_key ] : null;

        if ( ! $option ) {
            return;
        }

        update_option( $option, $value );

        do_action( 'adt_pfp_notice_updated', $notice_key, $value, $option );
    }

    /**
     * Reschedule a single notice cron based when snoozed.
     *
     * @since 1.2
     * @access public
     *
     * @param string $key   Notice key.
     * @param string $value Option value.
     */
    public function reschedule_notice_cron( $key, $value ) {
        if ( 'snooze' !== $value ) {
            return;
        }

        $notices = $this->_get_cron_notices();
        $notice  = isset( $notices[ $key ] ) ? $notices[ $key ] : array();

        // unschedule cron if present.
        $timestamp = wp_next_scheduled( 'adt_pfp_cron_notices', array( $key ) );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'adt_pfp_cron_notices', array( $key ) );
        }

        $this->_schedule_single_notice_cron( $key, $notice['option'], $notice['days'] );
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX methods
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX dismiss admin notice.
     *
     * @since 13.4.5
     * @access public
     */
    public function ajax_dismiss_admin_notice() {
        $notice_key = isset( $_REQUEST['notice'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['notice'] ) ) : '';

        if ( defined( 'DOING_AJAX' )
            && DOING_AJAX
            && current_user_can( 'manage_options' )
            && $notice_key
            && isset( $_REQUEST['nonce'] )
            && wp_verify_nonce( sanitize_key( $_REQUEST['nonce'] ), 'adt_pfp_dismiss_notice_' . $notice_key )
        ) {
            $response = isset( $_REQUEST['response'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['response'] ) ) : '';

            do_action( 'adt_pfp_before_dismiss_admin_notice', $notice_key, $response );

            $response = 'snooze' === $response ? 'snooze' : 'dismissed';
            $this->update_notice_option( $notice_key, $response );
        }

        wp_die();
    }

    /**
     * Temporary fix for Elite users.
     * Remove the action that shows the old review request notice. We will remove on the Elite side.
     * But, meantime, we need to remove it from the Free version.
     *
     * @since 13.4.5
     * @access public
     */
    public function remove_review_request_notice() {
        remove_action( 'admin_notices', 'woosea_elite_request_review', 10 );
    }

    /**
     * Run the class
     *
     * @since 13.4.5
     */
    public function run() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_notice_scripts' ) );

        // Trigger cron notices.
        add_action( 'adt_pfp_cron_notices', array( $this, 'trigger_cron_notices' ) );

        add_action( 'admin_notices', array( $this, 'display_notices' ) );

        add_action( 'wp_ajax_adt_pfp_dismiss_admin_notice', array( $this, 'ajax_dismiss_admin_notice' ) );
        add_action( 'adt_pfp_notice_updated', array( $this, 'reschedule_notice_cron' ), 10, 2 );

        if ( Helper::has_paid_plugin_active() ) {
            add_action( 'admin_init', array( $this, 'remove_review_request_notice' ), 10 );
        }
    }
}
