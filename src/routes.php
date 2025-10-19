<?php declare(strict_types=1);
// ==== ./src/routes.php ====

use Pecee\SimpleRouter\SimpleRouter;
// Import controllers using their full namespaces
use Autonomo\DigitalPartner\Controllers\VolunteerController;
use Autonomo\DigitalPartner\Controllers\ChartController;

SimpleRouter::get('/', function () {
    response()->header('Content-Type: text/html');
    // Assumes index.html exists in src/views/
    return file_get_contents(__DIR__ . '/views/index.html');
});

// API endpoint for AI companion volunteer signup
// Using fully qualified namespace for the controller class
SimpleRouter::post('/ai-companion/signup', function() {
    $controller = new VolunteerController(); // Default namespace is used here based on index.php
    $result = $controller->register();

    response()->header('Content-Type: application/json');
    return json_encode($result);
});

// Get volunteer stats (for internal use)
// Using fully qualified namespace for the controller class
SimpleRouter::get('/api/volunteer-stats', function() {
    $controller = new VolunteerController(); // Default namespace is used here based on index.php
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
// This route will handle requests like:
// GET /api/charts/slm-proficiency-v0-v13
// GET /api/charts/slm-usage-distribution
SimpleRouter::get('/api/charts/{id}', [ChartController::class, 'getChart']);

// Specific endpoint for the SLM Usage Distribution Pie Chart.
// UPDATED URL PATH to /api/charts/pie as per design requirement.
SimpleRouter::get('/api/charts/pie', function() { // <-- Changed URL path here
    $controller = new ChartController();
    // Call the getChart method with the specific internal ID for the pie chart.
    // The getChart method will then dispatch to the correct internal handler (getSlmUsageDistribution).
    return $controller->getChart(ChartController::SLM_USAGE_PIE_ID);
});

/*
 * Note on Namespaces:
 * The public/index.php file sets `SimpleRouter::setDefaultNamespace('Autonomo\DigitalPartner');`.
 * Since your controllers (VolunteerController, ChartController) are in `Autonomo\DigitalPartner\Controllers`,
 * you can use the controller class names directly in route definitions IF the default namespace was set to 'Autonomo\DigitalPartner\Controllers'.
 *
 * However, to ensure maximum compatibility and clarity, especially if the default namespace might change or if controllers were nested differently,
 * explicitly using the fully qualified namespace like `Autonomo\DigitalPartner\Controllers\ChartController::class` is often safer.
 *
 * For the provided structure in `index.php` and your controllers, using the controller names directly like `VolunteerController` and `ChartController`
 * within the `routes.php` file might require adjusting `SimpleRouter::setDefaultNamespace` in `index.php` to `Autonomo\DigitalPartner\Controllers`.
 *
 * For this example, I've kept the direct class names (e.g., `VolunteerController`, `ChartController`) assuming the `index.php`
 * correctly resolves them (or implicitly using the default namespace). If you encounter namespace issues,
 * uncomment the `use` statements with full namespaces and ensure `SimpleRouter::setDefaultNamespace('Autonomo\DigitalPartner\Controllers');` is set in `index.php`.
 *
 * Example adjustment if needed:
 * In index.php: `SimpleRouter::setDefaultNamespace('Autonomo\DigitalPartner\Controllers');`
 * Then in routes.php:
 * SimpleRouter::post('/ai-companion/signup', [VolunteerController::class, 'register']); // This should now work
 * SimpleRouter::get('/api/charts/{id}', [ChartController::class, 'getChart']); // This should now work
 */
