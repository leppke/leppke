<?php
/**
 * Plugin Name: Stripe Riport
 * Description: A belső hálózaton futó Python script által feltöltött Stripe tranzakciós riport fogadása és megjelenítése a könyvelőnek. Használat: [stripe_riport] shortcode egy oldalon; a feltöltési token a Beállítások → Stripe Riport oldalon található.
 * Version: 1.7.0
 * Author: Festipay
 */

if (!defined('ABSPATH')) {
    exit;
}

const STRIPE_RIPORT_OPTION       = 'stripe_riport_data';
const STRIPE_RIPORT_TOKEN_OPTION = 'stripe_riport_token';
const STRIPE_RIPORT_CAP_READ     = 'stripe_riport_read';

const STRIPE_RIPORT_CSV_HEADER = ['Fiók', 'Dátum', 'Ügyfél', 'Azonosító', 'Összeg', 'Díj', 'Nettó', 'Státusz', 'Leírás'];
const STRIPE_RIPORT_ROW_KEYS   = ['account', 'date', 'customer', 'id', 'amount', 'fee', 'net', 'status', 'description'];

/**
 * Aktiváláskor létrejön a "konyvelo" szerepkör (be tud lépni és látja a
 * riportot, mást nem szerkeszthet), az adminisztrátor megkapja az olvasási
 * jogot, és generálódik a feltöltési token, ha még nincs.
 */
register_activation_hook(__FILE__, function () {
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap(STRIPE_RIPORT_CAP_READ);
    }
    add_role('konyvelo', 'Könyvelő', ['read' => true, STRIPE_RIPORT_CAP_READ => true]);
    if (!get_option(STRIPE_RIPORT_TOKEN_OPTION)) {
        update_option(STRIPE_RIPORT_TOKEN_OPTION, wp_generate_password(48, false, false), false);
    }
});

/**
 * REST végpont: POST /wp-json/stripe-riport/v1/upload
 * Hitelesítés: X-Riport-Token fejléc, értéke a Beállítások → Stripe Riport
 * oldalon látható token. Szándékosan nem az Authorization fejlécet használjuk,
 * hogy JWT/egyéb hitelesítő bővítmények ne nyúljanak bele a kérésbe.
 */
add_action('rest_api_init', function () {
    register_rest_route('stripe-riport/v1', '/upload', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            $stored = (string) get_option(STRIPE_RIPORT_TOKEN_OPTION);
            $sent   = (string) $req->get_header('X-Riport-Token');
            return $stored !== '' && $sent !== '' && hash_equals($stored, $sent);
        },
        'callback'            => function (WP_REST_Request $req) {
            $body = $req->get_json_params();
            $rows = isset($body['rows']) && is_array($body['rows']) ? $body['rows'] : null;
            if ($rows === null) {
                return new WP_Error('bad_request', 'Hiányzó vagy hibás "rows" tömb.', ['status' => 400]);
            }

            $clean = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $clean_row = [];
                foreach (STRIPE_RIPORT_ROW_KEYS as $key) {
                    $clean_row[$key] = isset($row[$key]) ? sanitize_text_field((string) $row[$key]) : '';
                }
                $clean[] = $clean_row;
            }

            // Opcionális webhook-állapot blokk fiókonként.
            $clean_webhooks = [];
            $webhooks = isset($body['webhooks']) && is_array($body['webhooks']) ? $body['webhooks'] : [];
            foreach ($webhooks as $wh) {
                if (!is_array($wh)) {
                    continue;
                }
                $entry = [
                    'account'      => sanitize_text_field((string) ($wh['account'] ?? '')),
                    'events_total' => (int) ($wh['events_total'] ?? 0),
                    'endpoints'    => [],
                    'pending'      => [],
                ];
                if (isset($wh['error'])) {
                    $entry['error'] = sanitize_text_field((string) $wh['error']);
                }
                foreach ((array) ($wh['endpoints'] ?? []) as $ep) {
                    if (is_array($ep)) {
                        $entry['endpoints'][] = [
                            'url'    => esc_url_raw((string) ($ep['url'] ?? '')),
                            'status' => sanitize_text_field((string) ($ep['status'] ?? '')),
                        ];
                    }
                }
                foreach ((array) ($wh['pending'] ?? []) as $ev) {
                    if (is_array($ev) && count($entry['pending']) < 200) {
                        $entry['pending'][] = [
                            'date' => sanitize_text_field((string) ($ev['date'] ?? '')),
                            'type' => sanitize_text_field((string) ($ev['type'] ?? '')),
                            'id'   => sanitize_text_field((string) ($ev['id'] ?? '')),
                        ];
                    }
                }
                $clean_webhooks[] = $entry;
            }

            update_option(STRIPE_RIPORT_OPTION, [
                'updated'  => current_time('mysql'),
                'rows'     => $clean,
                'webhooks' => $clean_webhooks,
            ], false);

            return ['ok' => true, 'count' => count($clean)];
        },
    ]);
});

/**
 * Beállítások → Stripe Riport: itt olvasható ki a feltöltési token, és itt
 * lehet újat generálni (a régi azonnal érvénytelenné válik).
 */
add_action('admin_menu', function () {
    add_options_page('Stripe Riport', 'Stripe Riport', 'manage_options', 'stripe-riport', function () {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['stripe_riport_regenerate'])) {
            check_admin_referer('stripe_riport_regenerate');
            update_option(STRIPE_RIPORT_TOKEN_OPTION, wp_generate_password(48, false, false), false);
            echo '<div class="notice notice-success"><p>Új token generálva — a Python konfigurációban is cserélni kell!</p></div>';
        }
        $token = (string) get_option(STRIPE_RIPORT_TOKEN_OPTION);
        if ($token === '') {
            $token = wp_generate_password(48, false, false);
            update_option(STRIPE_RIPORT_TOKEN_OPTION, $token, false);
        }
        ?>
        <div class="wrap">
            <h1>Stripe Riport</h1>
            <p>A belső gépen futó feltöltő script ezzel a tokennel hitelesít
               (a <code>WP_RIPORT_TOKEN</code> értéke a <code>.bat</code> fájlban):</p>
            <p><input type="text" readonly value="<?php echo esc_attr($token); ?>"
                      style="width: 100%; max-width: 600px; font-family: monospace;"
                      onclick="this.select();"></p>
            <form method="post">
                <?php wp_nonce_field('stripe_riport_regenerate'); ?>
                <p><button type="submit" name="stripe_riport_regenerate" value="1" class="button">
                    Új token generálása (a régi érvénytelenné válik)
                </button></p>
            </form>
        </div>
        <?php
    });
});

/**
 * CSV-export a könyvelőnek: admin-post.php?action=stripe_riport_export
 * (?account=Név esetén csak az adott fiók sorai). Bejelentkezett,
 * riport-olvasási jogú felhasználónak; UTF-8 BOM + pontosvessző elválasztó,
 * hogy a magyar Excel azonnal oszlopokra bontva nyissa meg.
 */
add_action('admin_post_stripe_riport_export', function () {
    if (!current_user_can(STRIPE_RIPORT_CAP_READ)) {
        wp_die('Ez a riport csak jogosult felhasználóknak érhető el.', '', ['response' => 403]);
    }
    $data = get_option(STRIPE_RIPORT_OPTION);
    $rows = !empty($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    $account = isset($_GET['account']) ? sanitize_text_field(wp_unslash($_GET['account'])) : '';
    $filename = 'stripe-riport-' . gmdate('Y-m-d');
    if ($account !== '') {
        $rows = array_filter($rows, function ($row) use ($account) {
            return (string) ($row['account'] ?? '') === $account;
        });
        $filename .= '-' . sanitize_file_name($account);
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, STRIPE_RIPORT_CSV_HEADER, ';');
    foreach ($rows as $row) {
        $line = [];
        foreach (STRIPE_RIPORT_ROW_KEYS as $key) {
            $line[] = (string) ($row[$key] ?? '');
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
});

/** A riport közös stíluslapja (beágyazott és teljes képernyős nézethez). */
function stripe_riport_styles(): string {
    return '<style>
        .stripe-riport-wrap { width: 100%; }
        .stripe-riport-toolbar { display: flex; justify-content: space-between;
            align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 0 10px; }
        .stripe-riport-toolbar p { margin: 0; }
        .stripe-riport-wrap h3 { margin: 24px 0 8px; }
        a.stripe-riport-export { display: inline-block; padding: 8px 16px;
            background: #2271b1; color: #fff; border-radius: 4px;
            text-decoration: none; font-weight: 600; }
        a.stripe-riport-export:hover { background: #135e96; color: #fff; }
        .stripe-riport-scroll { overflow-x: auto; }
        table.stripe-riport { width: 100%; border-collapse: collapse;
            font-size: 14px; line-height: 1.4; }
        table.stripe-riport th, table.stripe-riport td { padding: 8px 12px;
            border-bottom: 1px solid #ddd; text-align: left;
            white-space: nowrap; vertical-align: top; }
        table.stripe-riport thead th { background: #f0f0f1;
            border-bottom: 2px solid #c3c4c7; position: sticky; top: 0; }
        table.stripe-riport tbody tr:nth-child(even) { background: #f8f9fa; }
        table.stripe-riport tbody tr:hover { background: #eef4fa; }
        table.stripe-riport td:nth-child(3) { font-family: monospace;
            font-size: 12px; }
        table.stripe-riport th:nth-child(4), table.stripe-riport td:nth-child(4),
        table.stripe-riport th:nth-child(5), table.stripe-riport td:nth-child(5),
        table.stripe-riport th:nth-child(6), table.stripe-riport td:nth-child(6) {
            text-align: right; }
        table.stripe-riport td:nth-child(8) { white-space: normal;
            min-width: 220px; }
        table.stripe-riport-wh th, table.stripe-riport-wh td {
            font-family: inherit !important; font-size: 14px !important;
            text-align: left !important; }
        .stripe-riport-badge { display: inline-block; padding: 2px 10px;
            border-radius: 10px; font-size: 12px; font-weight: 600; }
        .stripe-riport-badge-ok { background: #d5f5d5; color: #116611; }
        .stripe-riport-badge-bad { background: #fbd5d5; color: #8a1f1f; }
    </style>';
}

/** Webhook-állapot szakasz: végpontok státusza + kézbesítetlen események. */
function stripe_riport_render_webhooks(array $webhooks): string {
    $out = '<h2>Webhookok állapota</h2>';
    foreach ($webhooks as $wh) {
        $label = $wh['account'] !== '' ? $wh['account'] : 'Stripe';
        $out .= '<h3>' . esc_html($label) . '</h3>';

        if (!empty($wh['error'])) {
            $out .= '<p><span class="stripe-riport-badge stripe-riport-badge-bad">nem elérhető</span> '
                  . esc_html($wh['error']) . '</p>';
            continue;
        }

        if (empty($wh['endpoints'])) {
            $out .= '<p>Ehhez a fiókhoz nincs webhook-végpont beállítva.</p>';
            continue;
        }

        $out .= '<div class="stripe-riport-scroll"><table class="stripe-riport stripe-riport-wh">'
              . '<thead><tr><th>Végpont URL</th><th>Státusz</th></tr></thead><tbody>';
        foreach ($wh['endpoints'] as $ep) {
            $ok = ($ep['status'] === 'enabled');
            $out .= '<tr><td>' . esc_html($ep['url']) . '</td><td>'
                  . '<span class="stripe-riport-badge stripe-riport-badge-' . ($ok ? 'ok' : 'bad') . '">'
                  . esc_html($ok ? 'aktív' : $ep['status'])
                  . '</span></td></tr>';
        }
        $out .= '</tbody></table></div>';

        $pending_count = count($wh['pending']);
        $delivered = max(0, (int) $wh['events_total'] - $pending_count);
        $out .= '<p>' . (int) $wh['events_total'] . ' esemény az időszakban — '
              . '<span class="stripe-riport-badge stripe-riport-badge-ok">' . $delivered . ' kézbesítve</span> ';
        if ($pending_count > 0) {
            $out .= '<span class="stripe-riport-badge stripe-riport-badge-bad">'
                  . $pending_count . ' kézbesítetlen (a Stripe újrapróbálja)</span>';
        } else {
            $out .= '<span class="stripe-riport-badge stripe-riport-badge-ok">nincs kézbesítetlen</span>';
        }
        $out .= '</p>';

        if ($pending_count > 0) {
            $out .= '<div class="stripe-riport-scroll"><table class="stripe-riport stripe-riport-wh">'
                  . '<thead><tr><th>Dátum</th><th>Esemény típusa</th><th>Azonosító</th></tr></thead><tbody>';
            foreach ($wh['pending'] as $ev) {
                $out .= '<tr><td>' . esc_html($ev['date']) . '</td><td>' . esc_html($ev['type'])
                      . '</td><td>' . esc_html($ev['id']) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }
    }
    return $out;
}

/**
 * A riport törzse: felső összesítő sáv, majd fiókonként cím + letöltő gomb +
 * táblázat. A táblázatokban a Fiók oszlop nem szerepel, mert a csoportosítás
 * mutatja.
 */
function stripe_riport_render_body(array $data, bool $standalone): string {
    $export_url = admin_url('admin-post.php?action=stripe_riport_export');
    $view_url   = admin_url('admin-post.php?action=stripe_riport_view');

    $groups = [];
    foreach ($data['rows'] as $row) {
        $groups[(string) ($row['account'] ?? '')][] = $row;
    }
    ksort($groups);
    $multi = count($groups) > 1;

    $display_header = array_slice(STRIPE_RIPORT_CSV_HEADER, 1);
    $display_keys   = array_slice(STRIPE_RIPORT_ROW_KEYS, 1);

    $out  = '<div class="stripe-riport-wrap">';
    $out .= '<div class="stripe-riport-toolbar">'
          . '<p>Utolsó frissítés: ' . esc_html($data['updated'])
          . ' &nbsp;•&nbsp; ' . count($data['rows']) . ' tétel összesen</p>'
          . '<p>'
          . ($standalone ? '' : '<a class="stripe-riport-export" href="' . esc_url($view_url) . '" target="_blank">Megnyitás teljes képernyőn</a> ')
          . '<a class="stripe-riport-export" href="' . esc_url($export_url) . '">'
          . ($multi ? 'Összes letöltése Excelbe (CSV)' : 'Letöltés Excelbe (CSV)')
          . '</a></p></div>';

    foreach ($groups as $label => $rows) {
        if ($multi) {
            $account_url = add_query_arg('account', rawurlencode($label), $export_url);
            $out .= '<h3>' . esc_html($label !== '' ? $label : 'Stripe') . '</h3>';
            $out .= '<div class="stripe-riport-toolbar">'
                  . '<p>' . count($rows) . ' tétel</p>'
                  . '<a class="stripe-riport-export" href="' . esc_url($account_url) . '">'
                  . 'Letöltés Excelbe (CSV)</a>'
                  . '</div>';
        }
        $out .= '<div class="stripe-riport-scroll">';
        $out .= '<table class="stripe-riport"><thead><tr>';
        foreach ($display_header as $col) {
            $out .= '<th>' . esc_html($col) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $out .= '<tr>';
            foreach ($display_keys as $key) {
                $out .= '<td>' . esc_html($row[$key] ?? '') . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
    }

    if (!empty($data['webhooks'])) {
        $out .= stripe_riport_render_webhooks($data['webhooks']);
    }

    $out .= '</div>';
    return $out;
}

/**
 * Teljes képernyős nézet: admin-post.php?action=stripe_riport_view
 * A sablon nélkül, önálló oldalként rendereli a riportot, így a táblázat a
 * böngészőablak teljes szélességét használja — a téma hasáb-korlátaitól és
 * levágásaitól függetlenül.
 */
add_action('admin_post_stripe_riport_view', function () {
    if (!current_user_can(STRIPE_RIPORT_CAP_READ)) {
        wp_die('Ez a riport csak jogosult felhasználóknak érhető el.', '', ['response' => 403]);
    }
    $data = get_option(STRIPE_RIPORT_OPTION);

    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="hu"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Stripe riport</title>'
       . '<style>body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",'
       . ' Roboto, Arial, sans-serif; margin: 24px; color: #1d2327; }'
       . ' h1 { font-size: 24px; margin: 0 0 16px; }</style>'
       . stripe_riport_styles()
       . '</head><body><h1>Stripe riport</h1>';
    if (empty($data['rows'])) {
        echo '<p>Még nem érkezett riport.</p>';
    } else {
        echo stripe_riport_render_body($data, true);
    }
    echo '</body></html>';
    exit;
});

/**
 * [stripe_riport] shortcode: a riport beágyazva, a sablon tartalomhasábjának
 * szélességében, plusz gomb a teljes képernyős nézethez. Csak bejelentkezett,
 * riport-olvasási joggal rendelkező felhasználó látja a tartalmat — maga az
 * oldal így akár publikus is lehet.
 */
add_shortcode('stripe_riport', function () {
    if (!is_user_logged_in() || !current_user_can(STRIPE_RIPORT_CAP_READ)) {
        return '<p>Ez a riport csak bejelentkezett, jogosult felhasználóknak érhető el.</p>';
    }

    $data = get_option(STRIPE_RIPORT_OPTION);
    if (empty($data['rows'])) {
        return '<p>Még nem érkezett riport.</p>';
    }

    return stripe_riport_styles() . stripe_riport_render_body($data, false);
});
