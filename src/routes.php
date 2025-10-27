<?php declare(strict_types=1);
// ==== ./src/routes.php ====

use Pecee\SimpleRouter\SimpleRouter;
use Autonomo\API\Controllers\VolunteerController;
use Autonomo\API\Controllers\ChartController;
use Autonomo\API\WAHA\Controllers\WhatsAppWebhookController;

error_reporting(E_ALL); // Add for development debugging
ini_set('display_errors', '1'); // Add for development debugging

SimpleRouter::get('/', function () {
    response()->header('Content-Type: text/html');
    // Assumes index.html exists in src/views/
    return file_get_contents(__DIR__ . '/views/index.html');
});

SimpleRouter::get('/auto-whatsapp-api', function () {
    response()->header('Content-Type: text/html');
    // Assumes index.html exists in src/views/
    return file_get_contents(__DIR__ . '/views/auto-whatsapp-api.html');
});

// API endpoint for AI companion volunteer signup
SimpleRouter::post('/ai-companion/signup', function() {
    $controller = new VolunteerController();
    $result = $controller->register();

    response()->header('Content-Type: application/json');
    return json_encode($result);
});

// Get volunteer stats (for internal use)
SimpleRouter::get('/volunteer-stats', function() {
    $controller = new VolunteerController();
    $result = $controller->getStats();

    response()->header('Content-Type: application/json');
    return json_encode($result);
});

// Health check endpoint
SimpleRouter::get('/api/health', function () {
    response()->header('Content-Type: application/json');
    return json_encode([
        'status' => 'healthy',
        'service' => 'Digital Partner Backup',
        'timestamp' => date('c')
    ]);
});

// ===================================================
// Chart Endpoints
// ===================================================

// Generic endpoint to get chart data by ID.
// Handles requests like: GET /charts/slm-proficiency-v0-v13
SimpleRouter::get('/charts/{id}', [ChartController::class, 'getChart']);

SimpleRouter::get('/api/charts/pie', function() { return ''; });

// Specific endpoint for the SLM Usage Distribution Pie Chart.
// URL path is /charts/pie
SimpleRouter::get('/charts/pie', function() {
    $controller = new ChartController();
    return $controller->getChart(ChartController::SLM_USAGE_PIE_ID);
});

SimpleRouter::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'handle']);


// ===================================================
// WAHA Webhook Endpoint
// ===================================================

// Handles incoming WhatsApp messages via webhook.
// Forwards messages to LLM and sends back replies.
// This route is protected by the ApiKeyMiddleware.

// Define a group that applies the ApiKeyMiddleware to all routes within it.
// This is the standard way to apply middleware to specific routes/groups in Pecee\SimpleRouter.
//SimpleRouter::group(['middleware' => new ApiKeyMiddleware($wahaApiKey)], function () {
//    // Define the webhook route within this group.
//    // It will automatically inherit the ApiKeyMiddleware.
//    SimpleRouter::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'handle']);
//
//    // If you add other protected routes in the future, they can also be placed inside this group.
//});
