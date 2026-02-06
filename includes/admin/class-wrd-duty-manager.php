<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Duty Manager Dashboard - unified view of all products and their duty status
 */
class WRD_Duty_Manager {
    
    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        
        // Handle inline save
        if (isset($_POST['wrd_duty_manager_save']) && wp_verify_nonce($_POST['wrd_duty_manager_nonce'] ?? '', 'wrd_duty_manager_save')) {
            $this->handle_inline_save();
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Duty Manager', 'woocommerce-us-duties'); ?></h1>
            <p class="description">
                <?php esc_html_e('Manage duty settings for all products. Products can inherit HS codes from their categories.', 'woocommerce-us-duties'); ?>
            </p>
            
            <?php $this->render_filters(); ?>
            <?php $this->render_products_table(); ?>
        </div>
        
        <style>
            .wrd-duty-manager-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .wrd-duty-manager-table th { background: #f6f7f7; padding: 10px; text-align: left; border-bottom: 2px solid #c3c4c7; }
            .wrd-duty-manager-table td { padding: 10px; border-bottom: 1px solid #dcdcde; }
            .wrd-duty-manager-table tr:hover { background: #f6f7f7; }
            .wrd-status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 500; }
            .wrd-status-complete { background: #d4edda; color: #155724; }
            .wrd-status-inherited { background: #d1ecf1; color: #0c5460; }
            .wrd-status-missing { background: #f8d7da; color: #721c24; }
            .wrd-status-partial { background: #fff3cd; color: #856404; }
            .wrd-inline-edit { display: none; }
            .wrd-inline-edit input { width: 100%; padding: 4px; }
            .wrd-inline-edit-active .wrd-inline-view { display: none; }
            .wrd-inline-edit-active .wrd-inline-edit { display: block; }
            .wrd-edit-actions { opacity: 0; transition: opacity 0.2s; }
            tr:hover .wrd-edit-actions { opacity: 1; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Inline editing
            $('.wrd-edit-btn').on('click', function(e) {
                e.preventDefault();
                $(this).closest('tr').addClass('wrd-inline-edit-active');
            });
            
            $('.wrd-cancel-btn').on('click', function(e) {
                e.preventDefault();
                $(this).closest('tr').removeClass('wrd-inline-edit-active');
            });
            
            // Filter toggle
            $('#wrd-filter-missing').on('change', function() {
                if ($(this).is(':checked')) {
                    $('tr[data-status="complete"], tr[data-status="inherited"]').hide();
                } else {
                    $('tr[data-status]').show();
                }
            });
        });
        </script>
        <?php
    }
    
    private function render_filters(): void {
        ?>
        <div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
            <label style="margin-right: 20px;">
                <input type="checkbox" id="wrd-filter-missing" />
                <?php esc_html_e('Show only products missing duty info', 'woocommerce-us-duties'); ?>
            </label>
        </div>
        <?php
    }
    
    private function render_products_table(): void {
        // Get all products
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        
        $products = get_posts($args);
        
        ?>
        <form method="post">
            <?php wp_nonce_field('wrd_duty_manager_save', 'wrd_duty_manager_nonce'); ?>
            <table class="wrd-duty-manager-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Product', 'woocommerce-us-duties'); ?></th>
                        <th><?php esc_html_e('Category', 'woocommerce-us-duties'); ?></th>
                        <th><?php esc_html_e('HS Code', 'woocommerce-us-duties'); ?></th>
                        <th><?php esc_html_e('Origin', 'woocommerce-us-duties'); ?></th>
                        <th><?php esc_html_e('Status', 'woocommerce-us-duties'); ?></th>
                        <th><?php esc_html_e('Actions', 'woocommerce-us-duties'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $post): 
                        $product = wc_get_product($post->ID);
                        if (!$product) continue;
                        
                        $effective = WRD_Category_Settings::get_effective_hs_code($product);
                        $product_hs = $product->get_meta('_hs_code', true);
                        $product_origin = $product->get_meta('_country_of_origin', true);
                        
                        // Determine status
                        $status = 'missing';
                        $status_label = __('Missing', 'woocommerce-us-duties');
                        $has_profile = false;
                        
                        if ($effective['hs_code'] && $effective['origin']) {
                            $has_profile = (bool) WRD_DB::get_profile_by_hs_country($effective['hs_code'], $effective['origin']);
                            if (!$has_profile) {
                                $status = 'missing';
                                $status_label = __('No profile match', 'woocommerce-us-duties');
                            } elseif ($effective['source'] === 'product') {
                                $status = 'complete';
                                $status_label = __('Complete', 'woocommerce-us-duties');
                            } else {
                                $status = 'inherited';
                                $status_label = sprintf(__('Inherited from %s', 'woocommerce-us-duties'), $effective['source']);
                            }
                        } elseif ($effective['hs_code'] || $effective['origin']) {
                            $status = 'partial';
                            $status_label = __('Partial', 'woocommerce-us-duties');
                        }
                        
                        // Get categories
                        $terms = get_the_terms($post->ID, 'product_cat');
                        $category_names = $terms && !is_wp_error($terms) ? implode(', ', wp_list_pluck($terms, 'name')) : '-';
                        
                        ?>
                        <tr data-status="<?php echo esc_attr($status); ?>" data-product-id="<?php echo esc_attr($post->ID); ?>">
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                                <div style="font-size: 11px; color: #666;">ID: <?php echo esc_html($post->ID); ?></div>
                            </td>
                            <td><?php echo esc_html($category_names); ?></td>
                            <td>
                                <div class="wrd-inline-view">
                                    <?php if ($effective['hs_code']): ?>
                                        <code><?php echo esc_html($effective['hs_code']); ?></code>
                                        <?php if ($effective['source'] !== 'product'): ?>
                                            <span style="color: #666; font-size: 11px;">↓</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </div>
                                <div class="wrd-inline-edit">
                                    <input type="text" name="hs_code[<?php echo esc_attr($post->ID); ?>]" 
                                           value="<?php echo esc_attr($product_hs); ?>" 
                                           placeholder="<?php echo esc_attr($effective['hs_code'] ?: 'e.g., 6205.20'); ?>" />
                                </div>
                            </td>
                            <td>
                                <div class="wrd-inline-view">
                                    <?php if ($effective['origin']): ?>
                                        <code><?php echo esc_html($effective['origin']); ?></code>
                                        <?php if ($effective['source'] !== 'product'): ?>
                                            <span style="color: #666; font-size: 11px;">↓</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </div>
                                <div class="wrd-inline-edit">
                                    <input type="text" name="origin[<?php echo esc_attr($post->ID); ?>]" 
                                           value="<?php echo esc_attr($product_origin); ?>" 
                                           maxlength="2"
                                           placeholder="<?php echo esc_attr($effective['origin'] ?: 'e.g., CN'); ?>" />
                                </div>
                            </td>
                            <td>
                                <span class="wrd-status-badge wrd-status-<?php echo esc_attr($status); ?>">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                            </td>
                            <td>
                                <div class="wrd-edit-actions">
                                    <a href="#" class="wrd-edit-btn"><?php esc_html_e('Edit', 'woocommerce-us-duties'); ?></a>
                                    <span class="wrd-inline-edit">
                                        <button type="submit" name="wrd_duty_manager_save" class="button button-small button-primary">
                                            <?php esc_html_e('Save', 'woocommerce-us-duties'); ?>
                                        </button>
                                        <a href="#" class="wrd-cancel-btn button button-small"><?php esc_html_e('Cancel', 'woocommerce-us-duties'); ?></a>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <?php
    }
    
    private function handle_inline_save(): void {
        $hs_codes = $_POST['hs_code'] ?? [];
        $origins = $_POST['origin'] ?? [];
        
        foreach ($hs_codes as $product_id => $hs_code) {
            $product_id = (int) $product_id;
            $product = wc_get_product($product_id);
            if (!$product) continue;
            
            $hs_code = trim(sanitize_text_field($hs_code));
            $origin = isset($origins[$product_id]) ? strtoupper(trim(sanitize_text_field($origins[$product_id]))) : '';

            WRD_Admin::upsert_product_classification($product_id, [
                'hs_code' => $hs_code,
                'origin' => $origin,
            ]);
        }
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Products updated successfully.', 'woocommerce-us-duties') . '</p></div>';
    }
}
