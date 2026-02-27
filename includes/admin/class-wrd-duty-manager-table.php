<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WRD_Duty_Manager_Table extends WP_List_Table {
    private $status_filter;
    private $type_filter;

    public function __construct(string $status = 'all', string $type = 'all') {
        parent::__construct([
            'singular' => 'wrd_hs_item',
            'plural' => 'wrd_hs_items',
            'ajax' => false,
        ]);

        $allowed_statuses = ['all', 'needs_hs', 'needs_origin', 'missing_profile', 'legacy', 'ready'];
        $allowed_types = ['all', 'product', 'variation'];
        $this->status_filter = in_array($status, $allowed_statuses, true) ? $status : 'all';
        $this->type_filter = in_array($type, $allowed_types, true) ? $type : 'all';
    }

    public function get_columns() {
        return [
            'product' => __('Product', 'woocommerce-us-duties'),
            'sku' => __('SKU', 'woocommerce-us-duties'),
            'type' => __('Type', 'woocommerce-us-duties'),
            'source' => __('Source', 'woocommerce-us-duties'),
            'hs_code' => __('HS Code', 'woocommerce-us-duties'),
            'origin' => __('Origin', 'woocommerce-us-duties'),
            'profile' => __('Profile', 'woocommerce-us-duties'),
            'status' => __('Status', 'woocommerce-us-duties'),
            'actions' => __('Actions', 'woocommerce-us-duties'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'product' => ['title', true],
            'sku' => ['sku', false],
            'type' => ['type', false],
            'status' => ['status', false],
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'sku':
            case 'type':
            case 'source':
            case 'profile':
                return (string) $item[$column_name];
            case 'status':
                return wp_kses_post($item['status_html']);
            case 'hs_code':
                return $this->render_hs_input($item);
            case 'origin':
                return $this->render_origin_input($item);
            case 'actions':
                return $this->render_actions($item);
        }

        return '';
    }

    protected function column_product($item) {
        $edit_url = get_edit_post_link((int) $item['id']);
        $name = esc_html((string) $item['name']);
        $id = (int) $item['id'];
        return '<strong><a href="' . esc_url((string) $edit_url) . '">' . $name . '</a></strong><br><small>ID: ' . esc_html((string) $id) . '</small>';
    }

    private function render_hs_input(array $item): string {
        $value = esc_attr((string) $item['local_hs']);
        $placeholder = esc_attr((string) $item['effective_hs']);
        return '<input type="text" class="wrd-duty-hs" value="' . $value . '" placeholder="' . $placeholder . '" style="width:140px;" />';
    }

    private function render_origin_input(array $item): string {
        $value = esc_attr((string) $item['local_origin']);
        $placeholder = esc_attr((string) $item['effective_origin']);
        return '<input type="text" class="wrd-duty-origin" maxlength="2" value="' . $value . '" placeholder="' . $placeholder . '" style="width:72px; text-transform:uppercase;" />';
    }

    private function render_actions(array $item): string {
        $pid = (int) $item['id'];
        $profile = '<input type="text" class="wrd-profile-lookup" placeholder="' . esc_attr__('Search profile…', 'woocommerce-us-duties') . '" style="min-width:180px;" />';
        $save = '<button type="button" class="button button-small button-primary wrd-duty-save" data-product-id="' . esc_attr((string) $pid) . '">' . esc_html__('Save', 'woocommerce-us-duties') . '</button>';
        $open = '<a class="button button-small" href="' . esc_url((string) get_edit_post_link($pid)) . '">' . esc_html__('Open', 'woocommerce-us-duties') . '</a>';
        $msg = '<span class="wrd-duty-row-status" style="margin-left:6px;"></span>';
        return $profile . ' ' . $save . ' ' . $open . $msg;
    }

    public function prepare_items() {
        $per_page = 40;
        $paged = max(1, $this->get_pagenum());
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'title';
        $order = isset($_REQUEST['order']) && strtolower((string) wp_unslash($_REQUEST['order'])) === 'asc' ? 'ASC' : 'DESC';

        $allowed_orderby = ['title', 'sku', 'type', 'status'];
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'title';
        }

        $args = [
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $paged,
            's' => $search,
            'orderby' => 'title',
            'order' => $order,
        ];

        if ($this->type_filter === 'product') {
            $args['post_type'] = ['product'];
        } elseif ($this->type_filter === 'variation') {
            $args['post_type'] = ['product_variation'];
        }

        if ($this->status_filter !== 'all') {
            $args['meta_query'] = [
                [
                    'key' => '_wrd_customs_status',
                    'value' => $this->status_filter,
                    'compare' => '=',
                ],
            ];
        }

        if ($orderby === 'sku') {
            $args['meta_key'] = '_sku';
            $args['orderby'] = 'meta_value';
        } elseif ($orderby === 'type') {
            $args['orderby'] = 'post_type';
        } elseif ($orderby === 'status') {
            $args['meta_key'] = '_wrd_customs_status_rank';
            $args['orderby'] = 'meta_value_num';
        }

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product((int) $post->ID);
            if (!$product) { continue; }

            $local_hs = trim((string) $product->get_meta('_hs_code', true));
            $local_origin = strtoupper(trim((string) $product->get_meta('_country_of_origin', true)));
            $effective = WRD_Category_Settings::get_effective_hs_code($product);

            $effective_hs = trim((string) ($effective['hs_code'] ?? ''));
            $effective_origin = strtoupper(trim((string) ($effective['origin'] ?? '')));
            $source = (string) ($effective['source'] ?? 'none');

            $profile_id = (int) get_post_meta((int) $post->ID, '_wrd_profile_id', true);
            $status = sanitize_key((string) get_post_meta((int) $post->ID, '_wrd_customs_status', true));
            if ($status === '') {
                $status = 'needs_hs';
            }

            $status_html = $this->render_status_html($status, $effective_hs, $effective_origin, $profile_id);
            $profile_text = $profile_id > 0
                ? sprintf(__('Linked #%d', 'woocommerce-us-duties'), $profile_id)
                : __('No profile', 'woocommerce-us-duties');

            $items[] = [
                'id' => (int) $post->ID,
                'name' => (string) $product->get_name(),
                'product' => '',
                'sku' => esc_html((string) $product->get_sku()),
                'type' => $product->is_type('variation') ? esc_html__('Variation', 'woocommerce-us-duties') : esc_html__('Product', 'woocommerce-us-duties'),
                'source' => esc_html($source),
                'local_hs' => $local_hs,
                'local_origin' => $local_origin,
                'effective_hs' => $effective_hs,
                'effective_origin' => $effective_origin,
                'hs_code' => '',
                'origin' => '',
                'profile' => esc_html($profile_text),
                'status' => $status,
                'status_html' => $status_html,
                'actions' => '',
            ];
        }

        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'product'];
        $this->set_pagination_args([
            'total_items' => (int) $query->found_posts,
            'per_page' => $per_page,
            'total_pages' => (int) $query->max_num_pages,
        ]);
    }

    private function render_status_html(string $status, string $hs, string $origin, int $profile_id): string {
        if ($status === 'ready') {
            return '<span style="color:#008a20;font-weight:600;">' . esc_html__('Ready', 'woocommerce-us-duties') . '</span>';
        }
        if ($status === 'missing_profile') {
            return '<span style="color:#d98300;font-weight:600;">' . esc_html__('Missing Profile', 'woocommerce-us-duties') . '</span>';
        }
        if ($status === 'legacy') {
            return '<span style="color:#996800;font-weight:600;">' . esc_html__('Legacy', 'woocommerce-us-duties') . '</span>';
        }
        if ($status === 'needs_origin') {
            return '<span style="color:#a00;font-weight:600;">' . esc_html__('Needs Origin', 'woocommerce-us-duties') . '</span>';
        }
        return '<span style="color:#a00;font-weight:600;">' . esc_html__('Needs HS', 'woocommerce-us-duties') . '</span>';
    }
}
