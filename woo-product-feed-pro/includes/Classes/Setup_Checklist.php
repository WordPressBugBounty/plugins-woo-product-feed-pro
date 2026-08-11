<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Factories\Product_Feed_Query;
use AdTribes\PFP\Classes\Plugin_Installer;

/**
 * Post-install "Getting Started" setup checklist for the Manage Feeds page.
 *
 * Renders a dismissible onboarding card at the top of Manage Feeds that guides
 * a new user through the first things to do after installing Product Feed PRO.
 * Most steps auto-complete from real plugin state; the rest are one-click
 * actions the user can take directly from the card.
 *
 * @since 13.6.0
 */
class Setup_Checklist extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Option: whether this site is eligible to see the checklist at all
     * ('yes'|'no'). Set on activation for fresh installs only; existing sites
     * updating to 13.6.0 never gain this option and stay ineligible.
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_ELIGIBLE = 'adt_pfp_setup_checklist_eligible';

    /**
     * Option: whether the user has dismissed the checklist ('yes'|'no').
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_DISMISSED = 'adt_pfp_setup_checklist_dismissed';

    /**
     * Option: whether the user has confirmed submitting a feed ('yes'|'no').
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_SUBMIT_DONE = 'adt_pfp_setup_checklist_submit_done';

    /**
     * Option: whether all essentials have been completed and the celebration
     * shown, after which the card must not reappear ('yes'|'no').
     *
     * @since 13.6.0
     * @var string
     */
    const OPTION_COMPLETED = 'adt_pfp_setup_checklist_completed';

    /**
     * Nonce action used by the checklist AJAX endpoint.
     *
     * @since 13.6.0
     * @var string
     */
    const NONCE_ACTION = 'adt_nonce';

    /**
     * Memoized result of checklist_enabled_for_user() for this request.
     *
     * @since 13.6.0
     * @var bool|null
     */
    private $enabled_for_user = null;

    /**
     * Sister plugins offered by the optional "Add a free sister plugin" step.
     *
     * Every slug here is already allow-listed in Plugin_Installer, so the
     * one-click install reuses the existing installer wholesale.
     *
     * @since 13.6.0
     * @return array
     */
    private function get_sister_plugins() {
        $plugins = array(
            'saveto-wishlist-lite-for-woocommerce'  => array(
                'name'    => __( 'SaveTo Wishlist Lite', 'woo-product-feed-pro' ),
                'tagline' => __( 'Wishlists that bring shoppers back to buy', 'woo-product-feed-pro' ),
            ),
            'storeagent-ai-for-woocommerce'         => array(
                'name'    => __( 'StoreAgent AI', 'woo-product-feed-pro' ),
                'tagline' => __( 'AI chatbot that answers product questions', 'woo-product-feed-pro' ),
            ),
            'advanced-coupons-for-woocommerce-free' => array(
                'name'    => __( 'Advanced Coupons', 'woo-product-feed-pro' ),
                'tagline' => __( 'BOGO deals, store credit & smart coupons', 'woo-product-feed-pro' ),
            ),
        );

        /**
         * Filter the sister plugins offered by the setup checklist.
         *
         * @since 13.6.0
         * @param array $plugins Keyed by wp.org slug. Each slug must be allow-listed in Plugin_Installer.
         */
        return apply_filters( 'adt_pfp_setup_checklist_sister_plugins', $plugins );
    }

    /**
     * Whether the checklist is enabled at all for the current user, ignoring
     * dismissal state. Shared by is_visible() and is_restorable() so the
     * capability/eligibility/completed/filter checks are only ever written
     * once.
     *
     * @since 13.6.0
     * @return bool
     */
    private function checklist_enabled_for_user() {
        // Memoized: a single Manage Feeds render asks three times over
        // is_visible()/is_restorable(), and re-running the capability check and
        // the two filters each time is wasted work.
        if ( null !== $this->enabled_for_user ) {
            return $this->enabled_for_user;
        }

        $this->enabled_for_user = $this->compute_checklist_enabled_for_user();
        return $this->enabled_for_user;
    }

    /**
     * Uncached body of checklist_enabled_for_user().
     *
     * @since 13.6.0
     * @return bool
     */
    private function compute_checklist_enabled_for_user() {
        // Only for users allowed to manage the feeds pages.
        if ( ! current_user_can( apply_filters( 'adt_pfp_admin_capability', 'manage_options' ) ) ) {
            return false;
        }

        /**
         * Filter whether this site is eligible for the onboarding checklist.
         *
         * Only fresh installs opt in by default; return true to show it on an
         * existing site (e.g. for support or QA).
         *
         * @since 13.6.0
         * @param bool $eligible Whether the site opted in on activation.
         */
        if ( ! apply_filters( 'adt_pfp_setup_checklist_eligible', 'yes' === get_option( self::OPTION_ELIGIBLE, 'no' ) ) ) {
            return false;
        }

        // Permanently hidden once all essentials were completed.
        if ( 'yes' === get_option( self::OPTION_COMPLETED, 'no' ) ) {
            return false;
        }

        /**
         * Filter whether the setup checklist is shown at all.
         *
         * Return false to hide the onboarding checklist on sites where it is
         * not wanted (e.g. managed/white-label installs).
         *
         * @since 13.6.0
         * @param bool $show Whether to show the checklist.
         */
        return (bool) apply_filters( 'adt_pfp_show_setup_checklist', true );
    }

    /**
     * Whether the checklist should be shown for the current request.
     *
     * @since 13.6.0
     * @return bool
     */
    public function is_visible() {
        return $this->checklist_enabled_for_user() && 'yes' !== get_option( self::OPTION_DISMISSED, 'no' );
    }

    /**
     * Whether WooCommerce is active.
     *
     * @since 13.6.0
     * @return bool
     */
    private function wc_active() {
        return function_exists( 'WC' ) && defined( 'WC_VERSION' );
    }

    /**
     * Whether the environment checks pass.
     *
     * WooCommerce active and PHP/WP/WooCommerce all meet the declared minimums.
     *
     * @since 13.6.0
     * @return bool
     */
    private function environment_ok() {
        if ( ! $this->wc_active() ) {
            return false;
        }

        /**
         * Filter the minimum environment versions used by the checklist env step.
         *
         * @since 13.6.0
         * @param array $minimums Associative array with 'php', 'wp' and 'wc' keys.
         */
        $minimums = apply_filters(
            'adt_pfp_setup_checklist_min_versions',
            array(
                'php' => '7.4',
                'wp'  => '5.9',
                'wc'  => '4.4',
            )
        );

        return version_compare( PHP_VERSION, $minimums['php'], '>=' )
            && version_compare( get_bloginfo( 'version' ), $minimums['wp'], '>=' )
            && version_compare( WC_VERSION, $minimums['wc'], '>=' );
    }

    /**
     * Whether WP-Cron is disabled on this site.
     *
     * Informational only: recurring feed generation runs on Action Scheduler,
     * so a disabled WP-Cron does not break anything.
     *
     * @since 13.6.0
     * @return bool
     */
    private function is_wp_cron_disabled() {
        return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    }

    /**
     * Get the file URL of the most recent published feed, if any.
     *
     * @since 13.6.0
     * @return string Empty string when there is no published feed.
     */
    private function get_first_feed_url() {
        $query = new Product_Feed_Query(
            array(
                'post_status'    => array( 'publish' ),
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ),
            'edit'
        );

        foreach ( $query->get_posts() as $feed ) {
            if ( ! $feed instanceof Product_Feed ) {
                continue;
            }
            return $feed->get_file_url();
        }

        return '';
    }

    /**
     * Whether at least one of the sister plugins is installed and active.
     *
     * @since 13.6.0
     * @return bool
     */
    private function any_sister_active() {
        $installer = Plugin_Installer::instance();
        foreach ( array_keys( $this->get_sister_plugins() ) as $slug ) {
            if ( Helper::is_plugin_active( $installer->get_plugin_basename_by_slug( $slug ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the checklist steps with their computed state.
     *
     * @since 13.6.0
     * @return array
     */
    public function get_steps() {
        $steps = array();

        // 1. Environment checks (essential, auto-detected).
        $wc_active   = $this->wc_active();
        $env_ok      = $this->environment_ok();
        $env_subline = $wc_active
            ? sprintf(
                /* translators: 1: PHP version, 2: WordPress version, 3: WooCommerce version. */
                __( 'WooCommerce active · PHP %1$s · WP %2$s · WooCommerce %3$s', 'woo-product-feed-pro' ),
                PHP_VERSION,
                get_bloginfo( 'version' ),
                WC_VERSION
            )
            : __( 'WooCommerce is not active — activate it to generate feeds', 'woo-product-feed-pro' );
        $steps['env'] = array(
            'title'        => $env_ok
                ? __( 'Your store passed all environment checks', 'woo-product-feed-pro' )
                : __( 'Check your store environment', 'woo-product-feed-pro' ),
            'sub'          => $env_subline,
            'done'         => $env_ok,
            'optional'     => false,
            'warning_chip' => $this->is_wp_cron_disabled(),
        );

        // 2. Create your first feed (essential, auto-detected).
        $feed_count    = (int) Product_Feed_Helper::get_total_product_feed();
        $steps['feed'] = array(
            'title'      => __( 'Create your first feed', 'woo-product-feed-pro' ),
            'sub'        => $feed_count > 0
                ? sprintf(
                    /* translators: %d: number of feeds detected. */
                    _n( '%d feed detected — nothing to do here', '%d feeds detected — nothing to do here', $feed_count, 'woo-product-feed-pro' ),
                    $feed_count
                )
                : __( 'No feeds yet — create your first feed to get started', 'woo-product-feed-pro' ),
            'done'       => $feed_count > 0,
            'optional'   => false,
            'plain_done' => $feed_count > 0,
        );

        // 3. Submit a feed to a marketing channel (essential, user action).
        $submit_done     = 'yes' === get_option( self::OPTION_SUBMIT_DONE, 'no' );
        $feed_url        = $this->get_first_feed_url();
        $steps['submit'] = array(
            'title'     => __( 'Submit a feed to a marketing channel', 'woo-product-feed-pro' ),
            'sub'       => ( $feed_url || $submit_done )
                ? __( 'Copy your feed URL and add it as a data source', 'woo-product-feed-pro' )
                : ( $feed_count > 0
                    ? __( 'Publish a feed first — draft feeds have no public URL yet', 'woo-product-feed-pro' )
                    : __( 'Create and publish a feed first, then submit its URL', 'woo-product-feed-pro' ) ),
            'done'      => $submit_done,
            'optional'  => false,
            'show_copy' => ! $submit_done,
            'feed_url'  => $feed_url,
        );

        // 4. Add a free sister plugin (optional).
        $installer = Plugin_Installer::instance();
        $plugins   = array();
        foreach ( $this->get_sister_plugins() as $slug => $data ) {
            $installed = Helper::is_plugin_active( $installer->get_plugin_basename_by_slug( $slug ) );

            // Icon served from the WordPress.org plugin directory CDN (same
            // source WordPress core uses on the plugin-install screen), so the
            // logo stays current and no third-party art ships in the plugin.
            $icon = isset( $data['icon'] )
                ? $data['icon']
                : 'https://ps.w.org/' . rawurlencode( $slug ) . '/assets/icon-256x256.png';

            $plugins[] = array(
                'slug'      => $slug,
                'name'      => $data['name'],
                'tagline'   => $data['tagline'],
                'icon'      => $icon,
                'installed' => $installed,
            );
        }
        $steps['sister'] = array(
            'title'    => __( 'Add a free sister plugin', 'woo-product-feed-pro' ),
            'sub'      => __( 'Pick one — we install & activate it for you in one click', 'woo-product-feed-pro' ),
            'done'     => $this->any_sister_active(),
            'optional' => true,
            'plugins'  => $plugins,
        );

        // 5. Upgrade to unlock Elite (optional) — hidden entirely when Elite is active.
        if ( ! Helper::has_paid_plugin_active() ) {
            $steps['upgrade'] = array(
                'title'        => __( 'Upgrade to unlock Elite features & addons', 'woo-product-feed-pro' ),
                'sub'          => __( 'Multi-currency, translations, priority support', 'woo-product-feed-pro' ),
                'done'         => false,
                'optional'     => true,
                'show_upgrade' => true,
                'upgrade_url'  => Helper::get_utm_url( 'pricing', 'pfp', 'setup-checklist', 'upgrade to elite' ),
            );
        }

        /**
         * Filter the setup checklist steps.
         *
         * Lets Elite and add-ons add or complete steps.
         *
         * @since 13.6.0
         * @param array $steps The checklist steps keyed by step id.
         */
        return apply_filters( 'adt_pfp_setup_checklist_steps', $steps );
    }

    /**
     * Get the essential-step progress for the current state.
     *
     * @since 13.6.0
     * @param array $steps Steps from get_steps().
     * @return array{done:int,total:int,all_done:bool,pct:int}
     */
    public function get_progress( $steps ) {
        $essential = array_filter(
            $steps,
            static function ( $step ) {
                return empty( $step['optional'] );
            }
        );

        $total = count( $essential );
        $done  = count(
            array_filter(
                $essential,
                static function ( $step ) {
                    return ! empty( $step['done'] );
                }
            )
        );

        return array(
            'done'     => $done,
            'total'    => $total,
            'all_done' => $total > 0 && $done === $total,
            'pct'      => $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0,
        );
    }

    /**
     * Render the checklist card above the Manage Feeds page title.
     *
     * @since 13.6.0
     * @return void
     */
    public function render() {
        if ( ! $this->is_visible() ) {
            return;
        }

        $steps    = $this->get_steps();
        $progress = $this->get_progress( $steps );

        // Header mascot progresses with the essential steps: just starting
        // shows the idea pose, halfway shows the feeds pose, and all done shows
        // the celebrate pose. The front-end swaps it live as steps complete.
        $mascots = $this->get_mascot_urls();
        if ( $progress['all_done'] ) {
            $mascot_url = $mascots['celebrate'];
        } elseif ( $progress['pct'] >= 50 ) {
            $mascot_url = $mascots['feeds'];
        } else {
            $mascot_url = $mascots['idea'];
        }

        Helper::locate_admin_template(
            'components/setup-checklist.php',
            true,
            false,
            array(
                'steps'      => $steps,
                'progress'   => $progress,
                'mascot_url' => $mascot_url,
                'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
            )
        );

        // Any route to "all essentials done" — env fixed later, a filtered
        // step, or submit persisted out of band — must also retire the card
        // after one showing, not just the common path through ajax_update().
        if ( $progress['all_done'] ) {
            update_option( self::OPTION_COMPLETED, 'yes', false );
        }
    }

    /**
     * Whether the "Show setup checklist" restore button should be offered.
     *
     * True when the checklist was dismissed but not permanently completed.
     *
     * @since 13.6.0
     * @return bool
     */
    public function is_restorable() {
        return $this->checklist_enabled_for_user() && 'yes' === get_option( self::OPTION_DISMISSED, 'no' );
    }

    /**
     * Whether the checklist's script/style should be enqueued on this request:
     * either the card itself renders, or the restore link does.
     *
     * @since 13.6.0
     * @return bool
     */
    public function should_enqueue() {
        return $this->is_visible() || $this->is_restorable();
    }

    /**
     * Render the "Show setup checklist" restore button next to the page title.
     *
     * @since 13.6.0
     * @return void
     */
    public function render_restore_link() {
        if ( ! $this->is_restorable() ) {
            return;
        }
        ?>
        <button
            type="button"
            class="adt-setup-checklist-restore adt-tw-inline-flex adt-tw-items-center adt-tw-gap-1 adt-tw-text-sm adt-tw-font-semibold adt-tw-text-primary adt-tw-no-underline"
            data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
        >
            <span class="adt-tw-icon-[lucide--list-checks] adt-tw-w-4 adt-tw-h-4"></span>
            <?php esc_html_e( 'Show setup checklist', 'woo-product-feed-pro' ); ?>
        </button>
        <?php
    }

    /**
     * Get the localization data passed to the checklist front-end app.
     *
     * @since 13.6.0
     * @return array
     */
    public function get_l10n() {
        return array(
            'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
            'installNonce' => wp_create_nonce( 'adt_install_plugin' ),
            'mascots'      => $this->get_mascot_urls(),
        );
    }

    /**
     * Resolve a mascot URL from an ordered list of candidate files.
     *
     * @since 13.6.0
     * @param array $candidates Ordered filenames; the first that exists wins.
     * @return string URL, or an empty string when none exist.
     */
    private function resolve_mascot_url( $candidates ) {
        foreach ( $candidates as $file ) {
            if ( file_exists( ADT_PFP_PLUGIN_DIR_PATH . 'static/images/' . $file ) ) {
                return ADT_PFP_IMAGES_URL . $file;
            }
        }
        return '';
    }

    /**
     * Get the header mascot URL for each progress stage.
     *
     * Each stage falls back to an earlier pose when its asset is missing.
     *
     * @since 13.6.0
     * @return array{idea:string,feeds:string,celebrate:string}
     */
    public function get_mascot_urls() {
        return array(
            'idea'      => $this->resolve_mascot_url( array( 'mascot-idea.png' ) ),
            'feeds'     => $this->resolve_mascot_url( array( 'mascot-feeds.png', 'mascot-idea.png' ) ),
            'celebrate' => $this->resolve_mascot_url( array( 'mascot-celebrate.png', 'mascot-feeds.png', 'mascot-idea.png' ) ),
        );
    }

    /**
     * AJAX: persist a checklist interaction (dismiss / restore / mark submitted).
     *
     * @since 13.6.0
     * @return void
     */
    public function ajax_update() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( apply_filters( 'adt_pfp_admin_capability', 'manage_options' ) ) ) {
            wp_send_json_error( __( 'You are not allowed to do this.', 'woo-product-feed-pro' ) );
        }

        $task = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';

        switch ( $task ) {
            case 'dismiss':
                update_option( self::OPTION_DISMISSED, 'yes', false );
                break;
            case 'restore':
                update_option( self::OPTION_DISMISSED, 'no', false );
                break;
            case 'submit':
                update_option( self::OPTION_SUBMIT_DONE, 'yes', false );

                // Confirming the submission is usually the last essential step,
                // and the card celebrates in place without a reload. Persist the
                // completion here too, or a dismiss straight after would strand
                // OPTION_COMPLETED at 'no' and leave the restore link forever.
                $progress = $this->get_progress( $this->get_steps() );
                if ( $progress['all_done'] ) {
                    update_option( self::OPTION_COMPLETED, 'yes', false );
                }
                break;
            default:
                wp_send_json_error( __( 'Unknown checklist task.', 'woo-product-feed-pro' ) );
        }

        wp_send_json_success();
    }

    /**
     * Run the class.
     *
     * @codeCoverageIgnore
     * @since 13.6.0
     * @return void
     */
    public function run() {
        add_action( 'adt_pfp_manage_feeds_before_title', array( $this, 'render' ) );
        add_action( 'adt_pfp_manage_feeds_after_title', array( $this, 'render_restore_link' ) );
        add_action( 'wp_ajax_adt_pfp_setup_checklist_update', array( $this, 'ajax_update' ) );
    }
}
