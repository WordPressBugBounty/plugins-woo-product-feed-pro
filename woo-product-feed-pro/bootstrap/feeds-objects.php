<?php
/**
 * Class objects instance list.
 *
 * @since   13.4.5
 * @package AdTribes\PFP
 */

use AdTribes\PFP\Classes\Feeds\Google_Product_Review;
use AdTribes\PFP\Classes\Feeds\OpenAI_Product_Feed;
use AdTribes\PFP\Classes\Feeds\Google_UCP_Feed;
use AdTribes\PFP\Classes\UCP\UCP_Feature;

defined( 'ABSPATH' ) || exit;

$objects = array(
    Google_Product_Review::instance(),
    OpenAI_Product_Feed::instance(),
);

// The UCP connector is a closed beta — see UCP_Feature::is_available().
if ( UCP_Feature::instance()->is_available() ) {
    $objects[] = Google_UCP_Feed::instance();
}

return $objects;
