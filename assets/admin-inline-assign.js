jQuery(function($) {
  'use strict';

  var currentProductId = null;
  var $currentRow = null;
  var i18n = (typeof WRDInlineAssign !== 'undefined' && WRDInlineAssign.i18n) ? WRDInlineAssign.i18n : {};

  // Handle "Assign Profile" row action click
  $(document).on('click', '.wrd-assign-profile-action', function(e) {
    e.preventDefault();

    var $link = $(this);
    var productId = $link.data('product-id');
    var $productRow = $link.closest('tr');

    // Close any existing inline edit rows
    closeInlineAssign();

    // Clone the template
    var $template = $('#wrd-inline-assign-row').clone();
    $template.attr('id', 'wrd-inline-assign-' + productId);
    $template.data('product-id', productId);

    // Insert after current row
    $template.insertAfter($productRow);

    // Store references
    currentProductId = productId;
    $currentRow = $template;

    // Initialize autocomplete on the cloned row
    initAutocomplete($template);

    // Show the row with animation
    $template.show();
    $template.find('.wrd-profile-lookup').focus();

    // Highlight current product row
    $productRow.addClass('wrd-editing');
  });

  // Initialize autocomplete for a specific context
  function initAutocomplete($context) {
    $context.find('.wrd-profile-lookup').autocomplete({
      minLength: 2,
      source: function(req, resp) {
        $.getJSON(WRDInlineAssign.ajax, {
          action: 'wrd_search_profiles',
          nonce: WRDInlineAssign.searchNonce,
          term: req.term
        }, function(data) {
          resp(data || []);
        });
      },
      select: function(e, ui) {
        if (ui && ui.item) {
          $context.find('.wrd-hs-code').val(ui.item.hs || '');
          $context.find('.wrd-country').val(ui.item.cc || '');
          $(this).val(''); // Clear autocomplete field
        }
        return false;
      }
    });
  }

  // Handle Apply button
  $(document).on('click', '.wrd-apply-assign', function(e) {
    e.preventDefault();

    var $row = $(this).closest('tr');
    var productId = $row.data('product-id');
    var hsCode = $row.find('.wrd-hs-code').val().trim();
    var country = $row.find('.wrd-country').val().trim().toUpperCase();

    if (!hsCode || !country) {
      alert(i18n.missing_hs_country || 'Please enter both HS code and country code.');
      return;
    }

    var $spinner = $row.find('.spinner');
    var $button = $(this);

    // Show loading state
    $button.prop('disabled', true);
    $spinner.addClass('is-active');

    // Send AJAX request
    $.post(WRDInlineAssign.ajax, {
      action: 'wrd_quick_assign_profile',
      nonce: WRDInlineAssign.nonce,
      product_id: productId,
      hs_code: hsCode,
      country: country
    })
    .done(function(response) {
      if (response.success) {
        // Success - close row and reload the table row
        var $productRow = $row.prev('tr');
        closeInlineAssign();

        // Update the customs column if visible
        var $customsCell = $productRow.find('.column-wrd_customs');
        if ($customsCell.length) {
          var statusHtml = response.data.has_profile
            ? '<span style="color:#008a00;">' + hsCode + '</span> (' + country + ')'
            : '<span style="color:#d98300;">' + hsCode + '</span> (' + country + ')<br>No profile';
          $customsCell.html(statusHtml);
        }

        // Highlight the row briefly
        $productRow.css('background-color', '#d7f3d7');
        setTimeout(function() {
          $productRow.css('background-color', '');
        }, 1500);
      } else {
        alert((i18n.error_prefix || 'Error:') + ' ' + (response.data && response.data.message ? response.data.message : (i18n.unknown_error || 'Unknown error')));
        $button.prop('disabled', false);
        $spinner.removeClass('is-active');
      }
    })
    .fail(function() {
      alert((i18n.error_prefix || 'Error:') + ' ' + (i18n.assign_failed || 'Failed to assign profile. Please try again.'));
      $button.prop('disabled', false);
      $spinner.removeClass('is-active');
    });
  });

  // Handle Cancel button
  $(document).on('click', '.wrd-cancel-assign', function(e) {
    e.preventDefault();
    closeInlineAssign();
  });

  // Handle Escape key
  $(document).on('keyup', function(e) {
    if (e.key === 'Escape' && $currentRow) {
      closeInlineAssign();
    }
  });

  // Close inline assign row
  function closeInlineAssign() {
    if ($currentRow) {
      var $productRow = $currentRow.prev('tr');
      $productRow.removeClass('wrd-editing');
      $currentRow.remove();
      $currentRow = null;
      currentProductId = null;
    }
  }

  // Close inline edit when clicking outside
  $(document).on('click', function(e) {
    if ($currentRow && !$(e.target).closest('#wrd-inline-assign-' + currentProductId + ', .wrd-assign-profile-action').length) {
      // Don't close if clicking on autocomplete menu
      if (!$(e.target).closest('.ui-autocomplete').length) {
        closeInlineAssign();
      }
    }
  });
});
