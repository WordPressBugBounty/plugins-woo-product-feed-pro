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
                <span><?php echo esc_html( $setting['label'] ); ?></span>
            </td>
            <td>
                <button class="button button-secondary" id="<?php echo esc_attr( $setting['btn_id'] ); ?>"><?php echo esc_html( $setting['btn_label'] ); ?></button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
