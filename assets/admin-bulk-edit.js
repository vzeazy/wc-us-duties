(function($) {
    'use strict';

    var WRDBulkEdit = {
        init: function() {
            this.bindEvents();
            this.attachAutocomplete();
        },

        bindEvents: function() {
            // Watch bulk action dropdowns
            $(document).on('change', '#bulk-action-selector-top, #bulk-action-selector-bottom', this.toggleBulkEdit);
            
            // Handle bulk edit form interactions
            $(document).on('change', '.bulk-date-action', this.toggleDateInput);
            $(document).on('click', '#wrd-bulk-edit .cancel', this.cancelBulkEdit);
            $(document).on('click', '#doaction, #doaction2', this.handleBulkAction);
            
            // Form submission
            $(document).on('submit', 'form', this.validateBulkSubmission);
        },

        toggleBulkEdit: function() {
            var action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();
            var $bulkEdit = $('#wrd-bulk-edit');
            
            if (action === 'set_dates') {
                // Check if any items are selected
                var selectedItems = $('input[name="ids[]"]:checked').length;
                if (selectedItems === 0) {
                    alert(wrdBulkEdit.strings.no_items_selected);
                    $('#bulk-action-selector-top, #bulk-action-selector-bottom').val('');
                    return false;
                }
                
                // Show bulk edit panel with WordPress-style animation
                $bulkEdit.show().find('.bulk-edit-fields').hide().fadeIn(200);
                
                // Scroll to bulk edit panel
                $('html, body').animate({
                    scrollTop: $bulkEdit.offset().top - 50
                }, 300);
                
            } else {
                WRDBulkEdit.cancelBulkEdit();
            }
        },

        toggleDateInput: function() {
            var $select = $(this);
            var $dateInput = $select.siblings('.bulk-date-input');
            
            if ($select.val() === 'set') {
                $dateInput.show().focus();
            } else {
                $dateInput.hide().val('');
            }
        },

        cancelBulkEdit: function() {
            $('#wrd-bulk-edit').fadeOut(200);
            $('#bulk-action-selector-top, #bulk-action-selector-bottom').val('');
            
            // Reset form fields
            $('.bulk-date-action').val('');
            $('.bulk-date-input').hide().val('');
            
            return false;
        },

        handleBulkAction: function(e) {
            var action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();
            
            if (action === 'delete') {
                var selectedItems = $('input[name="ids[]"]:checked').length;
                if (selectedItems === 0) {
                    alert(wrdBulkEdit.strings.no_items_selected);
                    return false;
                }
                
                if (!confirm(wrdBulkEdit.strings.confirm_delete)) {
                    return false;
                }
            }
            
            if (action === 'set_dates') {
                // Prevent default form submission, let our bulk edit form handle it
                e.preventDefault();
                return false;
            }
        },

        validateBulkSubmission: function(e) {
            var $form = $(this);
            
            // Only validate if this is a bulk edit submission
            if (!$form.find('input[name="bulk_edit"]').length) {
                return true;
            }
            
            var selectedItems = $('input[name="ids[]"]:checked').length;
            if (selectedItems === 0) {
                alert(wrdBulkEdit.strings.no_items_selected);
                return false;
            }
            
            // Check if at least one action is selected
            var hasAction = false;
            $('.bulk-date-action').each(function() {
                if ($(this).val() !== '') {
                    hasAction = true;
                    return false;
                }
            });
            
            if (!hasAction) {
                alert('Please select at least one date action to perform.');
                return false;
            }
            
            // Validate date inputs
            var isValid = true;
            $('.bulk-date-action').each(function() {
                var $select = $(this);
                var $dateInput = $select.siblings('.bulk-date-input');
                
                if ($select.val() === 'set') {
                    if (!$dateInput.val()) {
                        alert('Please enter a date for the selected action.');
                        $dateInput.focus();
                        isValid = false;
                        return false;
                    }
                }
            });
            
            if (!isValid) {
                return false;
            }
            
            // Show loading state
            var $spinner = $form.find('.spinner');
            var $submitBtn = $form.find('input[type="submit"]');
            
            $spinner.addClass('is-active');
            $submitBtn.prop('disabled', true).val(wrdBulkEdit.strings.processing);
            
            return true;
        },

        attachAutocomplete: function() {
            // Reuse existing autocomplete functionality from admin-quick-bulk.js
            function attachAutocomplete(context) {
                $(context).find('input.wrd-profile-lookup').each(function() {
                    var $input = $(this);
                    if ($input.data('wrdBound')) return;
                    $input.data('wrdBound', true);
                    
                    if (typeof $input.autocomplete === 'function' && typeof WRDProfiles !== 'undefined') {
                        $input.autocomplete({
                            minLength: 2,
                            source: function(req, resp) {
                                $.getJSON(WRDProfiles.ajax, { 
                                    action: 'wrd_search_profiles', 
                                    nonce: WRDProfiles.nonce, 
                                    term: req.term 
                                }, function(data) {
                                    resp(data || []);
                                });
                            },
                            select: function(e, ui) {
                                var item = ui.item || {};
                                var $wrap = $input.closest('.inline-edit-col, .wrd-customs-inline, .inline-edit-row');
                                $wrap.find('input[name="wrd_customs_description"]').val(item.desc || '');
                                $wrap.find('input[name="wrd_country_of_origin"]').val(item.cc || '');
                            }
                        });
                    }
                });
            }
            
            // Initial bind
            attachAutocomplete(document.body);
            
            // Bind when bulk edit opens
            $(document).on('bulk_edit_opened', function() {
                attachAutocomplete('#wrd-bulk-edit');
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WRDBulkEdit.init();
    });

})(jQuery);
