<?php
/**
 * Author: Rymera Web Co
 *
 * @package AdTribes\PFP
 */

namespace AdTribes\PFP\Abstracts;

use AdTribes\PFP\Classes\Product_Feed_Attributes;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Class
 */
abstract class Abstract_Filters_Rules extends Abstract_Class {

    /**
     * The feed type.
     *
     * @since 13.4.5
     * @access public
     *
     * @var string
     */
    public $feed_type = '';

    /**
     * Product feed attributes instance.
     *
     * @since 13.4.5
     * @access public
     *
     * @var Product_Feed_Attributes
     */
    protected $product_feed_attributes;

    /**
     * Product feed attributes.
     *
     * @since 13.4.5
     * @access public
     *
     * @var array
     */
    public $attributes = array();

    /**
     * Construct.
     *
     * @param string $feed_type The feed type.
     */
    public function __construct( $feed_type = '' ) {
        $this->feed_type = $feed_type;
        $this->init_attributes();
    }

    /**
     * Initialize attributes.
     *
     * @since 13.4.5
     * @access public
     */
    protected function init_attributes() {
        $this->product_feed_attributes = new Product_Feed_Attributes();
        $this->attributes              = $this->get_attributes();
    }

    /**
     * Get attributes.
     *
     * @since 13.4.5
     * @access public
     *
     * @return array
     */
    protected function get_attributes() {
        $attributes = $this->product_feed_attributes->get_attributes();
        return apply_filters( 'adt_pfp_get_filters_rules_attributes', $attributes, $this->feed_type );
    }

    /**
     * Run the class
     *
     * @since 13.4.5
     * @access public
     */
    abstract public function run();
}
