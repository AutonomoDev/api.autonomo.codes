<?php declare(strict_types=1);

// ==== ./src/Controllers/VolunteerController.php ====
namespace Autonomo\DigitalPartner\Controllers;

use Pecee\Http\Response;
use Pecee\SimpleRouter\SimpleRouter;

class VolunteerController
{
    private const VOLUNTEERS_DIR = __DIR__ . '/../../storage/volunteers/';
    private const STATS_FILE = self::VOLUNTEERS_DIR . 'stats.json';

    private const COUNTRIES = [
        'AU' => 'Australia',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'CN' => 'China',
        'FR' => 'France',
        'DE' => 'Germany',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'MX' => 'Mexico',
        'NL' => 'Netherlands',
        'PH' => 'Philippines',
        'PL' => 'Poland',
        'RU' => 'Russia',
        'SG' => 'Singapore',
        'ES' => 'Spain',
        'TW' => 'Taiwan',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States'
    ];

    /**
     * Register a new volunteer
     */
    public function register(): array
    {
        try {
            // Get JSON input
            $input = json_decode(file_get_contents('php://input'), true);

            // Validate required fields
            $errors = $this->validateInput($input);
            if (!empty($errors)) {
                SimpleRouter::response()->httpCode(400);
                return [
                    'success' => false,
                    'errors' => $errors,
                    'message' => 'Please correct the errors and try again.'
                ];
            }

            // Ensure storage directory exists
            if (!is_dir(self::VOLUNTEERS_DIR)) {
                mkdir(self::VOLUNTEERS_DIR, 0755, true);
            }

            // Generate unique filename: Timestamp-Country-FirstName_LastName.md
            $timestamp = date('YmdHis');
            $countryCode = strtoupper($input['country']);
            $nameSlug = $this->slugifyName($input['fullName']);
            $filename = "{$timestamp}-{$countryCode}-{$nameSlug}.md";
            $filepath = self::VOLUNTEERS_DIR . $filename;

            // Create markdown content
            $markdownContent = $this->createMarkdownContent($input);

            // Save to file
            if (file_put_contents($filepath, $markdownContent) === false) {
                throw new \Exception('Failed to save volunteer registration');
            }

            // Update statistics
            $this->updateStats($input);

            // Log the registration (optional)
            $this->logRegistration($input, $filename);

            // Return success response
            return [
                'success' => true,
                'message' => 'Thank you for volunteering! We\'ll contact you soon.',
                'registrationId' => $timestamp . '_' . substr(md5($input['email']), 0, 8)
            ];

        } catch (\Exception $e) {
            // Log the error
            error_log('Volunteer registration error: ' . $e->getMessage());

            SimpleRouter::response()->httpCode(500);
            return [
                'success' => false,
                'message' => 'An error occurred during registration. Please try again later.'
            ];
        }
    }

    /**
     * Get volunteer statistics (for internal use)
     */
    public function getStats(): array
    {
        if (!file_exists(self::STATS_FILE)) {
            return [
                'totalVolunteers' => 0,
                'countries' => [],
                'platforms' => [],
                'experienceLevels' => [],
                'lastRegistration' => null
            ];
        }

        $stats = json_decode(file_get_contents(self::STATS_FILE), true);
        return $stats ?: [];
    }

    /**
     * Validate input data
     */
    private function validateInput($input): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['fullName', 'email', 'country', 'aiPlatforms', 'motivation', 'consent'];

        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        // Email validation
        if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please provide a valid email address';
        }

        // Country validation (must be valid country code)
        if (!empty($input['country']) && !isset(self::COUNTRIES[strtoupper($input['country'])])) {
            $errors['country'] = 'Please provide a valid country code';
        }

        // Consent must be true
        if (isset($input['consent']) && $input['consent'] !== true) {
            $errors['consent'] = 'You must agree to the terms to participate';
        }

        // Minimum length validations
        if (!empty($input['motivation']) && strlen($input['motivation']) < 20) {
            $errors['motivation'] = 'Please provide more detail about your motivation (minimum 20 characters)';
        }

        return $errors;
    }

    /**
     * Create markdown content for the volunteer registration
     */
    private function createMarkdownContent(array $data): string
    {
        $timestamp = $data['timestamp'] ?? date('c');
        $newsletter = $data['newsletter'] ?? false ? 'Yes' : 'No';
        $experience = $data['experience'] ?? 'Not specified';
        $technicalSkills = $data['technicalSkills'] ?? 'Not specified';

        // Get full country name from code
        $countryCode = strtoupper($data['country']);
        $countryName = self::COUNTRIES[$countryCode] ?? $countryCode;

        $markdown = <<<MD
# Volunteer Registration

## Personal Information

- **Name:** {$data['fullName']}
- **Email:** {$data['email']}
- **Country:** {$countryName}
- **Registration Date:** {$timestamp}

## AI Companion Experience

- **Platforms Used:** {$data['aiPlatforms']}
- **Experience Duration:** {$experience}

## Motivation

{$data['motivation']}

## Technical Background

{$technicalSkills}

## Preferences

- **Newsletter Subscription:** {$newsletter}
- **Consent to Beta Testing:** Yes

---

*This registration was submitted through the Digital Partner Backup volunteer program.*

MD;

        return $markdown;
    }

    /**
     * Update statistics file
     */
    private function updateStats(array $data): void
    {
        $stats = [];

        if (file_exists(self::STATS_FILE)) {
            $stats = json_decode(file_get_contents(self::STATS_FILE), true) ?: [];
        }

        // Initialize stats structure if empty
        if (empty($stats)) {
            $stats = [
                'totalVolunteers' => 0,
                'countries' => [],
                'platforms' => [],
                'experienceLevels' => [],
                'lastRegistration' => null,
                'registrationsByMonth' => []
            ];
        }

        // Update total count
        $stats['totalVolunteers']++;

        // Update country stats (use 2-letter code)
        $countryCode = strtoupper($data['country']);
        if (!isset($stats['countries'][$countryCode])) {
            $stats['countries'][$countryCode] = 0;
        }
        $stats['countries'][$countryCode]++;

        // Update platform stats (split by comma if multiple)
        $platforms = array_map('trim', explode(',', $data['aiPlatforms'] ?? ''));
        foreach ($platforms as $platform) {
            if (!empty($platform)) {
                if (!isset($stats['platforms'][$platform])) {
                    $stats['platforms'][$platform] = 0;
                }
                $stats['platforms'][$platform]++;
            }
        }

        // Update experience level stats
        $experience = $data['experience'] ?? 'not-specified';
        if (!isset($stats['experienceLevels'][$experience])) {
            $stats['experienceLevels'][$experience] = 0;
        }
        $stats['experienceLevels'][$experience]++;

        // Update last registration
        $stats['lastRegistration'] = $data['timestamp'] ?? date('c');

        // Update monthly stats
        $month = date('Y-m');
        if (!isset($stats['registrationsByMonth'][$month])) {
            $stats['registrationsByMonth'][$month] = 0;
        }
        $stats['registrationsByMonth'][$month]++;

        // Save updated stats
        file_put_contents(self::STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
    }

    /**
     * Log registration to a CSV file for easy export
     */
    private function logRegistration(array $data, string $filename): void
    {
        $logFile = self::VOLUNTEERS_DIR . 'registrations.csv';

        // Create CSV header if file doesn't exist
        $writeHeader = !file_exists($logFile);

        $fp = fopen($logFile, 'a');

        if ($writeHeader) {
            fputcsv($fp, [
                'Timestamp',
                'File',
                'Name',
                'Email',
                'Country Code',
                'Country Name',
                'Platforms',
                'Experience',
                'Newsletter',
                'Technical Skills'
            ], escape: "\\");
        }

        $countryCode = strtoupper($data['country']);
        $countryName = self::COUNTRIES[$countryCode] ?? $countryCode;

        fputcsv($fp, [
            $data['timestamp'] ?? date('c'),
            $filename,
            $data['fullName'],
            $data['email'],
            $countryCode,
            $countryName,
            $data['aiPlatforms'],
            $data['experience'] ?? '',
            $data['newsletter'] ?? false ? 'Yes' : 'No',
            $data['technicalSkills'] ?? ''
        ], escape: "\\");

        fclose($fp);
    }

    /**
     * Create a URL-safe slug from full name in format FirstName_LastName
     */
    private function slugifyName(string $fullName): string
    {
        // Remove extra spaces and split name
        $nameParts = preg_split('/\s+/', trim($fullName));

        // Handle different name formats
        if (count($nameParts) === 1) {
            // Single name
            $slug = $this->cleanNamePart($nameParts[0]);
        } elseif (count($nameParts) === 2) {
            // First and Last name
            $slug = $this->cleanNamePart($nameParts[0]) . '_' . $this->cleanNamePart($nameParts[1]);
        } else {
            // Multiple names: use first and last
            $firstName = $this->cleanNamePart($nameParts[0]);
            $lastName = $this->cleanNamePart(end($nameParts));
            $slug = $firstName . '_' . $lastName;
        }

        return $slug;
    }

    /**
     * Clean individual name part for slug
     */
    private function cleanNamePart(string $part): string
    {
        // Remove non-alphanumeric characters and convert to lowercase
        $clean = preg_replace('/[^a-z0-9]/i', '', $part);
        return ucfirst(strtolower($clean));
    }

    /**
     * Get full country name from country code
     */
    private function getCountryName(string $code): string
    {
        $code = strtoupper($code);
        return self::COUNTRIES[$code] ?? $code;
    }
}
