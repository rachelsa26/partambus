<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

redirect(current_user() ? '/dashboard.php' : '/auth/login.php');
