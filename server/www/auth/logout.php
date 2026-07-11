<?php
require_once __DIR__ . '/auth.php';
destroy_session();
header('Location: /auth/login.php');
exit;
