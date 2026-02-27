<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WRD_Reconciliation_Table extends WP_List_Table {
    private $status;
    private $filters = [];
    private $records = [];
    private $status_counts = [];
    private $indexed = false;

    public function __construct(string $status = 'needs_data', array $filters = []) {
        parent::__construct([
            'singular' => 'wrd_reconcile_product',
            'plural'   => 'wrd_reconcile_products',
            'ajax'     => false,
        ]);
        $category_filter = 'all';
        if (isset($filters['category'])) {
            $raw_category = $filters['category'];
            if (is_numeric($raw_category)) {
                $category_filter = (string) max(0, (int) $raw_category);
            } else {
                $category_filter = sanitize_key((string) $raw_category);
            }
        }
        $this->status = $this->normalize_status($status);
        $this->filters = [
            'type' => isset($filters['type']) ? sanitize_key((string) $filters['type']) : 'all',
            'source' => isset($filters['source']) ? sanitize_key((string) $filters['source']) : 'all',
            'category' => $category_filter,
        ];
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" class="wrd-reconcile-select-all" />',
            'title' => __('Product', 'woocommerce-us-duties'),
            'sku' => __('SKU', 'woocommerce-us-duties'),
            'type' => __('Type', 'woocommerce-us-duties'),
            'source' => __('Source', 'woocommerce-us-duties'),
            'hs_code' => __('HS Code', 'woocommerce-us-duties'),
            'origin' => __('Origin', 'woocommerce-us-duties'),
            'status' => __('Status', 'woocommerce-us-duties'),
            'assign' => __('Action', 'woocommerce-us-duties'),
        ];
    }

    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" class="wrd-reconcile-select" name="wrd_selected_products[]" value="%d" />',
            (int) $item['id']
        );
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'sku': return esc_html($item['sku']);
            case 'type': return esc_html($item['type']);
            case 'source': return esc_html($item['source_label']);
            case 'status': return wp_kses_post($item['status_html']);
        }
        return '';
    }

    protected function column_title($item) {
        $editUrl = get_edit_post_link($item['id']);
        return sprintf('<strong><a href="%s">%s</a></strong>', esc_url($editUrl), esc_html($item['title']));
    }

    protected function column_hs_code($item) {
        $pid = (int)$item['id'];
        $hs_id = 'wrd-hs-' . $pid;
        return '<label class="screen-reader-text" for="' . esc_attr($hs_id) . '">' . esc_html__('HS code', 'woocommerce-us-duties') . '</label>'
             . '<input type="text" id="' . esc_attr($hs_id) . '" class="wrd-hs" data-product="' . esc_attr($pid) . '" placeholder="' . esc_attr__('HS Code', 'woocommerce-us-duties') . '" value="' . esc_attr($item['hs_code']) . '" />';
    }

    protected function column_origin($item) {
        $pid = (int)$item['id'];
        $cc_id = 'wrd-cc-' . $pid;
        return '<label class="screen-reader-text" for="' . esc_attr($cc_id) . '">' . esc_html__('Country code', 'woocommerce-us-duties') . '</label>'
             . '<input type="text" id="' . esc_attr($cc_id) . '" class="wrd-cc" data-product="' . esc_attr($pid) . '" placeholder="' . esc_attr__('ISO-2', 'woocommerce-us-duties') . '" maxlength="2" value="' . esc_attr($item['origin']) . '" />';
    }

    protected function column_assign($item) {
        $pid = (int) $item['id'];
        return '<div class="wrd-row-actions" data-product="' . esc_attr($pid) . '">'
             . '<button type="button" class="button button-primary button-small wrd-apply" data-product="' . esc_attr($pid) . '">' . esc_html__('Apply', 'woocommerce-us-duties') . '</button>'
             . '<span class="wrd-status" aria-live="polite" role="status"></span>'
             . '</div>';
    }

    public function get_status_counts(): array {
        $this->ensure_indexed();
        return $this->status_counts;
    }

    public function prepare_items() {
        $this->ensure_indexed();

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $items = array_values(array_filter($this->records, function (array $record): bool {
            return $this->matches_status_filter($record['status_key']);
        }));

        $total = count($items);
        $this->items = array_slice($items, $offset, $per_page);
        $this->_column_headers = [$this->get_columns(), [], [], 'title'];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    private function ensure_indexed(): void {
        if ($this->indexed) { return; }

        $this->status_counts = [
            'needs_data' => 0,
            'no_match' => 0,
            'warnings' => 0,
            'ready' => 0,
            'all' => 0,
        ];

        $ids = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);

        $profile_cache = [];
        foreach ($ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product) { continue; }

            $record = $this->build_record($product, $profile_cache);
            if (!$this->matches_secondary_filters($record)) { continue; }

            $this->records[] = $record;
            $this->status_counts['all']++;
            if (isset($this->status_counts[$record['status_key']])) {
                $this->status_counts[$record['status_key']]++;
            }
        }

        $this->indexed = true;
    }

    private function build_record(WC_Product $product, array &$profile_cache): array {
        $pid = (int) $product->get_id();
        $effective = WRD_Category_Settings::get_effective_hs_code($product);
        $hs_code = trim((string) ($effective['hs_code'] ?? ''));
        $origin = strtoupper(trim((string) ($effective['origin'] ?? '')));
        $missing_hs = ($hs_code === '');
        $missing_origin = ($origin === '');
        $type_key = $product->is_type('variation') ? 'variation' : 'product';
        $type_label = $type_key === 'variation' ? 'Variation' : 'Product';

        $source = $this->resolve_source_bucket($product, $effective);
        $source_labels = [
            'explicit' => __('Explicit', 'woocommerce-us-duties'),
            'category' => __('Category', 'woocommerce-us-duties'),
            'parent' => __('Parent Variation', 'woocommerce-us-duties'),
            'none' => __('None', 'woocommerce-us-duties'),
        ];
        $source_label = $source_labels[$source] ?? ucfirst($source);

        $has_profile = false;
        if (!$missing_hs && !$missing_origin) {
            $cache_key = $hs_code . '|' . $origin;
            if (!array_key_exists($cache_key, $profile_cache)) {
                $profile_cache[$cache_key] = (bool) WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            }
            $has_profile = $profile_cache[$cache_key];
        }

        $warnings = $this->collect_warnings($product, $hs_code, $origin, $has_profile);
        $status_key = 'ready';
        $status_parts = [];
        if ($missing_hs || $missing_origin) {
            $status_key = 'needs_data';
            if ($missing_hs) {
                $status_parts[] = '<span style="color:#a00;">' . esc_html__('Enter HS code in the HS Code column', 'woocommerce-us-duties') . '</span>';
            }
            if ($missing_origin) {
                $status_parts[] = '<span style="color:#a00;">' . esc_html__('Enter origin in the Origin column', 'woocommerce-us-duties') . '</span>';
            }
        } elseif (!$has_profile) {
            $status_key = 'no_match';
            $status_parts[] = '<span style="color:#d98300;">' . esc_html__('No matching profile', 'woocommerce-us-duties') . '</span>';
        } elseif (!empty($warnings)) {
            $status_key = 'warnings';
            foreach ($warnings as $warning) {
                $status_parts[] = '<span style="color:#996800;">' . esc_html($warning) . '</span>';
            }
        } else {
            $status_parts[] = '<span style="color:#008a20;">' . esc_html__('Ready', 'woocommerce-us-duties') . '</span>';
        }

        if (!empty($effective['source']) && strpos((string) $effective['source'], 'category:') === 0) {
            $status_parts[] = '<span style="color:#2271b1;">' . esc_html(sprintf(__('Inherited from %s', 'woocommerce-us-duties'), substr((string) $effective['source'], 9))) . '</span>';
        }

        return [
            'id' => $pid,
            'title' => (string) $product->get_name(),
            'sku' => (string) $product->get_sku(),
            'type' => $type_label,
            'type_key' => $type_key,
            'source_key' => $source,
            'source_label' => $source_label,
            'category_ids' => $this->resolve_category_ids($product),
            'hs_code' => $hs_code,
            'origin' => $origin,
            'status_key' => $status_key,
            'status_html' => implode(' · ', $status_parts),
        ];
    }

    private function collect_warnings(WC_Product $product, string $hs_code, string $origin, bool $has_profile): array {
        $warnings = [];

        if ($origin !== '' && !preg_match('/^[A-Z]{2}$/', $origin)) {
            $warnings[] = __('Invalid origin format', 'woocommerce-us-duties');
        }
        if ($hs_code !== '' && !preg_match('/^[0-9]{4,12}(\\.[0-9]{1,4})*$/', $hs_code)) {
            $warnings[] = __('HS code format looks unusual', 'woocommerce-us-duties');
        }

        $linked_profile_id = (int) $product->get_meta('_wrd_profile_id', true);
        if ($linked_profile_id > 0) {
            $linked = WRD_DB::get_profile_by_id($linked_profile_id);
            if (!$linked) {
                $warnings[] = __('Linked profile missing', 'woocommerce-us-duties');
            } else {
                $linked_hs = trim((string) ($linked['hs_code'] ?? ''));
                $linked_cc = strtoupper(trim((string) ($linked['country_code'] ?? '')));
                if (($hs_code !== '' && $linked_hs !== $hs_code) || ($origin !== '' && $linked_cc !== $origin)) {
                    $warnings[] = __('Linked profile mismatch', 'woocommerce-us-duties');
                }
            }
        }

        if ($has_profile && $origin === 'US') {
            $warnings[] = __('Origin set to US; verify duty expectations', 'woocommerce-us-duties');
        }

        return array_values(array_unique($warnings));
    }

    private function resolve_source_bucket(WC_Product $product, array $effective): string {
        $source = (string) ($effective['source'] ?? '');
        if (strpos($source, 'category:') === 0) {
            return 'category';
        }

        $local_hs = trim((string) $product->get_meta('_hs_code', true));
        $local_origin = strtoupper(trim((string) $product->get_meta('_country_of_origin', true)));
        if ($local_hs !== '' || $local_origin !== '') {
            return 'explicit';
        }

        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $parent_hs = trim((string) $parent->get_meta('_hs_code', true));
                $parent_origin = strtoupper(trim((string) $parent->get_meta('_country_of_origin', true)));
                if ($parent_hs !== '' || $parent_origin !== '') {
                    return 'parent';
                }
            }
        }

        return 'none';
    }

    private function matches_secondary_filters(array $record): bool {
        if (!in_array($this->filters['type'], ['all', 'product', 'variation'], true)) {
            return false;
        }
        if (!in_array($this->filters['source'], ['all', 'explicit', 'category', 'parent', 'none'], true)) {
            return false;
        }
        $category_filter = (string) ($this->filters['category'] ?? 'all');
        if ($category_filter !== 'all' && !ctype_digit($category_filter)) {
            return false;
        }
        if ($this->filters['type'] !== 'all' && $record['type_key'] !== $this->filters['type']) {
            return false;
        }
        if ($this->filters['source'] !== 'all' && $record['source_key'] !== $this->filters['source']) {
            return false;
        }
        if ($category_filter !== 'all') {
            $category_id = (int) $category_filter;
            $record_categories = isset($record['category_ids']) && is_array($record['category_ids']) ? $record['category_ids'] : [];
            if ($category_id <= 0 || !in_array($category_id, $record_categories, true)) {
                return false;
            }
        }
        return true;
    }

    private function resolve_category_ids(WC_Product $product): array {
        $category_ids = array_map('intval', (array) $product->get_category_ids());
        if (!empty($category_ids)) {
            return array_values(array_unique(array_filter($category_ids)));
        }

        if ($product->is_type('variation')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    $parent_categories = array_map('intval', (array) $parent->get_category_ids());
                    return array_values(array_unique(array_filter($parent_categories)));
                }
            }
        }

        return [];
    }

    private function normalize_status(string $status): string {
        $status = sanitize_key($status);
        $map = [
            'any_missing' => 'needs_data',
            'missing_desc' => 'needs_data',
            'missing_origin' => 'needs_data',
            'no_profile' => 'no_match',
            'needs_data' => 'needs_data',
            'no_match' => 'no_match',
            'warnings' => 'warnings',
            'ready' => 'ready',
            'all' => 'all',
        ];
        return $map[$status] ?? 'needs_data';
    }

    private function matches_status_filter(string $status_key): bool {
        return $this->status === 'all' || $this->status === $status_key;
    }
}
