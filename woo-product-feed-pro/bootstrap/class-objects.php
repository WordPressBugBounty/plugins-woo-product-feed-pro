<?php
/**
 * Class objects instance list.
 *
 * @since   13.3.3
 * @package AdTribes\PFP
 */

use AdTribes\PFP\Classes\WP_Admin;
use AdTribes\PFP\Classes\Product_Feed_Admin;
use AdTribes\PFP\Classes\Product_Feed_Attributes;
use AdTribes\PFP\Classes\Product_Data;
use AdTribes\PFP\Classes\Shipping_Data;
use AdTribes\PFP\Classes\Filters;
use AdTribes\PFP\Classes\Rules;
use AdTribes\PFP\Classes\Cron;
use AdTribes\PFP\Classes\Heartbeat;
use AdTribes\PFP\Classes\Marketing;
use AdTribes\PFP\Classes\Usage;
use AdTribes\PFP\Classes\Google_Product_Taxonomy_Fetcher;
use AdTribes\PFP\Classes\Plugin_Installer;
use AdTribes\PFP\Classes\FunnelKit_Stripe;
use AdTribes\PFP\Post_Types\Product_Feed_Post_Type;

defined( 'ABSPATH' ) || exit;

return array(
    Product_Feed_Admin::instance(),
    Product_Feed_Attributes::instance(),
    Product_Data::instance(),
    Shipping_Data::instance(),
    Filters::instance(),
    Rules::instance(),
    Cron::instance(),
    Heartbeat::instance(),
    WP_Admin::instance(),
    Marketing::instance(),
    Usage::instance(),
    Google_Product_Taxonomy_Fetcher::instance(),
    Plugin_Installer::instance(),
    FunnelKit_Stripe::instance(),
    Product_Feed_Post_Type::instance(),
);
