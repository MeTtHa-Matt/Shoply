<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

const VOICE_OUT_OF_SCOPE_MESSAGE = 'Votre question n’a pas de rapport avec mes fonctions.';
const VOICE_NOT_UNDERSTOOD_MESSAGE = 'Je n’ai pas compris ce que vous souhaitez.';

function voice_error(string $message, int $status = 422): never
{
    json_response(['ok' => false, 'message' => $message], $status);
}

function voice_http_json(string $url, array $headers, array $payload): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('HTTP client unavailable.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'AI provider returned HTTP ' . $status . '.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('AI provider returned invalid JSON.');
    }
    return $decoded;
}

function voice_extract_json(string $text): array
{
    $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text);
    $start = strpos($clean, '{');
    $end = strrpos($clean, '}');
    if ($start === false || $end === false || $end < $start) {
        throw new RuntimeException('AI response is not an object.');
    }
    $data = json_decode(substr($clean, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !in_array($data['action'] ?? null, ['add', 'create_list', 'clarify', 'out_of_scope'], true)) {
        throw new RuntimeException('AI response has an invalid action.');
    }
    foreach (['target_list', 'item', 'message_to_user'] as $field) {
        if (!array_key_exists($field, $data) || ($data[$field] !== null && !is_string($data[$field]))) {
            throw new RuntimeException('AI response has an invalid field.');
        }
    }
    return [
        'action' => $data['action'],
        'target_list' => $data['target_list'] === null ? null : trim($data['target_list']),
        'item' => $data['item'] === null ? null : trim($data['item']),
        'message_to_user' => $data['message_to_user'] === null ? null : trim($data['message_to_user']),
    ];
}

function voice_prompt(string $transcription, array $listNames): string
{
    return 'Tu es l’assistant vocal de Shoply, une application de listes de courses.\n'
        . 'Listes disponibles (JSON): ' . json_encode(array_values($listNames), JSON_UNESCAPED_UNICODE) . "\n"
        . 'Transcription utilisateur: ' . json_encode($transcription, JSON_UNESCAPED_UNICODE) . "\n\n"
        . 'Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte autour, avec exactement ces clés: '
        . 'action (add, create_list, clarify ou out_of_scope), target_list (nom exact ou null), '
        . 'item (article ou null), message_to_user (phrase courte ou null). '
        . 'Utilise add uniquement pour un ajout clair à une liste existante. '
        . 'Utilise create_list pour une demande explicite de nouvelle liste, clarify pour une demande ambiguë ou une liste absente, '
        . 'et out_of_scope pour tout sujet sans rapport avec les courses. N’invente jamais un nom de liste.';
}

function voice_ai_json(string $prompt): array
{
    $system = 'Tu es un parseur strict. Tu ne produis jamais de texte hors JSON.';
    $geminiKey = trim((string) env_value('GEMINI_API_KEY', ''));
    $groqKey = trim((string) env_value('GROQ_API_KEY', env_value('QROQ_API_KEY', '')));
    $lastError = null;

    if ($geminiKey !== '') {
        try {
            $response = voice_http_json(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string) env_value('GEMINI_MODEL', 'gemini-3.5-flash')) . ':generateContent?key=' . rawurlencode($geminiKey),
                [],
                [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
                ]
            );
            return voice_extract_json((string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        } catch (Throwable $error) {
            $lastError = $error;
            error_log('[Shoply] Gemini voice request failed: ' . $error->getMessage());
        }
    }

    if ($groqKey !== '') {
        try {
            $response = voice_http_json('https://api.groq.com/openai/v1/chat/completions', ['Authorization: Bearer ' . $groqKey], [
                'model' => env_value('GROQ_MODEL', 'qwen/qwen3.8-27b'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            ]);
            return voice_extract_json((string) ($response['choices'][0]['message']['content'] ?? ''));
        } catch (Throwable $error) {
            $lastError = $error;
            error_log('[Shoply] Groq voice request failed: ' . $error->getMessage());
        }
    }
    throw new RuntimeException('No AI provider available.', 0, $lastError);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user()) {
    voice_error('Authentification requise.', 401);
}
if (!verify_csrf()) {
    voice_error('Votre session a expiré. Rechargez la page puis recommencez.', 403);
}

$transcription = trim((string) ($_POST['transcription'] ?? ''));
if ($transcription === '' || mb_strlen($transcription) > 1000) {
    voice_error('La transcription est vide ou trop longue.');
}

try {
    $database = db();
    $userId = (int) current_user()['id'];
    $listsQuery = $database->prepare('SELECT l.id, l.name FROM shopping_lists l WHERE l.user_id = ? OR EXISTS (SELECT 1 FROM shopping_list_members m WHERE m.list_id = l.id AND m.user_id = ?) ORDER BY l.name');
    $listsQuery->execute([$userId, $userId]);
    $availableLists = $listsQuery->fetchAll();
    $command = voice_ai_json(voice_prompt($transcription, array_column($availableLists, 'name')));

    if ($command['action'] === 'out_of_scope') {
        json_response(['ok' => true, 'action' => 'out_of_scope', 'message_to_user' => VOICE_OUT_OF_SCOPE_MESSAGE]);
    }
    if ($command['action'] !== 'add') {
        $targetExists = false;
        foreach ($availableLists as $availableList) {
            if ($command['target_list'] !== null && mb_strtolower($availableList['name']) === mb_strtolower($command['target_list'])) {
                $targetExists = true;
                break;
            }
        }
        $message = $command['target_list'] !== null && !$targetExists
            ? 'La liste « ' . $command['target_list'] . ' » n’existe pas, voulez-vous que je la crée ?'
            : VOICE_NOT_UNDERSTOOD_MESSAGE;
        json_response(['ok' => true, 'action' => $command['action'], 'message_to_user' => $message]);
    }

    $target = null;
    foreach ($availableLists as $availableList) {
        if ($command['target_list'] !== null && mb_strtolower($availableList['name']) === mb_strtolower($command['target_list'])) {
            $target = $availableList;
            break;
        }
    }
    if (!$target || $command['item'] === null || $command['item'] === '' || mb_strlen($command['item']) > 180) {
        $message = $command['target_list'] !== null && !$target
            ? 'La liste « ' . $command['target_list'] . ' » n’existe pas, voulez-vous que je la crée ?'
            : VOICE_NOT_UNDERSTOOD_MESSAGE;
        json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => $message]);
    }

    $database->beginTransaction();
    $position = $database->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM shopping_items WHERE list_id = ?');
    $position->execute([(int) $target['id']]);
    $insert = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, ?)');
    $insert->execute([(int) $target['id'], $command['item'], (int) $position->fetchColumn()]);
    $itemId = (int) $database->lastInsertId();
    $message = mb_substr(current_user()['name'] . ' a ajouté ' . $command['item'] . ' à la liste « ' . $target['name'] . ' ».', 0, 255);
    $members = $database->prepare('SELECT user_id FROM shopping_list_members WHERE list_id = ? AND user_id <> ?');
    $members->execute([(int) $target['id'], $userId]);
    $notification = $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)');
    foreach ($members->fetchAll() as $member) {
        $notification->execute([(int) $member['user_id'], $userId, 'list_item_added', $message]);
    }
    $database->commit();
    json_response(['ok' => true, 'action' => 'add', 'list_id' => (int) $target['id'], 'item' => ['id' => $itemId, 'label' => $command['item'], 'is_done' => false], 'message_to_user' => 'J’ajoute « ' . $command['item'] . ' » à « ' . $target['name'] . ' ».']);
} catch (Throwable $error) {
    if (isset($database) && $database instanceof PDO && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('[Shoply] Voice command failed: ' . get_class($error) . ' - ' . $error->getMessage());
    voice_error('La commande vocale est momentanément indisponible.', 503);
}