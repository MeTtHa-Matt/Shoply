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
$isRegister = $page === 'register';
$title = $page === 'home' ? 'Votre espace' : ($isRegister ? 'Creer un compte' : 'Se connecter');
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#153c3b">
    <meta name="description" content="Shoply, vos courses plus simples, ensemble.">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <title><?= e($title) ?> · Shoply</title>
</head>
<body>
<div class="ambient ambient-one" aria-hidden="true"></div><div class="ambient ambient-two" aria-hidden="true"></div>
<main class="shell">
    <header class="topbar"><a class="brand" href="index.php?page=login" aria-label="Shoply, accueil"><span class="brand-mark">S</span><span>shoply<span class="dot">.</span></span></a><span class="top-note">Courses partagees, esprit leger</span></header>
    <?php if ($page === 'home' && $user): ?>
        <section class="welcome-panel">
            <div class="eyebrow">ESPACE PERSONNEL</div><h1>Bonjour, <?= e($user['name']) ?>.</h1><p>Votre espace est pret. Les listes partagees arrivent juste ici.</p>
            <div class="empty-state"><span class="empty-icon">+</span><h2>Votre premiere liste</h2><p>Ajoutez les produits qui comptent, puis invitez votre foyer a participer.</p><button class="button button-primary" type="button" disabled>Bientot disponible</button></div>
            <form class="logout-form" method="post" action="index.php?page=logout"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button button-quiet" type="submit">Se deconnecter</button></form>
        </section>
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
<script src="assets/js/app.js" defer></script>
</body></html>