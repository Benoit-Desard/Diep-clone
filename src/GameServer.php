<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * Couche réseau : décode les messages clients et les transmet au moteur.
 *
 * Protocole client -> serveur (clés courtes) :
 *   {"t":"j","n":"pseudo"}          rejoindre / rejouer
 *   {"t":"i","k":5,"a":1.57,"f":1}  inputs : k = masque (1 haut, 2 bas, 4 gauche, 8 droite), a = angle, f = tir
 *   {"t":"s","i":3}                 améliorer la statistique n°i (0-7)
 *   {"t":"c","c":2}                 choisir une classe (1 Twin, 2 Sniper, 3 Machine Gun)
 */
final class GameServer implements MessageComponentInterface
{
    private \SplObjectStorage $ids;
    private int $next = 0;

    public function __construct(private GameEngine $engine)
    {
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

        switch ($d['t'] ?? '') {
            case 'j':
                $this->engine->join($cid, $from, is_string($d['n'] ?? null) ? $d['n'] : '');
                break;
            case 'i':
                $this->engine->input($cid, $d);
                break;
            case 's':
                $this->engine->upgrade($cid, (int) ($d['i'] ?? -1));
                break;
            case 'c':
                $this->engine->evolve($cid, (int) ($d['c'] ?? 0));
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (isset($this->ids[$conn])) {
            $this->engine->leave($this->ids[$conn]);
            $this->ids->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }
}
