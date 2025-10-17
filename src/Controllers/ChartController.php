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
 *   - Example: `GET /api/charts/slm-proficiency-v0-v13`
 *   - Example: `GET /api/charts/slm-usage-distribution` (though a dedicated route `/chart/pie` is also provided)
 *
 * - `GET /chart/pie`: A dedicated endpoint specifically for the SLM Usage Distribution pie chart.
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
 *     "chartType": "bar|pie|line|...", // Optional but recommended for frontend rendering hints.
 *     "data": [...], // Array of data points or slices formatted for the specific chart type.
 *     "xAxis": {...}, // Optional: Configuration for the X-axis (typically for bar/line charts).
 *     "yAxis": {...}, // Optional: Configuration for the Y-axis (typically for bar/line charts).
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
    private const SLM_USAGE_PIE_ID = 'slm-usage-distribution';
    private const SLM_USAGE_PIE_TITLE = 'SLM Usage Distribution';
    private const SLM_USAGE_PIE_DESCRIPTION = 'Proportion of usage across different language models';

    /**
     * Retrieves chart data based on the provided chart ID.
     * This method acts as a dispatcher for different chart types. It is intended
     * to be called via routes like `/api/charts/{id}`.
     *
     * @param string $id The unique identifier for the chart (e.g., 'slm-proficiency-v0-v13', 'slm-usage-distribution').
     * @return array The chart data in JSON-compatible array format or an error response.
     */
    public function getChart(string $id): array
    {
        return match ($id) {
            self::SLM_PROFICIENCY_ID => $this->getSlmProficiencyEvolution(),
            self::SLM_USAGE_PIE_ID   => $this->getSlmUsageDistribution(),
            // Add other chart IDs and their corresponding methods here if needed
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
     * Generates data for the SLM Proficiency Evolution chart.
     * This data is typically suitable for bar or line charts, showing performance scores.
     *
     * @return array Chart data.
     */
    private function getSlmProficiencyEvolution(): array
    {
        try {
            $response = [
                'success' => true,
                'chartId' => self::SLM_PROFICIENCY_ID,
                'title' => self::SLM_PROFICIENCY_TITLE,
                'description' => self::SLM_PROFICIENCY_DESCRIPTION,
                'data' => $this->getProficiencyModels(), // Data for the proficiency chart
                'xAxis' => [
                    'label' => 'Score Change',
                    'unit' => 'points',
                    'min' => -15,
                    'max' => 25
                ],
                'yAxis' => [
                    'label' => 'Model',
                    'type' => 'category'
                ],
                'timestamp' => date('c') // ISO 8601 date
            ];

            return $response;

        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('SLM Proficiency Chart data generation error: ' . $e->getMessage());

            // Return a standardized error response
            SimpleRouter::response()->httpCode(500);
            return [
                'success' => false,
                'message' => 'Could not retrieve SLM proficiency chart data at this time.'
            ];
        }
    }

    /**
     * Generates data for the SLM Usage Distribution pie chart.
     * This method is designed to provide data specifically formatted for pie chart visualization.
     * It is intended to be called via a dedicated route like `/chart/pie`.
     *
     * ## SLM Usage Pie Chart Data Structure:
     *
     * ```json
     * {
     *     "success": true,
     *     "chartId": "slm-usage-distribution",
     *     "title": "SLM Usage Distribution",
     *     "description": "Proportion of usage across different language models",
     *     "chartType": "pie",
     *     "data": [
     *         {
     *             "id": "usage-1",        // Unique identifier for the data point/slice.
     *             "label": "qwen3_coder_30b", // Display label for the slice.
     *             "value": 350,           // Numeric value determining the slice size.
     *             "color": "#FF6384",     // Optional: Hex color code for the slice.
     *             "metadata": {           // Optional: Additional data associated with the slice.
     *                 "modelSize": "30b",
     *                 "category": "coder"
     *             }
     *         },
     *         // ... more slices ...
     *     ],
     *     "timestamp": "2023-10-27T10:30:00+00:00"
     * }
     * ```
     *
     * @return array Chart data formatted for a pie chart.
     */
    private function getSlmUsageDistribution(): array
    {
        try {
            $response = [
                'success' => true,
                'chartId' => self::SLM_USAGE_PIE_ID,
                'title' => self::SLM_USAGE_PIE_TITLE,
                'description' => self::SLM_USAGE_PIE_DESCRIPTION,
                'chartType' => 'pie', // Explicitly state chart type for frontend rendering
                'data' => $this->getUsageData(), // Data formatted for a pie chart
                // xAxis/yAxis are typically not needed or used differently for pie charts
                'timestamp' => date('c') // ISO 8601 date
            ];

            return $response;

        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('SLM Usage Pie Chart data generation error: ' . $e->getMessage());

            // Return a standardized error response
            SimpleRouter::response()->httpCode(500);
            return [
                'success' => false,
                'message' => 'Could not retrieve SLM usage pie chart data at this time.'
            ];
        }
    }

    /**
     * Generates the dynamic data for the SLM proficiency chart models with random values.
     * In a real application, this would likely fetch data from a database.
     *
     * @return array Array of model data objects suitable for proficiency charts.
     */
    private function getProficiencyModels(): array
    {
        $minValue = 10.0;
        $maxValue = 25.0;
        $precisionFactor = 10.0; // For one decimal place

        // Generate random values
        $value1 = (int) floor(mt_rand((int) ($minValue * $precisionFactor), $maxValue * $precisionFactor)) / $precisionFactor;
        $value2 = (int) floor(mt_rand((int) ($minValue * $precisionFactor), $maxValue * $precisionFactor)) / $precisionFactor;
        $value3 = (int) floor(mt_rand((int) ($minValue * $precisionFactor), (int) $maxValue * $precisionFactor)) / $precisionFactor;

        return [
            [
                'id' => '1',
                'label' => 'qwen3_coder_30b',
                'value' => $value1, // Dynamic random value
                'color' => '#4A90E2', // Example blue
                'metadata' => ['modelSize' => '30b', 'version' => 'v13', 'category' => 'coder']
            ],
            [
                'id' => '2',
                'label' => 'openai_gpt_oss_20b',
                'value' => $value2, // Dynamic random value
                'color' => '#50E3C2', // Example teal
                'metadata' => ['modelSize' => '20b', 'version' => 'v13', 'category' => 'general']
            ],
            [
                'id' => '3',
                'label' => 'microsoft_phi4_reasoning_14b',
                'value' => $value3, // Dynamic random value
                'color' => '#B8E986', // Example green
                'metadata' => ['modelSize' => '14b', 'version' => 'v13', 'category' => 'reasoning']
            ]
        ];
    }

    /**
     * Generates the dynamic data for the SLM usage pie chart with random values.
     * This data represents usage counts or proportions suitable for pie slices.
     * In a real application, this would likely fetch data from a database.
     *
     * @return array Array of model data objects for pie chart slices.
     */
    private function getUsageData(): array
    {
        // Define the range for random usage counts (e.g., 50 to 500)
        $minCount = 50;
        $maxCount = 500;

        // Generate random counts for different models
        $count1 = mt_rand($minCount, $maxCount);
        $count2 = mt_rand($minCount, $maxCount);
        $count3 = mt_rand($minCount, $maxCount);
        $count4 = mt_rand($minCount, $maxCount); // Added another model for better visualization

        // Define models and their associated data for the pie chart.
        // Using distinct colors is generally recommended for pie charts.
        return [
            [
                'id' => 'usage-1',
                'label' => 'qwen3_coder_30b',
                'value' => $count1, // Represents usage count
                'color' => '#FF6384', // Example red/pink
                'metadata' => ['modelSize' => '30b', 'category' => 'coder']
            ],
            [
                'id' => 'usage-2',
                'label' => 'openai_gpt_oss_20b',
                'value' => $count2, // Represents usage count
                'color' => '#36A2EB', // Example blue
                'metadata' => ['modelSize' => '20b', 'category' => 'general']
            ],
            [
                'id' => 'usage-3',
                'label' => 'microsoft_phi4_reasoning_14b',
                'value' => $count3, // Represents usage count
                'color' => '#FFCE56', // Example yellow
                'metadata' => ['modelSize' => '14b', 'category' => 'reasoning']
            ],
             [
                'id' => 'usage-4',
                'label' => 'google_gemini_pro_12b', // Example additional model
                'value' => $count4, // Represents usage count
                'color' => '#8A2BE2', // Example purple
                'metadata' => ['modelSize' => '12b', 'category' => 'multimodal']
            ]
        ];
    }
}

/*
 * === Routing Configuration for Chart Endpoints ===
 *
 * To ensure the chart endpoints function correctly, especially the new `/chart/pie` endpoint,
 * you need to configure your routes in `src/routes.php` (which is included by `public/index.php`).
 *
 * 1.  **Generic Chart Endpoint (`/api/charts/{id}`)**:
 *     This route is suitable for fetching chart data by its ID.
 *     If you have a generic route like this, it will handle `slm-proficiency-v0-v13` and `slm-usage-distribution`.
 *
 *     ```php
 *     // Example in src/routes.php
 *     use Pecee\SimpleRouter\SimpleRouter;
 *     use Autonomo\DigitalPartner\Controllers\ChartController;
 *
 *     // Handles requests like /api/charts/slm-proficiency-v0-v13 or /api/charts/slm-usage-distribution
 *     SimpleRouter::get('/api/charts/{id}', [ChartController::class, 'getChart']);
 *     ```
 *
 * 2.  **Dedicated Pie Chart Endpoint (`/chart/pie`)**:
 *     As requested, this provides a direct URL for the pie chart. This route will explicitly call
 *     the `getChart` method with the correct internal ID for the pie chart.
 *
 *     ```php
 *     // Example in src/routes.php (add this below other routes)
 *     use Pecee\SimpleRouter\SimpleRouter;
 *     use Autonomo\DigitalPartner\Controllers\ChartController;
 *
 *     SimpleRouter::get('/chart/pie', function() {
 *         $controller = new ChartController();
 *         // Call the getChart method with the specific ID for the pie chart.
 *         // The getChart method itself maps this ID ('slm-usage-distribution') to the correct handler.
 *         return $controller->getChart(ChartController::SLM_USAGE_PIE_ID);
 *     });
 *     ```
 *
 * Make sure these routes are defined in your `src/routes.php` file for the application to function as expected.
 */
