<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;

/** Serveur autoritaire de l'arène et des évolutions. */
final class GameEngine
{
    public const TICK = 30;
    public const WORLD = 4000;
    private const MAX_LEVEL = 45;

    /** Geometry is expressed as local barrel angles; all combat values stay server-side. */
    private const CLASSES = [
        0=>['n'=>'Basic Tank','p'=>-1,'l'=>1,'reload'=>.52,'damage'=>1.0,'speed'=>1.0,'pen'=>1.0,'recoil'=>30,'fov'=>1.0,'kind'=>'gun','barrels'=>[0]],
        1=>['n'=>'Twin','p'=>0,'l'=>15,'reload'=>.48,'damage'=>.72,'speed'=>1.0,'pen'=>1.0,'recoil'=>24,'fov'=>1.0,'kind'=>'gun','barrels'=>[-.035,.035]],
        2=>['n'=>'Sniper','p'=>0,'l'=>15,'reload'=>.9,'damage'=>1.8,'speed'=>1.45,'pen'=>1.8,'recoil'=>42,'fov'=>1.35,'kind'=>'gun','barrels'=>[0]],
        3=>['n'=>'Machine Gun','p'=>0,'l'=>15,'reload'=>.2,'damage'=>.48,'speed'=>.9,'pen'=>.8,'recoil'=>14,'fov'=>1.0,'kind'=>'spread','barrels'=>[0]],
        4=>['n'=>'Flank Guard','p'=>0,'l'=>15,'reload'=>.58,'damage'=>.85,'speed'=>1.0,'pen'=>1.0,'recoil'=>22,'fov'=>1.0,'kind'=>'gun','barrels'=>[0,3.14159]],
        5=>['n'=>'Triplet','p'=>1,'l'=>30,'reload'=>.48,'damage'=>.7,'speed'=>1.0,'pen'=>1.1,'recoil'=>24,'fov'=>1.0,'kind'=>'gun','barrels'=>[-.12,0,.12]],
        6=>['n'=>'Quad Tank','p'=>1,'l'=>30,'reload'=>.52,'damage'=>.62,'speed'=>1.0,'pen'=>1.0,'recoil'=>22,'fov'=>1.0,'kind'=>'gun','barrels'=>[0,1.5708,3.14159,4.71239]],
        7=>['n'=>'Twin Flank','p'=>1,'l'=>30,'reload'=>.52,'damage'=>.62,'speed'=>1.0,'pen'=>1.0,'recoil'=>22,'fov'=>1.0,'kind'=>'gun','barrels'=>[0,.05,3.14159,3.19159]],
        8=>['n'=>'Assassin','p'=>2,'l'=>30,'reload'=>1.0,'damage'=>2.4,'speed'=>1.55,'pen'=>2.2,'recoil'=>48,'fov'=>1.55,'kind'=>'gun','barrels'=>[0]],
        9=>['n'=>'Overseer','p'=>2,'l'=>30,'reload'=>.8,'damage'=>1.0,'speed'=>1.0,'pen'=>1.0,'recoil'=>0,'fov'=>1.1,'kind'=>'drone','barrels'=>[]],
        10=>['n'=>'Trapper','p'=>2,'l'=>30,'reload'=>.7,'damage'=>1.0,'speed'=>1.0,'pen'=>1.0,'recoil'=>0,'fov'=>1.1,'kind'=>'trap','barrels'=>[0]],
        11=>['n'=>'Destroyer','p'=>3,'l'=>30,'reload'=>1.05,'damage'=>3.2,'speed'=>1.1,'pen'=>3.0,'recoil'=>85,'fov'=>1.0,'kind'=>'gun','barrels'=>[0]],
        12=>['n'=>'Gunner','p'=>3,'l'=>30,'reload'=>.35,'damage'=>.42,'speed'=>1.0,'pen'=>.8,'recoil'=>18,'fov'=>1.0,'kind'=>'gun','barrels'=>[-.16,-.05,.05,.16]],
        13=>['n'=>'Tri-Angle','p'=>4,'l'=>30,'reload'=>.42,'damage'=>.7,'speed'=>1.15,'pen'=>1.0,'recoil'=>36,'fov'=>1.0,'kind'=>'gun','barrels'=>[0,2.55,3.73]],
        14=>['n'=>'Ranger','p'=>8,'l'=>45,'reload'=>1.05,'damage'=>2.8,'speed'=>1.7,'pen'=>2.6,'recoil'=>50,'fov'=>1.7,'kind'=>'gun','barrels'=>[0]],
        15=>['n'=>'Stalker','p'=>8,'l'=>45,'reload'=>1.1,'damage'=>2.6,'speed'=>1.5,'pen'=>2.4,'recoil'=>45,'fov'=>1.5,'kind'=>'gun','barrels'=>[0]],
        16=>['n'=>'Overlord','p'=>9,'l'=>45,'reload'=>.7,'damage'=>1.3,'speed'=>1.2,'pen'=>1.2,'recoil'=>0,'fov'=>1.15,'kind'=>'drone','barrels'=>[]],
        17=>['n'=>'Manager','p'=>9,'l'=>45,'reload'=>.8,'damage'=>1.5,'speed'=>1.25,'pen'=>1.4,'recoil'=>0,'fov'=>1.2,'kind'=>'drone','barrels'=>[]],
        18=>['n'=>'Annihilator','p'=>11,'l'=>45,'reload'=>1.15,'damage'=>3.8,'speed'=>1.15,'pen'=>3.5,'recoil'=>105,'fov'=>1.0,'kind'=>'gun','barrels'=>[0]],
        19=>['n'=>'Hybrid','p'=>11,'l'=>45,'reload'=>1.05,'damage'=>3.3,'speed'=>1.1,'pen'=>3.2,'recoil'=>90,'fov'=>1.0,'kind'=>'hybrid','barrels'=>[0]],
        20=>['n'=>'Fighter','p'=>13,'l'=>45,'reload'=>.4,'damage'=>.72,'speed'=>1.35,'pen'=>1.0,'recoil'=>38,'fov'=>1.0,'kind'=>'gun','barrels'=>[0,2.55,3.73]],
        21=>['n'=>'Booster','p'=>13,'l'=>45,'reload'=>.42,'damage'=>.68,'speed'=>1.4,'pen'=>1.0,'recoil'=>42,'fov'=>1.0,'kind'=>'gun','barrels'=>[0,2.45,3.83]],
    ];

    private array $players=[];
    private array $entities=[];
    private int $nextId=1;
    private int $tickNo=0;
    private float $started;
    private float $lastTime;
    private string $mode='ffa';

    public function __construct() { $this->started=$this->lastTime=microtime(true); }

    public function join(int $cid, ConnectionInterface $conn, string $name, string $mode='ffa'): void
    {
        if (isset($this->players[$cid]) && isset($this->entities[$this->players[$cid]])) return;
        $this->mode=$mode==='teams'?'teams':'ffa';
        $name=mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u','',$name))?:'Tank'.$cid,0,15);
        $id=$this->nextId++; $team=$this->mode==='teams'?(($cid%2)+1):0;
        $x=$this->mode==='teams'?($team===1?350:3650):self::WORLD/2;
        $this->entities[$id]=['i'=>$id,'t'=>0,'x'=>(float)$x,'y'=>2000.0,'vx'=>0.0,'vy'=>0.0,'a'=>0.0,'r'=>29.0,'hp'=>60.0,'mh'=>60.0,'n'=>$name,'l'=>1,'xp'=>0,'pt'=>0,'st'=>array_fill(0,8,0),'c'=>0,'team'=>$team,'conn'=>$conn,'cid'=>$cid,'keys'=>0,'fire'=>false,'ability'=>false,'cd'=>0.0,'lastHit'=>0,'drones'=>[]];
        $this->players[$cid]=$id;
        $conn->send(json_encode(['t'=>'sp','id'=>$id,'w'=>self::WORLD,'mode'=>$this->mode]));
    }

    public function leave(int $cid): void
    {
        $id=$this->players[$cid]??0; unset($this->players[$cid],$this->entities[$id]);
        foreach ($this->entities as $eid=>$e) if (($e['owner']??0)===$id) unset($this->entities[$eid]);
    }

    public function input(int $cid,array $d): void
    {
        $id=$this->players[$cid]??0; if (!$id||!isset($this->entities[$id])) return;
        $p=&$this->entities[$id]; $p['keys']=((int)($d['k']??0))&15;
        if (isset($d['a'])&&is_numeric($d['a'])&&is_finite((float)$d['a'])) $p['a']=max(-M_PI,min(M_PI,(float)$d['a']));
        $p['fire']=!empty($d['f']); $p['ability']=!empty($d['b']); unset($p);
    }

    public function upgrade(int $cid,int $stat): void
    {
        $id=$this->players[$cid]??0; if (!$id||!isset($this->entities[$id])) return;
        $p=&$this->entities[$id]; if ($stat>=0&&$stat<8&&$p['pt']>0&&$p['st'][$stat]<7) {$p['st'][$stat]++;$p['pt']--;}
        unset($p);
    }

    public function evolve(int $cid,int $class): void
    {
        $id=$this->players[$cid]??0; if (!$id||!isset($this->entities[$id],self::CLASSES[$class])) return;
        $p=&$this->entities[$id];$d=self::CLASSES[$class];
        if ($p['l']<$d['l']||$p['c']!==$d['p']) {unset($p);return;}
        foreach ($p['drones'] as $drone) unset($this->entities[$drone]);
        $p['drones']=[];$p['c']=$class;$p['cd']=0;
        if ($d['kind']==='drone') $this->createDrones($p,$class===17?8:4);
        unset($p);
    }

    private function createDrones(array &$p,int $count): void
    {
        for($i=0;$i<$count;$i++) {$id=$this->nextId++;$this->entities[$id]=['i'=>$id,'t'=>8,'x'=>$p['x'],'y'=>$p['y'],'vx'=>0.0,'vy'=>0.0,'a'=>0.0,'r'=>10.0,'hp'=>20.0,'mh'=>20.0,'owner'=>$p['i'],'slot'=>$i,'dmg'=>14.0,'team'=>$p['team']];$p['drones'][]=$id;}
    }

    public function tick(): void
    {
        $now=microtime(true);$dt=min(.08,max(.005,$now-$this->lastTime));$this->lastTime=$now;$this->tickNo++;
        foreach($this->players as $cid=>$id) {
            if(!isset($this->entities[$id])) continue;$p=&$this->entities[$id];$a=(($p['keys']&8)?1:0)-(($p['keys']&4)?1:0);$b=(($p['keys']&2)?1:0)-(($p['keys']&1)?1:0);
            if($a&&$b){$a*=M_SQRT1_2;$b*=M_SQRT1_2;}$spd=240+22*$p['st'][7];
            $p['vx']=($p['vx']+$a*$spd*5*$dt)*exp(-5*$dt);$p['vy']=($p['vy']+$b*$spd*5*$dt)*exp(-5*$dt);
            $p['x']=max($p['r'],min(self::WORLD-$p['r'],$p['x']+$p['vx']*$dt));$p['y']=max($p['r'],min(self::WORLD-$p['r'],$p['y']+$p['vy']*$dt));
            if($p['hp']<$p['mh']&&$this->tickNo-$p['lastHit']>90)$p['hp']=min($p['mh'],$p['hp']+$p['mh']*(.01+.012*$p['st'][0])*$dt);
            $p['cd']-=$dt;$def=self::CLASSES[$p['c']];
            if($p['fire']&&$p['cd']<=0){$this->shoot($p,$def);$p['cd']=max(.1,$def['reload']*(.87**$p['st'][6]));}
            if($p['ability']&&$def['kind']==='trap'&&$p['cd']<=0){$this->placeTrap($p);$p['cd']=.7;}
            unset($p);
        }
        $this->updateEntities($dt);$this->broadcast($now);
    }

    private function shoot(array &$p,array $d): void
    {
        if($d['kind']==='drone'||$d['kind']==='trap') return;
        foreach($d['barrels'] as $offset){$angle=$p['a']+$offset;if($d['kind']==='spread')$angle+=($this->rand()-.5)*.24;
            $speed=(550+70*$p['st'][3])*$d['speed'];$id=$this->nextId++;$len=$p['r']*1.7;
            $this->entities[$id]=['i'=>$id,'t'=>1,'x'=>$p['x']+cos($angle)*$len,'y'=>$p['y']+sin($angle)*$len,'vx'=>cos($angle)*$speed,'vy'=>sin($angle)*$speed,'a'=>$angle,'r'=>7.0,'hp'=>1.0,'mh'=>1.0,'dmg'=>(3+3*$p['st'][5])*$d['damage'],'pen'=>1+$p['st'][4]*.8+$d['pen']*.2,'life'=>1.8,'owner'=>$p['i'],'team'=>$p['team']];}
        $p['vx']-=cos($p['a'])*$d['recoil'];$p['vy']-=sin($p['a'])*$d['recoil'];
    }

    private function placeTrap(array &$p): void
    {
        $id=$this->nextId++;$x=$p['x']+cos($p['a'])*($p['r']+25);$y=$p['y']+sin($p['a'])*($p['r']+25);
        $this->entities[$id]=['i'=>$id,'t'=>7,'x'=>$x,'y'=>$y,'vx'=>0.0,'vy'=>0.0,'a'=>$p['a'],'r'=>19.0,'hp'=>70.0,'mh'=>70.0,'life'=>12.0,'owner'=>$p['i'],'team'=>$p['team']];
    }

    private function updateEntities(float $dt): void
    {
        foreach($this->entities as $id=>&$e) {
            if($e['t']===1){$e['x']+=$e['vx']*$dt;$e['y']+=$e['vy']*$dt;$e['life']-=$dt;if($e['life']<=0||$e['x']<0||$e['y']<0||$e['x']>self::WORLD||$e['y']>self::WORLD){unset($this->entities[$id]);continue;}
                foreach($this->entities as $tid=>&$target){if($tid===$id||$target['t']===1||$target['t']===8||($target['t']===0&&($target['i']===$e['owner']||($e['team']&&$target['team']===$e['team']))))continue;$dx=$target['x']-$e['x'];$dy=$target['y']-$e['y'];if($dx*$dx+$dy*$dy>($target['r']+$e['r'])**2)continue;
                    if($target['t']===0){$target['hp']-=$e['dmg'];$target['lastHit']=$this->tickNo;if($target['hp']<=0){$owner=$this->entities[$e['owner']]??null;if($owner)$this->addXp($owner['i'],60);$target['hp']=$target['mh'];$target['x']=$this->mode==='teams'?($target['team']===1?350:3650):2000;$target['y']=2000;$target['l']=1;$target['xp']=0;$target['c']=0;$target['drones']=[];}}
                    else { $target['hp']-=($e['dmg']??8);if($target['hp']<=0){$owner=$this->entities[$e['owner']]??null;if($owner)$this->addXp($owner['i'],25);unset($this->entities[$tid]);}}
                    $e['pen']--;if($e['pen']<=0){unset($this->entities[$id]);break;}
                }unset($target);
            } elseif($e['t']===7){$e['life']-=$dt;if($e['life']<=0)unset($this->entities[$id]);}
            elseif($e['t']===8){$owner=$this->entities[$e['owner']]??null;if(!$owner){unset($this->entities[$id]);continue;}$ang=$owner['a']+($e['slot']*2*M_PI/max(1,count($owner['drones'])));$rad=$owner['fire']?230:95;$tx=$owner['x']+cos($ang)*$rad;$ty=$owner['y']+sin($ang)*$rad;$e['vx']=($tx-$e['x'])*2.5;$e['vy']=($ty-$e['y'])*2.5;$e['x']+=$e['vx']*$dt;$e['y']+=$e['vy']*$dt;
                if($owner['fire']&&$this->tickNo%12===0){$best=null;$bd=520*520;foreach($this->entities as $target){if(!in_array($target['t'],[0,2,3,4],true)||($target['t']===0&&($target['team']===$owner['team']||$target['i']===$owner['i'])))continue;$dx=$target['x']-$e['x'];$dy=$target['y']-$e['y'];$q=$dx*$dx+$dy*$dy;if($q<$bd){$bd=$q;$best=$target;}}if($best){$angle=atan2($best['y']-$e['y'],$best['x']-$e['x']);$bid=$this->nextId++;$this->entities[$bid]=['i'=>$bid,'t'=>1,'x'=>$e['x'],'y'=>$e['y'],'vx'=>cos($angle)*560,'vy'=>sin($angle)*560,'a'=>$angle,'r'=>5.0,'hp'=>1.0,'mh'=>1.0,'dmg'=>$e['dmg'],'pen'=>1.0,'life'=>1.2,'owner'=>$owner['i'],'team'=>$owner['team']];}}
            }
        }unset($e);
    }

    private function addXp(int $id,int $xp): void
    {
        if(!isset($this->entities[$id]))return;$p=&$this->entities[$id];$p['xp']+=$xp;
        while($p['l']<self::MAX_LEVEL&&$p['xp']>=floor(8*($p['l']**1.8))){$p['l']++;$p['pt']++;$p['mh']+=2;$p['hp']=min($p['mh'],$p['hp']+2);}
        unset($p);
    }

    private function choices(array $p): array
    {
        $out=[];foreach(self::CLASSES as $id=>$d)if($d['p']===$p['c']&&$d['l']<=$p['l'])$out[]=['id'=>$id,'name'=>$d['n'],'level'=>$d['l']];return $out;
    }

    private function broadcast(float $now): void
    {
        $entities=array_values($this->entities);$lb=[];foreach($this->players as $id){$p=$this->entities[$id]??null;if($p)$lb[]=[$p['n'],$p['xp'],$p['i'],$p['team']];}usort($lb,static fn($a,$b)=>$b[1]<=>$a[1]);
        foreach($this->players as $id){$p=$this->entities[$id]??null;if(!$p)continue;$packed=[];foreach($entities as $e){if(($e['t']??0)===1||hypot($e['x']-$p['x'],$e['y']-$p['y'])<1500)$packed[]=$this->pack($e);}
            $lo=(int)floor(8*max(0,$p['l']-1)**1.8);$nx=$p['l']<self::MAX_LEVEL?(int)floor(8*$p['l']**1.8):$lo;$d=self::CLASSES[$p['c']];
            $m=['t'=>'u','ts'=>(int)(($now-$this->started)*1000),'e'=>$packed,'me'=>['x'=>(int)$p['x'],'y'=>(int)$p['y'],'hp'=>(int)$p['hp'],'mh'=>(int)$p['mh'],'xp'=>$p['xp'],'lo'=>$lo,'nx'=>$nx,'lv'=>$p['l'],'pt'=>$p['pt'],'st'=>$p['st'],'c'=>$p['c'],'cc'=>$this->choices($p),'fov'=>$d['fov'],'mode'=>$this->mode,'team'=>$p['team']],'lb'=>array_slice($lb,0,8)];
            $p['conn']->send(json_encode($m,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    private function pack(array $e): array
    {
        $out=['i'=>$e['i'],'t'=>$e['t'],'x'=>(int)$e['x'],'y'=>(int)$e['y'],'a'=>round($e['a']??0,2),'r'=>round($e['r']??8,1),'hp'=>isset($e['mh'])?(int)max(0,100*$e['hp']/$e['mh']):100];foreach(['n','c','l','team','owner'] as $k)if(isset($e[$k]))$out[$k]=$e[$k];return $out;
    }
    private function rand(): float {return mt_rand()/mt_getrandmax();}
}
