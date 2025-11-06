<?php declare(strict_types=1);
// ==== src/WAHA/Services/PhoneNumberFormatter.php ====

namespace Autonomo\API\WAHA\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * A standalone service to format WhatsApp Chat IDs into human-readable phone numbers.
 *
 * This class relies on the 'giggsey/libphonenumber-for-php' library.
 * You must install it via Composer: `composer require giggsey/libphonenumber-for-php`
 */
class PhoneNumberFormatter
{
    private PhoneNumberUtil $phoneUtil;
    private WhatsAppService $wa;

    public function __construct(?WhatsAppService $whatsAppService = null)
    {
        // PhoneNumberUtil is a singleton for efficiency
        $this->phoneUtil = PhoneNumberUtil::getInstance();
        $this->wa = $whatsAppService ?? new WhatsAppService();
    }

    /**
     * Takes a WhatsApp chat ID and formats it into a standard international format.
     *
     * @param string $chatId The ID from WhatsApp (e.g., '18323039477@c.us').
     * @return string The formatted number (e.g., '+1 832-303-9477') or the original chat ID on failure.
     */
    public function formatFromChatId(string $chatId): string
    {
        // 1. Detect if it is a lid, and if so, convert to a phone number.
        if (str_ends_with($chatId, '@lid')) {
            $numberStr = $this->wa->getPhoneNumberFromLid($chatId);
        } else {
            // 2. Extract the numeric part of the ID
            $parts = explode('@', $chatId);
            if (count($parts) < 1 || !ctype_digit($parts[0])) {
                // This is not a standard user chat ID (e.g., a group ID), return it as is.
                return $chatId;
            }
            $numberStr = $parts[0];
        }

        // 2. Prepend '+' to make it a valid E.164 format string for the parser.
        $e164Number = '+' . $numberStr;

        try {
            // 3. Parse the number string. The second argument (region) is null
            //    because the number is already in a full international format.
            $phoneNumberProto = $this->phoneUtil->parse($e164Number, null);

            // 4. Check if the parsed number is considered valid by the library.
            if (!$this->phoneUtil->isValidNumber($phoneNumberProto)) {
                return $chatId; // Not a valid number, return original.
            }

            // 5. Format the number into the standard international format.
            //    The library handles the correct spacing and hyphenation for each country.
            //    For US: +1 832-303-9477
            //    For UAE: +971 58 536 4477
            return $this->phoneUtil->format($phoneNumberProto, PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException $e) {
            // If the library cannot parse the number, it's malformed.
            // We'll return the original ID as a safe fallback.
            error_log("Could not parse phone number from chat ID '{$chatId}': " . $e->getMessage());
            return $chatId;
        }
    }
}