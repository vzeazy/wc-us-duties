(function($){
  function setStatus($cell, message, state){
    var $status = $cell.find('.wrd-description-status');
    $status.removeClass('is-error is-success');
    if (state === 'error') {
      $status.addClass('is-error');
    } else if (state === 'success') {
      $status.addClass('is-success');
    }
    $status.text(message || '');
  }

  function syncState($cell){
    var $input = $cell.find('.wrd-description-input');
    var current = $input.val();
    var initial = String($input.data('initial') || '');
    var changed = current !== initial;
    $cell.find('.wrd-description-save').prop('disabled', !changed);
    $cell.find('.wrd-description-reset').toggleClass('is-hidden', !changed);
    if (!changed) {
      setStatus($cell, '', '');
    }
  }

  function saveDescription($cell){
    var profileId = parseInt($cell.data('profile-id'), 10) || 0;
    var $input = $cell.find('.wrd-description-input');
    var description = $input.val();
    var initial = String($input.data('initial') || '');

    if (profileId <= 0){
      setStatus($cell, WRDProfilesTable.i18n.error || 'Save failed', 'error');
      return;
    }
    if (description === initial){
      setStatus($cell, WRDProfilesTable.i18n.unchanged || 'No changes to save.', '');
      syncState($cell);
      return;
    }

    $cell.find('.wrd-description-save').prop('disabled', true);
    setStatus($cell, WRDProfilesTable.i18n.saving || 'Saving...', '');

    $.post(WRDProfilesTable.ajax, {
      action: 'wrd_update_profile_description',
      nonce: WRDProfilesTable.nonce,
      profile_id: profileId,
      description: description
    }).done(function(resp){
      if (resp && resp.success) {
        $input.data('initial', description);
        syncState($cell);
        setStatus($cell, WRDProfilesTable.i18n.saved || 'Saved', 'success');
      } else {
        setStatus($cell, WRDProfilesTable.i18n.error || 'Save failed', 'error');
        syncState($cell);
      }
    }).fail(function(){
      setStatus($cell, WRDProfilesTable.i18n.error || 'Save failed', 'error');
      syncState($cell);
    });
  }

  $(function(){
    $(document).on('input', '.wrd-description-input', function(){
      syncState($(this).closest('.wrd-description-cell'));
    });

    $(document).on('click', '.wrd-description-save', function(event){
      event.preventDefault();
      saveDescription($(this).closest('.wrd-description-cell'));
    });

    $(document).on('click', '.wrd-description-reset', function(event){
      event.preventDefault();
      var $cell = $(this).closest('.wrd-description-cell');
      var $input = $cell.find('.wrd-description-input');
      $input.val(String($input.data('initial') || ''));
      syncState($cell);
    });

    $(document).on('keydown', '.wrd-description-input', function(event){
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter'){
        event.preventDefault();
        saveDescription($(this).closest('.wrd-description-cell'));
      }
    });
  });
})(jQuery);
