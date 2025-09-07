(function($){
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
          $wrap.find('input[name="wrd_customs_description"]').val(item.desc || '');
          $wrap.find('input[name="wrd_country_of_origin"]').val(item.cc || '');
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
  });

  // Fallback: initial bind
  $(function(){
    attachAutocomplete(document.body);
  });
})(jQuery);

