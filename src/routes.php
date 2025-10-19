<?php declare(strict_types=1);
// ==== ./src/routes.php ====

use Pecee\SimpleRouter\SimpleRouter;
// Import controllers using their full namespaces
use Autonomo\API\Controllers\VolunteerController;
use Autonomo\API\Controllers\ChartController;
// Import the new WhatsApp Webhook Controller
use Autonomo\API\WAHA\Controllers\WhatsAppWebhookController;
// Import the new Middleware
use Autonomo\API\Middleware\ApiKeyMiddleware;

// --- API Key Configuration ---
// Load API key from environment variable.
// It's crucial to set this in your server's environment for security.
// For local development, you might use a .env file with a library like vlucas/phpdotenv.
$wahaApiKey = getenv('WAHA_API_KEY');

// Fallback for development/testing if the environment variable is not set.
// !!! IMPORTANT: Replace 'YOUR_DEV_FALLBACK_API_KEY' with a strong, random key if you use this fallback,
// !!! and NEVER commit sensitive keys directly into your code.
// !!! Ensure the environment variable is set in production.
if (!$wahaApiKey) {
    $wahaApiKey = 'YOUR_DEV_FALLBACK_API_KEY'; // !!! CHANGE THIS FOR PRODUCTION OR SET ENV VAR !!!
    // Log a warning for development environments
    error_log("WAHA_API_KEY environment variable not set. Using fallback key. Set it for production security.");
}
// --- End API Key Configuration ---


SimpleRouter::get('/', function () {
    response()->header('Content-Type: text/html');
    // Assumes index.html exists in src/views/
    return file_get_contents(__DIR__ . '/views/index.html');
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

// Specific endpoint for the SLM Usage Distribution Pie Chart.
// URL path is /charts/pie
SimpleRouter::get('/charts/pie', function() {
    $controller = new ChartController();
    return $controller->getChart(ChartController::SLM_USAGE_PIE_ID);
});


// ===================================================
// WAHA Webhook Endpoint
// ===================================================

// Handles incoming WhatsApp messages via webhook.
// Forwards messages to LLM and sends back replies.
// This route is protected by the ApiKeyMiddleware.

// Define a group that applies the ApiKeyMiddleware to all routes within it.
// This is the standard way to apply middleware to specific routes/groups in Pecee\SimpleRouter.
SimpleRouter::group(['middleware' => new ApiKeyMiddleware($wahaApiKey)], function () {
    // Define the webhook route within this group.
    // It will automatically inherit the ApiKeyMiddleware.
    SimpleRouter::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'handle']);

    // If you add other protected routes in the future, they can also be placed inside this group.
});
