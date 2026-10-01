<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP\REST\UCP
 */

namespace AdTribes\PFP\REST\UCP;

use AdTribes\PFP\Abstracts\Abstract_REST;
use AdTribes\PFP\Classes\UCP\Account_Client;
use AdTribes\PFP\Classes\UCP\Account_Connect;
use AdTribes\PFP\Classes\UCP\Auth;
use AdTribes\PFP\Classes\UCP\Eligibility;
use AdTribes\PFP\Classes\UCP\Keys;
use AdTribes\PFP\Classes\UCP\Payments;
use AdTribes\PFP\Classes\UCP\Preflight;
use AdTribes\PFP\Classes\UCP\Profile;
use AdTribes\PFP\Classes\UCP\Settings;
use AdTribes\PFP\Classes\UCP\UCP_Feature;
use AdTribes\PFP\Traits\Singleton_Trait;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Admin REST endpoints for the UCP page (wp-admin only).
 *
 * Uses the plugin's default `adtribes/v1` namespace with cookie+nonce
 * auth and the `manage_adtribes_product_feeds` capability — entirely
 * separate from the Google-facing `adtribes-ucp/v1` surface.
 *
 * @since 13.6.0
 */
class Admin extends Abstract_REST {

    use Singleton_Trait;

    /**
     * Settings keys the admin UI may write, with their sanitizers.
     *
     * @since 13.6.0
     * @var array
     */
    const WRITABLE_SETTINGS = array(
        'enabled'                => 'toggle',
        'staging_mode'           => 'toggle',
        'google_approved'        => 'toggle',
        'merchant_center_linked' => 'toggle',
        'google_partner_id'      => 'text',
        'google_webhook_key'     => 'text',
    );

    /**
     * Transient caching the dashboard eligibility stats.
     *
     * @since 13.6.0
     * @var string
     */
    const STATS_TRANSIENT = 'adt_ucp_eligibility_stats';

    /**
     * Register plugin routes.
     *
     * @since 13.6.0
     * @return void
     */
    public function register_plugin_routes() {

        $this->rest_base = 'ucp';

        parent::register_plugin_routes();
    }

    /**
     * Register the admin routes.
     *
     * @since 13.6.0
     * @return void
     */
    public function register_routes() {

        $routes = array(
            'status'             => array( array( 'methods' => 'GET', 'callback' => array( $this, 'get_status' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'preflight'          => array( array( 'methods' => 'POST', 'callback' => array( $this, 'run_preflight' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'settings'           => array( array( 'methods' => 'POST', 'callback' => array( $this, 'save_settings' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'api-keys'           => array(
                array(
                    'methods'  => 'POST',
                    'callback' => array( $this, 'generate_api_key' ),
                    'args'     => array(
                        'environment' => array(
                            'required' => true,
                            'type'     => 'string',
                            'enum'     => array( Auth::ENV_STAGING, Auth::ENV_PRODUCTION ),
                        ),
                    ),
                ),
            ),
            'eligibility'        => array(
                array( 'methods' => 'GET', 'callback' => array( $this, 'get_eligibility' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
                array( 'methods' => 'POST', 'callback' => array( $this, 'set_eligibility' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            ),
            'eligibility/rules'  => array( array( 'methods' => 'POST', 'callback' => array( $this, 'save_eligibility_rules' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'products'           => array( array( 'methods' => 'GET', 'callback' => array( $this, 'search_products' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'account'            => array( array( 'methods' => 'GET', 'callback' => array( $this, 'get_account' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'account/topup'      => array( array( 'methods' => 'POST', 'callback' => array( $this, 'create_topup_link' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'account/auto-topup' => array( array( 'methods' => 'POST', 'callback' => array( $this, 'save_auto_topup' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
            'account/disconnect' => array( array( 'methods' => 'POST', 'callback' => array( $this, 'disconnect_account' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
        );

        foreach ( $routes as $path => $endpoints ) {
            foreach ( $endpoints as &$endpoint ) {
                $endpoint['permission_callback'] = array( $this, 'admin_permissions_check' );
            }
            unset( $endpoint );

            register_rest_route( $this->namespace, '/' . $this->rest_base . '/' . $path, $endpoints );
        }
    }

    /**
     * Permission check: the plugin's feed-management capability.
     *
     * @since 13.6.0
     *
     * @return true|WP_Error True when authorized.
     */
    public function admin_permissions_check() {

        /**
         * Filter the capability required to manage UCP settings via REST.
         *
         * Mirrors the AI Discovery admin page gate so the API cannot perform
         * actions the page hides (enabling the connector, minting production
         * API keys, reading connection secrets). Defaults to `manage_options`
         * — enabling agentic checkout is an administrator-level action, not a
         * shop_manager (`manage_woocommerce`) one.
         *
         * @since 13.6.0
         *
         * @param string $capability Required capability.
         */
        $capability = apply_filters( 'adt_pfp_admin_capability', 'manage_options' );

        if ( current_user_can( $capability ) ) {
            return true;
        }

        return new WP_Error(
            'adt_ucp_forbidden',
            __( 'You are not allowed to manage UCP settings.', 'woo-product-feed-pro' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * GET ucp/status — everything the admin app needs to render.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function get_status( $request ) {

        $settings = Settings::instance();
        $feature  = UCP_Feature::instance();
        $adapter  = Payments::instance()->get_active_adapter();

        $all = $settings->get_all();
        unset( $all[ Preflight::RESULTS_KEY ] );

        // Never echo the Google webhook shared secret back to the browser —
        // it verifies inbound webhooks and has no business in page state,
        // the DOM, or devtools. Expose only whether one is configured.
        $has_webhook_key = '' !== (string) ( $all['google_webhook_key'] ?? '' );
        unset( $all['google_webhook_key'] );

        $data = array(
            'settings'        => $all,
            'has_webhook_key' => $has_webhook_key,
            'is_enabled'      => $feature->is_enabled(),
            'is_live'         => $feature->is_live(),
            'is_serving'      => $feature->is_serving(),
            'gates'           => $feature->get_gates(),
            'profile_url'     => home_url( '/.well-known/ucp' ),
            'preflight'       => Preflight::instance()->get_results(),
            'account'         => array(
                'connected'   => Account_Client::instance()->is_connected(),
                'server_url'  => Account_Client::instance()->get_server_url(),
                'connect_url' => Account_Connect::get_connect_action_url(),
            ),
            'gateway'         => array(
                'detected'            => null !== $adapter,
                'id'                  => $adapter ? $adapter->get_id() : '',
                'gateway_id'          => $adapter ? $adapter->get_gateway_id() : '',
                'has_key'             => $adapter && '' !== $adapter->get_publishable_key(),
                'supports_completion' => $adapter && $adapter->supports_completion(),
                'active_title'        => $this->get_active_gateway_title( $adapter ? $adapter->get_gateway_id() : '' ),
            ),
            'api_keys'        => array(
                'staging'    => Auth::instance()->get_key_meta( Auth::ENV_STAGING ),
                'production' => Auth::instance()->get_key_meta( Auth::ENV_PRODUCTION ),
            ),
            'signing_key'     => array(
                'kid' => Keys::instance()->get_kid(),
            ),
            'stats'           => $this->get_eligibility_stats(),
        );

        return $this->rest_response( $data, $request );
    }

    /**
     * Get the display title of the store's active payment gateway.
     *
     * Prefers the adapter-backed gateway; otherwise the first enabled
     * gateway (so the Payments panel can say what IS active when it
     * isn't a supported one).
     *
     * @since 13.6.0
     * @access private
     *
     * @param string $preferred_id Gateway ID to prefer.
     * @return string Gateway title, empty string when none enabled.
     */
    private function get_active_gateway_title( $preferred_id = '' ) {

        if ( null === WC()->payment_gateways() ) {
            return '';
        }

        $gateways = WC()->payment_gateways()->payment_gateways();

        if ( '' !== $preferred_id && isset( $gateways[ $preferred_id ] ) ) {
            return $gateways[ $preferred_id ]->get_method_title();
        }

        foreach ( $gateways as $gateway ) {
            if ( 'yes' === $gateway->enabled ) {
                return $gateway->get_method_title();
            }
        }

        return '';
    }

    /**
     * POST ucp/preflight — run the checks.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function run_preflight( $request ) {

        return $this->rest_response( Preflight::instance()->run(), $request );
    }

    /**
     * POST ucp/settings — update whitelisted settings.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function save_settings( $request ) {

        $params   = (array) $request->get_json_params();
        $settings = Settings::instance();

        foreach ( self::WRITABLE_SETTINGS as $key => $type ) {
            if ( ! array_key_exists( $key, $params ) ) {
                continue;
            }

            $value = 'toggle' === $type
                ? ( wc_string_to_bool( $params[ $key ] ) ? 'yes' : 'no' )
                : sanitize_text_field( (string) $params[ $key ] );

            $settings->set( $key, $value );
        }

        // Enabling the feature provisions the signing keypair.
        if ( UCP_Feature::instance()->is_enabled() ) {
            Keys::instance()->ensure_keys();
        }

        return $this->rest_response( array( 'settings' => $settings->get_all() ), $request );
    }

    /**
     * POST ucp/api-keys — (re)generate an API key; plaintext returned once.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response|WP_Error Response.
     */
    public function generate_api_key( $request ) {

        $environment = sanitize_key( (string) ( $request->get_json_params()['environment'] ?? '' ) );

        $key = Auth::instance()->generate_key( $environment );

        if ( is_wp_error( $key ) ) {
            return $key;
        }

        return $this->rest_response(
            array(
                'environment' => $environment,
                'key'         => $key,
                'meta'        => Auth::instance()->get_key_meta( $environment ),
            ),
            $request
        );
    }

    /**
     * GET ucp/account — live account status and current-period usage.
     *
     * Fetched lazily by the account panel (never on page-state load) so a
     * slow or unreachable account server cannot delay the admin page.
     * Each section degrades independently to null with an error message.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function get_account( $request ) {

        $client = Account_Client::instance();

        $data = array(
            'connected' => $client->is_connected(),
            'status'    => null,
            'usage'     => null,
            'errors'    => array(),
        );

        if ( $client->is_connected() ) {
            $status = $client->get_account_status();
            if ( is_wp_error( $status ) ) {
                $data['errors']['status'] = $status->get_error_message();
            } else {
                $data['status'] = $status;
            }

            $usage = $client->get_usage_summary();
            if ( is_wp_error( $usage ) ) {
                $data['errors']['usage'] = $usage->get_error_message();
            } else {
                $data['usage'] = $usage;
            }
        }

        return $this->rest_response( $data, $request );
    }

    /**
     * POST ucp/account/topup — get the storefront top-up link (rc.4).
     *
     * Credits are purchased on the adtribes.io store; the returned URL is
     * the add-to-cart link carrying a signed account token. No card data
     * touches the plugin or the account server.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response|WP_Error Response.
     */
    public function create_topup_link( $request ) {

        $link = Account_Client::instance()->create_topup_link();

        if ( is_wp_error( $link ) ) {
            $link->add_data( array( 'status' => 502 ) );
            return $link;
        }

        return $this->rest_response( array( 'url' => esc_url_raw( (string) ( $link['url'] ?? '' ) ) ), $request );
    }

    /**
     * POST ucp/account/auto-topup — store the auto-topup configuration.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response|WP_Error Response.
     */
    public function save_auto_topup( $request ) {

        $params = (array) $request->get_json_params();

        $result = Account_Client::instance()->set_auto_topup(
            wc_string_to_bool( $params['enabled'] ?? false ),
            absint( $params['threshold_cents'] ?? 0 ),
            absint( $params['amount_cents'] ?? 0 )
        );

        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 502 ) );
            return $result;
        }

        return $this->rest_response( $result, $request );
    }

    /**
     * POST ucp/account/disconnect — revoke the store link and delete
     * local credentials.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function disconnect_account( $request ) {

        Account_Client::instance()->disconnect();

        return $this->rest_response( array( 'disconnected' => true ), $request );
    }

    /**
     * GET ucp/eligibility — paginated products that carry a manual
     * override, with their UCP resolution.
     *
     * Only overridden products are listed — stores can have hundreds of
     * thousands of products, so the overrides UI is search-and-add (see
     * search_products()) rather than a browse-everything table.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function get_eligibility( $request ) {

        $page   = max( 1, absint( $request->get_param( 'page' ) ) );
        $search = sanitize_text_field( (string) $request->get_param( 'search' ) );

        $query = new \WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 20,
                'paged'          => $page,
                's'              => $search,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'     => Eligibility::META_KEY,
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $eligibility = Eligibility::instance();
        $items       = array();

        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post );
            if ( ! $product ) {
                continue;
            }

            $items[] = array(
                'id'         => $product->get_id(),
                'name'       => $product->get_name(),
                'sku'        => $product->get_sku(),
                'type'       => $product->get_type(),
                'override'   => $eligibility->get_override( $product ),
                'resolution' => $eligibility->is_eligible( $product ),
            );
        }

        $categories = array();
        $terms      = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => 200,
            )
        );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $categories[] = array(
                    'id'   => (int) $term->term_id,
                    'name' => $term->name,
                );
            }
        }

        return $this->rest_response(
            array(
                'items'       => $items,
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
                'exclusions'  => $eligibility->get_hard_exclusions(),
                'rules'       => $eligibility->get_rules(),
                'categories'  => $categories,
                'stats'       => $this->get_eligibility_stats(),
            ),
            $request
        );
    }

    /**
     * POST ucp/eligibility/rules — save the merchant eligibility rules.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function save_eligibility_rules( $request ) {

        $params = (array) $request->get_json_params();
        $rules  = Eligibility::instance()->save_rules( is_array( $params['rules'] ?? null ) ? $params['rules'] : array() );

        $this->flush_eligibility_stats();

        return $this->rest_response(
            array(
                'rules' => $rules,
                'stats' => $this->get_eligibility_stats(),
            ),
            $request
        );
    }

    /**
     * Approximate eligibility stats for the dashboard cards.
     *
     * `eligible` counts explicit include-overrides plus rule matches
     * translated to a product query (products with any override are
     * excluded from the rules count so nothing is counted twice);
     * purchasability is not evaluated per product, so treat the numbers
     * as dashboard-grade, not billing-grade.
     *
     * @since 13.6.0
     * @access private
     *
     * @return array {total, eligible, excluded, hard_excluded}.
     */
    private function get_eligibility_stats() {

        $cached = get_transient( self::STATS_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $eligibility = Eligibility::instance();

        $count_query = function ( array $args ) {
            $query = new \WP_Query(
                array_merge(
                    array(
                        'post_type'      => 'product',
                        'post_status'    => 'publish',
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                    ),
                    $args
                )
            );

            return (int) $query->found_posts;
        };

        $total   = $count_query( array() );
        $include = $count_query(
            array(
                'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'   => Eligibility::META_KEY,
                        'value' => 'include',
                    ),
                ),
            )
        );
        $exclude = $count_query(
            array(
                'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'   => Eligibility::META_KEY,
                        'value' => 'exclude',
                    ),
                ),
            )
        );

        // Hard-excluded: product types declared in the exclusion rules that exist as terms.
        $hard_types = array();
        foreach ( $eligibility->get_hard_exclusions() as $rule ) {
            foreach ( (array) ( $rule['types'] ?? array() ) as $type ) {
                $hard_types[] = $type;
                $hard_types[] = 'variable-' . $type;
            }
        }

        $hard = $count_query(
            array(
                'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => array_values( array_unique( $hard_types ) ),
                    ),
                ),
            )
        );

        // Rules-eligible: translate conditions to a query; skip products
        // carrying any explicit override (already counted / excluded). Rules
        // come from stored settings, so treat their shape as untrusted — a
        // malformed record must not warn its way through the stats query.
        $rules_count = 0;
        $rules       = $eligibility->get_rules();
        $conditions  = isset( $rules['conditions'] ) && is_array( $rules['conditions'] ) ? $rules['conditions'] : array();

        if ( ! empty( $conditions ) ) {
            $meta_query = array(
                array(
                    'key'     => Eligibility::META_KEY,
                    'compare' => 'NOT EXISTS',
                ),
            );
            $tax_query  = array(
                array(
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => array_values( array_unique( $hard_types ) ),
                    'operator' => 'NOT IN',
                ),
            );

            foreach ( $conditions as $condition ) {
                if ( ! is_array( $condition ) || ! isset( $condition['field'], $condition['operator'], $condition['value'] ) ) {
                    continue;
                }

                if ( 'stock_status' === $condition['field'] ) {
                    $meta_query[] = array(
                        'key'     => '_stock_status',
                        'value'   => $condition['value'],
                        'compare' => 'is' === $condition['operator'] ? '=' : '!=',
                    );
                } elseif ( 'price' === $condition['field'] ) {
                    $compare_map  = array(
                        'greater_than' => '>',
                        'less_than'    => '<',
                        'at_least'     => '>=',
                        'at_most'      => '<=',
                    );
                    $meta_query[] = array(
                        'key'     => '_price',
                        'value'   => $condition['value'],
                        'type'    => 'DECIMAL(10,4)',
                        'compare' => $compare_map[ $condition['operator'] ] ?? '>',
                    );
                } elseif ( 'category' === $condition['field'] ) {
                    $tax_query[] = array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => (array) $condition['value'],
                        'operator' => 'in' === $condition['operator'] ? 'IN' : 'NOT IN',
                    );
                }
            }

            $rules_count = $count_query(
                array(
                    'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    'tax_query'  => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                )
            );
        }

        $stats = array(
            'total'         => $total,
            'eligible'      => $include + $rules_count,
            'excluded'      => $exclude,
            'hard_excluded' => $hard,
        );

        // Dashboard-grade counts: cache briefly so opening the screen (and the
        // eligibility/rules-save round-trips that re-read them) don't re-run
        // five COUNT passes each time. Invalidated on any eligibility change.
        set_transient( self::STATS_TRANSIENT, $stats, 10 * MINUTE_IN_SECONDS );

        return $stats;
    }

    /**
     * Invalidate the cached eligibility stats.
     *
     * @since 13.6.0
     * @access private
     */
    private function flush_eligibility_stats() {

        delete_transient( self::STATS_TRANSIENT );
    }

    /**
     * POST ucp/eligibility — bulk set per-product overrides.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response|WP_Error Response.
     */
    public function set_eligibility( $request ) {

        $params      = (array) $request->get_json_params();
        $product_ids = array_filter( array_map( 'absint', (array) ( $params['product_ids'] ?? array() ) ) );
        $override    = sanitize_key( (string) ( $params['override'] ?? '' ) );

        if ( empty( $product_ids ) ) {
            return new WP_Error(
                'adt_ucp_invalid_request',
                __( 'No products provided.', 'woo-product-feed-pro' ),
                array( 'status' => 400 )
            );
        }

        $eligibility = Eligibility::instance();

        foreach ( $product_ids as $product_id ) {
            $eligibility->set_override( $product_id, $override );
        }

        $this->flush_eligibility_stats();

        return $this->rest_response(
            array(
                'updated'  => count( $product_ids ),
                'override' => $override,
            ),
            $request
        );
    }

    /**
     * GET ucp/products — search products for the override picker.
     *
     * Uses WooCommerce's own product search (title, SKU, content) so the
     * overrides UI scales to stores with hundreds of thousands of
     * products instead of paging through all of them.
     *
     * @since 13.6.0
     *
     * @param WP_REST_Request $request Request.
     * @return \WP_REST_Response Response.
     */
    public function search_products( $request ) {

        $term  = sanitize_text_field( (string) $request->get_param( 'search' ) );
        $items = array();

        if ( strlen( $term ) >= 2 ) {
            $data_store  = \WC_Data_Store::load( 'product' );
            $ids         = $data_store->search_products( $term, '', false, false, 10 );
            $eligibility = Eligibility::instance();

            foreach ( array_filter( array_map( 'absint', (array) $ids ) ) as $product_id ) {
                $product = wc_get_product( $product_id );
                if ( ! $product || 'publish' !== $product->get_status() ) {
                    continue;
                }

                $sku     = $product->get_sku();
                $items[] = array(
                    'id'       => $product->get_id(),
                    'name'     => $product->get_name(),
                    'sku'      => $sku,
                    'label'    => $product->get_name() . ( $sku ? ' (' . $sku . ')' : '' ),
                    'override' => $eligibility->get_override( $product ),
                );
            }
        }

        return $this->rest_response( array( 'items' => $items ), $request );
    }
}
