<?php
$target = 'index.php';

if (isset($_GET['kat']) && $_GET['kat'] !== '') {
    $target .= '?kat=' . intval($_GET['kat']);
}

header('Location: ' . $target);
exit;
