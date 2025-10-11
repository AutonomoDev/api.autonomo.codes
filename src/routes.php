<?php declare(strict_types=1);
// ==== ./src/routes.php ====

use Pecee\SimpleRouter\SimpleRouter;
use Autonomo\DigitalPartner\Controllers\VolunteerController;

SimpleRouter::get('/', function () {
    response()->header('Content-Type: text/html');
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
SimpleRouter::get('/api/volunteer-stats', function() {
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
