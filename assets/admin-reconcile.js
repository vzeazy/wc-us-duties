(function($){
  function attachAutocomplete(context){
    $(context).find('input.wrd-profile-lookup').each(function(){
      var $input = $(this);
      if ($input.data('wrdBound')) return;
      $input.data('wrdBound', true);
      if (typeof $input.autocomplete !== 'function') return;
      $input.autocomplete({
        minLength: 2,
        source: function(req, resp){
          $.getJSON(WRDReconcile.ajax, { action: 'wrd_search_profiles', nonce: WRDReconcile.searchNonce, term: req.term }, function(data){
            resp(data || []);
          });
        },
        select: function(e, ui){
          var $wrap = $input.closest('.wrd-assign');
          if (ui && ui.item){
            $wrap.find('input.wrd-hs').val(ui.item.hs || '');
            $wrap.find('input.wrd-cc').val(ui.item.cc || '');
          }
        }
      });

      // On focus, fetch suggestions based on current hs_code/cc if input is empty
      $input.on('focus', function(){
        if ($input.val()) return;
        var $wrap = $input.closest('.wrd-assign');
        var hs_code = $wrap.find('input.wrd-hs').val();
        var cc = $wrap.find('input.wrd-cc').val();
        if (!hs_code || !cc) return;
        $.getJSON(WRDReconcile.ajax, { action: 'wrd_reconcile_suggest', nonce: WRDReconcile.nonce, hs_code: hs_code, cc: cc }, function(resp){
          if (resp && resp.success && resp.data && resp.data.length){
            // Populate autocomplete menu immediately
            $input.autocomplete('option', 'source', resp.data);
            $input.autocomplete('search', '');
          }
        });
      });
    });
  }

  function attachHandlers(context){
    $(context).on('click', '.wrd-assign .wrd-apply', function(){
      var $wrap = $(this).closest('.wrd-assign');
      var pid = $wrap.data('product');
      var hs_code = $wrap.find('input.wrd-hs').val();
      var cc = $wrap.find('input.wrd-cc').val();
      var $status = $wrap.find('.wrd-status');
      $status.text('…');
      $.post(WRDReconcile.ajax, {
        action: 'wrd_reconcile_assign',
        nonce: WRDReconcile.nonce,
        product_id: pid,
        hs_code: hs_code,
        cc: cc
      }).done(function(resp){
        if (resp && resp.success){
          $status.text(WRDReconcile.i18n.applied);
          setTimeout(function(){ $status.text(''); }, 1000);
        } else {
          $status.text(WRDReconcile.i18n.error);
        }
      }).fail(function(){
        $status.text(WRDReconcile.i18n.error);
      });
    });
  }

  $(function(){
    attachAutocomplete(document.body);
    attachHandlers(document.body);
  });
})(jQuery);
