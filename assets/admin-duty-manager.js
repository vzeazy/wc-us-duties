(function($){
  function attachAutocomplete(context){
    $(context).find('input.wrd-profile-lookup').each(function(){
      var $input = $(this);
      if ($input.data('wrdBound')) return;
      $input.data('wrdBound', true);
      $input.autocomplete({
        minLength: 2,
        source: function(req, resp){
          $.getJSON(WRDDutyManager.ajax, {
            action: 'wrd_search_profiles',
            nonce: WRDDutyManager.searchNonce,
            term: req.term
          }, function(data){
            resp(data || []);
          });
        },
        select: function(e, ui){
          var $row = $input.closest('tr');
          var item = ui.item || {};
          $row.find('input.wrd-duty-hs').val(item.hs || '');
          $row.find('input.wrd-duty-origin').val(item.cc || '');
        }
      });
    });
  }

  function saveRow($btn){
    var $row = $btn.closest('tr');
    var productId = parseInt($btn.data('product-id'), 10) || 0;
    var hs = ($row.find('input.wrd-duty-hs').val() || '').trim();
    var origin = ($row.find('input.wrd-duty-origin').val() || '').trim().toUpperCase();
    var $status = $row.find('.wrd-duty-row-status');

    if (!productId || !hs || !origin) {
      $status.text(WRDDutyManager.i18n.missing || 'HS and Origin are required.').css('color', '#a00');
      return;
    }

    $btn.prop('disabled', true);
    $status.text(WRDDutyManager.i18n.saving || 'Saving...').css('color', '#666');

    $.post(WRDDutyManager.ajax, {
      action: 'wrd_quick_assign_profile',
      nonce: WRDDutyManager.nonce,
      product_id: productId,
      hs_code: hs,
      country: origin
    }).done(function(res){
      if (res && res.success) {
        $status.text(WRDDutyManager.i18n.saved || 'Saved').css('color', '#008a20');
        $row.addClass('wrd-duty-row-saved');
        setTimeout(function(){ $row.removeClass('wrd-duty-row-saved'); }, 1200);

        var hasProfile = !!(res.data && res.data.has_profile);
        var $profileCell = $row.find('.column-wrd_hs_profile');
        var $statusCell = $row.find('.column-wrd_hs_status');

        if ($profileCell.length) {
          var $view = $profileCell.find('.wrd-profile-view');
          if ($view.length) {
            if (hasProfile) {
              $view.removeClass('wrd-hs-pill--warn').addClass('wrd-hs-pill--ok').text(WRDDutyManager.i18n.linked || 'Linked');
            } else {
              $view.removeClass('wrd-hs-pill--ok').addClass('wrd-hs-pill--warn').text(WRDDutyManager.i18n.noProfile || 'No profile');
            }
          }
          $profileCell.removeClass('wrd-profile-editing');
        }

        if ($statusCell.length) {
          if (hasProfile) {
            $statusCell.html('<span class=\"wrd-hs-pill wrd-hs-pill--ok\">' + (WRDDutyManager.i18n.ready || 'Ready') + '</span>');
          } else {
            $statusCell.html('<span class=\"wrd-hs-pill wrd-hs-pill--warn\">' + (WRDDutyManager.i18n.missingProfile || 'Missing Profile') + '</span>');
          }
        }
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message : (WRDDutyManager.i18n.failed || 'Save failed');
        $status.text(msg).css('color', '#a00');
      }
    }).fail(function(){
      $status.text(WRDDutyManager.i18n.failed || 'Save failed').css('color', '#a00');
    }).always(function(){
      $btn.prop('disabled', false);
    });
  }

  $(function(){
    attachAutocomplete(document.body);
    $(document).on('input', 'input.wrd-duty-origin', function(){
      this.value = (this.value || '').toUpperCase();
    });

    $(document).on('click', '.wrd-duty-save', function(e){
      e.preventDefault();
      saveRow($(this));
    });

    $(document).on('click', '.wrd-profile-edit-toggle', function(e){
      e.preventDefault();
      var $cell = $(this).closest('td.column-wrd_hs_profile');
      $cell.addClass('wrd-profile-editing');
      $cell.find('input.wrd-profile-lookup').focus();
    });

    $(document).on('click', '.wrd-profile-edit-cancel', function(e){
      e.preventDefault();
      $(this).closest('td.column-wrd_hs_profile').removeClass('wrd-profile-editing');
    });

    $(document).on('click', '.wrd-legacy-suggest-toggle', function(e){
      e.preventDefault();
      $(this).closest('.wrd-legacy-suggest-wrap').toggleClass('is-open');
    });

    $(document).on('click', '.wrd-legacy-suggest-cancel', function(e){
      e.preventDefault();
      $(this).closest('.wrd-legacy-suggest-wrap').removeClass('is-open');
    });

    $(document).on('click', '.wrd-apply-suggested-hs', function(e){
      e.preventDefault();
      var $btn = $(this);
      var $row = $btn.closest('tr');
      var hs = ($btn.data('hs') || '').toString().trim();
      var origin = ($btn.data('origin') || '').toString().trim().toUpperCase();
      if (hs) {
        $row.find('input.wrd-duty-hs').val(hs);
      }
      if (origin) {
        $row.find('input.wrd-duty-origin').val(origin);
      }
      var $saveBtn = $row.find('.wrd-duty-save').first();
      if ($saveBtn.length) {
        saveRow($saveBtn);
      }
      $btn.closest('.wrd-legacy-suggest-wrap').removeClass('is-open');
    });

    $(document).on('keydown', 'input.wrd-duty-hs, input.wrd-duty-origin', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        var $btn = $(this).closest('tr').find('.wrd-duty-save').first();
        if ($btn.length) {
          saveRow($btn);
        }
      }
    });
  });
})(jQuery);
