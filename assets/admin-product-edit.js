(function($) {
    'use strict';

    var WRDProductEdit = {
        init: function() {
            this.attachAutocomplete();
        },

        attachAutocomplete: function() {
            var self = this;

            // Product edit page autocomplete
            $('.wrd-customs-fields .wrd-profile-lookup').each(function() {
                var $input = $(this);
                if ($input.data('wrdBound')) return;
                $input.data('wrdBound', true);

                if (typeof $input.autocomplete === 'function' && typeof WRDProduct !== 'undefined') {
                    $input.autocomplete({
                        minLength: 2,
                        source: function(req, resp) {
                            $.getJSON(WRDProduct.ajax, {
                                action: 'wrd_search_profiles',
                                nonce: WRDProduct.nonce,
                                term: req.term
                            }, function(data) {
                                resp(data || []);
                            });
                        },
                        select: function(e, ui) {
                            e.preventDefault();
                            var item = ui.item || {};

                            // Populate fields
                            $('#_hs_code').val(item.hs || '').trigger('change');
                            $('#_country_of_origin').val(item.cc || '').trigger('change');

                            // Clear the search field
                            $input.val('');

                            return false;
                        }
                    });
                }
            });

            // Variation autocomplete
            $(document).on('woocommerce_variations_loaded', function() {
                self.attachVariationAutocomplete();
            });
        },

        attachVariationAutocomplete: function() {
            $('.wrd-variation-customs-fields .wrd-profile-lookup').each(function() {
                var $input = $(this);
                if ($input.data('wrdBound')) return;
                $input.data('wrdBound', true);

                if (typeof $input.autocomplete === 'function' && typeof WRDProduct !== 'undefined') {
                    $input.autocomplete({
                        minLength: 2,
                        source: function(req, resp) {
                            $.getJSON(WRDProduct.ajax, {
                                action: 'wrd_search_profiles',
                                nonce: WRDProduct.nonce,
                                term: req.term
                            }, function(data) {
                                resp(data || []);
                            });
                        },
                        select: function(e, ui) {
                            e.preventDefault();
                            var item = ui.item || {};
                            var $wrap = $input.closest('.wrd-variation-customs-fields');

                            // Find the variation fields and populate
                            $wrap.find('input[name^="_hs_code"]').val(item.hs || '').trigger('change');
                            $wrap.find('input[name^="_country_of_origin"]').val(item.cc || '').trigger('change');

                            // Clear the search field
                            $input.val('');

                            return false;
                        }
                    });
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WRDProductEdit.init();
    });

})(jQuery);
