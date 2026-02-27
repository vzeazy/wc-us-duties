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
            'active' => __('Active', 'woocommerce-us-duties'),
            'postal_rate' => __('Postal %', 'woocommerce-us-duties'),
            'commercial_rate' => __('Commercial %', 'woocommerce-us-duties'),
            'notes' => __('Notes', 'woocommerce-us-duties'),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'description_raw' => ['description_raw', false],
            'country_code' => ['country_code', false],
            'hs_code' => ['hs_code', false],
            'products' => ['products', false],
            'active' => ['effective_from', false],
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'country_code':
                return esc_html(strtoupper((string)($item['country_code'] ?? '')));
            case 'hs_code':
                return esc_html((string)($item['hs_code'] ?? ''));
            case 'postal_rate':
            case 'commercial_rate':
                // Expect computed aliases from query; format as percentage with up to 4 decimals
                $key = $column_name;
                if (!isset($item[$key])) {
                    return '—';
                }
                $val = (float) $item[$key];
                if ($val === 0.0) {
                    return '0';
                }
                return esc_html(rtrim(rtrim(number_format($val, 4, '.', ''), '0'), '.') );
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
        $cloneUrl = add_query_arg([
            'page' => 'wrd-customs',
            'tab' => 'profiles',
            'action' => 'edit',
            'clone' => (int)$item['id'],
        ], admin_url('admin.php'));
        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', esc_url($editUrl), __('Edit', 'woocommerce-us-duties')),
            'clone' => sprintf('<a href="%s">%s</a>', esc_url($cloneUrl), __('Clone', 'woocommerce-us-duties')),
        ];
        return sprintf('<strong><a href="%s">%s</a></strong> <span class="description">| %s</span> %s', esc_url($editUrl), $title, $cc, $this->row_actions($actions));
    }

    protected function column_products($item) {
        $hs = isset($item['hs_code']) ? (string)$item['hs_code'] : '';
        $cc = isset($item['country_code']) ? strtoupper((string)$item['country_code']) : '';
        $key = $hs . '|' . $cc;
        $count = isset($this->counts[$key]) ? (int)$this->counts[$key] : 0;
        if ($hs === '' || $cc === '') { return '—'; }
        $url = add_query_arg([
            'page' => 'wrd-customs',
            'tab' => 'profiles',
            'action' => 'impacted',
            'hs_code' => rawurlencode($hs),
            'cc' => $cc,
        ], admin_url('admin.php'));
        return sprintf('<a href="%s">%d</a>', esc_url($url), $count);
    }

    protected function column_active($item) {
        $status = $this->get_active_status_meta($item);
        $pill = sprintf(
            '<span class="wrd-status-pill wrd-status-pill--%1$s">%2$s</span>',
            esc_attr($status['state']),
            esc_html($status['label'])
        );
        if ($status['meta'] === '') {
            return $pill;
        }
        return $pill . '<span class="wrd-cell-meta">' . esc_html($status['meta']) . '</span>';
    }

    protected function column_notes($item) {
        $notes = trim((string)($item['notes'] ?? ''));
        if ($notes === '') {
            return '<span class="wrd-cell-empty">—</span>';
        }

        $singleLine = preg_replace('/\s+/', ' ', $notes);
        $singleLine = is_string($singleLine) ? trim($singleLine) : $notes;
        $snippet = wp_html_excerpt($singleLine, 100, '…');

        return sprintf(
            '<span class="wrd-notes-snippet" title="%1$s">%2$s</span>',
            esc_attr($notes),
            esc_html($snippet)
        );
    }

    private function get_active_status_meta(array $item): array {
        $today = current_time('Y-m-d');
        $from = isset($item['effective_from']) ? trim((string)$item['effective_from']) : '';
        $to = isset($item['effective_to']) ? trim((string)$item['effective_to']) : '';

        if ($from !== '' && $today < $from) {
            return [
                'state' => 'scheduled',
                'label' => __('Scheduled', 'woocommerce-us-duties'),
                'meta' => sprintf(__('Starts %s', 'woocommerce-us-duties'), $from),
            ];
        }

        if ($to !== '' && $today > $to) {
            return [
                'state' => 'expired',
                'label' => __('Expired', 'woocommerce-us-duties'),
                'meta' => sprintf(__('Ended %s', 'woocommerce-us-duties'), $to),
            ];
        }

        if ($from !== '' && $to !== '') {
            return [
                'state' => 'active',
                'label' => __('Active', 'woocommerce-us-duties'),
                'meta' => sprintf(__('Active %1$s to %2$s', 'woocommerce-us-duties'), $from, $to),
            ];
        }

        if ($from !== '') {
            return [
                'state' => 'active',
                'label' => __('Active', 'woocommerce-us-duties'),
                'meta' => sprintf(__('Since %s', 'woocommerce-us-duties'), $from),
            ];
        }

        if ($to !== '') {
            return [
                'state' => 'active',
                'label' => __('Active', 'woocommerce-us-duties'),
                'meta' => sprintf(__('Until %s', 'woocommerce-us-duties'), $to),
            ];
        }

        return [
            'state' => 'active',
            'label' => __('Always', 'woocommerce-us-duties'),
            'meta' => '',
        ];
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
                $updated = $this->handle_bulk_profile_update($ids);
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

    private function handle_bulk_profile_update($ids) {
        global $wpdb;
        $table = WRD_DB::table_profiles();
        $updated = 0;
        
        // Handle new bulk edit format
        $from_action = isset($_REQUEST['bulk_effective_from_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_from_action'])) : '';
        $to_action = isset($_REQUEST['bulk_effective_to_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_to_action'])) : '';
        $from_date = isset($_REQUEST['bulk_effective_from']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_from'])) : '';
        $to_date = isset($_REQUEST['bulk_effective_to']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_effective_to'])) : '';

        // Rates/CUSMA/notes actions
        $postal_action = isset($_REQUEST['bulk_postal_rate_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_postal_rate_action'])) : '';
        $commercial_action = isset($_REQUEST['bulk_commercial_rate_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_commercial_rate_action'])) : '';
        $cusma_action = isset($_REQUEST['bulk_cusma_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_cusma_action'])) : '';
        $notes_action = isset($_REQUEST['bulk_notes_action']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_notes_action'])) : '';

        $postal_rate_raw = isset($_REQUEST['bulk_postal_rate']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_postal_rate'])) : '';
        $commercial_rate_raw = isset($_REQUEST['bulk_commercial_rate']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_commercial_rate'])) : '';
        $notes_value = isset($_REQUEST['bulk_notes']) ? wp_kses_post(wp_unslash($_REQUEST['bulk_notes'])) : '';

        $postal_rate = is_numeric($postal_rate_raw) ? (float) $postal_rate_raw : null;
        $commercial_rate = is_numeric($commercial_rate_raw) ? (float) $commercial_rate_raw : null;
        
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
            $current = $wpdb->get_row(
                $wpdb->prepare("SELECT us_duty_json, fta_flags, notes FROM {$table} WHERE id = %d", $id),
                ARRAY_A
            );
            if (!$current) {
                continue;
            }

            $data = [];
            $fmt = [];
            $udj_changed = false;
            $fta_changed = false;
            $notes_changed = false;
            
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

            $udj = [];
            if (isset($current['us_duty_json']) && $current['us_duty_json'] !== '') {
                $udj = json_decode((string) $current['us_duty_json'], true);
            }
            if (!is_array($udj)) {
                $udj = [];
            }
            if (!isset($udj['postal']) || !is_array($udj['postal'])) {
                $udj['postal'] = [];
            }
            if (!isset($udj['postal']['rates']) || !is_array($udj['postal']['rates'])) {
                $udj['postal']['rates'] = [];
            }
            if (!isset($udj['commercial']) || !is_array($udj['commercial'])) {
                $udj['commercial'] = [];
            }
            if (!isset($udj['commercial']['rates']) || !is_array($udj['commercial']['rates'])) {
                $udj['commercial']['rates'] = [];
            }

            if ($postal_action === 'set' && $postal_rate !== null && $postal_rate >= 0 && $postal_rate <= 100) {
                $udj['postal']['rates']['base'] = (float) $postal_rate;
                $udj_changed = true;
            } elseif ($postal_action === 'clear' && array_key_exists('base', $udj['postal']['rates'])) {
                unset($udj['postal']['rates']['base']);
                $udj_changed = true;
            }

            if ($commercial_action === 'set' && $commercial_rate !== null && $commercial_rate >= 0 && $commercial_rate <= 100) {
                $udj['commercial']['rates']['base'] = (float) $commercial_rate;
                $udj_changed = true;
            } elseif ($commercial_action === 'clear' && array_key_exists('base', $udj['commercial']['rates'])) {
                unset($udj['commercial']['rates']['base']);
                $udj_changed = true;
            }

            $fta_flags = [];
            if (isset($current['fta_flags']) && $current['fta_flags'] !== '') {
                $fta_flags = json_decode((string) $current['fta_flags'], true);
            }
            if (!is_array($fta_flags)) {
                $fta_flags = [];
            }

            if ($cusma_action === 'enable' && !in_array('CUSMA', $fta_flags, true)) {
                $fta_flags[] = 'CUSMA';
                $fta_changed = true;
            } elseif ($cusma_action === 'disable' && in_array('CUSMA', $fta_flags, true)) {
                $fta_flags = array_values(array_filter($fta_flags, function($flag) {
                    return $flag !== 'CUSMA';
                }));
                $fta_changed = true;
            }

            $current_notes = isset($current['notes']) ? (string) $current['notes'] : '';
            if ($notes_action === 'replace') {
                $data['notes'] = $notes_value;
                $fmt[] = '%s';
                $notes_changed = true;
            } elseif ($notes_action === 'append') {
                if ($notes_value !== '') {
                    $data['notes'] = $current_notes === '' ? $notes_value : ($current_notes . "\n" . $notes_value);
                    $fmt[] = '%s';
                    $notes_changed = true;
                }
            } elseif ($notes_action === 'clear') {
                $data['notes'] = '';
                $fmt[] = '%s';
                $notes_changed = true;
            }

            if ($udj_changed) {
                $data['us_duty_json'] = wp_json_encode($udj, JSON_UNESCAPED_SLASHES);
                $fmt[] = '%s';
            }

            if ($fta_changed) {
                $data['fta_flags'] = wp_json_encode(array_values($fta_flags));
                $fmt[] = '%s';
            }
            
            if (!empty($data)) {
                $result = $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
                if ($result !== false && ($result > 0 || $udj_changed || $fta_changed || $notes_changed)) {
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
        $allowed = ['id','description_raw','country_code','hs_code','products','effective_from'];
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
                $hs = isset($r['hs_code']) ? (string)$r['hs_code'] : '';
                $cc = isset($r['country_code']) ? strtoupper((string)$r['country_code']) : '';
                $key = $hs . '|' . $cc;
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

            // Compute preview duty rates (percent) for each row
            foreach ($rows as &$row) {
                $udj = [];
                if (isset($row['us_duty_json'])) {
                    $udj = is_array($row['us_duty_json']) ? $row['us_duty_json'] : json_decode((string) $row['us_duty_json'], true);
                }
                if (!is_array($udj)) {
                    $row['postal_rate'] = 0.0;
                    $row['commercial_rate'] = 0.0;
                } else {
                    $row['postal_rate'] = WRD_Duty_Engine::compute_rate_percent($udj, 'postal');
                    $row['commercial_rate'] = WRD_Duty_Engine::compute_rate_percent($udj, 'commercial');
                }
            }
            unset($row);

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
            $hs = isset($r['hs_code']) ? (string)$r['hs_code'] : '';
            $cc = isset($r['country_code']) ? strtoupper((string)$r['country_code']) : '';
            if ($hs !== '' && $cc !== '') {
                $pairs[$hs . '|' . $cc] = [$hs, $cc];
            }
        }
        if (empty($pairs)) { return []; }

        // Build OR conditions for pairs
        $conds = [];
        $params = [];
        foreach ($pairs as [$hs, $cc]) {
            $conds[] = '(h.meta_value = %s AND c.meta_value = %s)';
            $params[] = $hs; $params[] = $cc;
        }
        $wherePairs = implode(' OR ', $conds);

        $post_types = [ 'product', 'product_variation' ];
        $inTypes = implode("','", array_map('esc_sql', $post_types));

        $sql = "SELECT h.meta_value AS hs_code, c.meta_value AS cc, COUNT(p.ID) AS cnt
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} h ON (h.post_id = p.ID AND h.meta_key = '_hs_code')
                INNER JOIN {$wpdb->postmeta} c ON (c.post_id = p.ID AND c.meta_key = '_country_of_origin')
                WHERE p.post_type IN ('{$inTypes}')
                  AND p.post_status NOT IN ('trash','auto-draft')
                  AND ({$wherePairs})
                GROUP BY h.meta_value, c.meta_value";
        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['hs_code'] . '|' . strtoupper((string)$r['cc'])] = (int)$r['cnt'];
        }
        return $map;
    }
}
