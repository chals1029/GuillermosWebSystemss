<?php
// Redirect root to the landing page view while respecting installations under a subdirectory.
$basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

if ($basePath === '.' || $basePath === '/') {
	$basePath = '';
}

header('Location: ' . $basePath . '/Views/landing/index.php');
exit;

?>
