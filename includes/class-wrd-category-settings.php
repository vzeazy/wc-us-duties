<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Category-level duty settings for inheritance
 */
class WRD_Category_Settings {
    
    public function init(): void {
        // Add fields to category edit screen
        add_action('product_cat_add_form_fields', [$this, 'add_category_fields']);
        add_action('product_cat_edit_form_fields', [$this, 'edit_category_fields'], 10, 2);
        
        // Save category fields
        add_action('created_product_cat', [$this, 'save_category_fields']);
        add_action('edited_product_cat', [$this, 'save_category_fields']);
    }
    
    /**
     * Add fields to new category form
     */
    public function add_category_fields(): void {
        ?>
        <div class="form-field wrd-category-duty-settings">
            <h3><?php esc_html_e('Default Duty Settings', 'woocommerce-us-duties'); ?></h3>
            <p><?php esc_html_e('Products in this category will inherit these settings if they don\'t have their own values.', 'woocommerce-us-duties'); ?></p>
            
            <label for="wrd_default_hs_code"><?php esc_html_e('Default HS Code', 'woocommerce-us-duties'); ?></label>
            <input type="text" name="wrd_default_hs_code" id="wrd_default_hs_code" placeholder="e.g., 6205.20" />
            <p class="description"><?php esc_html_e('Harmonized System code for products in this category.', 'woocommerce-us-duties'); ?></p>
        </div>
        
        <div class="form-field">
            <label for="wrd_default_country_of_origin"><?php esc_html_e('Default Country of Origin', 'woocommerce-us-duties'); ?></label>
            <input type="text" name="wrd_default_country_of_origin" id="wrd_default_country_of_origin" maxlength="2" placeholder="e.g., CN" />
            <p class="description"><?php esc_html_e('ISO-2 country code (e.g., CN, CA, TW).', 'woocommerce-us-duties'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Add fields to edit category form
     */
    public function edit_category_fields($term, $taxonomy): void {
        $hs_code = get_term_meta($term->term_id, 'wrd_default_hs_code', true);
        $origin = get_term_meta($term->term_id, 'wrd_default_country_of_origin', true);
        ?>
        <tr class="form-field wrd-category-duty-settings">
            <th scope="row" colspan="2">
                <h3><?php esc_html_e('Default Duty Settings', 'woocommerce-us-duties'); ?></h3>
                <p class="description"><?php esc_html_e('Products in this category will inherit these settings if they don\'t have their own values.', 'woocommerce-us-duties'); ?></p>
            </th>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="wrd_default_hs_code"><?php esc_html_e('Default HS Code', 'woocommerce-us-duties'); ?></label>
            </th>
            <td>
                <input type="text" name="wrd_default_hs_code" id="wrd_default_hs_code" value="<?php echo esc_attr($hs_code); ?>" placeholder="e.g., 6205.20" />
                <p class="description"><?php esc_html_e('Harmonized System code for products in this category.', 'woocommerce-us-duties'); ?></p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="wrd_default_country_of_origin"><?php esc_html_e('Default Country of Origin', 'woocommerce-us-duties'); ?></label>
            </th>
            <td>
                <input type="text" name="wrd_default_country_of_origin" id="wrd_default_country_of_origin" value="<?php echo esc_attr($origin); ?>" maxlength="2" placeholder="e.g., CN" />
                <p class="description"><?php esc_html_e('ISO-2 country code (e.g., CN, CA, TW).', 'woocommerce-us-duties'); ?></p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Save category fields
     */
    public function save_category_fields($term_id): void {
        if (isset($_POST['wrd_default_hs_code'])) {
            $hs_code = trim(sanitize_text_field(wp_unslash($_POST['wrd_default_hs_code'])));
            update_term_meta($term_id, 'wrd_default_hs_code', $hs_code);
        }
        
        if (isset($_POST['wrd_default_country_of_origin'])) {
            $origin = strtoupper(trim(sanitize_text_field(wp_unslash($_POST['wrd_default_country_of_origin']))));
            update_term_meta($term_id, 'wrd_default_country_of_origin', $origin);
        }
    }
    
    /**
     * Get effective HS code for a product (with category fallback)
     */
    public static function get_effective_hs_code($product): array {
        $product_hs = $product->get_meta('_hs_code', true);
        $product_origin = $product->get_meta('_country_of_origin', true);
        
        // Check parent for variations
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                if (!$product_hs) {
                    $product_hs = $parent->get_meta('_hs_code', true);
                }
                if (!$product_origin) {
                    $product_origin = $parent->get_meta('_country_of_origin', true);
                }
            }
        }
        
        $product_hs = is_string($product_hs) ? trim($product_hs) : '';
        $product_origin = is_string($product_origin) ? strtoupper(trim($product_origin)) : '';
        
        // If we have both from product, return them
        if ($product_hs && $product_origin) {
            return [
                'hs_code' => $product_hs,
                'origin' => $product_origin,
                'source' => 'product'
            ];
        }
        
        // Try category fallback
        $category_hs = '';
        $category_origin = '';
        $category_source = null;
        
        $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $terms = get_the_terms($product_id, 'product_cat');
        
        if ($terms && !is_wp_error($terms)) {
            // Use first category with duty settings
            foreach ($terms as $term) {
                $cat_hs = get_term_meta($term->term_id, 'wrd_default_hs_code', true);
                $cat_origin = get_term_meta($term->term_id, 'wrd_default_country_of_origin', true);
                
                if ($cat_hs || $cat_origin) {
                    $category_hs = $cat_hs ?: '';
                    $category_origin = $cat_origin ?: '';
                    $category_source = $term->name;
                    break;
                }
            }
        }
        
        return [
            'hs_code' => $product_hs ?: $category_hs,
            'origin' => $product_origin ?: $category_origin,
            'source' => ($product_hs || $product_origin) ? 'product' : ($category_source ? 'category:' . $category_source : 'none')
        ];
    }
}
