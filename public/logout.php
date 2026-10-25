<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\UrlHelper;

Auth::logout();
header('Location: ' . UrlHelper::url('login.php'));
exit;

