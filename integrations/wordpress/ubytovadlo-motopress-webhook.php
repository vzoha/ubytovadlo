<?php
/**
 * Plugin Name: Ubytovadlo — MotoPress webhook
 * Description: Po vytvoření nebo potvrzení rezervace v MotoPress Hotel Booking ťukne na Ubytovadlo, aby ji hned naimportovalo.
 * Version: 1.1.0
 * License: LicenseRef-FSL-1.1-ALv2
 *
 * Nasazení: zkopírujte tenhle soubor do wp-content/mu-plugins/ (must-use plugins se
 * načtou samy, bez aktivace). Do wp-config.php doplňte adresu z Ubytovadla
 * (Nastavení → Připojení → „Okamžitý import z webu", tlačítko Kopírovat):
 *
 *     define('UBYTOVADLO_WEBHOOK_URL', 'https://app.priklad.cz/webhook/motopress/…token…');
 *
 * Forwarder posílá jen ID rezervace; žádná logika ani údaje hosta tu nejsou —
 * Ubytovadlo si detail dotáhne samo z REST API.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ubytovadlo_notify_booking($booking): void
{
    if (!defined('UBYTOVADLO_WEBHOOK_URL') || !UBYTOVADLO_WEBHOOK_URL) {
        return;
    }

    $bookingId = is_object($booking) && method_exists($booking, 'getId') ? $booking->getId() : $booking;
    $bookingId = (int) $bookingId;
    if ($bookingId <= 0) {
        return;
    }

    // blocking=false → checkout hosta na odpověď nečeká (fire-and-forget).
    // Když ťuknutí selže (síť, výpadek), rezervaci stejně dožene pravidelná kontrola.
    wp_remote_post(UBYTOVADLO_WEBHOOK_URL, [
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode(['booking_id' => $bookingId]),
    ]);
}

// Vytvoření (placed) i potvrzení (confirmed) rezervace → import naskočí hned.
// Když se u jedné rezervace spustí obojí, nevadí: Ubytovadlo import upsertne
// podle MotoPress ID, duplicita nevznikne.
add_action('mphb_booking_placed', 'ubytovadlo_notify_booking', 10, 1);
add_action('mphb_booking_confirmed', 'ubytovadlo_notify_booking', 10, 1);
