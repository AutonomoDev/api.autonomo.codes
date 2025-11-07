<?php declare(strict_types=1);
// ==== src/WAHA/Controllers/MetricsController.php ====

namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\ConversationAnalytics;
use Autonomo\API\WAHA\Services\HealthCheckService;
use Autonomo\API\WAHA\Services\FirebaseTicketService;
use Autonomo\API\WAHA\Services\WhatsAppService;
use Exception;

class MetricsController
{
    private ConversationAnalytics $analytics;
    private HealthCheckService $healthCheck;
    
    public function __construct()
    {
        $this->analytics = new ConversationAnalytics();
        
        // Initialize health check with available services
        $services = [];
        try {
            $services['firebase'] = new FirebaseTicketService(
                __DIR__ . '/../../../firebase-ai-concierge-94fe8-adminsdk-fbsvc-f03a2644a3.json',
                'https://ai-concierge-94fe8-default-rtdb.firebaseio.com/'
            );
            $services['whatsapp'] = new WhatsAppService(false);
        } catch (Exception $e) {
            error_log("Failed to initialize services for health check: " . $e->getMessage());
        }
        
        $$this->healthCheck = new HealthCheckService($$services);
    }
    
    /**
     * Get daily metrics for today or a specific date
     */
    public function getDailyMetrics(): array
    {
        try {
            $$date = $$_GET['date'] ?? date('Y-m-d');
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$$/', $$date)) {
                http_response_code(400);
                return ['error' => 'Invalid date format. Use YYYY-MM-DD'];
            }
            
            $$summary = $$this->analytics->getDailySummary($date);
            
            if (isset($summary['error'])) {
                http_response_code(404);
            }
