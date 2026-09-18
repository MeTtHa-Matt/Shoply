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
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 12,
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

function voice_prompt(string $transcription, array $listNames, ?array $pendingContext = null): string
{
    $context = $pendingContext ? ' Contexte de la demande précédente à reprendre: ' . json_encode($pendingContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '. La nouvelle phrase est la réponse de l’utilisateur à ce contexte.' : '';
    return 'Shoply: transforme la demande en JSON strict avec les clés action, target_list, item, additions, message_to_user. '
        . 'Actions: add, create_list, clarify, out_of_scope. Listes: '
        . json_encode(array_values($listNames), JSON_UNESCAPED_UNICODE) . '. Demande: '
        . json_encode($transcription, JSON_UNESCAPED_UNICODE) . '. '
        . 'Comprends les variantes: ajoute, rajoute, mets, note, inscris, pense à prendre, n’oublie pas de prendre, il faut acheter, il me faut, je voudrais, je veux acheter. '
        . 'Les transcriptions vocales peuvent être phonétiques, approximatives, séparées ou collées: rapproche la prononciation de la liste disponible sans inventer une nouvelle liste. '
        . 'target_list doit contenir uniquement le nom canonique de la liste, sans « la liste »; il doit reprendre le nom demandé même si la liste manque. '
        . 'add seulement si article et liste sont clairs. '
        . 'Conserve exactement les accents et caractères français des articles dans item (par exemple « blé » doit rester « blé », jamais « ble »), car le texte sera affiché et lu à voix haute. Pour plusieurs ajouts, sépare chaque article, y compris dans une suite comme « element 1 element 2 et element 3 » ou « blé, farine et sucre »; ne conserve jamais « et » ou « virgule » dans un article; additions=[{"target_list":"...","item":"element 1"},{"target_list":"...","item":"element 2"},{"target_list":"...","item":"element 3"}]. Sinon additions=[]. '
        . 'clarify si ambigu ou liste absente; create_list seulement si création explicitement demandée; out_of_scope hors courses. JSON uniquement.'
        . $context;
}

function voice_normalize(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function voice_fold(string $value): string
{
    $value = voice_normalize($value);
    $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;
    return strtr($value, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    ]);
}

function voice_item_key(string $value): string
{
    $value = voice_fold($value);
    return trim(preg_replace('/[^a-z0-9]+/u', '', $value) ?? '');
}

function voice_list_key(string $value): string
{
    $value = voice_fold($value);
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

function voice_split_items(string $itemPart): array
{
    $itemPart = preg_replace('/\s+(?:virgule|point[- ]virgule)\s+/u', ', ', trim($itemPart)) ?? trim($itemPart);
    $parts = preg_split('/\s+(?:et|puis|ainsi que)\s+|\s*[,;]\s*/u', $itemPart, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $items = [];
    foreach ($parts as $part) {
        $hasTrailingConjunction = preg_match('/\s+(?:et|puis|ainsi que)$/iu', trim($part)) === 1;
        $numberedParts = preg_split('/\s+(?=(?:element|article|produit)\s+\d+\b)/iu', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($numberedParts as $numberedPart) {
            $item = trim(preg_replace('/\s+(?:et|puis|ainsi que)$/iu', '', $numberedPart) ?? $numberedPart);
            $simpleWords = preg_split('/\s+/u', $item, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($hasTrailingConjunction && count($simpleWords) > 1 && !preg_match('/\d/u', $item) && !preg_match('/\b(?:de|du|des|la|le|les|et)\b/iu', $item)) {
                foreach ($simpleWords as $simpleWord) {
                    $items[] = $simpleWord;
                }
                continue;
            }
            if ($item !== '') {
                $items[] = $item;
            }
        }
    }
    return $items;
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

function voice_fast_parse_multiple_lists(string $transcription, array $availableLists): ?array
{
    $normalized = voice_normalize($transcription);
    $matches = [];
    foreach ($availableLists as $availableList) {
        $listName = voice_normalize((string) $availableList['name']);
        $offset = 0;
        while (($position = mb_strpos($normalized, $listName, $offset)) !== false) {
            $matches[] = ['position' => $position, 'length' => mb_strlen($listName), 'list' => $availableList];
            $offset = $position + mb_strlen($listName);
        }
    }
    usort($matches, static fn (array $left, array $right): int => $left['position'] <=> $right['position'] ?: $right['length'] <=> $left['length']);
    $selected = [];
    $cursor = 0;
    foreach ($matches as $match) {
        if ($match['position'] < $cursor) {
            continue;
        }
        $segment = trim(mb_substr($normalized, $cursor, $match['position'] - $cursor));
        $segment = trim(preg_replace('/^(?:et|puis|ensuite|et puis)\s+/u', '', $segment) ?? $segment);
        $segment = preg_replace('/^.*\b(?:acheter|achete|ajouter|ajoute|rajoute|mettre|mets|met|note|noter|inscris|inscrire|prends|prendre|veux|voudrais|faudra|faut|besoin|pense|penser|oublie|oublier)\b\s*/u', '', $segment) ?? $segment;
        $segment = trim(preg_replace('/(?:\b(?:la|le|les|ma|mes)?\s*liste)\s*$/u', '', $segment) ?? $segment);
        $segment = trim(preg_replace('/\s+(?:a|à|dans|sur|pour)\s*$/u', '', $segment) ?? $segment);
        if ($segment === '') {
            continue;
        }
        $items = voice_split_items($segment);
        foreach ($items as $item) {
            $selected[] = ['target_list' => $match['list']['name'], 'item' => trim($item)];
        }
        $cursor = $match['position'] + $match['length'];
    }
    if (count($selected) < 2 || count(array_unique(array_column($selected, 'target_list'))) < 2) {
        return null;
    }
    return ['action' => 'add', 'target_list' => $selected[0]['target_list'], 'item' => $selected[0]['item'], 'additions' => $selected, 'message_to_user' => null];
}

function voice_fast_parse(string $transcription, array $availableLists, ?array $defaultList = null): ?array
{
    $normalized = voice_normalize($transcription);
    $multipleLists = voice_fast_parse_multiple_lists($transcription, $availableLists);
    if ($multipleLists !== null) {
        return $multipleLists;
    }
    if ($defaultList && preg_match('/^(?:ajoute|ajouter|rajoute|rajouter|mets|mettre|note|noter|inscris|inscrire|pense(?: à)?|n’oublie pas de|il faut|il me faut|je veux|je voudrais)\b\s+(.+)$/iu', $normalized, $defaultMatch)) {
        $itemPart = trim($defaultMatch[1]);
        $itemPart = preg_replace('/\s+(?:a|à|dans|sur|pour)\s+(?:(?:la|le|ma|mes)\s+)?liste\s*$/iu', '', $itemPart) ?? $itemPart;
        $itemPart = trim(preg_replace('/^(?:des|du|de la|de l\x27|un|une|le|la)\s+/u', '', $itemPart) ?? $itemPart);
        if ($itemPart !== '' && mb_strlen($itemPart) <= 180) {
            $items = voice_split_items($itemPart);
            $additions = array_map(static fn (string $item): array => ['target_list' => $defaultList['name'], 'item' => trim($item)], $items);
            if ($additions) {
                return ['action' => 'add', 'target_list' => $defaultList['name'], 'item' => $additions[0]['item'], 'additions' => $additions, 'message_to_user' => null];
            }
        }
    }
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
        $items = voice_split_items($itemPart);
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
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string) env_value('GEMINI_MODEL', 'gemini-flash-lite-latest')) . ':generateContent?key=' . rawurlencode($geminiKey),
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
                'model' => env_value('GROQ_MODEL', 'llama-3.1-8b-instant'),
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
    $pendingContext = [];
    $pendingContextJson = trim((string) ($_POST['pending_context'] ?? ''));
    if ($pendingContextJson !== '') {
        $decodedPendingContext = json_decode($pendingContextJson, true);
        if (is_array($decodedPendingContext)) {
            $pendingContext = $decodedPendingContext;
            $pendingList = trim((string) ($pendingContext['target_list'] ?? $pendingList));
            $pendingItem = trim((string) ($pendingContext['item'] ?? $pendingItem));
        }
    }
    $pendingAdditions = is_array($pendingContext['additions'] ?? null) ? $pendingContext['additions'] : [];
    if ($confirmCreate) {
        $affirmative = preg_match('/^(oui|ouais|yes|d[’\']accord|d’accord|bien sûr|bien sur|crée(?:-la| la)?|vas[- ]?y|fais[- -]le|confirme)\b/iu', $transcription) === 1;
        if ($affirmative && $pendingList !== '' && ($pendingItem !== '' || $pendingAdditions) && mb_strlen($pendingList) <= 120) {
            if (voice_resolve_list($pendingList, $availableLists) !== null) {
                json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => 'Cette liste existe déjà. Que souhaitez-vous y ajouter ?', 'pending' => ['target_list' => $pendingList, 'item' => $pendingItem, 'additions' => $pendingAdditions]]);
            }
            $itemsToCreate = $pendingAdditions ?: [['item' => $pendingItem]];
            $itemsToCreate = array_values(array_filter($itemsToCreate, static fn (array $addition): bool => is_string($addition['item'] ?? null) && trim($addition['item']) !== '' && mb_strlen(trim($addition['item'])) <= 180));
            if (!$itemsToCreate) {
                json_response(['ok' => true, 'action' => 'clarify', 'message_to_user' => VOICE_NOT_UNDERSTOOD_MESSAGE]);
            }
            $database->beginTransaction();
            $createList = $database->prepare('INSERT INTO shopping_lists (user_id, name) VALUES (?, ?)');
            $createList->execute([$userId, $pendingList]);
            $newListId = (int) $database->lastInsertId();
            $database->prepare('INSERT INTO shopping_list_members (list_id, user_id, role) VALUES (?, ?, \'owner\')')->execute([$newListId, $userId]);
            $insertNewItem = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, ?)');
            $createdLabels = [];
            foreach ($itemsToCreate as $position => $addition) {
                $label = trim((string) $addition['item']);
                $insertNewItem->execute([$newListId, $label, $position + 1]);
                $createdLabels[] = $label;
            }
            $database->commit();
            json_response(['ok' => true, 'action' => 'add', 'list_id' => $newListId, 'items' => array_map(static fn (string $label): array => ['id' => 0, 'label' => $label, 'is_done' => false], $createdLabels), 'message_to_user' => 'Je crée la liste « ' . $pendingList . ' » et j’ajoute ' . implode(', ', $createdLabels) . '.']);
        }
    }
    $currentListId = (int) ($_POST['list_id'] ?? 0);
    $defaultList = null;
    foreach ($availableLists as $availableList) {
        if ((int) $availableList['id'] === $currentListId) {
            $defaultList = $availableList;
            break;
        }
    }
    $command = $pendingContext ? null : voice_fast_parse($transcription, $availableLists, $defaultList);
    if ($command === null) {
        $command = voice_ai_json(voice_prompt($transcription, array_column($availableLists, 'name'), $pendingContext ?: null));
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
    $insert = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, ?)');
    $notification = $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)');
    $responseItems = [];
    $messages = [];
    $duplicateMessages = [];
    $knownItemsByList = [];
    $nextPositions = [];
    $addedLabelsByList = [];
    $listNamesById = [];
    foreach ($targets as $targetData) {
        $target = $targetData['list'];
        $item = $targetData['item'];
        $targetId = (int) $target['id'];
        if (!isset($knownItemsByList[$targetId])) {
            $existingItems = $database->prepare('SELECT label, position FROM shopping_items WHERE list_id = ? FOR UPDATE');
            $existingItems->execute([$targetId]);
            $knownItemsByList[$targetId] = [];
            $nextPositions[$targetId] = 1;
            foreach ($existingItems->fetchAll() as $existingItem) {
                $knownItemsByList[$targetId][voice_item_key((string) $existingItem['label'])] = (string) $existingItem['label'];
                $nextPositions[$targetId] = max($nextPositions[$targetId], (int) $existingItem['position'] + 1);
            }
            $listNamesById[$targetId] = $target['name'];
        }
        $itemKey = voice_item_key($item);
        if ($itemKey !== '' && isset($knownItemsByList[$targetId][$itemKey])) {
            $duplicateMessages[] = '« ' . $item . ' » est déjà dans « ' . $target['name'] . ' »';
            continue;
        }
        $itemPosition = $nextPositions[$targetId]++;
        $insert->execute([$targetId, $item, $itemPosition]);
        $knownItemsByList[$targetId][$itemKey] = $item;
        $responseItems[] = ['list_id' => $targetId, 'label' => $item, 'is_done' => false];
        $messages[] = '« ' . $item . ' » à « ' . $target['name'] . ' »';
        $addedLabelsByList[$targetId][] = $item;
    }
    foreach ($addedLabelsByList as $targetId => $labels) {
        $members = $database->prepare('SELECT user_id FROM shopping_list_members WHERE list_id = ? AND user_id <> ?');
        $members->execute([(int) $targetId, $userId]);
        $message = mb_substr(current_user()['name'] . ' a ajouté ' . implode(', ', $labels) . ' à la liste « ' . $listNamesById[$targetId] . ' ».', 0, 255);
        foreach ($members->fetchAll() as $member) {
            $notification->execute([(int) $member['user_id'], $userId, 'list_item_added', $message]);
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