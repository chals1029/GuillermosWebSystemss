<?php
session_start();
session_destroy();
header('Location: Views/landing/index.php');
exit;
?>