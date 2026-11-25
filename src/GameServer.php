<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/** Borne WebSocket : protocole JSON compact, taille des messages entrants limitée. */
final class GameServer implements MessageComponentInterface
{
    private \SplObjectStorage $ids;
    private int $next=0;
    public function __construct(private GameEngine $engine) { $this->ids=new \SplObjectStorage(); }
    public function onOpen(ConnectionInterface $conn): void { $this->ids[$conn]=++$this->next; }
    public function onMessage(ConnectionInterface $from,$msg): void
    {
        if(!isset($this->ids[$from])||strlen((string)$msg)>1024)return;
        $d=json_decode((string)$msg,true);if(!is_array($d))return;$cid=$this->ids[$from];
        switch($d['t']??''){
            case 'j':$this->engine->join($cid,$from,is_string($d['n']??null)?$d['n']:'',is_string($d['m']??null)?$d['m']:'ffa');break;
            case 'i':$this->engine->input($cid,$d);break;
            case 's':$this->engine->upgrade($cid,(int)($d['i']??-1));break;
            case 'c':$this->engine->evolve($cid,(int)($d['c']??-1));break;
        }
    }
    public function onClose(ConnectionInterface $conn): void { if(isset($this->ids[$conn])){$this->engine->leave($this->ids[$conn]);$this->ids->detach($conn);} }
    public function onError(ConnectionInterface $conn,\Exception $e): void { $conn->close(); }
}
