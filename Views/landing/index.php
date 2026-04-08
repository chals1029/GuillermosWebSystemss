<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$googleAuthHint = $_SESSION['google_auth_hint'] ?? null;
if ($googleAuthHint !== null) {
  unset($_SESSION['google_auth_hint']);
}

if ($googleAuthHint === null && isset($_GET['google'])) {
  $status = strtolower(trim((string)($_GET['google'] ?? '')));
  if ($status === 'existing' || $status === 'new') {
    $email = trim((string)($_GET['gh_email'] ?? ''));
    $name = trim((string)($_GET['gh_name'] ?? ''));
    $suggestedUsername = trim((string)($_GET['gh_username'] ?? ''));
    $message = trim((string)($_GET['gh_message'] ?? ''));

    $hint = [
      'status' => $status,
    ];

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $hint['email'] = $email;
    }
    if ($name !== '') {
      $hint['name'] = $name;
    }
    if ($suggestedUsername !== '') {
      $hint['suggestedUsername'] = $suggestedUsername;
    }
    if ($message !== '') {
      $hint['message'] = $message;
    }

    $googleAuthHint = $hint;
  }
}

$googleAuthHintJson = json_encode($googleAuthHint, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($googleAuthHintJson === false) {
  $googleAuthHintJson = 'null';
}
$scriptName = $_SERVER['PHP_SELF'] ?? '';
$basePath = rtrim(preg_replace('#/Views/.*$#', '', $scriptName), '/');
if ($basePath === '/') {
  $basePath = '';
}
?>
<?php
// Fetch top product from customer_feedback by highest average rating
// Keep as best-effort; if no results found, we'll fallback to existing static cards
require_once __DIR__ . '/../../Config.php';

function safeImageForImg($image) {
  // If it's already a data URI, keep it
  if (empty($image)) return null;
  if (preg_match('#^data:image/#', $image)) return $image;

  // If it looks like binary blob (guess via control chars or length), convert to data URI
  $looksBinary = strlen($image) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $image);
  if ($looksBinary) {
    if (function_exists('finfo_buffer')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = $finfo ? finfo_buffer($finfo, $image) : 'image/jpeg';
      if ($finfo) finfo_close($finfo);
      return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($image);
    }
    return 'data:image/jpeg;base64,' . base64_encode($image);
  }

  // If looks like a path (relative or absolute), return as-is
  return $image;
}

function getTopDeals($conn, int $limit = 4, int $minSold = 1) {
  if (!($conn instanceof mysqli)) return [];
  $top = [];
  // If OwnerController exists, use its getProductPerformance to ensure parity with admin dashboard
  // Try to reuse OwnerController if available so landing matches admin results
  $ownerControllerPath = __DIR__ . '/../../Controllers/OwnerController.php';
  if (file_exists($ownerControllerPath)) {
    try {
      require_once $ownerControllerPath;
      if (class_exists('OwnerController')) {
        $ownerController = new OwnerController();
      $pf = $ownerController->getProductPerformance('all');
      if (is_array($pf) && !empty($pf)) {
        $pf = array_values(array_filter($pf));
        $slice = array_slice($pf, 0, $limit);
        foreach ($slice as $r) {
          $top[] = [
            'Product_ID' => $r['id'] ?? null,
            'Product_Name' => $r['name'] ?? null,
            'Description' => $r['cat'] ?? '',
            'Price' => $r['price'] ?? 0,
            'Image' => $r['image'] ?? null,
            'Category' => $r['cat'] ?? '',
            'sales' => (int)($r['sales'] ?? 0),
            'revenue' => (float)($r['revenue'] ?? 0),
            'Avg_Rating' => isset($r['rating']) ? (float)$r['rating'] : null,
            'FeedbackCount' => (int)($r['reviews'] ?? 0),
          ];
        }
        return $top;
        }
      }
    } catch (Throwable $e) {
      // fallback to manual SQL below
    }
  }
  // Match admin logic (sum quantity as 'sales', order by total quantity desc). Use completed orders only in landing page?
  // Admin uses total_quantity across all order_details; we try to match it as closely as possible.
  $sql = "SELECT p.Product_ID, p.Product_Name, p.Description, COALESCE(p.Price, 0) AS Price, p.Image, p.Category, s.total_quantity AS sales, s.total_revenue AS revenue, f.avg_rating AS Avg_Rating, f.total_reviews AS FeedbackCount, f.positive_reviews AS positive_reviews \n"
      . "FROM (SELECT od.Product_ID, SUM(od.Quantity) AS total_quantity, SUM(od.Subtotal) AS total_revenue FROM order_detail od GROUP BY od.Product_ID) s \n"
      . "JOIN product p ON p.Product_ID = s.Product_ID \n"
      . "LEFT JOIN (SELECT Product_ID, AVG(Rating) AS avg_rating, COUNT(*) AS total_reviews, SUM(CASE WHEN Rating >= 4 THEN 1 ELSE 0 END) AS positive_reviews FROM customer_feedback GROUP BY Product_ID) f ON f.Product_ID = p.Product_ID \n"
      . "ORDER BY s.total_quantity DESC, s.total_revenue DESC \n"
      . "LIMIT ?";
  try {
    // Use prepared statement to bind the minimum sold and limit
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param('i', $limit);
      $stmt->execute();
      $res = $stmt->get_result();
      $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($rows as $r) {
        $r['Image'] = safeImageForImg($r['Image'] ?? null);
        $r['sales'] = (int)($r['sales'] ?? 0);
        $r['revenue'] = (float)($r['revenue'] ?? 0);
        $r['positive_reviews'] = (int)($r['positive_reviews'] ?? 0);
        $r['FeedbackCount'] = (int)($r['FeedbackCount'] ?? 0);
        $r['Avg_Rating'] = isset($r['Avg_Rating']) && $r['Avg_Rating'] !== null ? (float)$r['Avg_Rating'] : null;
        // Normalize fields; do not add to $top yet — apply admin filters first
        // Apply the same filters as admin program: if there are feedback stats, apply positive reviews and rating threshold.
        $include = true;
        $hasFeedback = isset($r['FeedbackCount']) && $r['FeedbackCount'] > 0;
        $positiveThreshold = 4.0;
        if ($hasFeedback) {
          $positiveReviews = (int)($r['positive_reviews'] ?? 0);
          if ($positiveReviews <= 0) $include = false;
          if ($r['Avg_Rating'] !== null && $r['Avg_Rating'] < $positiveThreshold) $include = false;
        }
        if ($include) {
          $top[] = $r;
        }
      }
      $stmt->close();
    }
  } catch (Throwable $e) {
    // best-effort: ignore and let caller fallback to static markup
  }
  return $top;
}

$topDeals = [];
if (isset($conn) && $conn instanceof mysqli) {
  $topDeals = getTopDeals($conn, 4);
}
?>
<?php
// Helper: render rating stars for numeric Avg_Rating
function renderStars($rating, $max = 5) {
  if ($rating === null || $rating === '') return '';
  $rating = (float)$rating;
  $full = (int)floor($rating);
  $half = (($rating - $full) >= 0.5) ? 1 : 0;
  $empty = $max - $full - $half;
  $html = '';
  for ($i = 0; $i < $full; $i++) { $html .= '<i class="bi bi-star-fill" aria-hidden="true"></i>'; }
  if ($half) { $html .= '<i class="bi bi-star-half" aria-hidden="true"></i>'; }
  for ($i = 0; $i < $empty; $i++) { $html .= '<i class="bi bi-star" aria-hidden="true"></i>'; }
  $html .= ' <span class="rating-text">' . number_format($rating, 1) . '/5</span>';
  return $html;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Guillermo's Café</title>

  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="../../guillermos.ico">
  <link rel="shortcut icon" type="image/x-icon" href="../../guillermos.ico">

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <!-- Lobster Font for logo branding -->
  <link href="https://fonts.googleapis.com/css2?family=Lobster&display=swap" rel="stylesheet">

  <style>
    body, html {
      height: 100%;
      margin: 0;
      font-family: 'Poppins', sans-serif;
      scroll-behavior: smooth;
      background: url('bg/wallpaper.jpg') top center repeat-y;
      background-size: cover;
      background-attachment: fixed;
    }

    .zoom-on-scroll {
      transform: scale(0.9);
      opacity: 0;
      transition: transform 0.6s ease, opacity 0.6s ease;
      transition-delay: var(--zoom-delay, 0s);
    }

    .zoom-on-scroll.is-visible {
      transform: scale(1);
      opacity: 1;
    }

    .hero-fade {
      opacity: 0;
      animation: fadeInUp 0.8s ease forwards;
    }

    .hero-delay-1 { animation-delay: 0.15s; }
    .hero-delay-2 { animation-delay: 0.3s; }
    .hero-delay-3 { animation-delay: 0.45s; }

    .social-icons a {
      color: #fff;
      margin-right: 0;
      font-size: 1.5rem;
      text-decoration: none;
      transition: transform 0.3s ease, color 0.3s ease;
    }

    .social-icons a:hover {
      color: #d2a679;
      transform: translateY(-4px) scale(1.1);
    }

    .bg-overlay {
      background-color: rgba(0, 0, 0, 0.4);
      width: 100%;
      min-height: 100vh;
    }

    /* HEADER NAVBAR */
    .navbar {
      background: rgba(0, 0, 0, 0.4);
      backdrop-filter: blur(10px);
      position: fixed;
      top: 0;
      width: 100%;
      z-index: 10;
      padding: 15px 40px;
      display: flex; /* Ensure flexbox behavior */
      justify-content: space-between; /* Distribute items with space between */
      align-items: center; /* Vertically align items */
    }
    .navbar-brand {
      color: #f4e9c9;
      font-family: 'Lobster', cursive;
      font-size: clamp(1.1rem, 1.8vw, 1.8rem);
      letter-spacing: 0.05em;
      text-shadow: 0 3px 14px rgba(0, 0, 0, 0.35);
      flex-shrink: 1; /* Allow the brand to shrink */
      white-space: nowrap; /* Prevent text wrapping */
      overflow: hidden; /* Hide overflowing text */
      text-overflow: ellipsis; /* Show ellipsis for hidden text */
    }
    .navbar-brand:hover {
      color: #ffffff;
    }
    .logo-text {
      font-family: 'Lobster', cursive;
      transform: skewX(10deg);
    }
    .nav-link {
      color: #fff !important;
      margin: 0 10px;
      font-weight: 500;
      transition: 0.3s;
    }
    .nav-link:hover {
      color: #d2a679 !important;
    }
    .btn-login-nav {
      border: 2px solid #fff;
      border-radius: 50px;
      padding: 6px 20px;
      color: #fff;
      font-weight: bold;
      transition: 0.3s;
      text-decoration: none;
    }
    .btn-login-nav:hover {
      background-color: #d2a679;
      color: #fff;
      border-color: #d2a679;
    }

    /* HERO */
    .center-content {
      position: relative;
      z-index: 2;
      height: 100vh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      color: #fff;
      text-align: center;
      padding: 0 20px;
      transition: opacity 0.4s ease;
      width: min(75vw, 100%);
      margin: 0 auto;
    }

    .fade-hidden { opacity: 0.2; transition: opacity 0.4s ease; }

    .guill-tm {
      display: flex;
      align-items: flex-start;
      justify-content: center;
      gap: 5px;
      flex-wrap: nowrap;
    }

    .guill-tm img {
      width: min(60%, 450px);
      height: auto;
      margin-top: clamp(-15vh, -10vw, -10vh);
      margin-bottom: clamp(-15vh, -10vw, -10vh);
      animation: float 8s ease-in-out infinite alternate;
    }

    .guill-tm span {
      font-size: clamp(1rem, 2vw, 1.5rem);
      font-weight: bold;
      margin-top: 4vh;
    }

    .hero-branding {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 2vh;
    }

    .center-content p {
      max-width: 500px;
      font-size: clamp(0.9rem, 2.2vw, 1rem);
      margin-top: clamp(2vh, 4vw, 5vh);
      margin-bottom: 25px;
    }

    @media (min-width: 992px) {
      .center-content {
        width: 75vw;
      }
    }
    
    .social-icons {
      margin-top: 25px;
      display: flex;
      justify-content: center;
      gap: 20px;
      transition: opacity 0.4s ease;
    }

    /* Modal Styles */
    .modal-content {
      border-radius: 25px;
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.4);
      color: #fff;
      box-shadow: 0 0 15px 5px rgba(255, 255, 255, 0.3), 0 4px 30px rgba(0,0,0,0.3);
      transition: all 0.3s ease;
    }

    .modal.fade .modal-dialog {
      transform: translateY(-26px) scale(0.93);
      opacity: 0;
      transition: transform 0.45s ease, opacity 0.45s ease;
    }

    .modal.show .modal-dialog {
      transform: translateY(0) scale(1);
      opacity: 1;
    }

    .modal-content:hover {
      box-shadow: 0 0 20px 8px rgba(255, 255, 255, 0.4), 0 4px 30px rgba(0,0,0,0.3);
    }

    .modal-header,
    .modal-title {
      display: none;
    }

    /* Top Deals */
    #topDeals { padding: 100px 0; min-height: 100vh; }
    #topDeals h2 { font-weight: 700; margin-bottom: 100px; text-align:center; color:#fff; }
    #topDeals .card {
      border: none;
      border-radius: 20px;
      overflow: hidden;
      text-align: center;
      background: #fff;
      transition: transform 0.45s ease, box-shadow 0.45s ease;
    }
    #topDeals .card img { height: 180px; object-fit: cover; border-radius: 50%; width: 180px; margin: 20px auto; }
    #topDeals .badge { background-color: #d2a679; color: #fff; font-size: 0.8rem; border-radius: 10px; }
    #topDeals .btn-order { background-color: #d2a679; border: none; border-radius: 10px; color: #fff; font-weight: 600; padding: 8px 20px; }
    #topDeals .btn-order:hover { background-color: #b58961; }

    /* Price & Rating */
    .card-body .price-rating {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: -5px;
      margin-bottom: 10px;
    }
    .card-body .price-rating .price {
      font-weight: 600;
      color: #000;
    }
    .card-body .price-rating .rating {
      font-weight: 600;
      color: #d2a679;
    }
    /* Stars rendering */
    .rating-stars i { color: #d2a679; margin-right: 2px; }
    .rating-text { color: #24160e; font-weight: 600; margin-left: 6px; }

    /* Featured Drinks */
    #featuredDrinks {
      padding: 100px 0;
      color: #fff;
      text-align: center;
      min-height: 100vh;
    }

    #featuredDrinks h2 {
      font-weight: 700;
      margin-bottom: 100px;
      text-align: center;
      color: #fff;
    }

    #featuredDrinks .card {
      border: none;
      border-radius: 20px;
      overflow: hidden;
      text-align: center;
      background: #fff;
      padding: 1rem;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      transition: transform 0.45s ease, box-shadow 0.45s ease;
    }

    #featuredDrinks .card img {
      height: 180px;
      object-fit: cover;
      border-radius: 20px;
      width: 180px;
      margin: 20px auto;
    }

    #featuredDrinks .badge {
      background-color: #d2a679;
      color: #fff;
      font-size: 0.8rem;
      border-radius: 10px;
      position: absolute;
      top: 0;
      left: 0;
      margin: 0.5rem;
    }

    #featuredDrinks .btn-order {
      background-color: #d2a679;
      border: none;
      border-radius: 10px;
      color: #fff;
      font-weight: 600;
      padding: 8px 20px;
      transition: 0.3s;
    }

    #featuredDrinks .btn-order:hover {
      background-color: #b58961;
    }

    #topDeals .card:hover,
    #featuredDrinks .card:hover {
      transform: translateY(-10px) scale(1.02);
      box-shadow: 0 12px 25px rgba(0, 0, 0, 0.2);
    }

    #featuredDrinks .price-rating {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: -5px;
      margin-bottom: 10px;
    }

    #featuredDrinks .price-rating .price {
      font-weight: 600;
      color: #000;
    }

    #featuredDrinks .price-rating .rating {
      font-weight: 600;
      color: #d2a679;
    }

    /* About */
    #about {
      padding: 100px 0;
      color: #fff;
      min-height: 100vh;
    }

    #about .container {
      max-width: 1100px;
    }

    .about-wrapper {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: clamp(32px, 6vw, 60px);
    }

    /* About section infinite carousel */
    .about-media {
      flex: 1 1 420px;
      max-width: 100%;
      overflow: hidden;
      -webkit-mask-image: linear-gradient(to right, transparent, #000 10%, #000 90%, transparent);
      mask-image: linear-gradient(to right, transparent, #000 10%, #000 90%, transparent);
    }

    .about-media-scroller {
      margin-top: 5px;
      display: flex;
      gap: 16px;
      animation: scroll 30s linear infinite;
    }

    .about-media:hover .about-media-scroller {
      animation-play-state: paused;
    }

    .about-media figure {
      margin: 0;
      position: relative;
      overflow: hidden;
      border-radius: 22px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.32);
      aspect-ratio: 4 / 3;
      flex-shrink: 0;
      width: clamp(200px, 30vw, 300px);
    }

    .about-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.45s ease;
    }

    .about-media figure:hover img {
      transform: scale(1.05);
    }

    .about-copy {
      flex: 1 1 360px;
      color: rgba(255, 255, 255, 0.88);
      font-size: clamp(1rem, 1.2vw, 1.1rem);
      line-height: 1.75;
      text-align: center;
      max-width: 680px;
      margin-bottom: -2.7rem;
      
    }

    .about-copy h2 {
      font-weight: 700;
      margin-bottom: 4.6rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #fff;
    }

    .about-copy p {
      margin: 0 0 1.1rem;
    }

    @keyframes scroll {
      to {
        transform: translateX(calc(-100% - 16px * 5)); /* 5 images, 5 gaps */
      }
    }

    .form-label { color: #fff; }
    .form-control {
      background: rgba(255, 255, 255, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: #fff;
      border-radius: 10px;
      transition: 0.3s;
    }
    .form-control::placeholder { color: rgba(255, 255, 255, 0.7); }
    .btn-login {
      background-color: #d2a679;
      border: none;
      color: white;
      font-weight: bold;
      border-radius: 30px;
      padding: 10px;
      transition: 0.3s;
    }
    .btn-login:hover { background-color: #b58961; transform: translateY(-3px) scale(1.03); }
    .forgot-link, .register-link { color:#fff; cursor:pointer; }
    .forgot-link:hover, .register-link:hover { color:#d2a679; text-decoration:underline; }
    .key-icon {
      font-size: 2rem;
      color: #d2a679;
      margin-bottom: 10px;
    }

    .auth-form {
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
    }

    .auth-inline {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .auth-inline .form-check {
      margin: 0;
    }

    .auth-divider {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-size: 0.85rem;
      color: rgba(255, 255, 255, 0.7);
      margin: 0.5rem 0 0.25rem;
    }

    .auth-divider::before,
    .auth-divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: rgba(255, 255, 255, 0.25);
    }

    .btn-social {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 8px 16px;
      border-radius: 32px;
      border: 1px solid rgba(255, 255, 255, 0.4);
      color: #1f1f1f;
      font-weight: 600;
      background: #fff;
      text-decoration: none;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .btn-social:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(255, 255, 255, 0.18);
      color: #1f1f1f;
    }

    .btn-social__icon {
      width: 18px;
      height: 18px;
      object-fit: contain;
    }

    .auth-modal .modal-dialog {
      max-width: 960px;
    }

    .auth-modal .modal-content {
      background: transparent;
      border: none;
      border-radius: 24px;
      overflow: hidden;
    }

    .auth-modal .left-side {
      position: relative;
      min-height: 100%;
      overflow: hidden;
      background: #0a0a0a;
    }

    .auth-modal .slide-stage {
      position: absolute;
      inset: 0;
      background-image: var(--slide-bg, url('bg/wallpaper.jpg'));
      background-size: cover;
      background-position: center;
    }

    .auth-modal .slide-stage::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(180deg, rgba(0, 0, 0, 0.45) 0%, rgba(0, 0, 0, 0.75) 100%);
    }

    .auth-modal .logo-top {
      position: absolute;
      top: 30px;
      left: 30px;
      width: 90px;
      z-index: 3;
    }

    .auth-modal .slide-overlay {
      position: absolute;
      inset: 0;
      padding: clamp(28px, 4vw, 48px);
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      z-index: 3;
      gap: clamp(20px, 3vw, 32px);
    }

    .auth-modal .auth-slides {
      position: relative;
      min-height: clamp(220px, 28vh, 280px);
    }

    .auth-modal .auth-slide {
      position: absolute;
      inset: auto 0 0 0;
      display: flex;
      flex-direction: column;
      gap: 0.65rem;
      color: #fff;
      opacity: 0;
      transform: translateX(-40px);
      transition: opacity 0.6s ease, transform 0.6s ease;
      pointer-events: none;
    }

    .auth-modal .auth-slide.is-active {
      opacity: 1;
      transform: translateX(0);
      pointer-events: auto;
    }

    .auth-modal .auth-slide .slide-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.35rem 0.8rem;
      border-radius: 999px;
      background: rgba(210, 166, 121, 0.88);
      color: #240d00;
      font-size: 0.8rem;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .auth-modal .auth-slide h4 {
      font-size: clamp(1.6rem, 2.8vw, 2rem);
      font-weight: 700;
      margin: 0;
      line-height: 1.2;
    }

    .auth-modal .auth-slide p {
      margin: 0;
      color: rgba(255, 255, 255, 0.78);
      font-size: clamp(0.9rem, 1.6vw, 1rem);
      max-width: 360px;
    }

    .auth-modal .slide-dots {
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }

    .auth-modal .slide-dots button {
      width: 36px;
      height: 4px;
      border: none;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.35);
      padding: 0;
      transition: background 0.3s ease, transform 0.3s ease;
    }

    .auth-modal .slide-dots button.is-active {
      background: #d2a679;
      transform: scaleX(1.2);
    }

    .auth-modal .right-side {
      background: rgba(12, 12, 12, 0.78);
      backdrop-filter: blur(16px);
      padding: clamp(28px, 4vw, 48px);
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .auth-modal .right-side > .text-center {
      margin-bottom: clamp(16px, 3vw, 28px);
    }

    .auth-modal #modalTitle {
      font-weight: 700;
      color: #fff;
      margin-bottom: 6px;
    }

    .auth-modal #modalSubtitle {
      color: rgba(255, 255, 255, 0.65);
      font-size: 0.9rem;
    }

    .auth-modal #modalSubtitle a {
      color: #fff;
      text-decoration: underline;
    }

    .auth-modal .auth-switch {
      animation: authSwitch 0.35s ease forwards;
      transform-origin: top center;
    }

    .auth-modal .auth-form {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .auth-modal .auth-form .row > * {
      margin-bottom: 0;
    }

    .auth-modal .auth-form .form-control {
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: #fff;
      border-radius: 999px;
      padding: 0.8rem 1.4rem;
    }

    .auth-modal .auth-form .form-control::placeholder {
      color: rgba(255, 255, 255, 0.7);
    }

    .auth-modal .auth-form .form-control:focus {
      box-shadow: 0 0 0 0.2rem rgba(210, 166, 121, 0.25);
      border-color: #d2a679;
    }

    .auth-modal .password-field {
      position: relative;
    }

    .auth-modal .password-field .form-control {
      padding-right: 3rem;
    }

    .auth-modal .password-toggle-btn {
      position: absolute;
      top: 50%;
      right: 0.85rem;
      transform: translateY(-50%);
      border: none;
      background: transparent;
      color: rgba(255, 255, 255, 0.8);
      padding: 0.2rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .auth-modal .password-toggle-btn:hover {
      color: #fff;
    }

    .auth-modal .auth-form .btn-login {
      border-radius: 999px;
      height: 50px;
      font-weight: 700;
      letter-spacing: 0.04em;
      background: #d2a679;
      color: #fff;
      border: none;
    }

    .auth-modal .auth-form .btn-login:hover {
      background: #b58961;
    }

    .auth-modal .auth-form .btn-social {
      background: transparent;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.4);
      color: #fff;
      height: 50px;
      gap: 0.7rem;
      transition: transform 0.3s ease, border-color 0.3s ease;
    }

    .auth-modal .auth-form .btn-social:hover {
      transform: translateY(-2px);
      border-color: #fff;
    }

    .auth-modal .password-strength {
      margin-top: 0.35rem;
      padding: 0.7rem 0.85rem;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .auth-modal .password-strength-label {
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.78rem;
      font-weight: 600;
      margin-bottom: 0.35rem;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .auth-modal .password-rules {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 0.2rem;
    }

    .auth-modal .password-rules li {
      font-size: 0.82rem;
      color: rgba(255, 255, 255, 0.72);
    }

    .auth-modal .password-rules li.is-valid {
      color: #baf7cc;
    }

    .auth-modal .auth-form .btn-social img,
    .auth-modal .auth-form .btn-social i {
      width: 22px;
      height: 22px;
      object-fit: contain;
    }

    .auth-modal .auth-form .form-check-label,
    .auth-modal .auth-form .form-check-input {
      color: rgba(255, 255, 255, 0.8);
    }

    .auth-modal .auth-form .form-check-input {
      background: transparent;
      border-color: rgba(255, 255, 255, 0.5);
    }

    .auth-modal .auth-form .form-check-input:checked {
      background-color: #007bff;
      border-color: #007bff;
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e");
    }

    .auth-modal .form-message {
      margin: 0;
    }

    @media (max-width: 991.98px) {
      .auth-modal .modal-dialog {
        max-width: min(95vw, 540px);
      }
      .auth-modal .right-side {
        border-radius: 24px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .auth-modal .auth-slide {
        transition: none;
        transform: none;
      }
    }

    .auth-switch {
      animation: authSwitch 0.35s ease forwards;
      transform-origin: top center;
    }

    .form-message {
      display: none;
      margin-bottom: 16px;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 0.9rem;
      text-align: center;
      font-weight: 500;
    }

    .form-message[data-state] {
      display: block;
    }

    .form-message[data-state='info'] {
      background: rgba(33, 150, 243, 0.18);
      border: 1px solid rgba(33, 150, 243, 0.35);
      color: #dbeefe;
    }

    .form-message[data-state='success'] {
      background: rgba(76, 175, 80, 0.2);
      border: 1px solid rgba(76, 175, 80, 0.45);
      color: #e7ffe6;
    }

    .form-message[data-state='error'] {
      background: rgba(217, 83, 79, 0.2);
      border: 1px solid rgba(217, 83, 79, 0.45);
      color: #ffe3e0;
    }

    .btn-login.is-loading {
      opacity: 0.75;
      pointer-events: none;
    }

    @keyframes authSwitch {
      0% {
        opacity: 0;
        transform: translateY(18px) scale(0.97);
      }
      60% {
        transform: translateY(-4px) scale(1.01);
      }
      100% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @keyframes fadeInUp {
      0% {
        opacity: 0;
        transform: translateY(36px) scale(0.96);
      }
      60% {
        transform: translateY(-6px) scale(1.01);
      }
      100% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @keyframes float {
      0% {
        transform: translateY(0);
      }
      100% {
        transform: translateY(-18px);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .hero-fade {
        animation: none !important;
        opacity: 1;
      }
      .zoom-on-scroll {
        transition: none;
        transform: none;
        opacity: 1;
      }
      .guill-tm img {
        animation: none;
      }
      .modal.fade .modal-dialog,
      .modal.show .modal-dialog {
        transition: none;
        transform: none;
        opacity: 1;
      }
      .auth-switch {
        animation: none;
      }
    }

    /* ----------------- MOBILE HAMBURGER & MENU (only visible on small screens) ----------------- */
    /* Keep the desktop layout untouched. Only under 992px we hide the inline nav and show the hamburger. */
    .mobile-hamburger {
      display: none;
      background: none;
      border: none;
      width:44px;
      height:44px;
      transition: background .15s, transform .15s;
      color: #fff;
      z-index: 1102;
    }
    .mobile-hamburger:hover { background: rgba(255,255,255,0.04); transform: scale(1.02); }

    .hamburger-lines {
      display:inline-block;
      width:22px;
      height:2px;
      background:#fff;
      position:relative;
      border-radius:2px;
      transition: transform .22s ease;
    }
    .hamburger-lines::before,
    .hamburger-lines::after {
      content: "";
      position: absolute;
      left: 0;
      width:22px;
      height:2px;
      background:#fff;
      border-radius:2px;
      transition: transform .22s ease, top .22s ease, bottom .22s ease, opacity .18s;
    }
    .hamburger-lines::before { top: -7px; }
    .hamburger-lines::after { bottom: -7px; }
    /* when open -> morph to X */
    .mobile-hamburger.is-open .hamburger-lines {
      background: transparent;
    }
    .mobile-hamburger.is-open .hamburger-lines::before {
      transform: rotate(45deg) translate(4px, 4px);
      top: 0;
    }
    .mobile-hamburger.is-open .hamburger-lines::after {
      transform: rotate(-45deg) translate(4px, -4px);
      bottom: 0;
    }

    /* mobile menu that slides down under the navbar */
    .mobile-menu {
      position: fixed;
      left: 0;
      right: 0;
      top: 64px; /* approx navbar height; safe default */
      transform: translateY(-110%);
      background: rgba(6,6,6,0.95);
      color: #fff;
      z-index: 1100;
      padding: 12px 18px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.45);
      transition: transform .32s cubic-bezier(.2,.9,.2,1), opacity .25s ease;
      opacity: 0;
      border-bottom-left-radius: 10px;
      border-bottom-right-radius: 10px;
    }
    .mobile-menu.open {
      transform: translateY(0);
      opacity: 1;
    }
    .mobile-menu ul {
      list-style: none;
      margin: 6px 0;
      padding: 6px 0;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .mobile-menu a {
      color: #fff;
      padding: 12px 10px;
      display: block;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 600;
      transition: background .18s, color .18s, transform .12s;
    }
    .mobile-menu a:hover {
      background: rgba(255,255,255,0.04);
      color: #d2a679;
      transform: translateX(4px);
    }

    /* subtle overlay behind menu to avoid interaction with page */
    .mobile-menu-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.35);
      z-index: 1099;
      display: none;
      transition: opacity .25s ease;
    }
    .mobile-menu-overlay.show {
      display: block;
      opacity: 1;
    }

    /* prevent body scroll while mobile menu open */
    body.mobile-menu-open {
      overflow: hidden;
    }

    /* only show hamburger and hide inline nav at small widths */
    @media (max-width: 991.98px) {
      .navbar { padding: 12px 18px; }
      .navbar .ms-auto {
        display: none !important; /* hide desktop nav items on mobile */
      }
      .mobile-hamburger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: auto;
      }
    }

  </style>
  <script>
    window.APP_BASE_PATH = <?php echo json_encode($basePath ?: ''); ?>;
    window.USER_LOGGED_IN = <?= isset($_SESSION['user']) && !empty($_SESSION['user']) ? 'true' : 'false' ?>;
    window.__GOOGLE_AUTH_HINT = <?php echo $googleAuthHintJson; ?>;
  </script>
</head>

<body>
  <div class="bg-overlay">
    <!-- HEADER -->
    <nav class="navbar navbar-expand-lg">
      <a class="navbar-brand logo-text" href="#">Guillermo's Café</a>
      <div class="ms-auto d-flex align-items-center">
        <a href="#topDeals" class="nav-link">Top Deals</a>
        <a href="#featuredDrinks" class="nav-link">Featured Drinks</a>
        <a href="#about" class="nav-link">About</a>
        <a href="#" class="btn-login-nav ms-3" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a>
      </div>

      <!-- MOBILE HAMBURGER (only visible on mobile) - appears on the right side -->
      <button id="mobileHamburger" class="mobile-hamburger" aria-expanded="false" aria-controls="mobileMenu" aria-label="Open menu">
        <span class="hamburger-lines" aria-hidden="true"></span>
      </button>
    </nav>

    <!-- Mobile slide-down menu + overlay -->
    <div id="mobileMenu" class="mobile-menu" role="menu" aria-hidden="true">
      <ul>
        <li><a href="#topDeals" class="mobile-menu-link" data-close>Top Deals</a></li>
        <li><a href="#featuredDrinks" class="mobile-menu-link" data-close>Featured Drinks</a></li>
        <li><a href="#about" class="mobile-menu-link" data-close>About</a></li>
        <li><a href="#" class="mobile-menu-link" data-bs-toggle="modal" data-bs-target="#loginModal" data-close>Login</a></li>
      </ul>
    </div>
    <div id="mobileMenuOverlay" class="mobile-menu-overlay" aria-hidden="true"></div>

    <!-- HERO -->
    <div class="center-content" id="mainContent">
      <div class="hero-branding">
        <div class="guill-tm hero-fade">
          <img src="bg/guill.png" alt="Guillermo's Logo" class="img-fluid">
          <span>TM</span>
        </div>
        <h5 class="hero-fade hero-delay-1">SINCE 2020</h5>
      </div>
      <p class="hero-fade hero-delay-2">Welcome to Guillermo's Café! Enjoy freshly baked, handcrafted treats made with love to brighten your day in cozy comfort.</p>
      <div class="social-icons hero-fade hero-delay-3" id="socialIcons">
        <a href="https://www.facebook.com/share/1EpCnwYXfb/" target="_blank"><i class="bi bi-facebook"></i></a>
        <a href="tel:09682569677"><i class="bi bi-telephone"></i></a>
      </div>
    </div>

    <!-- TOP DEALS -->
    <section id="topDeals">
      <div class="container">
        <h2>TOP DEALS</h2>
        <div class="row justify-content-center g-4">
          <?php
          // Build a list of up to 4 cards: dynamic top deals followed by sample fallbacks if needed
          $fallbacks = [
            [ 'Category' => 'Student Meal', 'Image' => 'Rice_meal/adobo.jpg', 'Product_Name' => 'Chicken Adobo Flakes', 'Description'=> 'Nice and crispy chicken adobo flakes', 'Price' => 150.0, 'OrderCount' => 0, 'Avg_Rating' => 4.8, 'Rating' => '4.8/5' ],
            [ 'Category' => 'Most Loved', 'Image' => 'Pastries/choco.jpg', 'Product_Name' => 'Chocolate Cake', 'Description' => 'A decadent, dark chocolate cake with a rich, fudge frosting', 'Price' => 550.0, 'OrderCount' => 0, 'Avg_Rating' => 4.8, 'Rating' => '4.8/5' ],
            [ 'Category' => 'Premium Dish', 'Image' => 'Pasta/aglio.jpg', 'Product_Name' => 'Seafood Aglio Olio', 'Description' => 'Olive oil, garlic, parmesan cheese, shrimps, mussels', 'Price' => 190.0, 'OrderCount' => 0, 'Avg_Rating' => 4.8, 'Rating' => '4.8/5' ],
            [ 'Category' => '#Best Seller', 'Image' => 'Pizza/pizza.jpg', 'Product_Name' => 'Beef & Mushroom Pizza', 'Description' => 'Ground beef rich in spices, mushrooms and cheese', 'Price' => 260.0, 'OrderCount' => 0, 'Avg_Rating' => 4.8, 'Rating' => '4.8/5' ],
          ];
          $cards = [];
          if (!empty($topDeals) && is_array($topDeals)) {
            foreach ($topDeals as $d) {
              $cards[] = [
                'Category' => $d['Category'] ?? 'Top Deal',
                'Image' => $d['Image'] ?? null,
                'Product_Name' => $d['Product_Name'] ?? 'Top Deal',
                'Description' => $d['Description'] ?? '',
                'Price' => $d['Price'] ?? 0,
                'OrderCount' => $d['sales'] ?? 0,
                'Avg_Rating' => $d['Avg_Rating'] ?? null,
                'Product_ID' => $d['Product_ID'] ?? null,
              ];
            }
          }
          // Fill with fallbacks if not enough
          while (count($cards) < 4) {
            $cards[] = array_shift($fallbacks);
          }
          foreach ($cards as $card) {
          ?>
          <div class="col-md-3 col-sm-6">
            <div class="card p-3 shadow-sm position-relative zoom-on-scroll" style="--zoom-delay: 0s;" <?= isset($card['Product_ID']) ? 'data-product-id="'.(int)$card['Product_ID'].'"' : '' ?> >
              <span class="badge position-absolute top-0 start-0 m-2"><?= htmlspecialchars($card['Category']) ?></span>
              <img src="<?= htmlspecialchars($card['Image'] ?? 'Rice_meal/adobo.jpg') ?>" alt="<?= htmlspecialchars($card['Product_Name']) ?>">
              <div class="card-body">
                <h5 class="card-title"><?= htmlspecialchars($card['Product_Name']) ?></h5>
                <p class="text-muted"><?= htmlspecialchars($card['Description']) ?></p>
                <div class="price-rating">
                  <span class="price">₱<?= number_format((float)($card['Price'] ?? 0), 2) ?><?php if (!empty($card['Category']) && strtolower($card['Category']) === 'rice_meal'): ?> <small class="text-muted">Bowl</small><?php endif; ?></span>
                  <span class="rating">
                    <?php if (isset($card['OrderCount']) && (int)$card['OrderCount'] > 0) { echo 'Orders: ' . (int)$card['OrderCount']; } else { echo 'Orders: 0'; } ?>
                    <?php if (!empty($card['Avg_Rating'])) { echo ' • '; echo renderStars($card['Avg_Rating']); } elseif (!empty($card['Rating'])) { echo ' • ' . htmlspecialchars($card['Rating']); } ?>
                  </span>
                </div>
                <button class="btn btn-order" <?= isset($card['Product_ID']) ? 'data-product-id="'.(int)$card['Product_ID'].'"' : '' ?>>Order Now</button>
              </div>
            </div>
          </div>
          <?php } ?>
        </div>
      </div>
    </section>

    <!-- FEATURED DRINKS -->
    <section id="featuredDrinks">
      <div class="container">
        <h2>FEATURED DRINKS</h2>
        <div class="row justify-content-center g-4">
          <div class="col-md-3 col-sm-6">
            <div class="card p-3 shadow-sm position-relative zoom-on-scroll" style="--zoom-delay: 0s;">
              <span class="badge position-absolute top-0 start-0 m-2">Student Favorite</span>
              <img src="Drinks/spanishlatte.jpg" alt="Spanish Latte">
              <div class="card-body">
                <h5 class="card-title">Spanish Latte</h5>
                <p class="text-muted">Rich espresso latte with a touch of cinnamon</p>
                <div class="price-rating">
                  <span class="price">₱140.00</span>
                  <span class="rating">4.7/5 ⭐</span>
                </div>
                <button class="btn btn-order">Order Now</button>
              </div>
            </div>
          </div>

          <div class="col-md-3 col-sm-6">
            <div class="card p-3 shadow-sm position-relative zoom-on-scroll" style="--zoom-delay: 0.1s;">
              <span class="badge position-absolute top-0 start-0 m-2">Most Loved</span>
              <img src="Drinks/Caramel Macchiato.jpg" alt="Caramel Macchiato">
              <div class="card-body">
                <h5 class="card-title">Caramel Macchiato</h5>
                <p class="text-muted">Smooth espresso with caramel drizzle and milk foam</p>
                <div class="price-rating">
                  <span class="price">₱150.00</span>
                  <span class="rating">4.8/5 ⭐</span>
                </div>
                <button class="btn btn-order">Order Now</button>
              </div>
            </div>
          </div>

          <div class="col-md-3 col-sm-6">
            <div class="card p-3 shadow-sm position-relative zoom-on-scroll" style="--zoom-delay: 0.2s;">
              <span class="badge position-absolute top-0 start-0 m-2">Premium</span>
              <img src="Drinks/Matcha Latte.jpg" alt="Matcha Latte">
              <div class="card-body">
                <h5 class="card-title">Matcha Latte</h5>
                <p class="text-muted">Creamy Japanese green tea latte with foam</p>
                <div class="price-rating">
                  <span class="price">₱150.00</span>
                  <span class="rating">4.9/5 ⭐</span>
                </div>
                <button class="btn btn-order">Order Now</button>
              </div>
            </div>
          </div>

          <div class="col-md-3 col-sm-6">
            <div class="card p-3 shadow-sm position-relative zoom-on-scroll" style="--zoom-delay: 0.3s;">
              <span class="badge position-absolute top-0 start-0 m-2">#Best Seller</span>
              <img src="Drinks/Lemon berry.jpg" alt="Lemon berry">
              <div class="card-body">
                <h5 class="card-title">Lemon Berry</h5>
                <p class="text-muted">Tangy mixed berries with fresh lemon slices over</p>
                <div class="price-rating">
                  <span class="price">₱80.00</span>
                  <span class="rating">4.8/5 ⭐</span>
                </div>
                <button class="btn btn-order">Order Now</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>



    <!-- ABOUT -->
    <section id="about">
      <div class="container">
        <div class="about-wrapper">
            <div class="about-copy">
              <h2>ABOUT US</h2>
              <p>Guillermo's Café, established locally in 2020, is a coffee shop, bakery, and restaurant dedicated to serving high-quality, homemade products made with love and passion.</p>
              <p>We offer a wide variety of food and beverages, including coffee, milk tea, breads, pastries, pasta, pizza, and burgers. Committed to excellence and customer satisfaction, Guillermo's Café continues to provide a warm and enjoyable dining experience for everyone.</p>
            </div>
            <div class="about-media">
              <div class="about-media-scroller">
                <figure class="about-photo">
                  <img src="PHOTO_S/Front.jpg" alt="Welcoming storefront of Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Inside.jpg" alt="Inviting interior of Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Ambiance.jpg" alt="Relaxed ambiance inside Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Table.jpg" alt="Signature table spread at Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Poster.jpg" alt="Guillermo's Café feature poster">
                </figure>
                <!-- Duplicated for infinite loop -->
                <figure class="about-photo">
                  <img src="PHOTO_S/Front.jpg" alt="Welcoming storefront of Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Inside.jpg" alt="Inviting interior of Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Ambiance.jpg" alt="Relaxed ambiance inside Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Table.jpg" alt="Signature table spread at Guillermo's Café">
                </figure>
                <figure class="about-photo">
                  <img src="PHOTO_S/Poster.jpg" alt="Guillermo's Café feature poster">
                </figure>
              </div>
            </div>
        </div>
      </div>
    </section>
  </div>

  <!-- LOGIN/REGISTER MODAL -->
  <div class="modal fade auth-modal" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl" id="authModalDialog">
      <div class="modal-content">
        <div class="row g-0">
          <div class="col-lg-6 left-side d-none d-lg-block">
            <div class="slide-stage" id="authSlideStage"></div>
            <div class="logo-top">
              <img src="bg/guill.png" alt="Guillermo's Café logo" class="img-fluid">
            </div>
            <div class="slide-overlay">
              <div class="auth-slides" id="authSlides">
                <div class="auth-slide is-active" data-bg="Drinks/spanishlatte.jpg">
                  <span class="slide-badge">Best Drinks</span>
                  <h4>Spanish Latte Bliss</h4>
                  <p>Rich espresso latte with a touch of cinnamon for a cozy uplift.</p>
                </div>
                <div class="auth-slide" data-bg="Pasta/aglio.jpg">
                  <span class="slide-badge">Pasta Favorites</span>
                  <h4>Seafood Aglio Olio</h4>
                  <p>Garlic-infused olive oil pasta loaded with shrimp and mussels.</p>
                </div>
                <div class="auth-slide" data-bg="Pastries/choco.jpg">
                  <span class="slide-badge">Sweet Pastries</span>
                  <h4>Chocolate Cake Indulgence</h4>
                  <p>Decadent layers of dark chocolate baked fresh every morning.</p>
                </div>
                <div class="auth-slide" data-bg="Pizza/pizza.jpg">
                  <span class="slide-badge">Signature Pizza</span>
                  <h4>Beef &amp; Mushroom Pizza</h4>
                  <p>Crispy crust topped with savory beef, mushrooms, and cheese.</p>
                </div>
                <div class="auth-slide" data-bg="Rice_meal/adobo.jpg">
                  <span class="slide-badge">Rice Meals</span>
                  <h4>Chicken Adobo Flakes</h4>
                  <p>Golden adobo flakes paired with steaming rice and pickled veggies.</p>
                </div>
              </div>
              <div class="slide-dots" id="authSlideDots"></div>
            </div>
          </div>
          <div class="col-lg-6 right-side">
            <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-4" data-bs-dismiss="modal" aria-label="Close"></button>
            <div class="text-center">
              <h3 class="text-white fw-bold mb-1" id="modalTitle">Create an account</h3>
              <p class="text-white-50 small mb-0" id="modalSubtitle"></p>
            </div>
            <div id="modalFormContainer" class="mt-4"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    const zoomTargets = document.querySelectorAll('.zoom-on-scroll');

    if ('IntersectionObserver' in window) {
      const zoomObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
          } else {
            entry.target.classList.remove('is-visible');
          }
        });
      }, { threshold: 0.2 });

      zoomTargets.forEach(el => zoomObserver.observe(el));
    } else {
      zoomTargets.forEach(el => el.classList.add('is-visible'));
    }

    const loginModal = document.getElementById('loginModal');
    const mainContent = document.getElementById('mainContent');
    const socialIcons = document.getElementById('socialIcons');
    const modalFormContainer = document.getElementById('modalFormContainer');
    const modalTitleEl = document.getElementById('modalTitle');
    const modalSubtitleEl = document.getElementById('modalSubtitle');
    const slideStage = document.getElementById('authSlideStage');
    const slidesContainer = document.getElementById('authSlides');
    const slideDotsContainer = document.getElementById('authSlideDots');
    const slideItems = slidesContainer ? Array.from(slidesContainer.querySelectorAll('.auth-slide')) : [];
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const googleAuthHint = window.__GOOGLE_AUTH_HINT || null;
    const appBasePath = window.APP_BASE_PATH || '';
    let slideDots = [];
    let slideIndex = 0;
    let slideIntervalId = null;
    const SLIDE_INTERVAL = 6000;

    loginModal.addEventListener('show.bs.modal', () => {
      mainContent.classList.add('fade-hidden');
      socialIcons.classList.add('fade-hidden');
      if (slideItems.length) {
        goToSlide(slideIndex, true);
        restartSlideShow();
      }
    });

    loginModal.addEventListener('hidden.bs.modal', () => {
      mainContent.classList.remove('fade-hidden');
      socialIcons.classList.remove('fade-hidden');
      stopSlideShow();
    });

    loginModal.addEventListener('shown.bs.modal', () => {
      if (!googleAuthHint) {
        return;
      }
      if (googleAuthHint.status === 'existing') {
        const passwordInput = loginModal.querySelector('#loginForm input[name="password"]');
        if (passwordInput) {
          passwordInput.focus();
        }
      } else if (googleAuthHint.status === 'new') {
        const usernameInput = loginModal.querySelector('#registerForm input[name="username"]');
        if (usernameInput) {
          usernameInput.focus();
        }
      }
    });

    function setupSlideDots() {
      if (!slideDotsContainer || !slideItems.length) {
        return;
      }
      slideDotsContainer.innerHTML = '';
      slideDots = slideItems.map((_, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', `Show highlight ${index + 1}`);
        button.addEventListener('click', () => {
          goToSlide(index, true);
          restartSlideShow();
        });
        slideDotsContainer.appendChild(button);
        return button;
      });
    }

    function updateSlideDots() {
      if (!slideDots.length) {
        return;
      }
      slideDots.forEach((dot, index) => {
        if (index === slideIndex) {
          dot.classList.add('is-active');
          dot.setAttribute('aria-current', 'true');
        } else {
          dot.classList.remove('is-active');
          dot.removeAttribute('aria-current');
        }
      });
    }

    function goToSlide(targetIndex, force = false) {
      if (!slideItems.length) {
        return;
      }
      const nextIndex = ((targetIndex % slideItems.length) + slideItems.length) % slideItems.length;
      if (!force && nextIndex === slideIndex) {
        return;
      }
      const previousSlide = slideItems[slideIndex];
      const nextSlide = slideItems[nextIndex];

      if (previousSlide && previousSlide !== nextSlide) {
        previousSlide.classList.remove('is-active');
      }
      if (nextSlide) {
        nextSlide.classList.add('is-active');
        if (slideStage) {
          const bg = nextSlide.dataset.bg || 'bg/wallpaper.jpg';
          slideStage.style.setProperty('--slide-bg', `url('${bg}')`);
        }
      }

      slideIndex = nextIndex;
      updateSlideDots();
    }

    function startSlideShow() {
      if (slideIntervalId || slideItems.length <= 1 || prefersReducedMotion) {
        return;
      }
      slideIntervalId = setInterval(() => {
        goToSlide(slideIndex + 1);
      }, SLIDE_INTERVAL);
    }

    function stopSlideShow() {
      if (!slideIntervalId) {
        return;
      }
      clearInterval(slideIntervalId);
      slideIntervalId = null;
    }

    function restartSlideShow() {
      stopSlideShow();
      startSlideShow();
    }

    function initializeAuthSlides() {
      if (!slideItems.length) {
        return;
      }
      slideIndex = 0;
      setupSlideDots();
      goToSlide(0, true);
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    const viewHeaders = {
      login: {
        title: 'Welcome back',
        subtitle: `Don't have an account? <a href="#" class="text-white js-view-link" data-view="register">Register</a>`
      },
      register: {
        title: 'Create an account',
        subtitle: `Already have an account? <a href="#" class="text-white js-view-link" data-view="login">Log in</a>`
      },
      forgot: {
        title: 'Forgot password?',
        subtitle: `<a href="#" class="text-white js-view-link" data-view="login">Back to login</a>`
      },
      reset: {
        title: 'Reset password',
        subtitle: ({ email }) => {
          const safe = escapeHtml(email || 'your email');
          return `Enter the code we sent to <strong>${safe}</strong>.`;
        }
      },
      verify: {
        title: 'Verify email',
        subtitle: ({ email }) => {
          const safe = escapeHtml(email || 'your email');
          return `Enter the code we sent to <strong>${safe}</strong>.`;
        }
      }
    };

    const templates = {
      login: () => `
        <form id="loginForm" class="auth-form" action="${appBasePath}/Controllers/AuthController.php?action=login" method="POST">
          <div class="form-message" data-message></div>
          <div class="row g-3">
            <div class="col-12">
              <input type="text" class="form-control" name="identity" required placeholder="Email or Username">
            </div>
            <div class="col-12">
              <input type="password" class="form-control" name="password" required placeholder="Password">
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 small text-white-50">
            <a href="#" class="text-white js-view-link" data-view="forgot">Forgot password?</a>
          </div>
          <button type="submit" class="btn btn-login w-100 mt-2">Log In</button>
          <div class="text-center text-white-50 my-3">or continue with</div>
          <a href="${appBasePath}/Controllers/GoogleAuthController.php" class="btn btn-social w-100">
            <img src="bg/google.png" alt="Google" class="btn-social__icon">
            <span>Sign in with Google</span>
          </a>
        </form>
      `,
      register: () => `
        <form id="registerForm" class="auth-form" action="${appBasePath}/Controllers/AuthController.php?action=register" method="POST" novalidate>
          <div class="form-message" data-message></div>
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <input type="text" class="form-control" name="username" required placeholder="Username">
            </div>
            <div class="col-12 col-md-6">
              <input type="text" class="form-control" name="name" required placeholder="Full Name">
            </div>
            <div class="col-12">
              <input type="email" class="form-control" name="email" required placeholder="Email">
            </div>
            <div class="col-12">
              <input type="tel" class="form-control" name="phonenumber" required placeholder="Contact Number" pattern="[0-9]{11}" maxlength="11">
            </div>
            <div class="col-12">
              <input type="password" class="form-control" name="password" required placeholder="Password" minlength="8" autocomplete="new-password">
              <div class="password-strength" data-password-strength>
                <div class="password-strength-label">Password strength</div>
                <ul class="password-rules">
                  <li data-rule="minLength">At least 8 characters</li>
                  <li data-rule="hasUpper">At least 1 uppercase letter</li>
                  <li data-rule="hasLower">At least 1 lowercase letter</li>
                  <li data-rule="hasNumber">At least 1 number</li>
                  <li data-rule="hasSpecial">At least 1 special character</li>
                </ul>
              </div>
            </div>
          </div>
          <div class="form-check text-white-50 mt-2">
            <input class="form-check-input" type="checkbox" id="confirmCheck" required>
            <label class="form-check-label" for="confirmCheck">
              I confirm all the details are correct
            </label>
          </div>
          <button type="submit" class="btn btn-login w-100 mt-3" id="registerBtn" disabled>Create Account</button>
          <div class="text-center text-white-50 my-3">or register with</div>
          <a href="${appBasePath}/Controllers/GoogleAuthController.php" class="btn btn-social w-100">
            <img src="bg/google.png" alt="Google" class="btn-social__icon">
            <span>Sign up with Google</span>
          </a>
        </form>
      `,
      forgot: () => `
        <form id="forgotForm" class="auth-form" action="${appBasePath}/Controllers/AuthController.php?action=forgot-password" method="POST">
          <p class="text-white-50 small text-center mb-1">We'll email you a reset code.</p>
          <div class="form-message" data-message></div>
          <input type="email" class="form-control" name="email" required placeholder="Email address">
          <button type="submit" class="btn btn-login w-100">Send reset code</button>
          <div class="text-center text-white-50 mt-3">
            Remembered it? <a href="#" class="text-white js-view-link" data-view="login">Log in</a>
          </div>
        </form>
      `,
      reset: ({ email = '' } = {}) => {
        const safeEmail = escapeHtml(email);
        return `
          <form id="resetForm" class="auth-form" action="${appBasePath}/Controllers/AuthController.php?action=reset-password" method="POST" novalidate>
            <input type="hidden" name="email" value="${safeEmail}">
            <div class="form-message" data-message></div>
            <input type="text" class="form-control" name="reset_code" required placeholder="Reset code" pattern="[0-9]{6}" maxlength="6">
            <input type="password" class="form-control" name="new_password" required placeholder="New password" minlength="6">
            <input type="password" class="form-control" name="confirm_password" required placeholder="Confirm new password" minlength="6">
            <button type="submit" class="btn btn-login w-100">Update password</button>
            <div class="text-center text-white-50 mt-3">
              Back to <a href="#" class="text-white js-view-link" data-view="login">Log in</a>
            </div>
          </form>
        `;
      },
      verify: ({ email = '' } = {}) => {
        const safeEmail = escapeHtml(email);
        const displayEmail = safeEmail || 'your email';
        return `
          <form id="verifyForm" class="auth-form" action="${appBasePath}/Controllers/AuthController.php?action=verify-email" method="POST" novalidate>
            <input type="hidden" name="email" value="${safeEmail}">
            <p class="text-white-50 small text-center mb-1">Enter the 6-digit code sent to <strong>${displayEmail}</strong>.</p>
            <div class="form-message" data-message></div>
            <input type="text" class="form-control" name="verification_code" required placeholder="Verification code" pattern="[0-9]{6}" maxlength="6">
            <button type="submit" class="btn btn-login w-100">Verify email</button>
            <div class="text-center text-white-50 mt-3">
              Didn't receive the code? <a href="#" data-role="resend">Resend</a>
            </div>
            <div class="text-center text-white-50 mt-2">
              <a href="#" class="text-white js-view-link" data-view="login">Back to login</a>
            </div>
          </form>
        `;
      }
    };

    let currentView = 'login';
    let pendingEmail = '';
    let pendingResetEmail = '';

    function getHeader(view, options) {
      const config = viewHeaders[view];
      if (!config) {
        return { title: 'Account', subtitle: '' };
      }
      const title = typeof config.title === 'function' ? config.title(options) : config.title;
      const subtitle = typeof config.subtitle === 'function' ? config.subtitle(options) : config.subtitle;
      return { title, subtitle };
    }

    function renderView(view, options = {}) {
      currentView = view;
      const { title, subtitle } = getHeader(view, options);
      modalTitleEl.textContent = title || 'Account';
      if (subtitle) {
        modalSubtitleEl.innerHTML = subtitle;
        modalSubtitleEl.classList.remove('d-none');
      } else {
        modalSubtitleEl.innerHTML = '';
        modalSubtitleEl.classList.add('d-none');
      }

      const viewMarkup = templates[view] ? templates[view](options) : '';
      modalFormContainer.innerHTML = `<div class="auth-switch">${viewMarkup}</div>`;
      wireLinks();
      attachFormHandlers(view, options);
      attachPasswordToggles(modalFormContainer);
      const messageBox = modalFormContainer.querySelector('[data-message]');
      const defaultHintMessage = getGoogleHintMessage(view);
      if (messageBox) {
        if (options.message) {
          setMessage(messageBox, options.message, options.state || 'info');
        } else if (defaultHintMessage) {
          setMessage(messageBox, defaultHintMessage.text, defaultHintMessage.state);
        } else {
          setMessage(messageBox, '');
        }
      }

      applyGoogleHintToView(view);

    }

    function wireLinks() {
      loginModal.querySelectorAll('.js-view-link').forEach(link => {
        link.addEventListener('click', event => {
          event.preventDefault();
          const targetView = link.dataset.view;
          if (!targetView) {
            return;
          }
          if (targetView === 'verify') {
            if (pendingEmail) {
              renderView('verify', { email: pendingEmail });
            }
            return;
          }
          renderView(targetView);
        });
      });
    }

    function attachFormHandlers(view, options = {}) {
      if (view === 'login') {
        const form = modalFormContainer.querySelector('#loginForm');
        if (form) {
          form.addEventListener('submit', handleLoginSubmit);
        }
      }

      if (view === 'register') {
        const form = modalFormContainer.querySelector('#registerForm');
        if (form) {
          form.addEventListener('submit', handleRegisterSubmit);
          const confirmCheck = form.querySelector('#confirmCheck');
          const registerBtn = form.querySelector('#registerBtn');
          const passwordInput = form.querySelector('input[name="password"]');
          const syncRegisterState = () => {
            const passwordValue = passwordInput ? passwordInput.value : '';
            const checks = getPasswordChecks(passwordValue);
            updatePasswordRuleUI(form, checks);
            const strongEnough = Object.values(checks).every(Boolean);
            if (registerBtn) {
              registerBtn.disabled = !(confirmCheck && confirmCheck.checked && strongEnough);
            }
          };
          if (confirmCheck) {
            confirmCheck.addEventListener('change', syncRegisterState);
          }
          if (passwordInput) {
            passwordInput.addEventListener('input', syncRegisterState);
          }
          syncRegisterState();
        }
      }

      if (view === 'verify') {
        const form = modalFormContainer.querySelector('#verifyForm');
        if (form) {
          const emailInput = form.querySelector('input[name="email"]');
          if (emailInput && !emailInput.value && pendingEmail) {
            emailInput.value = pendingEmail;
          }
          form.addEventListener('submit', handleVerifySubmit);
          const resendLink = form.querySelector('[data-role="resend"]');
          if (resendLink) {
            resendLink.addEventListener('click', handleResendClick);
          }
        }
      }

      if (view === 'forgot') {
        const form = modalFormContainer.querySelector('#forgotForm');
        if (form) {
          form.addEventListener('submit', handleForgotSubmit);
        }
      }

      if (view === 'reset') {
        const form = modalFormContainer.querySelector('#resetForm');
        if (form) {
          form.addEventListener('submit', handleResetSubmit);
        }
      }
    }

    function attachPasswordToggles(scope) {
      if (!scope) {
        return;
      }

      const passwordInputs = scope.querySelectorAll('input[type="password"]');
      passwordInputs.forEach(input => {
        if (input.dataset.toggleReady === '1') {
          return;
        }

        const parent = input.parentNode;
        if (!parent) {
          return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'password-field';
        parent.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'password-toggle-btn';
        toggleBtn.setAttribute('aria-label', 'Show password');
        toggleBtn.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';

        toggleBtn.addEventListener('click', () => {
          const showPassword = input.type === 'password';
          input.type = showPassword ? 'text' : 'password';
          toggleBtn.innerHTML = `<i class="bi ${showPassword ? 'bi-eye-slash' : 'bi-eye'}" aria-hidden="true"></i>`;
          toggleBtn.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        });

        wrapper.appendChild(toggleBtn);
        input.dataset.toggleReady = '1';
      });
    }

    function getGoogleHintMessage(view) {
      if (!googleAuthHint) {
        return null;
      }
      if (googleAuthHint.status === 'existing' && view === 'login') {
        return {
          text: googleAuthHint.message || 'We recognized your Google email. Enter your password to continue.',
          state: 'info'
        };
      }
      if (googleAuthHint.status === 'new' && view === 'register') {
        return {
          text: googleAuthHint.message || 'We saved your Google email. Complete the details to finish signing up.',
          state: 'info'
        };
      }
      return null;
    }

    function applyGoogleHintToView(view) {
      if (!googleAuthHint) {
        return;
      }

      if (view === 'login' && googleAuthHint.email) {
        const identityInput = modalFormContainer.querySelector('#loginForm input[name="identity"]');
        if (identityInput) {
          identityInput.value = googleAuthHint.email;
        }
      }

      if (view === 'register') {
        if (googleAuthHint.email) {
          const emailInput = modalFormContainer.querySelector('#registerForm input[name="email"]');
          if (emailInput) {
            emailInput.value = googleAuthHint.email;
          }
        }
        if (googleAuthHint.name) {
          const nameInput = modalFormContainer.querySelector('#registerForm input[name="name"]');
          if (nameInput && !nameInput.value) {
            nameInput.value = googleAuthHint.name;
          }
        }
        if (googleAuthHint.status === 'new' && googleAuthHint.email) {
          const usernameInput = modalFormContainer.querySelector('#registerForm input[name="username"]');
          if (usernameInput && !usernameInput.value) {
            const suggestionSource = googleAuthHint.suggestedUsername
              ? String(googleAuthHint.suggestedUsername)
              : (googleAuthHint.email.includes('@') ? googleAuthHint.email.split('@')[0] : googleAuthHint.email);
            const suggestion = suggestionSource.trim();
            if (suggestion) {
              usernameInput.value = suggestion;
            }
          }
        }
      }
    }

    function setMessage(box, text, state = 'info') {
      if (!box) {
        return;
      }
      if (!text) {
        box.textContent = '';
        delete box.dataset.state;
        return;
      }
      box.textContent = text;
      box.dataset.state = state;
    }

    function getPasswordChecks(password) {
      const value = String(password || '');
      return {
        minLength: value.length >= 8,
        hasUpper: /[A-Z]/.test(value),
        hasLower: /[a-z]/.test(value),
        hasNumber: /\d/.test(value),
        hasSpecial: /[^A-Za-z0-9]/.test(value)
      };
    }

    function updatePasswordRuleUI(form, checks) {
      if (!form) {
        return;
      }

      const rules = form.querySelectorAll('[data-password-strength] [data-rule]');
      rules.forEach(ruleEl => {
        const ruleName = ruleEl.dataset.rule;
        if (!ruleName) {
          return;
        }
        if (checks[ruleName]) {
          ruleEl.classList.add('is-valid');
        } else {
          ruleEl.classList.remove('is-valid');
        }
      });
    }

    function setButtonLoading(button, loadingText) {
      if (!button) {
        return;
      }
      if (!button.dataset.initialText) {
        button.dataset.initialText = button.textContent.trim();
      }
      button.classList.add('is-loading');
      button.disabled = true;
      if (loadingText) {
        button.textContent = loadingText;
      }
    }

    function clearButtonLoading(button) {
      if (!button) {
        return;
      }
      button.classList.remove('is-loading');
      button.disabled = false;
      if (button.dataset.initialText) {
        button.textContent = button.dataset.initialText;
        delete button.dataset.initialText;
      }
    }

    async function submitWithJson(url, formData) {
      const response = await fetch(url, {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      const rawText = await response.text();
      let data = null;
      if (rawText) {
        try {
          data = JSON.parse(rawText);
        } catch (error) {
          console.warn('Non-JSON response received:', rawText);
        }
      }

      return { response, data, rawText };
    }

    function extractErrorMessage(rawText) {
      const trimmed = (rawText || '').trim();
      if (!trimmed) {
        return '';
      }

      const withoutScripts = trimmed.replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, ' ');
      const plain = withoutScripts.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      return plain.slice(0, 200);
    }

    async function handleRegisterSubmit(event) {
      event.preventDefault();
      const form = event.currentTarget;
      const messageBox = form.querySelector('[data-message]');
      const submitButton = form.querySelector('button[type="submit"]');
      const passwordInput = form.querySelector('input[name="password"]');

      const checks = getPasswordChecks(passwordInput ? passwordInput.value : '');
      const strongEnough = Object.values(checks).every(Boolean);
      if (!strongEnough) {
        setMessage(messageBox, 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.', 'error');
        return;
      }

      setMessage(messageBox, 'Sending verification code...', 'info');
      setButtonLoading(submitButton, 'Sending...');

      const formData = new FormData(form);

      try {
        const { response, data, rawText } = await submitWithJson(form.action, formData);
        if (response.ok && data && data.status === 'success') {
          pendingEmail = data.email || formData.get('email') || '';
          renderView('verify', {
            email: pendingEmail,
            message: data.message || 'Verification code sent. Please check your email.',
            state: 'info'
          });
        } else {
          if (!data) {
            console.error('Registration response (raw):', rawText);
          }
          const errorMessage = data?.message || extractErrorMessage(rawText) || 'Registration failed. Please try again.';
          setMessage(messageBox, errorMessage, 'error');
        }
      } catch (error) {
        console.error('Registration error:', error);
        setMessage(messageBox, 'Unable to send verification email right now. Please try again.', 'error');
      } finally {
        clearButtonLoading(submitButton);
      }
    }

    async function handleLoginSubmit(event) {
      event.preventDefault();
      const form = event.currentTarget;
      const messageBox = form.querySelector('[data-message]');
      const submitButton = form.querySelector('button[type="submit"]');

      setMessage(messageBox, 'Signing you in...', 'info');
      setButtonLoading(submitButton, 'Signing in...');

      const formData = new FormData(form);

      try {
        const { response, data, rawText } = await submitWithJson(form.action, formData);
        if (response.ok && data && data.status === 'success' && data.redirect) {
          setMessage(messageBox, 'Login successful. Redirecting...', 'success');
          window.location.href = data.redirect;
          return;
        }

        if (!data) {
          console.error('Login response (raw):', rawText);
        }
        const errorMessage = data?.message || extractErrorMessage(rawText) || 'Login failed. Please check your credentials and try again.';
        setMessage(messageBox, errorMessage, 'error');
      } catch (error) {
        console.error('Login error:', error);
        setMessage(messageBox, 'Unable to sign in right now. Please try again.', 'error');
      } finally {
        clearButtonLoading(submitButton);
      }
    }

    async function handleForgotSubmit(event) {
      event.preventDefault();
      const form = event.currentTarget;
      const messageBox = form.querySelector('[data-message]');
      const submitButton = form.querySelector('button[type="submit"]');

      setMessage(messageBox, 'Sending reset code...', 'info');
      setButtonLoading(submitButton, 'Sending...');

      const formData = new FormData(form);

      try {
        const { response, data, rawText } = await submitWithJson(form.action, formData);
        if (response.ok && data && data.status === 'success') {
          pendingResetEmail = data.email || formData.get('email') || '';
          const proceedToReset = Boolean(data.proceed_to_reset);
          const successMessage = data.message || 'Reset code sent! Please check your email.';
          setMessage(messageBox, successMessage, proceedToReset ? 'info' : 'success');
          setTimeout(() => {
            renderView('reset', {
              email: pendingResetEmail,
              message: proceedToReset
                ? 'Use the reset code already sent to your email. You can request a new one after 5 minutes.'
                : 'Enter the code we sent to complete your password reset.',
              state: 'info'
            });
          }, 800);
        } else {
          if (!data) {
            console.error('Forgot password response (raw):', rawText);
          }
          const errorMessage = data?.message || extractErrorMessage(rawText) || 'Unable to send reset code. Please try again.';
          setMessage(messageBox, errorMessage, 'error');
        }
      } catch (error) {
        console.error('Forgot password error:', error);
        setMessage(messageBox, 'Unable to send reset code right now. Please try again.', 'error');
      } finally {
        clearButtonLoading(submitButton);
      }
    }

    async function handleResetSubmit(event) {
      event.preventDefault();
      const form = event.currentTarget;
      const messageBox = form.querySelector('[data-message]');
      const submitButton = form.querySelector('button[type="submit"]');

      const emailField = form.querySelector('input[name="email"]');
      if (emailField && !emailField.value && pendingResetEmail) {
        emailField.value = pendingResetEmail;
      }

      const newPassword = form.querySelector('input[name="new_password"]').value;
      const confirmPassword = form.querySelector('input[name="confirm_password"]').value;

      if (newPassword !== confirmPassword) {
        setMessage(messageBox, 'Passwords do not match. Please try again.', 'error');
        return;
      }

      setMessage(messageBox, 'Updating password...', 'info');
      setButtonLoading(submitButton, 'Updating...');

      const formData = new FormData(form);

      try {
        const { response, data, rawText } = await submitWithJson(form.action, formData);
        if (response.ok && data && data.status === 'success') {
          setMessage(messageBox, 'Password updated! Redirecting to login...', 'success');
          pendingResetEmail = '';
          setTimeout(() => {
            renderView('login', {
              message: 'Password updated successfully. Please sign in with your new password.',
              state: 'success'
            });
          }, 1000);
        } else {
          if (!data) {
            console.error('Reset password response (raw):', rawText);
          }
          const errorMessage = data?.message || extractErrorMessage(rawText) || 'Unable to update password. Please try again.';
          setMessage(messageBox, errorMessage, 'error');
        }
      } catch (error) {
        console.error('Reset password error:', error);
        setMessage(messageBox, 'Unable to update password right now. Please try again.', 'error');
      } finally {
        clearButtonLoading(submitButton);
      }
    }

    async function handleVerifySubmit(event) {
      event.preventDefault();
      const form = event.currentTarget;
      const messageBox = form.querySelector('[data-message]');
      const submitButton = form.querySelector('button[type="submit"]');

      setMessage(messageBox, 'Verifying code...', 'info');
      setButtonLoading(submitButton, 'Verifying...');

      const formData = new FormData(form);
      if (!formData.get('email') && pendingEmail) {
        formData.set('email', pendingEmail);
      }

      try {
        const { response, data, rawText } = await submitWithJson(form.action, formData);
        if (response.ok && data && data.status === 'success') {
          setMessage(messageBox, 'Verification successful! Redirecting to login...', 'success');
          setTimeout(() => {
            pendingEmail = '';
            renderView('login', {
              message: 'Account verified successfully! Please log in.',
              state: 'success'
            });
          }, 1200);
        } else {
          if (!data) {
            console.error('Verification response (raw):', rawText);
          }
          const errorMessage = data?.message || extractErrorMessage(rawText) || 'Invalid verification code. Please try again.';
          setMessage(messageBox, errorMessage, 'error');
        }
      } catch (error) {
        console.error('Verification error:', error);
        setMessage(messageBox, 'We could not verify the code. Please try again.', 'error');
      } finally {
        clearButtonLoading(submitButton);
      }
    }

    async function handleResendClick(event) {
      event.preventDefault();
      const link = event.currentTarget;
      if (link.dataset.loading === '1') {
        return;
      }

      const form = modalFormContainer.querySelector('#verifyForm');
      if (!form) {
        return;
      }

      const messageBox = form.querySelector('[data-message]');

      const emailInput = form.querySelector('input[name="email"]');
      const emailToUse = (emailInput?.value || pendingEmail || '').trim();
      if (!emailToUse) {
        setMessage(messageBox, 'No email address available. Please register again.', 'error');
        return;
      }

      setMessage(messageBox, 'Sending a new verification code...', 'info');

      const originalText = link.textContent;
      link.dataset.loading = '1';
      link.textContent = 'Sending...';

      const formData = new FormData();
      formData.append('email', emailToUse);

      try {
        const resendUrl = `${appBasePath}/Controllers/AuthController.php?action=resend-code`;
        const { response, data, rawText } = await submitWithJson(resendUrl, formData);
        if (response.ok && data && data.status === 'success') {
          pendingEmail = emailToUse;
          setMessage(messageBox, data.message || 'A new verification code has been sent.', 'success');
        } else {
          if (!data) {
            console.error('Resend code response (raw):', rawText);
          }
          const errorMessage = data?.message || extractErrorMessage(rawText) || 'Unable to resend the verification code. Please try again later.';
          setMessage(messageBox, errorMessage, 'error');
        }
      } catch (error) {
        console.error('Resend code error:', error);
        setMessage(messageBox, 'Unable to resend the verification code right now. Please try again.', 'error');
      } finally {
        link.textContent = originalText;
        delete link.dataset.loading;
      }
    }

    initializeAuthSlides();

    let initialView = 'login';
    let initialOptions = {};
    if (googleAuthHint) {
      if (googleAuthHint.status === 'existing') {
        initialView = 'login';
        initialOptions = {
          message: googleAuthHint.message || 'We recognized your Google email. Enter your password to continue.',
          state: 'info'
        };
      } else if (googleAuthHint.status === 'new') {
        initialView = 'register';
        initialOptions = {
          message: googleAuthHint.message || 'We saved your Google email. Complete the details to finish signing up.',
          state: 'info'
        };
      }
    }

    renderView(initialView, initialOptions);

    if (googleAuthHint) {
      const modalInstance = bootstrap.Modal.getOrCreateInstance(loginModal);
      modalInstance.show();
    }

    document.querySelectorAll('[data-bs-target="#loginModal"]').forEach(trigger => {
      trigger.addEventListener('click', () => {
        const targetView = googleAuthHint && googleAuthHint.status === 'new' ? 'register' : 'login';
        renderView(targetView);
        if (slideItems.length) {
          goToSlide(0, true);
          restartSlideShow();
        }
      });
    });

    // === Directing to login form (clicking order now button) - open login when product-specific ===//
    document.querySelectorAll('.btn-order').forEach(button => {
      button.addEventListener('click', function(e) {
        e.preventDefault();
        const el = this;
        // If this button is associated with a product (top-deal) or inside the topDeals section, open the login modal directly
        const isTopDeal = el.closest('#topDeals') !== null || (el.dataset && el.dataset.productId);
        if (isTopDeal) {
          const loginModalEl = document.getElementById('loginModal');
          if (loginModalEl) {
            const modalInstance = bootstrap.Modal.getOrCreateInstance(loginModalEl);
            modalInstance.show();
            return;
          }
          // fallback: if no modal available, redirect to main landing page (login is accessible from there)
          const base = `<?= $basePath ?>` || '';
          window.location.href = base + '/Views/landing/index.php';
          return;
        }

        // Non-product buttons: show friendly chat reply or fallback to chat overlay
        if (window.landingChat && typeof window.landingChat.handleOrderNow === 'function') {
          window.landingChat.handleOrderNow();
        } else {
          const openBtn = document.getElementById('landing-open-chat');
          if (openBtn) openBtn.click();
          setTimeout(() => {
            const messagesEl = document.getElementById('landing-chat-messages');
            if (messagesEl) messagesEl.innerHTML = '';
            const botEl = document.createElement('div');
            botEl.className = 'chat-bubble bot';
            botEl.innerHTML = 'To order, please sign in on our website or create an account. Click the Login button above to sign in.';
            messagesEl.appendChild(botEl);
          }, 200);
        }
      });
    });

    /* ----------------- MOBILE HAMBURGER BEHAVIOR ----------------- */
    (function() {
      const hamburger = document.getElementById('mobileHamburger');
      const mobileMenu = document.getElementById('mobileMenu');
      const overlay = document.getElementById('mobileMenuOverlay');

      function openMenu() {
        if (!mobileMenu || !hamburger || !overlay) return;
        hamburger.classList.add('is-open');
        hamburger.setAttribute('aria-expanded', 'true');
        mobileMenu.classList.add('open');
        mobileMenu.setAttribute('aria-hidden', 'false');
        overlay.classList.add('show');
        document.body.classList.add('mobile-menu-open');
      }

      function closeMenu() {
        if (!mobileMenu || !hamburger || !overlay) return;
        hamburger.classList.remove('is-open');
        hamburger.setAttribute('aria-expanded', 'false');
        mobileMenu.classList.remove('open');
        mobileMenu.setAttribute('aria-hidden', 'true');
        overlay.classList.remove('show');
        document.body.classList.remove('mobile-menu-open');
      }

      // Toggle on hamburger click
      hamburger?.addEventListener('click', (e) => {
        e.stopPropagation();
        if (mobileMenu.classList.contains('open')) {
          closeMenu();
        } else {
          openMenu();
        }
      });

      // Close when clicking overlay
      overlay?.addEventListener('click', closeMenu);

      // Close when a mobile menu link clicked (and respect data-bs-target to open modal)
      document.querySelectorAll('.mobile-menu-link').forEach(link => {
        link.addEventListener('click', (e) => {
          // allow anchors to navigate to sections; close menu first for a smooth UX
          closeMenu();
          // If this is supposed to trigger the modal (Login), bootstrap will handle the modal open via data attributes.
        });
      });

      // Close menu when switching to desktop size to avoid stuck state
      window.addEventListener('resize', () => {
        if (window.innerWidth > 991.98 && mobileMenu.classList.contains('open')) {
          closeMenu();
        }
      });

      // Accessibility: close on Escape
      window.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && mobileMenu.classList.contains('open')) {
          closeMenu();
        }
      });
    })();
  </script>

      <!-- Add Chat Widget Start -->
      <div id="landing-chat-root"></div>
      <style>
      /* Landing chat widget */
      #landing-chat-root .chat-btn { position: fixed; right: 18px; bottom: 18px; z-index: 99999; }
      #landing-chat-root .chat-note { position: fixed; right: 86px; bottom: 26px; z-index: 99999; background:#fff; color:#6B4F3F; border-radius:16px; padding:6px 10px; box-shadow:0 6px 18px rgba(0,0,0,0.15); font-weight:700; display:inline-block; }
      #landing-chat-root .chat-btn button { background: #6B4F3F; color: white; width:56px; height:56px; border-radius:50%; border:none; box-shadow: 0 8px 20px rgba(0,0,0,0.25); font-size:22px; cursor:pointer }
      #landing-chat-root .chat-overlay { position: fixed; right: 18px; bottom: 86px; width: 360px; max-width:92vw; height: 460px; background: #fff; border-radius: 12px; box-shadow:0 14px 40px rgba(0,0,0,0.25); overflow:hidden; z-index: 99999; display:none; }
      #landing-chat-root .chat-header { background:#6B4F3F; color:#fff; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; }
      #landing-chat-root .chat-messages { padding:12px; overflow-y:auto; height: 340px; background:#f9f9f9; }
      #landing-chat-root .chat-input { padding:10px; display:flex; gap:8px; border-top:1px solid #eee; }
      #landing-chat-root .chat-input input { padding: 10px; border-radius:8px; border:1px solid #ddd; width:100%; }
      #landing-chat-root .chat-suggest { padding:8px; display:flex; gap:8px; flex-wrap:wrap; }
      #landing-chat-root .chat-suggest button { background:#e9e9e9; border:none; padding:6px 10px; border-radius:10px; cursor:pointer; }
      #landing-chat-root .chat-bubble { display:inline-block; padding:8px 12px; border-radius:12px; margin:6px 0; max-width:78%; }
      #landing-chat-root .chat-bubble.user { background:#6B4F3F; color:#fff; text-align:right; float:right; }
      #landing-chat-root .chat-bubble.bot { background:#fff; border:1px solid #eee; color:#333; float:left; }
      #landing-chat-root .small-muted { font-size:12px; color:#666; margin-top:6px; text-align:center; }
      </style>

      <script>
      // Landing Chat: simple client-side chatbot without AI
      (() => {
        const root = document.getElementById('landing-chat-root');
        if (!root) return;

        root.innerHTML = `
          <div class="chat-note" id="landing-chat-note" style="display:none;">Hi there 👋</div>
          <div class="chat-btn"><button id="landing-open-chat" aria-label="Open chat" title="Open chat">🤖</button></div>
          <div class="chat-overlay" id="landing-chat-overlay">
            <div class="chat-header"><div>Guillermo's Helper</div><button id="landing-close-chat" style="background:transparent;border:none;color:#fff;font-size:18px;cursor:pointer">×</button></div>
            <div class="chat-messages" id="landing-chat-messages"><div style="text-align:center;margin-top:120px;color:#888;">Hi there! How can I help you today?</div></div>
            <div class="chat-suggest" id="landing-chat-suggest"></div>
            <div class="chat-input"><input id="landing-chat-input" placeholder="Type your question..." /><button id="landing-chat-send">Send</button></div>
          </div>
        `;

        const overlay = document.getElementById('landing-chat-overlay');
        const openBtn = document.getElementById('landing-open-chat');
        const closeBtn = document.getElementById('landing-close-chat');
        const messagesEl = document.getElementById('landing-chat-messages');
        const suggestEl = document.getElementById('landing-chat-suggest');
        const inputEl = document.getElementById('landing-chat-input');
        const sendBtn = document.getElementById('landing-chat-send');

        function addMessage(role, text) {
          const div = document.createElement('div');
          div.className = 'chat-bubble ' + (role === 'user' ? 'user' : 'bot');
          div.innerHTML = text;
          messagesEl.appendChild(div);
          messagesEl.scrollTop = messagesEl.scrollHeight;
          // Re-bind any in-message login links: clicking them should open the login modal
          // Use event delegation below as well so new content is covered; this ensures links with ids like
          // 'landing-chat-login', 'landing-chat-login-order', 'landing-chat-login-typed' trigger modal open.
        }

        function reply(text) {
          setTimeout(() => addMessage('bot', text), 300);
        }

        const S_MAP_LINK = 'https://www.google.com/maps/place/Guillermo%27s+Bread+and+Pastry/@14.0554808,120.6428081,17z/data=!3m1!4b1!4m6!3m5!1s0x33bd972cab250eb5:0x6e0e76c19a9e1241!8m2!3d14.0554756!4d120.645383!16s%2Fg%2F11qpy1t852?entry=ttu&g_ep=EgoyMDI1MTEyMy4xIKXMDSoASAFQAw%3D%3D';
        const S_ADDRESS = 'camp avejar, 112 J P Laurel St, Nasugbu, Batangas';
        const suggestions = [
        { label: 'View Menu', action: () => handleViewMenu() },
        { label: 'Opening Hours', action: () => reply('We are open from 7:00 AM to 9:00 PM daily.') },
        { label: 'Where are you located?', action: () => reply(`We are at ${S_ADDRESS}. <a href="${S_MAP_LINK}" target="_blank">Open in Google Maps</a>` ) },
        { label: 'Order Now', action: () => handleOrderNow() },
      ];

        function renderSuggestions() {
          suggestEl.innerHTML = '';
          suggestions.forEach(s => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = s.label;
            b.addEventListener('click', s.action);
            suggestEl.appendChild(b);
          });
        }

        function goToCategory(category) {
          // navigate to customer menu filtered by category
          const base = `<?= $basePath ?>` || '';
          const url = base + '/Views/customer_dashboard/Customer.php' + (category && category !== 'all' ? '?category=' + encodeURIComponent(category) : '');
          window.location.href = url;
        }

        function goToCustomer() {
          const base = `<?= $basePath ?>` || '';
          const url = base + '/Views/customer_dashboard/Customer.php';
          window.location.href = url;
        }

        function openLoginModal() {
          // fallback: if a login modal exists, open it. Otherwise, point to login page
          const loginModal = document.getElementById('loginModal');
          if (loginModal) {
            const modal = bootstrap.Modal.getOrCreateInstance(loginModal);
            modal.show();
          } else {
            const base = `<?= $basePath ?>` || '';
            window.location.href = base + '/Views/landing/index.php';
          }
        }

        function handleViewMenu() {
          // Open login modal immediately and give brief instructions
          openLoginModal();
          reply('Opening the login dialog so you can view our menu and place orders.');
        }

        function handleOrderNow() {
          // Open login modal directly to improve UX, then show confirmation in chat
          openLoginModal();
          reply('Opening the login dialog so you can sign in and place an order.');
        }

        function handleInput(text) {
          addMessage('user', text);
          text = (text || '').toLowerCase();
          if (text.includes('menu') || text.includes('order') || text.includes('pizza') || text.includes('drink')) {
            openLoginModal();
            reply('Opening the login dialog so you can view our full menu and place orders.');
            return;
          }
          if (text.includes('open') || text.includes('hour') || text.includes('time')) {
            reply('We are open from 7:00 AM to 9:00 PM daily.');
            return;
          }
          if (text.includes('location') || text.includes('where') || text.includes('address')) {
            reply(`We are at ${S_ADDRESS} — here is a map: <a href="${S_MAP_LINK}" target="_blank">Open in Google Maps</a>`);
            return;
          }
          if (text.includes('reserve') || text.includes('reservation') || text.includes('book')) {
            reply('You can reserve via your account on our website. Please log in and head to the Reservations section.');
            return;
          }
          if (text.includes('contact') || text.includes('phone') || text.includes('email')) {
            reply('You can call us at (123) 456-7890 or email (info@guillermoscafe.shop)');
            return;
          }
          // fallback
          reply("Sorry, I didn't quite get that — try asking about 'menu', 'hours', 'location', or 'order'.");
        }

        function showWelcomeNote() {
          const note = document.getElementById('landing-chat-note');
          if (!note) return;
          note.style.display = 'inline-block';
          note.classList.remove('fade-out');
          void note.offsetWidth; // trigger reflow
          note.classList.add('pop');
          // Hide after a while
          setTimeout(() => {
            note.classList.remove('pop');
            note.classList.add('fade-out');
            setTimeout(() => { try { note.style.display = 'none'; } catch(e) {} }, 420);
          }, 3600);
        }
        function init() {
          openBtn.addEventListener('click', () => {
            // Toggle overlay open/close when clicking the landing chat button
            if (overlay.style.display === 'block') {
              overlay.style.display = 'none';
              return;
            }
            overlay.style.display = 'block';
            inputEl.focus();
            // Show an introductory message when chat is opened for the first time
            if (!landingWelcomeShown) {
              reply("Hello! I'm Guillermo's Helper 🤖 — I can help with the menu, opening hours, location, and ordering.");
              landingWelcomeShown = true;
            }
          });
          closeBtn.addEventListener('click', () => overlay.style.display = 'none');
          sendBtn.addEventListener('click', () => { const t = inputEl.value.trim(); if (t) { handleInput(t); inputEl.value = ''; } });
          inputEl.addEventListener('keypress', e => { if (e.key === 'Enter') { sendBtn.click(); } });
          renderSuggestions();
        }

        init();
        // Show welcome chat-note when the page loads
        setTimeout(() => showWelcomeNote(), 900);
        // Expose functions to global so the page buttons can use it
        window.landingChat = {
            handleOrderNow: handleOrderNow,
            handleViewMenu: handleViewMenu,
            openLoginModal: openLoginModal
        };
      })();
      </script>
      <!-- Add Chat Widget End -->
</body>
</html>