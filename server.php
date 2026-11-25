<?php
declare(strict_types=1);

// Ratchet 0.4 utilise des propriétés dynamiques (dépréciées en PHP 8.2+) : on masque ces notices.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';

use Diep\GameEngine;
use Diep\GameServer;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

$port = (int) (getenv('WS_PORT') ?: 8080);

$loop   = Loop::get();
$engine = new GameEngine();
$socket = new SocketServer("0.0.0.0:{$port}", [], $loop);

new IoServer(new HttpServer(new WsServer(new GameServer($engine))), $socket, $loop);

// Boucle de jeu : tickrate fixe de 30 Hz (33.3 ms)
$loop->addPeriodicTimer(1 / GameEngine::TICK, static function () use ($engine): void {
    try {
        $engine->tick();
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[tick] %s (%s:%d)\n", $e->getMessage(), $e->getFile(), $e->getLine()));
    }
});

echo "Diep.LAN : WebSocket sur ws://0.0.0.0:{$port} - tickrate " . GameEngine::TICK . " Hz\n";
$loop->run();
