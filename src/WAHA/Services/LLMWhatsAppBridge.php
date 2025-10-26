<?php
// ==== ./src/WAHA/Services/LLMWhatsAppBridge.php ====
namespace Autonomo\API\WAHA\Services;

use Autonomo\AiSpeaker\LLMSpeaker;
use Autonomo\API\WAHA\Auth\WAHAAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;


class LLMWhatsAppBridge
{
    private LLMSpeaker $ai;

    private function loadDotEnv(): void
    {
        // Only load Dotenv if not already loaded
        if (!class_exists(\Dotenv\Dotenv::class)) {
            // Try to include Composer's autoloader
            $autoload = __DIR__ . '/../../../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new \RuntimeException("Composer autoload not found at: {$autoload}");
            }
        }

        // Verify vlucas/phpdotenv is installed
        if (!class_exists(\Dotenv\Dotenv::class)) {
            throw new \RuntimeException('vlucas/phpdotenv is not installed. Run: composer require vlucas/phpdotenv');
        }

        // Define the .env path
        $envPath = realpath(__DIR__ . '/../../../');
        if ($envPath === false) {
            throw new \RuntimeException('Invalid .env path: ' . __DIR__ . '/../../../');
        }

        // Load environment variables safely
        $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->safeLoad(); // use load() for strict mode
    }

    public function __construct(?LLMSpeaker $ai = null)
    {
        $this->ai = $ai ?? new LLMSpeaker('Anthropic');

        $this->loadDotEnv();
    }

    /**
     * @param array $prompt
     * @return array
     * @throws \Exception
     */
    public function chat(array $prompt, $chatId): array
    {
        file_put_contents('/srv/http/waha/whatsapp-2.log', print_r([$chatId, $prompt], true) . "\n", FILE_APPEND);

        // 1. Get the LLM response text (using your hardcoded data)
//        $llmResponse = unserialize(<<<TXT
//a:8:{s:5:"model";s:25:"claude-3-5-haiku-20241022";s:2:"id";s:28:"msg_01MPj19eusPavhfd5k5cMmVp";s:4:"type";s:7:"message";s:4:"role";s:9:"assistant";s:7:"content";a:1:{i:0;O:8:"stdClass":2:{s:4:"type";s:4:"text";s:4:"text";s:430:"Good day, Mr. Eltawil. I'm sorry to hear about the issue with your toilet in apartment 4502. I'll log a repair request right away. Our maintenance technician, Rajesh Sharma, will come to inspect and repair the toilet. He will be available tomorrow between 10:00 AM and 12:00 PM. His contact number is +971 50 623 8741. Please ensure someone is present in the apartment during this time. Is there anything else I can help you with?";}}s:11:"stop_reason";s:8:"end_turn";s:13:"stop_sequence";N;s:5:"usage";O:8:"stdClass":6:{s:12:"input_tokens";i:115;s:27:"cache_creation_input_tokens";i:0;s:23:"cache_read_input_tokens";i:0;s:14:"cache_creation";O:8:"stdClass":2:{s:25:"ephemeral_5m_input_tokens";i:0;s:25:"ephemeral_1h_input_tokens";i:0;}s:13:"output_tokens";i:116;s:12:"service_tier";s:8:"standard";}}
//TXT
//        );
//        $textToSend = $llmResponse['content'][0]->text;

        // 2. Prepare the request data
        $apiKey = env('WAHA_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('WAHA_API_KEY is not set in your .env file.');
        }

        $url = 'http://localhost:3000/api/sendText';
        $url = env('WAHA_API_URL') . '/api/sendText';

        $phone = substr($chatId, 0, strpos($chatId, '@'));

        $systemPrompt = <<<TXT
You are a friendly concierge in an apartment building.
 There are many tenants and you need to find the right one.

DO NOT give a tenant a recommendation for themselves, ever.

Tenants: 
Maizen Eltawil - Marina Towers, Apt 4502, Dubai Marina. Phone: 971543998492 
Theodore R. Smith - Sulafa Tower, Apt 3602, Dubai Marina. Phone: 18323039477
Arshad Iqbal - Abdullah Meheirah building, Apt 402, Barsha Heights. Phone: 919874022772
Richard Stalwart - Marina Tower, Dubai Harbor, near Barsha Heights. Phone: 923338809541
Robert Smith - Jumeira Gardens Tower, Apt 6105, Al Satwa. Phone: 17138228904 
Alvin Alcasid - Garden Residences, Apt 1503, Deira. Phone: 971543998492
Saiful Sumaon - Jumeira Lakes Tower, Apt 1302, Jumeira Lakes. Phone: 8801713203656

Task: Plumber
Contact: [Business] Thomas Services UAE, at Al Saef - 1st St - Al Thanyah Third - Barsha Heights - Dubai,
phone +971 585-36-0247

Task: AC / air-conditioning Repair
Contact: [Technician] Alvin Alcasid, Dubai Media City, phone: +971 526-53-6551

Task: Electrical Repair
Contact: [Business] Al Sammak Electrical Repair, Al Satwa, +971 555-15-3398 

Task: AC Repair
Contact: [Business] ABDS AC Repairing Services, Al Satwa, +971 524-56-4517


Confirm that the name of the tenant is not the same as the name of the technician.
Confirm that they do not have the same phone number. if not, try again now.
Loosly match the digits, because you have numbers unformatted.

Keep your internal thoughts and confirmations to yourself. 
Respond to the user in whatever language they last spoke in.

FAQ:

Amenities	gym, fitness, workout, exercise	Where is the gym?	The resident gym and fitness center is open 24/7.	Sulafa Tower	Tower A	P2	Floor 42
Amenities	gym, fitness, workout, exercise	Where is the gym?	The resident gym and fitness center is open 24/7.	Palm Jumeirah	Palm Tower A	P2	West Wing
Amenities	pool, swimming, sunbathing	What time does the pool close?	The rooftop infinity pool is open from 6 AM to 10 PM daily.	Palm Jumeirah	Palm Tower A	45 (Rooftop)	N/A
Amenities	kids, children, play area	Is there a play area for kids?	Yes, the children's indoor play area is located on the ground floor.	Palm Jumeirah	Palm Tower A	G	East Wing
Amenities	cinema, movie, theater	How do I book the resident cinema?	The private cinema can be booked through the concierge desk. Bookings are available in 3-hour slots.	Palm Jumeirah	Palm Tower A	P1	N/A
Policies	guest, visitor, friend, family	Can I bring a guest to the pool?	Residents are welcome to bring up to two guests to the pool area. Please ensure they are signed in at the front desk.	All	All	N/A	N/A
Policies	parking, car, valet	Where do my visitors park?	Visitor parking is available on level B1. Please register your guest's vehicle at the security desk for a temporary pass.	All	All	B1	Visitor Zone
Policies	pets, dog, cat, animal	Is the building pet-friendly?	Yes, the building is pet-friendly for small domestic pets. Please ensure they are leashed in all common areas.	All	All	N/A	N/A
Policies	delivery, talabat, careem, amazon	Where do I collect my food deliveries?	All food and package deliveries can be collected from the concierge desk in the main lobby.	All	All	G	Concierge Desk
Services	cleaning, housekeeping	Do you offer apartment cleaning?	Yes, our housekeeping team offers several cleaning packages. I can schedule a service for you or send you the price list.	All	All	N/A	N/A
Services	maintenance, broken, fix	How do I report a maintenance issue?	You can report any maintenance issue directly to me, 24/7. What seems to be the problem?	All	All	N/A	N/A
Contact	manager, management, office	What is the management office email?	The building management office can be reached at manager@palmtower.com. Is there an issue I can assist you with directly?	All	All	N/A	N/A
Locations	washroom, bathroom, toilet	Where is the nearest washroom?	Public washrooms are available for residents and their guests.	Palm Jumeirah	Palm Tower A	G	Main Lobby, West
Locations	washroom, bathroom, toilet	Where is the nearest washroom?	Public washrooms are available for residents and their guests.	Palm Jumeirah	Palm Tower A	P2	Pool Area
Locations	washroom, bathroom, toilet	Where is the nearest washroom?	Public washrooms are available for residents and their guests.	Palm Jumeirah	Palm Tower A	45	Sky Lounge
Locations	trash, garbage, waste	Where is the trash room?	The waste disposal room is located on every floor.	All	All	Every Floor	Service Elevator Area
Locations	emergency, exit, fire escape	Where is the fire exit?	Emergency fire exits are located at the North and South ends of the main hallway on every floor.	All	All	Every Floor	North & South Hallway
TXT;


        $actualPrompt = "Incoming phone number ($phone) --- \n" . implode("\n", $prompt);
        $response = $this->ai->chat([['role' => 'user', 'content' => $actualPrompt]], $systemPrompt);
        file_put_contents('/srv/http/waha/llm-reply-' . time() . '.log', print_r($response, true) . "\n", FILE_APPEND);

        return $response;
    }

}
