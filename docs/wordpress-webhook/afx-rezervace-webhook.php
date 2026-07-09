<?php
/**
 * Plugin Name: AppleFix — most rezervací do CRM
 * Description: Posílá rezervace z RepairPlugin Pro do Fix-CRM (admin.applefix.cloud). Nahrát do wp-content/mu-plugins/.
 * Version: 1.0
 *
 * ⚠ ŠABLONA — názvy tabulky/sloupců RepairPluginu ověřit podle skutečného
 *   zdrojáku na hostingu (wp-content/plugins/Repairplugin-pro/) a upravit
 *   sekci MAPOVÁNÍ níže. Klíč vlož z CRM: Nastavení → Integrace.
 */

if (!defined('ABSPATH')) exit;

define('AFX_CRM_WEBHOOK_URL', 'https://admin.applefix.cloud/api/website_booking.php');
define('AFX_CRM_WEBHOOK_KEY', 'DOPLNIT_KLIC_Z_CRM_NASTAVENI');

/**
 * Odeslání jedné rezervace do CRM (idempotentní — CRM dedupuje dle booking_id).
 */
function afx_crm_push_booking(array $b): void {
    $payload = [
        'booking_id'  => (string)($b['id'] ?? ''),
        'name'        => (string)($b['name'] ?? ''),
        'phone'       => (string)($b['phone'] ?? ''),
        'email'       => (string)($b['email'] ?? ''),
        'device'      => (string)($b['device'] ?? ''),
        'service'     => (string)($b['service'] ?? ''),
        'notes'       => (string)($b['notes'] ?? ''),
        'appointment' => (string)($b['appointment'] ?? ''),
        'delivery'    => (string)($b['delivery'] ?? ''),
        'status'      => (string)($b['status'] ?? 'pending'),
    ];
    wp_remote_post(AFX_CRM_WEBHOOK_URL, [
        'timeout' => 8,
        'headers' => ['Content-Type' => 'application/json', 'X-AFX-KEY' => AFX_CRM_WEBHOOK_KEY],
        'body'    => wp_json_encode($payload),
    ]);
}

/**
 * ── MAPOVÁNÍ (doplnit po inspekci pluginu) ──────────────────────────────────
 * Varianta A (preferovaná): plugin má akční hook po vytvoření rezervace, např.
 *   add_action('repairplugin_booking_created', function ($booking_id) { ... });
 * Varianta B (univerzální): watcher na WP-cron — každých 5 minut projde tabulku
 *   rezervací a nové řádky (id > poslední odeslané, uložené v option) odešle.
 *
 * Níže je připravená varianta B s TODO místy:
 */
add_action('afx_crm_booking_sync', 'afx_crm_booking_sync_run');
if (!wp_next_scheduled('afx_crm_booking_sync')) {
    wp_schedule_event(time() + 120, 'afx_five_minutes', 'afx_crm_booking_sync');
}
add_filter('cron_schedules', function ($s) {
    $s['afx_five_minutes'] = ['interval' => 300, 'display' => 'Každých 5 minut (AFX)'];
    return $s;
});

function afx_crm_booking_sync_run(): void {
    global $wpdb;
    // TODO: ověřit skutečný název tabulky a sloupců RepairPluginu:
    $table = $wpdb->prefix . 'DOPLNIT_TABULKU_REZERVACI';
    $lastId = (int)get_option('afx_crm_last_booking_id', 0);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 20", $lastId
    ), ARRAY_A);
    if (!$rows) return;

    foreach ($rows as $r) {
        afx_crm_push_booking([
            'id'          => $r['id'],
            // TODO: namapovat sloupce dle skutečného schématu pluginu:
            'name'        => $r['customer_name'] ?? '',
            'phone'       => $r['phone'] ?? '',
            'email'       => $r['email'] ?? '',
            'device'      => trim(($r['device_brand'] ?? '') . ' ' . ($r['device_model'] ?? '')),
            'service'     => $r['service'] ?? '',
            'notes'       => $r['message'] ?? '',
            'appointment' => trim(($r['booking_date'] ?? '') . ' ' . ($r['booking_time'] ?? '')),
            'delivery'    => $r['delivery_method'] ?? '',
            'status'      => $r['status'] ?? 'pending',
        ]);
        update_option('afx_crm_last_booking_id', (int)$r['id'], false);
    }
}
