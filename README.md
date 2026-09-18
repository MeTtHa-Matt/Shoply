# Shoply

Shoply est une application web mobile-first de listes de courses partageables. Elle permet de preparer ses achats seul ou a plusieurs, depuis un telephone comme depuis un ordinateur.

## Fonctionnalites

- creation de compte avec confirmation d'adresse email ;
- recuperation et changement de mot de passe avec validation par email ;
- connexion persistante et gestion de session securisee ;
- creation, modification, partage et suivi de listes de courses ;
- ajout d'articles, changement de statut et attribution a un membre ;
- ajout de proches, demandes d'amitie et groupes de proches ;
- notifications dans l'application et par email ;
- installation comme PWA avec cache hors ligne des ressources statiques ;
- interface responsive, accessible au clavier et compatible avec la reduction des animations.

## Stack technique

- PHP natif, HTML, CSS et JavaScript ;
- MySQL ou MariaDB via PDO et requetes preparees ;
- PHPMailer pour les emails SMTP ;
- aucune dependance frontend ni framework applicatif.

## Prerequis

- PHP 8.0 ou version ulterieure ;
- extensions PHP `pdo_mysql`, `mbstring` et `openssl` ;
- MySQL 8+ ou MariaDB compatible ;
- un serveur SMTP pour la verification des comptes et les notifications ;
- Composer uniquement si les dependances de `vendor/` doivent etre reinstallees.

## Installation locale

Depuis la racine du projet :

1. Creer une base MySQL ou MariaDB et un utilisateur dedie.
2. Copier `.env.example` vers `.env`, puis renseigner les valeurs locales :

```bash
cp .env.example .env
```

3. Importer le schema :

```bash
mysql -u shoply -p shoply < db.sql
```

4. Demarrer le serveur PHP integre :

```bash
php -S 127.0.0.1:8080 -t .
```

5. Ouvrir [http://127.0.0.1:8080/index.php?page=login](http://127.0.0.1:8080/index.php?page=login).

Le schema est idempotent. L'application cree aussi certains index et tables de compatibilite a la premiere connexion.

## Configuration `.env`

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=shoply
DB_USER=shoply
DB_PASSWORD=mot-de-passe-local

MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=mot-de-passe-smtp
MAIL_ENCRYPTION=tls
MAIL_FROM=no-reply@example.com
MAIL_FROM_NAME=Shoply

# A activer uniquement lorsque l'application est servie en HTTPS.
SESSION_SECURE=false

# Commande vocale (optionnel)
GEMINI_API_KEY=
GEMINI_MODEL=gemini-flash-lite-latest
GROQ_API_KEY=
GROQ_MODEL=llama-3.1-8b-instant
```

Ne committez jamais `.env`. En production, utilisez HTTPS et definissez `SESSION_SECURE=true`.

Les cles `GEMINI_API_KEY` et `GROQ_API_KEY` activent la comprehension IA de la commande vocale. Au moins une des deux cles est necessaire pour ce mode ; les autres fonctionnalites de Shoply restent utilisables sans fournisseur IA.

Les URLs de verification et de notification sont derivees automatiquement de la requete courante. Aucun domaine ou sous-dossier ne doit donc etre code en dur.

## Retention des donnees

A chaque ouverture de connexion PDO, Shoply nettoie automatiquement :

- les jetons de verification et de connexion expires ;
- les jetons de changement de mot de passe expires ;
- les comptes non verifies apres 7 jours ;
- les notifications apres 90 jours ;
- les demandes d'amitie refusees apres 180 jours.

Ces durees peuvent etre surchargees dans `.env` :

```dotenv
UNVERIFIED_USER_RETENTION_DAYS=7
NOTIFICATION_RETENTION_DAYS=90
DECLINED_REQUEST_RETENTION_DAYS=180
```

Les listes, articles, comptes verifies, relations acceptees et partages actifs ne sont pas concernes par cette purge.

## Securite

Shoply applique notamment les mesures suivantes :

- echappement HTML des donnees utilisateur ;
- protection CSRF sur les formulaires qui modifient l'etat ;
- mots de passe hashes avec `password_hash` ;
- jetons opaques stockes sous forme de hash, a usage unique et expires ;
- sessions regenerees apres authentification et cookies `HttpOnly` / `SameSite=Lax` ;
- en-tetes HTTP de securite et politique CSP ;
- identifiants SQL et SMTP lus depuis `.env`, jamais affiches dans l'interface.

## Structure du projet

```text
index.php                 Point d'entree et actions applicatives
api/                      Endpoints PHP, dont la commande vocale
assets/css/               Feuilles de style
assets/js/                JavaScript de l'interface
includes/bootstrap.php    Environnement, session, PDO, CSRF et helpers
includes/mailer.php       Emails HTML via PHPMailer
partials/                 Fragments reutilisables de l'interface
db.sql                    Schema de donnees de reference
manifest.webmanifest      Configuration PWA
sw.js                     Service worker
PROJECT_RULES.md          Regles fonctionnelles, securite et UI
```

## Notes de developpement

- Le code applicatif reste volontairement en PHP, HTML, CSS et JavaScript natifs.
- Toute requete SQL doit passer par PDO avec une requete preparee.
- Les nouvelles vues doivent reutiliser les variables et composants de `assets/css/app.css`.
- Les secrets et fichiers d'environnement restent hors du depot Git.
