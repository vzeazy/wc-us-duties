(function($){
  function getI18n(key, fallback){
    return (window.WRDProfiles && WRDProfiles.i18n && WRDProfiles.i18n[key]) ? WRDProfiles.i18n[key] : fallback;
  }

  function attachAutocomplete(context){
    $(context).find('input.wrd-profile-lookup').each(function(){
      var $input = $(this);
      if ($input.data('wrdBound')) return;
      $input.data('wrdBound', true);
      $input.autocomplete({
        minLength: 2,
        source: function(req, resp){
          $.getJSON(WRDProfiles.ajax, { action: 'wrd_search_profiles', nonce: WRDProfiles.nonce, term: req.term }, function(data){
            resp(data || []);
          });
        },
        select: function(e, ui){
          var item = ui.item || {};
          var $wrap = $input.closest('.inline-edit-col, .wrd-customs-inline, .inline-edit-row');
          $wrap.find('input[name="wrd_hs_code"]').val(item.hs || '');
          $wrap.find('input[name="wrd_country_of_origin"]').val(item.cc || '');
          $wrap.find('input[name="wrd_customs_description"]').val(item.desc || '');
        }
      });
    });
  }

  $(document).on('click', 'a.editinline', function(){
    // Quick edit opened
    setTimeout(function(){ attachAutocomplete('#the-list tr.inline-editor'); }, 50);
  });

  // Bulk edit: when Bulk Edit panel appears
  $(document).on('bulk_edit_loaded', function(){
    attachAutocomplete('#bulk-edit');
    attachAutocomplete('#woocommerce-fields-bulk');
  });

  // Fallback: initial bind
  $(function(){
    attachAutocomplete(document.body);

    function syncActionFields($context){
      var hsAction = ($context.find('select[name="wrd_hs_action"]').val() || '');
      var originAction = ($context.find('select[name="wrd_origin_action"]').val() || '');
      var descAction = ($context.find('select[name="wrd_desc_action"]').val() || '');
      var metalAction = ($context.find('select[name="wrd_232_metal_action"]').val() || '');
      var modeAction = ($context.find('select[name="wrd_232_mode_action"]').val() || '');

      $context.find('input.wrd-bulk-value-hs').closest('label').toggle(hsAction === 'set');
      $context.find('input.wrd-bulk-value-origin').closest('label').toggle(originAction === 'set');
      $context.find('input.wrd-bulk-value-desc').closest('label').toggle(descAction === 'set');
      $context.find('input.wrd-bulk-value-metal').closest('label').toggle(metalAction === 'set');
      $context.find('select.wrd-bulk-value-mode').closest('label').toggle(modeAction === 'set');
    }

    $(document).on('change', 'select[name="wrd_hs_action"], select[name="wrd_origin_action"], select[name="wrd_desc_action"], select[name="wrd_232_metal_action"], select[name="wrd_232_mode_action"]', function(){
      syncActionFields($(this).closest('.inline-edit-row, #bulk-edit, #woocommerce-fields-bulk'));
    });

    $(document).on('click', '#bulk_edit', function(e){
      var selected = $('input[name="post[]"]:checked').length;
      if (!selected) {
        alert(getI18n('no_selection', 'No products selected for bulk update.'));
        e.preventDefault();
        return false;
      }

      var $bulk = $('#bulk-edit');
      var hsAction = ($bulk.find('select[name="wrd_hs_action"]').val() || '');
      var originAction = ($bulk.find('select[name="wrd_origin_action"]').val() || '');
      var descAction = ($bulk.find('select[name="wrd_desc_action"]').val() || '');
      var metalAction = ($bulk.find('select[name="wrd_232_metal_action"]').val() || '');
      var modeAction = ($bulk.find('select[name="wrd_232_mode_action"]').val() || '');
      var onlyEmpty = $bulk.find('input[name="wrd_only_empty"]').is(':checked');

      var changes = [];
      if (hsAction === 'set') changes.push(getI18n('hs_set', 'HS: set value'));
      if (hsAction === 'clear') changes.push(getI18n('hs_clear', 'HS: clear'));
      if (hsAction === 'suggest') changes.push(getI18n('hs_suggest', 'HS: apply suggested (legacy)'));
      if (originAction === 'set') changes.push(getI18n('origin_set', 'Origin: set value'));
      if (originAction === 'clear') changes.push(getI18n('origin_clear', 'Origin: clear'));
      if (descAction === 'set') changes.push(getI18n('desc_set', 'Description: set value'));
      if (descAction === 'clear') changes.push(getI18n('desc_clear', 'Description: clear'));
      if (metalAction === 'set') changes.push(getI18n('metal_set', '232 metal value: set'));
      if (metalAction === 'clear') changes.push(getI18n('metal_clear', '232 metal value: clear'));
      if (modeAction === 'set') changes.push(getI18n('mode_set', '232 variation mode: set'));
      if (modeAction === 'clear') changes.push(getI18n('mode_clear', '232 variation mode: reset to inherit'));
      if (onlyEmpty) changes.push(getI18n('only_empty', 'Only update empty fields'));

      if (!changes.length) {
        alert(getI18n('no_actions', 'No bulk customs actions selected.'));
        e.preventDefault();
        return false;
      }

      var message = getI18n('preview_prefix', 'About to update') + ' ' + selected + ' ' + getI18n('preview_suffix', 'products with:') + '\n- ' + changes.join('\n- ') + '\n\n' + getI18n('confirm', 'Continue?');
      if (!window.confirm(message)) {
        e.preventDefault();
        return false;
      }
      return true;
    });

    syncActionFields($(document.body));
  });
})(jQuery);
