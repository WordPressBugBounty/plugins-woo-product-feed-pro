jQuery(function ($) {
  $('#wpadminbar #wp-toolbar #wp-admin-bar-adt-pfp-funnelkit-stripe')
    .pointer({
      pointerClass: 'adt-pfp-funnelkit-stripe-pointer',
      // content: i18n.content,
      content: '<h3>' + adt_pfp_funnelkit_stripe.i18n.heading + '</h3>' + adt_pfp_funnelkit_stripe.i18n.content,
      position: {
        edge: 'top',
        align: 'center',
      },
      pointerWidth: 350,
      buttons: function (event, target) {
        const $buttons = jQuery('<div></div>', { class: 'adt-pfp-funnelkit-stripe-pointer-buttons' });

        $buttons.append(createInstallButton(target));
        $buttons.append(createDismissButton(target));

        return $buttons;
      },
    })
    .pointer('open');

  $('#wpadminbar #wp-toolbar #wp-admin-bar-adt-pfp-funnelkit-stripe').on('click', function () {
    $(this).pointer('toggle');
  });

  /**
   * Create a install button for the funnelkit stripe pointer.
   *
   * @param target
   * @returns {JQuery<HTMLElement>}
   */
  function createInstallButton(target) {
    return $('<a></a>', {
      class: 'install button button-primary',
      text: adt_pfp_funnelkit_stripe.i18n.install_button,

      // Callback to dismiss the license reminder when the close button is clicked.
      click: (e) => {
        // Get the button.
        const button = $(e.target);

        // if has installed, do nothing
        if (button.hasClass('installed')) {
          return;
        }

        e.preventDefault();

        // Disable the button.
        button.attr('disabled', 'disabled');

        // Add installing class to the button.
        button.addClass('installing');

        // Change text to installing.
        button.html(adt_pfp_funnelkit_stripe.i18n.install_button_installing);

        $.post(
          ajaxurl,
          {
            action: 'adt_install_activate_plugin',
            nonce: adt_pfp_funnelkit_stripe.install_nonce,
            plugin_slug: 'funnelkit-stripe-woo-payment-gateway',
            silent: true,
          },
          (response) => {
            if (response.success) {
              $.get(
                ajaxurl,
                {
                  action: 'adt_pfp_get_funnelkit_stripe_connect_link',
                  nonce: adt_pfp_funnelkit_stripe.nonce,
                },
                (response) => {
                  // Enable the button.
                  button.removeAttr('disabled');

                  // Remove the installing class from the button.
                  button.removeClass('installing');

                  // Add installing class to the button.
                  button.addClass('installed');

                  if (response.success) {
                    if (response.data.connected) {
                      button.html(adt_pfp_funnelkit_stripe.i18n.install_button_settings);
                    } else {
                      button.html(adt_pfp_funnelkit_stripe.i18n.install_button_connect);
                    }

                    // Change the button link.
                    button.attr('href', response.data.url);
                  }
                },
                'json'
              );
            } else {
              // Enable the button.
              button.removeAttr('disabled');

              button.removeClass('installing');

              // Change text to install.
              button.html(adt_pfp_funnelkit_stripe.i18n.install_button);
            }
          },
          'json'
        );
      },
    });
  }

  /**
   * Create a dismiss button for the funnelkit stripe pointer.
   *
   * @param target
   * @returns {JQuery<HTMLElement>}
   */
  function createDismissButton(target) {
    return $('<a></a>', {
      class: 'close',
      href: '#',
      text: adt_pfp_funnelkit_stripe.i18n.dismiss_button,

      // Callback to dismiss the license reminder when the close button is clicked.
      click: (e) => {
        e.preventDefault();

        $.post(
          ajaxurl,
          {
            action: 'adt_pfp_dismiss_funnelkit_stripe_pointer',
            nonce: adt_pfp_funnelkit_stripe.nonce,
          },
          'json'
        );

        target.element.pointer('close');
      },
    });
  }
});
