<?php
/**
 * Feed Validator product-education page.
 *
 * Markup follows the "Feed Validator Landing" design; the presentation lives in
 * `src/scss/style.scss` (the `.adt-feed-validator` block).
 *
 * @package AdTribes\PFP
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Helpers\Helper;

/**
 * Every outbound link is built with Helper::get_utm_url() so traffic this page
 * sends to adtribes.io is attributable back to it. The campaign names identify
 * the placement.
 */
$adt_hero_cta_url  = Helper::get_utm_url( 'pricing', 'pfp', 'feedvalidatorpage', 'feedvalidatorhero' );
$adt_outro_cta_url = Helper::get_utm_url( 'pricing', 'pfp', 'feedvalidatorpage', 'feedvalidatoroutro' );
$adt_features_url  = Helper::get_utm_url( 'features/feed-validator', 'pfp', 'feedvalidatorpage', 'feedvalidatorfeatures' );

/**
 * Icon classes are stored whole so Tailwind's content scanner sees each
 * Iconify class literal in this file and emits the matching selector.
 */
$adt_steps = array(
    array(
        'modifier'    => 'monitor',
        'icon'        => 'adt-tw-icon-[lucide--brain]',
        'title'       => __( 'Smart Monitoring', 'woo-product-feed-pro' ),
        'description' => __( 'Checks your feed URLs on a schedule and looks for critical issues that might block your listings.', 'woo-product-feed-pro' ),
    ),
    array(
        'modifier'    => 'logs',
        'icon'        => 'adt-tw-icon-[lucide--file-search]',
        'title'       => __( 'Detailed Logs', 'woo-product-feed-pro' ),
        'description' => __( 'Pinpoints exactly what\'s wrong — invalid URLs, missing attributes, formatting issues — so you can act quickly.', 'woo-product-feed-pro' ),
    ),
    array(
        'modifier'    => 'alerts',
        'icon'        => 'adt-tw-icon-[lucide--bell-ring]',
        'title'       => __( 'Alerts & Notifications', 'woo-product-feed-pro' ),
        'description' => __( 'Instant alerts the moment errors are found. Stay on top of issues before your ad spend is wasted.', 'woo-product-feed-pro' ),
    ),
);

$adt_features = array(
    array(
        'modifier'    => 'fast',
        'icon'        => 'adt-tw-icon-[lucide--zap]',
        'title'       => __( 'Catch Errors Fast', 'woo-product-feed-pro' ),
        'description' => __( 'Get notified the moment a feed becomes invalid or contains missing info — so you can fix it before it impacts your product visibility.', 'woo-product-feed-pro' ),
    ),
    array(
        'modifier'    => 'validate',
        'icon'        => 'adt-tw-icon-[lucide--list-checks]',
        'title'       => __( 'Validate All Your Feeds', 'woo-product-feed-pro' ),
        'description' => __( 'There\'s no limit to the number of feeds you can validate. Simply enter the feed URL and the Feed Validator starts monitoring.', 'woo-product-feed-pro' ),
    ),
    array(
        'modifier'    => 'compliant',
        'icon'        => 'adt-tw-icon-[lucide--globe]',
        'title'       => __( 'Stay Compliant Across Channels', 'woo-product-feed-pro' ),
        'description' => __( 'Meet the formatting and compliance rules for Google Shopping, Meta and more. Avoid rejected listings and lost sales.', 'woo-product-feed-pro' ),
    ),
);

$adt_channels = array(
    __( 'Works with Google & Meta', 'woo-product-feed-pro' ),
    __( 'Bing, Pinterest, TikTok & more', 'woo-product-feed-pro' ),
    __( 'Unlimited feeds', 'woo-product-feed-pro' ),
);

$adt_quotes = array(
    array(
        'quote'  => __( '"The best plugin with great tutorials and a super free version! Connecting my website to different platforms took just 10 minutes."', 'woo-product-feed-pro' ),
        'author' => 'Yulia Pavlova',
    ),
    array(
        'quote'  => __( '"This plugin worked and resolved an issue I wasted days trying to fix getting my Meta catalog to sync with WooCommerce. Thank you!"', 'woo-product-feed-pro' ),
        'author' => 'Desta Cachile',
    ),
    array(
        'quote'  => __( '"Your tool has truly streamlined our processes and significantly impacted our efficiency. We appreciate your commitment to excellence."', 'woo-product-feed-pro' ),
        'author' => 'Ahmed Latif',
    ),
);

$adt_plan_note = __( 'Included in Plus & Business plans', 'woo-product-feed-pro' );
?>
<div class="wrap adt-tw-wrapper nosubsub">
    <hr class="wp-header-end" />
    <div class="adt-feed-validator">

        <section class="adt-feed-validator__hero">
            <span class="adt-feed-validator__eyebrow">
                <span class="adt-feed-validator__icon adt-tw-icon-[lucide--shield-check]" aria-hidden="true"></span>
                <?php esc_html_e( 'FEED VALIDATOR TOOL', 'woo-product-feed-pro' ); ?>
            </span>
            <div class="adt-feed-validator__hero-row">
                <img
                    class="adt-feed-validator__hero-mascot"
                    src="<?php echo esc_url( ADT_PFP_IMAGES_URL . 'mascot-feeds.png' ); ?>"
                    width="132"
                    height="165"
                    alt=""
                />
                <div class="adt-feed-validator__hero-copy">
                    <h1 class="adt-feed-validator__h1">
                        <?php esc_html_e( 'Keep your product feeds error-free — automatically', 'woo-product-feed-pro' ); ?>
                    </h1>
                    <p class="adt-feed-validator__hero-text">
                        <?php esc_html_e( 'The Feed Validator Tool continuously monitors your feed URLs, catches errors before they hit your listings, and sends instant alerts — so your products stay compliant and visible.', 'woo-product-feed-pro' ); ?>
                    </p>
                </div>
            </div>
            <div class="adt-feed-validator__hero-actions">
                <a class="adt-feed-validator__cta" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $adt_hero_cta_url ); ?>">
                    <span class="adt-feed-validator__icon adt-tw-icon-[lucide--badge-check]" aria-hidden="true"></span>
                    <?php esc_html_e( 'Start Validating My Feeds', 'woo-product-feed-pro' ); ?>
                </a>
                <span class="adt-feed-validator__note"><?php echo esc_html( $adt_plan_note ); ?></span>
            </div>
        </section>

        <section class="adt-feed-validator__shot">
            <img
                class="adt-feed-validator__shot-img"
                src="<?php echo esc_url( ADT_PFP_IMAGES_URL . 'feed-validator-1.png' ); ?>"
                width="1320"
                height="970"
                alt="<?php esc_attr_e( 'The Feed Validator dashboard listing monitored feeds and their health', 'woo-product-feed-pro' ); ?>"
                loading="lazy"
            />
        </section>

        <section class="adt-feed-validator__section">
            <div class="adt-feed-validator__section-head">
                <h2 class="adt-feed-validator__h2"><?php esc_html_e( 'How it works', 'woo-product-feed-pro' ); ?></h2>
                <p class="adt-feed-validator__lede">
                    <?php esc_html_e( 'Set it once and let AdTribes watch your feeds around the clock.', 'woo-product-feed-pro' ); ?>
                </p>
            </div>
            <div class="adt-feed-validator__grid">
                <?php foreach ( $adt_steps as $adt_step ) : ?>
                    <div class="adt-feed-validator__card adt-feed-validator__step adt-feed-validator__step--<?php echo esc_attr( $adt_step['modifier'] ); ?>">
                        <span class="adt-feed-validator__step-icon">
                            <span class="adt-feed-validator__icon <?php echo esc_attr( $adt_step['icon'] ); ?>" aria-hidden="true"></span>
                        </span>
                        <h3 class="adt-feed-validator__card-title"><?php echo esc_html( $adt_step['title'] ); ?></h3>
                        <p class="adt-feed-validator__card-text"><?php echo esc_html( $adt_step['description'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="adt-feed-validator__section">
            <div class="adt-feed-validator__section-head">
                <h2 class="adt-feed-validator__h2"><?php esc_html_e( 'Never miss a feed error again', 'woo-product-feed-pro' ); ?></h2>
                <p class="adt-feed-validator__lede">
                    <?php esc_html_e( 'Your store\'s product visibility, protected 24/7.', 'woo-product-feed-pro' ); ?>
                </p>
            </div>
            <div class="adt-feed-validator__grid">
                <?php foreach ( $adt_features as $adt_feature ) : ?>
                    <div class="adt-feed-validator__card adt-feed-validator__feature adt-feed-validator__feature--<?php echo esc_attr( $adt_feature['modifier'] ); ?>">
                        <span class="adt-feed-validator__icon <?php echo esc_attr( $adt_feature['icon'] ); ?>" aria-hidden="true"></span>
                        <h3 class="adt-feed-validator__card-title"><?php echo esc_html( $adt_feature['title'] ); ?></h3>
                        <p class="adt-feed-validator__card-text"><?php echo esc_html( $adt_feature['description'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="adt-feed-validator__channels">
                <?php foreach ( $adt_channels as $adt_channel ) : ?>
                    <span class="adt-feed-validator__channel">
                        <span class="adt-feed-validator__icon adt-tw-icon-[lucide--check]" aria-hidden="true"></span>
                        <?php echo esc_html( $adt_channel ); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="adt-feed-validator__section adt-feed-validator__proof">
            <span class="adt-feed-validator__stars" aria-hidden="true">★★★★★</span>
            <h2 class="adt-feed-validator__proof-title">
                <?php esc_html_e( 'Trusted by growing WooCommerce stores', 'woo-product-feed-pro' ); ?>
            </h2>
            <p class="adt-feed-validator__proof-sub">
                <?php esc_html_e( '1000+ five-star reviews on WordPress.org · 90,000+ active installs', 'woo-product-feed-pro' ); ?>
            </p>
            <div class="adt-feed-validator__grid">
                <?php foreach ( $adt_quotes as $adt_quote ) : ?>
                    <blockquote class="adt-feed-validator__card adt-feed-validator__quote">
                        <span class="adt-feed-validator__quote-stars" aria-hidden="true">★★★★★</span>
                        <span class="screen-reader-text"><?php esc_html_e( '5 out of 5 stars', 'woo-product-feed-pro' ); ?></span>
                        <p class="adt-feed-validator__quote-text"><?php echo esc_html( $adt_quote['quote'] ); ?></p>
                        <div class="adt-feed-validator__quote-by">
                            <strong><?php echo esc_html( $adt_quote['author'] ); ?></strong><br />
                            <?php esc_html_e( 'via WordPress.org', 'woo-product-feed-pro' ); ?>
                        </div>
                    </blockquote>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="adt-feed-validator__section">
            <div class="adt-feed-validator__outro">
                <div class="adt-feed-validator__outro-row">
                    <img
                        class="adt-feed-validator__outro-mascot"
                        src="<?php echo esc_url( ADT_PFP_IMAGES_URL . 'mascot-celebrate.png' ); ?>"
                        width="128"
                        height="160"
                        alt=""
                    />
                    <div class="adt-feed-validator__outro-copy">
                        <h2 class="adt-feed-validator__outro-title">
                            <?php esc_html_e( 'Get the Feed Validator Tool today', 'woo-product-feed-pro' ); ?>
                        </h2>
                        <p class="adt-feed-validator__outro-text">
                            <?php esc_html_e( 'Create & validate product feeds for advertising platforms, shopping networks and comparison sites — all from one place.', 'woo-product-feed-pro' ); ?>
                        </p>
                        <div class="adt-feed-validator__outro-actions">
                            <a class="adt-feed-validator__cta adt-feed-validator__cta--inverse" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $adt_outro_cta_url ); ?>">
                                <span class="adt-feed-validator__icon adt-tw-icon-[lucide--badge-check]" aria-hidden="true"></span>
                                <?php esc_html_e( 'Start Validating My Feeds', 'woo-product-feed-pro' ); ?>
                            </a>
                            <a class="adt-feed-validator__cta-ghost" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $adt_features_url ); ?>">
                                <?php esc_html_e( 'See all features', 'woo-product-feed-pro' ); ?>
                            </a>
                        </div>
                        <p class="adt-feed-validator__outro-note"><?php echo esc_html( $adt_plan_note ); ?></p>
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>
