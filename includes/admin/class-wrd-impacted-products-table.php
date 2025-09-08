<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WRD_Impacted_Products_Table extends WP_List_Table {
    private $desc_norm;
    private $cc;
    private $search = '';

    public function __construct(string $desc_norm, string $cc) {
        parent::__construct([
            'singular' => 'wrd_impacted_product',
            'plural'   => 'wrd_impacted_products',
            'ajax'     => false,
        ]);
        $this->desc_norm = $desc_norm;
        $this->cc = strtoupper($cc);
        $this->search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'title' => __('Title', 'woocommerce-us-duties'),
            'type' => __('Type', 'woocommerce-us-duties'),
            'sku' => __('SKU', 'woocommerce-us-duties'),
            'origin' => __('Origin', 'woocommerce-us-duties'),
            'stock' => __('Stock', 'woocommerce-us-duties'),
            'desc' => __('Description', 'woocommerce-us-duties'),
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'type': return esc_html($item['type']);
            case 'sku': return esc_html($item['sku']);
            case 'origin': return esc_html($item['origin']);
            case 'stock':
                $st = (string)($item['stock'] ?? '');
                if ($st === 'instock') {
                    return '<span class="status-instock" style="color:#008a00;">' . esc_html__('In stock', 'woocommerce-us-duties') . '</span>';
                } elseif ($st === 'outofstock') {
                    return '<span class="status-outofstock" style="color:#a00;">' . esc_html__('Out of stock', 'woocommerce-us-duties') . '</span>';
                } elseif ($st === 'onbackorder') {
                    return '<span class="status-backorder" style="color:#d98300;">' . esc_html__('Backorder', 'woocommerce-us-duties') . '</span>';
                }
                return '';
            case 'desc': return esc_html($item['desc']);
        }
        return '';
    }

    protected function column_title($item) {
        $editUrl = get_edit_post_link($item['id']);
        return sprintf('<strong><a href="%s">%s</a></strong>', esc_url($editUrl), esc_html($item['title']));
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" />', (int)$item['id']);
    }

    public function get_bulk_actions() {
        return [
            'mark_instock' => __('Mark in stock', 'woocommerce-us-duties'),
            'mark_outofstock' => __('Mark out of stock', 'woocommerce-us-duties'),
        ];
    }

    public function process_bulk_action() {
        $action = $this->current_action();
        if (!$action) { return; }

        // WP_List_Table default bulk nonce uses 'bulk-' . $this->_args['plural']
        check_admin_referer('bulk-' . $this->_args['plural']);

        $ids = isset($_REQUEST['ids']) ? (array) $_REQUEST['ids'] : [];
        $ids = array_map('intval', $ids);
        if (empty($ids)) { return; }

        if (!function_exists('wc_update_product_stock_status')) { return; }

        $status = '';
        if ($action === 'mark_instock') { $status = 'instock'; }
        if ($action === 'mark_outofstock') { $status = 'outofstock'; }
        if ($status === '') { return; }

        $updated = 0;
        foreach ($ids as $pid) {
            // Update stock status for products and variations
            wc_update_product_stock_status($pid, $status);
            $updated++;
        }

        if ($updated > 0) {
            add_action('admin_notices', function() use ($updated, $status) {
                $msg = ($status === 'instock')
                    ? _n('%d product marked in stock.', '%d products marked in stock.', $updated, 'woocommerce-us-duties')
                    : _n('%d product marked out of stock.', '%d products marked out of stock.', $updated, 'woocommerce-us-duties');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf($msg, $updated)) . '</p></div>';
            });
        }
    }

    public function prepare_items() {
        global $wpdb;
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $post_types = [ 'product', 'product_variation' ];
        $inTypes = implode("','", array_map('esc_sql', $post_types));

        $where = $wpdb->prepare(
            "p.post_type IN ('{$inTypes}') AND p.post_status NOT IN ('trash','auto-draft')
             AND d.meta_key='_wrd_desc_norm' AND d.meta_value=%s
             AND c.meta_key='_wrd_origin_cc' AND c.meta_value=%s",
            $this->desc_norm, $this->cc
        );

        $searchSql = '';
        $params = [];
        if ($this->search !== '') {
            $like = '%' . $wpdb->esc_like($this->search) . '%';
            $searchSql = $wpdb->prepare(" AND (p.post_title LIKE %s OR sku.meta_value LIKE %s)", $like, $like);
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} d ON d.post_id=p.ID
            INNER JOIN {$wpdb->postmeta} c ON c.post_id=p.ID
            LEFT JOIN {$wpdb->postmeta} sku ON (sku.post_id=p.ID AND sku.meta_key='_sku')
            WHERE {$where}{$searchSql}");

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type,
                    COALESCE(vdesc.meta_value, pdesc.meta_value) AS desc_raw,
                    COALESCE(vorg.meta_value, porg.meta_value) AS origin,
                    sku.meta_value AS sku,
                    stock.meta_value AS stock_status
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} d ON d.post_id=p.ID
             INNER JOIN {$wpdb->postmeta} c ON c.post_id=p.ID
             LEFT JOIN {$wpdb->postmeta} pdesc ON (pdesc.post_id = CASE WHEN p.post_type='product' THEN p.ID ELSE p.post_parent END AND pdesc.meta_key='_customs_description')
             LEFT JOIN {$wpdb->postmeta} porg ON (porg.post_id = CASE WHEN p.post_type='product' THEN p.ID ELSE p.post_parent END AND porg.meta_key='_country_of_origin')
             LEFT JOIN {$wpdb->postmeta} vdesc ON (vdesc.post_id = p.ID AND vdesc.meta_key='_customs_description')
             LEFT JOIN {$wpdb->postmeta} vorg ON (vorg.post_id = p.ID AND vorg.meta_key='_country_of_origin')
             LEFT JOIN {$wpdb->postmeta} sku ON (sku.post_id=p.ID AND sku.meta_key='_sku')
             LEFT JOIN {$wpdb->postmeta} stock ON (stock.post_id=p.ID AND stock.meta_key='_stock_status')
             WHERE {$where}{$searchSql}
             ORDER BY p.ID DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int)$r['ID'],
                'title' => $r['post_title'],
                'type' => $r['post_type'] === 'product_variation' ? 'Variation' : 'Product',
                'sku' => (string)$r['sku'],
                'origin' => strtoupper((string)$r['origin']),
                'stock' => (string)$r['stock_status'],
                'desc' => (string)$r['desc_raw'],
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
