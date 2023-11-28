<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

?>
<div class="gifty_gift_card_block">
    <h4><?php
        esc_html_e( apply_filters(
                        'gifty_wc_gc_form_title',
                        __( 'Have a gift card?', 'gifty-woocommerce' )
                    ) ); ?></h4>
    <div class="gifty_gift_card_form">
        <?php esc_html_e( apply_filters( 'gifty_wc_gc_form_before', null ) ); ?>
        <div id="gifty_gift_card_notices_wrapper" aria-live="assertive"></div>
        <?php
        woocommerce_form_field(
            'gifty_cart_code',
            [
                'placeholder' => 'JW96-S75S-9FV8-L9S4',
                'minlength'   => 16,
                'label'       => __( 'Enter your gift card code', 'gifty-woocommerce' ),
                'label_class' => 'screen-reader-text',
            ],
            wc_get_post_data_by_key( 'gifty_cart_code' )
        ); ?>
        <button type="button" name="gifty_cart_submit" id="gifty_cart_submit"
                class="button woocommerce-button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>"
                disabled>
            <?php
            esc_html_e( apply_filters(
                            'gifty_wc_gc_form_buttom',
                            __( 'Apply', 'gifty-woocommerce' )
                        ) );
            ?>
        </button>
    </div>
</div>
