<?php
/**
 * REST objects.
 *
 * @since   13.4.3
 * @package AdTribes\PFP
 */

use AdTribes\PFP\REST\API;
use AdTribes\PFP\REST\Filters_Rules;
use AdTribes\PFP\REST\Field_Mappings;
use AdTribes\PFP\REST\Field_Mapping_Preview;
use AdTribes\PFP\REST\UCP\Checkout_Sessions;
use AdTribes\PFP\REST\UCP\Orders;
use AdTribes\PFP\REST\UCP\Admin as UCP_Admin;
use AdTribes\PFP\Classes\UCP\UCP_Feature;

defined( 'ABSPATH' ) || exit;

$objects = array(
    API::instance(),
    Filters_Rules::instance(),
    Field_Mappings::instance(),
    Field_Mapping_Preview::instance(),
);

// The UCP connector is a closed beta — see UCP_Feature::is_available(). Skip
// its controllers entirely so the routes are never registered on a store that
// has not set the ADT_UCP_BETA constant.
if ( UCP_Feature::instance()->is_available() ) {
    $objects[] = Checkout_Sessions::instance();
    $objects[] = Orders::instance();
    $objects[] = UCP_Admin::instance();
}

return $objects;
