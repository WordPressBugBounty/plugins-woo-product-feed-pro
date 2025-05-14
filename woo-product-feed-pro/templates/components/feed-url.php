<?php
/**
 * Template file for displaying the feed URL.
 *
 * @package AdTribes\PFP\Templates
 * @since 13.4.4
 *
 * @var object $feed The feed object.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( file_exists( $feed->get_file_path() ) ) : ?>
    <div class="adt-manage-feeds-table-row-url-input">
        <input 
            type="text" 
            value="<?php echo esc_url( $feed->file_url ); ?>" 
            readonly 
            class="adt-manage-feeds-table-row-url-input-input"
        />
        <a
            class="adt-manage-feeds-table-row-url-button adt-manage-feeds-table-row-url-copy-button"
            title="Copy URL"
        >
            <span class="adt-tooltip">
                <span class="adt-manage-feeds-table-row-url-button-icon adt-tw-icon-[lucide--copy]"></span>
                <div class="adt-tooltip-content">
                    <?php esc_html_e( 'Copy URL', 'woo-product-feed-pro' ); ?>
                </div>
            </span>
        </a>
        <a
            class="adt-manage-feeds-table-row-url-button adt-manage-feeds-table-row-url-link-button"
            title="Open in new tab"
            href="<?php echo esc_url( $feed->file_url ); ?>"
            target="_blank"
        >
            <span class="adt-tooltip">
                <span class="adt-manage-feeds-table-row-url-button-icon adt-tw-icon-[lucide--external-link]"></span>
                <div class="adt-tooltip-content">
                    <?php esc_html_e( 'Open in new tab', 'woo-product-feed-pro' ); ?>
                </div>
            </span>
        </a>
    </div>
<?php elseif ( 'processing' === $feed->status ) : ?>
    <div class="adt-manage-feeds-table-row-url-link-disabled adt-tooltip adt-tw-flex adt-tw-items-center adt-tw-justify-center adt-tw-gap-2">
        <span><?php esc_html_e( 'Processing...', 'woo-product-feed-pro' ); ?></span>
        <span class="adt-tw-icon-[lucide--circle-help] adt-tw-w-4 adt-tw-h-4 adt-tw-text-gray-400"></span>
        <div class="adt-tooltip-content">
            <?php esc_html_e( 'The feed is processing, please wait until it is ready.', 'woo-product-feed-pro' ); ?>
        </div>
    </div>
<?php else : ?>
    <div class="adt-manage-feeds-table-row-url-link-disabled adt-tooltip adt-tw-flex adt-tw-items-center adt-tw-justify-center adt-tw-gap-2">
        <span><?php esc_html_e( 'Feed not available', 'woo-product-feed-pro' ); ?></span>
        <span class="adt-tw-icon-[lucide--circle-help] adt-tw-w-4 adt-tw-h-4 adt-tw-text-gray-400"></span>
        <div class="adt-tooltip-content">
            <?php esc_html_e( 'The feed file is not available, refresh the feed to generate a new file.', 'woo-product-feed-pro' ); ?>
        </div>
    </div>
<?php endif; ?>
