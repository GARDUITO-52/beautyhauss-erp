<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Content-Type: application/json');
echo json_encode(['token' => csrfToken()]);
