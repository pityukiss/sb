<?php
include __DIR__ . '/db.php';
include __DIR__ . '/auth.php';

authLogoutUser($conn);
header("Location: index.php");
exit;
