<?php declare(strict_types=1);

namespace Autonomo\API\AutonomoConcierge;

readonly class TenantManager
{
    public static function grabTenantDetails(string $phoneNumber): array
    {
        static $tenants = [];

        if (!empty($tenants)) {
            if (empty($tenants[$phoneNumber])) {
                throw new NotTenantException("Unknown phone number: {$phoneNumber}");
            }

            return $tenants[$phoneNumber];
        }

        if (($fp = fopen(__DIR__ . '/../../storage/tenants.tsv', 'r')) === false) {
            throw new \RuntimeException('Unable to open tenants.tsv');
        }

        try {
            while ($row = fgetcsv($fp, 0, "\t")) {
                $row = array_map('trim', $row);
                if (!isset($row[1])) { continue; }            // malformed

                if ($row[1] === $phoneNumber) {
                    $tenants[$phoneNumber] = [
                        'name'        => $row[0] ?? '',
                        'phoneNumber' => $row[1],
                        'email'       => $row[2] ?? '',
                        'area'        => $row[3] ?? '',
                        'building'    => $row[4] ?? '',
                        'aptNo'       => $row[5] ?? '',
                        'tenantType'  => $row[6] ?? '',
                        'moveInDate'  => $row[7] ?? '',
                    ];

                    return $tenants[$phoneNumber];
                }
            }

            throw new NotTenantException("Unknown phone number: {$phoneNumber}");
        } finally {
            fclose($fp);
        }
    }
}
