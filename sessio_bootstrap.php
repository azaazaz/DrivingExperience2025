<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Lightweight session usage: give the visitor a stable code.
 * This satisfies "sessions are used" without needing login.
 */
if (!isset($_SESSION['user_code'])) {
    $_SESSION['user_code'] = bin2hex(random_bytes(8));
}

/**
 * Storage for anonymized mappings (code -> real IDs).
 */
if (!isset($_SESSION['exp_map']) || !is_array($_SESSION['exp_map'])) {
    $_SESSION['exp_map'] = [];
}
