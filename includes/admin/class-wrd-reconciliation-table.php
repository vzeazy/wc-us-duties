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
        global $wpdb;
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $post_types = [ 'product', 'product_variation' ];
        $inTypes = implode("','", array_map('esc_sql', $post_types));

        // Build where for missing/no-profile (now based on HS code)
        $where = "p.post_type IN ('{$inTypes}') AND p.post_status NOT IN ('trash','auto-draft')";
        $joins = "LEFT JOIN {$wpdb->postmeta} h ON (h.post_id = p.ID AND h.meta_key = '_wrd_hs_code')
                  LEFT JOIN {$wpdb->postmeta} c ON (c.post_id = p.ID AND c.meta_key = '_wrd_origin_cc')";

        if ($this->status === 'missing_desc') {
            // Renamed to missing_hs
            $where .= " AND (h.meta_value IS NULL OR h.meta_value = '')";
        } elseif ($this->status === 'missing_origin') {
            $where .= " AND (c.meta_value IS NULL OR c.meta_value = '')";
        } elseif ($this->status === 'no_profile') {
            // No profile: both present but not found in profiles table
            $profiles = WRD_DB::table_profiles();
            $joins .= " LEFT JOIN {$profiles} prof ON (prof.hs_code = h.meta_value AND prof.country_code = c.meta_value
                        AND (prof.effective_from IS NULL OR prof.effective_from <= DATE(NOW()))
                        AND (prof.effective_to IS NULL OR prof.effective_to >= DATE(NOW())))";
            $where .= " AND (COALESCE(h.meta_value,'') <> '' AND COALESCE(c.meta_value,'') <> '' AND prof.id IS NULL)";
        } else { // any_missing
            $where .= " AND (COALESCE(h.meta_value,'') = '' OR COALESCE(c.meta_value,'') = '')";
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p {$joins} WHERE {$where}");

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type,
                    COALESCE(vhs.meta_value, phs.meta_value) AS hs_code,
                    COALESCE(vorg.meta_value, porg.meta_value) AS origin,
                    sku.meta_value AS sku
             FROM {$wpdb->posts} p
             {$joins}
             LEFT JOIN {$wpdb->postmeta} phs ON (phs.post_id = CASE WHEN p.post_type='product' THEN p.ID ELSE p.post_parent END AND phs.meta_key='_hs_code')
             LEFT JOIN {$wpdb->postmeta} porg ON (porg.post_id = CASE WHEN p.post_type='product' THEN p.ID ELSE p.post_parent END AND porg.meta_key='_country_of_origin')
             LEFT JOIN {$wpdb->postmeta} vhs ON (vhs.post_id = p.ID AND vhs.meta_key='_hs_code')
             LEFT JOIN {$wpdb->postmeta} vorg ON (vorg.post_id = p.ID AND vorg.meta_key='_country_of_origin')
             LEFT JOIN {$wpdb->postmeta} sku ON (sku.post_id=p.ID AND sku.meta_key='_sku')
             WHERE {$where}
             ORDER BY p.ID DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);

        $items = [];
        foreach ($rows as $r) {
            $missing_hs = (trim((string)$r['hs_code']) === '');
            $missing_cc = (strtoupper(trim((string)$r['origin'])) === '');
            $status_parts = [];
            if ($missing_hs) { $status_parts[] = '<span style="color:#a00;">' . esc_html__('Missing HS code', 'woocommerce-us-duties') . '</span>'; }
            if ($missing_cc) { $status_parts[] = '<span style="color:#a00;">' . esc_html__('Missing origin', 'woocommerce-us-duties') . '</span>'; }
            if (!$missing_hs && !$missing_cc) { $status_parts[] = '<span style="color:#d98300;">' . esc_html__('No profile', 'woocommerce-us-duties') . '</span>'; }
            $items[] = [
                'id' => (int)$r['ID'],
                'title' => (string)$r['post_title'],
                'type' => $r['post_type'] === 'product_variation' ? 'Variation' : 'Product',
                'sku' => (string)$r['sku'],
                'hs_code' => trim((string)$r['hs_code']),
                'origin' => strtoupper(trim((string)$r['origin'])),
                'status_html' => implode(' · ', $status_parts),
            ];
        }

        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], [], 'title'];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);
    }
}
