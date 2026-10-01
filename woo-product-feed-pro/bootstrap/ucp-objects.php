<?php
/**
 * UCP (Universal Commerce Protocol) connector class objects instance list.
 *
 * @since   13.6.0
 * @package AdTribes\PFP
 */

use AdTribes\PFP\Classes\UCP\UCP_Feature;
use AdTribes\PFP\Classes\UCP\Profile;
use AdTribes\PFP\Classes\UCP\Idempotency;
use AdTribes\PFP\Classes\UCP\Payments;
use AdTribes\PFP\Classes\UCP\Orders;
use AdTribes\PFP\Classes\UCP\Refunds;
use AdTribes\PFP\Classes\UCP\Webhooks;
use AdTribes\PFP\Classes\UCP\Account_Connect;
use AdTribes\PFP\Classes\UCP\Account_Notices;
use AdTribes\PFP\Classes\Admin_Pages\UCP_Page;

defined( 'ABSPATH' ) || exit;

/*
 * The connector is a closed beta: nothing below is wired unless the store
 * sets `define( 'ADT_UCP_BETA', true );` in wp-config.php. See
 * UCP_Feature::is_available().
 */
if ( ! UCP_Feature::instance()->is_available() ) {
    return array();
}

/*
 * Past that gate these objects are wired unconditionally, by design — not for
 * want of a feature gate. Each one decides availability at request time
 * instead:
 *
 * - Profile registers its rewrite rule always and gates the response on
 *   UCP_Feature::is_serving(), specifically so toggling the connector never
 *   requires a rewrite flush (see its class docblock).
 * - Webhooks must keep listening on the Action Scheduler dispatch hook so
 *   deliveries already queued when the connector is switched off still
 *   complete their retries; its status listener early-returns on is_serving().
 * - Orders wires the orders-list column and filter so historical agentic
 *   orders stay identifiable after the connector is turned off.
 * - Idempotency only schedules its purge while the feature is enabled.
 *
 * Wiring them behind an enabled check would trade those properties for a
 * handful of early-returning hook callbacks.
 */
return array(
    UCP_Feature::instance(),
    Profile::instance(),
    Idempotency::instance(),
    Payments::instance(),
    Orders::instance(),
    Refunds::instance(),
    Webhooks::instance(),
    Account_Connect::instance(),
    Account_Notices::instance(),
    UCP_Page::instance(),
);
