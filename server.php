<?php
declare(strict_types=1);

// Ratchet 0.4 utilise des propriétés dynamiques (dépréciées en PHP 8.2+) : on masque ces notices.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';
// Chargement explicite : fonctionne même si l'autoload PSR-4 n'est pas configuré dans composer.json.
require_once __DIR__ . '/src/ClassTree.php';
require_once __DIR__ . '/src/GameEngine.php';
require_once __DIR__ . '/src/GameServer.php';

use Diep\GameEngine;
use Diep\GameServer;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

$port = (int) (getenv('WS_PORT') ?: 8080);

$loop    = Loop::get();
$engines = ['ffa' => new GameEngine('ffa'), 'teams' => new GameEngine('teams')];
$socket  = new SocketServer("0.0.0.0:{$port}", [], $loop);

new IoServer(new HttpServer(new WsServer(new GameServer($engines))), $socket, $loop);

// Boucle de jeu : tickrate fixe de 30 Hz (33.3 ms) pour chaque salle
$stats = ['n' => 0, 'sum' => 0.0, 'max' => 0.0];
$loop->addPeriodicTimer(1 / GameEngine::TICK, static function () use ($engines, &$stats): void {
    $t0 = microtime(true);
    foreach ($engines as $mode => $engine) {
        try {
            $engine->tick();
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[tick %s] %s (%s:%d)\n", $mode, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }
    $ms = (microtime(true) - $t0) * 1000;
    $stats['n']++;
    $stats['sum'] += $ms;
    $stats['max'] = max($stats['max'], $ms);
});

// Surveillance : signale (toutes les 10 s) si les ticks approchent du budget de 33 ms
$loop->addPeriodicTimer(10.0, static function () use (&$stats): void {
    if ($stats['n'] > 0 && ($stats['max'] > 25.0 || $stats['sum'] / $stats['n'] > 15.0)) {
        fwrite(STDERR, sprintf("[perf] tick moyen %.1f ms, max %.1f ms (budget 33 ms)\n", $stats['sum'] / $stats['n'], $stats['max']));
    }
    $stats = ['n' => 0, 'sum' => 0.0, 'max' => 0.0];
});

echo "Diep.LAN : WebSocket sur ws://0.0.0.0:{$port} - salles FFA + Équipes - " . GameEngine::TICK . " Hz\n";
$loop->run();
