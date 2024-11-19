<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div
    class="notice notice-<?php echo esc_attr( "$type $type" ); ?> is-dismissible"
    id="<?php echo esc_attr( $message_id ); ?>"
>
    <?php if ( 'html' === $message_format ) : ?>
        <?php echo wp_kses( $message, 'post' ); ?>
    <?php else : ?>
        <p><?php echo esc_html( $message ); ?></p>
    <?php endif; ?>
</div>
