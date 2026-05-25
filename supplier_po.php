<?php
/**
 * Public, token-authenticated supplier confirmation endpoint (Option B).
 *
 * Flow:
 *   GET  /supplier_po.php?id=42&token=...           → renders Views/supplier_po_view.php
 *   POST /supplier_po.php?action=supply-supplier-confirm-po → handled by SupplyChainController::handlePublicAjax()
 *   GET  /supplier_po.php?action=supply-supplier-view-po&id=...&token=... → JSON detail
 *
 * No session is required. Authentication is performed entirely by the
 * unguessable per-PO token, compared with hash_equals() to avoid timing
 * attacks. DdosGuard rate-limits the endpoint to defeat brute-force token
 * enumeration even with the 256-bit token space.
 */
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Controllers/Security/DdosGuard.php';
require_once __DIR__ . '/Controllers/SupplyChainController.php';

// Aggressive rate limit — the legitimate supplier only loads this a few times.
DdosGuard::protect([
    'scope'           => 'supplier_po',
    'max_requests'    => 30,
    'window_seconds'  => 60,
    'block_seconds'   => 600,
    'request_methods' => ['GET', 'POST'],
    'response_type'   => 'html',
    'message'         => 'Too many requests. Please wait a few minutes before retrying.',
]);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '') {
    // AJAX handler — JSON response.
    (new SupplyChainController())->handlePublicAjax();
    exit;
}

// HTML render path.
$poId  = (int)($_GET['id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));

$controller = new SupplyChainController();
$payload = $controller->getSupplierPoByToken($poId, $token);

http_response_code($payload === null ? 403 : 200);

require __DIR__ . '/Views/supplier_po_view.php';
