<?php

// --- CONFIGURATION ---
define('BOT_TOKEN', '8520641282:AAHrMUaH6Iankll-eDe44yJxn7gMzTIuK8g');
define('LOG_CHANNEL_ID', '-1003241628417'); // Channel ID
define('DOMAIN', 'https://storebytebot-file.vercel.app'); // Yahan apna domain dalein (last me slash na lagayein)

define('DB_FILE', 'database.json');
define('SESSION_FILE', 'sessions.json');

// --- HELPER FUNCTIONS ---

// Telegram API Call function
function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);
    return json_decode($res, true);
}

// Data Load/Save Functions
function loadData($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?? [];
}

function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function generateId() {
    return substr(str_shuffle(md5(time())), 0, 6);
}

// --- WEB SERVER LOGIC (Direct Link Handler) ---
$requestUri = $_SERVER['REQUEST_URI'];

// 1. Home Route
if ($requestUri == '/' || $requestUri == '/index.php') {
    echo "Bot is running on PHP!";
    exit;
}

// 2. Web Link Handler: /file/:id
if (preg_match('/\/file\/([a-zA-Z0-9]+)/', $requestUri, $matches)) {
    $uniqueId = $matches[1];
    $db = loadData(DB_FILE);

    if (!isset($db[$uniqueId])) {
        http_response_code(404);
        echo 'File not found or link expired.';
        exit;
    }

    $files = $db[$uniqueId];
    // First file ko redirect karenge
    $fileEntry = $files[0];
    
    // File path lane ke liye API call
    $fileInfo = bot('getFile', ['file_id' => $fileEntry['file_id']]);
    
    if (isset($fileInfo['result']['file_path'])) {
        $filePath = $fileInfo['result']['file_path'];
        $downloadLink = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $filePath;
        header("Location: $downloadLink");
        exit;
    } else {
        // Agar file too large hai ya error hai, to bot pe bhejo
        $botUsername = bot('getMe')['result']['username'];
        echo "
            <h2>View in Telegram</h2>
            <p>Browser view is limited or file is too big. Please open in bot:</p>
            <a href='https://t.me/$botUsername?start=$uniqueId'>Open in Bot</a>
        ";
        exit;
    }
}

// --- BOT LOGIC (Webhook Handler) ---

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit; // Agar koi update nahi hai to exit

$message = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

// 1. MESSAGE HANDLER
if ($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $messageId = $message['message_id'];

    // /start Logic
    if (strpos($text, "/start") === 0) {
        $param = trim(substr($text, 6));

        if (empty($param)) {
            // Welcome Screen
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>📂 File Store Bot (PHP)</b>\n\n" .
                          "Files is secure <b>and full privacy</b> with.\n" .
                          "AWS server:\n" .
                          "🔹 Bot Share Link\n" .
                          "🔹 Direct Web Download Link\n\n" .
                          "👇 <b>Upload Now:</b>",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => "📤 Upload New File", 'callback_data' => "start_upload"]
                    ]]
                ])
            ]);
        } else {
            // File Retrieval Logic
            $uniqueId = $param;
            $db = loadData(DB_FILE);

            if (!isset($db[$uniqueId])) {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Link expired or invalid."]);
            } else {
                $files = $db[$uniqueId];
                bot('sendMessage', [
                    'chat_id' => $chatId, 
                    'text' => "📂 <b>Sending " . count($files) . " files...</b>", 
                    'parse_mode' => 'HTML'
                ]);

                foreach ($files as $f) {
                    bot('copyMessage', [
                        'chat_id' => $chatId,
                        'from_chat_id' => LOG_CHANNEL_ID,
                        'message_id' => $f['msg_id']
                    ]);
                }
            }
        }
    }
    // File Handler (Upload Logic)
    else {
        // Check Session
        $sessions = loadData(SESSION_FILE);
        
        // Agar user session me hai aur upload mode on hai
        if (isset($sessions[$chatId])) {
            // Media Detection
            $fileId = null;
            if (isset($message['document'])) $fileId = $message['document']['file_id'];
            elseif (isset($message['video'])) $fileId = $message['video']['file_id'];
            elseif (isset($message['audio'])) $fileId = $message['audio']['file_id'];
            elseif (isset($message['photo'])) $fileId = end($message['photo'])['file_id'];

            if ($fileId) {
                // Step A: File ko Channel me copy karein
                $sentMsg = bot('copyMessage', [
                    'chat_id' => LOG_CHANNEL_ID,
                    'from_chat_id' => $chatId,
                    'message_id' => $messageId
                ]);

                if ($sentMsg && isset($sentMsg['result']['message_id'])) {
                    // Step B: Save info to session
                    $sessions[$chatId]['files'][] = [
                        'msg_id' => $sentMsg['result']['message_id'], // Channel ID
                        'file_id' => $fileId,
                        'type' => 'media'
                    ];
                    saveData(SESSION_FILE, $sessions);

                    // Feedback notification
                    $reply = bot('sendMessage', ['chat_id' => $chatId, 'text' => "✅ Added."]);
                    // PHP me sleep/timeout avoid karte hain web server par, isliye delete nahi kar rahe turant
                } else {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Error: Bot channel me forward nahi kar pa raha. Kya bot admin hai?"]);
                }
            }
        }
    }
}

// 2. CALLBACK QUERY HANDLER
if ($callbackQuery) {
    $chatId = $callbackQuery['message']['chat']['id'];
    $data = $callbackQuery['data'];
    $msgId = $callbackQuery['message']['message_id'];
    $queryId = $callbackQuery['id'];

    if ($data === "start_upload") {
        // Session Init
        $sessions = loadData(SESSION_FILE);
        $sessions[$chatId] = ['files' => []];
        saveData(SESSION_FILE, $sessions);

        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📤 <b>Upload Mode ON</b>\n\n" .
                      "Apni files bhejna shuru karein.\n" .
                      "Jab ho jaye, tab niche <b>Done</b> button dabayein.",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[ ['text' => "✅ Done & Save", 'callback_data' => "save_files"] ]]
            ])
        ]);
    }

    if ($data === "save_files") {
        $sessions = loadData(SESSION_FILE);

        if (!isset($sessions[$chatId]) || empty($sessions[$chatId]['files'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $queryId, 
                'text' => "❌ Pehle kuch files upload karein!", 
                'show_alert' => true
            ]);
        } else {
            // Save to Database
            $uniqueId = generateId();
            $db = loadData(DB_FILE);
            $db[$uniqueId] = $sessions[$chatId]['files'];
            saveData(DB_FILE, $db);

            // Generate Links
            $botUser = bot('getMe');
            $botUsername = $botUser['result']['username'];
            $botLink = "https://t.me/$botUsername?start=$uniqueId";
            $webLink = DOMAIN . "/file/$uniqueId";

            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "✅ <b>Files Stored Successfully!</b>\n\n" .
                          "🤖 <b>Bot Link:</b>\n<code>$botLink</code>\n\n" .
                          "🌐 <b>Web/Direct Link:</b>\n<code>$webLink</code>",
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[ ['text' => "📤 Upload More", 'callback_data' => "start_upload"] ]]
                ])
            ]);

            // Clear Session
            unset($sessions[$chatId]);
            saveData(SESSION_FILE, $sessions);
        }
    }
}
?>
