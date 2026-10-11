<?php
/**
 * Web UI page of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
// logout.php — destroys the session. State-changing: requires POST + a valid
// CSRF token so a third-party page cannot force-logout a logged-in user.
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Security\SessionGuard;

SessionGuard::start();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && SessionGuard::verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    SessionGuard::logout();
}
header('Location: login.php');
exit;
