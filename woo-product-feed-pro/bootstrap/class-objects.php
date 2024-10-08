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
use AdTribes\PFP\Classes\Cron;
use AdTribes\PFP\Classes\Heartbeat;
use AdTribes\PFP\Classes\Marketing;
use AdTribes\PFP\Post_Types\Product_Feed_Post_Type;

defined( 'ABSPATH' ) || exit;

return array(
    Product_Feed_Admin::instance(),
    Product_Feed_Attributes::instance(),
    Cron::instance(),
    Heartbeat::instance(),
    WP_Admin::instance(),
    Marketing::instance(),
    Product_Feed_Post_Type::instance(),
);
