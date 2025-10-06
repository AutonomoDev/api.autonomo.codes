<?php declare(strict_types=1);

use Pecee\SimpleRouter\SimpleRouter;
// Remove the unused import from the Workday Planner
// use PHPExperts\WorkdayPlanner\Controllers\WorkdayPlannerController;
use Autonomo\DigitalPartner\Controllers\VolunteerController; // Make sure this import is present

SimpleRouter::get('/', function () {
    // This will now serve the Autonomo AI API documentation
    return response()->view('index'); // Assuming index.html is in src/views/
});

// API endpoint for AI companion volunteer signup
// Changed from /api/volunteer-signup to /ai-companion/signup
SimpleRouter::post('/ai-companion/signup', [VolunteerController::class, 'register']);

// Get volunteer stats (for internal use)
SimpleRouter::get('/api/volunteer-stats', [VolunteerController::class, 'getStats']);

// Health check endpoint
SimpleRouter::get('/api/health', function () {
    return [
        'status' => 'healthy',
        'service' => 'Digital Partner Backup',
        'timestamp' => date('c')
    ];
});
