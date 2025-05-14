jQuery(document).ready(function ($) {
  jQuery('#shipping_zone').on('click', function () {
    var variations = $('#shipping_zone').is(':checked') ? 1 : 0;
    if (variations == '1') {
      jQuery
        .ajax({
          method: 'POST',
          url: ajaxurl,
          data: { action: 'woosea_shipping_zones' },
        })
        .done(function (data) {
          data = JSON.parse(data);
          $('#shipping_zones').after(
            '<tr id="select_shipping_zone"><td><i>Select shipping zone:</i><br/>You have multiple shipping zones configured for your shop. Do you want to add all Shipping zones to your product feed or just a one?</td><td valign="top"><select name="zone" class="select-field">' +
              data.dropdown +
              '</select></td></tr>'
          );
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
      'Google Shopping': ['xml'],
      'Fashionchick.nl': ['csv', 'txt'],
      'Bol.com': ['csv', 'txt'],
      'Snapchat Product Catalog': ['csv'],
    };
    const selected_channel = $table.find('#channel_hash option:selected').text();

    // If the channel has a specific format, replace the default formats with the specific ones
    const formats = channel_format[selected_channel] || ['xml', 'csv', 'txt', 'tsv'];

    for (var i = 0; i < formats.length; i++) {
      options += '<option value="' + formats[i] + '">' + formats[i].toUpperCase() + '</option>';
    }
    $fileformat.html(options);

    // Trigger file format change event
    $fileformat.trigger('change');
  });

  jQuery('#countries').on('change', function () {
    var country = this.value;
    var security = $('#_wpnonce').val();
    var td = $('#channel_hash').closest('td');

    var select = $('#channel_hash');
    select.empty();

    // Show loading indicator
    select.html('<option value="">Loading channels...</option>');

    // Add a spinner inside the td element
    if ($('#channel-loading-spinner').length === 0) {
      td.append(
        '<span id="channel-loading-spinner" class="spinner is-active" style="float:none;margin-left:5px;display:inline-block;"></span>'
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
          select.html('<option value="">Error loading channels</option>');
        }
      })
      .fail(function (response) {
        console.log('Failed AJAX Call :( /// Return Data: ' + response);
        // Add a default option if the AJAX call fails
        select.html('<option value="">Error loading channels</option>');
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

    if (fileformat == 'xml') {
      $('#delimiter').remove();
    } else {
      // Put delimiter dropdown back
      if ($('#delimiter').length == 0) {
        $('#file').after(
          '<tr id="delimiter"><td><span>Delimiter:</span></td><td><select name="delimiter" class="select-field"><option value=",">, comma</option><option value="|">| pipe</option><option value=";">;</option><option value="tab">tab</option><option value="#">#</option></select></td></tr>'
        );
      }
    }
  });

  var manage_fileformat = jQuery('#fileformat').val();
  var project_update = jQuery('#project_update').val();

  if (manage_fileformat == 'xml') {
    $('#delimiter').remove();
  }
});
