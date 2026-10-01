<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\Classes\UCP
 */

namespace AdTribes\PFP\Classes\UCP;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Classes\Admin_Pages\UCP_Page;
use AdTribes\PFP\Traits\Singleton_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Low-balance admin notice for the prepaid AI-checkout credit balance.
 *
 * Keys off the `balance_status` / `channel` fields the server reports on
 * `/v1/account/status` (contract 1.0.0-rc.2): `low` warns before the
 * balance runs out, `grace` means the balance is negative and
 * verification stays live only for the grace window. Reads only the
 * cached status snapshot — an admin notice must never block on a remote
 * call; the cache refreshes whenever the AI Discovery page loads.
 *
 * @since 13.6.0
 */
class Account_Notices extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Render the low-balance notice when the cached status warrants one.
     *
     * @since 13.6.0
     * @access public
     */
    public function render_balance_notice() {

        /** This filter is documented in includes/REST/UCP/Admin.php */
        if ( ! current_user_can( apply_filters( 'adt_pfp_admin_capability', 'manage_options' ) ) ) {
            return;
        }

        // The AI Discovery page shows the live balance itself.
        if ( isset( $_GET['page'] ) && UCP_Page::MENU_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display routing only.
            return;
        }

        if ( ! Account_Client::instance()->is_connected() ) {
            return;
        }

        $status         = Account_Client::instance()->get_cached_status();
        $balance_status = (string) ( $status['balance_status'] ?? '' );
        $channel        = (string) ( $status['channel'] ?? '' );

        if ( 'grace' === $balance_status || 'grace' === $channel ) {
            $message = __( 'Your AdTribes AI-checkout credit balance is negative. Top up now to keep agentic checkout active — verification stops when the grace window ends.', 'woo-product-feed-pro' );
            $class   = 'notice-error';
        } elseif ( 'low' === $balance_status ) {
            $message = __( 'Your AdTribes AI-checkout credit balance is running low. Top up to avoid interrupting agentic checkout.', 'woo-product-feed-pro' );
            $class   = 'notice-warning';
        } else {
            return;
        }

        printf(
            '<div class="notice %1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
            esc_attr( $class ),
            esc_html( $message ),
            esc_url( Account_Connect::get_page_url() ),
            esc_html__( 'Open AI Discovery', 'woo-product-feed-pro' )
        );
    }

    /**
     * Run the class.
     *
     * @since 13.6.0
     * @access public
     */
    public function run() {

        add_action( 'admin_notices', array( $this, 'render_balance_notice' ) );
    }
}
