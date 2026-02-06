<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WRD_Reconciliation_Table extends WP_List_Table {
    private $status;
    private $items_data = [];

    public function __construct(string $status = 'any_missing') {
        parent::__construct([
            'singular' => 'wrd_reconcile_product',
            'plural'   => 'wrd_reconcile_products',
            'ajax'     => false,
        ]);
        $this->status = $status;
    }

    public function get_columns() {
        return [
            'title' => __('Product', 'woocommerce-us-duties'),
            'sku' => __('SKU', 'woocommerce-us-duties'),
            'type' => __('Type', 'woocommerce-us-duties'),
            'hs_code' => __('HS Code', 'woocommerce-us-duties'),
            'origin' => __('Origin', 'woocommerce-us-duties'),
            'status' => __('Status', 'woocommerce-us-duties'),
            'assign' => __('Assign', 'woocommerce-us-duties'),
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'sku': return esc_html($item['sku']);
            case 'type': return esc_html($item['type']);
            case 'hs_code': return esc_html($item['hs_code']);
            case 'origin': return esc_html($item['origin']);
            case 'status': return wp_kses_post($item['status_html']);
            case 'assign': return $this->render_assign_controls($item);
        }
        return '';
    }

    protected function column_title($item) {
        $editUrl = get_edit_post_link($item['id']);
        return sprintf('<strong><a href="%s">%s</a></strong>', esc_url($editUrl), esc_html($item['title']));
    }

    private function render_assign_controls(array $item): string {
        $pid = (int)$item['id'];
        $html = '<div class="wrd-assign" data-product="' . esc_attr($pid) . '">'
              . '<input type="text" class="wrd-profile-lookup" placeholder="' . esc_attr__('Search profiles…', 'woocommerce-us-duties') . '" style="min-width:260px" /> '
              . '<input type="text" class="wrd-hs" placeholder="' . esc_attr__('HS Code', 'woocommerce-us-duties') . '" value="' . esc_attr($item['hs_code']) . '" style="width:120px" /> '
              . '<input type="text" class="wrd-cc" placeholder="CC" maxlength="2" value="' . esc_attr($item['origin']) . '" style="width:60px" /> '
              . '<button type="button" class="button wrd-apply">' . esc_html__('Apply', 'woocommerce-us-duties') . '</button> '
              . '<span class="wrd-status" style="margin-left:6px;"></span>'
              . '</div>';
        return $html;
    }

    public function prepare_items() {
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $ids = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);

        $profile_cache = [];
        $items = [];

        foreach ($ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product) { continue; }

            $effective = WRD_Category_Settings::get_effective_hs_code($product);
            $hs_code = trim((string) ($effective['hs_code'] ?? ''));
            $origin = strtoupper(trim((string) ($effective['origin'] ?? '')));
            $missing_hs = ($hs_code === '');
            $missing_origin = ($origin === '');
            $has_profile = false;

            if (!$missing_hs && !$missing_origin) {
                $cache_key = $hs_code . '|' . $origin;
                if (!array_key_exists($cache_key, $profile_cache)) {
                    $profile_cache[$cache_key] = (bool) WRD_DB::get_profile_by_hs_country($hs_code, $origin);
                }
                $has_profile = $profile_cache[$cache_key];
            }

            $status_key = 'ok';
            $status_parts = [];
            if ($missing_hs) {
                $status_key = 'missing_hs';
                $status_parts[] = '<span style="color:#a00;">' . esc_html__('Missing HS code', 'woocommerce-us-duties') . '</span>';
            }
            if ($missing_origin) {
                $status_key = 'missing_origin';
                $status_parts[] = '<span style="color:#a00;">' . esc_html__('Missing origin', 'woocommerce-us-duties') . '</span>';
            }
            if (!$missing_hs && !$missing_origin && !$has_profile) {
                $status_key = 'no_profile';
                $status_parts[] = '<span style="color:#d98300;">' . esc_html__('No matching profile', 'woocommerce-us-duties') . '</span>';
            }
            if (!$this->matches_status_filter($status_key)) {
                continue;
            }

            if (!empty($effective['source']) && strpos((string) $effective['source'], 'category:') === 0) {
                $status_parts[] = '<span style="color:#2271b1;">' . esc_html(sprintf(__('Inherited from %s', 'woocommerce-us-duties'), substr((string) $effective['source'], 9))) . '</span>';
            }

            $items[] = [
                'id' => (int) $pid,
                'title' => (string) $product->get_name(),
                'type' => $product->is_type('variation') ? 'Variation' : 'Product',
                'sku' => (string) $product->get_sku(),
                'hs_code' => $hs_code,
                'origin' => $origin,
                'status_html' => implode(' · ', $status_parts),
            ];
        }

        $total = count($items);
        $this->items = array_slice($items, $offset, $per_page);
        $this->_column_headers = [$this->get_columns(), [], [], 'title'];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    private function matches_status_filter(string $status_key): bool {
        if ($this->status === 'any_missing') {
            return in_array($status_key, ['missing_hs', 'missing_origin', 'no_profile'], true);
        }
        if ($this->status === 'missing_desc') {
            // Legacy key retained for URL compatibility.
            return $status_key === 'missing_hs';
        }
        if ($this->status === 'missing_origin') {
            return $status_key === 'missing_origin';
        }
        if ($this->status === 'no_profile') {
            return $status_key === 'no_profile';
        }
        return true;
    }
}
