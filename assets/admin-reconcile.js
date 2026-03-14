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

  function normalizeMetalForCompare(value){
    var normalized = normalizeMetal(value);
    return normalized === '' ? $.trim(value || '') : normalized;
  }

  function normalizeStockStatus(value){
    var stock = $.trim(value || '').toLowerCase();
    if (stock === 'instock' || stock === 'outofstock' || stock === 'onbackorder'){
      return stock;
    }
    return '';
  }

  function getRowByProductId(productId){
    return $('input.wrd-reconcile-select[value="' + productId + '"]').closest('tr');
  }

  function setRowStatus($row, message, state){
    var $status = $row.find('.column-hs_code .wrd-status');
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
    var profileId = parseInt($row.find('input.wrd-selected-profile-id').val(), 10) || 0;
    $row.find('input.wrd-cc').val(cc);
    return { hs: hs, cc: cc, metal: metal, profileId: profileId };
  }

  function setRowBaseline($row, hs, cc, metal){
    $row.data('wrdInitialHs', normalizeHs(hs));
    $row.data('wrdInitialCc', normalizeCountryCode(cc));
    $row.data('wrdInitialMetal', normalizeMetalForCompare(metal));
  }

  function clearRowRuleSelection($row){
    $row.find('input.wrd-selected-profile-id').val('0');
    $row.find('.wrd-requires-232').val('0');
  }

  function setRowRuleSelection($row, item){
    var profileId = parseInt(item && item.profile_id, 10) || 0;
    $row.find('input.wrd-selected-profile-id').val(String(profileId));
    $row.find('.wrd-requires-232').val(item && item.requires_232 ? '1' : '0');
  }

  function clearBulkRuleSelection(){
    $('#wrd-reconcile-bulk-profile-id').val('0');
    $('#wrd-reconcile-bulk-requires-232').val('0');
  }

  function setBulkRuleSelection(item){
    var profileId = parseInt(item && item.profile_id, 10) || 0;
    $('#wrd-reconcile-bulk-profile-id').val(String(profileId));
    $('#wrd-reconcile-bulk-requires-232').val(item && item.requires_232 ? '1' : '0');
  }

  function syncRowApplyState($row){
    var hs = normalizeHs($row.find('input.wrd-hs').val());
    var cc = normalizeCountryCode($row.find('input.wrd-cc').val());
    var metal = normalizeMetalForCompare($row.find('input.wrd-232-metal').val());
    $row.find('input.wrd-cc').val(cc);

    if (typeof $row.data('wrdInitialHs') === 'undefined'){
      setRowBaseline($row, hs, cc, metal);
    }

    var changed = hs !== String($row.data('wrdInitialHs')) || cc !== String($row.data('wrdInitialCc')) || metal !== String($row.data('wrdInitialMetal'));
    var $button = $row.find('.wrd-apply');
    $button.toggleClass('is-idle', !changed);
    $button.prop('disabled', !changed);

    if (!changed){
      var $status = $row.find('.column-hs_code .wrd-status');
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
    var $rows = $('input.wrd-reconcile-select');
    var count = $rows.filter(':checked').length;
    var total = $rows.length;
    $('.wrd-reconcile-selected-count strong').text(count);
    var showBulk = count > 0;
    $('.wrd-reconcile-select-all')
      .prop('checked', total > 0 && count === total)
      .prop('indeterminate', count > 0 && count < total);
    $('#wrd-reconcile-bulk-row').toggleClass('is-active', showBulk);
    $('#wrd-reconcile-filter-row').toggleClass('is-hidden', showBulk);
    if (!showBulk){
      setBulkStatus('', '');
    }
  }

  function syncFilterControls(){
    var hasFilters = ($('#wrd-rtype').val() || 'all') !== 'all' ||
      ($('#wrd-rsource').val() || 'all') !== 'all' ||
      ($('#wrd-rcat').val() || 'all') !== 'all' ||
      ($('#wrd-rstock').val() || 'all') !== 'all';
    $('#wrd-reconcile-clear-filters').toggleClass('is-hidden', !hasFilters);
  }

  function syncBulkControls(){
    var action = $('#wrd-reconcile-bulk-action').val() || 'set_values';
    $('#wrd-reconcile-bulk-rule-field').toggleClass('is-hidden', action !== 'copy_rule');
  }

  function closeRowRuleLookup($row){
    if (!$row || !$row.length){
      return;
    }
    $row.find('.wrd-rule-lookup-wrap').addClass('is-hidden');
    $row.find('.wrd-rule-toggle').attr('aria-expanded', 'false');
    $row.find('.wrd-rule-lookup').val('');
  }

  function openRowRuleLookup($row){
    if (!$row || !$row.length){
      return;
    }
    $('tr').find('.wrd-rule-lookup-wrap').addClass('is-hidden');
    $('tr').find('.wrd-rule-toggle').attr('aria-expanded', 'false');
    $row.find('.wrd-rule-lookup-wrap').removeClass('is-hidden');
    $row.find('.wrd-rule-toggle').attr('aria-expanded', 'true');
    $row.find('.wrd-rule-lookup').trigger('focus');
  }

  function attachAutocomplete(context){
    if (typeof $.fn.autocomplete !== 'function' || typeof WRDReconcile === 'undefined') {
      return;
    }

    $(context).find('.column-hs_code input.wrd-rule-lookup, #wrd-reconcile-bulk-rule').each(function(){
      var $input = $(this);
      if ($input.data('wrdBound')) {
        return;
      }
      $input.data('wrdBound', true);
      $input.autocomplete({
        minLength: 1,
        delay: 100,
        source: function(req, resp){
          $.getJSON(WRDReconcile.ajax, {
            action: 'wrd_search_profiles',
            nonce: WRDReconcile.searchNonce,
            term: req.term
          }, function(data){
            resp(data || []);
          });
        },
        select: function(event, ui){
          event.preventDefault();
          var item = ui.item || {};
          if ($input.attr('id') === 'wrd-reconcile-bulk-rule'){
            $('#wrd-reconcile-bulk-hs').val(item.hs || '');
            $('#wrd-reconcile-bulk-cc').val(normalizeCountryCode(item.cc || ''));
            setBulkRuleSelection(item);
            setBulkStatus('', '');
            $input.val(item.label || item.value || '');
          } else {
            var $row = $input.closest('tr');
            $row.find('input.wrd-hs').val(item.hs || '');
            $row.find('input.wrd-cc').val(normalizeCountryCode(item.cc || ''));
            setRowRuleSelection($row, item);
            syncRowApplyState($row);
            setRowStatus($row, '', '');
            closeRowRuleLookup($row);
          }
          return false;
        }
      });
    });
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
      profile_id: values.profileId,
      metal_value_232: values.metal
    }).done(function(resp){
      if (resp && resp.success){
        var data = resp.data || {};
        $row.find('input.wrd-hs').val(data.hs_code || values.hs);
        $row.find('input.wrd-cc').val(data.cc || values.cc);
        $row.find('input.wrd-selected-profile-id').val(String(parseInt(data.profile_id, 10) || 0));
        $row.find('.wrd-requires-232').val(data.requires_232 ? '1' : '0');
        setRowBaseline($row, data.hs_code || values.hs, data.cc || values.cc, values.metal);
        syncRowApplyState($row);
        setRowStatus($row, WRDReconcile.i18n.saved, 'success');
      } else {
        var message = (resp && resp.data && resp.data.message === 'rule_not_found') ? WRDReconcile.i18n.ruleNotFound : WRDReconcile.i18n.error;
        setRowStatus($row, message, 'error');
      }
    }).fail(function(xhr){
      var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message === 'rule_not_found') ? WRDReconcile.i18n.ruleNotFound : WRDReconcile.i18n.error;
      setRowStatus($row, message, 'error');
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

    var action = $('#wrd-reconcile-bulk-action').val() || '';
    if (!action){
      setBulkStatus(WRDReconcile.i18n.actionRequired, 'error');
      return;
    }

    var hs = $.trim($('#wrd-reconcile-bulk-hs').val());
    var cc = normalizeCountryCode($('#wrd-reconcile-bulk-cc').val());
    var metal = normalizeMetal($('#wrd-reconcile-bulk-metal').val());
    var stock = normalizeStockStatus($('#wrd-reconcile-bulk-stock').val());
    var profileId = parseInt($('#wrd-reconcile-bulk-profile-id').val(), 10) || 0;
    var requires232 = String($('#wrd-reconcile-bulk-requires-232').val() || '') === '1';
    $('#wrd-reconcile-bulk-cc').val(cc);

    if (action === 'copy_rule' && profileId <= 0){
      setBulkStatus(WRDReconcile.i18n.chooseRule, 'error');
      return;
    }

    if (action === 'set_values'){
      if ((hs && !cc) || (!hs && cc)){
        setBulkStatus(validateValues(hs, cc), 'error');
        return;
      }
      if (!hs && !cc && metal === '' && stock === ''){
        setBulkStatus(WRDReconcile.i18n.bulkValueRequired, 'error');
        return;
      }
    } else {
      var validationError = validateValues(hs, cc);
      if (validationError){
        setBulkStatus(validationError, 'error');
        return;
      }
    }
    if (requires232 && metal === ''){
      setBulkStatus(WRDReconcile.i18n.missing232, 'error');
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
      profile_id: profileId,
      metal_value_232: metal,
      stock_status: stock
    }).done(function(resp){
      if (resp && resp.success){
        setBulkStatus(WRDReconcile.i18n.bulkSaved.replace('%d', resp.data.updated || selected.length), 'success');
        window.location.reload();
      } else {
        var message = (resp && resp.data && resp.data.message === 'rule_not_found') ? WRDReconcile.i18n.ruleNotFound : WRDReconcile.i18n.error;
        setBulkStatus(message, 'error');
      }
    }).fail(function(xhr){
      var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message === 'rule_not_found') ? WRDReconcile.i18n.ruleNotFound : WRDReconcile.i18n.error;
      setBulkStatus(message, 'error');
    }).always(function(){
      $button.prop('disabled', false).removeClass('updating-message');
    });
  }

  $(function(){
    attachAutocomplete(document.body);

    $('input.wrd-reconcile-select').closest('tr').each(function(){
      syncRowApplyState($(this));
    });

    $(document).on('click', '.wrd-apply', function(){
      var productId = parseInt($(this).data('product'), 10);
      if (productId > 0){
        applySingle(productId);
      }
    });

    $(document).on('click', '.wrd-rule-toggle', function(event){
      event.preventDefault();
      var $row = $(this).closest('tr');
      var isOpen = !$row.find('.wrd-rule-lookup-wrap').hasClass('is-hidden');
      if (isOpen){
        closeRowRuleLookup($row);
      } else {
        openRowRuleLookup($row);
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
        clearRowRuleSelection($input.closest('tr'));
        syncRowApplyState($input.closest('tr'));
      } else {
        clearBulkRuleSelection();
      }
    });

    $(document).on('input', '.column-hs_code input.wrd-hs', function(){
      clearRowRuleSelection($(this).closest('tr'));
      syncRowApplyState($(this).closest('tr'));
    });

    $(document).on('keydown', '.column-hs_code input.wrd-rule-lookup', function(event){
      if (event.key === 'Escape'){
        event.preventDefault();
        closeRowRuleLookup($(this).closest('tr'));
      }
    });

    $(document).on('input', '.column-metal_232 input.wrd-232-metal', function(){
      syncRowApplyState($(this).closest('tr'));
    });

    $(document).on('input', '#wrd-reconcile-bulk-hs', function(){
      clearBulkRuleSelection();
    });

    $(document).on('input', '#wrd-reconcile-bulk-rule', function(){
      $('#wrd-reconcile-bulk-profile-id').val('0');
    });

    $(document).on('change click', '.wrd-reconcile-select-all', function(){
      var checked = $(this).is(':checked');
      $('input.wrd-reconcile-select').prop('checked', checked);
      $('.wrd-reconcile-select-all').not(this).prop('checked', checked).prop('indeterminate', false);
      updateSelectedCount();
    });

    $(document).on('change', '.wrd-reconcile-select', function(){
      updateSelectedCount();
    });

    $(document).on('mousedown', function(event){
      var $target = $(event.target);
      if (!$target.closest('.wrd-rule-lookup-wrap, .wrd-rule-toggle, .ui-autocomplete').length){
        $('tr').each(function(){
          closeRowRuleLookup($(this));
        });
      }
    });

    $(document).on('click', '#wrd-reconcile-bulk-apply', function(event){
      event.preventDefault();
      applyBulk();
    });

    $(document).on('change', '#wrd-rtype, #wrd-rsource, #wrd-rcat, #wrd-rstock', function(){
      syncFilterControls();
    });

    $(document).on('change', '#wrd-reconcile-bulk-action', function(){
      syncBulkControls();
      setBulkStatus('', '');
    });

    syncFilterControls();
    syncBulkControls();
    updateSelectedCount();
  });
})(jQuery);
