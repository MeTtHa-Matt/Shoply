# Shoply

Application PHP native de listes de courses partageables, avec authentification et confirmation d'adresse email.

## Demarrage local

1. Renseigner les valeurs de `.env` (ce fichier reste local et ne doit pas etre committe).
2. Importer `db.sql` dans la base configuree. Le schema est idempotent et conserve les tables existantes.
3. Demarrer le serveur depuis la racine du projet:

```bash
php -S 127.0.0.1:8080 -t .
```

4. Ouvrir `http://127.0.0.1:8080/index.php?page=login`.

L'URL de verification email est generee depuis la requete courante. Elle suit donc automatiquement le domaine, le port et le sous-dossier utilises en local ou en production.

## Structure utile

- `PROJECT_RULES.md`: regles fonctionnelles, securite et UI du projet.
- `db.sql`: tables requises par Shoply.
- `includes/bootstrap.php`: environnement, session, PDO, CSRF et helpers.
- `includes/mailer.php`: email HTML SMTP via PHPMailer.
- `manifest.webmanifest`, `sw.js`: installation et cache PWA.