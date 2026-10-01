jQuery(document).ready(function ($) {
  const { __ } = wp.i18n;

  jQuery('#shipping_zone').on('click', function () {
    var variations = $('#shipping_zone').is(':checked') ? 1 : 0;
    if (variations == '1') {
      var security = $('form.adt-edit-feed-form#general #_wpnonce').val();
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: { action: 'woosea_shipping_zones', security: security },
        })
        .done(function (data) {
          // A refused request answers with { success: false } and no dropdown.
          if (!data || !data.dropdown) {
            console.error('Invalid response format:', data);
            return;
          }

          const selectShippingZoneHtml = [
            '<tr id="select_shipping_zone">',
            '  <td>',
            '    <i>' + __('Select shipping zone:', 'woo-product-feed-pro') + '</i><br/>',
            '    ' +
              __(
                'You have multiple shipping zones configured for your shop. Do you want to add all Shipping zones to your product feed or just a one?',
                'woo-product-feed-pro',
              ),
            '  </td>',
            '  <td valign="top">',
            '    <select name="zone" class="select-field">' + data.dropdown + '</select>',
            '  </td>',
            '</tr>',
          ].join('');
          $('#shipping_zones').after(selectShippingZoneHtml);
        })
        .fail(function (data) {
          console.log('Failed AJAX Call :( /// Return Data: ' + data);
        });
    } else {
      $('#select_shipping_zone').remove();
    }
  });

  jQuery('#channel_hash').on('change', function () {
    var $table = $('table.woo-product-feed-pro-table');
    var $fileformat = $table.find('tr#file select#fileformat');

    var options = '';
    var channel_format = {
      'Google Remarketing - DRM': ['csv'],
      'Guenstiger.de': ['csv'],
      'Google - DSA': ['csv'],
      'Wish.com': ['csv'],
      'Google Local Products Inventory': ['xml', 'txt'],
      'Google Shopping': ['xml', 'csv', 'txt', 'tsv'],
      'Fashionchick.nl': ['csv', 'txt'],
      'Bol.com': ['csv', 'txt'],
      'Snapchat Product Catalog': ['csv'],
      // Parquet is listed first on purpose. A new feed starts on 'xml', which no
      // OpenAI format matches, so the restore below is skipped and the select lands
      // on the first entry - making this order the default format for a new OpenAI
      // feed. Parquet is the format the OpenAI spec prefers, so keep it first.
      'OpenAI Product Feed': ['parquet', 'jsonl.gz', 'csv.gz', 'tsv.gz'],
      'Amazon': ['tsv', 'txt', 'csv'],
      'Merkandi': ['xml'],
    };
    const selected_channel = $table.find('#channel_hash option:selected').text();

    // If the channel has a specific format, replace the default formats with the specific ones
    let formats = channel_format[selected_channel] || ['xml', 'csv', 'txt', 'tsv', 'jsonl'];

    // Parquet needs PHP extensions the host may not have. Drop the option rather than
    // let a merchant pick a format that would write an unreadable file, and show the
    // reason next to the dropdown so the absence is explained rather than silent.
    // Default to unsupported when the localized params are missing: a failed
    // wp_localize_script must not offer a format the host cannot write.
    const parquet_supported =
      typeof woosea_channel_params !== 'undefined' &&
      woosea_channel_params.parquet_supported === '1';
    const $parquet_notice = $('#parquet-unsupported-notice');

    if (!parquet_supported && formats.includes('parquet')) {
      formats = formats.filter(function (format) {
        return format !== 'parquet';
      });
      $parquet_notice.show();
    } else {
      $parquet_notice.hide();
    }

    // Get the currently selected format before replacing options
    var current_format = $fileformat.val();

    for (var i = 0; i < formats.length; i++) {
      options += '<option value="' + formats[i] + '">' + formats[i].toUpperCase() + '</option>';
    }
    $fileformat.html(options);

    // Restore the previously selected format if it's still available
    if (formats.includes(current_format)) {
      $fileformat.val(current_format);
    }

    // Trigger file format change event
    $fileformat.trigger('change');
  });

  jQuery('#countries').on('change', function () {
    var country = this.value;
    var security = $('form.adt-edit-feed-form#general #_wpnonce').val();
    var td = $('#channel_hash').closest('td');
    var is_new_feed = $(this).data('is_new_feed');

    // Prevent retrieving channels for existing feeds.
    if (is_new_feed == false) {
      return;
    }

    var select = $('#channel_hash');
    select.empty();

    // Show loading indicator
    select.html('<option value="">' + __('Loading channels...', 'woo-product-feed-pro') + '</option>');

    // Add a spinner inside the td element
    if ($('#channel-loading-spinner').length === 0) {
      td.append(
        '<span id="channel-loading-spinner" class="spinner is-active" style="float:none;margin-left:5px;display:inline-block;"></span>',
      );
    }

    // Disable the select while loading channels
    select.prop('disabled', true);

    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: { action: 'woosea_print_channels', country: country, security: security },
      })

      .done(function (response) {
        // Check if data is successful and contains HTML
        if (response.success && response.data) {
          // Append the HTML content to the select element
          select.html(response.data);

          // Reinitialize select2 if it's being used
          if (select.hasClass('woo-sea-select2')) {
            select.select2({
              containerCssClass: 'woo-sea-select2-selection',
            });
          }
        } else {
          console.error('Invalid response format:', response);
          // Add a default option if the response is invalid
          select.html('<option value="">' + __('Error loading channels', 'woo-product-feed-pro') + '</option>');
        }
      })
      .fail(function (response) {
        console.log('Failed AJAX Call :( /// Return Data: ' + response);
        // Add a default option if the AJAX call fails
        select.html('<option value="">' + __('Error loading channels', 'woo-product-feed-pro') + '</option>');
      })
      .always(function () {
        // Re-enable the select after loading channels
        select.prop('disabled', false);

        // Remove the spinner
        $('#channel-loading-spinner').remove();
      });
  });

  jQuery('#fileformat').on('change', function () {
    var fileformat = this.value;
    var $table = $('table.woo-product-feed-pro-table');

    if (fileformat == 'xml' || fileformat == 'jsonl') {
      $table.find('#delimiter').remove();
    } else {
      // Put delimiter dropdown back
      if ($table.find('#delimiter').length == 0) {
        $table.find('#file').after(
          '<tr id="delimiter"><td><span>' +
            __('Delimiter:', 'woo-product-feed-pro') +
            '</span></td><td><select name="delimiter" class="select-field"><option value=",">, comma</option><option value="|">| pipe</option><option value=";">;</option><option value="tab">tab</option><option value="#">#</option></select></td></tr>',
        );
      }

      // TSV is tab-separated by definition — auto-select tab.
      if (fileformat == 'tsv') {
        $table.find('#delimiter select[name="delimiter"]').val('tab');
      }
    }
  });

  var manage_fileformat = jQuery('#fileformat').val();
  var project_update = jQuery('#project_update').val();

  if (manage_fileformat == 'xml' || manage_fileformat == 'jsonl') {
    $('#delimiter').remove();
  }

  // Trigger channel change on page load to filter formats appropriately
  if (jQuery('#channel_hash').length) {
    jQuery('#channel_hash').trigger('change');
  }
});
