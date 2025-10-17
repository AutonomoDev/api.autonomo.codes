# ==== ./tests/chart-tests.sh ====
# Base URL for the API
BASE_URL="https://api.autonomo.codes"

# --- 1. Test Health Check Endpoint ---
echo "--- Testing Health Check Endpoint ---"
curl -X GET "${BASE_URL}/api/health"
echo ""
echo ""

# --- 2. Test Volunteer Sign Up Endpoint ---
echo "--- Testing Volunteer Sign Up Endpoint (POST) ---"

# Sample JSON payload for volunteer registration
# Using a valid example with required fields and consent=true
VOLUNTEER_DATA_VALID='{
  "fullName": "Test User",
  "email": "test.user@example.com",
  "country": "US",
  "aiPlatforms": "ExampleAI, TestBot",
  "experience": "over-year",
  "motivation": "I want to help improve AI companion technology.",
  "technicalSkills": "None",
  "consent": true,
  "newsletter": false,
  "timestamp": "'$(date --iso-8601=seconds)'"
}'

# Using a sample with required fields but consent=false to test error
VOLUNTEER_DATA_INVALID_CONSENT='{
  "fullName": "Test User Consent Fail",
  "email": "test.consent@example.com",
  "country": "GB",
  "aiPlatforms": "ExampleAI",
  "experience": "1-6-months",
  "motivation": "Testing consent flow.",
  "consent": false,
  "timestamp": "'$(date --iso-8601=seconds)'"
}'

echo "Sending valid registration data..."
curl -X POST "${BASE_URL}/ai-companion/signup" \
     -H "Content-Type: application/json" \
     -d "$VOLUNTEER_DATA_VALID"
echo ""
echo ""

echo "Sending invalid registration data (consent=false)..."
curl -X POST "${BASE_URL}/ai-companion/signup" \
     -H "Content-Type: application/json" \
     -d "$VOLUNTEER_DATA_INVALID_CONSENT"
echo ""
echo ""

# --- 3. Test Volunteer Statistics Endpoint ---
echo "--- Testing Volunteer Statistics Endpoint ---"
curl -X GET "${BASE_URL}/api/volunteer-stats"
echo ""
echo ""

# --- 4. Test SLM Proficiency Chart Data Endpoint ---
# This endpoint returns data suitable for bar/line charts.
echo "--- Testing SLM Proficiency Chart Data Endpoint ---"
# Expected response contains: 'success', 'chartId', 'title', 'description', 'data', 'xAxis', 'yAxis', 'timestamp'.
curl -X GET "${BASE_URL}/api/charts/slm-proficiency"
echo ""
echo ""

# --- 5. Test SLM Usage Pie Chart Data Endpoint ---
# This endpoint returns data specifically formatted for a pie chart.
echo "--- Testing SLM Usage Pie Chart Data Endpoint ---"
# Accessing the pie chart via the new dedicated '/api/charts/pie' route.
#
# Expected response structure:
# {
#     "success": true,
#     "chartId": "slm-usage-distribution",
#     "title": "SLM Usage Distribution",
#     "description": "Proportion of usage across different language models",
#     "chartType": "pie",
#     "data": [...], // Array of slice objects
#     "timestamp": "ISO8601_timestamp"
# }
curl -X GET "${BASE_URL}/api/charts/pie" # <-- Changed URL path here
echo ""
echo ""

echo "--- All Tests Completed ---"
