<?php
/**
 * Plugin Name: Stripe Riport
 * Description: A belső hálózaton futó Python script által feltöltött Stripe tranzakciós riport fogadása és megjelenítése a könyvelőnek. Használat: [stripe_riport] shortcode egy oldalon; a feltöltési token a Beállítások → Stripe Riport oldalon található.
 * Version: 1.1.0
 * Author: Festipay
 */

if (!defined('ABSPATH')) {
    exit;
}

const STRIPE_RIPORT_OPTION       = 'stripe_riport_data';
const STRIPE_RIPORT_TOKEN_OPTION = 'stripe_riport_token';
const STRIPE_RIPORT_CAP_READ     = 'stripe_riport_read';

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
 * Törzs: {"rows": [{"date": "...", "id": "...", "amount": "...", "fee": "...",
 *                   "net": "...", "status": "...", "description": "..."}, ...]}
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
