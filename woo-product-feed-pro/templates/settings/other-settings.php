<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! empty( $settings ) ) :
?>
<hr/>
<table class="woo-product-feed-pro-table woo-product-feed-pro-table--other-settings">
    <thead>
        <tr>
            <td colspan="2"><strong><?php esc_html_e( 'Other settings', 'woo-product-feed-pro' ); ?></strong></td>
        </tr>
    </thead>
    <tbody>
    <?php
    foreach ( $settings as $setting ) :
?>
        <tr>
            <td>
                <?php if ( ! empty( $setting['title'] ) && ! empty( $setting['show_title'] ) && true === $setting['show_title'] ) : ?>
                    <h4 style="margin: 4px 0 8px 0;"><?php echo esc_html( $setting['title'] ); ?></h4>
                <?php endif; ?>
                <span><?php echo wp_kses_post( $setting['desc'] ?? '' ); ?></span>
            </td>
            <td>
                <?php if ( ! empty( $setting['type'] ) ) : ?>
                    <?php if ( 'checkbox' === $setting['type'] ) : ?>
                        <label class="woo-product-feed-pro-switch">
                            <input
                                type="checkbox"
                                id="<?php echo esc_attr( $setting['id'] ?? '' ); ?>"
                                name="<?php echo esc_attr( $setting['id'] ?? '' ); ?>"
                                class="checkbox-field"
                                title="<?php echo esc_attr( $setting['title'] ?? '' ); ?>"
                                <?php echo ( 'yes' === get_option( $setting['id'] ) ) ? 'checked' : ''; ?>
                            />
                            <div class="woo-product-feed-pro-slider round"></div>
                        </label>
                    <?php elseif ( 'button' === $setting['type'] ) : ?>
                        <button class="button button-secondary" id="<?php echo esc_attr( $setting['id'] ?? '' ); ?>">
                            <?php echo esc_html( $setting['title'] ?? '' ); ?>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
