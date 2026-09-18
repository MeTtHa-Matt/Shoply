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
    if (isset($data['additions'])) {
        if (!is_array($data['additions'])) {
            throw new RuntimeException('AI response has invalid additions.');
        }
        foreach ($data['additions'] as $addition) {
            if (!is_array($addition) || !is_string($addition['target_list'] ?? null) || !is_string($addition['item'] ?? null)) {
                throw new RuntimeException('AI response has invalid addition fields.');
            }
        }
    }
    return [
        'action' => $data['action'],
        'target_list' => $data['target_list'] === null ? null : trim($data['target_list']),
        'item' => $data['item'] === null ? null : trim($data['item']),
        'message_to_user' => $data['message_to_user'] === null ? null : trim($data['message_to_user']),
        'additions' => array_values(array_map(static fn (array $addition): array => ['target_list' => trim($addition['target_list']), 'item' => trim($addition['item'])], $data['additions'] ?? [])),
    ];
}

function voice_prompt(string $transcription, array $listNames): string
{
    return 'Shoply: transforme la demande en JSON strict avec les clés action, target_list, item, additions, message_to_user. '
        . 'Actions: add, create_list, clarify, out_of_scope. Listes: '
        . json_encode(array_values($listNames), JSON_UNESCAPED_UNICODE) . '. Demande: '
        . json_encode($transcription, JSON_UNESCAPED_UNICODE) . '. '
        . 'Comprends les variantes: ajoute, rajoute, mets, note, inscris, pense à prendre, n’oublie pas de prendre, il faut acheter, il me faut, je voudrais, je veux acheter. '
        . 'Les transcriptions vocales peuvent être phonétiques, approximatives, séparées ou collées: rapproche la prononciation de la liste disponible sans inventer une nouvelle liste. '
        . 'target_list doit contenir uniquement le nom canonique de la liste, sans « la liste »; il doit reprendre le nom demandé même si la liste manque. '
        . 'add seulement si article et liste sont clairs. '
        . 'Pour plusieurs ajouts, additions=[{"target_list":"...","item":"..."}]. Sinon additions=[]. '
        . 'clarify si ambigu ou liste absente; create_list seulement si création explicitement demandée; out_of_scope hors courses. JSON uniquement.';
}

function voice_normalize(string $value): string
{
    $value = mb_strtolower(trim($value));
    return strtr($value, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe']);
}

function voice_item_key(string $value): string
{
    $value = voice_normalize($value);
    return trim(preg_replace('/[^a-z0-9]+/u', '', $value) ?? '');
}

function voice_list_key(string $value): string
{
    $value = voice_normalize($value);
    $value = preg_replace('/^(?:la|le|les|ma|mes)?\s*liste\s+/u', '', $value) ?? $value;
    return trim($value);
}

function voice_compact_key(string $value): string
{
    return preg_replace('/[^a-z0-9]+/u', '', voice_list_key($value)) ?? '';
}

function voice_list_similarity(string $left, string $right): int
{
    $leftKey = voice_list_key($left);
    $rightKey = voice_list_key($right);
    if ($leftKey === $rightKey) {
        return 100;
    }
    $leftCompact = voice_compact_key($left);
    $rightCompact = voice_compact_key($right);
    if ($leftCompact === $rightCompact) {
        return 98;
    }
    if ($leftCompact === '' || $rightCompact === '' || mb_strlen($leftCompact) < 3 || mb_strlen($rightCompact) < 3) {
        return 0;
    }
    similar_text($leftCompact, $rightCompact, $percent);
    $soundDistance = levenshtein(metaphone($leftCompact), metaphone($rightCompact));
    $maxLength = max(mb_strlen($leftCompact), mb_strlen($rightCompact));
    $allowedDistance = max(1, (int) floor($maxLength / 5));
    if ($soundDistance <= $allowedDistance && $percent >= 45) {
        return max(60, 86 - ($soundDistance * 10));
    }
    return (int) round($percent);
}

function voice_same_list(string $left, string $right): bool
{
    return voice_list_similarity($left, $right) >= 76;
}

function voice_resolve_list(string $spokenName, array $availableLists): ?array
{
    $best = null;
    $bestScore = 0;
    $secondScore = 0;
    foreach ($availableLists as $availableList) {
        $score = voice_list_similarity((string) $availableList['name'], $spokenName);
        if ($score > $bestScore) {
            $secondScore = $bestScore;
            $bestScore = $score;
            $best = $availableList;
        } elseif ($score > $secondScore) {
            $secondScore = $score;
        }
    }
    if ($best === null || $bestScore < 76 || ($bestScore < 100 && $bestScore - $secondScore < 8)) {
        return null;
    }
    return $best;
}

function voice_fast_parse(string $transcription, array $availableLists): ?array
{
    $normalized = voice_normalize($transcription);
    usort($availableLists, static fn (array $left, array $right): int => mb_strlen($right['name']) <=> mb_strlen($left['name']));
    foreach ($availableLists as $availableList) {
        $listName = voice_normalize((string) $availableList['name']);
        $listPosition = mb_strpos($normalized, $listName);
        if ($listPosition === false) {
            continue;
        }
        $beforeList = trim(mb_substr($normalized, 0, $listPosition));
        $beforeList = trim(preg_replace('/(?:\b(?:la|le|les|ma|mes)?\s*liste)\s*$/u', '', $beforeList) ?? $beforeList);
        if (!preg_match('/(?:\ba\b|\bdans\b|\bsur\b|\bpour\b)\s*$/u', $beforeList, $preposition, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $itemPart = trim(mb_substr($beforeList, 0, mb_strlen($beforeList) - mb_strlen($preposition[0][0])));
        $itemPart = preg_replace('/^.*\b(?:acheter|achete|ajouter|ajoute|rajoute|mettre|mets|met|note|noter|inscris|inscrire|prends|prendre|veux|voudrais|faudra|faut|besoin|pense|penser|oublie|oublier)\b\s*/u', '', $itemPart) ?? $itemPart;
        $itemPart = trim(preg_replace('/^(?:des|du|de la|de l\x27|un|une|le|la)\s+/u', '', $itemPart) ?? $itemPart);
        if ($itemPart === '' || mb_strlen($itemPart) > 180) {
            continue;
        }
        $items = preg_split('/\s+(?:et|puis|ainsi que)\s+|\s*,\s*/u', $itemPart, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $additions = array_map(static function (string $item) use ($availableList): array {
            $item = trim(preg_replace('/^(?:des|du|de la|de l\x27|un|une|le|la)\s+/u', '', trim($item)) ?? trim($item));
            return ['target_list' => $availableList['name'], 'item' => $item];
        }, $items);
        return ['action' => 'add', 'target_list' => $availableList['name'], 'item' => $additions[0]['item'] ?? null, 'additions' => $additions, 'message_to_user' => null];
    }
    if (preg_match('/\b(?:a|dans|sur|pour)\s+(.+)$/u', $normalized, $listMatch, PREG_OFFSET_CAPTURE)) {
        $spokenList = trim($listMatch[1][0]);
        foreach ($availableLists as $availableList) {
            if (!voice_same_list($availableList['name'], $spokenList)) {
                continue;
            }
            $beforeList = trim(mb_substr($normalized, 0, (int) $listMatch[0][1]));
            $itemPart = preg_replace('/^.*\b(?:acheter|achete|ajouter|ajoute|rajoute|mettre|mets|met|note|noter|inscris|inscrire|prends|prendre|veux|voudrais|faudra|faut|besoin|pense|penser|oublie|oublier)\b\s*/u', '', $beforeList) ?? $beforeList;
            $itemPart = trim(preg_replace('/^(?:des|du|de la|de l\x27|un|une|le|la)\s+/u', '', $itemPart) ?? $itemPart);
            if ($itemPart === '' || mb_strlen($itemPart) > 180) {
                continue;
            }
            return ['action' => 'add', 'target_list' => $availableList['name'], 'item' => $itemPart, 'additions' => [['target_list' => $availableList['name'], 'item' => $itemPart]], 'message_to_user' => null];
        }
    }
    return null;
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
                    'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 160, 'responseMimeType' => 'application/json'],
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
                'max_tokens' => 160,
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
    $confirmCreate = filter_var($_POST['confirm_create'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $pendingList = trim((string) ($_POST['pending_target_list'] ?? ''));
    $pendingItem = trim((string) ($_POST['pending_item'] ?? ''));
    if ($confirmCreate) {
        $affirmative = preg_match('/^(oui|ouais|yes|d[’\']accord|d’accord|bien sûr|bien sur|crée(?:-la| la)?|vas[- ]?y|fais[- -]le|confirme)\b/iu', $transcription) === 1;
        if (!$affirmative || $pendingList === '' || $pendingItem === '' || mb_strlen($pendingList) > 120 || mb_strlen($pendingItem) > 180) {
            json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => VOICE_NOT_UNDERSTOOD_MESSAGE]);
        }
        foreach ($availableLists as $availableList) {
        if (voice_resolve_list($pendingList, $availableLists) !== null) {
            json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => 'Cette liste existe déjà. Que souhaitez-vous y ajouter ?']);
        }
        }
        $database->beginTransaction();
        $createList = $database->prepare('INSERT INTO shopping_lists (user_id, name) VALUES (?, ?)');
        $createList->execute([$userId, $pendingList]);
        $newListId = (int) $database->lastInsertId();
        $database->prepare('INSERT INTO shopping_list_members (list_id, user_id, role) VALUES (?, ?, \'owner\')')->execute([$newListId, $userId]);
        $insertNewItem = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, 1)');
        $insertNewItem->execute([$newListId, $pendingItem]);
        $database->commit();
        json_response(['ok' => true, 'action' => 'add', 'list_id' => $newListId, 'item' => ['id' => (int) $database->lastInsertId(), 'label' => $pendingItem, 'is_done' => false], 'message_to_user' => 'Je crée la liste « ' . $pendingList . ' » et j’ajoute « ' . $pendingItem . ' ».']);
    }
    $command = voice_fast_parse($transcription, $availableLists);
    if ($command === null) {
        $command = voice_ai_json(voice_prompt($transcription, array_column($availableLists, 'name')));
    }

    if ($command['action'] === 'out_of_scope') {
        json_response(['ok' => true, 'action' => 'out_of_scope', 'message_to_user' => VOICE_OUT_OF_SCOPE_MESSAGE]);
    }
    $additions = $command['additions'] ?: [['target_list' => $command['target_list'], 'item' => $command['item']]];
    if ($command['action'] !== 'add') {
        $targetExists = $command['target_list'] !== null && voice_resolve_list($command['target_list'], $availableLists) !== null;
        $message = $command['target_list'] !== null && !$targetExists
            ? 'La liste « ' . $command['target_list'] . ' » n’existe pas, voulez-vous que je la crée ?'
            : VOICE_NOT_UNDERSTOOD_MESSAGE;
        json_response(['ok' => true, 'action' => $command['action'], 'message_to_user' => $message, 'pending' => ['target_list' => $command['target_list'], 'item' => $command['item'], 'additions' => $additions]]);
    }

    $targets = [];
    foreach ($additions as $addition) {
        $target = null;
        if (($addition['target_list'] ?? null) !== null) {
            $target = voice_resolve_list((string) $addition['target_list'], $availableLists);
        }
        if (!$target || ($addition['item'] ?? '') === '' || mb_strlen((string) $addition['item']) > 180) {
            $missingList = (string) ($addition['target_list'] ?? $command['target_list'] ?? '');
            $message = $missingList !== '' && !$target
                ? 'La liste « ' . $missingList . ' » n’existe pas, voulez-vous que je la crée ?'
                : VOICE_NOT_UNDERSTOOD_MESSAGE;
            json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => $message, 'pending' => ['target_list' => $missingList, 'item' => (string) ($addition['item'] ?? '')]]);
        }
        $targets[] = ['list' => $target, 'item' => trim((string) $addition['item'])];
    }

    $database->beginTransaction();
    $position = $database->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM shopping_items WHERE list_id = ?');
    $insert = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, ?)');
    $notification = $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)');
    $responseItems = [];
    $messages = [];
    $duplicateMessages = [];
    $knownItemsByList = [];
    foreach ($targets as $targetData) {
        $target = $targetData['list'];
        $item = $targetData['item'];
        $targetId = (int) $target['id'];
        if (!isset($knownItemsByList[$targetId])) {
            $existingItems = $database->prepare('SELECT label FROM shopping_items WHERE list_id = ? FOR UPDATE');
            $existingItems->execute([$targetId]);
            $knownItemsByList[$targetId] = [];
            foreach ($existingItems->fetchAll() as $existingItem) {
                $knownItemsByList[$targetId][voice_item_key((string) $existingItem['label'])] = (string) $existingItem['label'];
            }
        }
        $itemKey = voice_item_key($item);
        if ($itemKey !== '' && isset($knownItemsByList[$targetId][$itemKey])) {
            $duplicateMessages[] = '« ' . $item . ' » est déjà dans « ' . $target['name'] . ' »';
            continue;
        }
        $position->execute([(int) $target['id']]);
        $insert->execute([(int) $target['id'], $item, (int) $position->fetchColumn()]);
        $knownItemsByList[$targetId][$itemKey] = $item;
        $responseItems[] = ['list_id' => $targetId, 'label' => $item, 'is_done' => false];
        $messages[] = '« ' . $item . ' » à « ' . $target['name'] . ' »';
        $members = $database->prepare('SELECT user_id FROM shopping_list_members WHERE list_id = ? AND user_id <> ?');
        $members->execute([(int) $target['id'], $userId]);
        foreach ($members->fetchAll() as $member) {
            $notification->execute([(int) $member['user_id'], $userId, 'list_item_added', mb_substr(current_user()['name'] . ' a ajouté ' . $item . ' à la liste « ' . $target['name'] . ' ».', 0, 255)]);
        }
    }
    $database->commit();
    if (!$responseItems) {
        json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => implode(' et ', $duplicateMessages) . '.']);
    }
    $responseMessage = 'J’ajoute ' . implode(' et ', $messages) . '.';
    if ($duplicateMessages) {
        $responseMessage .= ' ' . implode(' et ', $duplicateMessages) . '.';
    }
    json_response(['ok' => true, 'action' => 'add', 'items' => $responseItems, 'message_to_user' => $responseMessage]);
} catch (Throwable $error) {
    if (isset($database) && $database instanceof PDO && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('[Shoply] Voice command failed: ' . get_class($error) . ' - ' . $error->getMessage());
    voice_error('La commande vocale est momentanément indisponible.', 503);
}