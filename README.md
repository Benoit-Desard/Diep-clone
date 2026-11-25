# Diep.LAN

Clone multijoueur local inspiré de diep.io : simulation PHP autoritaire (30 Hz), serveur WebSocket Ratchet/ReactPHP et rendu Canvas 60 FPS interpolé. Les collisions, déplacements, tirs, IA, dégâts et progression sont simulés côté serveur. Les classes, formes, tirs, crashers et boss sont envoyés dans un viewport compact.

## Prérequis
- PHP 8.1+ avec `mbstring` activé
- Composer
- Navigateur moderne avec Canvas, WebSocket et Web Audio

## Installation et lancement
```bash
composer install
# Terminal 1 : serveur WebSocket (écoute sur toutes les interfaces LAN)
composer start
# Terminal 2 : interface web
composer web
```
Ouvrir `http://localhost:8000`. Les autres joueurs du réseau utilisent `http://IP_DU_SERVEUR:8000`. Autoriser les ports TCP 8000 (HTTP) et 8080 (WebSocket) dans le pare-feu. Configuration : `WS_PORT=8080 composer start`.

## Commandes et progression
ZQSD/WASD/flèches pour bouger, souris pour viser, clic gauche ou espace pour tirer, clic droit pour déposer un piège (classe Trapper) ou utiliser la capacité, 1 à 8 pour attribuer les points de compétences. Évolutions proposées par le serveur aux niveaux 15, 30 et 45, selon la classe parent. La sélection FFA/équipes se fait avant de rejoindre. En équipes, les joueurs sont distribués Bleu/Rouge, avec bases protégées et tourelles défensives.

## Classes et contenu
Tank, Twin, Sniper, Machine Gun et Flank Guard au niveau 15 ; Triplet, Quad Tank, Overseer, Assassin, Trapper, Destroyer, Gunner, Tri-Angle et Smasher au niveau 30 ; Ranger, Stalker, Overlord et Booster au niveau 45. Inclut drones pilotés par l'orientation/clic, pièges, classes de contact, carrés/triangles/pentagones, Alpha Pentagone central, Crashers agressifs, tirs de tourelles, classement, XP, soins et arbres d'évolution.

## Architecture et notes
`src/GameEngine.php` contient l'autorité de simulation, la grille spatiale et les snapshots filtrés par champ de vision. `src/GameServer.php` valide et distribue les messages JSON compacts. `server.php` lance Ratchet dans ReactPHP à 30 Hz. `public/client.js` interpole les snapshots et dessine via `requestAnimationFrame`; l'audio est synthétisé par Web Audio lorsque le joueur interagit.

Ce projet est une base jouable extensible, pas une reproduction exacte des mécaniques propriétaires ni une promesse de capacité de production. Pour une exposition hors LAN, ajouter TLS/WSS, limitation de débit, authentification, supervision et tests de charge.
