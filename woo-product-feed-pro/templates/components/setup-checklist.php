<?php
/**
 * Setup checklist ("Getting Started") card for the Manage Feeds page.
 *
 * @package AdTribes\PFP
 *
 * @var array  $steps      Checklist steps from Setup_Checklist::get_steps().
 * @var array  $progress   Essential-step progress {done,total,all_done,pct}.
 * @var string $mascot_url URL of the Pip mascot image (empty to hide it).
 * @var string $nonce      Nonce for the checklist AJAX endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'You are not allowed to call this page directly.' );
}

use AdTribes\PFP\Helpers\Helper;

$all_done = ! empty( $progress['all_done'] );
$headline = $all_done
    ? __( "You're all set up!", 'woo-product-feed-pro' )
    : __( 'Finish setting up Product Feed PRO', 'woo-product-feed-pro' );
$subline  = $all_done
    ? __( 'Your feeds are ready to reach shoppers.', 'woo-product-feed-pro' )
    : sprintf(
        ( $progress['total'] - $progress['done'] ) === 1
            /* translators: 1: completed essential steps, 2: total essential steps. */
            ? __( '%1$d of %2$d essential steps done — almost there!', 'woo-product-feed-pro' )
            /* translators: 1: completed essential steps, 2: total essential steps. */
            : __( '%1$d of %2$d essential steps done', 'woo-product-feed-pro' ),
        (int) $progress['done'],
        (int) $progress['total']
    );
?>
<div class="adt-setup-checklist" data-nonce="<?php echo esc_attr( $nonce ); ?>">
    <div class="adt-setup-checklist__hairline"></div>

    <div class="adt-setup-checklist__header">
        <?php if ( $mascot_url ) : ?>
            <img class="adt-setup-checklist__mascot" src="<?php echo esc_url( $mascot_url ); ?>" alt="<?php esc_attr_e( 'Pip the panda', 'woo-product-feed-pro' ); ?>" />
        <?php endif; ?>
        <div class="adt-setup-checklist__intro">
            <div class="adt-setup-checklist__eyebrow"><?php esc_html_e( 'Getting Started', 'woo-product-feed-pro' ); ?></div>
            <h2 class="adt-setup-checklist__headline"><?php echo esc_html( $headline ); ?></h2>
            <div class="adt-setup-checklist__subline"><?php echo esc_html( $subline ); ?></div>
        </div>
    </div>

    <div class="adt-setup-checklist__body">
        <div class="adt-setup-checklist__progress">
            <div
                class="adt-setup-checklist__progress-track"
                role="progressbar"
                aria-valuenow="<?php echo esc_attr( (int) $progress['pct'] ); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="<?php esc_attr_e( 'Setup checklist progress', 'woo-product-feed-pro' ); ?>"
            >
                <div class="adt-setup-checklist__progress-fill" style="width:<?php echo esc_attr( (int) $progress['pct'] ); ?>%;"></div>
            </div>
            <span class="adt-setup-checklist__progress-label" aria-live="polite">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: completed essential steps, 2: total essential steps. */
                        __( '%1$d / %2$d done', 'woo-product-feed-pro' ),
                        (int) $progress['done'],
                        (int) $progress['total']
                    )
                );
                ?>
            </span>
        </div>

        <div class="adt-setup-checklist__steps">
            <?php foreach ( $steps as $key => $step ) : ?>
                <div
                    class="adt-setup-checklist__step"
                    data-step="<?php echo esc_attr( $key ); ?>"
                    data-optional="<?php echo empty( $step['optional'] ) ? '0' : '1'; ?>"
                >
                    <div class="adt-setup-checklist__step-row">
                        <?php if ( ! empty( $step['done'] ) ) : ?>
                            <span class="adt-setup-checklist__check adt-setup-checklist__check--done">
                                <span class="adt-tw-icon-[lucide--check]"></span>
                            </span>
                        <?php else : ?>
                            <span class="adt-setup-checklist__check adt-setup-checklist__check--todo"></span>
                        <?php endif; ?>

                        <div class="adt-setup-checklist__step-body">
                            <div class="adt-setup-checklist__step-title-row">
                                <span class="adt-setup-checklist__step-title"><?php echo esc_html( $step['title'] ); ?></span>
                                <?php if ( ! empty( $step['optional'] ) ) : ?>
                                    <span class="adt-setup-checklist__badge"><?php esc_html_e( 'Optional', 'woo-product-feed-pro' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="adt-setup-checklist__step-sub"><?php echo esc_html( $step['sub'] ); ?></div>
                        </div>

                        <div class="adt-setup-checklist__step-actions">
                            <?php if ( ! empty( $step['warning_chip'] ) ) : ?>
                                <span
                                    class="adt-setup-checklist__chip"
                                    title="<?php esc_attr_e( 'WP-Cron is disabled. Action Scheduler will refresh your feeds instead — nothing is broken.', 'woo-product-feed-pro' ); ?>"
                                >
                                    <span class="adt-tw-icon-[lucide--triangle-alert]"></span>
                                    <?php esc_html_e( 'WP-Cron off', 'woo-product-feed-pro' ); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ( ! empty( $step['plain_done'] ) ) : ?>
                                <span class="adt-setup-checklist__auto"><?php esc_html_e( 'Auto-detected', 'woo-product-feed-pro' ); ?></span>
                            <?php endif; ?>

                            <?php if ( ! empty( $step['show_copy'] ) ) : ?>
                                <div class="adt-setup-checklist__submit" data-feed-url="<?php echo esc_url( $step['feed_url'] ); ?>">
                                    <button type="button" class="adt-setup-checklist__btn adt-setup-checklist__btn--primary adt-setup-checklist__copy" <?php disabled( '', $step['feed_url'] ); ?>>
                                        <span class="adt-tw-icon-[lucide--copy]"></span>
                                        <?php esc_html_e( 'Copy Feed URL', 'woo-product-feed-pro' ); ?>
                                    </button>
                                    <span class="adt-setup-checklist__copied" role="status" aria-live="polite" hidden>
                                        <span class="adt-tw-icon-[lucide--clipboard-check]"></span>
                                        <?php esc_html_e( 'Copied', 'woo-product-feed-pro' ); ?>
                                    </span>
                                    <button type="button" class="adt-setup-checklist__btn adt-setup-checklist__btn--outline adt-setup-checklist__mark" hidden>
                                        <?php esc_html_e( "I've submitted it", 'woo-product-feed-pro' ); ?>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $step['show_upgrade'] ) ) : ?>
                                <a class="adt-setup-checklist__btn adt-setup-checklist__btn--upgrade" href="<?php echo esc_url( $step['upgrade_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    <span class="adt-tw-icon-[fluent--sparkle-16-filled]"></span>
                                    <?php esc_html_e( 'Upgrade', 'woo-product-feed-pro' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ( ! empty( $step['plugins'] ) ) : ?>
                        <div class="adt-setup-checklist__sisters">
                            <?php foreach ( $step['plugins'] as $sister_plugin ) : ?>
                                <div class="adt-setup-checklist__sister" data-slug="<?php echo esc_attr( $sister_plugin['slug'] ); ?>">
                                    <div class="adt-setup-checklist__sister-head">
                                        <?php if ( $sister_plugin['icon'] ) : ?>
                                            <img
                                                class="adt-setup-checklist__sister-icon"
                                                src="<?php echo esc_url( $sister_plugin['icon'] ); ?>"
                                                alt="<?php echo esc_attr( $sister_plugin['name'] ); ?>"
                                                width="38"
                                                height="38"
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        <?php else : ?>
                                            <span class="adt-setup-checklist__sister-icon adt-setup-checklist__sister-icon--fallback adt-tw-icon-[lucide--puzzle]" role="img" aria-label="<?php echo esc_attr( $sister_plugin['name'] ); ?>"></span>
                                        <?php endif; ?>
                                        <div class="adt-setup-checklist__sister-meta">
                                            <div class="adt-setup-checklist__sister-name"><?php echo esc_html( $sister_plugin['name'] ); ?></div>
                                            <div class="adt-setup-checklist__sister-tagline"><?php echo esc_html( $sister_plugin['tagline'] ); ?></div>
                                        </div>
                                    </div>
                                    <?php if ( ! empty( $sister_plugin['installed'] ) ) : ?>
                                        <span class="adt-setup-checklist__sister-state adt-setup-checklist__sister-state--installed">
                                            <span class="adt-tw-icon-[lucide--check]"></span>
                                            <?php esc_html_e( 'Installed & Active', 'woo-product-feed-pro' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <button type="button" class="adt-setup-checklist__btn adt-setup-checklist__btn--outline adt-setup-checklist__install">
                                            <span class="adt-tw-icon-[lucide--download]"></span>
                                            <?php esc_html_e( 'Install & Activate', 'woo-product-feed-pro' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="adt-setup-checklist__footer">
            <?php if ( $all_done ) : ?>
                <span class="adt-setup-checklist__celebrate">
                    <span class="adt-tw-icon-[lucide--party-popper]"></span>
                    <?php esc_html_e( 'All essentials complete — great work!', 'woo-product-feed-pro' ); ?>
                </span>
            <?php else : ?>
                <span class="adt-setup-checklist__help">
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: 1: opening anchor tag, 2: closing anchor tag. */
                            __( '%1$sNeed help?%2$s Check our Getting Started guide', 'woo-product-feed-pro' ),
                            '<a href="' . esc_url( Helper::get_utm_url( 'setting-up-your-first-google-shopping-product-feed', 'pfp', 'setup-checklist', 'getting started guide' ) ) . '" target="_blank" rel="noopener noreferrer">',
                            '</a>'
                        )
                    );
                    ?>
                </span>
            <?php endif; ?>
            <button type="button" class="adt-setup-checklist__dismiss"><?php esc_html_e( 'Dismiss checklist', 'woo-product-feed-pro' ); ?></button>
        </div>
    </div>
</div>
