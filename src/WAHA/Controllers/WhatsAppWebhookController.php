<?php
// ==== ./src/WAHA/Controllers/WhatsAppWebhookController.php ====
namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\LLMWhatsAppBridge;
use Autonomo\API\WAHA\Services\WhatsAppService;
// MODIFIED: We are now using our new, purpose-built LlmService
use Autonomo\API\WAHA\Services\LlmService;
use Exception;

class WhatsAppWebhookController
{
    public function handle(): void
    {
        $logDir = __DIR__ . '/../../../storage';

        $raw = null;
//        $raw = <<<JSON
//{"id":"evt_01k7y9fk63jdvsyfktzr2ypewq","timestamp":1760879430851,"event":"message","session":"default","metadata":{},"me":{"id":"971585364477@c.us","pushName":"Autonomo"},"payload":{"id":"false_971543998492@c.us_3EB062CF135C8FDE457527","timestamp":1760879430,"from":"971543998492@c.us","fromMe":false,"source":"app","to":"971585364477@c.us","body":"Hey the toilet is not flushing please come and figure it out","hasMedia":false,"media":null,"ack":1,"ackName":"SERVER","vCards":[],"_data":{"id":{"fromMe":false,"remote":"971543998492@c.us","id":"3EB062CF135C8FDE457527","_serialized":"false_971543998492@c.us_3EB062CF135C8FDE457527"},"viewed":false,"body":"Hey the toilet is not flushing please come and figure it out","type":"chat","t":1760879430,"clientReceivedTsMillis":1760879429821,"notifyName":"Mazen Eltawil","from":"971543998492@c.us","to":"971585364477@c.us","ack":1,"invis":false,"isNewMsg":true,"star":false,"kicNotified":false,"recvFresh":true,"isFromTemplate":false,"isAdsMedia":false,"pollInvalidated":false,"isSentCagPollCreation":false,"latestEditMsgKey":null,"latestEditSenderTimestampMs":null,"mentionedJidList":[],"groupMentions":[],"isEventCanceled":false,"eventInvalidated":false,"isVcardOverMmsDocument":false,"isForwarded":false,"isQuestion":false,"questionReplyQuotedMessage":null,"questionResponsesCount":0,"readQuestionResponsesCount":0,"labels":[],"hasReaction":false,"viewMode":"VISIBLE","messageSecret":{"0":76,"1":0,"2":175,"3":140,"4":210,"5":241,"6":96,"7":9,"8":66,"9":147,"10":100,"11":243,"12":180,"13":230,"14":76,"15":201,"16":140,"17":229,"18":106,"19":145,"20":87,"21":21,"22":37,"23":32,"24":167,"25":177,"26":99,"27":71,"28":11,"29":36,"30":79,"31":199},"productHeaderImageRejected":false,"lastPlaybackProgress":0,"isDynamicReplyButtonsMsg":false,"isCarouselCard":false,"parentMsgId":null,"callSilenceReason":null,"isVideoCall":false,"callDuration":null,"callCreator":null,"callParticipants":null,"isCallLink":null,"callLinkToken":null,"isMdHistoryMsg":false,"stickerSentTs":0,"isAvatar":false,"lastUpdateFromServerTs":0,"invokedBotWid":null,"bizBotType":null,"botResponseTargetId":null,"botPluginType":null,"botPluginReferenceIndex":null,"botPluginSearchProvider":null,"botPluginSearchUrl":null,"botPluginSearchQuery":null,"botPluginMaybeParent":false,"botReelPluginThumbnailCdnUrl":null,"botMessageDisclaimerText":null,"botMsgBodyType":null,"reportingTokenInfo":{"reportingToken":{"0":104,"1":228,"2":28,"3":17,"4":0,"5":99,"6":77,"7":34,"8":59,"9":216,"10":237,"11":131,"12":5,"13":128,"14":74,"15":153},"version":2,"reportingTag":{"0":1,"1":12,"2":28,"3":163,"4":41,"5":5,"6":121,"7":13,"8":96,"9":89,"10":111,"11":115,"12":150,"13":254,"14":7,"15":219,"16":253,"17":186,"18":34,"19":1}},"requiresDirectConnection":null,"bizContentPlaceholderType":null,"hostedBizEncStateMismatch":false,"senderOrRecipientAccountTypeHosted":false,"placeholderCreatedWhenAccountIsHosted":false,"groupHistoryBundleMessageKey":null,"groupHistoryBundleMetadata":null,"links":[]}},"engine":"WEBJS","environment":{"version":"2025.10.3","engine":"WEBJS","tier":"CORE","browser":"/usr/bin/chromium"}}
//JSON;

        if ($raw === null) {
            $raw = file_get_contents('php://input');
            error_log("WAHA Webhook received: " . $raw);
            file_put_contents($logDir . '/input.log', $raw . "\n", FILE_APPEND);
        }

        $data = json_decode($raw, true);

        $payload = $data['payload'];
        $chatId = $payload['from'] ?? null;
        $payloadId = $payload['id'] ?? null;
        $messageId = $payload['id'] ?? null;
        $message = trim($payload['body'] ?? '');
        $isFromMe = $payload['fromMe'] ?? false;


//         file_put_contents('/tmp/asdf.net', print_r([$payload, $isFromMe, $messageId, $chatId, $text], true));
        // return;
        //file_put_contents('/tmp/msg-' . time(), $message);

        // 2. Ignore invalid, empty, or self-sent messages.
        if (!$chatId || !$messageId || $isFromMe || empty($message)) {
            if ($chatId === null) {
                $message = "No chatId";
            }
            if (!$messageId) {
                $message = "No messageId";
            }
            if ($isFromMe) {
                $message = "is from me";
            }
            if (empty($message)) {
                $message = "No message";
            }
            $message .= "\n" . print_r($payload, true);
            file_put_contents('/tmp/bugged-' . time(), $message);
            http_response_code(200);
            echo json_encode(['status' => 'ignored_invalid_or_self_message']);
            return;
        }

        $wa = new WhatsAppService();

        try {
            // (UX) React to show the message is being processed.
//            $wa->sendText($chatId, 'Hello World!!');
            file_put_contents('/tmp/whatsapp.log', print_r([$chatId, $message], true) . "\n", FILE_APPEND);

            // 3. Get the AI reply from our new LlmService.
            $AI = new LLMWhatsAppBridge();
            $replyResponse = $AI->chat([$message], $chatId);
            file_put_contents('/tmp/whatsapp.log', print_r($replyResponse, true) . "\n", FILE_APPEND);
//            return;
//            print_r($reply);
            $reply = $replyResponse['content'][0]['text'];

            // 4. Send the reply using the WhatsAppService.
            $wa->sendText($chatId, $reply, $messageId);

            response()->header('Content-Type: application/json');

            echo json_encode(['status' => 'ok', 'reply_sent' => true]);

            file_put_contents($logDir . '/log.txt',FILE_APPEND);

        } catch (Exception $e) {
            error_log("Error in WhatsAppWebhookController: " . $e->getMessage());

            // Notify the user of an error.
            $wa->sendText($chatId, "I'm sorry, I encountered a server error. Please try again later.", $messageId);

//            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
