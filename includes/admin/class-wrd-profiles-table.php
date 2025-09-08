<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WRD_Profiles_Table extends WP_List_Table {
    private $search = '';
    private $counts = [];

    public function __construct() {
        parent::__construct([
            'singular' => 'customs_profile',
            'plural'   => 'customs_profiles',
            'ajax'     => false,
        ]);
        $this->search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'description_raw' => __('Description', 'woocommerce-us-duties'),
            'country_code' => __('Country', 'woocommerce-us-duties'),
            'hs_code' => __('HS', 'woocommerce-us-duties'),
            'products' => __('Products', 'woocommerce-us-duties'),
            'effective_from' => __('From', 'woocommerce-us-duties'),
            'effective_to' => __('To', 'woocommerce-us-duties'),
            'notes' => __('Notes', 'woocommerce-us-duties'),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'description_raw' => ['description_raw', false],
            'country_code' => ['country_code', false],
            'hs_code' => ['hs_code', false],
            'products' => ['products', false],
            'effective_from' => ['effective_from', false],
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'country_code':
                return esc_html(strtoupper((string)($item['country_code'] ?? '')));
            case 'hs_code':
                return esc_html((string)($item['hs_code'] ?? ''));
            case 'effective_from':
            case 'effective_to':
                return esc_html((string)($item[$column_name] ?? ''));
            case 'notes':
                $notes = (string)($item['notes'] ?? '');
                if ($notes === '') { return ''; }
                $short = mb_substr($notes, 0, 60);
                if (mb_strlen($notes) > 60) { $short .= '…'; }
                return esc_html($short);
        }
        // Fallback to raw for any unhandled columns
        return isset($item[$column_name]) ? esc_html((string)$item[$column_name]) : '';
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" />', (int)$item['id']);
    }

    protected function column_description_raw($item) {
        $editUrl = add_query_arg([
            'page' => 'wrd-customs',
            'tab' => 'profiles',
            'action' => 'edit',
            'id' => $item['id'],
        ], admin_url('admin.php'));
        $title = esc_html($item['description_raw']);
        $cc = esc_html($item['country_code']);
        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', esc_url($editUrl), __('Edit', 'woocommerce-us-duties')),
        ];
        return sprintf('<strong><a href="%s">%s</a></strong> <span class="description">| %s</span> %s', esc_url($editUrl), $title, $cc, $this->row_actions($actions));
    }

    protected function column_products($item) {
        $descNorm = isset($item['description_normalized']) ? (string)$item['description_normalized'] : '';
        $cc = isset($item['country_code']) ? strtoupper((string)$item['country_code']) : '';
        $key = $descNorm . '|' . $cc;
        $count = isset($this->counts[$key]) ? (int)$this->counts[$key] : 0;
        if ($descNorm === '' || $cc === '') { return '—'; }
        $url = add_query_arg([
            'page' => 'wrd-customs',
            'tab' => 'profiles',
            'action' => 'impacted',
            'desc_norm' => rawurlencode($descNorm),
            'cc' => $cc,
        ], admin_url('admin.php'));
        return sprintf('<a href="%s">%d</a>', esc_url($url), $count);
    }

    public function get_bulk_actions() {
        return [
            'set_dates' => __('Set Effective Dates…', 'woocommerce-us-duties'),
            'delete' => __('Delete', 'woocommerce-us-duties'),
        ];
    }

    public function process_bulk_action() {
        $action = $this->current_action();
        
        if ('delete' === $action) {
            check_admin_referer('bulk-customs_profiles');
            $ids = isset($_REQUEST['ids']) ? (array) $_REQUEST['ids'] : [];
            $ids = array_map('intval', $ids);
            if ($ids) {
                global $wpdb; $table = WRD_DB::table_profiles();
                $in = '(' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN {$in}", $ids));
                if ($deleted !== false) {
                    add_action('admin_notices', function() use ($deleted) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . 
                             sprintf(
                                 /* translators: %d: number of deleted profiles */
                                 _n('%d profile deleted.', '%d profiles deleted.', $deleted, 'woocommerce-us-duties'), 
                                 $deleted
                             ) . '</p></div>';
                    });
                }
            }
        } elseif ('set_dates' === $action || isset($_POST['bulk_edit'])) {
            // Handle both old and new bulk edit forms
            if (isset($_POST['bulk_edit'])) {
                check_admin_referer('wrd_bulk_edit_nonce', 'bulk_edit_nonce');
            } else {
                check_admin_referer('bulk-customs_profiles');
            }
            
            $ids = isset($_REQUEST['ids']) ? (array) $_REQUEST['ids'] : [];
            $ids = array_map('intval', $ids);
            
            if ($ids) {
                $updated = $this->handle_bulk_date_update($ids);
                if ($updated > 0) {
                    add_action('admin_notices', function() use ($updated) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . 
                             sprintf(
                                 /* translators: %d: number of updated profiles */
                                 _n('%d profile updated.', '%d profiles updated.', $updated, 'woocommerce-us-duties'), 
                                 $updated
                             ) . '</p></div>';
                    });
                }
            }
        }
    }

    private function handle_bulk_date_update($ids) {
        global $wpdb;
        $table = WRD_DB::table_profiles();
        $updated = 0;
        
        // Handle new bulk edit format
        $from_action = isset($_REQUEST['bulk_effective_from_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_from_action'])) : '';
        $to_action = isset($_REQUEST['bulk_effective_to_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_to_action'])) : '';
        $from_date = isset($_REQUEST['bulk_effective_from']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_from'])) : '';
        $to_date = isset($_REQUEST['bulk_effective_to']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_to'])) : '';
        
        // Fallback to old format for backward compatibility
        if (empty($from_action) && empty($to_action)) {
            $from_action = !empty($_REQUEST['bulk_set_from']) ? 'set' : '';
            $to_action = !empty($_REQUEST['bulk_set_to']) ? 'set' : '';
            if (!empty($_REQUEST['bulk_clear_to'])) {
                $to_action = 'clear';
            }
        }
        
        // Validate dates
        $is_date = function($d) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); };
        
        foreach ($ids as $id) {
            $data = [];
            $fmt = [];
            
            // Handle effective_from
            if ($from_action === 'set' && $is_date($from_date)) {
                $data['effective_from'] = $from_date;
                $fmt[] = '%s';
            } elseif ($from_action === 'clear') {
                $data['effective_from'] = null;
                $fmt[] = '%s';
            }
            
            // Handle effective_to
            if ($to_action === 'set' && $is_date($to_date)) {
                $data['effective_to'] = $to_date;
                $fmt[] = '%s';
            } elseif ($to_action === 'clear') {
                $data['effective_to'] = null;
                $fmt[] = '%s';
            }
            
            if (!empty($data)) {
                $result = $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
                if ($result !== false) {
                    $updated++;
                }
            }
        }
        
        return $updated;
    }

    public function prepare_items() {
        global $wpdb; $table = WRD_DB::table_profiles();
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $where = '1=1';
        $params = [];
        if ($this->search !== '') {
            $where .= ' AND (t.description_raw LIKE %s OR t.country_code LIKE %s OR t.hs_code LIKE %s)';
            $like = '%' . $wpdb->esc_like($this->search) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'id';
        $order = (isset($_REQUEST['order']) && strtolower($_REQUEST['order']) === 'asc') ? 'ASC' : 'DESC';
        $allowed = ['id','description_raw','country_code','hs_code','effective_from','products'];
        if (!in_array($orderby, $allowed, true)) { $orderby = 'id'; }

        $total = (int) $wpdb->get_var($params
            ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} t WHERE {$where}", $params)
            : "SELECT COUNT(*) FROM {$table} t WHERE {$where}"
        );

        if ($orderby === 'products') {
            // Fetch all rows first (to compute counts across entire set), then sort in PHP by impacted count
            $sqlAll = "SELECT t.* FROM {$table} AS t WHERE {$where}";
            $allRows = $params
                ? $wpdb->get_results($wpdb->prepare($sqlAll, $params), ARRAY_A)
                : $wpdb->get_results($sqlAll, ARRAY_A);

            $countsAll = $this->fetch_counts_for_rows($allRows);
            // Attach count and sort
            foreach ($allRows as &$r) {
                $desc = isset($r['description_normalized']) ? (string)$r['description_normalized'] : '';
                $cc = isset($r['country_code']) ? strtoupper((string)$r['country_code']) : '';
                $key = $desc . '|' . $cc;
                $r['__impacted_cnt'] = isset($countsAll[$key]) ? (int)$countsAll[$key] : 0;
            }
            unset($r);
            usort($allRows, function($a, $b) use ($order) {
                $av = (int)($a['__impacted_cnt'] ?? 0);
                $bv = (int)($b['__impacted_cnt'] ?? 0);
                if ($av === $bv) { return 0; }
                return ($order === 'ASC') ? ($av <=> $bv) : ($bv <=> $av);
            });

            $total = count($allRows);
            $rows = array_slice($allRows, $offset, $per_page);
            $this->items = $rows;
            $this->counts = $countsAll;
        } else {
            // SQL-level ordering for other columns
            $orderExpr = 't.' . $orderby; // safe
            $sql = "SELECT t.* FROM {$table} AS t WHERE {$where} ORDER BY {$orderExpr} {$order} LIMIT %d OFFSET %d";
            $rows = $params
                ? $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per_page, $offset])), ARRAY_A)
                : $wpdb->get_results($wpdb->prepare($sql, $per_page, $offset), ARRAY_A);

            $this->items = $rows;
            $this->counts = $this->fetch_counts_for_rows($rows);
        }
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'description_raw'];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);
    }

    private function fetch_counts_for_rows(array $rows): array {
        if (empty($rows)) { return []; }
        global $wpdb;
        $pairs = [];
        foreach ($rows as $r) {
            $desc = isset($r['description_normalized']) ? (string)$r['description_normalized'] : '';
            $cc = isset($r['country_code']) ? strtoupper((string)$r['country_code']) : '';
            if ($desc !== '' && $cc !== '') {
                $pairs[$desc . '|' . $cc] = [$desc, $cc];
            }
        }
        if (empty($pairs)) { return []; }

        // Build OR conditions for pairs
        $conds = [];
        $params = [];
        foreach ($pairs as [$desc, $cc]) {
            $conds[] = '(d.meta_value = %s AND c.meta_value = %s)';
            $params[] = $desc; $params[] = $cc;
        }
        $wherePairs = implode(' OR ', $conds);

        $post_types = [ 'product', 'product_variation' ];
        $inTypes = implode("','", array_map('esc_sql', $post_types));

        $sql = "SELECT d.meta_value AS desc_norm, c.meta_value AS cc, COUNT(p.ID) AS cnt
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} d ON (d.post_id = p.ID AND d.meta_key = '_wrd_desc_norm')
                INNER JOIN {$wpdb->postmeta} c ON (c.post_id = p.ID AND c.meta_key = '_wrd_origin_cc')
                WHERE p.post_type IN ('{$inTypes}')
                  AND p.post_status NOT IN ('trash','auto-draft')
                  AND ({$wherePairs})
                GROUP BY d.meta_value, c.meta_value";
        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['desc_norm'] . '|' . strtoupper((string)$r['cc'])] = (int)$r['cnt'];
        }
        return $map;
    }
}
