jQuery(function ($) {
  //jQuery(document).ready(function($) {
  const { __ } = window.wp.i18n;
  
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
      woosea_check_processing_feeds(true, true);
    }
  });

  // Remember the saved value of every setting field, so a failed save can put it
  // back. This runs with the handler below rather than on a later tick, so a save
  // can never fail before the value is on record. The selector takes every
  // general setting rather than a list of field types, so a field type added
  // later is on record without a change here. A checkbox needs no record: the
  // change event has just flipped it, so the saved state is the opposite of the
  // new one.
  $('.adt-pfp-general-setting').each(function () {
    adt_pfp_remember_setting_value($(this));
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
  $('.adt-pfp-general-setting').on('change', function (e) {
    // Get name of setting.
    var nonce = $('#_wpnonce').val();
    var setting = $(this).attr('name');
    var $row = $(this).closest('tr');
    var confirmation = $(this).data('confirmation');

    // Disable the button.
    var $button = $(this);
    $button.prop('disabled', true);
    $button.addClass('loading');

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

    if (confirmation && type === 'checkbox' && value === true) {
      var popup_dialog = confirm(confirmation);
      if (popup_dialog == false) {
        $(this).prop('checked', false);
        e.preventDefault();
        return;
      }
    }

    if ($row.hasClass('group') && type === 'checkbox') {
      var group = $row.data('group');
      adt_show_or_hide_addtitional_setting_row(group, value);
    }

    // The value to put back if the save fails. A checkbox needs no record, so
    // only a select reaches the record here: a text input has its own save
    // button and does not use this handler.
    var previous_value = type === 'checkbox' ? !value : $button.data('adtSavedValue');

    // Send AJAX request to update the setting.
    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'adt_pfp_update_settings',
          security: nonce,
          setting: setting,
          type: type,
          value: value,
        },
      })
      .done(function (response) {
        if (response.success) {
          adt_pfp_remember_setting_value($button);
          toastr.success(response.data.message);
        } else {
          adt_pfp_restore_setting_value($button, previous_value);
          toastr.error(adt_pfp_get_error_message(response.data));
        }
      })
      .fail(function (jqXHR) {
        adt_pfp_restore_setting_value($button, previous_value);
        toastr.error(adt_pfp_get_error_message(adt_pfp_get_response_data(jqXHR)));
      })
      .always(function () {
        $button.prop('disabled', false);
        $button.removeClass('loading');
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

  /**
   * Store the current value of a setting field as its last saved value.
   *
   * @param {Object} $field Setting field.
   *
   * @return {void}
   */
  function adt_pfp_remember_setting_value($field) {
    var value = $field.is(':checkbox') ? $field.is(':checked') : $field.val();

    $field.data('adtSavedValue', value);
  }

  /**
   * Put a setting field back to its last saved value.
   *
   * The plugin saves each setting over AJAX without reloading the page, so a
   * failed save leaves the field showing a value the database never received.
   * This restores the field, to keep the page in step with the database.
   *
   * Only a checkbox or a select is restored. Text that the user typed stays on
   * screen, so the error message can be acted on without typing it again.
   *
   * @param {Object}         $field         Setting field.
   * @param {boolean|string} previous_value Value the field held before the save.
   *
   * @return {void}
   */
  function adt_pfp_restore_setting_value($field, previous_value) {
    var is_checkbox = $field.is(':checkbox');

    // A field added to the page after load has no record, and jQuery reads an
    // undefined value as an empty string, which would clear a select instead of
    // restoring it. Leave such a field alone.
    if (previous_value === undefined || (!is_checkbox && !$field.is('select'))) {
      return;
    }

    if (is_checkbox) {
      $field.prop('checked', previous_value);
    } else {
      $field.val(previous_value);
    }

    var $row = $field.closest('tr');

    if ($row.hasClass('group') && is_checkbox) {
      adt_show_or_hide_addtitional_setting_row($row.data('group'), previous_value);
    }
  }

  /**
   * Read the response body of a failed AJAX request.
   *
   * jQuery gives the .fail() callback the jqXHR object, not the parsed body,
   * so the body is only available when the server answered with JSON.
   *
   * @param {Object} jqXHR jqXHR object of the failed request.
   *
   * @return {string|Object|null} Response data, or null when there is none.
   */
  function adt_pfp_get_response_data(jqXHR) {
    if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data) {
      return jqXHR.responseJSON.data;
    }

    return null;
  }

  /**
   * Get the error message to show for a failed request.
   *
   * @param {string|Object|null} data Response data.
   *
   * @return {string} Error message.
   */
  function adt_pfp_get_error_message(data) {
    if (typeof data === 'string' && data) {
      return data;
    }

    if (data && data.message) {
      return data.message;
    }

    return __('The setting could not be saved. Please try again.', 'woo-product-feed-pro');
  }

  // Save Batch Size
  jQuery('.adt-pfp-save-setting-button').on('click', function (e) {
    e.preventDefault();

    var $col = $(this).closest('td');
    var $input = $col.find('input[type="text"], textarea');
    var $error = $col.find('.error-message');
    var id = $input.attr('id');
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
        regex = /^[0-9A-Za-z\s]*$/;
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

    // Disable the button
    var $button = $(this);
    $button.prop('disabled', true);
    $button.addClass('loading');

    // Set text to "Saving..." to the value attribute
    var originalText = $button.val();
    $button.val('Saving...');

    // Now we need to save the conversion ID so we can use it in the dynamic remarketing JS
    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'adt_pfp_update_settings',
          security: nonce,
          setting: setting,
          type: 'text',
          value: value,
        },
      })
      .done(function (response) {
        if (response.success) {
          toastr.success(response.data.message);
        } else {
          toastr.error(adt_pfp_get_error_message(response.data));
        }
      })
      .fail(function (jqXHR) {
        toastr.error(adt_pfp_get_error_message(adt_pfp_get_response_data(jqXHR)));
      })
      .always(function () {
        $button.prop('disabled', false);
        $button.val(originalText);
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
      var popup_dialog = confirm(__('Are you sure you want to copy this feed?', 'woo-product-feed-pro'));
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
      var popup_dialog = confirm(__('Are you sure you want to delete this feed?', 'woo-product-feed-pro'));
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
      var popup_dialog = confirm(__('Are you sure you want to cancel processing the feed?', 'woo-product-feed-pro'));
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
      var popup_dialog = confirm(__('Are you sure you want to refresh the product feed?', 'woo-product-feed-pro'));
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
          .done(function (response) {
            if (response.success) {
              const feed_id = response.data.feed_id || null;
              const offset = response.data.offset || 0;
              const batch_size = response.data.batch_size || 0;
              const executed_from = response.data.executed_from || 'cron';

              if (feed_id && executed_from === 'ajax') {
                woosea_generate_product_feed(feed_id, offset, batch_size);
              }

              if (!isRefreshRunning) {
                woosea_check_processing_feeds();
              }
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
    var popup_dialog = confirm(__('Are you sure you want to migrate your products to a custom post type?', 'woo-product-feed-pro'));
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
            toastr.success(response.data.message);
          } else {
            toastr.error('Migration failed');
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
    var popup_dialog = confirm(__('Are you sure you want to delete the custom attributes product meta keys cache?', 'woo-product-feed-pro'));
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
            toastr.success(response.data.message);
          } else {
            toastr.error(response.data.message);
          }
        })
        .fail(function (data) {
          // Enable the button
          $button.prop('disabled', false);
        });
    }
  });

  $('#adt_update_file_url_to_lower_case').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var popup_dialog = confirm(__('Are you sure you want to convert all feed file URLs to lowercase?', 'woo-product-feed-pro'));
    var $button = $(this);

    if (popup_dialog == true) {
      // Disable the button
      $button.prop('disabled', true);

      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'adt_update_file_url_to_lower_case',
            security: nonce,
          },
        })
        .done(function (response) {
          // Enable the button
          $button.prop('disabled', false);

          if (response.success) {
            toastr.success(response.data.message);
          } else {
            toastr.error(response.data.message);
          }
        })
        .fail(function (data) {
          // Enable the button
          $button.prop('disabled', false);
        });
    }
  });

  $('#adt_regenerate_action_schedulers').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var popup_dialog = confirm(__('Are you sure you want to regenerate the Action Scheduler feed refresh tasks for all active feeds?', 'woo-product-feed-pro'));
    var $button = $(this);

    if (popup_dialog == true) {
      // Disable the button
      $button.prop('disabled', true);

      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'adt_regenerate_action_schedulers',
            security: nonce,
          },
        })
        .done(function (response) {
          // Enable the button
          $button.prop('disabled', false);

          if (response.success) {
            toastr.success(response.data.message);
          } else {
            toastr.error(response.data.message);
          }
        })
        .fail(function (data) {
          // Enable the button
          $button.prop('disabled', false);
        });
    }
  });

  $('#adt_use_legacy_filters_and_rules').on('change', function (e) {
    var nonce = $('#_wpnonce').val();
    var value = $(this).is(':checked');

    if (value === true) {
      var popup_dialog = confirm(__('Are you sure you want to use legacy filters and rules?', 'woo-product-feed-pro'));
      if (popup_dialog == false) {
        $(this).prop('checked', false);
        e.preventDefault();
        return;
      }
    }

    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'adt_use_legacy_filters_and_rules',
          security: nonce,
          value: value,
        },
      })
      .done(function (response) {
        if (response.success) {
          toastr.success(response.data.message);
        } else {
          toastr.error(response.data.message);
        }
      })
      .fail(function (data) {
        toastr.error('An error occurred while using the legacy filters and rules. Please try again.');
      });
  });

  $('#adt_fix_duplicate_feed').on('click', function () {
    var nonce = $('#_wpnonce').val();
    var popup_dialog = confirm(__('Are you sure you want to fix the duplicated feed?', 'woo-product-feed-pro'));

    if (popup_dialog == true) {
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: {
            action: 'adt_fix_duplicate_feed',
            security: nonce,
          },
        })
        .done(function (response) {
          if (response.success) {
            toastr.success(response.data.message);
          } else {
            toastr.error(response.data.message);
          }
        })
        .fail(function (data) {
          toastr.error('An error occurred while fixing the duplicated feed. Please try again.');
        });
    }
  });

  $('#adt_pfp_anonymous_data').on('change', function (e) {
    var nonce = $('#_wpnonce').val();
    var value = $(this).is(':checked');

    if (value === true) {
      var popup_dialog = confirm(__('Are you sure you want to allow usage tracking?', 'woo-product-feed-pro'));
      if (popup_dialog === false) {
        $(this).prop('checked', false);
        e.preventDefault();
        return;
      }
    }

    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'adt_pfp_anonymous_data',
          security: nonce,
          value: value,
        },
      })
      .done(function (response) {
        if (response.success) {
          toastr.success(response.data.message);
        } else {
          toastr.error(response.data.message);
        }
      })
      .fail(function () {
        toastr.error('An error occurred while saving the anonymous data. Please try again.');
      });
  });

  // Unreachable: the markup that called this was removed. The endpoint now expects
  // `nonce` with the `adt_nonce` action, not `security`. See includes/Classes/Heartbeat.php.
  function woosea_generate_product_feed(feed_id, offset, batch_size) {
    var nonce = $('#_wpnonce').val();
    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'adt_pfp_generate_product_feed',
          security: nonce,
          feed_id: feed_id,
          offset: offset,
          batch_size: batch_size,
        },
      })
      .done(function (response) {
        if (response.success && response.data.status === 'processing') {
          woosea_generate_product_feed(response.data.feed_id, response.data.offset, response.data.batch_size);
        }
      });
  }

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
   *
   * @param {boolean} force - Whether to force the check.
   * @param {boolean} on_load - Whether the check is on load.
   */
  function woosea_check_processing_feeds(force = false, on_load = false) {
    var nonce = $('#_wpnonce').val();
    const hashes = woosea_get_processing_feeds();

    if ((!isRefreshRunning || !force) && hashes.length < 1) {
      isRefreshRunning = false;
      return;
    }

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

              if (on_load && feed.executed_from === 'ajax') {
                woosea_generate_product_feed(feed.feed_id, feed.offset, feed.batch_size);
              }
            } else {
              $status.removeClass('woo-product-feed-pro-blink_me');
              $row.removeClass('processing');
              $status.text(feed.status);
            }
          });
        }

        if (isRefreshRunning) {
          setTimeout(woosea_check_processing_feeds, 1000); // Check every second.
        }
      });
  }

  // Add copy to clipboard functionality for the debug information content box.
  new ClipboardJS('.copy-product-feed-pro-debug-info');

  /**
   * Generic "download text content as a file" click handler.
   *
   * Reads text from the element targeted by `data-download-target` (CSS selector),
   * wraps it in a UTF-8 plain-text Blob, and triggers a same-page download. The
   * filename is `data-filename-prefix` + UTC ISO timestamp + `.txt`.
   *
   * @param {jQuery.Event} e The click event.
   * @since 13.5.5
   */
  $(document).on('click', '.download-product-feed-pro-debug-info', function (e) {
    e.preventDefault();

    var $button = $(this);
    var targetSelector = $button.data('download-target');
    var $target = targetSelector ? $(targetSelector) : $();

    if (!$target.length) {
      return;
    }

    var content = $target.text();
    var prefix = $button.data('filename-prefix') || 'debug-log';
    // Include seconds in the timestamp so repeated downloads in the same day produce unique filenames.
    var timestamp = new Date().toISOString().slice(0, 19).replace(/:/g, '-') + 'Z';
    var filename = prefix + '-' + timestamp + '.txt';

    var blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);

    var $link = $('<a/>', { href: url, download: filename }).css('display', 'none').appendTo('body');
    $link[0].click();
    $link.remove();

    // Release the object URL after a tick so the download has time to start.
    setTimeout(function () { URL.revokeObjectURL(url); }, 100);
  });

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

  // Handle download button clicks for CSV, TSV, TXT, and JSONL files
  $(document).on('click', '.adt-manage-feeds-table-row-url-link-button[download]', function (e) {
    e.preventDefault();

    var $link = $(this);
    var fileUrl = $link.attr('href');
    var fileName = fileUrl.substring(fileUrl.lastIndexOf('/') + 1);

    // Show loading state
    var $icon = $link.find('.adt-manage-feeds-table-row-url-button-icon');
    var originalIconClass = $icon.attr('class');
    $icon.attr(
      'class',
      'adt-manage-feeds-table-row-url-button-icon adt-tw-icon-[lucide--loader-circle] adt-tw-animate-spin'
    );

    // Fetch the file and trigger download
    fetch(fileUrl)
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.blob();
      })
      .then(function (blob) {
        // Create a temporary URL for the blob
        var blobUrl = window.URL.createObjectURL(blob);

        // Create a temporary link and trigger download
        var tempLink = document.createElement('a');
        tempLink.href = blobUrl;
        tempLink.download = fileName;
        document.body.appendChild(tempLink);
        tempLink.click();

        // Clean up
        document.body.removeChild(tempLink);
        window.URL.revokeObjectURL(blobUrl);

        // Restore icon
        $icon.attr('class', originalIconClass);
      })
      .catch(function (error) {
        console.error('Download failed:', error);
        // Restore icon
        $icon.attr('class', originalIconClass);
        // Fallback: try opening in new tab
        window.open(fileUrl, '_blank');
      });
  });
});
