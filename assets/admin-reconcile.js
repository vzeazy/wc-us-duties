(function($){
  function normalizeHs(value){
    return $.trim(value || '');
  }

  function normalizeCountryCode(value){
    return $.trim(value || '').toUpperCase();
  }

  function normalizeMetal(value){
    var raw = $.trim(value || '');
    if (!raw) {
      return '';
    }
    var parsed = parseFloat(raw);
    if (!isFinite(parsed) || parsed < 0) {
      return '';
    }
    return String(parsed);
  }

  function getRowByProductId(productId){
    return $('input.wrd-reconcile-select[value="' + productId + '"]').closest('tr');
  }

  function setRowStatus($row, message, state){
    var $status = $row.find('.wrd-row-actions .wrd-status');
    $status.removeClass('is-error is-success is-info');
    if (state === 'error') {
      $status.addClass('is-error');
    } else if (state === 'success') {
      $status.addClass('is-success');
    } else if (state === 'info') {
      $status.addClass('is-info');
    }
    $status.text(message || '');
  }

  function setBulkStatus(message, state){
    var $status = $('.wrd-reconcile-bulk-status');
    $status.removeClass('is-error is-success is-info');
    if (state === 'error') {
      $status.addClass('is-error');
    } else if (state === 'success') {
      $status.addClass('is-success');
    } else if (state === 'info') {
      $status.addClass('is-info');
    }
    $status.text(message || '');
  }

  function getRowValues(productId){
    var $row = getRowByProductId(productId);
    var hs = normalizeHs($row.find('input.wrd-hs').val());
    var cc = normalizeCountryCode($row.find('input.wrd-cc').val());
    var metal = normalizeMetal($row.find('input.wrd-232-metal').val());
    $row.find('input.wrd-cc').val(cc);
    $row.find('input.wrd-232-metal').val(metal);
    return { hs: hs, cc: cc, metal: metal };
  }

  function setRowBaseline($row, hs, cc, metal){
    $row.data('wrdInitialHs', normalizeHs(hs));
    $row.data('wrdInitialCc', normalizeCountryCode(cc));
    $row.data('wrdInitialMetal', normalizeMetal(metal));
  }

  function syncRowApplyState($row){
    var hs = normalizeHs($row.find('input.wrd-hs').val());
    var cc = normalizeCountryCode($row.find('input.wrd-cc').val());
    var metal = normalizeMetal($row.find('input.wrd-232-metal').val());
    $row.find('input.wrd-cc').val(cc);
    $row.find('input.wrd-232-metal').val(metal);

    if (typeof $row.data('wrdInitialHs') === 'undefined'){
      setRowBaseline($row, hs, cc, metal);
    }

    var changed = hs !== String($row.data('wrdInitialHs')) || cc !== String($row.data('wrdInitialCc')) || metal !== String($row.data('wrdInitialMetal'));
    var $button = $row.find('.wrd-apply');
    $button.toggleClass('is-idle', !changed);
    $button.prop('disabled', !changed);

    if (!changed){
      var $status = $row.find('.wrd-row-actions .wrd-status');
      if (!$status.hasClass('is-error') && !$status.hasClass('is-info')){
        $status.text('');
      }
    }
  }

  function validateValues(hs, cc){
    if (!hs || !cc){
      return WRDReconcile.i18n.missing;
    }
    if (!/^[A-Z]{2}$/.test(cc)){
      return WRDReconcile.i18n.invalidCountry;
    }
    return '';
  }

  function rowRequires232($row){
    return String($row.find('.wrd-requires-232').val() || '') === '1';
  }

  function updateSelectedCount(){
    var count = $('input.wrd-reconcile-select:checked').length;
    $('.wrd-reconcile-selected-count strong').text(count);
    var showBulk = count > 0;
    $('#wrd-reconcile-bulk-row').toggleClass('is-active', showBulk);
    $('#wrd-reconcile-filter-row').toggleClass('is-hidden', showBulk);
    if (!showBulk){
      setBulkStatus('', '');
    }
  }

  function syncFilterControls(){
    var hasFilters = ($('#wrd-rtype').val() || 'all') !== 'all' ||
      ($('#wrd-rsource').val() || 'all') !== 'all' ||
      ($('#wrd-rcat').val() || 'all') !== 'all';
    $('#wrd-reconcile-clear-filters').toggleClass('is-hidden', !hasFilters);
  }

  function applySingle(productId){
    var $row = getRowByProductId(productId);
    if (!$row.length){
      return;
    }
    if ($row.find('.wrd-apply').prop('disabled')){
      return;
    }
    var values = getRowValues(productId);
    var validationError = validateValues(values.hs, values.cc);
    if (validationError){
      setRowStatus($row, validationError, 'error');
      return;
    }
    if (rowRequires232($row) && values.metal === ''){
      setRowStatus($row, WRDReconcile.i18n.missing232, 'error');
      return;
    }

    var $button = $row.find('.wrd-apply');
    $button.prop('disabled', true).addClass('updating-message');
    setRowStatus($row, WRDReconcile.i18n.saving, 'info');

    $.post(WRDReconcile.ajax, {
      action: 'wrd_reconcile_assign',
      nonce: WRDReconcile.nonce,
      product_id: productId,
      hs_code: values.hs,
      cc: values.cc,
      metal_value_232: values.metal
    }).done(function(resp){
      if (resp && resp.success){
        setRowBaseline($row, values.hs, values.cc, values.metal);
        syncRowApplyState($row);
        setRowStatus($row, WRDReconcile.i18n.saved, 'success');
      } else {
        setRowStatus($row, WRDReconcile.i18n.error, 'error');
      }
    }).fail(function(){
      setRowStatus($row, WRDReconcile.i18n.error, 'error');
    }).always(function(){
      $button.prop('disabled', false).removeClass('updating-message');
    });
  }

  function applyBulk(){
    var selected = $('input.wrd-reconcile-select:checked').map(function(){
      return parseInt($(this).val(), 10);
    }).get().filter(function(id){
      return id > 0;
    });

    if (!selected.length){
      setBulkStatus(WRDReconcile.i18n.noSelection, 'error');
      return;
    }

    var hs = $.trim($('#wrd-reconcile-bulk-hs').val());
    var cc = normalizeCountryCode($('#wrd-reconcile-bulk-cc').val());
    var metal = normalizeMetal($('#wrd-reconcile-bulk-metal').val());
    $('#wrd-reconcile-bulk-cc').val(cc);
    $('#wrd-reconcile-bulk-metal').val(metal);

    var validationError = validateValues(hs, cc);
    if (validationError){
      setBulkStatus(validationError, 'error');
      return;
    }

    var $button = $('#wrd-reconcile-bulk-apply');
    $button.prop('disabled', true).addClass('updating-message');
    setBulkStatus(WRDReconcile.i18n.bulkSaving, 'info');

    $.post(WRDReconcile.ajax, {
      action: 'wrd_reconcile_assign_bulk',
      nonce: WRDReconcile.nonce,
      product_ids: selected,
      hs_code: hs,
      cc: cc,
      metal_value_232: metal
    }).done(function(resp){
      if (resp && resp.success){
        setBulkStatus(WRDReconcile.i18n.bulkSaved.replace('%d', resp.data.updated || selected.length), 'success');
        window.location.reload();
      } else {
        setBulkStatus(WRDReconcile.i18n.error, 'error');
      }
    }).fail(function(){
      setBulkStatus(WRDReconcile.i18n.error, 'error');
    }).always(function(){
      $button.prop('disabled', false).removeClass('updating-message');
    });
  }

  $(function(){
    $('input.wrd-reconcile-select').closest('tr').each(function(){
      syncRowApplyState($(this));
    });

    $(document).on('click', '.wrd-apply', function(){
      var productId = parseInt($(this).data('product'), 10);
      if (productId > 0){
        applySingle(productId);
      }
    });

    $(document).on('keydown', '.column-hs_code input.wrd-hs, .column-origin input.wrd-cc, .column-metal_232 input.wrd-232-metal', function(event){
      if (event.key === 'Enter'){
        event.preventDefault();
        var productId = parseInt($(this).data('product'), 10);
        if (productId > 0){
          applySingle(productId);
        }
      }
    });

    $(document).on('input', '.column-origin input.wrd-cc, #wrd-reconcile-bulk-cc', function(){
      var $input = $(this);
      $input.val(normalizeCountryCode($input.val()));
      if ($input.hasClass('wrd-cc')){
        syncRowApplyState($input.closest('tr'));
      }
    });

    $(document).on('input', '.column-hs_code input.wrd-hs', function(){
      syncRowApplyState($(this).closest('tr'));
    });

    $(document).on('input', '.column-metal_232 input.wrd-232-metal', function(){
      syncRowApplyState($(this).closest('tr'));
    });

    $(document).on('change', '.wrd-reconcile-select, .wrd-reconcile-select-all', function(){
      if ($(this).hasClass('wrd-reconcile-select-all')){
        $('input.wrd-reconcile-select').prop('checked', $(this).is(':checked'));
      }
      updateSelectedCount();
    });

    $(document).on('click', '#wrd-reconcile-bulk-apply', function(event){
      event.preventDefault();
      applyBulk();
    });

    $(document).on('change', '#wrd-rtype, #wrd-rsource, #wrd-rcat', function(){
      syncFilterControls();
    });

    syncFilterControls();
    updateSelectedCount();
  });
})(jQuery);
