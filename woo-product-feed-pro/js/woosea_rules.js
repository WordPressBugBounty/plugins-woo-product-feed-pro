jQuery(document).ready(function ($) {
  // Add standard filters
  jQuery('.add-filter').on('click', function () {
    var nonce = $('#_wpnonce').val();
    // Count amount of rows, used to create the form array field and values
    var TrueRowCount = $('#woosea-ajax-table >tbody >tr').length - 1;
    var rowCount = Math.round(new Date().getTime() + Math.random() * 100);

    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_ajax_get_attributes',
          type: 'html',
          security: nonce,
        },
      })
      .done(function (response) {
        if (!response.success) {
          console.log('Error: ' + response.message);
          return;
        }

        if (TrueRowCount == 0) {
          $('#woosea-ajax-table')
            .find('tbody:first')
            .append(
              '<tr><td><input type="hidden" name="rules[' +
                rowCount +
                '][rowCount]" value="' +
                rowCount +
                '"><input type="checkbox" name="record" class="checkbox-field"></td><td><i>Filter:</i></td><td><select name="rules[' +
                rowCount +
                '][attribute]" id="rules_' +
                rowCount +
                '" class="select-field woo-sea-select2">' +
                response.data +
                '</select></td><td><select name="rules[' +
                rowCount +
                '][condition]" class="select-field woo-sea-select2"><option value="contains">contains</option><option value="containsnot">does not contain</option><option value="=">is equal to</option><option value="!=">is not equal to</option><option value=">">is greater than</option><option value=">=">is greater or equal to</option><option value="<">is less than</option><option value="=<">is less or equal to</option><option value="empty">is empty</option><option value="notempty">is not empty</option></select></td><td><input type="text" name="rules[' +
                rowCount +
                '][criteria]" class="input-field-large" id="criteria_' +
                rowCount +
                '"></td><td><input type="checkbox" name="rules[' +
                rowCount +
                '][cs]" class="checkbox-field" alt="Case sensitive"></td><td><select name="rules[' +
                rowCount +
                '][than]" class="select-field"><optgroup label="Action">Action:<option value="exclude"> Exclude</option><option value="include_only">Include only</option></optgroup></select></td><td>&nbsp;</td></tr>'
            );
        } else {
          $(
            '<tr><td><input type="hidden" name="rules[' +
              rowCount +
              '][rowCount]" value="' +
              rowCount +
              '"><input type="checkbox" name="record" class="checkbox-field"></td><td><i>Filter:</i></td><td><select name="rules[' +
              rowCount +
              '][attribute]" id="rules_' +
              rowCount +
              '" class="select-field woo-sea-select2">' +
              response.data +
              '</select></td><td><select name="rules[' +
              rowCount +
              '][condition]" class="select-field woo-sea-select2"><option value="contains">contains</option><option value="containsnot">does not contain</option><option value="=">is equal to</option><option value="!=">is not equal to</option><option value=">">is greater than</option><option value=">=">is greater or equal to</option><option value="<">is less than</option><option value="=<">is less or equal to</option><option value="empty">is empty</option><option value="notempty">is not empty</option></select></td><td><input type="text" name="rules[' +
              rowCount +
              '][criteria]" class="input-field-large" id="criteria_' +
              rowCount +
              '"></td><td><input type="checkbox" name="rules[' +
              rowCount +
              '][cs]" class="checkbox-field" alt="Case sensitive"></td><td><select name="rules[' +
              rowCount +
              '][than]" class="select-field"><optgroup label="Action">Action:<option value="exclude"> Exclude</option><option value="include_only">Include only</option></optgroup></select></td><td>&nbsp;</td></tr>'
          ).insertBefore('.rules-buttons');
        }

        // Initialize select2 for the new row
        $(document.body).trigger('init_woosea_select2');

        // Check if user selected a data manipulation condition
        jQuery('#rules_' + rowCount).on('change', function () {
          if ($(this).val() == 'categories') {
            var feed_id = $(this).closest('form#rulesandfilters').find('#feed_id').val();
            var checkNumeric = $.isNumeric(rowCount);
            if (checkNumeric) {
              jQuery
                .ajax({
                  method: 'POST',
                  url: ajaxurl,
                  data: {
                    action: 'woosea_categories_dropdown',
                    feed_id: feed_id,
                    rowCount: rowCount,
                  },
                })

                .done(function (data) {
                  data = JSON.parse(data);
                  jQuery('#criteria_' + rowCount).replaceWith('' + data.dropdown + '');
                });
            }
          }
        });
      })
      .fail(function (data) {
        console.log('Failed AJAX Call :( /// Return Data: ' + data);
      });
  });

  // Add rules
  jQuery('.add-rule').on('click', function () {
    var nonce = $('#_wpnonce').val();
    // Count amount of rows, used to create the form array field and values
    var TrueRowCount = $('#woosea-ajax-table >tbody >tr').length - 1;
    var rowCount = Math.round(new Date().getTime() + Math.random() * 100);

    jQuery
      .ajax({
        method: 'POST',
        url: ajaxurl,
        data: {
          action: 'woosea_ajax_get_attributes',
          type: 'html',
          security: nonce,
        },
      })
      .done(function (response) {
        if (!response.success) {
          console.log('Error: ' + response.message);
          return;
        }

        if (TrueRowCount == 0) {
          $('#woosea-ajax-table')
            .find('tbody:first')
            .append(
              '<tr><td><input type="hidden" name="rules2[' +
                rowCount +
                '][rowCount]" value="' +
                rowCount +
                '"><input type="checkbox" name="record" class="checkbox-field"></td><td><i>Rule:</i></td><td><select name="rules2[' +
                rowCount +
                '][attribute]" class="select-field woo-sea-select2">' +
                response.data +
                '</select></td><td><select name="rules2[' +
                rowCount +
                '][condition]" class="select-field woo-sea-select2"  id="condition_' +
                rowCount +
                '""><option value="contains">contains</option><option value="containsnot">does not contain</option><option value="=">is equal to</option><option value="!=">is not equal to</option><option value=">">is greater than</option><option value=">=">is greater or equal to</option><option value="<">is less than</option><option value="=<">is less or equal to</option><option value="empty">is empty</option><option value="multiply">multiply</option><option value="divide">divide</option><option value="plus">plus</option><option value="minus">minus</option><option value="findreplace">find and replace</option></select></td><td><input type="text" name="rules2[' +
                rowCount +
                '][criteria]" class="input-field-large"></td><td><input type="checkbox" name="rules2[' +
                rowCount +
                '][cs]" class="checkbox-field" alt="Case sensitive" id="cs_' +
                rowCount +
                '"></td><td><select name="rules2[' +
                rowCount +
                '][than_attribute]" class="select-field woo-sea-select2" id="than_attribute_' +
                rowCount +
                '" style="width:300px;">' +
                response.data +
                '</select> </td><td><input type="text" name="rules2[' +
                rowCount +
                '][newvalue]" class="input-field-large" id="is-field_' +
                rowCount +
                '"></td></tr>'
            );
        } else {
          $(
            '<tr><td><input type="hidden" name="rules2[' +
              rowCount +
              '][rowCount]" value="' +
              rowCount +
              '"><input type="checkbox" name="record" class="checkbox-field"></td><td><i>Rule:</i></td><td><select name="rules2[' +
              rowCount +
              '][attribute]" class="select-field woo-sea-select2">' +
              response.data +
              '</select></td><td><select name="rules2[' +
              rowCount +
              '][condition]" class="select-field woo-sea-select2"  id="condition_' +
              rowCount +
              '""><option value="contains">contains</option><option value="containsnot">does not contain</option><option value="=">is equal to</option><option value="!=">is not equal to</option><option value=">">is greater than</option><option value=">=">is greater or equal to</option><option value="<">is less than</option><option value="=<">is less or equal to</option><option value="empty">is empty</option><option value="multiply">multiply</option><option value="divide">divide</option><option value="plus">plus</option><option value="minus">minus</option><option value="findreplace">find and replace</option></select></td><td><input type="text" name="rules2[' +
              rowCount +
              '][criteria]" class="input-field-large"></td><td><input type="checkbox" name="rules2[' +
              rowCount +
              '][cs]" class="checkbox-field" alt="Case sensitive" id="cs_' +
              rowCount +
              '"></td><td><select name="rules2[' +
              rowCount +
              '][than_attribute]" class="select-field woo-sea-select2" id="than_attribute_' +
              rowCount +
              '" style="width:150px;">' +
              response.data +
              '</select> </td><td><input type="text" name="rules2[' +
              rowCount +
              '][newvalue]" class="input-field-large" id="is-field_' +
              rowCount +
              '"></td></tr>'
          ).insertBefore('.rules-buttons');
        }

        // Initialize select2 for the new row
        $(document.body).trigger('init_woosea_select2');

        // Check if user selected a data manipulation condition
        jQuery('#condition_' + rowCount).on('change', function () {
          var manipulators = ['multiply', 'divide', 'plus', 'minus'];
          var cond = $(this).val();

          // User selected a data manipulation value so remove some input fields
          if (jQuery.inArray(cond, manipulators) != -1) {
            jQuery('#than_attribute_' + rowCount).remove();
            jQuery('#is-field_' + rowCount).remove();
            jQuery('#cs_' + rowCount).remove();
          }

          // Replace pieces of string
          var modifiers = ['replace'];
          if (jQuery.inArray(cond, modifiers) != -1) {
            jQuery('#than_attribute_' + rowCount).remove();
            jQuery('#cs_' + rowCount).remove();
          }
        });

        // Check if user created  a Google category rule
        jQuery('#than_attribute_' + rowCount).on('change', function () {
          if ($(this).val() == 'google_category') {
            var rownr = $(this).closest('tr').prevAll('tr').length;

            $('#is-field_' + rowCount).replaceWith(
              '<input type="search" name="rules2[' +
                rowCount +
                '][newvalue]" class="input-field-large js-typeahead js-autosuggest autocomplete_' +
                rowCount +
                '">'
            );

            jQuery('.js-autosuggest').on('click', function () {
              var rowCount = $(this).closest('tr').prevAll('tr').length;

              jQuery('.autocomplete_' + rowCount).typeahead({
                input: '.js-autosuggest',
                source: google_taxonomy,
                hint: true,
                loadingAnimation: true,
                items: 10,
                minLength: 2,
                alignWidth: false,
                debug: true,
              });
              jQuery('.autocomplete_' + rowCount).focus();

              jQuery(this).keyup(function () {
                var minimum = 5;
                var len = jQuery(this).val().length;
                if (len >= minimum) {
                  jQuery(this).closest('input').removeClass('input-field-large');
                  jQuery(this).closest('input').addClass('input-field-large-active');
                } else {
                  jQuery(this).closest('input').removeClass('input-field-large-active');
                  jQuery(this).closest('input').addClass('input-field-large');
                }
              });

              jQuery(this).click(function () {
                var len = jQuery(this).val().length;
                if (len < 1) {
                  jQuery(this).closest('input').removeClass('input-field-large-active');
                  jQuery(this).closest('input').addClass('input-field-large');
                }
              });
            });
          }
        });
      })
      .fail(function (data) {
        console.log('Failed AJAX Call :( /// Return Data: ' + data);
      });
  });

  // Find and remove selected table rows
  jQuery('.delete-row').on('click', function () {
    //$("table tbody").find('input[name="record"]').each(function(){
    $('.woo-product-feed-pro-body')
      .find('input[name="record"]')
      .each(function () {
        if ($(this).is(':checked')) {
          $(this).closest('tr').remove();
        }
      });
  });
});
