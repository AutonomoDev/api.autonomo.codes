<?php declare(strict_types=1);
// ==== src/WAHA/Services/ConversationAnalytics.php ====

namespace Autonomo\API\WAHA\Services;

class ConversationAnalytics
{
    private string $analyticsPath;
    
    public function __construct(string $analyticsPath = null)
    {
        $this->analyticsPath = $analyticsPath ?? __DIR__ . '/../../../storage/analytics';
        
        if (!is_dir($this->analyticsPath)) {
            mkdir($this->analyticsPath, 0775, true);
        }
    }
    
    /**
     * Track conversation metrics with response time
     */
    public function trackConversation(
        string $chatId, 
        array $conversation, 
        array $extractedData,
        int $responseTimeMs = null
    ): void {
        $date = date('Y-m-d');
        $metricsFile = $this->analyticsPath . '/metrics_' . $date . '.json';
        
        $metrics = [];
        if (file_exists($metricsFile)) {
            $metrics = json_decode(file_get_contents($metricsFile), true) ?? [];
        }
        
        // Initialize daily metrics if not exists
        if (!isset($metrics[$date])) {
            $metrics[$date] = [
                'total_conversations' => 0,
                'total_messages' => 0,
                'categories' => [],
                'response_times_ms' => [],
                'customer_satisfaction' => [],
                'peak_hours' => array_fill(0, 24, 0),
                'unique_users' => [],
                'severity_distribution' => [],
                'tickets_created' => 0,
                'faq_queries' => 0
            ];
        }
        
        // Update metrics
        $metrics[$date]['total_conversations']++;
        $metrics[$date]['total_messages'] += count($conversation['messages'] ?? []);
        
        // Track category distribution
        $category = $extractedData['category'] ?? 'Uncategorized';
        if (!isset($metrics[$date]['categories'][$category])) {
            $metrics[$date]['categories'][$category] = 0;
        }
        $metrics[$date]['categories'][$category]++;
        
        // Track FAQ queries separately
        if ($category === 'FAQ') {
            $metrics[$date]['faq_queries']++;
        } else {
            $metrics[$date]['tickets_created']++;
        }
        
        // Track severity distribution
        $severity = $extractedData['severity'] ?? 'Normal';
        if (!isset($metrics[$date]['severity_distribution'][$severity])) {
            $metrics[$date]['severity_distribution'][$severity] = 0;
        }
        $metrics[$date]['severity_distribution'][$severity]++;
        
        // Track unique users
        if (!in_array($chatId, $metrics[$date]['unique_users'])) {
            $metrics[$date]['unique_users'][] = $chatId;
        }
        
        // Track peak hours
        $hour = (int)date('H');
        $metrics[$date]['peak_hours'][$hour]++;
        
        // Track actual response time
        if ($responseTimeMs !== null) {
            $metrics[$date]['response_times_ms'][] = $responseTimeMs;
        }
        
        file_put_contents($metricsFile, json_encode($metrics, JSON_PRETTY_PRINT));
    }
    
    /**
     * Generate daily summary report
     */
    public function getDailySummary(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $metricsFile = $this->analyticsPath . '/metrics_' . $date . '.json';
        
        if (!file_exists($metricsFile)) {
            return ['error' => 'No metrics available for ' . $date];
        }
        
        $metrics = json_decode(file_get_contents($metricsFile), true);
        $dayMetrics = $metrics[$date] ?? [];
        
        // Calculate summary statistics
        $summary = [
            'date' => $date,
            'total_conversations' => $dayMetrics['total_conversations'] ?? 0,
            'total_messages' => $dayMetrics['total_messages'] ?? 0,
            'unique_users' => count($dayMetrics['unique_users'] ?? []),
            'tickets_created' => $dayMetrics['tickets_created'] ?? 0,
            'faq_queries' => $dayMetrics['faq_queries'] ?? 0,
            'top_categories' => $this->getTopCategories($dayMetrics['categories'] ?? []),
            'severity_distribution' => $dayMetrics['severity_distribution'] ?? [],
            'average_response_time_ms' => $this->calculateAverage($dayMetrics['response_times_ms'] ?? []),
            'peak_hour' => $this->findPeakHour($dayMetrics['peak_hours'] ?? []),
            'messages_per_conversation' => $dayMetrics['total_conversations'] > 0 
                ? round($dayMetrics['total_messages'] / $dayMetrics['total_conversations'], 2)
                : 0
        ];
        
        return $summary;
    }
    
    /**
     * Get metrics for a date range
     */
    public function getRangeMetrics(string $startDate, string $endDate): array
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = \DateInterval::createFromDateString('1 day');
        $period = new \DatePeriod($start, $interval, $end->modify('+1 day'));
        
        $aggregatedMetrics = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_summaries' => [],
            'totals' => [
                'conversations' => 0,
                'messages' => 0,
                'unique_users' => [],
                'tickets_created' => 0,
                'faq_queries' => 0,
                'categories' => [],
                'severity_distribution' => []
            ]
        ];
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $summary = $this->getDailySummary($dateStr);
            
            if (!isset($summary['error'])) {
                $aggregatedMetrics['daily_summaries'][$dateStr] = $summary;
                
                // Aggregate totals
                $aggregatedMetrics['totals']['conversations'] += $summary['total_conversations'];
                $aggregatedMetrics['totals']['messages'] += $summary['total_messages'];
                $aggregatedMetrics['totals']['tickets_created'] += $summary['tickets_created'];
                $aggregatedMetrics['totals']['faq_queries'] += $summary['faq_queries'];
                
                // Merge categories
                foreach ($summary['top_categories'] as $category => $count) {
                    if (!isset($aggregatedMetrics['totals']['categories'][$category])) {
                        $aggregatedMetrics['totals']['categories'][$category] = 0;
                    }
                    $aggregatedMetrics['totals']['categories'][$category] += $count;
                }
            }
        }
        
        // Calculate unique users across all days
        $aggregatedMetrics['totals']['unique_users'] = count(array_unique($aggregatedMetrics['totals']['unique_users']));
        
        return $aggregatedMetrics;
    }
    
    private function getTopCategories(array $categories, int $limit = 5): array
    {
        arsort($categories);
        return array_slice($categories, 0, $limit, true);
    }
    
    private function calculateAverage(array $values): float
    {
        if (empty($values)) {
            return 0;
        }
        return round(array_sum($values) / count($values), 2);
    }
    
    private function findPeakHour(array $hourCounts): ?int
    {
        if (empty($hourCounts)) {
            return null;
        }
        $maxCount = max($hourCounts);
        return array_search($maxCount, $hourCounts);
    }
}
