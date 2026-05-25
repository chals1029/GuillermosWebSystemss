<?php
/**
 * Supplier-facing PO confirmation page (Option B).
 *
 * Rendered exclusively by /supplier_po.php after a successful token check.
 * Receives:
 *   - $payload : array|null  Result from SupplyChainController::getSupplierPoByToken()
 *   - $poId    : int
 *   - $token   : string
 *
 * Branding mirrors the public landing page (Lobster headers, Poppins body,
 * brown / cream palette).
 *
 * Security notes:
 *   - All values are escaped with htmlspecialchars() before output.
 *   - The token is echoed only inside a hidden input on the same-origin form.
 *   - The page sets robots:noindex so a leaked link is never archived.
 */
declare(strict_types=1);

/** @var array|null $payload */
/** @var int $poId */
/** @var string $token */

$invalid = ($payload === null);
$po       = $payload['po']       ?? [];
$lines    = $payload['lines']    ?? [];
$supplier = $payload['supplier'] ?? [];

$status         = (string)($po['Status'] ?? '');
$canConfirm     = !in_array($status, ['Cancelled', 'Received'], true);
$alreadyHandled = in_array($status, ['Confirmed', 'Shipped', 'Partial', 'Received'], true);

$basePath = rtrim(defined('APP_BASE_PATH') ? (string)APP_BASE_PATH : '', '/');
$peso = static fn(float $n): string => '₱' . number_format($n, 2);
$h    = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Pre-compute the existing lead time (in days from today) so we can pre-select
// the dropdown when a supplier returns to update an already-confirmed order.
$preselectDays = 0;
if (!empty($po['Expected_Delivery'])) {
    $expectedTs = strtotime((string)$po['Expected_Delivery']);
    $today = strtotime(date('Y-m-d'));
    if ($expectedTs !== false && $expectedTs >= $today) {
        $preselectDays = (int)round(($expectedTs - $today) / 86400);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Purchase Order<?= $invalid ? '' : ' #' . (int)($po['PO_ID'] ?? $poId) ?> · Guillermo's Café</title>
  <link rel="icon" type="image/x-icon" href="<?= $h($basePath) ?>/guillermos.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lobster&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --brand:        #4d2e00;
      --brand-deep:   #2e1c00;
      --brand-soft:   #fff7ec;
      --cream:        #f4e9c9;
      --accent:       #c4882a;
      --ink:          #2b2b2b;
      --muted:        #8a7250;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: var(--brand-soft);
      color: var(--ink);
      font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
    }

    /* Brand bar — matches the navbar styling on the landing page. */
    .brand-bar {
      background: var(--brand);
      color: var(--cream);
      padding: 1rem 0;
      box-shadow: 0 4px 16px rgba(77, 46, 0, .15);
    }
    .brand-bar .container { display: flex; align-items: center; gap: .75rem; }
    .brand-bar .logo {
      font-family: 'Lobster', cursive;
      font-size: clamp(1.4rem, 2.4vw, 1.9rem);
      letter-spacing: .04em;
      color: var(--cream);
      text-decoration: none;
    }
    .brand-bar .portal-tag {
      margin-left: auto;
      font-size: .78rem;
      font-weight: 500;
      letter-spacing: .12em;
      text-transform: uppercase;
      opacity: .75;
    }

    .po-card {
      border: 0;
      border-radius: 18px;
      box-shadow: 0 12px 40px rgba(77, 46, 0, .1);
      background: #fff;
      overflow: hidden;
    }
    .po-card .card-header {
      background: #fff;
      border-bottom: 1px solid #f1e8da;
      padding: 1.5rem 1.75rem 1.25rem;
    }
    .po-card .card-body { padding: 1.75rem; }
    .po-card .card-header .h4,
    .po-card h2 {
      font-family: 'Lobster', cursive;
      letter-spacing: .04em;
      color: var(--brand);
    }
    .po-card .card-header .h4 { font-size: 1.9rem; margin: 0; }
    .po-card h2 { font-size: 1.3rem; margin: 0 0 1rem; }
    .label-overline {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .12em;
      color: var(--muted);
      font-weight: 600;
    }

    .status-pill {
      font-weight: 600;
      padding: .4em 1em;
      border-radius: 999px;
      font-size: .8rem;
      letter-spacing: .04em;
    }
    .status-Draft, .status-Ordered { background: #fff4d6; color: #7a5500; }
    .status-Confirmed              { background: #d6f0e2; color: #14633c; }
    .status-Shipped, .status-Partial { background: #d8e7ff; color: #0b3a82; }
    .status-Received               { background: #c9efd2; color: #0f6228; }
    .status-Cancelled              { background: #fde2e2; color: #9b1c1c; }

    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.1rem 1.5rem;
    }
    .info-grid .label { font-size: .72rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: .2rem; font-weight: 600; }
    .info-grid .value { font-weight: 600; color: var(--brand); }

    .table thead th {
      background: #fbf3e3;
      color: var(--brand);
      border-bottom: 0;
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      font-weight: 600;
    }
    .table tbody td { vertical-align: middle; }
    .totals-row td  { font-weight: 700; background: #fbf3e3; color: var(--brand); }

    .form-control,
    .form-select {
      border-radius: 10px;
      border: 1px solid #e6d8bd;
      padding: .6rem .85rem;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 .2rem rgba(196, 136, 42, .2);
    }

    .btn-brand {
      background: var(--brand);
      color: #fff;
      font-weight: 600;
      border-radius: 12px;
      padding: .85rem 1.6rem;
      border: 0;
      letter-spacing: .03em;
    }
    .btn-brand:hover, .btn-brand:focus { background: var(--brand-deep); color: #fff; }
    .btn-brand:disabled { opacity: .65; }

    .footer-note { color: var(--muted); font-size: .8rem; }

    /* Material issues */
    .issue-section h2 { color: #9b1c1c !important; }
    .issue-card {
      background: #fff7ec;
      border: 1px solid #f3d9d9;
      border-left: 4px solid #c0392b;
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 12px;
    }
    .issue-card-head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
    }
    .issue-notes, .issue-reply {
      margin-top: 8px;
      background: #fff;
      border-radius: 8px;
      padding: 8px 10px;
      font-size: .9rem;
      color: #5a4423;
    }
    .issue-reply { background: #eef9f0; }
    .issue-status-Open         { background: #fff4d6; color: #7a5500; }
    .issue-status-Acknowledged { background: #d8e7ff; color: #0b3a82; }
    .issue-status-Resolved     { background: #d6f0e2; color: #14633c; }
    .issue-status-Rejected     { background: #e9e6df; color: #5a4423; }
    .issue-reply-form .btn-sm { padding: .5rem 1.1rem; }

    @media (max-width: 575.98px) {
      .info-grid { grid-template-columns: 1fr; }
      .po-card .card-body { padding: 1.2rem; }
      .po-card .card-header { padding: 1.25rem 1.25rem 1rem; }
    }
  </style>
</head>
<body>
  <header class="brand-bar">
    <div class="container">
      <span class="logo">Guillermo's Café</span>
      <span class="portal-tag">Supplier Portal</span>
    </div>
  </header>

  <main class="container py-4 py-md-5" style="max-width: 820px;">
<?php if ($invalid): ?>
    <div class="po-card">
      <div class="card-body text-center py-5">
        <div class="display-6 mb-2">🔒</div>
        <h2 class="mb-2">Link not valid</h2>
        <p class="text-muted mb-0">
          This purchase order link is invalid, expired, or has been revoked.<br>
          Please contact Guillermo's Café for an updated link.
        </p>
      </div>
    </div>
<?php else: ?>
    <article class="po-card mb-4">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <div class="label-overline">Purchase Order</div>
          <div class="h4 mt-1">PO #<?= (int)$po['PO_ID'] ?></div>
        </div>
        <span class="status-pill status-<?= $h($status) ?>"><?= $h($status) ?></span>
      </div>
      <div class="card-body">
        <div class="info-grid mb-4">
          <div>
            <div class="label">Supplier</div>
            <div class="value"><?= $h($supplier['Supplier_Name'] ?? '') ?></div>
          </div>
          <div>
            <div class="label">Order Date</div>
            <div class="value"><?= $h($po['Order_Date'] ?? '') ?></div>
          </div>
          <div>
            <div class="label">Contact Person</div>
            <div class="value"><?= $h($supplier['Contact_Person'] ?: '—') ?></div>
          </div>
          <div>
            <div class="label">Expected Delivery</div>
            <div class="value"><?= $h($po['Expected_Delivery'] ?: '—') ?></div>
          </div>
        </div>

<?php if (!empty($po['Notes'])): ?>
        <div class="alert alert-light border mb-4">
          <div class="label-overline mb-1">Notes from buyer</div>
          <div><?= nl2br($h($po['Notes'])) ?></div>
        </div>
<?php endif; ?>

<?php
  $issues = $payload['issues'] ?? [];
  $openIssues = array_values(array_filter($issues, static fn($i) => in_array(($i['Status'] ?? ''), ['Open', 'Acknowledged'], true)));
?>
<?php if (!empty($issues)): ?>
        <div class="issue-section mb-4">
          <h2 style="color:#9b1c1c;">⚠ Material Issues</h2>
          <p class="text-muted small mb-3">The buyer has flagged the following problem(s) with this delivery. You can reply directly below.</p>
<?php foreach ($issues as $iss):
        $sType = $h(str_replace('_', ' ', (string)($iss['Issue_Type'] ?? '')));
        $sAction = $h(str_replace('_', ' ', (string)($iss['Action_Requested'] ?? '')));
        $sStatus = (string)($iss['Status'] ?? '');
        $isOpen = in_array($sStatus, ['Open', 'Acknowledged'], true);
?>
          <div class="issue-card">
            <div class="issue-card-head">
              <div>
                <div class="fw-semibold"><?= $h($iss['Item_Name'] ?? 'Whole order') ?>
                  <span class="text-muted small">· <?= $sType ?></span>
                </div>
                <div class="small text-muted">
                  Action requested: <strong><?= $sAction ?></strong>
                  · Qty affected: <?= number_format((float)($iss['Quantity_Affected'] ?? 0), 2) ?>
                </div>
              </div>
              <span class="status-pill issue-status-<?= $h($sStatus) ?>"><?= $h($sStatus) ?></span>
            </div>
<?php if (!empty($iss['Buyer_Notes'])): ?>
            <div class="issue-notes"><strong>Buyer notes:</strong> <?= nl2br($h($iss['Buyer_Notes'])) ?></div>
<?php endif; ?>
<?php if (!empty($iss['Supplier_Reply'])): ?>
            <div class="issue-reply"><strong>Your reply:</strong> <?= nl2br($h($iss['Supplier_Reply'])) ?></div>
<?php endif; ?>
<?php if ($isOpen): ?>
            <form class="issue-reply-form mt-3" data-issue-id="<?= (int)$iss['Issue_ID'] ?>">
              <textarea class="form-control" name="reply" rows="2" maxlength="1000"
                        placeholder="Reply to buyer (e.g. We'll send a replacement on Monday, courier ABC, tracking #...)"
                        required></textarea>
              <div class="d-grid d-sm-flex justify-content-sm-end mt-2">
                <button type="submit" class="btn btn-brand btn-sm">Send reply</button>
              </div>
            </form>
<?php endif; ?>
          </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
        <h2>Order Lines</h2>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Item</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Unit Cost</th>
                <th class="text-end">Subtotal</th>
              </tr>
            </thead>
            <tbody>
<?php
  $computedTotal = 0.0;
  foreach ($lines as $line):
    $qty  = (float)($line['Quantity_Ordered'] ?? 0);
    $cost = (float)($line['Unit_Cost'] ?? 0);
    $sub  = $qty * $cost;
    $computedTotal += $sub;
?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= $h($line['Item_Name'] ?? '') ?></div>
                  <div class="small text-muted"><?= $h($line['Unit'] ?? '') ?></div>
                </td>
                <td class="text-end"><?= number_format($qty, 2) ?></td>
                <td class="text-end"><?= $peso($cost) ?></td>
                <td class="text-end"><?= $peso($sub) ?></td>
              </tr>
<?php endforeach; ?>
              <tr class="totals-row">
                <td colspan="3" class="text-end">Total</td>
                <td class="text-end"><?= $peso((float)($po['Total_Amount'] ?? $computedTotal)) ?></td>
              </tr>
            </tbody>
          </table>
        </div>

<?php if ($canConfirm): ?>
        <hr class="my-4">
        <h2><?= $alreadyHandled ? 'Update Confirmation' : 'Confirm This Order' ?></h2>

        <div id="alertBox" class="alert d-none" role="alert"></div>

        <form id="confirmForm" novalidate>
          <input type="hidden" name="action" value="supply-supplier-confirm-po">
          <input type="hidden" name="id" value="<?= (int)$po['PO_ID'] ?>">
          <input type="hidden" name="token" value="<?= $h($token) ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label for="leadTime" class="form-label fw-semibold">Delivery in</label>
              <select id="leadTime" name="Lead_Time_Days" class="form-select form-select-lg">
                <option value="1"  <?= $preselectDays === 1  ? 'selected' : '' ?>>Same day / next day (1 day)</option>
                <option value="2"  <?= $preselectDays === 2  ? 'selected' : '' ?>>2 days</option>
                <option value="3"  <?= $preselectDays === 3  ? 'selected' : '' ?>>3 days</option>
                <option value="5"  <?= $preselectDays === 5  ? 'selected' : '' ?>>About a week (5 days)</option>
                <option value="7"  <?= ($preselectDays === 7 || $preselectDays === 0) ? 'selected' : '' ?>>1 week (7 days)</option>
                <option value="10" <?= $preselectDays === 10 ? 'selected' : '' ?>>10 days</option>
                <option value="14" <?= $preselectDays === 14 ? 'selected' : '' ?>>2 weeks (14 days)</option>
                <option value="21" <?= $preselectDays === 21 ? 'selected' : '' ?>>3 weeks (21 days)</option>
                <option value="30" <?= $preselectDays === 30 ? 'selected' : '' ?>>1 month (30 days)</option>
              </select>
              <div class="form-text">We'll calculate the delivery date from today.</div>
            </div>
            <div class="col-md-6">
              <label for="status" class="form-label fw-semibold">Status</label>
              <select id="status" name="Status" class="form-select form-select-lg">
                <option value="Confirmed" <?= $status === 'Confirmed' ? 'selected' : '' ?>>Confirmed (preparing the order)</option>
                <option value="Shipped"   <?= $status === 'Shipped'   ? 'selected' : '' ?>>Shipped (already in transit)</option>
              </select>
            </div>
            <div class="col-12">
              <label for="notes" class="form-label fw-semibold">Notes for buyer <span class="text-muted fw-normal">(optional)</span></label>
              <textarea id="notes" name="Supplier_Notes" class="form-control" rows="3"
                        maxlength="1000"
                        placeholder="e.g. Delivering in two batches, courier name, tracking #..."><?= $h($po['Supplier_Notes'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4">
            <button type="submit" class="btn btn-brand btn-lg" id="submitBtn">
              <?= $alreadyHandled ? 'Update Order' : 'Place Order' ?>
            </button>
          </div>
        </form>
<?php else: ?>
        <hr class="my-4">
        <div class="alert alert-secondary mb-0">
          This purchase order is <strong><?= $h($status) ?></strong> and can no longer be updated through this link.
        </div>
<?php endif; ?>
      </div>
    </article>

    <p class="footer-note text-center mb-0">
      This page is for the named supplier only. Please do not share this link.
    </p>
<?php endif; ?>
  </main>

<?php if (!$invalid && $canConfirm): ?>
  <script>
    (function () {
      const form     = document.getElementById('confirmForm');
      const alertBox = document.getElementById('alertBox');
      const submit   = document.getElementById('submitBtn');

      function showAlert(type, message) {
        alertBox.className = 'alert alert-' + type;
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertBox.classList.add('d-none');

        submit.disabled = true;
        const originalLabel = submit.textContent;
        submit.textContent = 'Saving...';

        try {
          const body = new FormData(form);
          const res  = await fetch(window.location.pathname + window.location.search, { method: 'POST', body });
          const json = await res.json();
          if (json.status === 'success') {
            showAlert('success', json.message || 'Confirmation saved. Thank you!');
            setTimeout(() => window.location.reload(), 1200);
          } else {
            showAlert('danger', json.message || 'Could not save your confirmation.');
            submit.disabled = false;
            submit.textContent = originalLabel;
          }
        } catch (err) {
          showAlert('danger', 'Network error. Please try again.');
          submit.disabled = false;
          submit.textContent = originalLabel;
        }
      });
    })();
  </script>
<?php endif; ?>

<?php if (!$invalid && !empty($issues)): ?>
  <script>
    // Issue reply forms — works regardless of confirm-form availability so it
    // covers Received POs where the buyer has filed a problem.
    (function () {
      const id    = '<?= (int)($po['PO_ID'] ?? 0) ?>';
      const token = <?= json_encode($token) ?>;
      document.querySelectorAll('.issue-reply-form').forEach(rf => {
        rf.addEventListener('submit', async (e) => {
          e.preventDefault();
          const issueId = rf.dataset.issueId;
          const reply = rf.querySelector('textarea[name="reply"]').value.trim();
          if (!reply) return;
          const btn = rf.querySelector('button[type="submit"]');
          btn.disabled = true;
          const originalLabel = btn.textContent;
          btn.textContent = 'Sending...';
          try {
            const body = new URLSearchParams({
              action: 'supply-supplier-reply-issue',
              id, token,
              issue_id: issueId,
              reply
            });
            const res = await fetch(window.location.pathname + window.location.search, {
              method: 'POST',
              body
            });
            const json = await res.json();
            if (json.status === 'success') {
              alert(json.message || 'Reply sent. Thank you.');
              window.location.reload();
            } else {
              alert(json.message || 'Could not send reply.');
              btn.disabled = false;
              btn.textContent = originalLabel;
            }
          } catch (_) {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.textContent = originalLabel;
          }
        });
      });
    })();
  </script>
<?php endif; ?>
</body>
</html>
