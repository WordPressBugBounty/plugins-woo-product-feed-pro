<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="pfp-about-page" class="pfp-page wrap nosubsub">
    <div class="container">
        <div class="row">
            <div class="col xs-text-center">
                <img class="logo" src="<?php echo esc_url( WOOCOMMERCESEA_IMAGES_URL . 'adt-logo.png' ); ?>" alt="<?php esc_attr_e( 'AdTribes', 'woo-product-feed-pro' ); ?>" />
            </div>
        </div>
        <div class="row">
            <div class="col xs-text-center">
                <h1 class="page-title"><?php esc_html_e( 'Getting Help', 'woo-product-feed-pro' ); ?></h1>
                <p><?php esc_html_e( 'We\'re here to help you get the most out of Product Feed Pro for WooCommerce.', 'woo-product-feed-pro' ); ?></p>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <ul class="card-list">
                    <li class="card">
                        <div class="card-title">
                            <h3><?php esc_html_e( 'Knowledge Base', 'woo-product-feed-pro' ); ?></h3>
                        </div>
                        <div class="card-body xs-text-center">
                            <p class="mt-0"><?php esc_html_e( 'Access our self-service help documentation via the Knowledge Base. You\'ll find answers and solutions for a wide range of well know situations . You\'ll also find a Getting Started guide here for the plugin.', 'woo-product-feed-pro' ); ?></p>
                            <a target="_blank" href="<?php echo esc_url( 'https://adtribes.io/support/?utm_source=pfp&utm_medium=helppage&utm_campaign=helppageopenkbbutton' ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Open Knowledge Base', 'woo-product-feed-pro' ); ?></a>
                        </div>
                    </li> 
                    <li class="card">
                        <div class="card-title">
                            <h3><?php esc_html_e( 'Free Version WordPress.org Help Forums', 'woo-product-feed-pro' ); ?></h3>
                        </div>
                        <div class="card-body xs-text-center">
                            <p class="mt-0"><?php esc_html_e( 'Our support staff regularly check and help our free users at the official plugin WordPress.org help forums. Submit a post there with your question and we\'ll get back to you as soon as possible.', 'woo-product-feed-pro' ); ?></p>
                            <a target="_blank" href="<?php echo esc_url( 'https://wordpress.org/support/plugin/woo-product-feed-pro/' ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Visit WordPress.org Forums', 'woo-product-feed-pro' ); ?></a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
        <div class="row">
            <div class="col xs-text-center">
                <iframe src="https://adtribes.io/in-app-optin/?utm_source=pfp&utm_medium=helppage&utm_campaign=helppageinappoptin" width="100%" height="500" frameborder="0"></iframe>
            </div>
        </div>
    </div>
</div>
