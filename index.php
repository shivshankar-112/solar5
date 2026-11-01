<?php

require_once __DIR__ . '/src/Services/LicensingService.php';
// If license is invalid, halt the app and show the License Form
if (!(new \App\Services\LicensingService())->isValid()) {
    require_once __DIR__ . '/views/license_error.php';
    exit;
}

/**
 * https://adanisolarfranchise.in/
 * Version: 1.4.0
 */

if (php_sapi_name() === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require_once __DIR__ . '/vendor/autoload.php';

session_start();

// --- Session Timeout & Remember Me Logic ---
$timeout = 3600;
if (isset($_SESSION['user_id'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset(); session_destroy(); session_start();
    } else {
        $_SESSION['last_activity'] = time();
    }
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_me'])) {
    $repo = new \App\Repositories\JsonNoSqlRepository(__DIR__ . '/storage/db');
    $user = $repo->findOne('users', ['remember_token' => $_COOKIE['remember_me']]);
    if ($user) {
        $role = $repo->findOne('roles', ['id' => $user['role_id']]);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $role['name'] ?? 'Guest';
        $_SESSION['permissions'] = $role['permissions'] ?? [];
        $_SESSION['last_activity'] = time();
    }
}

// Client Portal Session
if (isset($_SESSION['client_id'])) {
    if (time() - $_SESSION['client_last_activity'] > $timeout) {
        unset($_SESSION['client_id'], $_SESSION['client_name'], $_SESSION['client_email'], $_SESSION['client_last_activity']);
    } else {
        $_SESSION['client_last_activity'] = time();
    }
}

if (!isset($_SESSION['client_id']) && isset($_COOKIE['client_remember_me'])) {
    $repo = new \App\Repositories\JsonNoSqlRepository(__DIR__ . '/storage/db');
    $client = $repo->findOne('clients', ['remember_token' => $_COOKIE['client_remember_me']]);
    if ($client) {
        $_SESSION['client_id'] = $client['id'];
        $_SESSION['client_name'] = $client['client_name'];
        $_SESSION['client_email'] = $client['email'];
        $_SESSION['client_last_activity'] = time();
    }
}
// ------------------------------------------

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('BASE_URL', $basePath);

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = substr($requestUri, strlen($basePath));
if (empty($uri)) $uri = '/';

$method = $_SERVER['REQUEST_METHOD'];

// Helper for redirects and links
function url($path) {
    return BASE_URL . $path;
}

// Public Routes
if ($uri === '/' && ($method === 'GET' || $method === 'HEAD')) {
    if (isset($_SESSION['user_id'])) { header('Location: ' . url('/dashboard')); }
    else { 
        // If the landing page file does not exist, send visitors to the login page instead of a broken page
        if (!file_exists(__DIR__ . '/views/landing.php')) {
            header('Location: ' . url('/admin/login'));
            exit;
        }
        $repo = new \App\Repositories\JsonNoSqlRepository(__DIR__ . '/storage/db');
        $settings = $repo->findOne('organization', ['id' => 'org_settings']);
        require_once __DIR__ . '/views/landing.php'; 
    }
    exit;
}

// Client Portal Login
if ($uri === '/login' && ($method === 'GET' || $method === 'HEAD')) {
    (new \App\Controllers\ClientAuthController())->showLogin();
    exit;
} elseif ($uri === '/login' && $method === 'POST') {
    (new \App\Controllers\ClientAuthController())->login();
    exit;
}

// Admin/Staff Login (/admin/login)
if ($uri === '/admin/login' && ($method === 'GET' || $method === 'HEAD')) {
    (new \App\Controllers\AuthController())->showLogin();
    exit;
} elseif ($uri === '/admin/login' && $method === 'POST') {
    (new \App\Controllers\AuthController())->login();
    exit;
}

// Public API
if ($uri === '/api/enquiry' && ($method === 'POST' || $method === 'OPTIONS')) {
    (new \App\Controllers\ExternalEnquiryController())->store();
    exit;
}

// Client Portal Auth Routes
if (strpos($uri, '/portal') === 0) {
    if ($uri === '/portal/logout') { (new \App\Controllers\ClientAuthController())->logout(); exit; }
    $portal = new \App\Controllers\ClientPortalController();
    if ($uri === '/portal/dashboard') { $portal->dashboard(); exit; }
    if ($uri === '/portal/approvals') { $portal->approvals(); exit; }
    if ($uri === '/portal/invoices') { $portal->invoices(); exit; }
    if ($uri === '/portal/payments') { $portal->payments(); exit; }
    if ($uri === '/portal/approval/status' && $method === 'POST') { $portal->updateApprovalStatus(); exit; }
    if ($uri === '/portal/profile' && $method === 'POST') { (new \App\Controllers\PortalProfileController())->update(); exit; }
}

// Staff Auth Middleware
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . url('/admin/login'));
    exit;
}

// Simple Router
if ($uri === '/logout') { (new \App\Controllers\AuthController())->logout(); }
elseif ($uri === '/dashboard') { (new \App\Controllers\DashboardController())->index(); }
elseif ($uri === '/settings' && $method === 'GET') { (new \App\Controllers\OrgSettingsController())->index(); }
elseif ($uri === '/settings' && $method === 'POST') { (new \App\Controllers\OrgSettingsController())->update(); }
elseif ($uri === '/settings/logo' && $method === 'POST') { (new \App\Controllers\OrgSettingsController())->uploadLogo(); }
elseif ($uri === '/settings/asset' && $method === 'POST') { (new \App\Controllers\OrgSettingsController())->uploadAsset(); }
elseif ($uri === '/database/clean' && $method === 'POST') { (new \App\Controllers\DatabaseController())->cleanCollection(); }
elseif ($uri === '/database/clear-logs' && $method === 'POST') { (new \App\Controllers\DatabaseController())->clearLogs(); }
elseif ($uri === '/settings/logs' && $method === 'GET') { (new \App\Controllers\AuditLogController())->index(); }
elseif ($uri === '/settings/email-logs' && $method === 'GET') { (new \App\Controllers\OrgSettingsController())->getEmailLogs(); }
elseif ($uri === '/settings/smtp-test' && $method === 'POST') { (new \App\Controllers\OrgSettingsController())->testSmtp(); }
elseif ($uri === '/settings/sample-data' && $method === 'POST') { (new \App\Controllers\OrgSettingsController())->generateSampleData(); }
elseif ($uri === '/settings/backup' && $method === 'GET') { (new \App\Controllers\BackupController())->download(); }
elseif ($uri === '/search' && $method === 'GET') { (new \App\Controllers\SearchController())->query(); }
elseif ($uri === '/profile' && $method === 'GET') { (new \App\Controllers\ProfileController())->index(); }
elseif ($uri === '/profile' && $method === 'POST') { (new \App\Controllers\ProfileController())->update(); }
elseif ($uri === '/help' && $method === 'GET') { 
    $repo = new \App\Repositories\JsonNoSqlRepository(__DIR__ . '/storage/db');
    $settings = $repo->findOne('organization', ['id' => 'org_settings']);
    require_once __DIR__ . '/views/help.php'; 
}
elseif ($uri === '/notifications' && $method === 'GET') { (new \App\Controllers\NotificationController())->index(); }
elseif ($uri === '/notifications/mark-read' && $method === 'POST') { (new \App\Controllers\NotificationController())->markRead(); }
elseif ($uri === '/clients' && $method === 'GET') { (new \App\Controllers\ClientController())->index(); }
elseif ($uri === '/clients' && $method === 'POST') { (new \App\Controllers\ClientController())->store(); }
elseif ($uri === '/clients/delete' && $method === 'POST') { (new \App\Controllers\ClientController())->delete(); }
elseif ($uri === '/clients/restore' && $method === 'POST') { (new \App\Controllers\ClientController())->restore(); }
elseif ($uri === '/clients/bulk-delete' && $method === 'POST') { (new \App\Controllers\ClientController())->bulkDelete(); }
elseif ($uri === '/clients/convert' && $method === 'POST') { (new \App\Controllers\ClientController())->convert(); }
elseif ($uri === '/clients/validate' && $method === 'POST') { (new \App\Controllers\ClientController())->validate(); }
elseif ($uri === '/clients/upload-photo' && $method === 'POST') { (new \App\Controllers\ClientController())->uploadPhoto(); }
elseif ($uri === '/enquiries' && $method === 'GET') { (new \App\Controllers\EnquiryController())->index(); }
elseif ($uri === '/enquiries/help' && $method === 'GET') { 
    $repo = new \App\Repositories\JsonNoSqlRepository(__DIR__ . '/storage/db');
    $settings = $repo->findOne('organization', ['id' => 'org_settings']);
    require_once __DIR__ . '/views/enquiry_help.php'; 
}
elseif ($uri === '/enquiries' && $method === 'POST') { (new \App\Controllers\EnquiryController())->store(); }
elseif ($uri === '/enquiries/update' && $method === 'POST') { (new \App\Controllers\EnquiryController())->update(); }
elseif ($uri === '/enquiries/validate' && $method === 'POST') { (new \App\Controllers\EnquiryController())->validate(); }
elseif ($uri === '/enquiries/upload-photo' && $method === 'POST') { (new \App\Controllers\EnquiryController())->uploadPhoto(); }
elseif ($uri === '/enquiries/bulk-update' && $method === 'POST') { (new \App\Controllers\EnquiryController())->bulkUpdate(); }
elseif ($uri === '/enquiries/bulk-delete' && $method === 'POST') { (new \App\Controllers\EnquiryController())->bulkDelete(); }
elseif ($uri === '/enquiries/delete' && $method === 'POST') { (new \App\Controllers\EnquiryController())->delete(); }
elseif ($uri === '/enquiries/restore' && $method === 'POST') { (new \App\Controllers\EnquiryController())->restore(); }
elseif ($uri === '/approvals' && $method === 'GET') { (new \App\Controllers\ApprovalController())->index(); }
elseif ($uri === '/approvals' && $method === 'POST') { (new \App\Controllers\ApprovalController())->store(); }
elseif ($uri === '/approvals/send-email' && $method === 'POST') { (new \App\Controllers\ApprovalController())->sendApprovalEmail(); }
elseif ($uri === '/approvals/status' && $method === 'POST') { (new \App\Controllers\ApprovalController())->updateStatus(); }
elseif ($uri === '/approvals/update' && $method === 'POST') { (new \App\Controllers\ApprovalController())->update(); }
elseif ($uri === '/approvals/delete' && $method === 'POST') { (new \App\Controllers\ApprovalController())->delete(); }
elseif ($uri === '/approvals/restore' && $method === 'POST') { (new \App\Controllers\ApprovalController())->restore(); }
elseif ($uri === '/approvals/bulk-update' && $method === 'POST') { (new \App\Controllers\ApprovalController())->bulkUpdate(); }
elseif ($uri === '/approvals/bulk-delete' && $method === 'POST') { (new \App\Controllers\ApprovalController())->bulkDelete(); }
elseif ($uri === '/invoices' && $method === 'GET') { (new \App\Controllers\InvoiceController())->index(); }
elseif ($uri === '/invoices' && $method === 'POST') { (new \App\Controllers\InvoiceController())->store(); }
elseif ($uri === '/invoices/status' && $method === 'POST') { (new \App\Controllers\InvoiceController())->updateStatus(); }
elseif ($uri === '/invoices/update' && $method === 'POST') { (new \App\Controllers\InvoiceController())->update(); }
elseif ($uri === '/invoices/send-email' && $method === 'POST') { (new \App\Controllers\InvoiceController())->sendInvoiceEmail(); }
elseif ($uri === '/invoices/record-payment' && $method === 'POST') { (new \App\Controllers\InvoiceController())->recordPayment(); }
elseif ($uri === '/invoices/delete' && $method === 'POST') { (new \App\Controllers\InvoiceController())->delete(); }
elseif ($uri === '/invoices/restore' && $method === 'POST') { (new \App\Controllers\InvoiceController())->restore(); }
elseif ($uri === '/invoices/bulk-update' && $method === 'POST') { (new \App\Controllers\InvoiceController())->bulkUpdate(); }
elseif ($uri === '/invoices/bulk-delete' && $method === 'POST') { (new \App\Controllers\InvoiceController())->bulkDelete(); }
elseif ($uri === '/invoices/unpaid' && $method === 'GET') { (new \App\Controllers\InvoiceController())->getUnpaidInvoices(); }
elseif ($uri === '/payments' && $method === 'GET') { (new \App\Controllers\PaymentController())->index(); }
elseif ($uri === '/payments/send-email' && $method === 'POST') { (new \App\Controllers\PaymentController())->sendPaymentEmail(); }
elseif ($uri === '/payments/delete' && $method === 'POST') { (new \App\Controllers\PaymentController())->delete(); }
elseif ($uri === '/payments/restore' && $method === 'POST') { (new \App\Controllers\PaymentController())->restore(); }
elseif ($uri === '/payments/bulk-delete' && $method === 'POST') { (new \App\Controllers\PaymentController())->bulkDelete(); }
elseif ($uri === '/staff' && $method === 'GET') { (new \App\Controllers\StaffController())->index(); }
elseif ($uri === '/staff' && $method === 'POST') { (new \App\Controllers\StaffController())->store(); }
elseif ($uri === '/staff/delete' && $method === 'POST') { (new \App\Controllers\StaffController())->delete(); }
elseif ($uri === '/staff/restore' && $method === 'POST') { (new \App\Controllers\StaffController())->restore(); }
elseif ($uri === '/roles' && $method === 'GET') { (new \App\Controllers\RoleController())->index(); }
elseif ($uri === '/roles' && $method === 'POST') { (new \App\Controllers\RoleController())->store(); }
elseif ($uri === '/roles/delete' && $method === 'POST') { (new \App\Controllers\RoleController())->delete(); }
else { http_response_code(404); echo "404 Not Found"; }
