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
  $('#add_mother_image').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();

    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_mother_image',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_mother_image',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like to add all country shipping costs
  $('#add_all_shipping').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_all_shipping',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_all_shipping',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like the plugin to respect free shipping class
  $('#free_shipping').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_free_shipping',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_free_shipping',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like the plugin to respect free shipping class
  $('#local_pickup_shipping').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_local_pickup_shipping',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_local_pickup_shipping',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like the plugin to remove the free shipping class
  $('#remove_free_shipping').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_remove_free_shipping',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_remove_free_shipping',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like to enable debug logging
  $('#add_woosea_logging').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_woosea_logging',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_woosea_logging',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like to enable only basis attributes in drop-downs
  $('#add_woosea_basic').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_woosea_basic',
          security: nonce,
          status: 'on',
        },
      });
    } else {
      // Checkbox is off
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_add_woosea_basic',
          security: nonce,
          status: 'off',
        },
      });
    }
  });

  // Check if user would like to add a Facebook Pixel to their website
  $('#woosea_content_ids').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();

    var content_ids = $('#woosea_content_ids').val();
    if (content_ids) {
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_facebook_content_ids',
          security: nonce,
          content_ids: content_ids,
        },
      });
    }
  });

  // Check if user would like to add a Facebook Pixel to their website
  $('#add_facebook_pixel').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_add_facebook_pixel_setting',
            security: nonce,
            status: 'on',
          },
        })
        .done(function (data) {
          $('#facebook_pixel').after(
            '<tr id="facebook_pixel_id"><td colspan="2"><span>Insert Facebook pixel ID:</span>&nbsp;<input type="hidden" name="nonce_facebook_pixel_id" id="nonce_facebook_pixel_id" value="' +
              nonce +
              '"><input type="text" class="input-field-medium" id="fb_pixel_id" name="fb_pixel_id">&nbsp;<input type="button" id="save_facebook_pixel_id" value="Save"></td></tr>'
          );
        })
        .fail(function (data) {
          console.log('Failed AJAX Call :( /// Return Data: ' + data);
        });
    } else {
      // Checkbox is off
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_add_facebook_pixel_setting',
            security: nonce,
            status: 'off',
          },
        })
        .done(function (data) {
          $('#facebook_pixel_id').remove();
        })
        .fail(function (data) {
          console.log('Failed AJAX Call :( /// Return Data: ' + data);
        });
    }
  });

  // Check if user would like to change the batch size
  $('#add_batch').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      var popup_dialog = confirm(
        'Are you sure you want to change the batch size?\n\nChanging the batch size could seriously effect the performance of your website. We advise against changing the batch size if you are unsure about its effects!\n\nPlease reach out to support@adtribes.io when you would like to receive some help with this feature.'
      );
      if (popup_dialog == true) {
        // Checkbox is on
        jQuery
          .ajax({
            method: 'POST',
            url: ajaxurl,
            data: {
              action: 'woosea_add_batch',
              security: nonce,
              status: 'on',
            },
          })
          .done(function (data) {
            $('#batch').after(
              '<tr id="woosea_batch_size"><td colspan="2"><span>Insert batch size:</span>&nbsp;<input type="hidden" name="nonce_batch" id="nonce_batch" value="' +
                nonce +
                '"><input type="text" class="input-field-medium" id="batch_size" name="batch_size">&nbsp;<input type="submit" id="save_batch_size" value="Save"></td></tr>'
            );
          })
          .fail(function (data) {
            console.log('Failed AJAX Call :( /// Return Data: ' + data);
          });
      }
    } else {
      // Checkbox is off
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_add_batch',
            security: nonce,
            status: 'off',
          },
        })
        .done(function (data) {
          $('#woosea_batch_size').remove();
        })
        .fail(function (data) {
          console.log('Failed AJAX Call :( /// Return Data: ' + data);
        });
    }
  });

  // Save Batch Size
  jQuery('#save_batch_size').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var batch_size = $('#batch_size').val();
    var re = /^[0-9]*$/;

    var woosea_valid_batch_size = re.test(batch_size);
    // Check for allowed characters
    if (!woosea_valid_batch_size) {
      $('.notice').replaceWith(
        "<div class='notice notice-error woosea-notice-conversion is-dismissible'><p>Sorry, only numbers are allowed for your batch size number.</p></div>"
      );
      // Disable submit button too
      $('#save_batch_size').attr('disabled', true);
    } else {
      $('.woosea-notice-conversion').remove();
      $('#save_batch_size').attr('disabled', false);

      // Now we need to save the conversion ID so we can use it in the dynamic remarketing JS
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_save_batch_size',
          security: nonce,
          batch_size: batch_size,
        },
      });
    }
  });

  // Check if user would like to enable Dynamic Remarketing
  $('#add_remarketing').on('change', function () {
    // on change of state
    var nonce = $('#_wpnonce').val();
    if (this.checked) {
      // Checkbox is on
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_add_remarketing',
            security: nonce,
            status: 'on',
          },
        })
        .done(function (data) {
          $('#remarketing').after(
            '<tr id="adwords_conversion_id"><td colspan="2"><span>Insert your Dynamic Remarketing Conversion tracking ID:</span>&nbsp;<input type="hidden" name="nonce_adwords_conversion_id" id="nonce_adwords_conversion_id" value="' +
              nonce +
              '"><input type="text" class="input-field-medium" id="adwords_conv_id" name="adwords_conv_id">&nbsp;<input type="submit" id="save_conversion_id" value="Save"></td></tr>'
          );
        })
        .fail(function (data) {
          console.log('Failed AJAX Call :( /// Return Data: ' + data);
        });
    } else {
      // Checkbox is off
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'woosea_add_remarketing',
            security: nonce,
            status: 'off',
          },
        })
        .done(function (data) {
          $('#adwords_conversion_id').remove();
        })
        .fail(function (data) {
          console.log('Failed AJAX Call :( /// Return Data: ' + data);
        });
    }
  });

  // Save Google Dynamic Remarketing pixel ID
  jQuery('#save_conversion_id').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var adwords_conversion_id = $('#adwords_conv_id').val();
    var re = /^[0-9,-]*$/;
    var woosea_valid_conversion_id = re.test(adwords_conversion_id);

    // Check for allowed characters
    if (!woosea_valid_conversion_id) {
      $('.notice').replaceWith(
        "<div class='notice notice-error woosea-notice-conversion is-dismissible'><p>Sorry, only numbers are allowed for your Dynamic Remarketing Conversion tracking ID.</p></div>"
      );
      // Disable submit button too
      $('#save_conversion_id').attr('disabled', true);
    } else {
      $('.woosea-notice-conversion').remove();
      $('#save_conversion_id').attr('disabled', false);

      // Now we need to save the conversion ID so we can use it in the dynamic remarketing JS
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_save_adwords_conversion_id',
          security: nonce,
          adwords_conversion_id: adwords_conversion_id,
        },
      });
    }
  });

  // Save Facebook Pixel ID
  jQuery('#save_facebook_pixel_id').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var facebook_pixel_id = $('#fb_pixel_id').val();
    var re = /^[0-9]*$/;
    var woosea_valid_facebook_pixel_id = re.test(facebook_pixel_id);

    // Check for allowed characters
    if (!woosea_valid_facebook_pixel_id) {
      $('.notice').replaceWith(
        "<div class='notice notice-error woosea-notice-conversion is-dismissible'><p>Sorry, only numbers are allowed for your Facebook Pixel ID.</p></div>"
      );
      // Disable submit button too
      $('#save_facebook_pixel_id').attr('disabled', true);
    } else {
      $('.woosea-notice-conversion').remove();
      $('#save_facebook_pixel_id').attr('disabled', false);

      // Now we need to save the Facebook pixel ID so we can use it in the facebook pixel JS
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_save_facebook_pixel_id',
          security: nonce,
          facebook_pixel_id: facebook_pixel_id,
        },
      });
    }
  });

  // Save Facebook Conversion API token
  jQuery('#save_facebook_capi_token').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var facebook_capi_token = $('#fb_capi_token').val();
    var re = /^[0-9A-Za-z]*$/;
    var woosea_valid_facebook_capi_token = re.test(facebook_capi_token);

    // Check for allowed characters
    if (!woosea_valid_facebook_capi_token) {
      $('.notice').replaceWith(
        "<div class='notice notice-error woosea-notice-conversion is-dismissible'><p>Sorry, this is not a valid Facebook Conversion API Token.</p></div>"
      );
      // Disable submit button too
      $('#save_facebook_capi_token').attr('disabled', true);
    } else {
      $('.woosea-notice-conversion').remove();
      $('#save_facebook_capi_token').attr('disabled', false);

      // Now we need to save the Facebook Conversion API Token
      jQuery.ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_save_facebook_capi_token',
          security: nonce,
          facebook_capi_token: facebook_capi_token,
        },
      });
    }
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
            $row.addClass('processing');
            $feedStatus.addClass('woo-product-feed-pro-blink_me');
            $feedStatus.text('processing (0%)');

            if (!isRefreshRunning) {
              woosea_check_processing_feeds();
            }
          })
          .fail(function () {
            $row.removeClass('processing');
            $feedStatus.removeClass('woo-product-feed-pro-blink_me');
            $feedStatus.text('ready');
          })
          .always(function () {});
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
