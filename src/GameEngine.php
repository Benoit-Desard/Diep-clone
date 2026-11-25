<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;

/**
 * Serveur de simulation autoritaire à tick fixe. Les coordonnées, dégâts,
 * collisions, IA et progression ne sont jamais validés par le navigateur.
 * Types réseau : 0 tank, 1 projectile, 2 carré, 3 triangle, 4 pentagone,
 * 5 alpha pentagone, 6 crasher, 7 piège, 8 drone, 9 tourelle.
 */
final class GameEngine
{
    public const TICK = 30;
    public const WORLD = 4000;
    private const CELL = 256;
    private const MAX_LEVEL = 45;
    private const VIEW_W = 1920;
    private const VIEW_H = 1080;
    private const SHAPES = [
        2 => ['hp'=>12.0,'r'=>23.0,'xp'=>12,'dmg'=>7.0,'target'=>110,'res'=>1.0],
        3 => ['hp'=>35.0,'r'=>27.0,'xp'=>30,'dmg'=>11.0,'target'=>45,'res'=>1.5],
        4 => ['hp'=>115.0,'r'=>43.0,'xp'=>150,'dmg'=>18.0,'target'=>16,'res'=>2.5],
    ];
    /** Weapon geometry/stats are server-side; client only renders class IDs. */
    private const CLASSES = [
        0=>['n'=>'Tank','parent'=>-1,'level'=>1,'reload'=>.52,'dmg'=>1,'speed'=>1,'len'=>1.75,'count'=>1,'spread'=>0,'fov'=>1,'kind'=>'gun'],
        1=>['n'=>'Twin','parent'=>0,'level'=>15,'reload'=>.48,'dmg'=>.72,'speed'=>1,'len'=>1.75,'count'=>2,'spread'=>.035,'fov'=>1,'kind'=>'gun'],
        2=>['n'=>'Sniper','parent'=>0,'level'=>15,'reload'=>.9,'dmg'=>1.8,'speed'=>1.45,'len'=>2.35,'count'=>1,'spread'=>0,'fov'=>1.35,'kind'=>'gun'],
        3=>['n'=>'Machine Gun','parent'=>0,'level'=>15,'reload'=>.2,'dmg'=>.48,'speed'=>.9,'len'=>1.65,'count'=>1,'spread'=>.2,'fov'=>1,'kind'=>'gun'],
        4=>['n'=>'Flank Guard','parent'=>0,'level'=>15,'reload'=>.58,'dmg'=>.85,'speed'=>1,'len'=>1.7,'count'=>2,'spread'=>0,'fov'=>1,'kind'=>'flank'],
        5=>['n'=>'Triplet','parent'=>1,'level'=>30,'reload'=>.48,'dmg'=>.7,'speed'=>1,'len'=>1.75,'count'=>3,'spread'=>.12,'fov'=>1,'kind'=>'fan'],
        6=>['n'=>'Quad Tank','parent'=>1,'level'=>30,'reload'=>.52,'dmg'=>.62,'speed'=>1,'len'=>1.72,'count'=>4,'spread'=>.04,'fov'=>1,'kind'=>'quad'],
        7=>['n'=>'Overseer','parent'=>1,'level'=>30,'reload'=>.8,'dmg'=>1,'speed'=>1,'len'=>1.5,'count'=>0,'spread'=>0,'fov'=>1.1,'kind'=>'drone'],
        8=>['n'=>'Assassin','parent'=>2,'level'=>30,'reload'=>1.0,'dmg'=>2.4,'speed'=>1.55,'len'=>2.6,'count'=>1,'spread'=>0,'fov'=>1.55,'kind'=>'gun'],
        9=>['n'=>'Trapper','parent'=>2,'level'=>30,'reload'=>.7,'dmg'=>1,'speed'=>1,'len'=>1.35,'count'=>1,'spread'=>0,'fov'=>1.1,'kind'=>'trap'],
        10=>['n'=>'Destroyer','parent'=>3,'level'=>30,'reload'=>1.05,'dmg'=>3.2,'speed'=>1.1,'len'=>1.8,'count'=>1,'spread'=>0,'fov'=>1,'kind'=>'gun'],
        11=>['n'=>'Gunner','parent'=>3,'level'=>30,'reload'=>.35,'dmg'=>.42,'speed'=>1,'len'=>1.65,'count'=>4,'spread'=>.16,'fov'=>1,'kind'=>'fan'],
        12=>['n'=>'Tri-Angle','parent'=>4,'level'=>30,'reload'=>.42,'dmg'=>.7,'speed'=>1.15,'len'=>1.65,'count'=>3,'spread'=>0,'fov'=>1,'kind'=>'flank'],
        13=>['n'=>'Smasher','parent'=>4,'level'=>30,'reload'=>1,'dmg'=>0,'speed'=>1,'len'=>0,'count'=>0,'spread'=>0,'fov'=>1,'kind'=>'melee'],
        14=>['n'=>'Ranger','parent'=>8,'level'=>45,'reload'=>1.05,'dmg'=>2.8,'speed'=>1.7,'len'=>2.8,'count'=>1,'spread'=>0,'fov'=>1.7,'kind'=>'gun'],
        15=>['n'=>'Stalker','parent'=>8,'level'=>45,'reload'=>1.1,'dmg'=>2.6,'speed'=>1.5,'len'=>2.6,'count'=>1,'spread'=>0,'fov'=>1.5,'kind'=>'gun'],
        16=>['n'=>'Overlord','parent'=>7,'level'=>45,'reload'=>.7,'dmg'=>1.3,'speed'=>1.2,'len'=>1.5,'count'=>0,'spread'=>0,'fov'=>1.15,'kind'=>'drone'],
        17=>['n'=>'Booster','parent'=>12,'level'=>45,'reload'=>.45,'dmg'=>.8,'speed'=>1.3,'len'=>1.6,'count'=>3,'spread'=>0,'fov'=>1,'kind'=>'flank'],
    ];

    /** Entity arrays are indexed by stable network ID. */
    private array $entities = [];
    /** Connection ID => player entity ID. */
    private array $players = [];
    private array $grid = [];
    private int $nextId = 1;
    private int $tickNo = 0;
    private int $cols;
    private float $started;
    private float $lastTime;
    private string $mode = 'ffa';

    public function __construct()
    {
        $this->cols = (int) ceil(self::WORLD / self::CELL);
        $this->started = $this->lastTime = microtime(true);
        $this->seedWorld();
    }

    public function join(int $cid, ConnectionInterface $conn, string $name, string $mode = 'ffa'): void
    {
        if (isset($this->players[$cid]) && isset($this->entities[$this->players[$cid]])) return;
        $this->mode = $mode === 'teams' ? 'teams' : 'ffa';
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        $name = mb_substr($name ?: 'Tank' . $cid, 0, 15);
        $id = $this->nextId++;
        $team = $this->mode === 'teams' ? (($cid % 2) + 1) : 0;
        $cx = $this->mode === 'teams' ? ($team === 1 ? 350.0 : self::WORLD - 350.0) : self::WORLD / 2;
        $this->entities[$id] = ['i'=>$id,'t'=>0,'x'=>$cx+($this->rand()-.5)*140,'y'=>self::WORLD/2+($this->rand()-.5)*900,'vx'=>0.0,'vy'=>0.0,'a'=>0.0,'r'=>29.0,'hp'=>60.0,'mh'=>60.0,'n'=>$name,'l'=>1,'xp'=>0,'pt'=>0,'st'=>array_fill(0,8,0),'c'=>0,'team'=>$team,'cid'=>$cid,'conn'=>$conn,'keys'=>0,'fire'=>false,'ability'=>false,'cd'=>0.0,'trapCd'=>0.0,'shield'=>90,'lastHit'=>$this->tickNo,'attacker'=>0,'barrel'=>0,'drones'=>[]];
        $this->players[$cid] = $id;
        if ($this->mode === 'teams') $this->ensureTurrets();
        $conn->send(json_encode(['t'=>'sp','id'=>$id,'w'=>self::WORLD,'mode'=>$this->mode], JSON_UNESCAPED_UNICODE));
    }

    public function leave(int $cid): void
    {
        if (!isset($this->players[$cid])) return;
        $id = $this->players[$cid]; unset($this->players[$cid], $this->entities[$id]);
        foreach ($this->entities as $eid=>&$e) if (($e['owner'] ?? 0) === $id) unset($this->entities[$eid]);
        unset($e);
    }

    /** @param array<string,mixed> $d */
    public function input(int $cid, array $d): void
    {
        $id = $this->players[$cid] ?? 0;
        if (!$id || !isset($this->entities[$id])) return;
        $p =& $this->entities[$id];
        $p['keys'] = ((int)($d['k'] ?? 0)) & 15;
        if (isset($d['a']) && (is_float($d['a']) || is_int($d['a'])) && is_finite((float)$d['a'])) $p['a'] = max(-M_PI, min(M_PI, (float)$d['a']));
        $p['fire'] = !empty($d['f']);
        $p['ability'] = !empty($d['b']);
        unset($p);
    }

    public function upgrade(int $cid, int $stat): void
    {
        $id = $this->players[$cid] ?? 0; if (!$id || !isset($this->entities[$id])) return;
        $p =& $this->entities[$id];
        if ($stat >= 0 && $stat < 8 && $p['pt'] > 0 && $p['st'][$stat] < 7) { $p['st'][$stat]++; $p['pt']--; $this->recalc($p); }
        unset($p);
    }

    public function evolve(int $cid, int $class): void
    {
        $id = $this->players[$cid] ?? 0; if (!$id || !isset($this->entities[$id], self::CLASSES[$class])) return;
        $p =& $this->entities[$id]; $def = self::CLASSES[$class];
        if ($p['l'] >= $def['level'] && $p['c'] === $def['parent']) {
            $p['c'] = $class;
            if ($def['kind'] === 'melee') $p['r'] *= 1.15;
            if ($def['kind'] === 'drone') $this->createDrones($p, $class === 16 ? 8 : 4);
        }
        unset($p);
    }

    public function tick(): void
    {
        $now = microtime(true); $dt = min(.08, max(.005, $now - $this->lastTime)); $this->lastTime = $now; $this->tickNo++;
        $this->updatePlayers($dt);
        $this->updateAi($dt);
        $this->updateBullets($dt);
        $this->buildGrid();
        $this->collideBodies();
        $this->collideBullets();
        $this->resolveDeaths();
        $this->spawnShapes();
        $this->broadcast($now);
    }

    private function seedWorld(): void
    {
        foreach (self::SHAPES as $type=>$def) for ($i=0;$i<$def['target'];$i++) $this->spawnShape($type);
        // Alpha Pentagon: unique boss in the central arena.
        $this->entities[$this->nextId++] = ['i'=>$this->nextId-1,'t'=>5,'x'=>self::WORLD/2,'y'=>self::WORLD/2,'vx'=>0.0,'vy'=>0.0,'a'=>0.0,'r'=>115.0,'hp'=>8000.0,'mh'=>8000.0,'xp'=>5000,'attacker'=>0];
        for ($i=0;$i<9;$i++) { $a=$i*M_PI*2/9; $this->entities[$this->nextId] = ['i'=>$this->nextId,'t'=>6,'x'=>2000+cos($a)*580,'y'=>2000+sin($a)*580,'vx'=>0.0,'vy'=>0.0,'a'=>$a,'r'=>16.0,'hp'=>45.0,'mh'=>45.0,'xp'=>45,'attacker'=>0,'target'=>0]; $this->nextId++; }
    }

    private function spawnShape(int $type): void
    {
        $d=self::SHAPES[$type]; $a=$this->rand()*M_PI*2; $dist=$type===4?900*$this->rand():($type===3?450+1100*sqrt($this->rand()):0);
        $x=$type===2?80+$this->rand()*(self::WORLD-160):self::WORLD/2+cos($a)*$dist;
        $y=$type===2?80+$this->rand()*(self::WORLD-160):self::WORLD/2+sin($a)*$dist;
        $id=$this->nextId++;
        $this->entities[$id]=['i'=>$id,'t'=>$type,'x'=>$x,'y'=>$y,'vx'=>($this->rand()-.5)*12,'vy'=>($this->rand()-.5)*12,'a'=>$this->rand()*6.28,'r'=>$d['r'],'hp'=>$d['hp'],'mh'=>$d['hp'],'xp'=>$d['xp'],'attacker'=>0];
    }

    private function updatePlayers(float $dt): void
    {
        foreach ($this->players as $cid=>$id) {
            if (!isset($this->entities[$id])) continue;
            $p =& $this->entities[$id];
            $ax=(($p['keys']&8)?1:0)-(($p['keys']&4)?1:0); $ay=(($p['keys']&2)?1:0)-(($p['keys']&1)?1:0);
            if ($ax && $ay) { $ax*=M_SQRT1_2; $ay*=M_SQRT1_2; }
            $k=5.0; $kind=self::CLASSES[$p['c']]['kind']; $boost=$kind==='melee'?1.65:1.0;
            $p['vx']=($p['vx']+$ax*(250+24*$p['st'][7])*$k*$boost*$dt)*exp(-$k*$dt);
            $p['vy']=($p['vy']+$ay*(250+24*$p['st'][7])*$k*$boost*$dt)*exp(-$k*$dt);
            $p['x']=max($p['r'],min(self::WORLD-$p['r'],$p['x']+$p['vx']*$dt)); $p['y']=max($p['r'],min(self::WORLD-$p['r'],$p['y']+$p['vy']*$dt));
            if ($p['shield']>0) $p['shield']--;
            if ($p['hp']<$p['mh'] && $this->tickNo-$p['lastHit']>90) $p['hp']=min($p['mh'],$p['hp']+$p['mh']*(.01+.012*$p['st'][0])*$dt);
            $p['cd']-=$dt; $p['trapCd']-=$dt;
            if ($p['fire'] && $p['cd']<=0) { $p['shield']=0; $this->shoot($p); $p['cd']=max(.1,self::CLASSES[$p['c']]['reload']*(.87**$p['st'][6])); }
            if ($p['ability'] && $p['trapCd']<=0 && in_array($kind,['trap','melee'],true)) { $p['shield']=0; $this->placeTrap($p); $p['trapCd']=.7; }
            unset($p);
        }
    }

    private function shoot(array &$p): void
    {
        $d=self::CLASSES[$p['c']]; $kind=$d['kind'];
        if ($kind==='drone' || $kind==='melee') return;
        if ($kind==='trap') { $this->placeTrap($p); return; }
        $count=$d['count'];
        for ($i=0;$i<$count;$i++) {
            $angle=$p['a'];
            if ($kind==='fan') $angle+=(($i-($count-1)/2)*$d['spread'])+($this->rand()-.5)*.09;
            elseif ($kind==='flank' && $i===1) $angle+=M_PI;
            elseif ($kind==='quad') $angle+=($i*M_PI/2);
            elseif ($kind==='gun' && $count===2) $angle+=($i===0?-.035:.035);
            elseif ($kind==='flank' && $i===0) $angle=$p['a'];
            else $angle+=($this->rand()-.5)*$d['spread'];
            $cs=cos($angle);$sn=sin($angle);$id=$this->nextId++;
            $speed=(550+70*$p['st'][3])*$d['speed'];
            $this->entities[$id]=['i'=>$id,'t'=>1,'x'=>$p['x']+$cs*$p['r']*$d['len'],'y'=>$p['y']+$sn*$p['r']*$d['len'],'vx'=>$cs*$speed,'vy'=>$sn*$speed,'a'=>$angle,'r'=>$p['r']*.34,'hp'=>1.0,'mh'=>1.0,'dmg'=>(7+3*$p['st'][5])*$d['dmg'],'pen'=>1+.8*$p['st'][4],'life'=>1.8*$d['speed'],'owner'=>$p['i'],'team'=>$p['team'],'hit'=>[]];
        }
        $p['vx']-=cos($p['a'])*($d['dmg']>2?75:30); $p['vy']-=sin($p['a'])*($d['dmg']>2?75:30);
    }

    private function placeTrap(array &$p): void
    {
        $id=$this->nextId++;$x=$p['x']+cos($p['a'])*($p['r']+25);$y=$p['y']+sin($p['a'])*($p['r']+25);
        $this->entities[$id]=['i'=>$id,'t'=>7,'x'=>$x,'y'=>$y,'vx'=>0.0,'vy'=>0.0,'a'=>$p['a'],'r'=>19.0,'hp'=>65.0,'mh'=>65.0,'xp'=>12,'owner'=>$p['i'],'team'=>$p['team'],'life'=>12.0,'attacker'=>0];
    }

    private function createDrones(array &$p,int $count): void
    {
        for($i=0;$i<$count;$i++){ $id=$this->nextId++;$a=$i*2*M_PI/$count;$this->entities[$id]=['i'=>$id,'t'=>8,'x'=>$p['x'],'y'=>$p['y'],'vx'=>0.0,'vy'=>0.0,'a'=>$a,'r'=>11.0,'hp'=>20.0,'mh'=>20.0,'owner'=>$p['i'],'team'=>$p['team'],'slot'=>$i,'dmg'=>14.0];$p['drones'][]=$id; }
    }

    private function updateAi(float $dt): void
    {
        foreach ($this->entities as $id=>&$e) {
            if ($e['t']===8) {
                $owner=$this->entities[$e['owner']]??null; if (!$owner) { unset($this->entities[$id]); continue; }
                $desiredA=$owner['a']+$e['slot']*M_PI/2+($owner['fire']?0:M_PI/2);
                $radius=($owner['fire']?230:95);$tx=$owner['x']+cos($desiredA)*$radius;$ty=$owner['y']+sin($desiredA)*$radius;
                $e['vx']=($tx-$e['x'])*2.8;$e['vy']=($ty-$e['y'])*2.8;$e['x']+=$e['vx']*$dt;$e['y']+=$e['vy']*$dt;
            } elseif ($e['t']===6) {
                $best=0;$bd=700*700;
                foreach($this->players as $pid){$p=$this->entities[$pid]??null;if(!$p||($p['team']!==0&&$e['team']??false))continue;$dx=$p['x']-$e['x'];$dy=$p['y']-$e['y'];$q=$dx*$dx+$dy*$dy;if($q<$bd){$bd=$q;$best=$pid;}}
                if($best){$p=$this->entities[$best];$a=atan2($p['y']-$e['y'],$p['x']-$e['x']);$e['vx']+=cos($a)*220*$dt;$e['vy']+=sin($a)*220*$dt;$e['attacker']=$best;}
                else{$e['a']+=.5*$dt;$e['vx']+=cos($e['a'])*35*$dt;$e['vy']+=sin($e['a'])*35*$dt;}
                $e['vx']*=exp(-1.5*$dt);$e['vy']*=exp(-1.5*$dt);$e['x']+=$e['vx']*$dt;$e['y']+=$e['vy']*$dt;
            } elseif ($e['t']===1 || $e['t']===7) {
                if(isset($e['life'])){$e['life']-=$dt;if($e['life']<=0)unset($this->entities[$id]);}
                if($e['t']===1){$e['x']+=$e['vx']*$dt;$e['y']+=$e['vy']*$dt;$e['life']-=$dt;if($e['life']<=0||$e['x']<0||$e['y']<0||$e['x']>self::WORLD||$e['y']>self::WORLD)unset($this->entities[$id]);}
            } elseif ($e['t']>=2 && $e['t']<=5) { $e['x']+=$e['vx']*$dt;$e['y']+=$e['vy']*$dt;$e['vx']*=exp(-2*$dt);$e['vy']*=exp(-2*$dt);$e['a']+=.35*$dt; }
        }
        unset($e);
        if($this->mode==='teams')$this->updateTurrets($dt);
    }

    private function ensureTurrets(): void
    {
        foreach($this->entities as $e)if($e['t']===9)return;
        foreach([1,2] as $team){$x=$team===1?150:3850;for($j=0;$j<3;$j++){$id=$this->nextId++;$this->entities[$id]=['i'=>$id,'t'=>9,'x'=>$x,'y'=>1400+$j*600,'vx'=>0.0,'vy'=>0.0,'a'=>0.0,'r'=>32.0,'hp'=>500.0,'mh'=>500.0,'team'=>$team,'cd'=>0.0];}}
    }

    private function updateTurrets(float $dt): void
    {
        foreach($this->entities as $id=>&$turret){if($turret['t']!==9)continue;$turret['cd']-=$dt;if($turret['cd']>0)continue;$target=0;$best=500*500;
            foreach($this->players as $pid){$p=$this->entities[$pid]??null;if(!$p||$p['team']===$turret['team'])continue;$dx=$p['x']-$turret['x'];$dy=$p['y']-$turret['y'];$d=$dx*$dx+$dy*$dy;if($d<$best){$best=$d;$target=$pid;}}
            if($target){$p=$this->entities[$target];$a=atan2($p['y']-$turret['y'],$p['x']-$turret['x']);$turret['a']=$a;$bid=$this->nextId++;$this->entities[$bid]=['i'=>$bid,'t'=>1,'x'=>$turret['x'],'y'=>$turret['y'],'vx'=>cos($a)*650,'vy'=>sin($a)*650,'a'=>$a,'r'=>8.0,'hp'=>1,'mh'=>1,'dmg'=>12.0,'pen'=>1.0,'life'=>1.0,'owner'=>$id,'team'=>$turret['team'],'hit'=>[]];$turret['cd']=.8;}}
        unset($turret);
    }

    private function buildGrid(): void
    {
        $this->grid=[];
        foreach($this->entities as $id=>$e)if($e['t']!==1)$this->grid[$this->cellOf($e['x'],$e['y'])][]=$id;
    }
    private function cellOf(float $x,float $y): int {return max(0,min($this->cols-1,(int)($y/self::CELL)))*$this->cols+max(0,min($this->cols-1,(int)($x/self::CELL)));}
    private function query(float $x,float $y,float $r): array
    {
        $m=$r+125;$x0=max(0,(int)(($x-$m)/self::CELL));$x1=min($this->cols-1,(int)(($x+$m)/self::CELL));$y0=max(0,(int)(($y-$m)/self::CELL));$y1=min($this->cols-1,(int)(($y+$m)/self::CELL));$out=[];
        for($cy=$y0;$cy<=$y1;$cy++)for($cx=$x0;$cx<=$x1;$cx++)foreach($this->grid[$cy*$this->cols+$cx]??[] as $id)$out[]=$id;
        return $out;
    }

    private function collideBodies(): void
    {
        foreach($this->entities as $id=>$p){if($p['t']!==0)continue;foreach($this->query($p['x'],$p['y'],$p['r']) as $oid){if($oid===$id||!isset($this->entities[$oid]))continue;$o=$this->entities[$oid];if(!in_array($o['t'],[0,2,3,4,5,6,7,8,9],true)||($o['t']===0&&$oid<$id))continue;
            $dx=$o['x']-$p['x'];$dy=$o['y']-$p['y'];$rr=$p['r']+$o['r'];$d2=$dx*$dx+$dy*$dy;if($d2>=$rr*$rr)continue;$d=max(1,sqrt($d2));$nx=$dx/$d;$ny=$dy/$d;$over=$rr-$d;
            if($o['t']===0){$this->damage($oid,.6,$id);$this->damage($id,.6,$oid);}else{$this->damage($oid,in_array($p['c'],[13,17],true)?18:5,$id);if($o['t']===6||$o['t']>=2&&$o['t']<=5)$this->damage($id,$o['t']===6?10:(self::SHAPES[$o['t']]['dmg']??40),$oid);}
            $this->entities[$id]['x']-=$nx*$over*.4;$this->entities[$id]['y']-=$ny*$over*.4;$this->entities[$id]['vx']-=$nx*$over*3;$this->entities[$id]['vy']-=$ny*$over*3;
            if(isset($this->entities[$oid])&&$o['t']!==9){$this->entities[$oid]['x']+=$nx*$over*.6;$this->entities[$oid]['y']+=$ny*$over*.6;}
        }}
    }

    private function collideBullets(): void
    {
        foreach($this->entities as $bid=>$b){if($b['t']!==1||!isset($b['dmg']))continue;foreach($this->query($b['x'],$b['y'],$b['r']) as $tid){if($tid===$bid||!isset($this->entities[$tid])||isset($this->entities[$bid]['hit'][$tid]))continue;$t=$this->entities[$tid];if($t['t']===1||$t['t']===8||($t['t']===0&&($t['i']===$b['owner']||($b['team']&&$t['team']===$b['team'])) )||($t['t']===9&&($t['team']??0)===$b['team']))continue;
            $dx=$t['x']-$b['x'];$dy=$t['y']-$b['y'];$rr=$t['r']+$b['r'];if($dx*$dx+$dy*$dy>$rr*$rr)continue;$this->entities[$bid]['hit'][$tid]=true;$this->damage($tid,$b['dmg'],$b['owner']);$res=$t['t']===0?2:(self::SHAPES[$t['t']]['res']??1);$this->entities[$bid]['pen']-=$res;if($this->entities[$bid]['pen']<=0)unset($this->entities[$bid]);break;
        }}
    }

    private function damage(int $id,float $amount,int $attacker=0): void
    {
        if(!isset($this->entities[$id]))return;$e=&$this->entities[$id];if($e['t']===0&&$e['shield']>0)return;
        // Team bases are protected: enemies cannot damage players close to their own spawn.
        if($e['t']===0&&$this->mode==='teams'&&$e['team']!==0){$base=$e['team']===1?350:3650;if(abs($e['x']-$base)<260&&$attacker&&isset($this->entities[$attacker])&&($this->entities[$attacker]['team']??0)!==$e['team'])return;}
        $e['hp']-=$amount;if($attacker){$e['attacker']=$attacker;if($e['t']===0)$e['lastHit']=$this->tickNo;}unset($e);
    }

    private function resolveDeaths(): void
    {
        foreach($this->entities as $id=>$e){if(($e['hp']??1)>0)continue;$killer=$this->entities[$e['attacker']??0]??null;
            if($e['t']===0){if($killer&&$killer['t']===0)$this->addXp($killer['i'],(int)($e['xp']/2)+40);$conn=$e['conn'];$conn->send(json_encode(['t'=>'d','k'=>$killer['n']??'un ennemi','l'=>$e['l'],'s'=>$e['xp']],JSON_UNESCAPED_UNICODE));unset($this->players[$e['cid']]);
            }elseif($e['t']!==1&&$e['t']!==8&&$e['t']!==9&&$killer&&$killer['t']===0)$this->addXp($killer['i'],(int)($e['xp']??10));
            unset($this->entities[$id]);
        }
    }

    private function addXp(int $id,int $xp): void
    {
        if(!isset($this->entities[$id]))return;$p=&$this->entities[$id];$p['xp']+=$xp;
        while($p['l']<self::MAX_LEVEL&&$p['xp']>=floor(8*($p['l']**1.8))){$p['l']++;$p['pt']++;}
        $this->recalc($p);unset($p);
    }
    private function recalc(array &$p): void { $old=$p['mh'];$p['mh']=60+2*($p['l']-1)+20*$p['st'][1];$p['hp']=min($p['mh'],$p['hp']+max(0,$p['mh']-$old));$p['r']=29+.35*$p['l']+(in_array($p['c'],[13,17],true)?8:0); }

    private function spawnShapes(): void
    {
        foreach(self::SHAPES as $type=>$def){$count=0;foreach($this->entities as $e)if($e['t']===$type)$count++;if($count<$def['target']&&$this->rand()<.12)$this->spawnShape($type);}
        $crashers=0;foreach($this->entities as $e)if($e['t']===6)$crashers++;if($crashers<12&&$this->rand()<.025){$a=$this->rand()*M_PI*2;$id=$this->nextId++;$this->entities[$id]=['i'=>$id,'t'=>6,'x'=>2000+cos($a)*760,'y'=>2000+sin($a)*760,'vx'=>0.0,'vy'=>0.0,'a'=>$a,'r'=>16.0,'hp'=>45.0,'mh'=>45.0,'xp'=>45,'attacker'=>0];}
    }

    private function broadcast(float $now): void
    {
        $ts=(int)(($now-$this->started)*1000);$lb=$this->tickNo%6===0?$this->leaderboard():null;
        foreach($this->players as $cid=>$pid){$p=$this->entities[$pid]??null;if(!$p)continue;$fov=self::CLASSES[$p['c']]['fov'];$hw=self::VIEW_W/2*$fov+160;$hh=self::VIEW_H/2*$fov+160;$x0=$p['x']-$hw;$x1=$p['x']+$hw;$y0=$p['y']-$hh;$y1=$p['y']+$hh;$ents=[];
            $cx0=max(0,(int)($x0/self::CELL));$cx1=min($this->cols-1,(int)($x1/self::CELL));$cy0=max(0,(int)($y0/self::CELL));$cy1=min($this->cols-1,(int)($y1/self::CELL));$seen=[];
            for($cy=$cy0;$cy<=$cy1;$cy++)for($cx=$cx0;$cx<=$cx1;$cx++)foreach($this->grid[$cy*$this->cols+$cx]??[] as $eid){if(isset($seen[$eid])||!isset($this->entities[$eid]))continue;$seen[$eid]=true;$e=$this->entities[$eid];if($e['x']<$x0||$e['x']>$x1||$e['y']<$y0||$e['y']>$y1)continue;$ents[]=$this->packEntity($e);}
            foreach($this->entities as $e)if($e['t']===1&&$e['x']>=$x0&&$e['x']<=$x1&&$e['y']>=$y0&&$e['y']<=$y1)$ents[]=$this->packEntity($e);
            $lo=(int)floor(8*max(0,$p['l']-1)**1.8);$nx=$p['l']<self::MAX_LEVEL?(int)floor(8*$p['l']**1.8):$lo;
            $m=['t'=>'u','ts'=>$ts,'e'=>$ents,'me'=>['x'=>(int)$p['x'],'y'=>(int)$p['y'],'hp'=>(int)$p['hp'],'mh'=>(int)$p['mh'],'xp'=>$p['xp'],'lo'=>$lo,'nx'=>$nx,'lv'=>$p['l'],'pt'=>$p['pt'],'st'=>$p['st'],'c'=>$p['c'],'cc'=>$this->choices($p),'fv'=>$fov,'mode'=>$this->mode,'team'=>$p['team']]];
            if($lb!==null)$m['lb']=$lb;$p['conn']->send(json_encode($m,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    private function packEntity(array $e): array
    {
        $out=['i'=>$e['i'],'t'=>$e['t'],'x'=>(int)$e['x'],'y'=>(int)$e['y'],'a'=>round($e['a']??0,2),'r'=>round($e['r'],1),'hp'=>isset($e['mh'])?(int)max(0,100*$e['hp']/$e['mh']):100];
        foreach(['n','c','l','team','owner'] as $k)if(isset($e[$k]))$out[$k]=$e[$k];return $out;
    }
    private function choices(array $p): array { $out=[];foreach(self::CLASSES as $id=>$d)if($d['parent']===$p['c']&&$d['level']<=$p['l'])$out[]=['id'=>$id,'name'=>$d['n'],'level'=>$d['level']];return $out; }
    private function leaderboard(): array { $rows=[];foreach($this->players as $id){$p=$this->entities[$id]??null;if($p)$rows[]=$p;}usort($rows,static fn($a,$b)=>$b['xp']<=>$a['xp']);return array_map(static fn($p)=>[$p['n'],$p['xp'],$p['i'],$p['team']],array_slice($rows,0,8)); }
    private function rand(): float { return mt_rand()/mt_getrandmax(); }
}
