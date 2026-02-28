# PartyQuest

Jeu d'alcool mobile-first en PHP, pensé pour les soirées entre potes.

Projet réalisé par **stormcln**.

## Ce que fait l'app

- Comptes joueurs + administration
- Système de points, succès et objets
- Fil de posts (photos/vidéos) + likes
- Soirées avec carte et consultation des contenus
- Profils personnalisables
- PWA installable sur iPhone et Android (écran d'accueil)

## Stack

- PHP 
- Stockage JSON local dans `data/app_data.json`
- Uploads médias dans `uploads/`
- Leaflet + OpenStreetMap pour la carte
- PWA via `manifest.webmanifest` + `service-worker.js`

## Configuration obligatoire

1. Vérifier que ces fichiers existent à la racine :
   - `index.php`
   - `.htaccess`
   - `manifest.webmanifest`
   - `service-worker.js`
   - `logo.png`
2. Vérifier les droits d'écriture :
   - `data/`
   - `uploads/`
3. Vérifier le logo PWA :
   - Fichier : `logo.png`
   - Format recommandé : PNG carré (min 512x512)
   - Utilisé pour l'icône web + ajout à l'écran d'accueil

## Installation locale rapide

```bash
php -S 127.0.0.1:8080
```

Puis ouvrir `http://127.0.0.1:8080`.

## Déploiement Plesk

1. Uploader le contenu du repo dans `httpdocs`.
2. Activer PHP pour le domaine.
3. Laisser `DirectoryIndex` sur `index.php` (via `.htaccess` déjà fourni).
4. Vérifier que `mod_rewrite` est actif.
5. Donner les permissions d'écriture à `data/` et `uploads/`.
6. Vider le cache navigateur/service worker après mise à jour.

## Compte par défaut

- Identifiant : `admin`
- Mot de passe initial : `admin`

Pense à changer le mot de passe après le premier login.
