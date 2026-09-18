# Shoply - regles du projet

## Objectif

Shoply est une application web mobile-first de listes de courses partageables. Elle doit rester simple a comprendre, rapide a utiliser et accessible depuis un telephone comme depuis un ordinateur.

## Contraintes techniques

- PHP, HTML, CSS et JavaScript natifs uniquement dans le code applicatif.
- Aucun framework frontend ou backend.
- PHPMailer est la seule dependance applicative actuelle: il est deja fourni dans `vendor/` pour l'envoi SMTP.
- Toutes les requetes SQL passent par PDO et des requetes preparees.
- Les secrets restent dans `.env`, qui ne doit jamais etre committe.
- Le schema de donnees de reference est `db.sql`.
- L'interface doit etre responsive, utilisable au clavier et compatible avec un lecteur d'ecran.

## Regles de securite

- Echappement HTML de toute donnee utilisateur avec `htmlspecialchars`.
- Protection CSRF sur chaque formulaire qui modifie l'etat.
- Mots de passe hashes avec `password_hash` et verifies avec `password_verify`.
- Jetons de verification aleatoires, stockes uniquement sous forme de hash, a usage unique et avec expiration.
- Messages de connexion generiques afin de ne pas reveler si une adresse existe.
- Sessions regenerees apres authentification et cookies `HttpOnly`, `SameSite=Lax`.
- En-tetes de securite HTTP sur toutes les pages.
- Ne jamais afficher les identifiants SMTP ou SQL dans une reponse utilisateur.

## Regles produit

- Un utilisateur doit pouvoir creer un compte, confirmer son adresse et se connecter.
- Les liens envoyes par email doivent fonctionner en local et en production sans changement manuel: l'URL est derivee de la requete courante.
- L'application doit pouvoir etre installee en PWA depuis chaque page grace au manifest et au service worker racines.
- Les erreurs doivent rester lisibles et les actions principales visibles sans explication longue.
- Les donnees et etats vides doivent toujours avoir une issue claire pour l'utilisateur.

## Regles UI

- Design fait main, contraste eleve, palette chaude et lisible, sans dependance d'icones distante.
- Les boutons d'action ont un libelle clair; les controles purement iconographiques ont un nom accessible.
- Les animations restent discretes et sont desactivees avec `prefers-reduced-motion`.
- Les formulaires affichent leurs erreurs pres du champ concerne et conservent les valeurs non sensibles.
- Toute nouvelle vue doit reutiliser les variables CSS et les composants de `assets/css/app.css`.

## Flux email

- Les emails sont envoyes en HTML et disposent toujours d'un `AltBody` texte.
- Le HTML email utilise des styles inline et une structure compatible avec les clients mail courants.
- Le lien de verification contient un token opaque, expire apres 24 heures et est invalide apres utilisation.