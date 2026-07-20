<?php
/**
 * Plugin Name: Stripe Riport
 * Description: A belső hálózaton futó Python script által feltöltött Stripe tranzakciós riport fogadása és megjelenítése a könyvelőnek. Használat: [stripe_riport] shortcode egy oldalon.
 * Version: 1.0.0
 * Author: Festipay
 */

if (!defined('ABSPATH')) {
    exit;
}

const STRIPE_RIPORT_OPTION     = 'stripe_riport_data';
const STRIPE_RIPORT_CAP_UPLOAD = 'stripe_riport_upload';
const STRIPE_RIPORT_CAP_READ   = 'stripe_riport_read';

/**
 * Aktiváláskor létrejön két szerepkör:
 *  - "konyvelo": be tud lépni és látja a riportot (semmi mást nem szerkeszthet),
 *  - "riport_robot": csak feltölteni tud a REST végpontra (a Python script ezzel a fiókkal hitelesít).
 * Az adminisztrátor mindkét jogot megkapja.
 */
register_activation_hook(__FILE__, function () {
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap(STRIPE_RIPORT_CAP_UPLOAD);
        $admin->add_cap(STRIPE_RIPORT_CAP_READ);
    }
    add_role('konyvelo', 'Könyvelő', ['read' => true, STRIPE_RIPORT_CAP_READ => true]);
    add_role('riport_robot', 'Riport robot', ['read' => true, STRIPE_RIPORT_CAP_UPLOAD => true]);
});

/**
 * REST végpont: POST /wp-json/stripe-riport/v1/upload
 * Hitelesítés: WordPress Application Password (Basic auth), csak HTTPS felett.
 * Törzs: {"rows": [{"date": "...", "id": "...", "amount": "...", "fee": "...",
 *                   "net": "...", "status": "...", "description": "..."}, ...]}
 */
add_action('rest_api_init', function () {
    register_rest_route('stripe-riport/v1', '/upload', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can(STRIPE_RIPORT_CAP_UPLOAD);
        },
        'callback'            => function (WP_REST_Request $req) {
            $body = $req->get_json_params();
            $rows = isset($body['rows']) && is_array($body['rows']) ? $body['rows'] : null;
            if ($rows === null) {
                return new WP_Error('bad_request', 'Hiányzó vagy hibás "rows" tömb.', ['status' => 400]);
            }

            $allowed_keys = ['date', 'id', 'amount', 'fee', 'net', 'status', 'description'];
            $clean = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $clean_row = [];
                foreach ($allowed_keys as $key) {
                    $clean_row[$key] = isset($row[$key]) ? sanitize_text_field((string) $row[$key]) : '';
                }
                $clean[] = $clean_row;
            }

            update_option(STRIPE_RIPORT_OPTION, [
                'updated' => current_time('mysql'),
                'rows'    => $clean,
            ], false);

            return ['ok' => true, 'count' => count($clean)];
        },
    ]);
});

/**
 * [stripe_riport] shortcode: táblázatban jeleníti meg a legutóbb feltöltött
 * riportot. Csak bejelentkezett, riport-olvasási joggal rendelkező felhasználó
 * látja a tartalmat — maga az oldal így akár publikus is lehet.
 */
add_shortcode('stripe_riport', function () {
    if (!is_user_logged_in() || !current_user_can(STRIPE_RIPORT_CAP_READ)) {
        return '<p>Ez a riport csak bejelentkezett, jogosult felhasználóknak érhető el.</p>';
    }

    $data = get_option(STRIPE_RIPORT_OPTION);
    if (empty($data['rows'])) {
        return '<p>Még nem érkezett riport.</p>';
    }

    $out  = '<p>Utolsó frissítés: ' . esc_html($data['updated']) . '</p>';
    $out .= '<table class="stripe-riport"><thead><tr>'
          . '<th>Dátum</th><th>Azonosító</th><th>Összeg</th><th>Díj</th>'
          . '<th>Nettó</th><th>Státusz</th><th>Leírás</th>'
          . '</tr></thead><tbody>';
    foreach ($data['rows'] as $row) {
        $out .= '<tr>'
              . '<td>' . esc_html($row['date'] ?? '') . '</td>'
              . '<td>' . esc_html($row['id'] ?? '') . '</td>'
              . '<td>' . esc_html($row['amount'] ?? '') . '</td>'
              . '<td>' . esc_html($row['fee'] ?? '') . '</td>'
              . '<td>' . esc_html($row['net'] ?? '') . '</td>'
              . '<td>' . esc_html($row['status'] ?? '') . '</td>'
              . '<td>' . esc_html($row['description'] ?? '') . '</td>'
              . '</tr>';
    }
    $out .= '</tbody></table>';
    return $out;
});
