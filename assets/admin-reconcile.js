(function($){
  var activeRulePickerProductId = 0;

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
    var profileId = parseInt($row.find('input.wrd-selected-profile-id').val(), 10) || 0;
    $row.find('input.wrd-cc').val(cc);
    $row.find('input.wrd-232-metal').val(metal);
    return { hs: hs, cc: cc, metal: metal, profileId: profileId };
  }

  function setRowBaseline($row, hs, cc, metal){
    $row.data('wrdInitialHs', normalizeHs(hs));
    $row.data('wrdInitialCc', normalizeCountryCode(cc));
    $row.data('wrdInitialMetal', normalizeMetal(metal));
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
      ($('#wrd-rcat').val() || 'all') !== 'all' ||
      ($('#wrd-rstock').val() || 'all') !== 'all';
    $('#wrd-reconcile-clear-filters').toggleClass('is-hidden', !hasFilters);
  }

  function syncBulkControls(){
    var action = $('#wrd-reconcile-bulk-action').val() || 'set_values';
    $('#wrd-reconcile-bulk-rule-field').toggleClass('is-hidden', action !== 'copy_rule');
  }

  function closeRowRulePicker(){
    activeRulePickerProductId = 0;
    $('#wrd-reconcile-rule-popover').removeClass('is-open').attr('aria-hidden', 'true');
    $('#wrd-reconcile-row-rule').val('');
  }

  function openRowRulePicker($button){
    var productId = parseInt($button.data('product'), 10) || 0;
    if (productId <= 0){
      return;
    }

    activeRulePickerProductId = productId;
    var $popover = $('#wrd-reconcile-rule-popover');
    var offset = $button.offset() || { top: 0, left: 0 };
    var popoverWidth = Math.min(420, Math.max(280, $(window).width() - 32));
    var desiredLeft = offset.left - 140;
    var maxLeft = Math.max(12, $(window).width() - popoverWidth - 12);
    var left = Math.min(Math.max(12, desiredLeft), maxLeft);
    var top = offset.top + $button.outerHeight() + 8;

    $popover.css({
      width: popoverWidth,
      top: top,
      left: left
    }).addClass('is-open').attr('aria-hidden', 'false');

    $('#wrd-reconcile-row-rule').val('').trigger('focus');
  }

  function attachAutocomplete(context){
    if (typeof $.fn.autocomplete !== 'function' || typeof WRDReconcile === 'undefined') {
      return;
    }

    $(context).find('#wrd-reconcile-row-rule, #wrd-reconcile-bulk-rule').each(function(){
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
          if ($input.attr('id') === 'wrd-reconcile-row-rule'){
            var $row = getRowByProductId(activeRulePickerProductId);
            if ($row.length){
              $row.find('input.wrd-hs').val(item.hs || '');
              $row.find('input.wrd-cc').val(item.cc || '');
              setRowRuleSelection($row, item);
              syncRowApplyState($row);
              setRowStatus($row, '', '');
            }
            closeRowRulePicker();
          } else {
            $('#wrd-reconcile-bulk-hs').val(item.hs || '');
            $('#wrd-reconcile-bulk-cc').val(normalizeCountryCode(item.cc || ''));
            setBulkRuleSelection(item);
            setBulkStatus('', '');
          }
          $input.val(item.label || item.value || '');
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
    var profileId = parseInt($('#wrd-reconcile-bulk-profile-id').val(), 10) || 0;
    var requires232 = String($('#wrd-reconcile-bulk-requires-232').val() || '') === '1';
    $('#wrd-reconcile-bulk-cc').val(cc);
    $('#wrd-reconcile-bulk-metal').val(metal);

    if (action === 'copy_rule' && profileId <= 0){
      setBulkStatus(WRDReconcile.i18n.chooseRule, 'error');
      return;
    }

    var validationError = validateValues(hs, cc);
    if (validationError){
      setBulkStatus(validationError, 'error');
      return;
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
      metal_value_232: metal
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

    $(document).on('click', '.wrd-rule-picker-toggle', function(event){
      event.preventDefault();
      openRowRulePicker($(this));
    });

    $(document).on('click', '#wrd-reconcile-rule-close', function(event){
      event.preventDefault();
      closeRowRulePicker();
    });

    $(document).on('mousedown', function(event){
      var $target = $(event.target);
      if (!$target.closest('#wrd-reconcile-rule-popover, .wrd-rule-picker-toggle, .ui-autocomplete').length){
        closeRowRulePicker();
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

    $(document).on('input', '.column-metal_232 input.wrd-232-metal', function(){
      syncRowApplyState($(this).closest('tr'));
    });

    $(document).on('input', '#wrd-reconcile-bulk-hs', function(){
      clearBulkRuleSelection();
    });

    $(document).on('input', '#wrd-reconcile-bulk-rule', function(){
      $('#wrd-reconcile-bulk-profile-id').val('0');
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

    $(document).on('change', '#wrd-rtype, #wrd-rsource, #wrd-rcat, #wrd-rstock', function(){
      syncFilterControls();
    });

    $(document).on('change', '#wrd-reconcile-bulk-action', function(){
      syncBulkControls();
      setBulkStatus('', '');
    });

    $(document).on('keydown', function(event){
      if (event.key === 'Escape'){
        closeRowRulePicker();
      }
    });

    syncFilterControls();
    syncBulkControls();
    updateSelectedCount();
  });
})(jQuery);
