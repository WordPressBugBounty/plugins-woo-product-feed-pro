jQuery(function ($) {
  //jQuery(document).ready(function($) {
  var project_hash = null;
  var project_status = null;
  var isRefreshRunning = false;
  var refreshXHR = null;
  var pageName = $('.woo-product-feed-pro-table').data('pagename');
  var activeTab = $('woo-product-feed-pro-nav-tab-wrapper').find('.nav-tab-active').data('tab');

  $(document).ready(function () {
    // Run the check percentage function on load.
    // Only run this function on the manage feed page.
    if (pageName === 'manage_feed') {
      woosea_check_processing_feeds(true);
    }
  });

  $('.dismiss-review-notification, .review-notification .notice-dismiss').on('click', function () {
    var nonce = $('#_wpnonce').val();

    $('.review-notification').remove();

    jQuery.ajax({
      method: 'POST',
      url: ajaxurl,
      data: {
        action: 'woosea_review_notification',
        security: nonce,
      },
    });
  });

  $('.get_elite .notice-dismiss').on('click', function (e) {
    var nonce = $('#_wpnonce').val();

    $('.get_elite').remove();

    jQuery.ajax({
      method: 'POST',
      url: ajaxurl,
      data: {
        action: 'woosea_getelite_notification',
        security: nonce,
      },
    });
  });

  $('td[id=manage_inline]').find('div').parents('tr').hide();
  $('#woosea_main_table')
    .find('.woo-product-feed-pro-switch .checkbox-field')
    .on('change', function () {
      var nonce = $('#_wpnonce').val();

      project_hash = $(this).val();
      project_status = $(this).prop('checked');
      $parentTableRow = $(this).parents('tr');

      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_project_status',
            security: nonce,
            project_hash: project_hash,
            active: project_status,
          },
        })
        .done(function (response) {
          if (response.success) {
            if (response.data.status === 'publish') {
              $parentTableRow.removeClass('strikethrough');
            } else {
              $parentTableRow.addClass('strikethrough');
            }
          }
        });
    });

  // Check if user would like to use mother image for variations
  $('.adt-pfp-general-setting').on('change', function () {
    // Get name of setting.
    var nonce = $('#_wpnonce').val();
    var setting = $(this).attr('name');
    var $row = $(this).closest('tr');

    // Get type of setting
    var type = $(this).attr('type') || 'text';

    switch (type) {
      case 'checkbox':
        var value = $(this).is(':checked');
        break;
      case 'text':
      default:
        var value = $(this).val();
        break;
    }

    if ($row.hasClass('group') && type === 'checkbox') {
      var group = $row.data('group');
      adt_show_or_hide_addtitional_setting_row(group, value);
    }

    // Send AJAX request to update the setting.
    jQuery.ajax({
      method: 'POST',
      url: ajaxurl,
      data: {
        action: 'adt_pfp_update_settings',
        security: nonce,
        setting: setting,
        type: type,
        value: value,
      },
    });
  });

  /**
   * Show or hide additional setting row based on the value of the parent setting.
   *
   * @param {string} group
   * @param {boolean} value
   *
   * @return {void}
   */
  function adt_show_or_hide_addtitional_setting_row(group, value) {
    $child_group = $('.woo-product-feed-pro-table--manage-settings').find('tr.group-child[data-group="' + group + '"]');

    if (value) {
      $child_group.removeClass('hidden');
    } else {
      $child_group.addClass('hidden');
    }
  }

  // Save Batch Size
  jQuery('.adt-pfp-save-setting-button').on('click', function (e) {
    e.preventDefault();

    var $col = $(this).closest('td');
    var $input = $col.find('input[type="text"]');
    var $error = $col.find('.error-message');
    var id = $col.find('input[type="text"]').attr('id');
    var setting = $input.attr('name');
    var value = $input.val();
    var nonce = $('#_wpnonce').val();
    var regex = '';
    var error_message = '';

    switch (id) {
      case 'batch_size':
      case 'fb_pixel_id':
        regex = /^[0-9]*$/;
        error_message = 'Only numbers are allowed. Please enter a valid format.';
        break;
      case 'adwords_conv_id':
        regex = /^[0-9,-]*$/;
        error_message = 'Only numbers, comma (,) and hyphen (-) are allowed. Please enter a valid format.';
        break;
      default:
        regex = /^[0-9A-Za-z]*$/;
        error_message = 'Only numbers and letters are allowed. Please enter a valid format.';
        break;
    }

    // Check for allowed characters
    if (!regex.test(value)) {
      $error.text(error_message);
      $error.show();
      return;
    }

    $error.text('');
    $error.hide();

    // Now we need to save the conversion ID so we can use it in the dynamic remarketing JS
    jQuery.ajax({
      method: 'POST',
      url: ajaxurl,
      data: {
        action: 'adt_pfp_update_settings',
        security: nonce,
        setting: setting,
        type: 'text',
        value: value,
      },
    });
  });

  $('.actions').on('click', 'span', function () {
    var id = $(this).attr('id');
    var idsplit = id.split('_');
    var project_hash = idsplit[1];
    var action = idsplit[0];
    var nonce = $('#_wpnonce').val();
    var $row = $(this).closest('tr');
    var $feedStatus = $row.find('.woo-product-feed-pro-feed-status span');
    var feed_id = $row.data('id');

    if (action == 'gear') {
      $('tr')
        .not(':first')
        .click(function (event) {
          var $target = $(event.target);
          $target.closest('tr').next().find('div').parents('tr').slideDown('slow');
        });
    }

    if (action == 'copy') {
      var popup_dialog = confirm('Are you sure you want to copy this feed?');
      if (popup_dialog == true) {
        jQuery
          .ajax({
            method: 'POST',
            url: ajaxurl,
            data: {
              action: 'woosea_project_copy',
              security: nonce,
              id: feed_id,
            },
          })

          .done(function (response) {
            $('#woosea_main_table').append(
              '<tr class><td>&nbsp;</td><td colspan="5"><span>The plugin is creating a new product feed now: <b><i>"' +
                response.data.projectname +
                '"</i></b>. Please refresh your browser to manage the copied product feed project.</span></span></td></tr>'
            );
          });
      }
    }

    if (action == 'trash') {
      var popup_dialog = confirm('Are you sure you want to delete this feed?');
      if (popup_dialog == true) {
        jQuery.ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_project_delete',
            security: nonce,
            id: feed_id,
          },
        });

        $('table tbody')
          .find('input[name="manage_record"]')
          .each(function () {
            var hash = this.value;
            if (hash == project_hash) {
              $(this).parents('tr').remove();
            }
          });
      }
    }

    if (action == 'cancel') {
      var popup_dialog = confirm('Are you sure you want to cancel processing the feed?');
      if (popup_dialog == true) {
        // Stop the recurring process
        isRefreshRunning = false;

        // Abort the current AJAX request if one is running
        // Clear the reference to the aborted request
        if (refreshXHR) {
          refreshXHR.abort();
          refreshXHR = null;
        }

        jQuery
          .ajax({
            method: 'POST',
            url: ajaxurl,
            data: {
              action: 'woosea_project_cancel',
              security: nonce,
              id: feed_id,
            },
          })
          .done(function (response) {
            if (response.success) {
              console.log('Feed processing cancelled: ' + project_hash);

              $feedStatus.removeClass('woo-product-feed-pro-blink_me');
              $feedStatus.text('stopped');
            } else {
              console.log(response.data.message);
            }
          })
          .fail(function () {
            console.log('Feed processing cancel failed: ' + project_hash);
          })
          .always(function () {
            // Continue checking in case other feeds are processing.
            woosea_check_processing_feeds();
          });
      }
    }

    if (action == 'refresh') {
      var popup_dialog = confirm('Are you sure you want to refresh the product feed?');
      if (popup_dialog == true) {
        $row.addClass('processing');
        $feedStatus.addClass('woo-product-feed-pro-blink_me');
        $feedStatus.text('processing (0%)');

        jQuery
          .ajax({
            method: 'POST',
            url: ajaxurl,
            data: {
              action: 'woosea_project_refresh',
              security: nonce,
              id: feed_id,
            },
          })
          .done(function () {
            if (!isRefreshRunning) {
              woosea_check_processing_feeds();
            }
          })
          .fail(function () {
            $row.removeClass('processing');
            $feedStatus.removeClass('woo-product-feed-pro-blink_me');
            $feedStatus.text('ready');
          });
      }
    }
  });

  $('#adt_migrate_to_custom_post_type').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var popup_dialog = confirm('Are you sure you want to migrate your products to a custom post type?');
    var $button = $(this);

    if (popup_dialog == true) {
      // Disable the button
      $button.prop('disabled', true);

      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'adt_migrate_to_custom_post_type',
            security: nonce,
          },
        })
        .done(function (response) {
          // Enable the button
          $button.prop('disabled', false);

          if (response.success) {
            alert(response.data.message);
          } else {
            alert('Migration failed');
          }
        })
        .fail(function (data) {
          // Enable the button
          $button.prop('disabled', false);
        });
    }
  });

  $('#adt_clear_custom_attributes_product_meta_keys').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var popup_dialog = confirm('Are you sure you want to delete the custom attributes product meta keys cache?');
    var $button = $(this);

    if (popup_dialog == true) {
      // Disable the button
      $button.prop('disabled', true);

      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'adt_clear_custom_attributes_product_meta_keys',
            security: nonce,
          },
        })
        .done(function (response) {
          // Enable the button
          $button.prop('disabled', false);

          if (response.success) {
            alert(response.data.message);
          } else {
            alert(response.data.message);
          }
        })
        .fail(function (data) {
          // Enable the button
          $button.prop('disabled', false);
        });
    }
  });

  $('#adt_pfp_anonymous_data').on('change', function () {
    var nonce = $('#_wpnonce').val();
    var value = $(this).is(':checked');

    jQuery.ajax({
      method: 'POST',
      url: ajaxurl,
      data: {
        action: 'adt_pfp_anonymous_data',
        security: nonce,
        value: value,
      },
    });
  });

  /**
   * Get the processing feeds.
   *
   * @returns {Array} The hashes of the processing feeds.
   */
  function woosea_get_processing_feeds() {
    return $(
      'table.woo-product-feed-pro-table[data-pagename="manage_feed"] tbody tr.woo-product-feed-pro-table-row.processing'
    )
      .toArray()
      .map((row) => $(row).data('project_hash'));
  }

  /**
   * Check the processing feeds.
   * This function will be called every second to check the processing feeds.
   * If there are no processing feeds, the refresh interval will be stopped.
   */
  function woosea_check_processing_feeds(force = false) {
    var nonce = $('#_wpnonce').val();
    const hashes = woosea_get_processing_feeds();

    // Stop if no processing feeds or canceled
    if ((!isRefreshRunning || !force) && hashes.length < 1) {
      isRefreshRunning = false;
      return;
    }

    // Ensure the flag is set
    isRefreshRunning = true;

    refreshXHR = jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_project_processing_status',
          security: nonce,
          project_hashes: hashes,
        },
      })
      .done(function (response) {
        if (response.data.length > 0) {
          response.data.forEach((feed) => {
            var $row = $('.woo-product-feed-pro-table-row[data-project_hash="' + feed.hash + '"]');
            var $status = $row.find('.woo-product-feed-pro-feed-status span');

            if (feed.status === 'processing' && feed.proc_perc < 100) {
              $row.addClass('processing');
              $status.addClass('woo-product-feed-pro-blink_me');
              $status.text('processing (' + feed.proc_perc + '%)');
            } else {
              $status.removeClass('woo-product-feed-pro-blink_me');
              $row.removeClass('processing');

              if (feed.status === 'stopped') {
                $status.text('stopped');
              } else {
                $status.text('ready');
              }
            }
          });
        }

        // Continue if not canceled, user might cancel a feed while the check is running
        // Recursive call to keep checking
        if (isRefreshRunning) {
          woosea_check_processing_feeds();
        }
      });
  }

  // Add copy to clipboard functionality for the debug information content box.
  new ClipboardJS('.copy-product-feed-pro-debug-info');

  // Init tooltips and select2
  $(document.body)
    .on('init_woosea_tooltips', function () {
      $('.tips, .help_tip, .woocommerce-help-tip').tipTip({
        attribute: 'data-tip',
        fadeIn: 50,
        fadeOut: 50,
        delay: 200,
        keepAlive: true,
      });
    })
    .on('init_woosea_select2', function () {
      $('.woo-sea-select2').select2({
        containerCssClass: 'woo-sea-select2-selection',
      });
    });

  // Tooltips
  $(document.body).trigger('init_woosea_tooltips');

  // Select2
  $(document.body).trigger('init_woosea_select2');
});
