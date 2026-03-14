<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WRD_Reconciliation_Table extends WP_List_Table {
    private $status;
    private $filters = [];
    private $search = '';
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
            'stock' => $this->normalize_stock_filter($filters['stock'] ?? 'all'),
        ];
        $this->search = isset($filters['search']) ? sanitize_text_field((string) $filters['search']) : '';
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" class="wrd-reconcile-select-all" />',
            'title' => __('Product', 'woocommerce-us-duties'),
            'sku' => __('SKU', 'woocommerce-us-duties'),
            'source' => __('Duty', 'woocommerce-us-duties'),
            'stock_status' => __('Stock Status', 'woocommerce-us-duties'),
            'hs_code' => __('HS Code', 'woocommerce-us-duties'),
            'origin' => __('Origin', 'woocommerce-us-duties'),
            'metal_232' => __('232 USD', 'woocommerce-us-duties'),
            'status' => __('Status', 'woocommerce-us-duties'),
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
            case 'source':
                return !empty($item['duty_rates_html']) ? $item['duty_rates_html'] : '<span class="wrd-duty-empty">&mdash;</span>';
            case 'stock_status': return wp_kses_post($item['stock_status_html']);
            case 'status': return wp_kses_post($item['status_html']);
        }
        return '';
    }

    protected function column_title($item) {
        $editUrl = get_edit_post_link($item['id']);
        $type_marker = '';
        if (($item['type_key'] ?? '') === 'variation') {
            $type_marker = ' <span class="wrd-product-kind-badge" title="' . esc_attr__('Variation', 'woocommerce-us-duties') . '" aria-label="' . esc_attr__('Variation', 'woocommerce-us-duties') . '">VAR</span>';
        }

        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s',
            esc_url($editUrl),
            esc_html($item['title']),
            $type_marker
        );
    }

    private function render_status_badge(string $label, string $tone = 'neutral', string $help = ''): string {
        $allowed_tones = ['critical', 'warning', 'ready', 'info', 'neutral'];
        if (!in_array($tone, $allowed_tones, true)) {
            $tone = 'neutral';
        }
        $title_attr = $help !== '' ? ' title="' . esc_attr($help) . '"' : '';
        return '<span class="wrd-status-badge wrd-status-badge-' . esc_attr($tone) . '"' . $title_attr . '>'
            . esc_html($label)
            . '</span>';
    }

    protected function column_hs_code($item) {
        $pid = (int)$item['id'];
        $hs_id = 'wrd-hs-' . $pid;
        return '<label class="screen-reader-text" for="' . esc_attr($hs_id) . '">' . esc_html__('HS code', 'woocommerce-us-duties') . '</label>'
             . '<div class="wrd-hs-editor" data-product="' . esc_attr($pid) . '">'
             . '<input type="text" id="' . esc_attr($hs_id) . '" class="wrd-hs" data-product="' . esc_attr($pid) . '" placeholder="' . esc_attr__('HS code or saved rule', 'woocommerce-us-duties') . '" value="' . esc_attr($item['hs_code']) . '" />'
             . '<input type="hidden" class="wrd-selected-profile-id" value="' . esc_attr((string) ($item['profile_id'] ?? 0)) . '" />'
             . '<input type="hidden" class="wrd-requires-232" value="' . (!empty($item['requires_232']) ? '1' : '0') . '" />'
             . '<div class="wrd-hs-editor-footer"><button type="button" class="button button-secondary button-small wrd-apply" data-product="' . esc_attr($pid) . '">' . esc_html__('Save', 'woocommerce-us-duties') . '</button><span class="wrd-status" aria-live="polite" role="status"></span></div>'
             . '</div>';
    }

    protected function column_origin($item) {
        $pid = (int)$item['id'];
        $cc_id = 'wrd-cc-' . $pid;
        return '<label class="screen-reader-text" for="' . esc_attr($cc_id) . '">' . esc_html__('Country code', 'woocommerce-us-duties') . '</label>'
             . '<input type="text" id="' . esc_attr($cc_id) . '" class="wrd-cc" data-product="' . esc_attr($pid) . '" placeholder="' . esc_attr__('ISO-2', 'woocommerce-us-duties') . '" maxlength="2" value="' . esc_attr($item['origin']) . '" />';
    }

    protected function column_metal_232($item) {
        $pid = (int)$item['id'];
        $metal_id = 'wrd-232-' . $pid;
        $value = $item['metal_232'];
        return '<label class="screen-reader-text" for="' . esc_attr($metal_id) . '">' . esc_html__('Section 232 metal value in USD', 'woocommerce-us-duties') . '</label>'
             . '<input type="number" id="' . esc_attr($metal_id) . '" class="wrd-232-metal" data-product="' . esc_attr($pid) . '" min="0" step="0.01" placeholder="' . esc_attr__('232 USD', 'woocommerce-us-duties') . '" value="' . esc_attr($value) . '" />';
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
        global $wpdb;

        if ($this->indexed) { return; }

        $this->status_counts = [
            'needs_data' => 0,
            'no_match' => 0,
            'warnings' => 0,
            'ready' => 0,
            'all' => 0,
        ];

        $where = [
            "p.post_type IN ('product', 'product_variation')",
            "p.post_status IN ('publish', 'draft', 'pending', 'private')",
        ];

        $join = [
            "LEFT JOIN {$wpdb->postmeta} sku ON (sku.post_id = p.ID AND sku.meta_key = '_sku')",
            "LEFT JOIN {$wpdb->posts} parent_p ON (parent_p.ID = p.post_parent AND p.post_type = 'product_variation')",
            "LEFT JOIN {$wpdb->postmeta} parent_sku ON (parent_sku.post_id = parent_p.ID AND parent_sku.meta_key = '_sku')",
        ];

        if ($this->search !== '') {
            $like = '%' . $wpdb->esc_like($this->search) . '%';
            $where[] = $wpdb->prepare(
                '(p.post_title LIKE %s OR sku.meta_value LIKE %s OR parent_p.post_title LIKE %s OR parent_sku.meta_value LIKE %s)',
                $like,
                $like,
                $like,
                $like
            );
        }

        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             " . implode("\n", $join) . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.ID DESC'
        );
        $ids = array_map('intval', $ids);

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
        $stock_status_key = $this->normalize_stock_status((string) $product->get_stock_status());
        $stock_status_label = $this->get_stock_status_labels()[$stock_status_key] ?? __('N/A', 'woocommerce-us-duties');

        $has_profile = false;
        $matched_profile = null;
        $duty_rates_html = '';
        if (!$missing_hs && !$missing_origin) {
            $cache_key = $hs_code . '|' . $origin;
            if (!array_key_exists($cache_key, $profile_cache)) {
                $profile_cache[$cache_key] = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            }
            $matched_profile = $profile_cache[$cache_key];
            $has_profile = is_array($matched_profile);
            if ($has_profile) {
                $duty_rates_html = $this->render_duty_rates_meta($matched_profile);
            }
        }

        $warnings = $this->collect_warnings($product, $hs_code, $origin, $has_profile, $matched_profile);
        $requires_232 = ($has_profile && $this->profile_requires_section_232($matched_profile));
        $metal_232 = $this->get_effective_232_value_for_product($product);
        $missing_232 = ($requires_232 && $metal_232 === null);
        $status_key = 'ready';
        $status_parts = [];
        if ($missing_hs || $missing_origin || $missing_232) {
            $status_key = 'needs_data';
            if ($missing_hs) {
                $status_parts[] = $this->render_status_badge(
                    __('HS Missing', 'woocommerce-us-duties'),
                    'critical',
                    __('No HS code is set. Enter an HS code in the HS Code column and click Apply.', 'woocommerce-us-duties')
                );
            }
            if ($missing_origin) {
                $status_parts[] = $this->render_status_badge(
                    __('Origin Missing', 'woocommerce-us-duties'),
                    'critical',
                    __('No origin country is set. Enter a 2-letter country code in Origin and click Apply.', 'woocommerce-us-duties')
                );
            }
            if ($missing_232) {
                $status_parts[] = $this->render_status_badge(
                    __('232 Metal Missing', 'woocommerce-us-duties'),
                    'critical',
                    __('This duty rule requires a Section 232 metal value. Enter a per-product USD metal value and click Apply.', 'woocommerce-us-duties')
                );
            }
            foreach ($warnings as $warning) {
                $status_parts[] = $this->render_status_badge(
                    $warning,
                    'warning',
                    $warning
                );
            }
        } elseif (!$has_profile) {
            $status_key = 'no_match';
            $status_parts[] = $this->render_status_badge(
                __('No Duty Rule', 'woocommerce-us-duties'),
                'warning',
                __('HS code and origin are set, but no matching duty rule exists for this pair.', 'woocommerce-us-duties')
            );
        } elseif (!empty($warnings)) {
            $status_key = 'warnings';
            foreach ($warnings as $warning) {
                $status_parts[] = $this->render_status_badge(
                    $warning,
                    'warning',
                    $warning
                );
            }
        } else {
            $status_parts[] = $this->render_status_badge(
                __('Ready', 'woocommerce-us-duties'),
                'ready',
                __('Classification data is complete and a matching duty rule was found.', 'woocommerce-us-duties')
            );
        }

        if ($requires_232 && !$missing_232) {
            $status_parts[] = $this->render_status_badge(
                __('232 Rule', 'woocommerce-us-duties'),
                'info',
                __('This matched duty rule includes Section 232 data. Verify the metal value is correct for this product.', 'woocommerce-us-duties')
            );
        }

        if (!empty($effective['source']) && strpos((string) $effective['source'], 'category:') === 0) {
            $category_name = substr((string) $effective['source'], 9);
            $status_parts[] = $this->render_status_badge(
                __('Inherited', 'woocommerce-us-duties'),
                'info',
                sprintf(__('Values are inherited from category: %s', 'woocommerce-us-duties'), $category_name)
            );
        }

        $status_parts[] = $this->render_source_marker(
            $source,
            $source_label
        );

        return [
            'id' => $pid,
            'title' => (string) $product->get_name(),
            'sku' => (string) $product->get_sku(),
            'type' => $type_label,
            'type_key' => $type_key,
            'source_key' => $source,
            'source_label' => $source_label,
            'duty_rates_html' => $duty_rates_html,
            'category_ids' => $this->resolve_category_ids($product),
            'stock_status_key' => $stock_status_key,
            'stock_status_html' => $this->render_stock_status_badge($stock_status_key, $stock_status_label),
            'hs_code' => $hs_code,
            'origin' => $origin,
            'profile_id' => (int) ($matched_profile['id'] ?? $product->get_meta('_wrd_profile_id', true)),
            'metal_232' => $metal_232 === null ? '' : (string) round((float) $metal_232, 2),
            'requires_232' => $requires_232,
            'status_key' => $status_key,
            'status_html' => '<div class="wrd-status-badges">' . implode('', $status_parts) . '</div>',
        ];
    }

    private function collect_warnings(WC_Product $product, string $hs_code, string $origin, bool $has_profile, ?array $matched_profile = null): array {
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
                $warnings[] = __('Linked duty rule missing', 'woocommerce-us-duties');
            } else {
                $linked_hs = trim((string) ($linked['hs_code'] ?? ''));
                $linked_cc = strtoupper(trim((string) ($linked['country_code'] ?? '')));
                if (($hs_code !== '' && $linked_hs !== $hs_code) || ($origin !== '' && $linked_cc !== $origin)) {
                    $warnings[] = __('Linked duty rule mismatch', 'woocommerce-us-duties');
                }
            }
        }

        if ($has_profile && $origin === 'US') {
            $warnings[] = __('Origin set to US; verify duty expectations', 'woocommerce-us-duties');
        }

        return array_values(array_unique($warnings));
    }

    private function profile_requires_section_232(?array $profile): bool {
        if (!is_array($profile)) { return false; }
        $udj = isset($profile['us_duty_json']) ? $profile['us_duty_json'] : null;
        if (is_string($udj)) { $udj = json_decode($udj, true); }
        if (!is_array($udj)) { return false; }
        foreach (['postal', 'commercial'] as $channel) {
            if (!empty($udj[$channel]['components']) && is_array($udj[$channel]['components'])) {
                foreach ($udj[$channel]['components'] as $component) {
                    if (!is_array($component)) { continue; }
                    $code = sanitize_key((string)($component['code'] ?? ''));
                    if (strpos($code, '232') !== false) { return true; }
                }
            }
            if (!empty($udj[$channel]['rates']) && (is_array($udj[$channel]['rates']) || is_object($udj[$channel]['rates']))) {
                $rates = is_object($udj[$channel]['rates']) ? (array)$udj[$channel]['rates'] : $udj[$channel]['rates'];
                foreach ($rates as $rateKey => $value) {
                    if (is_numeric($value) && strpos(sanitize_key((string)$rateKey), '232') !== false) { return true; }
                }
            }
        }
        return false;
    }

    private function get_effective_232_value_for_product(WC_Product $product): ?float {
        if ($product->is_type('variation')) {
            $mode = (string)$product->get_meta('_wrd_232_basis_mode', true);
            if ($mode === 'none') { return 0.0; }
            if ($mode === 'explicit') {
                $raw = $product->get_meta('_wrd_232_metal_value_usd', true);
                return is_numeric($raw) ? max(0.0, (float)$raw) : null;
            }
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $raw = $parent->get_meta('_wrd_232_metal_value_usd', true);
                return is_numeric($raw) ? max(0.0, (float)$raw) : null;
            }
            return null;
        }
        $raw = $product->get_meta('_wrd_232_metal_value_usd', true);
        return is_numeric($raw) ? max(0.0, (float)$raw) : null;
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
        if (!in_array($this->filters['stock'], ['all', 'instock', 'outofstock', 'onbackorder'], true)) {
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
        if ($this->filters['stock'] !== 'all' && ($record['stock_status_key'] ?? '') !== $this->filters['stock']) {
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

    private function normalize_stock_filter($stock_filter): string {
        $stock_filter = sanitize_key((string) $stock_filter);
        return in_array($stock_filter, ['all', 'instock', 'outofstock', 'onbackorder'], true) ? $stock_filter : 'all';
    }

    private function normalize_stock_status(string $stock_status): string {
        $stock_status = sanitize_key($stock_status);
        return in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true) ? $stock_status : 'unknown';
    }

    private function get_stock_status_labels(): array {
        $options = function_exists('wc_get_stock_status_options') ? wc_get_stock_status_options() : [];
        $labels = [];

        foreach (['instock', 'outofstock', 'onbackorder'] as $stock_status) {
            if (isset($options[$stock_status]) && $options[$stock_status] !== '') {
                $labels[$stock_status] = (string) $options[$stock_status];
                continue;
            }

            if ($stock_status === 'instock') {
                $labels[$stock_status] = __('In stock', 'woocommerce-us-duties');
            } elseif ($stock_status === 'onbackorder') {
                $labels[$stock_status] = __('Backorder', 'woocommerce-us-duties');
            } else {
                $labels[$stock_status] = __('Out of stock', 'woocommerce-us-duties');
            }
        }
        $labels['unknown'] = __('N/A', 'woocommerce-us-duties');

        return $labels;
    }

    private function render_stock_status_badge(string $stock_status_key, string $label): string {
        $short_label = $this->get_stock_status_short_label($stock_status_key);

        return '<div class="wrd-status-badges wrd-status-badges-stock">'
            . '<span class="wrd-status-badge wrd-status-badge-stock wrd-status-badge-stock-' . esc_attr($stock_status_key) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">'
            . '<span class="wrd-status-badge-dot" aria-hidden="true"></span>'
            . '<span class="wrd-status-badge-text" aria-hidden="true">' . esc_html($short_label) . '</span>'
            . '</span>'
            . '</div>';
    }

    private function render_duty_rates_meta(array $profile): string {
        $udj = $profile['us_duty_json'] ?? null;
        if (is_string($udj)) {
            $udj = json_decode($udj, true);
        }
        if (!is_array($udj) || !class_exists('WRD_Duty_Engine')) {
            return '';
        }

        $postal_rate = WRD_Duty_Engine::compute_rate_percent($udj, 'postal');
        $commercial_rate = WRD_Duty_Engine::compute_rate_percent($udj, 'commercial');

        return '<div class="wrd-source-meta" title="' . esc_attr__('Matched duty rates', 'woocommerce-us-duties') . '">'
            . '<span>P ' . esc_html($this->format_duty_rate($postal_rate)) . '</span>'
            . '<span>C ' . esc_html($this->format_duty_rate($commercial_rate)) . '</span>'
            . '</div>';
    }

    private function render_source_marker(string $source_key, string $source_label): string {
        $marker_map = [
            'explicit' => 'E',
            'category' => 'C',
            'parent' => 'P',
            'none' => 'N',
        ];
        $marker = $marker_map[$source_key] ?? 'N';
        $title = $source_label !== '' ? $source_label : __('None', 'woocommerce-us-duties');

        return '<div class="wrd-source-marker-wrap">'
            . '<span class="wrd-source-marker wrd-source-marker-' . esc_attr($source_key) . '" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '">'
            . esc_html($marker)
            . '</span>'
            . '</div>';
    }

    private function format_duty_rate($rate): string {
        $value = is_numeric($rate) ? (float) $rate : 0.0;
        $formatted = number_format($value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted . '%';
    }

    private function get_stock_status_short_label(string $stock_status_key): string {
        if ($stock_status_key === 'instock') {
            return __('In', 'woocommerce-us-duties');
        }
        if ($stock_status_key === 'onbackorder') {
            return __('Back', 'woocommerce-us-duties');
        }
        if ($stock_status_key === 'outofstock') {
            return __('Out', 'woocommerce-us-duties');
        }

        return __('N/A', 'woocommerce-us-duties');
    }

    private function matches_status_filter(string $status_key): bool {
        return $this->status === 'all' || $this->status === $status_key;
    }
}
