<?php
// ==== ./src/Controllers/ChartController.php ====
namespace Autonomo\DigitalPartner\Controllers;

use Pecee\SimpleRouter\SimpleRouter;

/**
 * ChartController handles requests for various chart data endpoints.
 * It provides JSON responses containing data structured for frontend visualization.
 *
 * ## API Endpoints Provided:
 *
 * - `GET /api/charts/{id}`: Retrieves data for a specific chart identified by `{id}`.
 *   - Example: `GET /api/charts/slm-proficiency`
 *   - Example: `GET /api/charts/pie`
 *
 * ## General Response Structure:
 *
 * ### Success Response:
 * ```json
 * {
 *     "success": true,
 *     "chartId": "unique-chart-identifier",
 *     "title": "Chart Title",
 *     "description": "A brief explanation of the chart.",
 *     "data": [...], // Array of data points or slices formatted for the specific chart type.
 *     // ... other chart-specific properties
 *     "timestamp": "2023-10-27T10:30:00+00:00" // ISO 8601 formatted timestamp of data generation.
 * }
 * ```
 *
 * ### Error Response:
 * ```json
 * {
 *     "success": false,
 *     "message": "A human-readable error description."
 * }
 * ```
 */
class ChartController
{
    // Constants for the SLM Proficiency Evolution chart
    private const SLM_PROFICIENCY_ID = 'slm-proficiency-v0-v13';
    private const SLM_PROFICIENCY_TITLE = 'SLM Proficiency Evolution (v0 → v13)';
    private const SLM_PROFICIENCY_DESCRIPTION = 'Performance comparison across different language models';

    // Constants for the new SLM Usage Distribution chart (Pie Chart)
    public const SLM_USAGE_PIE_ID = 'slm-usage-distribution';
    private const SLM_USAGE_PIE_TITLE = 'SLM Usage Distribution';
    private const SLM_USAGE_PIE_DESCRIPTION = 'Proportion of usage across different language models';

    /**
     * Public endpoint handler for chart requests.
     * This method fetches the appropriate chart data and returns it as a JSON string.
     * It handles the JSON encoding and header setting to prevent conversion errors.
     *
     * @param string $id The unique identifier for the chart.
     * @return string The chart data as a JSON response.
     */
    public function getChart(string $id): string
    {
        // Set the content type header to indicate a JSON response.
        SimpleRouter::response()->header('Content-Type: application/json');

        // Get the chart data as a PHP array.
        $data = $this->getChartData($id);

        // Encode the array into a JSON string and return it.
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Retrieves chart data based on the provided chart ID.
     * This method acts as a dispatcher for different chart types, returning a PHP array.
     *
     * @param string $id The unique identifier for the chart (e.g., 'slm-proficiency', 'pie').
     * @return array The chart data in a PHP array format.
     */
    private function getChartData(string $id): array
    {
        return match ($id) {
            self::SLM_PROFICIENCY_ID, 'slm-proficiency' => $this->getSlmProficiencyEvolution(),
            self::SLM_USAGE_PIE_ID   => $this->getSlmUsageDistribution(),
            'pie'                    => $this->getMarketShareDistribution(),
            default => $this->handleChartNotFound(),
        };
    }

    /**
     * Handles the case where a requested chart ID is not found.
     * Sets a 404 HTTP status code and returns an error message.
     *
     * @return array Error response.
     */
    private function handleChartNotFound(): array
    {
        SimpleRouter::response()->httpCode(404);
        return [
            'success' => false,
            'message' => 'Chart not found.',
        ];
    }

    /**
     * Randomizes a numeric value by a given percentage.
     *
     * This helper function takes a number and applies a random variance to it,
     * returning a new number within the range of [value * (1 - percentage), value * (1 + percentage)].
     * It intelligently handles integers and floats, rounding the result appropriately.
     *
     * @param int|float $value The original numeric value.
     * @param float $percentage The percentage to vary by (e.g., 0.33 for +/- 33%).
     * @param ?int $precision The number of decimal places for the result. If null, integers are returned as integers, and floats are not rounded.
     * @return int|float The randomized value.
     */
    private function randomizeValue(int|float $value, float $percentage = 0.33, ?int $precision = null): int|float
    {
        if ($value == 0) {
            return 0;
        }
        // Calculate a random factor between (1 - $percentage) and (1 + $percentage).
        // mt_rand() / mt_getrandmax() generates a float between 0 and 1.
        $randomFactor = 1 + ((mt_rand() / mt_getrandmax()) * (2 * $percentage) - $percentage);

        $newValue = $value * $randomFactor;

        // Handle rounding based on precision and original type
        if (is_int($precision)) {
            return round($newValue, $precision);
        }

        // If no precision is set, return int for original int, and raw float otherwise.
        return is_int($value) ? (int) round($newValue) : $newValue;
    }

    /**
     * Generates data for the SLM Proficiency Evolution chart.
     *
     * @return array Chart data.
     */
    private function getSlmProficiencyEvolution(): array
    {
        try {
            return [
                'success' => true,
                'chartId' => self::SLM_PROFICIENCY_ID,
                'title' => self::SLM_PROFICIENCY_TITLE,
                'description' => self::SLM_PROFICIENCY_DESCRIPTION,
                'data' => $this->getProficiencyModels(),
                'xAxis' => [
                    'label' => 'Score Change', 'unit' => 'points', 'min' => -15, 'max' => 25
                ],
                'yAxis' => [
                    'label' => 'Model', 'type' => 'category'
                ],
                'timestamp' => date('c')
            ];
        } catch (\Exception $e) {
            SimpleRouter::response()->httpCode(500);
            return ['success' => false, 'message' => 'Could not retrieve SLM proficiency chart data at this time.'];
        }
    }

    /**
     * Generates data for the Market Share Distribution chart using a raw JSON heredoc.
     * This method now decodes the base data, randomizes its numeric values,
     * and recalculates totals and percentages to maintain data consistency.
     *
     * @return array Chart data.
     */
    private function getMarketShareDistribution(): array
    {
        try {
            // Use a heredoc to store the raw base JSON string.
            $rawJson = <<<JSON
{
  "success": true,
  "chartId": "market-share-2025",
  "title": "Market Share Distribution 2025",
  "description": "Product market share breakdown by category",
  "totalValue": 1000000,
  "currency": "USD",
  "data": [
    { "id": "1", "label": "Product A", "value": 350000, "percentage": 35, "color": "#2563EB", "details": { "revenue": 350000, "growth": 12.5, "customers": 1500, "region": "North America", "trend": "increasing" }},
    { "id": "2", "label": "Product B", "value": 250000, "percentage": 25, "color": "#F97316", "details": { "revenue": 250000, "growth": 8.2, "customers": 1200, "region": "Europe", "trend": "increasing" }},
    { "id": "3", "label": "Product C", "value": 200000, "percentage": 20, "color": "#10B981", "details": { "revenue": 200000, "growth": 15.1, "customers": 800, "region": "Asia", "trend": "strong-increasing" }},
    { "id": "4", "label": "Others", "value": 200000, "percentage": 20, "color": "#6B7280", "details": { "revenue": 200000, "growth": 5.0, "customers": 700, "region": "RoW", "trend": "stable" }}
  ],
  "metadata": { "totalCustomers": 4200, "averageGrowth": 10.45, "lastUpdated": "2025-10-16T12:00:00+00:00" },
  "timestamp": "2025-10-16T12:00:00+00:00"
}
JSON;

            // Decode the JSON string into an associative PHP array.
            $chartData = json_decode($rawJson, true);

            // 1. Randomize the "source of truth" values for each data slice.
            foreach ($chartData['data'] as &$slice) {
                // Randomize revenue, which is the base for value and percentage.
                $slice['details']['revenue'] = $this->randomizeValue($slice['details']['revenue']);
                $slice['value'] = $slice['details']['revenue']; // Sync value with new revenue.
                // Randomize other independent metrics.
                $slice['details']['growth'] = $this->randomizeValue($slice['details']['growth'], 0.33, 1);
                $slice['details']['customers'] = $this->randomizeValue($slice['details']['customers']);
            }
            unset($slice); // Unset reference to prevent side-effects.

            // 2. Recalculate totals and derived metrics from the new randomized values.
            $totalRevenue = array_sum(array_column($chartData['data'], 'value'));
            $totalCustomers = array_sum(array_column(array_column($chartData['data'], 'details'), 'customers'));
            $totalWeightedGrowth = array_reduce($chartData['data'], function ($carry, $item) {
                return $carry + ($item['details']['growth'] * $item['value']);
            }, 0);

            // 3. Update top-level and metadata totals.
            $chartData['totalValue'] = $totalRevenue;
            $chartData['metadata']['totalCustomers'] = $totalCustomers;
            $chartData['metadata']['averageGrowth'] = ($totalRevenue > 0) ? round($totalWeightedGrowth / $totalRevenue, 2) : 0;

            // 4. Recalculate percentages for each slice based on the new total.
            foreach ($chartData['data'] as &$slice) {
                $slice['percentage'] = ($totalRevenue > 0) ? round(($slice['value'] / $totalRevenue * 100), 2) : 0;
            }
            unset($slice);

            // 5. Update timestamps to reflect new data generation.
            $now = date('c');
            $chartData['timestamp'] = $now;
            $chartData['metadata']['lastUpdated'] = $now;

            return $chartData;

        } catch (\Exception $e) {
            SimpleRouter::response()->httpCode(500);
            return ['success' => false, 'message' => 'Could not retrieve market share chart data at this time.'];
        }
    }


    /**
     * Generates data for the SLM Usage Distribution pie chart.
     *
     * @return array Chart data formatted for a pie chart.
     */
    private function getSlmUsageDistribution(): array
    {
        try {
            return [
                'success' => true, 'chartId' => self::SLM_USAGE_PIE_ID, 'title' => self::SLM_USAGE_PIE_TITLE,
                'description' => self::SLM_USAGE_PIE_DESCRIPTION, 'chartType' => 'pie',
                'data' => $this->getUsageData(), 'timestamp' => date('c')
            ];
        } catch (\Exception $e) {
            SimpleRouter::response()->httpCode(500);
            return ['success' => false, 'message' => 'Could not retrieve SLM usage pie chart data at this time.'];
        }
    }

    /**
     * Provides base data for the SLM proficiency chart and applies randomization.
     *
     * @return array Array of model data objects.
     */
    private function getProficiencyModels(): array
    {
        // Define the base, static data for the models.
        $models = [
            ['id' => '1', 'label' => 'qwen3_coder_30b', 'value' => 21.5, 'color' => '#4A90E2', 'metadata' => ['modelSize' => '30b', 'version' => 'v13', 'category' => 'coder']],
            ['id' => '2', 'label' => 'openai_gpt_oss_20b', 'value' => 18.2, 'color' => '#50E3C2', 'metadata' => ['modelSize' => '20b', 'version' => 'v13', 'category' => 'general']],
            ['id' => '3', 'label' => 'microsoft_phi4_reasoning_14b', 'value' => 15.8, 'color' => '#B8E986', 'metadata' => ['modelSize' => '14b', 'version' => 'v13', 'category' => 'reasoning']]
        ];

        // Apply randomization to each model's value on every request.
        foreach ($models as &$model) {
            $model['value'] = $this->randomizeValue($model['value'], 0.33, 1);
        }

        return $models;
    }

    /**
     * Provides base data for the SLM usage pie chart and applies randomization.
     *
     * @return array Array of model data objects for pie chart slices.
     */
    private function getUsageData(): array
    {
        // Define the base, static data for the slices.
        $data = [
            ['id' => 'usage-1', 'label' => 'qwen3_coder_30b', 'value' => 420, 'color' => '#FF6384', 'metadata' => ['modelSize' => '30b', 'category' => 'coder']],
            ['id' => 'usage-2', 'label' => 'openai_gpt_oss_20b', 'value' => 310, 'color' => '#36A2EB', 'metadata' => ['modelSize' => '20b', 'category' => 'general']],
            ['id' => 'usage-3', 'label' => 'microsoft_phi4_reasoning_14b', 'value' => 150, 'color' => '#FFCE56', 'metadata' => ['modelSize' => '14b', 'category' => 'reasoning']],
            ['id' => 'usage-4', 'label' => 'google_gemini_pro_12b', 'value' => 120, 'color' => '#8A2BE2', 'metadata' => ['modelSize' => '12b', 'category' => 'multimodal']]
        ];

        // Apply randomization to each slice's value on every request.
        foreach ($data as &$slice) {
            $slice['value'] = $this->randomizeValue($slice['value']);
        }

        return $data;
    }
}

/*
 * === Routing Configuration for Chart Endpoints ===
 *
 * The controller now handles JSON encoding itself, so the route definition is simple.
 * This configuration correctly calls the public `getChart` method, which returns a
 * valid JSON string, preventing any "Array to string conversion" errors.
 *
 *     ```php
 *     // Example in src/routes.php
 *     use Pecee\SimpleRouter\SimpleRouter;
 *     use Autonomo\DigitalPartner\Controllers\ChartController;
 *
 *     // This route now works correctly.
 *     SimpleRouter::get('/api/charts/{id}', [ChartController::class, 'getChart']);
 *     ```
 */
