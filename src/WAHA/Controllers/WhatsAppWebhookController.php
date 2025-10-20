<?php
// ==== ./src/WAHA/Controllers/WhatsAppWebhookController.php ====
namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\LLMWhatsAppBridge;
use Autonomo\API\WAHA\Services\WhatsAppService;
use Exception;

class WhatsAppWebhookController
{
    public function handle(): void
    {
        ini_set('display_errors', true);

        $logDir = __DIR__ . '/../../../storage';

        $raw = null;
        $raw = <<<JSON
{"id":"evt_01k802138n3y6gdhn1wg6wzm2h","timestamp":1760938724630,"event":"message","session":"default","metadata":{},"me":{"id":"971585364477@c.us","pushName":"Autonomo"},"payload":{"id":"false_18323039477@c.us_AC61AAF55926950F8E4AB893D101C180","timestamp":1760938724,"from":"18323039477@c.us","fromMe":false,"source":"app","to":"971585364477@c.us","body":"Help my ac is out","hasMedia":false,"media":null,"ack":1,"ackName":"SERVER","vCards":[],"_data":{"id":{"fromMe":false,"remote":"18323039477@c.us","id":"AC61AAF55926950F8E4AB893D101C180","_serialized":"false_18323039477@c.us_AC61AAF55926950F8E4AB893D101C180"},"viewed":false,"body":"Help my ac is out","type":"chat","t":1760938724,"clientReceivedTsMillis":1760938723585,"notifyName":"Theodore R. Smith","from":"18323039477@c.us","to":"971585364477@c.us","ack":1,"invis":false,"isNewMsg":true,"star":false,"kicNotified":false,"recvFresh":true,"isFromTemplate":false,"isAdsMedia":false,"pollInvalidated":false,"isSentCagPollCreation":false,"latestEditMsgKey":null,"latestEditSenderTimestampMs":null,"mentionedJidList":[],"groupMentions":[],"isEventCanceled":false,"eventInvalidated":false,"isVcardOverMmsDocument":false,"isForwarded":false,"isQuestion":false,"questionReplyQuotedMessage":null,"questionResponsesCount":0,"readQuestionResponsesCount":0,"labels":[],"hasReaction":false,"viewMode":"VISIBLE","messageSecret":{"0":45,"1":53,"2":225,"3":99,"4":36,"5":12,"6":218,"7":143,"8":8,"9":8,"10":255,"11":14,"12":113,"13":12,"14":211,"15":248,"16":97,"17":134,"18":41,"19":146,"20":219,"21":210,"22":228,"23":201,"24":5,"25":84,"26":158,"27":108,"28":10,"29":36,"30":39,"31":114},"productHeaderImageRejected":false,"lastPlaybackProgress":0,"isDynamicReplyButtonsMsg":false,"isCarouselCard":false,"parentMsgId":null,"callSilenceReason":null,"isVideoCall":false,"callDuration":null,"callCreator":null,"callParticipants":null,"isCallLink":null,"callLinkToken":null,"isMdHistoryMsg":false,"stickerSentTs":0,"isAvatar":false,"lastUpdateFromServerTs":0,"invokedBotWid":null,"bizBotType":null,"botResponseTargetId":null,"botPluginType":null,"botPluginReferenceIndex":null,"botPluginSearchProvider":null,"botPluginSearchUrl":null,"botPluginSearchQuery":null,"botPluginMaybeParent":false,"botReelPluginThumbnailCdnUrl":null,"botMessageDisclaimerText":null,"botMsgBodyType":null,"reportingTokenInfo":{"reportingToken":{"0":115,"1":112,"2":186,"3":101,"4":161,"5":132,"6":26,"7":161,"8":81,"9":136,"10":136,"11":216,"12":133,"13":191,"14":205,"15":170},"version":2,"reportingTag":{"0":1,"1":12,"2":174,"3":138,"4":217,"5":47,"6":109,"7":61,"8":174,"9":85,"10":31,"11":31,"12":187,"13":248,"14":96,"15":142,"16":197,"17":192,"18":34,"19":8}},"requiresDirectConnection":null,"bizContentPlaceholderType":null,"hostedBizEncStateMismatch":false,"senderOrRecipientAccountTypeHosted":false,"placeholderCreatedWhenAccountIsHosted":false,"groupHistoryBundleMessageKey":null,"groupHistoryBundleMetadata":null,"links":[]}},"engine":"WEBJS","environment":{"version":"2025.10.4","engine":"WEBJS","tier":"CORE","browser":"/usr/bin/chromium"}}
JSON;

        if (empty($_GET['debug'])) {
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

        file_put_contents('/srv/http/waha/payload-' . time() . '.json', $raw);
        file_put_contents('/srv/http/waha/asdf.net', print_r([
            'payload' => $payload,
            'isFromMe' => $isFromMe,
            'chatId' => $chatId,
            'messageId' => $messageId,
            'message' => $message], true));
        // return;
        file_put_contents('/srv/http/waha/msg-' . time(), $message);

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
            file_put_contents('/srv/http/waha/bugged-' . time(), $message);
            http_response_code(200);
            echo json_encode(['status' => 'ignored_invalid_or_self_message']);
            return;
        }

        $wa = new WhatsAppService();

        try {
            // (UX) React to show the message is being processed.
//            $wa->sendText($chatId, 'Hello World!!');
            file_put_contents('/srv/http/waha/whatsapp.log', print_r([$chatId, $message], true) . "\n", FILE_APPEND);

            // 3. Get the AI reply from our WhatsApp LLM Bridge.
            $AI = new LLMWhatsAppBridge();
            $replyResponse = $AI->chat([$message], $chatId);
            file_put_contents('/srv/http/waha/whatsapp.log', print_r($replyResponse, true) . "\n", FILE_APPEND);
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
