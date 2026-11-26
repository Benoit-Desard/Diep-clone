<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * Couche réseau : héberge plusieurs salles (modes de jeu) et route les messages vers le bon moteur.
 *
 * Client -> serveur (clés courtes) :
 *   {"t":"j","n":"pseudo","m":"ffa"|"teams"}  rejoindre / rejouer dans un mode
 *   {"t":"i","k":5,"a":1.57,"d":300,"f":1,"g":0}  inputs : k masque (1 haut, 2 bas, 4 gauche, 8 droite),
 *                                                 a angle, d distance curseur, f tir (clic gauche), g clic droit
 *   {"t":"s","i":3}   améliorer la compétence n°i (0-7)
 *   {"t":"c","c":12}  choisir la classe d'id c
 *   {"t":"z"}         activer/désactiver la rotation automatique
 */
final class GameServer implements MessageComponentInterface
{
    /** @var array<string,GameEngine> */
    private array $engines;
    private \SplObjectStorage $ids;
    /** @var array<int,string> cid => mode de la salle */
    private array $room = [];
    private int $next = 0;

    /** @param array<string,GameEngine> $engines */
    public function __construct(array $engines)
    {
        $this->engines = $engines;
        $this->ids = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->ids[$conn] = ++$this->next;
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        if (!isset($this->ids[$from]) || strlen((string) $msg) > 512) {
            return;
        }
        $d = json_decode((string) $msg, true);
        if (!is_array($d)) {
            return;
        }
        $cid = $this->ids[$from];

        if (($d['t'] ?? '') === 'j') {
            $mode = ($d['m'] ?? 'ffa') === 'teams' ? 'teams' : 'ffa';
            if (isset($this->room[$cid]) && $this->room[$cid] !== $mode) {
                $this->engines[$this->room[$cid]]->leave($cid);
            }
            $this->room[$cid] = $mode;
            $this->engines[$mode]->join($cid, $from, is_string($d['n'] ?? null) ? $d['n'] : '');
            return;
        }

        $engine = isset($this->room[$cid]) ? $this->engines[$this->room[$cid]] : null;
        if ($engine === null) {
            return;
        }
        switch ($d['t'] ?? '') {
            case 'i': $engine->input($cid, $d); break;
            case 's': $engine->upgrade($cid, (int) ($d['i'] ?? -1)); break;
            case 'c': $engine->evolve($cid, (int) ($d['c'] ?? -1)); break;
            case 'z': $engine->toggleSpin($cid); break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (isset($this->ids[$conn])) {
            $cid = $this->ids[$conn];
            if (isset($this->room[$cid])) {
                $this->engines[$this->room[$cid]]->leave($cid);
                unset($this->room[$cid]);
            }
            $this->ids->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }
}
