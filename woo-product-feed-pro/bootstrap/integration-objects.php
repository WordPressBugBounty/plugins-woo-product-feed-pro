<?php
/**
 * Integration instance objects.
 *
 * @package AdTribes\PFP
 */

use AdTribes\PFP\Integrations\WC_Vendors;
use AdTribes\PFP\Integrations\WP_Rocket;
use AdTribes\PFP\Integrations\WWPP;

defined( 'ABSPATH' ) || exit;

return array_filter(
    array(
        new WC_Vendors(),
        new WP_Rocket(),
        new WWPP(),
    ),
);
