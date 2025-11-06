<?php declare(strict_types=1);
// ==== ./src/routes.php ====

use Pecee\SimpleRouter\SimpleRouter;
use Autonomo\API\Controllers\VolunteerController;
use Autonomo\API\Controllers\ChartController;
use Autonomo\API\WAHA\Controllers\PromptController;
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

SimpleRouter::get('/concierge', function () {
    response()->header('Content-Type: text/html');
    // Assumes index.html exists in src/views/
    return file_get_contents(__DIR__ . '/views/concierge.html');
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

// // Route for displaying the edit form (GET request, with admin password gate)
// SimpleRouter::get('/concierge/prompt',  [PromptController::class, 'edit']);
// SimpleRouter::post('/concierge/prompt', [PromptController::class, 'handle']);

// Handles both the GET request for the form and the two different POST requests
// (admin login vs. prompt save) for the /concierge/prompt endpoint.
SimpleRouter::all('/concierge/prompt', function () {
    // We need an instance of our controller to call its methods.
    $controller = new PromptController();

    // If it's a POST request, we need to figure out which kind it is.
    if (request()->getMethod() === 'post') {

        // The key distinction: The final "Save Prompt" form sends a 'prompt_content' field,
        // but the initial admin login form does not.
        if (request()->getInputHandler()->exists('prompt_content')) {
            // This is the prompt SAVE action. Route to handle().
            return $controller->handle();
        } else {
            // This is the admin password LOGIN action. Route to edit() to process the password.
            return $controller->edit();
        }

    }

    // If it's not a POST request, it must be a GET request.
    // The default action is to show the login/edit page.
    return $controller->edit();
});
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
