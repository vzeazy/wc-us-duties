(function($) {
    'use strict';

    var WRDBulkEdit = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Watch bulk action dropdowns
            $(document).on('change', '#bulk-action-selector-top, #bulk-action-selector-bottom', this.toggleBulkEdit);
            
            // Handle bulk edit form interactions
            $(document).on('change', '.bulk-date-action', this.toggleDateInput);
            $(document).on('change', '.bulk-rate-action', this.toggleRateInput);
            $(document).on('change', '.bulk-notes-action', this.toggleNotesInput);
            $(document).on('click', '#wrd-bulk-edit .cancel', this.cancelBulkEdit);
            $(document).on('click', '#doaction, #doaction2', this.handleBulkAction);
            // Explicitly mark when the bulk edit Update button is used
            $(document).on('click', 'input[name="bulk_edit"]', function() {
                var $form = $(this).closest('form');
                $form.data('wrdBulkSubmit', true);
            });
            
            // Form submission (only on profiles bulk-edit form)
            $(document).on('submit', 'form[data-wrd-bulk-form="1"]', this.validateBulkSubmission);
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

        toggleRateInput: function() {
            var $select = $(this);
            var $rateInput = $select.siblings('.bulk-rate-input');

            if ($select.val() === 'set') {
                $rateInput.show().focus();
            } else {
                $rateInput.hide().val('');
            }
        },

        toggleNotesInput: function() {
            var $select = $(this);
            var $notesInput = $select.siblings('.bulk-notes-input');
            var action = $select.val();

            if (action === 'replace' || action === 'append') {
                $notesInput.show().focus();
            } else {
                $notesInput.hide().val('');
            }
        },

        cancelBulkEdit: function() {
            $('#wrd-bulk-edit').fadeOut(200);
            $('#bulk-action-selector-top, #bulk-action-selector-bottom').val('');
            
            // Reset form fields
            $('.wrd-bulk-action-select').val('');
            $('.bulk-date-input').hide().val('');
            $('.bulk-rate-input').hide().val('');
            $('.bulk-notes-input').hide().val('');
            // Clear any pending bulk submit flags
            $('form').removeData('wrdBulkSubmit');
            
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

            // Skip validation for search submissions
            var isSearchSubmit = $form.find('input[name="s"]').length > 0 &&
                                 $form.find('input[name="s"]').val() !== '';
            if (isSearchSubmit) {
                return true;
            }

            // Only run validation when the bulk edit Update button initiated the submit
            var isBulkSubmit = $form.data('wrdBulkSubmit') === true;
            // Fallback: if user presses Enter within the bulk edit panel with actions chosen
            if (!isBulkSubmit) {
                var bulkPanelVisible = $('#wrd-bulk-edit:visible').length > 0;
                if (bulkPanelVisible) {
                    var hasActionChosen = false;
                    $('#wrd-bulk-edit .wrd-bulk-action-select').each(function(){
                        if ($(this).val() !== '') { hasActionChosen = true; return false; }
                    });
                    if (hasActionChosen) {
                        isBulkSubmit = true;
                    }
                }
            }

            // Only validate if this is a bulk edit submission
            if (!isBulkSubmit) {
                return true;
            }
            
            var selectedItems = $('input[name="ids[]"]:checked').length;
            if (selectedItems === 0) {
                alert(wrdBulkEdit.strings.no_items_selected);
                // Clear the flag so other submits aren't blocked
                $form.removeData('wrdBulkSubmit');
                return false;
            }
            
            // Check if at least one action is selected
            var hasAction = false;
            $('#wrd-bulk-edit .wrd-bulk-action-select').each(function() {
                if ($(this).val() !== '') {
                    hasAction = true;
                    return false;
                }
            });
            
            if (!hasAction) {
                alert(wrdBulkEdit.strings.select_action);
                $form.removeData('wrdBulkSubmit');
                return false;
            }
            
            // Validate date inputs
            var isValid = true;
            $('.bulk-date-action').each(function() {
                var $select = $(this);
                var $dateInput = $select.siblings('.bulk-date-input');
                
                if ($select.val() === 'set') {
                    if (!$dateInput.val()) {
                        alert(wrdBulkEdit.strings.enter_date);
                        $dateInput.focus();
                        $form.removeData('wrdBulkSubmit');
                        isValid = false;
                        return false;
                    }
                }
            });

            if (!isValid) {
                return false;
            }

            $('.bulk-rate-action').each(function() {
                var $select = $(this);
                var $rateInput = $select.siblings('.bulk-rate-input');

                if ($select.val() === 'set') {
                    var raw = $rateInput.val();
                    var parsed = parseFloat(raw);
                    if (raw === '') {
                        alert(wrdBulkEdit.strings.enter_rate);
                        $rateInput.focus();
                        $form.removeData('wrdBulkSubmit');
                        isValid = false;
                        return false;
                    }
                    if (isNaN(parsed) || parsed < 0 || parsed > 100) {
                        alert(wrdBulkEdit.strings.invalid_rate);
                        $rateInput.focus();
                        $form.removeData('wrdBulkSubmit');
                        isValid = false;
                        return false;
                    }
                }
            });

            if (!isValid) {
                return false;
            }

            $('.bulk-notes-action').each(function() {
                var $select = $(this);
                var $notesInput = $select.siblings('.bulk-notes-input');

                if ($select.val() === 'replace' || $select.val() === 'append') {
                    if (!$notesInput.val().trim()) {
                        alert(wrdBulkEdit.strings.enter_notes);
                        $notesInput.focus();
                        $form.removeData('wrdBulkSubmit');
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
            // Clear flag after successful validation so non-bulk submits work later
            $form.removeData('wrdBulkSubmit');
            
            return true;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WRDBulkEdit.init();
    });

})(jQuery);
