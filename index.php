<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

$page = $_GET['page'] ?? 'login';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = 'Votre session a expire. Rechargez la page puis recommencez.';
    } elseif ($page === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $nameParts = preg_split('/\s+/', $name, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
        remember_old(['name' => $name, 'email' => $email]);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'Indiquez un nom entre 2 et 80 caracteres.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Saisissez une adresse email valide.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Le mot de passe doit contenir au moins 8 caracteres.';
        }
        if ($password !== $passwordConfirmation) {
            $errors['password_confirmation'] = 'Les mots de passe ne correspondent pas.';
        }

        if (!$errors) {
            try {
                $database = db();
                $existing = $database->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $existing->execute([$email]);
                if ($existing->fetch()) {
                    $errors['email'] = 'Cette adresse est deja utilisee. Connectez-vous ou utilisez une autre adresse.';
                } else {
                    $database->beginTransaction();
                    $insert = $database->prepare('INSERT INTO users (first_name, last_name, email, password) VALUES (?, ?, ?, ?)');
                    $insert->execute([$firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT)]);
                    $userId = (int) $database->lastInsertId();
                    $rawToken = bin2hex(random_bytes(32));
                    $token = $database->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
                    $token->execute([$userId, hash('sha256', $rawToken)]);
                    $database->commit();

                    try {
                        send_verification_email($name, $email, app_url('index.php?page=verify&token=' . urlencode($rawToken)));
                    } catch (Throwable $mailError) {
                        $database->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                        throw $mailError;
                    }
                    clear_old();
                    flash('success', 'Votre compte est presque pret. Consultez votre boite mail pour le confirmer.');
                    redirect('index.php?page=login');
                }
            } catch (Throwable $error) {
                error_log('[Shoply] Registration failed: ' . get_class($error) . ' - ' . $error->getMessage());
                if (!$errors) {
                    $errors[] = 'Impossible de creer le compte pour le moment. Verifiez la configuration puis recommencez.';
                }
            }
        }
    } elseif ($page === 'home' && current_user()) {
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) current_user()['id'];
        try {
            $database = db();
            if ($action === 'create_list') {
                $listName = trim((string) ($_POST['list_name'] ?? ''));
                if (mb_strlen($listName) < 2 || mb_strlen($listName) > 120) {
                    flash('error', 'Donnez un nom de 2 a 120 caracteres a votre liste.');
                } else {
                    $statement = $database->prepare('INSERT INTO shopping_lists (user_id, name) VALUES (?, ?)');
                    $statement->execute([$userId, $listName]);
                    $database->prepare('INSERT INTO shopping_list_members (list_id, user_id, role) VALUES (?, ?, \'owner\')')->execute([(int) $database->lastInsertId(), $userId]);
                    flash('success', 'Votre nouvelle liste est prete.');
                }
                redirect('index.php?page=home');
            }

            if ($action === 'send_friend_request') {
                $receiverId = (int) ($_POST['receiver_id'] ?? 0);
                $friendRequestSent = false;
                $receiver = $database->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ? AND id <> ? LIMIT 1');
                $receiver->execute([$receiverId, $userId]);
                $receiverUser = $receiver->fetch();
                if (!$receiverUser) {
                    flash('error', 'Cet utilisateur est introuvable.');
                } else {
                    $existing = $database->prepare('SELECT id, sender_id, receiver_id, status FROM friendship_requests WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY id DESC LIMIT 1');
                    $existing->execute([$userId, $receiverId, $receiverId, $userId]);
                    $request = $existing->fetch();
                    if ($request && $request['status'] === 'accepted') {
                        flash('error', 'Vous êtes déjà amis avec cette personne.');
                    } elseif ($request && $request['status'] === 'pending') {
                        flash('error', 'Une demande est déjà en attente pour cette personne.');
                    } elseif ($request && $request['status'] === 'declined') {
                        $database->beginTransaction();
                        $database->prepare('UPDATE friendship_requests SET sender_id = ?, receiver_id = ?, status = \'pending\', created_at = NOW(), responded_at = NULL WHERE id = ?')->execute([$userId, $receiverId, $request['id']]);
                        $database->prepare('INSERT INTO notifications (user_id, actor_id, type, friendship_request_id, message) VALUES (?, ?, ?, ?, ?)')->execute([$receiverId, $userId, 'friend_request', $request['id'], current_user()['name'] . ' souhaite vous ajouter à ses proches.']);
                        $database->commit();
                        try {
                            send_friend_request_email(trim($receiverUser['first_name'] . ' ' . $receiverUser['last_name']), $receiverUser['email'], current_user()['name'], app_url('index.php?page=home&view=notifications'));
                        } catch (Throwable $mailError) {
                            error_log('[Shoply] Friend request email failed: ' . get_class($mailError) . ' - ' . $mailError->getMessage());
                        }
                        $friendRequestSent = true;
                        flash('success', 'Demande envoyée.');
                    } else {
                        $database->beginTransaction();
                        $database->prepare('INSERT INTO friendship_requests (sender_id, receiver_id) VALUES (?, ?)')->execute([$userId, $receiverId]);
                        $requestId = (int) $database->lastInsertId();
                        $database->prepare('INSERT INTO notifications (user_id, actor_id, type, friendship_request_id, message) VALUES (?, ?, ?, ?, ?)')->execute([$receiverId, $userId, 'friend_request', $requestId, current_user()['name'] . ' souhaite vous ajouter à ses proches.']);
                        $database->commit();
                        try {
                            send_friend_request_email(trim($receiverUser['first_name'] . ' ' . $receiverUser['last_name']), $receiverUser['email'], current_user()['name'], app_url('index.php?page=home&view=notifications'));
                        } catch (Throwable $mailError) {
                            error_log('[Shoply] Friend request email failed: ' . get_class($mailError) . ' - ' . $mailError->getMessage());
                        }
                        $friendRequestSent = true;
                        flash('success', 'Demande envoyée.');
                    }
                }
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                    json_response(['ok' => $friendRequestSent, 'message' => $friendRequestSent ? 'Demande envoyée.' : 'Demande impossible.'], $friendRequestSent ? 200 : 422);
                }
                $returnView = ($_POST['return_view'] ?? '') === 'profile' ? 'profile' : 'shared';
                redirect('index.php?page=home&view=' . $returnView);
            }

            if ($action === 'respond_friend_request') {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $decision = ($_POST['decision'] ?? '') === 'accept' ? 'accepted' : 'declined';
                $requestQuery = $database->prepare('SELECT id, sender_id, receiver_id, status FROM friendship_requests WHERE id = ? AND receiver_id = ? LIMIT 1');
                $requestQuery->execute([$requestId, $userId]);
                $request = $requestQuery->fetch();
                if ($request && $request['status'] === 'pending') {
                    $database->beginTransaction();
                    $database->prepare('UPDATE friendship_requests SET status = ?, responded_at = NOW() WHERE id = ?')->execute([$decision, $requestId]);
                    $database->prepare('UPDATE notifications SET read_at = NOW() WHERE friendship_request_id = ? AND user_id = ?')->execute([$requestId, $userId]);
                    if ($decision === 'accepted') {
                        $database->prepare('INSERT INTO notifications (user_id, actor_id, type, friendship_request_id, message) VALUES (?, ?, ?, ?, ?)')->execute([$request['sender_id'], $userId, 'friend_accepted', $requestId, current_user()['name'] . ' a accepté votre demande.']);
                    }
                    $database->commit();
                    flash('success', $decision === 'accepted' ? 'Vous êtes maintenant amis.' : 'Demande refusée.');
                }
                redirect('index.php?page=home&view=notifications');
            }

            if ($action === 'create_friend_group') {
                $groupName = trim((string) ($_POST['group_name'] ?? ''));
                if (mb_strlen($groupName) < 2 || mb_strlen($groupName) > 80) {
                    json_response(['ok' => false, 'message' => 'Le nom du groupe doit contenir entre 2 et 80 caractères.'], 422);
                }
                $group = $database->prepare('INSERT INTO friend_groups (user_id, name) VALUES (?, ?)');
                $group->execute([$userId, $groupName]);
                json_response(['ok' => true, 'group' => ['id' => (int) $database->lastInsertId(), 'name' => $groupName]]);
            }

            if ($action === 'assign_friend_group') {
                $friendId = (int) ($_POST['friend_id'] ?? 0);
                $groupId = (int) ($_POST['group_id'] ?? 0);
                $friendCheck = $database->prepare('SELECT u.id FROM users u INNER JOIN friendship_requests f ON f.status = \'accepted\' AND ((f.sender_id = ? AND f.receiver_id = u.id) OR (f.receiver_id = ? AND f.sender_id = u.id)) WHERE u.id = ? LIMIT 1');
                $friendCheck->execute([$userId, $userId, $friendId]);
                if (!$friendCheck->fetch()) {
                    json_response(['ok' => false, 'message' => 'Ce proche est introuvable.'], 404);
                }
                if ($groupId === 0) {
                    json_response(['ok' => false, 'message' => 'Précisez le groupe à retirer.'], 422);
                } else {
                    $groupCheck = $database->prepare('SELECT id FROM friend_groups WHERE id = ? AND user_id = ? LIMIT 1');
                    $groupCheck->execute([$groupId, $userId]);
                    if (!$groupCheck->fetch()) {
                        json_response(['ok' => false, 'message' => 'Groupe introuvable.'], 404);
                    }
                    $database->prepare('INSERT IGNORE INTO friend_group_members (group_id, friend_id) VALUES (?, ?)')->execute([$groupId, $friendId]);
                }
                json_response(['ok' => true]);
            }

            if ($action === 'unassign_friend_group') {
                $friendId = (int) ($_POST['friend_id'] ?? 0);
                $groupId = (int) ($_POST['group_id'] ?? 0);
                $database->prepare('DELETE gm FROM friend_group_members gm INNER JOIN friend_groups fg ON fg.id = gm.group_id WHERE fg.user_id = ? AND gm.group_id = ? AND gm.friend_id = ?')->execute([$userId, $groupId, $friendId]);
                json_response(['ok' => true]);
            }

            $listId = (int) ($_POST['list_id'] ?? 0);
            $listCheck = $database->prepare('SELECT l.id, l.user_id AS owner_id, m.role FROM shopping_lists l LEFT JOIN shopping_list_members m ON m.list_id = l.id AND m.user_id = ? WHERE l.id = ? AND (l.user_id = ? OR m.user_id = ?) LIMIT 1');
            $listCheck->execute([$userId, $listId, $userId, $userId]);
            $access = $listCheck->fetch();
            if (!$access) {
                if ($action === 'toggle_item') {
                    json_response(['ok' => false, 'message' => 'Liste introuvable.'], 404);
                }
                flash('error', 'Cette liste est introuvable.');
                redirect('index.php?page=home');
            }
            $notifyListMembers = static function (array $labels) use ($database, $listId, $userId): void {
                if (!$labels) {
                    return;
                }
                $listNameQuery = $database->prepare('SELECT name FROM shopping_lists WHERE id = ? LIMIT 1');
                $listNameQuery->execute([$listId]);
                $listName = (string) $listNameQuery->fetchColumn();
                $message = current_user()['name'] . ' a ajouté ' . implode(', ', $labels) . ' à la liste « ' . $listName . ' ». ';
                $message = mb_substr($message, 0, 255);
                $membersQuery = $database->prepare('SELECT user_id FROM shopping_list_members WHERE list_id = ? AND user_id <> ?');
                $membersQuery->execute([$listId, $userId]);
                $notification = $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)');
                foreach ($membersQuery->fetchAll() as $member) {
                    $notification->execute([(int) $member['user_id'], $userId, 'list_item_added', $message]);
                }
            };

            if ($action === 'add_item') {
                $label = trim((string) ($_POST['label'] ?? ''));
                if ($label !== '' && mb_strlen($label) <= 180) {
                    $database->beginTransaction();
                    $lockList = $database->prepare('SELECT id FROM shopping_lists WHERE id = ? FOR UPDATE');
                    $lockList->execute([$listId]);
                    $position = $database->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM shopping_items WHERE list_id = ?');
                    $position->execute([$listId]);
                    $insert = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, ?)');
                    $insert->execute([$listId, $label, (int) $position->fetchColumn()]);
                    $itemId = (int) $database->lastInsertId();
                    $notifyListMembers([$label]);
                    $database->commit();
                    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                        json_response(['ok' => true, 'item' => ['id' => $itemId, 'label' => $label, 'is_done' => false]]);
                    }
                    flash('success', 'Article ajoute.');
                } else {
                    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                        json_response(['ok' => false, 'message' => 'Ecrivez un article de 1 a 180 caracteres.'], 422);
                    }
                    flash('error', 'Ecrivez un article de 1 a 180 caracteres.');
                }
                redirect('index.php?page=home&list_id=' . $listId);
            }

            if ($action === 'save_note') {
                $normaliseNote = static function (string $value): array {
                    $value = str_replace(["\r\n", "\r"], "\n", $value);
                    return array_values(array_filter(array_map('trim', explode("\n", $value)), static fn (string $label): bool => $label !== ''));
                };
                $labels = $normaliseNote((string) ($_POST['content'] ?? ''));
                $baseLabels = $normaliseNote((string) ($_POST['base_content'] ?? ''));
                if (count($labels) > 200 || array_filter($labels, static fn (string $label): bool => mb_strlen($label) > 180)) {
                    json_response(['ok' => false, 'message' => 'Une liste ne peut pas dépasser 200 articles de 180 caractères.'], 422);
                }
                $database->beginTransaction();
                $lockList = $database->prepare('SELECT id FROM shopping_lists WHERE id = ? FOR UPDATE');
                $lockList->execute([$listId]);
                $items = $database->prepare('SELECT id, label, is_done FROM shopping_items WHERE list_id = ? ORDER BY position ASC, id ASC');
                $items->execute([$listId]);
                $existingItems = $items->fetchAll();
                $currentLabels = array_map(static fn (array $item): string => (string) $item['label'], $existingItems);
                $canReplace = $currentLabels === $baseLabels;
                $mergedLabels = $labels;
                if (!$canReplace) {
                    $mergedLabels = $currentLabels;
                    $hasDeletions = count($labels) < count($baseLabels);
                    if ($hasDeletions) {
                        $baseCounts = array_count_values($baseLabels);
                        $localCounts = array_count_values($labels);
                        foreach ($baseCounts as $baseLabel => $baseCount) {
                            $deletedCount = $baseCount - ($localCounts[$baseLabel] ?? 0);
                            for ($deletion = 0; $deletion < $deletedCount; $deletion++) {
                                $positionToRemove = array_search($baseLabel, $mergedLabels, true);
                                if ($positionToRemove === false) {
                                    break;
                                }
                                array_splice($mergedLabels, (int) $positionToRemove, 1);
                            }
                        }
                    }
                    $incomingAdditions = [];
                    foreach (array_slice($labels, count($baseLabels)) as $label) {
                        $incomingAdditions[] = $label;
                    }
                    foreach ($incomingAdditions as $label) {
                        $mergedLabels[] = $label;
                    }
                    if (!$hasDeletions) {
                        foreach ($baseLabels as $position => $baseLabel) {
                            if (isset($labels[$position]) && $labels[$position] !== $baseLabel && ($mergedLabels[$position] ?? null) === $baseLabel) {
                                $mergedLabels[$position] = $labels[$position];
                            }
                        }
                    }
                }
                if (count($mergedLabels) > 200 || array_filter($mergedLabels, static fn (string $label): bool => mb_strlen($label) > 180)) {
                    $database->rollBack();
                    json_response(['ok' => false, 'message' => 'Une liste ne peut pas dépasser 200 articles de 180 caractères.'], 422);
                }
                $update = $database->prepare('UPDATE shopping_items SET label = ?, position = ? WHERE id = ? AND list_id = ?');
                $insert = $database->prepare('INSERT INTO shopping_items (list_id, label, position) VALUES (?, ?, ?)');
                $addedLabels = [];
                foreach ($mergedLabels as $position => $label) {
                    if (isset($existingItems[$position])) {
                        $update->execute([$label, $position, $existingItems[$position]['id'], $listId]);
                    } else {
                        $insert->execute([$listId, $label, $position]);
                        $addedLabels[] = $label;
                    }
                }
                if ($canReplace && count($existingItems) > count($mergedLabels)) {
                    $delete = $database->prepare('DELETE FROM shopping_items WHERE id = ? AND list_id = ?');
                    foreach (array_slice($existingItems, count($mergedLabels)) as $item) {
                        $delete->execute([$item['id'], $listId]);
                    }
                }
                $notifyListMembers($addedLabels);
                $database->commit();
                json_response(['ok' => true, 'count' => count($mergedLabels), 'content' => implode("\n", $mergedLabels)]);
            }

            if ($action === 'delete_item') {
                $itemId = (int) ($_POST['item_id'] ?? 0);
                $delete = $database->prepare('DELETE FROM shopping_items WHERE id = ? AND list_id = ?');
                $delete->execute([$itemId, $listId]);
                redirect('index.php?page=home&list_id=' . $listId);
            }

            if ($action === 'delete_list') {
                $database->prepare('DELETE FROM shopping_lists WHERE id = ? AND user_id = ?')->execute([$listId, $userId]);
                $returnView = in_array($_POST['return_view'] ?? '', ['lists', 'shop'], true) ? $_POST['return_view'] : 'lists';
                redirect('index.php?page=home&view=' . $returnView);
            }

            if ($action === 'share_list') {
                if ((int) $access['owner_id'] !== $userId) {
                    flash('error', 'Seul le créateur peut inviter quelqu’un.');
                } else {
                    $inviteUserId = (int) ($_POST['invite_user_id'] ?? 0);
                    $invite = $database->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                    $invite->execute([$inviteUserId]);
                    $inviteUserId = (int) $invite->fetchColumn();
                    if (!$inviteUserId) {
                        flash('error', 'Cette adresse ne correspond pas encore à un compte Shoply.');
                    } elseif ((int) $inviteUserId === $userId) {
                        flash('error', 'Vous êtes déjà le créateur de cette liste.');
                    } else {
                        $friendCheck = $database->prepare('SELECT id FROM friendship_requests WHERE status = \'accepted\' AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) LIMIT 1');
                        $friendCheck->execute([$userId, $inviteUserId, $inviteUserId, $userId]);
                        if (!$friendCheck->fetch()) {
                            flash('error', 'Ajoutez d’abord cette personne à vos proches.');
                        } else {
                            $groupAlreadyShared = $database->prepare('SELECT fg.id FROM friend_groups fg INNER JOIN friend_group_members target_member ON target_member.group_id = fg.id AND target_member.friend_id = ? WHERE fg.user_id = ? AND NOT EXISTS (SELECT 1 FROM friend_group_members group_member LEFT JOIN shopping_list_members list_member ON list_member.list_id = ? AND list_member.user_id = group_member.friend_id WHERE group_member.group_id = fg.id AND list_member.user_id IS NULL) LIMIT 1');
                            $groupAlreadyShared->execute([$inviteUserId, $userId, $listId]);
                            if ($groupAlreadyShared->fetch()) {
                                flash('error', 'Le groupe de cette personne est déjà partagé avec cette liste.');
                            } else {
                            $membership = $database->prepare('INSERT IGNORE INTO shopping_list_members (list_id, user_id, role) VALUES (?, ?, \'member\')');
                            $membership->execute([$listId, $inviteUserId]);
                            if ($membership->rowCount() > 0) {
                                $listNameQuery = $database->prepare('SELECT name FROM shopping_lists WHERE id = ? LIMIT 1');
                                $listNameQuery->execute([$listId]);
                                $listName = (string) $listNameQuery->fetchColumn();
                                $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)')->execute([$inviteUserId, $userId, 'list_shared', current_user()['name'] . ' a partage la liste « ' . $listName . ' » avec vous.']);
                                $inviteNameQuery = $database->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) FROM users WHERE id = ? LIMIT 1");
                                $inviteNameQuery->execute([$inviteUserId]);
                                $inviteName = (string) $inviteNameQuery->fetchColumn();
                                $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)')->execute([$userId, $inviteUserId, 'list_shared_sent', 'La liste « ' . $listName . ' » a été partagée avec ' . $inviteName . '.']);
                            }
                            flash('success', 'La liste est maintenant partagée.');
                            }
                        }
                    }
                    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                        json_response(['ok' => true, 'list_id' => $listId, 'user_id' => $inviteUserId]);
                    }
                }
                redirect('index.php?page=home&view=shared&list_id=' . $listId);
            }

            if ($action === 'share_group') {
                if ((int) $access['owner_id'] !== $userId) {
                    json_response(['ok' => false, 'message' => 'Seul le créateur peut partager cette liste.'], 403);
                }
                $groupId = (int) ($_POST['group_id'] ?? 0);
                $groupCheck = $database->prepare('SELECT id, name FROM friend_groups WHERE id = ? AND user_id = ? LIMIT 1');
                $groupCheck->execute([$groupId, $userId]);
                $group = $groupCheck->fetch();
                if (!$group) {
                    json_response(['ok' => false, 'message' => 'Groupe introuvable.'], 404);
                }
                $membersQuery = $database->prepare('SELECT fgm.friend_id FROM friend_group_members fgm INNER JOIN friendship_requests f ON f.status = \'accepted\' AND ((f.sender_id = ? AND f.receiver_id = fgm.friend_id) OR (f.receiver_id = ? AND f.sender_id = fgm.friend_id)) WHERE fgm.group_id = ?');
                $membersQuery->execute([$userId, $userId, $groupId]);
                $added = 0;
                $memberInsert = $database->prepare('INSERT IGNORE INTO shopping_list_members (list_id, user_id, role) VALUES (?, ?, \'member\')');
                $listNameQuery = $database->prepare('SELECT name FROM shopping_lists WHERE id = ? LIMIT 1');
                $listNameQuery->execute([$listId]);
                $listName = (string) $listNameQuery->fetchColumn();
                $notification = $database->prepare('INSERT INTO notifications (user_id, actor_id, type, message) VALUES (?, ?, ?, ?)');
                foreach ($membersQuery->fetchAll() as $member) {
                    $memberInsert->execute([$listId, (int) $member['friend_id']]);
                    if ($memberInsert->rowCount() > 0) {
                        $added++;
                        $notification->execute([(int) $member['friend_id'], $userId, 'list_shared', current_user()['name'] . ' a partagé la liste « ' . $listName . ' » avec le groupe « ' . $group['name'] . ' ».']);
                    }
                }
                json_response(['ok' => true, 'added' => $added]);
            }

            if ($action === 'revoke_share') {
                if ((int) $access['owner_id'] !== $userId) {
                    json_response(['ok' => false, 'message' => 'Seul le créateur peut arrêter un partage.'], 403);
                }
                $memberId = (int) ($_POST['member_id'] ?? 0);
                if ($memberId === 0) {
                    $revoke = $database->prepare('DELETE FROM shopping_list_members WHERE list_id = ? AND user_id <> ?');
                    $revoke->execute([$listId, $userId]);
                } else {
                    $revoke = $database->prepare('DELETE FROM shopping_list_members WHERE list_id = ? AND user_id = ? AND user_id <> ?');
                    $revoke->execute([$listId, $memberId, $userId]);
                }
                json_response(['ok' => true]);
            }

            if ($action === 'toggle_item') {
                $itemId = (int) ($_POST['item_id'] ?? 0);
                $toggle = $database->prepare('UPDATE shopping_items SET is_done = 1 - is_done WHERE id = ? AND list_id = ?');
                $toggle->execute([$itemId, $listId]);
                $item = $database->prepare('SELECT is_done FROM shopping_items WHERE id = ? AND list_id = ? LIMIT 1');
                $item->execute([$itemId, $listId]);
                $state = $item->fetch();
                if (!$state) {
                    json_response(['ok' => false, 'message' => 'Article introuvable.'], 404);
                }
                $count = $database->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(is_done), 0) AS completed FROM shopping_items WHERE list_id = ?');
                $count->execute([$listId]);
                $progress = $count->fetch();
                json_response(['ok' => true, 'done' => (bool) $state['is_done'], 'total' => (int) $progress['total'], 'completed' => (int) $progress['completed']]);
            }
        } catch (Throwable $error) {
            error_log('[Shoply] List action failed: ' . get_class($error) . ' - ' . $error->getMessage());
            if ($action === 'toggle_item') {
                json_response(['ok' => false, 'message' => 'Impossible de sauvegarder cet article.'], 500);
            }
            flash('error', 'Impossible de modifier cette liste pour le moment.');
            redirect('index.php?page=home');
        }
    } elseif ($page === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        remember_old(['email' => $email]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $errors[] = 'Adresse email ou mot de passe incorrect.';
        } else {
            try {
                $statement = db()->prepare('SELECT id, first_name, last_name, email, password, email_verified_at FROM users WHERE email = ? LIMIT 1');
                $statement->execute([$email]);
                $user = $statement->fetch();
                if (!$user || !password_verify($password, $user['password'])) {
                    $errors[] = 'Adresse email ou mot de passe incorrect.';
                } elseif ($user['email_verified_at'] === null) {
                    $errors[] = 'Confirmez votre adresse email avant de vous connecter.';
                } else {
                    session_regenerate_id(true);
                    $displayName = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $displayName, 'email' => $user['email']];
                    clear_old();
                    flash('success', 'Bienvenue ' . $displayName . '.');
                    redirect('index.php?page=home');
                }
            } catch (Throwable $error) {
                $errors[] = 'Le service est momentanement indisponible. Reessayez dans un instant.';
            }
        }
    } elseif ($page === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('index.php?page=login');
    }
}

if ($page === 'verify') {
    $token = (string) ($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        flash('error', 'Ce lien de verification est invalide.');
    } else {
        try {
            $database = db();
            $statement = $database->prepare('SELECT t.id, t.user_id FROM email_verification_tokens t WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW() LIMIT 1');
            $statement->execute([hash('sha256', $token)]);
            $verification = $statement->fetch();
            if (!$verification) {
                flash('error', 'Ce lien est expire ou a deja ete utilise.');
            } else {
                $database->beginTransaction();
                $database->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$verification['user_id']]);
                $database->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?')->execute([$verification['id']]);
                $database->commit();
                flash('success', 'Adresse confirmee. Vous pouvez maintenant vous connecter.');
            }
        } catch (Throwable $error) {
            flash('error', 'La verification est temporairement indisponible.');
        }
    }
    redirect('index.php?page=login');
}

$flash = pull_flash();
$user = current_user();
if ($page === 'home' && !$user) {
    redirect('index.php?page=login');
}
if ($page === 'home' && $user && isset($_GET['api'])) {
    $database = db();
    $userId = (int) $user['id'];
    if ($_GET['api'] === 'notifications') {
        $query = $database->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $query->execute([$userId]);
        json_response(['ok' => true, 'unread' => (int) $query->fetchColumn()]);
    }
    if ($_GET['api'] === 'search_users') {
        $term = trim((string) ($_GET['q'] ?? ''));
        if ($term === '') {
            json_response(['ok' => true, 'users' => []]);
        }
        $query = $database->prepare('SELECT id, first_name, last_name, email FROM users WHERE id <> ? AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, \' \', last_name) LIKE ? OR email LIKE ?) ORDER BY first_name, last_name LIMIT 20');
        $like = '%' . $term . '%';
        $query->execute([$userId, $like, $like, $like, $like]);
        json_response(['ok' => true, 'users' => $query->fetchAll()]);
    }
    if ($_GET['api'] === 'list_items') {
        $listId = (int) ($_GET['list_id'] ?? 0);
        $access = $database->prepare('SELECT l.id FROM shopping_lists l LEFT JOIN shopping_list_members m ON m.list_id = l.id AND m.user_id = ? WHERE l.id = ? AND (l.user_id = ? OR m.user_id = ?) LIMIT 1');
        $access->execute([$userId, $listId, $userId, $userId]);
        if (!$access->fetch()) {
            json_response(['ok' => false, 'message' => 'Liste introuvable.'], 404);
        }
        $query = $database->prepare('SELECT id, label, is_done FROM shopping_items WHERE list_id = ? ORDER BY position ASC, id ASC');
        $query->execute([$listId]);
        json_response(['ok' => true, 'items' => $query->fetchAll()]);
    }
    if ($_GET['api'] === 'lists') {
        $query = $database->prepare('SELECT l.id, l.name, l.user_id AS owner_id, COUNT(i.id) AS item_count, COALESCE(SUM(i.is_done), 0) AS completed_count FROM shopping_lists l LEFT JOIN shopping_items i ON i.list_id = l.id WHERE l.user_id = ? OR EXISTS (SELECT 1 FROM shopping_list_members mx WHERE mx.list_id = l.id AND mx.user_id = ?) GROUP BY l.id');
        $query->execute([$userId, $userId]);
        json_response(['ok' => true, 'lists' => $query->fetchAll()]);
    }
    if ($_GET['api'] === 'shared_lists') {
        $query = $database->prepare('SELECT l.id, l.name FROM shopping_lists l WHERE l.user_id = ? ORDER BY l.updated_at DESC, l.id DESC');
        $query->execute([$userId]);
        $ownedLists = $query->fetchAll();
        $membersQuery = $database->prepare('SELECT m.list_id, u.id AS user_id, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS name, u.email FROM shopping_list_members m INNER JOIN users u ON u.id = m.user_id WHERE m.list_id = ? AND m.user_id <> ? ORDER BY u.first_name, u.last_name');
        $groupsQuery = $database->prepare('SELECT id, name FROM friend_groups WHERE user_id = ? ORDER BY name');
        $groupsQuery->execute([$userId]);
        $groups = $groupsQuery->fetchAll();
        $groupMembersQuery = $database->prepare('SELECT friend_id FROM friend_group_members WHERE group_id = ?');
        foreach ($groups as &$group) {
            $groupMembersQuery->execute([(int) $group['id']]);
            $group['member_ids'] = array_map('intval', array_column($groupMembersQuery->fetchAll(), 'friend_id'));
        }
        unset($group);
        foreach ($ownedLists as &$ownedList) {
            $membersQuery->execute([(int) $ownedList['id'], $userId]);
            $ownedList['members'] = $membersQuery->fetchAll();
        }
        unset($ownedList);
        $friendsQuery = $database->prepare('SELECT DISTINCT u.id, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS name, u.email FROM users u INNER JOIN friendship_requests f ON f.status = \'accepted\' AND ((f.sender_id = ? AND f.receiver_id = u.id) OR (f.receiver_id = ? AND f.sender_id = u.id)) WHERE u.id <> ? ORDER BY u.first_name, u.last_name');
        $friendsQuery->execute([$userId, $userId, $userId]);
        json_response(['ok' => true, 'lists' => $ownedLists, 'friends' => $friendsQuery->fetchAll(), 'groups' => $groups]);
    }
    if ($_GET['api'] === 'profile_groups') {
        $groupsQuery = $database->prepare('SELECT id, name FROM friend_groups WHERE user_id = ? ORDER BY name');
        $groupsQuery->execute([$userId]);
        $friendsQuery = $database->prepare('SELECT DISTINCT u.id, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS name, u.email FROM users u INNER JOIN friendship_requests f ON f.status = \'accepted\' AND ((f.sender_id = ? AND f.receiver_id = u.id) OR (f.receiver_id = ? AND f.sender_id = u.id)) WHERE u.id <> ? ORDER BY u.first_name, u.last_name');
        $friendsQuery->execute([$userId, $userId, $userId]);
        $friends = $friendsQuery->fetchAll();
        $friendGroupsQuery = $database->prepare('SELECT fgm.friend_id, fgm.group_id FROM friend_group_members fgm INNER JOIN friend_groups fg ON fg.id = fgm.group_id WHERE fg.user_id = ? AND fgm.friend_id = ?');
        foreach ($friends as &$friend) {
            $friendGroupsQuery->execute([$userId, (int) $friend['id']]);
            $friend['group_ids'] = array_map('intval', array_column($friendGroupsQuery->fetchAll(), 'group_id'));
        }
        unset($friend);
        json_response(['ok' => true, 'groups' => $groupsQuery->fetchAll(), 'friends' => $friends]);
    }
}
$isRegister = $page === 'register';
$title = $page === 'home' ? 'Mes listes' : ($isRegister ? 'Creer un compte' : 'Se connecter');
$lists = [];
$selectedList = null;
$selectedItems = [];
$members = [];
$availableUsers = [];
$searchUsers = [];
$notifications = [];
$unreadNotifications = 0;
if ($page === 'home' && $user) {
    $view = $_GET['view'] ?? 'lists';
    if ($view === 'split') {
        $view = 'shared';
    }
    if ($view === 'notifications') {
        $markNotificationsRead = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $markNotificationsRead->execute([(int) $user['id']]);
    }
    $listQuery = db()->prepare('SELECT l.id, l.name, l.user_id AS owner_id, l.updated_at, COUNT(i.id) AS item_count, COALESCE(SUM(i.is_done), 0) AS completed_count FROM shopping_lists l LEFT JOIN shopping_items i ON i.list_id = l.id WHERE l.user_id = ? OR EXISTS (SELECT 1 FROM shopping_list_members mx WHERE mx.list_id = l.id AND mx.user_id = ?) GROUP BY l.id ORDER BY l.updated_at DESC, l.id DESC');
    $listQuery->execute([(int) $user['id'], (int) $user['id']]);
    $lists = $listQuery->fetchAll();
    $sharedListIds = array_values(array_map('intval', array_column(array_filter($lists, static fn (array $list): bool => (int) $list['owner_id'] !== (int) $user['id']), 'id')));
    $availableUsers = db()->prepare('SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u INNER JOIN friendship_requests f ON f.status = \'accepted\' AND ((f.sender_id = ? AND f.receiver_id = u.id) OR (f.receiver_id = ? AND f.sender_id = u.id)) WHERE u.id <> ? ORDER BY u.first_name, u.last_name');
    $availableUsers->execute([(int) $user['id'], (int) $user['id'], (int) $user['id']]);
    $availableUsers = $availableUsers->fetchAll();
    $searchTerm = trim((string) ($_GET['q'] ?? ''));
    if (in_array($view, ['shared', 'profile'], true) && $searchTerm !== '') {
        $searchUsersQuery = db()->prepare('SELECT id, first_name, last_name, email FROM users WHERE id <> ? AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, \' \', last_name) LIKE ? OR email LIKE ?) ORDER BY first_name, last_name LIMIT 20');
        $like = '%' . $searchTerm . '%';
        $searchUsersQuery->execute([(int) $user['id'], $like, $like, $like, $like]);
        $searchUsers = $searchUsersQuery->fetchAll();
    }
    $notificationQuery = db()->prepare('SELECT n.id, n.type, n.message, n.read_at, n.created_at, n.friendship_request_id, n.actor_id, fr.status AS friendship_status, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS actor_name FROM notifications n LEFT JOIN users u ON u.id = n.actor_id LEFT JOIN friendship_requests fr ON fr.id = n.friendship_request_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 40');
    $notificationQuery->execute([(int) $user['id']]);
    $notifications = $notificationQuery->fetchAll();
    $unreadNotifications = count(array_filter($notifications, static fn (array $notification): bool => $notification['read_at'] === null));
    foreach ($notifications as &$notification) {
        if ($notification['type'] === 'friend_request' && $notification['friendship_status'] === 'pending') {
            $notification['read_at'] = null;
        }
    }
    unset($notification);
    $selectedId = (int) ($_GET['list_id'] ?? 0);
    foreach ($lists as $list) {
        if ((int) $list['id'] === $selectedId) {
            $selectedList = $list;
            break;
        }
    }
    if ($selectedList) {
        $memberQuery = db()->prepare('SELECT u.id, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS name, u.email, m.role FROM shopping_list_members m INNER JOIN users u ON u.id = m.user_id WHERE m.list_id = ? ORDER BY m.role DESC, u.first_name');
        $memberQuery->execute([$selectedList['id']]);
        $members = $memberQuery->fetchAll();
        $itemQuery = db()->prepare('SELECT i.id, i.label, i.is_done, i.assigned_user_id, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS assigned_name FROM shopping_items i LEFT JOIN users u ON u.id = i.assigned_user_id WHERE i.list_id = ? ORDER BY i.position ASC, i.id ASC');
        $itemQuery->execute([$selectedList['id']]);
        $selectedItems = $itemQuery->fetchAll();
    }
}
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#153c3b">
    <meta name="description" content="Shoply, vos courses plus simples, ensemble.">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=38">
    <title><?= e($title) ?> · Shoply</title>
</head>
<body class="<?= $user ? 'app-body' : 'auth-body' ?>" data-page="<?= e($page) ?>" data-user-id="<?= $user ? (int) $user['id'] : 0 ?>">
<div class="ambient ambient-one" aria-hidden="true"></div><div class="ambient ambient-two" aria-hidden="true"></div>
<main class="shell">
    <header class="topbar"><a class="brand" href="index.php?page=<?= $user ? 'home' : 'login' ?>" aria-label="Shoply, accueil"><span class="brand-mark">S</span><span>shoply<span class="dot">.</span></span></a><?php if ($user): ?><a class="notification-link" href="index.php?page=home&view=notifications" aria-label="Notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php if ($unreadNotifications > 0): ?><b><?= $unreadNotifications > 9 ? '9+' : $unreadNotifications ?></b><?php endif; ?></a><?php endif; ?></header>
    <?php if ($page === 'home' && $user): ?>
        <section class="dashboard dashboard-<?= e($view) ?>">
            <input class="csrf-token" type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="shared-list-meta" data-shared-list-ids="<?= e(implode(',', $sharedListIds)) ?>" hidden></div>
            <div class="dashboard-heading"><div><div class="eyebrow">SHOPLY</div><h1><?= $view === 'shared' ? 'Partagées' : ($view === 'profile' ? 'Profil' : ($view === 'shop' ? 'Courses' : ($view === 'split' ? 'Répartir' : 'Mes listes'))) ?></h1></div></div>
            <?php if ($flash): ?><div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
            <?php if ($view === 'new'): ?>
                <section class="focus-panel"><div class="focus-icon">+</div><div class="eyebrow">NOUVELLE LISTE</div><h2>Qu'est-ce qu'on prépare ?</h2><p>Un nom simple suffit. Vous pourrez ajouter les articles juste après.</p><form class="create-list-focus" method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_list"><label for="focus-list-name" class="sr-only">Nom de la liste</label><input id="focus-list-name" name="list_name" type="text" placeholder="Ex. Grand marché de samedi" maxlength="120" required autofocus><button class="button button-primary" type="submit">Créer la liste <span aria-hidden="true">→</span></button></form></section>
            <?php elseif ($view === 'notifications'): ?>
                <section class="focus-panel notifications-panel"><div class="focus-icon">♡</div><div class="eyebrow">NOTIFICATIONS</div><h2>Vos demandes</h2><p>Les invitations de vos proches apparaissent ici.</p><div class="notification-list"><?php if (!$notifications): ?><div class="quiet-empty"><span>·</span><p>Vous êtes à jour.</p></div><?php else: foreach ($notifications as $notification): ?><article class="notification-card <?= $notification['read_at'] === null ? 'is-unread' : '' ?>"><span class="notification-mark"><?= $notification['type'] === 'friend_request' ? '↗' : '✓' ?></span><div class="notification-copy"><strong><?= e($notification['message']) ?></strong><small><?= e(date('d/m/Y à H:i', strtotime($notification['created_at']))) ?></small></div><?php if ($notification['type'] === 'friend_request' && $notification['read_at'] === null): ?><div class="notification-actions"><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="respond_friend_request"><input type="hidden" name="request_id" value="<?= (int) $notification['friendship_request_id'] ?>"><input type="hidden" name="decision" value="accept"><button class="button button-primary" type="submit">Accepter</button></form><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="respond_friend_request"><input type="hidden" name="request_id" value="<?= (int) $notification['friendship_request_id'] ?>"><input type="hidden" name="decision" value="decline"><button class="button button-quiet" type="submit">Refuser</button></form></div><?php endif; ?></article><?php endforeach; endif; ?></div></section>
            <?php elseif ($view === 'shared'): ?>
                <section class="focus-panel shared-panel"><div class="focus-icon">↗</div><div class="eyebrow">À PLUSIEURS</div><h2>Vos proches</h2><p>Trouvez une personne, ajoutez-la, puis partagez vos listes avec elle.</p><form class="person-search" method="get" action="index.php"><input type="hidden" name="page" value="home"><input type="hidden" name="view" value="shared"><label for="person-search" class="sr-only">Rechercher un utilisateur</label><input id="person-search" name="q" type="search" value="<?= e($searchTerm ?? '') ?>" placeholder="Rechercher par nom ou email..."><button class="button button-primary" type="submit">Rechercher</button></form><?php if ($searchUsers): ?><div class="user-results"><?php foreach ($searchUsers as $searchUser): ?><div class="user-result"><span class="user-avatar"><?= e(strtoupper(substr($searchUser['first_name'], 0, 1))) ?></span><div><strong><?= e(trim($searchUser['first_name'] . ' ' . $searchUser['last_name'])) ?></strong><small><?= e($searchUser['email']) ?></small></div><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_friend_request"><input type="hidden" name="receiver_id" value="<?= (int) $searchUser['id'] ?>"><button class="icon-button" type="submit" aria-label="Ajouter <?= e(trim($searchUser['first_name'] . ' ' . $searchUser['last_name'])) ?>">+</button></form></div><?php endforeach; ?></div><?php elseif (isset($searchTerm) && $searchTerm !== ''): ?><div class="quiet-empty"><span>?</span><p>Aucun utilisateur trouvé.</p></div><?php endif; ?><div class="shared-divider"><span>LISTES PARTAGÉES</span></div><div class="shared-list-grid"><?php $sharedFound = false; foreach ($lists as $list): if ((int) $list['owner_id'] === (int) $user['id']) continue; $sharedFound = true; ?><a class="shared-list-card" href="index.php?page=home&view=shop&list_id=<?= (int) $list['id'] ?>"><span class="list-dot"></span><strong><?= e($list['name']) ?></strong><small><?= (int) $list['completed_count'] ?>/<?= (int) $list['item_count'] ?> articles · partagée</small><span class="card-arrow">→</span></a><?php endforeach; if (!$sharedFound): ?><div class="quiet-empty"><span>◎</span><p>Aucune liste partagée pour le moment.</p></div><?php endif; ?></div><div class="share-invite"><div><strong>Partager une liste</strong><small>Avec un contact accepté.</small></div><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="share_list"><label for="share-list" class="sr-only">Liste à partager</label><select id="share-list" name="list_id" required><option value="">Choisir une liste</option><?php foreach ($lists as $list): if ((int) $list['owner_id'] === (int) $user['id']): ?><option value="<?= (int) $list['id'] ?>"><?= e($list['name']) ?></option><?php endif; endforeach; ?></select><label for="invite-user" class="sr-only">Contact</label><select id="invite-user" name="invite_user_id" required><option value="">Choisir un proche</option><?php foreach ($availableUsers as $availableUser): ?><option value="<?= (int) $availableUser['id'] ?>"><?= e(trim($availableUser['first_name'] . ' ' . $availableUser['last_name'])) ?></option><?php endforeach; ?></select><button class="button button-primary" type="submit">Partager</button></form></div><a class="text-action" href="index.php?page=home&view=split">Répartir une liste partagée <span>→</span></a></section>
            <?php elseif ($view === 'split'): ?>
                <section class="focus-panel split-panel"><div class="focus-icon">✦</div><div class="eyebrow">RÉPARTITION</div><h2>Qui prend quoi ?</h2><p>Choisissez une liste partagée, puis attribuez chaque article.</p><?php if (!$selectedList || (int) $selectedList['owner_id'] === (int) $user['id']): ?><div class="compact-list-picker"><?php foreach ($lists as $list): if ((int) $list['owner_id'] !== (int) $user['id']): ?><a href="index.php?page=home&view=split&list_id=<?= (int) $list['id'] ?>"><?= e($list['name']) ?></a><?php endif; endforeach; ?></div><div class="quiet-empty"><span>✦</span><p>Choisissez une liste partagée pour commencer.</p></div><?php else: ?><div class="compact-list-picker"><?php foreach ($lists as $list): if ((int) $list['owner_id'] !== (int) $user['id']): ?><a class="<?= (int) $list['id'] === (int) $selectedList['id'] ? 'is-active' : '' ?>" href="index.php?page=home&view=split&list_id=<?= (int) $list['id'] ?>"><?= e($list['name']) ?></a><?php endif; endforeach; ?></div><div class="assignment-list"><?php foreach ($selectedItems as $item): ?><div class="assignment-row"><span class="assignment-check <?= (int) $item['is_done'] ? 'is-done' : '' ?>"><?= (int) $item['is_done'] ? '✓' : '·' ?></span><strong><?= e($item['label']) ?></strong><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign_item"><input type="hidden" name="list_id" value="<?= (int) $selectedList['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><select name="assigned_user_id" onchange="this.form.submit()"><option value="0">À répartir</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $item['assigned_user_id'] === (int) $member['id'] ? 'selected' : '' ?>><?= e($member['name']) ?></option><?php endforeach; ?></select></form></div><?php endforeach; if (!$selectedItems): ?><div class="quiet-empty"><span>✦</span><p>Cette liste ne contient pas encore d’articles.</p></div><?php endif; ?></div><?php endif; ?></section>
            <?php elseif ($view === 'profile'): ?>
                <section class="focus-panel profile-panel"><div class="profile-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div><div class="eyebrow">MON PROFIL</div><h2><?= e($user['name']) ?></h2><p><?= e($user['email']) ?></p><div class="profile-people"><strong>Ajouter des proches</strong><small>Recherchez un utilisateur par nom ou email.</small><form class="person-search" method="get" action="index.php"><input type="hidden" name="page" value="home"><input type="hidden" name="view" value="profile"><label for="profile-person-search" class="sr-only">Rechercher un utilisateur</label><input id="profile-person-search" name="q" type="search" value="<?= e($searchTerm ?? '') ?>" placeholder="Nom, prénom ou email..."><button class="button button-primary" type="submit">Rechercher</button></form><?php if ($searchUsers): ?><div class="user-results"><?php foreach ($searchUsers as $searchUser): ?><div class="user-result"><span class="user-avatar"><?= e(strtoupper(substr($searchUser['first_name'], 0, 1))) ?></span><div><strong><?= e(trim($searchUser['first_name'] . ' ' . $searchUser['last_name'])) ?></strong><small><?= e($searchUser['email']) ?></small></div><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_friend_request"><input type="hidden" name="return_view" value="profile"><input type="hidden" name="receiver_id" value="<?= (int) $searchUser['id'] ?>"><button class="icon-button" type="submit" aria-label="Ajouter <?= e(trim($searchUser['first_name'] . ' ' . $searchUser['last_name'])) ?>">+</button></form></div><?php endforeach; ?></div><?php elseif (isset($searchTerm) && $searchTerm !== ''): ?><div class="quiet-empty"><span>?</span><p>Aucun utilisateur trouvé.</p></div><?php endif; ?></div><div class="profile-friends"><strong>Mes proches</strong><?php if (!$availableUsers): ?><small>Vous n’avez pas encore de proche ajouté.</small><?php else: ?><div class="friend-chips"><?php foreach ($availableUsers as $availableUser): ?><span><?= e(trim($availableUser['first_name'] . ' ' . $availableUser['last_name'])) ?></span><?php endforeach; ?></div><?php endif; ?></div><form method="post" action="index.php?page=logout"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button button-primary" type="submit">Se déconnecter</button></form></section>
            <?php elseif (($view === 'lists' || $view === 'shop') && !$selectedList): ?>
                <section class="list-index" data-list-index><div class="list-index-head"><div><div class="eyebrow"><?= $view === 'shop' ? 'À ACHETER' : 'MES LISTES' ?></div><h2><?= $view === 'shop' ? 'Choisissez une liste' : 'Toutes vos listes' ?></h2></div><a class="icon-button" href="index.php?page=home&view=new" aria-label="Créer une liste">+</a></div><?php if (!$lists): ?><div class="quiet-empty"><span>✦</span><p>Aucune liste pour le moment.</p><a class="text-action" href="index.php?page=home&view=new">Créer votre première liste <span>→</span></a></div><?php else: ?><div class="list-index-rows"><?php foreach ($lists as $list): ?><a class="list-index-row" href="index.php?page=home&view=<?= $view ?>&list_id=<?= (int) $list['id'] ?>"><span class="list-row-mark"><?= $view === 'shop' ? '✓' : '•' ?></span><span class="list-row-content"><strong><?= e($list['name']) ?></strong><small><?= (int) $list['item_count'] ?> article<?= (int) $list['item_count'] === 1 ? '' : 's' ?><?= $view === 'shop' ? ' · ' . (int) $list['completed_count'] . ' acheté(s)' : '' ?></small></span><span class="list-row-arrow">→</span></a><?php endforeach; ?></div><?php endif; ?></section>
            <?php else: $isShopping = $view === 'shop'; $total = $selectedList ? (int) $selectedList['item_count'] : 0; $completed = $selectedList ? (int) $selectedList['completed_count'] : 0; $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0; ?>
                <section class="list-workspace <?= $selectedList ? '' : 'is-empty' ?>" data-can-delete-list="<?= $selectedList && (int) $selectedList['owner_id'] === (int) $user['id'] ? 'true' : 'false' ?>">
                    <div class="workspace-top"><div class="list-switcher" aria-label="Choisir une liste"><?php foreach ($lists as $list): ?><a class="list-switch-card <?= $selectedList && (int) $selectedList['id'] === (int) $list['id'] ? 'is-active' : '' ?>" href="index.php?page=home&view=<?= $isShopping ? 'shop' : 'lists' ?>&list_id=<?= (int) $list['id'] ?>"><span class="list-switch-top"><span class="list-dot"></span><small><?= (int) $list['completed_count'] ?>/<?= (int) $list['item_count'] ?></small></span><strong><?= e($list['name']) ?></strong><span class="list-switch-progress"><i class="progress-width-<?= (int) $list['item_count'] > 0 ? (int) round(((int) $list['completed_count'] / (int) $list['item_count']) * 100) : 0 ?>"></i></span></a><?php endforeach; ?><a class="list-switch-card list-switch-new" href="index.php?page=home&view=new"><span>+</span><strong>Nouvelle liste</strong></a></div><?php if ($selectedList): ?><div class="workspace-actions"><a class="button <?= $isShopping ? 'button-quiet' : 'button-primary' ?>" href="index.php?page=home&view=<?= $isShopping ? 'lists' : 'shop' ?>&list_id=<?= (int) $selectedList['id'] ?>"><?= $isShopping ? 'Revenir à la liste' : 'Commencer les courses' ?><span aria-hidden="true"><?= $isShopping ? '←' : '→' ?></span></a></div><?php endif; ?></div>
                    <?php if (!$selectedList): ?><div class="workspace-empty"><span class="empty-icon">+</span><h2>Créez votre première liste.</h2><p>Deux clics et vous êtes prêt.</p><a class="button button-primary" href="index.php?page=home&view=new">Créer une liste</a></div>
                    <?php else: ?><div class="workspace-heading"><div><div class="eyebrow"><?= $isShopping ? 'MODE COURSES' : 'LISTE ACTIVE' ?></div><h2><?= e($selectedList['name']) ?></h2></div></div><?php if ($isShopping): ?><div class="progress-card"><div class="progress-copy"><strong><span data-progress-completed><?= $completed ?></span> sur <span data-progress-total><?= $total ?></span> cochés</strong><span data-progress-percent><?= $percent ?>%</span></div><div class="progress-track"><span data-progress-bar style="width:<?= $percent ?>%"></span></div><p><?= $total === 0 ? 'Votre liste est vide pour le moment.' : ($percent === 100 ? 'Tout est dans le panier. Bien joué.' : 'Un article à la fois, vous y êtes presque.') ?></p></div><?php else: ?><form class="add-item-form" method="post" action="index.php?page=home&list_id=<?= (int) $selectedList['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_item"><input type="hidden" name="list_id" value="<?= (int) $selectedList['id'] ?>"><label for="item-label" class="sr-only">Ajouter un article</label><span class="add-item-symbol" aria-hidden="true">+</span><input id="item-label" name="label" type="text" placeholder="Ajouter un produit à la liste..." maxlength="180" autocomplete="off" required><button class="button button-primary" type="submit">Ajouter</button></form><?php endif; ?><div class="items-list <?= $isShopping ? 'shopping-mode' : '' ?>" data-list-id="<?= (int) $selectedList['id'] ?>"><?php if (!$selectedItems): ?><div class="items-empty"><span>✦</span><p>Votre liste attend ses premiers articles.</p></div><?php endif; ?><?php foreach ($selectedItems as $item): ?><div class="shopping-item <?= (int) $item['is_done'] ? 'is-done' : '' ?>" data-item-id="<?= (int) $item['id'] ?>"><button class="item-check" type="button" aria-label="<?= (int) $item['is_done'] ? 'Décocher' : 'Cocher' ?> <?= e($item['label']) ?>" aria-pressed="<?= (int) $item['is_done'] ? 'true' : 'false' ?>"><span>✓</span></button><span class="item-label"><?= e($item['label']) ?></span><?php if (!$isShopping): ?><form method="post" action="index.php?page=home&list_id=<?= (int) $selectedList['id'] ?>" data-confirm="Retirer cet article ?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="list_id" value="<?= (int) $selectedList['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><button class="item-delete" type="submit" aria-label="Retirer <?= e($item['label']) ?>">×</button></form><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
                </section>
            <?php endif; ?>
        </section>
        <nav class="bottom-nav" aria-label="Navigation principale"><a class="nav-item <?= $view === 'lists' ? 'is-active' : '' ?>" href="index.php?page=home&view=lists"><span class="nav-icon">▤</span><span>Listes</span></a><a class="nav-item <?= $view === 'shop' ? 'is-active' : '' ?>" href="index.php?page=home&view=shop"><span class="nav-icon">✓</span><span>Courses</span></a><a class="nav-create" href="index.php?page=home&view=new" aria-label="Créer une liste"><span>+</span></a><a class="nav-item <?= $view === 'shared' ? 'is-active' : '' ?>" href="index.php?page=home&view=shared"><span class="nav-icon">↗</span><span>Partagé</span></a><a class="nav-item <?= $view === 'profile' ? 'is-active' : '' ?>" href="index.php?page=home&view=profile"><span class="nav-icon">○</span><span>Profil</span></a></nav>
    <?php else: ?>
        <section class="auth-layout">
            <aside class="intro"><div class="eyebrow">LISTES DE COURSES · 01</div><h1>Faire les courses, <em>sans y penser deux fois.</em></h1><p>Shoply rassemble vos envies, vos essentiels et les personnes avec qui vous les partagez.</p><div class="feature-list"><div><span>01</span><p><strong>Clair au premier regard</strong><br>Chaque produit trouve sa place.</p></div><div><span>02</span><p><strong>Ensemble, naturellement</strong><br>Une liste qui vit avec votre foyer.</p></div></div></aside>
            <section class="auth-card" aria-labelledby="auth-title">
                <div class="card-heading"><div><div class="eyebrow"><?= $isRegister ? 'NOUVEAU COMPTE' : 'BON RETOUR' ?></div><h2 id="auth-title"><?= e($title) ?></h2></div><span class="card-number">0<?= $isRegister ? '2' : '1' ?></span></div>
                <?php if ($flash): ?><div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
                <?php if ($errors): ?><div class="notice notice-error" role="alert"><?= e(is_array($errors) ? (is_string(reset($errors)) ? reset($errors) : 'Verifiez les champs signales.') : $errors) ?></div><?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <?php if ($isRegister): ?><label for="name">Votre prenom ou nom<input id="name" name="name" type="text" autocomplete="name" value="<?= old('name') ?>" required aria-invalid="<?= isset($errors['name']) ? 'true' : 'false' ?>"><?php if (isset($errors['name'])): ?><small class="field-error"><?= e($errors['name']) ?></small><?php endif; ?></label><?php endif; ?>
                    <label for="email">Adresse email<input id="email" name="email" type="email" autocomplete="email" value="<?= old('email') ?>" required aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>"><?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif; ?></label>
                    <label for="password">Mot de passe<input id="password" name="password" type="password" autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>" required><?php if ($isRegister): ?><small>8 caracteres minimum</small><?php endif; ?><?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?></label>
                    <?php if ($isRegister): ?><label for="password_confirmation">Confirmer le mot de passe<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required><?php if (isset($errors['password_confirmation'])): ?><small class="field-error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?></label><?php endif; ?>
                    <button class="button button-primary button-wide" type="submit"><?= $isRegister ? 'Creer mon compte' : 'Ouvrir mon espace' ?><span aria-hidden="true">↗</span></button>
                </form>
                <p class="switch-copy"><?= $isRegister ? 'Vous avez deja un compte ?' : 'Pas encore de compte ?' ?> <a href="index.php?page=<?= $isRegister ? 'login' : 'register' ?>"><?= $isRegister ? 'Se connecter' : 'Creer un compte' ?></a></p>
            </section>
        </section>
    <?php endif; ?>
    <footer class="footer"><span>© <?= date('Y') ?> Shoply</span><span>Simplement utile.</span></footer>
</main>
<script src="assets/js/app.js?v=42" defer></script>
</body></html>