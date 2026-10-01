jQuery(function ($) {
  var google_taxonomy = [];

  /**
   * Load the Google Taxonomy list.
   *
   * Read the cached file directly first, because that request costs no PHP.
   * Some hosts block HTTP requests to .txt files below /uploads/, so fall back
   * to the ajax endpoint when the direct request fails or answers with
   * something else. A blocked request never reaches WordPress, so the plugin
   * cannot detect the block on the server.
   */
  function loadTaxonomy() {
    // `dataType: 'text'` so the body always arrives as a string. Without it
    // jQuery picks the parser from the Content-Type, and a host that serves the
    // .txt as json or xml hands back an object that isTaxonomyData rejects - a
    // readable list would take the ajax path for no reason.
    $.ajax({ url: pfp_google_taxonomy.file_url, dataType: 'text' })
      .done(function (data) {
        if (isTaxonomyData(data)) {
          initTaxonomy(data);
        } else {
          loadTaxonomyViaAjax();
        }
      })
      .fail(function () {
        loadTaxonomyViaAjax();
      });
  }

  /**
   * Load the Google Taxonomy list through admin-ajax.php.
   *
   * WordPress core needs this path, so host hardening rules keep it reachable.
   */
  function loadTaxonomyViaAjax() {
    $.post(pfp_google_taxonomy.ajax_url, {
      action: pfp_google_taxonomy.ajax_action,
      nonce: pfp_google_taxonomy.nonce,
    })
      .done(function (response) {
        var data = response && response.data ? response.data : null;

        if (response && response.success && data && isTaxonomyData(data.taxonomy)) {
          initTaxonomy(data.taxonomy);
          return;
        }

        showError(data && data.message ? data.message : null);
      })
      .fail(function () {
        // An expired nonce answers 403 here, so this branch is reachable on a
        // page that stayed open for a long time.
        showError(null);
      });
  }

  /**
   * Tell the user that the category list did not load.
   *
   * The bug this replaces was silent: the field stayed empty and gave the user
   * and support nothing to go on. Show the reason instead.
   *
   * @param {?string} message The message from the server, if it sent one.
   */
  function showError(message) {
    var text = message || pfp_google_taxonomy.error;

    if (window.console) {
      window.console.error('Google product taxonomy: ' + text);
    }

    var $table = $('#woosea-ajax-mapping-table');
    if (!$table.length || $table.prev('.adt-google-taxonomy-error').length) {
      return;
    }

    // .text() rather than .html(), because the message can come from the server.
    // role="alert" so a screen reader hears the failure. Without it the notice is
    // silent for that user, which is the bug this branch fixes.
    var $notice = $('<div class="notice notice-error adt-google-taxonomy-error" role="alert"></div>');
    $notice.append($('<p></p>').text(text));
    $table.before($notice);
  }

  /**
   * Check that a response holds the taxonomy list.
   *
   * Some hosts answer a blocked request with an HTML error page and a 200
   * status, so the status code alone does not prove that the list arrived. The
   * text of such a page often holds a ` - ` separator of its own, so match the
   * shape of a taxonomy line instead: an id, then ` - `, then the category path.
   *
   * @param {*} data The response body.
   * @return {boolean} True if the body holds taxonomy lines.
   */
  function isTaxonomyData(data) {
    return typeof data === 'string' && /^\d+ - /m.test(data);
  }

  /**
   * Build the typeahead from the Google Taxonomy list.
   *
   * @param {string} data The taxonomy list.
   */
  function initTaxonomy(data) {
    var lines = data.split('\n');
    for (var i = 1; i < lines.length; i++) {
      // Start from index 1 to skip the first line
      google_taxonomy.push(lines[i]);
    }

    // Initialize Bloodhound after data is loaded
    var google_taxonomy_dataset = new Bloodhound({
      local: google_taxonomy,
      datumTokenizer: Bloodhound.tokenizers.whitespace,
      queryTokenizer: Bloodhound.tokenizers.whitespace,
    });

    // Initialize Typeahead
    $('.input-google-taxonomy').typeahead(
      {
        hint: true,
        highlight: true,
        minLength: 0,
      },
      {
        name: 'google_taxonomy_dataset',
        source: google_taxonomy_dataset,
      }
    );

    // Set the typeahead value to the selected value on load page.
    $('.input-google-taxonomy').each(function () {
      var $this = $(this);
      var value = $this.val();

      if (value) {
        // Find the selected value in the google_taxonomy array
        var selected = google_taxonomy.find(function (element) {
          element = element.split(' - ')[0];
          element = element.trim();

          return element === value;
        });

        if (selected) {
          $this.typeahead('val', selected);
        }
      }
    });

    $('.input-google-taxonomy').bind('typeahead:change', function (ev, suggestion) {
      var $hint = $(this).siblings('.tt-hint');
      var $input = $(this);
      var $hidden_input = $input.closest('td').find('.input-google-taxonomy-hidden-id');

      // Set the hidden input value.
      var val = $input.typeahead('val');
      val = val.split(' - ')[0].trim();
      $hidden_input.val(val);

      if (this.value === '') {
        $hint.removeClass('active');
        $(this).removeClass('active');
      } else {
        $hint.addClass('active');
        $(this).addClass('active');
      }
    });
  }

  loadTaxonomy();

  // Collect every mapping row into the single `feed_mappings` JSON field on submit.
  //
  // The rows keep their legacy `mappings[<term_id>][<key>]` names, but four fields
  // per product category put a store with 250 categories on PHP's default
  // `max_input_vars` of 1000. Past that limit PHP drops the remaining fields before
  // the plugin reads them, so the rows below the cutoff saved and the rest reverted
  // to empty with no warning. One JSON field costs one POST field whatever the
  // category count.
  //
  // The payload is a list of rows, not an object keyed by term ID: the row order
  // decides which mapping wins for a product that sits in two mapped categories, and
  // `JSON.stringify` sorts integer-like object keys into ascending numeric order.
  //
  // Collected inputs are disabled so the browser leaves them out of the request. They
  // keep their names and values, so a repeated submit collects the same payload, and
  // a browser that never runs this script still posts the legacy arrays.
  $('#category_mapping').on('submit', function () {
    var $form = $(this);
    var $payload = $form.find('#adt-feed-mappings');

    // No payload field means the page was rendered before this change. Leave the
    // legacy arrays enabled rather than posting nothing at all.
    if (!$payload.length) {
      return;
    }

    var rows = [];
    var by_term_id = Object.create(null);

    $form.find('input[name^="mappings["]').each(function () {
      var parts = /^mappings\[([^\]]+)\]\[([^\]]+)\]$/.exec(this.name);

      if (!parts) {
        return;
      }

      var term_id = parts[1];

      if (!by_term_id[term_id]) {
        by_term_id[term_id] = { id: term_id, data: {} };
        rows.push(by_term_id[term_id]);
      }

      by_term_id[term_id].data[parts[2]] = this.value;

      // Only disable what made it into the payload, so a field the pattern above
      // skips keeps posting the legacy way instead of disappearing from both.
      // Disabled inline rather than through a collected jQuery set: `.add()` re-runs
      // a document-order sort over the whole running set on every call, which grows
      // faster than the input count and would stall Save on the large catalogues
      // this fix exists for.
      this.disabled = true;
    });

    $payload.val(JSON.stringify(rows));
  });

  // Copy the selected Google Taxonomy category.
  $('.copy-google-taxonomy-category').on('click', function () {
    // The typeahead is live only after the list loads. This handler reads
    // `typeahead('val')` from every row it copies to, and that answers undefined
    // on an input the typeahead never bound, which throws below. The window was
    // one static request before the fallback; it is now that request, an ajax
    // round trip, and on a cold cache a remote read as well, and it never closes
    // when both transports fail.
    if (!google_taxonomy.length) {
      return;
    }

    var $tr = $(this).closest('tr.catmapping');
    var $input = $tr.find('.input-google-taxonomy.tt-input');

    var value = $input.typeahead('val'); // Get value from typeahead
    var is_parent = $(this).data('is_parent');
    var category_id = $input.data('category_id');

    var starting_row = $tr.index();
    var $rows = $tr.siblings('.catmapping');
    if (is_parent) {
      $rows = $rows.filter(function () {
        return $(this).find('.input-google-taxonomy.tt-input').data('parent') === category_id;
      });
    } else {
      $rows = $rows.filter(function () {
        return $(this).index() > starting_row;
      });
    }

    $rows.each(function () {
      var $input = $(this).find('.input-google-taxonomy.tt-input');
      var $hidden_input = $input.closest('td').find('.input-google-taxonomy-hidden-id');

      // Set the typeahead value.
      $input.typeahead('val', value);

      // Set the hidden input value.
      var val = $input.typeahead('val');
      val = val.split(' - ')[0].trim();
      $hidden_input.val(val);

      if (value) {
        $input.addClass('active');
        $input.siblings('.tt-hint').addClass('active');
      } else {
        $input.removeClass('active');
        $input.siblings('.tt-hint').removeClass('active');
      }
    });
  });
});
