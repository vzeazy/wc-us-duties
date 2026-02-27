(function($){
  function normalizeCountryCode(value){
    return $.trim(value || '').toUpperCase();
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
    var hs = $.trim($row.find('input.wrd-hs').val());
    var cc = normalizeCountryCode($row.find('input.wrd-cc').val());
    $row.find('input.wrd-cc').val(cc);
    return { hs: hs, cc: cc };
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

  function updateSelectedCount(){
    var count = $('input.wrd-reconcile-select:checked').length;
    $('.wrd-reconcile-selected-count strong').text(count);
  }

  function applySingle(productId){
    var $row = getRowByProductId(productId);
    var values = getRowValues(productId);
    var validationError = validateValues(values.hs, values.cc);
    if (validationError){
      setRowStatus($row, validationError, 'error');
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
      cc: values.cc
    }).done(function(resp){
      if (resp && resp.success){
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
    $('#wrd-reconcile-bulk-cc').val(cc);

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
      cc: cc
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
    $(document).on('click', '.wrd-apply', function(){
      var productId = parseInt($(this).data('product'), 10);
      if (productId > 0){
        applySingle(productId);
      }
    });

    $(document).on('keydown', '.column-hs_code input.wrd-hs, .column-origin input.wrd-cc', function(event){
      if (event.key === 'Enter'){
        event.preventDefault();
        var productId = parseInt($(this).data('product'), 10);
        if (productId > 0){
          applySingle(productId);
        }
      }
    });

    $(document).on('input', '.column-origin input.wrd-cc, #wrd-reconcile-bulk-cc', function(){
      $(this).val(normalizeCountryCode($(this).val()));
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

    updateSelectedCount();
  });
})(jQuery);
