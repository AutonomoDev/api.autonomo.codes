<?php declare(strict_types=1);

namespace Autonomo\DigitalPartner\Controllers;

use Pecee\Http\Response;
use Pecee\SimpleRouter\SimpleRouter;

class VolunteerController
{
    private const VOLUNTEERS_DIR = __DIR__ . '/../../storage/volunteers/';
    private const STATS_FILE = self::VOLUNTEERS_DIR . 'stats.json';

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

            // Generate unique filename based on email and timestamp
            $timestamp = date('YmdHis');
            $emailSlug = $this->slugify($input['email']);
            $filename = "{$timestamp}_{$emailSlug}.md";
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

        $markdown = <<<MD
# Volunteer Registration

## Personal Information

- **Name:** {$data['fullName']}
- **Email:** {$data['email']}
- **Country:** {$data['country']}
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

        // Update country stats
        $country = $data['country'] ?? 'Unknown';
        if (!isset($stats['countries'][$country])) {
            $stats['countries'][$country] = 0;
        }
        $stats['countries'][$country]++;

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
                'Country',
                'Platforms',
                'Experience',
                'Newsletter',
                'Technical Skills'
            ]);
        }

        fputcsv($fp, [
            $data['timestamp'] ?? date('c'),
            $filename,
            $data['fullName'],
            $data['email'],
            $data['country'],
            $data['aiPlatforms'],
            $data['experience'] ?? '',
                $data['newsletter'] ?? false ? 'Yes' : 'No',
            $data['technicalSkills'] ?? ''
        ]);

        fclose($fp);
    }

    /**
     * Create a URL-safe slug from email
     */
    private function slugify(string $email): string
    {
        $username = explode('@', $email)[0];
        $slug = preg_replace('/[^a-z0-9-_]/i', '_', $username);
        return strtolower($slug);
    }
}