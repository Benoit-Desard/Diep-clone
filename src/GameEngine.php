<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;

/** Joueur (tank). */
final class Player
{
    public int $id = 0;
    public int $team = 0;
    public float $x = 0.0, $y = 0.0, $vx = 0.0, $vy = 0.0, $angle = 0.0;
    public float $hp = 0.0, $maxHp = 0.0, $r = 26.0;
    public int $level = 1, $xp = 0, $points = 0, $cls = 0, $keys = 0;
    /** @var int[] niveaux des 8 compétences */
    public array $stats = [0, 0, 0, 0, 0, 0, 0, 0];
    public bool $fire = false, $rmb = false, $wasFiring = false, $alive = true, $stealth = false, $autospin = false;
    public float $dist = 300.0;            // distance curseur -> tank (unités monde)
    public float $spinA = 0.0;             // rotation de l'anneau de tourelles
    /** @var float[] cooldown de chaque canon */
    public array $bcd = [0.0];
    /** @var float[] angle courant de chaque tourelle auto (indexé comme les canons) */
    public array $ta = [];
    /** @var array<int,object|null> cible de chaque tourelle auto */
    public array $tt = [];
    public int $shield = 0, $lastHitTick = 0, $lastAttacker = 0, $drones = 0, $still = 0;
    /** @var array<int,array> évènements perso (chiffres de dégâts) envoyés au prochain tick */
    public array $ev = [];
    /** @var int[] les 3 super-compétences choisies (+4 crans max) */
    public array $supers = [];
    /** phase de choix (UI ouverte) : immunité totale, aucune attaque */
    public bool $busy = false, $inFire = false, $inRmb = false;   // inFire/inRmb : entrée brute du client
    public int $busyUntil = 0, $busyTicks = 0, $busyCool = 0;
    /** seconde classe fusionnée au niveau 100 (-1 : aucune) et palier de combat (1-3) */
    public int $cls2 = -1, $tier = 1;

    public function __construct(
        public readonly int $cid,
        public readonly string $name,
        public readonly ConnectionInterface $conn
    ) {
    }
}

/** Polygone, Alpha Pentagone ou Crasher (types 2 à 6). */
final class Shape
{
    public float $vx = 0.0, $vy = 0.0, $a = 0.0, $spin = 0.0, $ox = 0.0, $oy = 0.0, $tx = 0.0, $ty = 0.0;
    public int $lastAttacker = 0, $team = 0, $pt = 0, $lastHit = 0, $cell = -1;

    public function __construct(
        public int $id,
        public int $type,
        public float $x,
        public float $y,
        public float $r,
        public float $hp,
        public float $maxHp
    ) {
    }
}

/** Projectile : kind 0 balle, 1 piège, 2 missile (accélère), 3 feu d'artifice. */
final class Bullet
{
    /** @var array<int,int> id de la cible => tick du dernier impact */
    public array $hit = [];
    /** @var array<int,true> balles ennemies déjà percutées (un choc par paire) */
    public array $clash = [];
    public float $a = 0.0, $spin = 0.0, $vmax = 0.0, $px = 0.0, $py = 0.0;
    /** durabilité face aux autres balles (2 x pénétration x robustesse de la classe) et distance parcourue ce tick */
    public float $hp = 0.0, $seg = 0.0;
    public int $tier = 0;   // palier du tireur (0 : tourelle de base, interagit avec tout le monde)

    public function __construct(
        public int $id,
        public int $owner,
        public int $team,
        public int $kind,
        public float $x,
        public float $y,
        public float $vx,
        public float $vy,
        public float $r,
        public float $dmg,
        public float $pen,
        public float $life
    ) {
        $this->px = $x;
        $this->py = $y;
        $this->hp = 2.0 * $pen;
    }
}

/** Drone : kind 0 drone, 1 carré nécromancien, 2 mini-tank (Factory), 3 partisan (Battleship). */
final class Drone
{
    public float $a = 0.0, $orb = 0.0, $shootCd = 0.0;
    public int $lastAttacker = 0, $ttl = 0, $kind = 0, $lastHit = 0;
    public bool $au = false;
    public ?object $tg = null;
    public int $tier = 0;

    public function __construct(
        public int $id,
        public int $owner,
        public int $team,
        public float $x,
        public float $y,
        public float $vx,
        public float $vy,
        public float $r,
        public float $hp,
        public float $maxHp,
        public float $dmg,
        public float $speed
    ) {
    }
}

/** Tourelle de base (mode Équipes). */
final class Turret
{
    public float $a = 0.0, $cd = 0.0;

    public function __construct(public int $id, public int $team, public float $x, public float $y, public float $r = 38.0)
    {
    }
}

/**
 * Moteur de jeu autoritaire. Toute la simulation tourne ici, côté serveur.
 * Types d'entités envoyés : 0 tank, 1 balle, 2 carré, 3 triangle, 4 pentagone, 5 Alpha, 6 Crasher,
 * 7 tourelle de base, 8 drone, 9 piège, 10 missile / feu d'artifice.
 */
final class GameEngine
{
    public const TICK = 30;
    /** Côté de l'arène : 4000 x √5 ≈ 8944 -> 9000, soit ~5x la surface de l'ancienne carte. */
    public const WORLD = 9000;

    private const CELL = 256;
    private const MAX_R = 64;
    private const MAX_LEVEL = 100;
    private const VIEW_W = 1920;
    private const VIEW_H = 1080;
    private const VIEW_MARGIN = 150;
    private const SHIELD_TICKS = 90;
    private const BASE_W = 640;
    private const ALPHA_RESPAWN = 30 * 45;
    private const SUPER_BONUS = 4;     // crans supplémentaires des 3 super-compétences
    private const BUSY_KEEP = 12;      // ticks d'immunité renouvelés à chaque ping de l'interface de choix
    private const BUSY_MAX = 300;      // immunité continue maximale (10 s)
    private const BUSY_COOL = 150;     // délai avant de pouvoir la réactiver (5 s)
    private const TIER2_LEVEL = 15;    // niveau du premier palier d'évolution
    /**
     * Paliers de combat : 1 = débutants (niv. 1-14), 2 = 1re évolution (niv. 15-99), 3 = niveau 100 + fusion.
     * Table de compatibilité (qui se voit, se cible et se blesse) : chaque palier liste ceux avec lesquels il interagit.
     * Elle est symétrique par construction (A voit B seulement si B voit A). Les polygones, Crashers, Alpha et
     * tourelles de base (palier 0) interagissent avec tout le monde.
     */
    private const TIER_LINK = [1 => [1], 2 => [2, 3], 3 => [2, 3]];
    private const CLASH_K = 0.15;      // durabilité perdue par une balle = dégâts de la balle adverse x CLASH_K

    /** hp, rayon, xp, dégâts de contact/tick, résistance infligée aux balles, population cible */
    private const SHAPES = [
        2 => ['hp' => 10.0, 'r' => 24.0, 'xp' => 10, 'dmg' => 0.20, 'res' => 1.0, 'target' => 150],
        3 => ['hp' => 30.0, 'r' => 26.0, 'xp' => 25, 'dmg' => 0.45, 'res' => 1.5, 'target' => 45],
        4 => ['hp' => 100.0, 'r' => 44.0, 'xp' => 130, 'dmg' => 0.60, 'res' => 2.5, 'target' => 12],
        5 => ['hp' => 2500.0, 'r' => 135.0, 'xp' => 3000, 'dmg' => 1.50, 'res' => 6.0, 'target' => 0],
        6 => ['hp' => 12.0, 'r' => 16.0, 'xp' => 15, 'dmg' => 0.90, 'res' => 1.0, 'target' => 16],
    ];

    private bool $teams;
    /** @var array<int,Player> cid => joueur */
    private array $players = [];
    /** @var array<int,Player> id d'entité => joueur vivant */
    private array $byId = [];
    /** @var array<int,Shape> */
    private array $shapes = [];
    /** @var array<int,Bullet> */
    private array $bullets = [];
    /** @var array<int,Drone> */
    private array $drones = [];
    /** @var Turret[] */
    private array $turrets = [];
    /** @var array<int,array<int,object>> grille spatiale : cellule => entités ciblables */
    private array $grid = [];
    /** @var object[] entités trop grosses pour la grille (Alpha) */
    private array $bigs = [];
    /** @var array<int,array<int,Shape>> grille PERSISTANTE des polygones : cellule => [id => polygone] */
    private array $sgrid = [];
    /** @var array<int,true> cellules proches d'un joueur : seules celles-ci sont simulées */
    private array $active = [];
    /** @var array<int,Shape> */
    private array $crashers = [];
    /** @var array<int,Shape> polygones touchés ce tick (évite de scanner toute la carte) */
    private array $hitShapes = [];
    /** @var array<int,int> population cible de chaque type, proportionnelle à la surface */
    private array $targets = [];
    /** @var array<int,array<int,bool>> compatibilité de paliers [a][b] */
    private array $link = [];
    /** @var array<int,array> explosions de la frame */
    private array $fx = [];
    /** @var array<int,array> zones de bases (mode Équipes) */
    private array $bases = [];
    /** @var array<int,int> */
    private array $counts = [2 => 0, 3 => 0, 4 => 0, 6 => 0];
    /** @var int[] */
    private array $xpTable = [0, 0];
    private ?Shape $alpha = null;
    private int $alphaAt = 0;
    private int $cols;
    private int $nextId = 1;
    private int $tickNo = 0;
    private float $t0;
    private float $lastTime;

    public function __construct(string $mode = 'ffa')
    {
        $this->teams = $mode === 'teams';
        $this->cols = (int) ceil(self::WORLD / self::CELL);
        foreach (self::TIER_LINK as $a => $list) {
            foreach (self::TIER_LINK as $b => $other) {
                $this->link[$a][$b] = in_array($b, $list, true) && in_array($a, $other, true);
            }
        }
        $area = (self::WORLD / 4000) ** 2;           // la densité de polygones reste celle de l'ancienne carte
        foreach ([2, 3, 4, 6] as $type) {
            $this->targets[$type] = (int) round(self::SHAPES[$type]['target'] * $area);
        }
        // Niveaux 2-45 : courbe d'origine. Au-delà, chaque niveau coûte 5,5 % de plus que le précédent :
        // la progression ralentit sans cesse jusqu'au niveau 100.
        for ($l = 2; $l <= 45; $l++) {
            $this->xpTable[$l] = (int) floor(8 * ($l - 1) ** 1.8);
        }
        $inc = (float) ($this->xpTable[45] - $this->xpTable[44]);
        for ($l = 46; $l <= self::MAX_LEVEL; $l++) {
            $inc *= 1.055;
            $this->xpTable[$l] = $this->xpTable[$l - 1] + (int) round($inc);
        }
        $this->t0 = $this->lastTime = microtime(true);
        if ($this->teams) {
            $this->buildBases();
        }
        $this->spawnShapes(true);
        $this->spawnAlpha();
    }

    // ================================================================== API réseau

    public function join(int $cid, ConnectionInterface $conn, string $name): void
    {
        $prev = $this->players[$cid] ?? null;
        if ($prev !== null && $prev->alive) {
            return;
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        $name = mb_substr($name, 0, 15);
        if ($name === '') {
            $name = 'Tank' . $cid;
        }

        $p = new Player($cid, $name, $conn);
        $p->id = $this->nextId++;
        $p->team = $this->teams ? ($prev !== null ? $prev->team : $this->smallerTeam()) : 0;
        [$p->x, $p->y] = $this->spawnPos($p->team);
        $p->shield = self::SHIELD_TICKS;
        $p->lastHitTick = $this->tickNo;
        $this->recalc($p);
        $this->resetBarrels($p);

        $this->players[$cid] = $p;
        $this->byId[$p->id] = $p;

        $conn->send(json_encode([
            't' => 'sp', 'id' => $p->id, 'w' => self::WORLD, 'md' => $this->teams ? 'teams' : 'ffa',
            'tm' => $p->team, 'bs' => $this->bases, 'cl' => ClassTree::all(),
        ], JSON_UNESCAPED_UNICODE));
    }

    public function leave(int $cid): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p !== null) {
            $this->removeDronesOf($p->id);
            unset($this->byId[$p->id], $this->players[$cid]);
        }
    }

    /** @param array<string,mixed> $d */
    public function input(int $cid, array $d): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive) {
            return;
        }
        $p->keys = ((int) ($d['k'] ?? 0)) & 15;
        $a = $d['a'] ?? 0;
        if ((is_int($a) || is_float($a)) && is_finite((float) $a) && !$p->autospin) {
            $p->angle = (float) $a;
        }
        $dist = $d['d'] ?? 300;
        if (is_int($dist) || is_float($dist)) {
            $p->dist = max(0.0, min(2500.0, (float) $dist));
        }
        $p->inFire = !empty($d['f']);
        $p->inRmb = !empty($d['g']);
    }

    public function upgrade(int $cid, int $stat): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive || $stat < 0 || $stat > 7 || $p->points < 1) {
            return;
        }
        if ($p->stats[$stat] >= $this->statMax($p, $stat)) {
            return;
        }
        $p->stats[$stat]++;
        $p->points--;
        $this->recalc($p);
    }

    public function evolve(int $cid, int $cls): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive || !ClassTree::has($cls)) {
            return;
        }
        $cur = ClassTree::get($p->cls);
        $new = ClassTree::get($cls);
        if (!in_array($cls, $cur['up'], true) || $p->level < $new['lv']) {
            return;
        }
        $p->cls = $cls;
        // Les classes sans compétences de balle (Smasher...) remboursent les points investis
        if ($new['mxg'] === 0) {
            for ($i = 3; $i <= 6; $i++) {
                $p->points += $p->stats[$i];
                $p->stats[$i] = 0;
            }
        }
        $this->resetBarrels($p);
        $this->recalc($p);
    }

    /** Ping de l'interface de choix (arbre, compétences) : immunité tant qu'il y a quelque chose à choisir. */
    public function busy(int $cid): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive) {
            return;
        }
        if ($p->points < 1 && !$this->choices($p) && !$this->canPickSupers($p) && !$this->canFuse($p)) {
            return; // rien à choisir : pas d'immunité gratuite
        }
        $p->busyUntil = $this->tickNo + self::BUSY_KEEP;
    }

    /** Spécialisation finale : 3 compétences distinctes gagnent +4 crans maximum (définitif). */
    public function setSupers(int $cid, array $list): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive || !$this->canPickSupers($p)) {
            return;
        }
        $ids = [];
        foreach ($list as $v) {
            if (!is_int($v) || $v < 0 || $v > 7) {
                return;
            }
            $ids[$v] = true;
        }
        if (count($ids) !== 3) {
            return;
        }
        foreach (array_keys($ids) as $i) {
            if ($this->statBase($p, $i) < 1) {
                return; // compétence verrouillée pour cette classe
            }
        }
        $p->supers = array_keys($ids);
        sort($p->supers);
        $this->recalc($p);
    }

    /** Seconde évolution (niveau 100) : fusionne une 2e classe avec la 1re. Définitif. */
    public function fuse(int $cid, int $cls2): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive || !$this->canFuse($p) || !ClassTree::has($cls2)
            || $cls2 === $p->cls || ClassTree::get($cls2)['lv'] < self::TIER2_LEVEL) {
            return;
        }
        $p->cls2 = $cls2;
        $this->resetBarrels($p);
        $this->recalc($p);
        $this->refreshTier($p);
    }

    private function canFuse(Player $p): bool
    {
        return $p->level >= self::MAX_LEVEL && $p->cls2 < 0 && $p->cls !== 0;
    }

    /** Classe réellement jouée : la 1re, ou la fusion des deux après la seconde évolution. */
    private function effCls(Player $p): array
    {
        return $p->cls2 >= 0 ? ClassTree::fused($p->cls, $p->cls2) : ClassTree::get($p->cls);
    }

    private function refreshTier(Player $p): void
    {
        $p->tier = ($p->level >= self::MAX_LEVEL && $p->cls2 >= 0) ? 3 : ($p->level >= self::TIER2_LEVEL ? 2 : 1);
    }

    /** Deux paliers peuvent-ils interagir ? (0 = entité neutre : polygones, tourelles de base) */
    private function linked(int $a, int $b): bool
    {
        return $a === 0 || $b === 0 || $this->link[$a][$b];
    }

    /** Visibilité : on voit les paliers compatibles, et toujours ses coéquipiers (qui ne peuvent pas se blesser). */
    private function sees(Player $viewer, int $tier, int $team): bool
    {
        return $this->linked($viewer->tier, $tier) || ($viewer->team !== 0 && $team === $viewer->team);
    }

    /** Disponible une seule fois, dès qu'on possède une classe finale (palier 4, niveau 45). */
    private function canPickSupers(Player $p): bool
    {
        return $p->supers === [] && ClassTree::get($p->cls)['lv'] >= 45;
    }

    public function toggleSpin(int $cid): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p !== null && $p->alive) {
            $p->autospin = !$p->autospin;
        }
    }

    // ================================================================== boucle de jeu

    public function tick(): void
    {
        $now = microtime(true);
        $dt = min(0.1, max(0.001, $now - $this->lastTime));
        $this->lastTime = $now;
        if (!$this->players) {
            return; // salle vide : on économise le CPU
        }
        $this->tickNo++;

        $this->updatePlayers($dt);
        $this->computeActive();
        $this->updateShapes($dt);
        $this->updateDrones($dt);
        $this->updateBullets($dt);
        $this->buildGrid();
        $this->updateCrashers();
        $this->updateTurrets($dt);
        $this->collideBodies();
        $this->collideBulletClashes();
        $this->collideBullets();
        $this->resolveDeaths();
        $this->spawnShapes(false);
        $this->broadcast($now);
    }

    // ------------------------------------------------------------------ joueurs

    private function updatePlayers(float $dt): void
    {
        $k = 5.0; // frottement (1/s) : vitesse max = accélération / k
        $friction = exp(-$k * $dt);
        $max = (float) self::WORLD;

        foreach ($this->players as $p) {
            if (!$p->alive) {
                continue;
            }
            $cls = $this->effCls($p);
            $this->refreshTier($p);

            // Phase de choix : immunité + aucune attaque, limitée (10 s max puis 5 s de recharge) contre les abus
            $want = $this->tickNo < $p->busyUntil && $p->busyCool <= 0;
            if ($want && ++$p->busyTicks > self::BUSY_MAX) {
                $want = false;
                $p->busyCool = self::BUSY_COOL;
            }
            if (!$want) {
                $p->busyTicks = 0;
            }
            if ($p->busyCool > 0) {
                $p->busyCool--;
            }
            $p->busy = $want;
            $p->fire = $p->inFire && !$want;         // l'entrée brute est conservée : le tir reprend dès la fin du choix
            $p->rmb = $p->inRmb && !$want;

            if ($p->autospin) {
                $p->angle += 2.2 * $dt;
            }
            $p->spinA += $cls['tsp'] * $dt;

            // Déplacement avec inertie
            $ax = (($p->keys & 8) ? 1 : 0) - (($p->keys & 4) ? 1 : 0);
            $ay = (($p->keys & 2) ? 1 : 0) - (($p->keys & 1) ? 1 : 0);
            if ($ax !== 0 && $ay !== 0) {
                $ax *= M_SQRT1_2;
                $ay *= M_SQRT1_2;
            }
            $acc = (240.0 + 22.0 * $p->stats[7]) * $cls['mv'] * $k;
            $p->vx = ($p->vx + $ax * $acc * $dt) * $friction;
            $p->vy = ($p->vy + $ay * $acc * $dt) * $friction;
            $p->x = min($max - $p->r, max($p->r, $p->x + $p->vx * $dt));
            $p->y = min($max - $p->r, max($p->r, $p->y + $p->vy * $dt));

            if ($p->shield > 0) {
                $p->shield--;
            }

            // Régénération (accélérée dans sa propre base)
            $regen = 0.01 + 0.012 * $p->stats[0];
            $inBase = $this->teams && $this->inOwnBase($p);
            if ($inBase) {
                $regen = max($regen, 0.08);
            }
            if ($p->hp < $p->maxHp && ($inBase || $this->tickNo - $p->lastHitTick > 90)) {
                $p->hp = min($p->maxHp, $p->hp + $p->maxHp * $regen * $dt);
            }

            // Furtivité (Stalker, Manager, Landmine)
            if ($cls['st'] > 0 && !$p->fire && !$p->rmb && $p->vx * $p->vx + $p->vy * $p->vy < 625.0) {
                $p->still++;
            } else {
                $p->still = 0;
            }
            $p->stealth = $cls['st'] > 0 && $p->still >= $cls['st'];

            $this->updateBarrels($p, $cls, $dt);
        }
    }

    private function updateBarrels(Player $p, array $cls, float $dt): void
    {
        $base = 0.5 * (0.87 ** $p->stats[6]) * $cls['rl'];
        $lmb = $p->fire;
        $rmb = $p->rmb;
        $pressed = $lmb || $rmb;
        $restage = $pressed && !$p->wasFiring;
        $p->wasFiring = $pressed;
        if ($pressed) {
            $p->shield = 0;
        }

        foreach ($cls['bar'] as $i => $bar) {
            $k = $bar['k'];
            if ($k === 'n') {
                continue;
            }
            $rel = max(0.034, $base * $bar['rl']);  // au plus un tir par tick et par canon

            if ($k === 'a') {                       // tourelle automatique
                $this->updateAutoTurret($p, $cls, $bar, $i, $rel, $dt);
                continue;
            }

            $p->bcd[$i] -= $dt;
            if ($p->bcd[$i] < -$dt) {
                $p->bcd[$i] = -$dt;
            }
            if ($restage && $bar['d'] > 0.0 && $p->bcd[$i] <= 0.0) {
                $p->bcd[$i] = $bar['d'] * $rel;     // décalage entre canons (alternance)
            }
            if ($p->bcd[$i] > 0.0) {
                continue;
            }

            if ($k === 'd') {                       // spawner : produit des drones en continu
                if ($p->drones >= $cls['dc'] + $cls['dcs'] * $p->stats[6]) {
                    continue;
                }
                $this->spawnDrone($p, $cls, $bar);
                $p->bcd[$i] += $rel;
                continue;
            }

            $trigger = $bar['tg'] === 1 ? $rmb : ($bar['tg'] === 2 ? $pressed : $lmb);
            if (!$trigger) {
                continue;
            }
            $ang = $p->angle + $bar['a'];
            $len = $p->r * $bar['l'];
            $off = $bar['o'] * $p->r;
            $cos = cos($ang);
            $sin = sin($ang);
            $this->fireFrom($p, $cls, $bar, $p->x + $cos * $len - $sin * $off, $p->y + $sin * $len + $cos * $off, $ang, true);
            $p->bcd[$i] += $rel;
        }
    }

    /** Tourelle auto : suit la cible la plus proche (ou le curseur / à l'opposé selon les clics). */
    private function updateAutoTurret(Player $p, array $cls, array $bar, int $i, float $rel, float $dt): void
    {
        $mAng = $bar['an'] + ($bar['ro'] ? $p->spinA : 0.0);
        $mx = $p->x + cos($mAng) * $bar['ra'] * $p->r;
        $my = $p->y + sin($mAng) * $bar['ra'] * $p->r;

        $tg = $p->tt[$i] ?? null;
        if (($this->tickNo + $i) % 3 === 0 || !$this->validTarget($tg)) {
            $tg = $this->findTarget($p, $mx, $my, (float) $bar['rg']);
            $p->tt[$i] = $tg;
        }

        $cur = $p->ta[$i] ?? $p->angle;
        $want = $cur;
        $shoot = false;
        if ($p->rmb) {
            $want = $p->angle + M_PI;               // clic droit : tourelles dos au curseur
        } elseif ($p->fire) {
            $cx = $p->x + cos($p->angle) * $p->dist;
            $cy = $p->y + sin($p->angle) * $p->dist;
            $want = atan2($cy - $my, $cx - $mx);    // clic gauche : tourelles vers le curseur
            $shoot = true;
        } elseif ($tg !== null) {
            $want = atan2($tg->y - $my, $tg->x - $mx);
            $shoot = true;
        }
        if ($p->busy) {
            $shoot = false;
        }
        $diff = fmod($want - $cur + 3 * M_PI, 2 * M_PI) - M_PI;
        $step = 9.0 * $dt;
        $cur += max(-$step, min($step, $diff));
        $p->ta[$i] = $cur;

        $p->bcd[$i] -= $dt;
        if ($p->bcd[$i] < -$dt) {
            $p->bcd[$i] = -$dt;
        }
        if ($shoot && $p->bcd[$i] <= 0.0) {
            $len = $p->r * $bar['l'] * 0.9;
            $this->fireFrom($p, $cls, $bar, $mx + cos($cur) * $len, $my + sin($cur) * $len, $cur, false);
            $p->bcd[$i] += $rel;
        }
    }

    /** Crée le(s) projectile(s) d'un canon à la position/angle donnés. */
    private function fireFrom(Player $p, array $cls, array $bar, float $x, float $y, float $ang, bool $recoil): void
    {
        $s = $p->stats;
        $k = $bar['k'];
        $n = (int) $bar['n'];
        $sd = $cls['sd'] + $bar['sd'];

        for ($j = 0; $j < $n; $j++) {
            $a = $ang;
            if ($sd > 0.0) {
                $a += ($this->rnd() - 0.5) * 2.0 * $sd;
            }
            $cos = cos($a);
            $sin = sin($a);
            $spd = (560.0 + 75.0 * $s[3]) * $cls['sp'] * $bar['sp'] * ($n > 1 ? 0.85 + 0.3 * $this->rnd() : 1.0);
            $dmg = (7.0 + 3.0 * $s[5]) * $cls['dm'] * $bar['dm'];
            $pen = (1.0 + 0.9 * $s[4]) * $cls['pn'] * $bar['pn'];
            $r = max(4.0, $p->r * $bar['w'] * 0.45 * $bar['sz']);
            $life = 1.8 * $cls['lf'] * $bar['lf'];
            $kind = 0;
            $vmax = 0.0;

            if ($k === 't') {                       // piège : freine puis reste en place
                $kind = 1;
                $pen = (6.0 + 2.5 * $s[4]) * $cls['pn'] * $bar['pn'];
                $life = 12.0 * $cls['lf'] * $bar['lf'];
            } elseif ($k === 'm') {                 // missile : démarre lentement puis accélère
                $kind = 2;
                $vmax = $spd * 1.3;
                $spd *= 0.5;
            } elseif ($k === 'f') {                 // feu d'artifice : explose en fin de vie
                $kind = 3;
            }

            $b = new Bullet($this->nextId++, $p->id, $p->team, $kind, $x, $y, $cos * $spd, $sin * $spd, $r, $dmg, $pen, $life);
            $b->vmax = $vmax;
            $b->tier = $p->tier;
            $b->hp = 2.0 * $pen * $cls['bh'] * $bar['bh'];   // robustesse spécifique à la classe (et au canon)
            if ($kind === 1) {
                $b->a = $a;
                $b->spin = ($this->rnd() - 0.5) * 2.0;
            }
            $this->bullets[$b->id] = $b;

            if ($recoil && $j === 0) {
                $rc = $cls['rc'] * $bar['rc'];
                $p->vx -= cos($ang) * $rc;
                $p->vy -= sin($ang) * $rc;
            }
        }
    }

    // ------------------------------------------------------------------ drones

    private function spawnDrone(Player $p, array $cls, array $bar): void
    {
        $s = $p->stats;
        $ang = $p->angle + $bar['a'];
        $len = $p->r * $bar['l'];
        $kind = (int) $bar['dk'];
        $ttl = 0;
        switch ($kind) {
            case 2:  // mini-tank
                $r = $p->r * 0.55; $hp = 25.0 + 8.0 * $s[4]; $dmg = 0.4 + 0.1 * $s[5]; $spd = 190.0 + 25.0 * $s[3];
                break;
            case 3:  // partisan : vie courte
                $r = $p->r * 0.28; $hp = 4.0; $dmg = 0.2 + 0.05 * $s[5]; $spd = 330.0; $ttl = 120;
                break;
            default: // drone
                $r = $p->r * 0.42; $hp = 10.0 + 5.0 * $s[4]; $dmg = 0.25 + 0.1 * $s[5]; $spd = 210.0 + 32.0 * $s[3];
        }
        $dmg *= $cls['dm'] * $bar['dm'];
        $spd *= $cls['sp'] * $bar['sp'];

        $d = new Drone($this->nextId++, $p->id, $p->team,
            $p->x + cos($ang) * $len, $p->y + sin($ang) * $len, cos($ang) * 260.0, sin($ang) * 260.0, $r, $hp, $hp, $dmg, $spd);
        $d->kind = $kind;
        $d->tier = $p->tier;
        $d->au = (bool) $bar['au'];
        $d->ttl = $ttl;
        $d->a = $ang;
        $d->orb = $this->rnd() * 2 * M_PI;
        $this->drones[$d->id] = $d;
        $p->drones++;
    }

    private function updateDrones(float $dt): void
    {
        $t = $this->tickNo;
        $w = (float) self::WORLD;
        foreach ($this->drones as $id => $d) {
            $o = $this->byId[$d->owner] ?? null;
            if ($o === null || !$o->alive) {
                unset($this->drones[$id]);
                continue;
            }
            $d->tier = $o->tier;                     // un drone suit le palier de son propriétaire
            if ($d->ttl > 0 && --$d->ttl <= 0) {
                $d->hp = 0.0;                        // expire : retiré dans resolveDeaths
                continue;
            }
            $cx = $o->x + cos($o->angle) * $o->dist;
            $cy = $o->y + sin($o->angle) * $o->dist;
            $retarget = (($d->id + $t) % 4) === 0 || !$this->validTarget($d->tg);

            if (!$d->au && $o->rmb) {                // clic droit : fuir le curseur
                $dx = $o->x - $cx;
                $dy = $o->y - $cy;
                $l = max(1.0, sqrt($dx * $dx + $dy * $dy));
                $tx = $o->x + $dx / $l * 240.0;
                $ty = $o->y + $dy / $l * 240.0;
                $d->tg = null;
            } elseif (!$d->au && $o->fire) {         // clic gauche : converger / attaquer près du curseur
                if ($retarget) {
                    $d->tg = $this->findTarget($d, $cx, $cy, 320.0);
                }
                $tx = $d->tg !== null ? $d->tg->x : $cx;
                $ty = $d->tg !== null ? $d->tg->y : $cy;
            } else {                                 // par défaut : entourer le tank, attaquer à proximité
                if ($retarget) {
                    $d->tg = $o->busy ? null : $this->findTarget($d, $o->x, $o->y, 380.0);
                }
                if ($d->tg !== null) {
                    $tx = $d->tg->x;
                    $ty = $d->tg->y;
                } else {
                    $ang = $d->orb + $t * 0.04;
                    $rad = 90.0 + ($d->id % 3) * 24.0;
                    $tx = $o->x + cos($ang) * $rad;
                    $ty = $o->y + sin($ang) * $rad;
                }
            }

            $dx = $tx - $d->x;
            $dy = $ty - $d->y;
            $dist = sqrt($dx * $dx + $dy * $dy);
            $wx = $wy = 0.0;
            if ($dist > 1.0) {
                $sp = min($d->speed, $dist * 4.0);
                $wx = $dx / $dist * $sp;
                $wy = $dy / $dist * $sp;
            }
            $k = min(1.0, 5.0 * $dt);
            $d->vx += ($wx - $d->vx) * $k;
            $d->vy += ($wy - $d->vy) * $k;
            $d->x = min($w, max(0.0, $d->x + $d->vx * $dt));
            $d->y = min($w, max(0.0, $d->y + $d->vy * $dt));
            if ($d->vx * $d->vx + $d->vy * $d->vy > 400.0) {
                $d->a = atan2($d->vy, $d->vx);
            }

            // Les mini-tanks (Factory) tirent sur leur cible
            if ($d->kind === 2) {
                $d->shootCd -= $dt;
                $tg = $d->tg;
                if ($tg !== null && $d->shootCd <= 0.0 && !$o->busy) {
                    $ang = atan2($tg->y - $d->y, $tg->x - $d->x);
                    $b = new Bullet($this->nextId++, $d->owner, $d->team, 0, $d->x + cos($ang) * $d->r, $d->y + sin($ang) * $d->r,
                        cos($ang) * 520.0, sin($ang) * 520.0, 6.0, $d->dmg * 9.0, 1.0, 1.1);
                    $b->tier = $d->tier;
                    $this->bullets[$b->id] = $b;
                    $d->shootCd = 0.7;
                }
            }
        }
    }

    private function removeDronesOf(int $pid): void
    {
        foreach ($this->drones as $id => $d) {
            if ($d->owner === $pid) {
                unset($this->drones[$id]);
            }
        }
        if (isset($this->byId[$pid])) {
            $this->byId[$pid]->drones = 0;
        }
    }

    /** Nécromancien : un carré touché devient un drone carré (plafond de drones). */
    private function tryInfect(object $m, Shape $sq): bool
    {
        $owner = $m instanceof Player ? $m : ($m instanceof Drone && $m->kind === 1 ? ($this->byId[$m->owner] ?? null) : null);
        if ($owner === null || !$owner->alive || $owner->busy) {
            return false;
        }
        $cls = ClassTree::get($owner->cls);
        if ($cls['nec'] !== 1 || $owner->drones >= $cls['dc'] + $cls['dcs'] * $owner->stats[6]) {
            return false;
        }
        $s = $owner->stats;
        $d = new Drone($this->nextId++, $owner->id, $owner->team, $sq->x, $sq->y, 0.0, 0.0, 22.0,
            15.0 + 6.0 * $s[4], 15.0 + 6.0 * $s[4], (0.15 + 0.06 * $s[5]) * $cls['dm'], (150.0 + 25.0 * $s[3]) * $cls['sp']);
        $d->kind = 1;
        $d->tier = $owner->tier;
        $d->a = $sq->a;
        $d->orb = $this->rnd() * 2 * M_PI;
        $this->drones[$d->id] = $d;
        $owner->drones++;
        $sq->hp = -1.0;
        $this->hitShapes[$sq->id] = $sq;
        $sq->lastAttacker = -1;                      // retrait silencieux (pas d'XP ni d'explosion)
        return true;
    }

    // ------------------------------------------------------------------ polygones, projectiles, IA

    /** Cellules à simuler : celles qui touchent la zone visible (+ marge) d'au moins un joueur. */
    private function computeActive(): void
    {
        $this->active = [];
        $last = $this->cols - 1;
        foreach ($this->players as $p) {
            if (!$p->alive) {
                continue;
            }
            $fov = $this->effCls($p)['fov'];
            $hw = self::VIEW_W / 2 * $fov + self::VIEW_MARGIN + 300;
            $hh = self::VIEW_H / 2 * $fov + self::VIEW_MARGIN + 300;
            $cx0 = max(0, (int) (($p->x - $hw) / self::CELL));
            $cx1 = min($last, (int) (($p->x + $hw) / self::CELL));
            $cy0 = max(0, (int) (($p->y - $hh) / self::CELL));
            $cy1 = min($last, (int) (($p->y + $hh) / self::CELL));
            for ($cy = $cy0; $cy <= $cy1; $cy++) {
                for ($cx = $cx0; $cx <= $cx1; $cx++) {
                    $this->active[$cy * $this->cols + $cx] = true;
                }
            }
        }
    }

    /** Ne simule que les polygones des cellules actives : le coût ne dépend plus de la taille de la carte. */
    private function updateShapes(float $dt): void
    {
        $f = exp(-2.5 * $dt);
        $w = (float) self::WORLD;
        $moves = [];
        foreach ($this->active as $cell => $_) {
            $bucket = $this->sgrid[$cell] ?? null;
            if ($bucket === null) {
                continue;
            }
            foreach ($bucket as $s) {
                $s->x += ($s->vx + $s->ox) * $dt;
                $s->y += ($s->vy + $s->oy) * $dt;
                $s->vx *= $f;
                $s->vy *= $f;
                $s->a += $s->spin * $dt;
                if ($s->x < $s->r)      { $s->x = $s->r;      $s->ox = abs($s->ox); }
                if ($s->x > $w - $s->r) { $s->x = $w - $s->r; $s->ox = -abs($s->ox); }
                if ($s->y < $s->r)      { $s->y = $s->r;      $s->oy = abs($s->oy); }
                if ($s->y > $w - $s->r) { $s->y = $w - $s->r; $s->oy = -abs($s->oy); }
                // Les polygones se régénèrent après 30 s sans dégât
                if ($s->hp < $s->maxHp && $s->hp > 0.0 && $this->tickNo - $s->lastHit > 900) {
                    $s->hp = min($s->maxHp, $s->hp + $s->maxHp * 0.05 * $dt);
                }
                $nc = $this->cellOf($s->x, $s->y);
                if ($nc !== $cell) {
                    $moves[] = [$s, $cell, $nc];
                }
            }
        }
        foreach ($moves as [$s, $from, $to]) {        // changement de cellule appliqué après la boucle
            unset($this->sgrid[$from][$s->id]);
            if (empty($this->sgrid[$from])) {
                unset($this->sgrid[$from]);
            }
            $this->sgrid[$to][$s->id] = $s;
            $s->cell = $to;
        }

        $a = $this->alpha;                            // l'Alpha (hors grille) tourne et se régénère toujours
        if ($a !== null) {
            $a->a += $a->spin * $dt;
            if ($a->hp < $a->maxHp && $a->hp > 0.0 && $this->tickNo - $a->lastHit > 900) {
                $a->hp = min($a->maxHp, $a->hp + $a->maxHp * 0.02 * $dt);
            }
        }
    }

    private function updateBullets(float $dt): void
    {
        $lim = self::WORLD + 50;
        foreach ($this->bullets as $id => $b) {
            $b->px = $b->x;
            $b->py = $b->y;
            $b->life -= $dt;
            if ($b->kind === 1) {                    // piège : freine jusqu'à l'arrêt
                $f = exp(-2.2 * $dt);
                $b->vx *= $f;
                $b->vy *= $f;
                $b->a += $b->spin * $dt;
            } elseif ($b->kind === 2 && $b->vx * $b->vx + $b->vy * $b->vy < $b->vmax * $b->vmax) {
                $g = 1.0 + 2.4 * $dt;                // missile : accélère
                $b->vx *= $g;
                $b->vy *= $g;
            }
            $b->x += $b->vx * $dt;
            $b->y += $b->vy * $dt;
            $b->seg = sqrt($b->vx * $b->vx + $b->vy * $b->vy) * $dt;
            if ($b->life <= 0.0 || $b->x < -50 || $b->y < -50 || $b->x > $lim || $b->y > $lim) {
                if ($b->kind === 3) {
                    $this->explode($b);
                }
                unset($this->bullets[$id]);
            }
        }
    }

    /** Feu d'artifice : 16 petites balles radiales. */
    private function explode(Bullet $b): void
    {
        for ($i = 0; $i < 16; $i++) {
            $a = 2 * M_PI * $i / 16;
            $c = new Bullet($this->nextId++, $b->owner, $b->team, 0, $b->x, $b->y, cos($a) * 380.0, sin($a) * 380.0,
                max(4.0, $b->r * 0.35), $b->dmg * 0.3, 1.0, 0.6);
            $c->tier = $b->tier;
            $this->bullets[$c->id] = $c;
        }
        $this->addFx($b->tier, ['x', (int) $b->x, (int) $b->y, 2, 30, $b->team]);
    }

    /** Crashers : patrouillent près du centre, chargent le joueur le plus proche à vue. */
    private function updateCrashers(): void
    {
        $c = self::WORLD / 2;
        $sc = self::WORLD / 4000;
        foreach ($this->crashers as $s) {
            if ((($s->id + $this->tickNo) % 3) !== 0 || !isset($this->active[$s->cell])) {
                continue;
            }
            $best = null;
            $bd = 700.0 * 700.0;
            foreach ($this->query($s->x, $s->y, 700.0, false) as $e) {
                if (!($e instanceof Player) || $e->stealth || $e->busy) {
                    continue;
                }
                $dx = $e->x - $s->x;
                $dy = $e->y - $s->y;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 < $bd) {
                    $bd = $d2;
                    $best = $e;
                }
            }
            if ($best !== null) {
                $d = max(1.0, sqrt($bd));
                $s->ox = ($best->x - $s->x) / $d * 215.0;
                $s->oy = ($best->y - $s->y) / $d * 215.0;
            } else {
                $dx = $s->tx - $s->x;
                $dy = $s->ty - $s->y;
                if ($s->pt <= $this->tickNo || $dx * $dx + $dy * $dy < 2500.0) {
                    $a = $this->rnd() * 2 * M_PI;
                    $rad = 900.0 * $sc * sqrt($this->rnd());
                    $s->tx = $c + cos($a) * $rad;
                    $s->ty = $c + sin($a) * $rad;
                    $s->pt = $this->tickNo + 90 + mt_rand(0, 150);
                    $dx = $s->tx - $s->x;
                    $dy = $s->ty - $s->y;
                }
                $d = max(1.0, sqrt($dx * $dx + $dy * $dy));
                $s->ox = $dx / $d * 70.0;
                $s->oy = $dy / $d * 70.0;
            }
            $s->a = atan2($s->oy, $s->ox);
        }
    }

    /** Tourelles de base (mode Équipes) : tirent sur l'ennemi le plus proche. */
    private function updateTurrets(float $dt): void
    {
        foreach ($this->turrets as $t) {
            $t->cd -= $dt;
            $best = null;
            $bd = 720.0 * 720.0;
            foreach ($this->query($t->x, $t->y, 720.0, false) as $e) {
                if (!($e instanceof Player) || $e->stealth || $e->busy || $e->team === $t->team) {
                    continue;
                }
                $dx = $e->x - $t->x;
                $dy = $e->y - $t->y;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 < $bd) {
                    $bd = $d2;
                    $best = $e;
                }
            }
            if ($best === null) {
                continue;
            }
            $t->a = atan2($best->y - $t->y, $best->x - $t->x);
            if ($t->cd <= 0.0) {
                $b = new Bullet($this->nextId++, $t->id, $t->team, 0, $t->x + cos($t->a) * 52.0, $t->y + sin($t->a) * 52.0,
                    cos($t->a) * 650.0, sin($t->a) * 650.0, 9.0, 9.0, 1.5, 1.3);
                $this->bullets[$b->id] = $b;
                $t->cd = 0.45;
            }
        }
    }

    // ================================================================== grille spatiale

    private function buildGrid(): void
    {
        $this->grid = [];                             // les polygones vivent dans la grille persistante $sgrid
        foreach ($this->players as $p) {
            if ($p->alive) {
                $this->grid[$this->cellOf($p->x, $p->y)][] = $p;
            }
        }
        foreach ($this->drones as $d) {
            $this->grid[$this->cellOf($d->x, $d->y)][] = $d;
        }
    }

    private function cellOf(float $x, float $y): int
    {
        $cx = min($this->cols - 1, max(0, (int) ($x / self::CELL)));
        $cy = min($this->cols - 1, max(0, (int) ($y / self::CELL)));
        return $cy * $this->cols + $cx;
    }

    /** @return object[] entités dont le centre est proche du cercle (x, y, rad) ; $shapes=false ignore les polygones */
    private function query(float $x, float $y, float $rad, bool $shapes = true): array
    {
        $m = $rad + self::MAX_R;
        return $this->queryBox($x - $m, $y - $m, $x + $m, $y + $m, $shapes);
    }

    /** @return object[] */
    private function queryBox(float $x0, float $y0, float $x1, float $y1, bool $shapes = true): array
    {
        $last = $this->cols - 1;
        $cx0 = max(0, (int) ($x0 / self::CELL));
        $cx1 = min($last, (int) ($x1 / self::CELL));
        $cy0 = max(0, (int) ($y0 / self::CELL));
        $cy1 = min($last, (int) ($y1 / self::CELL));
        $out = $shapes ? $this->bigs : [];
        for ($cy = $cy0; $cy <= $cy1; $cy++) {
            for ($cx = $cx0; $cx <= $cx1; $cx++) {
                $k = $cy * $this->cols + $cx;
                $cell = $this->grid[$k] ?? null;          // joueurs et drones
                if ($cell !== null) {
                    foreach ($cell as $e) {
                        $out[] = $e;
                    }
                }
                if ($shapes && isset($this->sgrid[$k])) { // polygones
                    foreach ($this->sgrid[$k] as $e) {
                        $out[] = $e;
                    }
                }
            }
        }
        return $out;
    }

    /** Cible ennemie la plus proche de (cx, cy) dans le rayon donné (grille du tick précédent). */
    private function findTarget(object $src, float $cx, float $cy, float $range): ?object
    {
        $best = null;
        $bd = PHP_FLOAT_MAX;
        foreach ($this->query($cx, $cy, $range) as $e) {
            if ($e->hp <= 0.0 || ($e instanceof Player && ($e->stealth || $e->busy || !$e->alive)) || $this->friendly($src, $e)
                || (!($e instanceof Shape) && !$this->linked($src->tier, $e->tier))) {
                continue;
            }
            $dx = $e->x - $cx;
            $dy = $e->y - $cy;
            $d2 = $dx * $dx + $dy * $dy;
            $lim = $range + $e->r;
            if ($d2 <= $lim * $lim && $d2 < $bd) {
                $bd = $d2;
                $best = $e;
            }
        }
        return $best;
    }

    private function validTarget(?object $t): bool
    {
        if ($t === null || $t->hp <= 0.0) {
            return false;
        }
        return !($t instanceof Player) || ($t->alive && !$t->stealth && !$t->busy);
    }

    // ================================================================== collisions

    private function ownerOf(object $e): int
    {
        return $e instanceof Player ? $e->id : ($e instanceof Drone ? $e->owner : -$e->id);
    }

    private function friendly(object $a, object $b): bool
    {
        if ($this->ownerOf($a) === $this->ownerOf($b)) {
            return true;
        }
        return $a->team !== 0 && $a->team === $b->team;
    }

    /** Un tank en phase de choix (et ses drones) n'inflige ni ne subit de dégâts de contact. */
    private function inert(object $e): bool
    {
        if ($e instanceof Player) {
            return $e->busy;
        }
        if ($e instanceof Drone) {
            $o = $this->byId[$e->owner] ?? null;
            return $o !== null && $o->busy;
        }
        return false;
    }

    private function invMass(object $e): float
    {
        if ($e instanceof Player) {
            return 1.0;
        }
        if ($e instanceof Drone) {
            return 2.5;
        }
        return $e->type === 5 ? 0.01 : ($e->type === 4 ? 0.9 : 1.4);
    }

    /** Sépare deux corps selon leur masse et leur transmet une impulsion. */
    private function push(object $a, object $b, float $nx, float $ny, float $ov): void
    {
        $ia = $this->invMass($a);
        $ib = $this->invMass($b);
        $sa = $ia / ($ia + $ib);
        $sb = 1.0 - $sa;
        $a->x -= $nx * $ov * $sa;
        $a->y -= $ny * $ov * $sa;
        $b->x += $nx * $ov * $sb;
        $b->y += $ny * $ov * $sb;
        $a->vx -= $nx * $ov * 6.0 * $sa;
        $a->vy -= $ny * $ov * 6.0 * $sa;
        $b->vx += $nx * $ov * 6.0 * $sb;
        $b->vy += $ny * $ov * 6.0 * $sb;
    }

    private function bodyDamage(Player $p): float
    {
        return (0.5 + 0.3 * $p->stats[2]) * $this->effCls($p)['bd']; // par tick de contact
    }

    private function contactDmg(object $e): float
    {
        if ($e instanceof Player) {
            return $this->bodyDamage($e);
        }
        return $e instanceof Drone ? $e->dmg : self::SHAPES[$e->type]['dmg'];
    }

    /** id du joueur à qui attribuer les dégâts/kills d'une entité */
    private function attrib(object $e): int
    {
        return $e instanceof Player ? $e->id : ($e instanceof Drone ? $e->owner : 0);
    }

    private function dealDamage(object $t, float $dmg, int $by): void
    {
        if ($t instanceof Player) {
            $this->hurt($t, $dmg, $by > 0 ? ($this->byId[$by] ?? null) : null);
            return;
        }
        $t->hp -= $dmg;
        $t->lastHit = $this->tickNo;
        if ($t instanceof Shape) {
            $this->hitShapes[$t->id] = $t;
        }
        if ($by > 0) {
            $t->lastAttacker = $by;
        }
    }

    private function hurt(Player $p, float $dmg, ?Player $by): void
    {
        if ($p->shield > 0 || $p->busy) {
            return;
        }
        $p->hp -= $dmg;
        $p->lastHitTick = $this->tickNo;
        $p->still = 0;                               // un tank furtif touché est révélé
        if ($by !== null) {
            $p->lastAttacker = $by->id;
        }
    }

    private function collideBodies(): void
    {
        $movers = [];
        foreach ($this->players as $p) {
            if ($p->alive) {
                $movers[] = $p;
            }
        }
        foreach ($this->drones as $d) {
            $movers[] = $d;
        }

        foreach ($movers as $m) {
            if ($m->hp <= 0.0) {
                continue;
            }
            foreach ($this->query($m->x, $m->y, $m->r) as $o) {
                if ($o === $m || $o->hp <= 0.0) {
                    continue;
                }
                if (!($o instanceof Shape) && !$this->linked($m->tier, $o->tier)) {
                    continue;                        // paliers incompatibles : intangibles, ni poussée ni dégâts
                }
                $dx = $o->x - $m->x;
                $dy = $o->y - $m->y;
                $rr = $m->r + $o->r;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 >= $rr * $rr) {
                    continue;
                }
                if (!($o instanceof Shape) && $o->id < $m->id) {
                    continue;                        // paire déjà traitée depuis l'autre corps
                }
                if ($o instanceof Shape && $o->type === 2 && $this->tryInfect($m, $o)) {
                    continue;
                }
                $friendly = $this->friendly($m, $o);
                if ($friendly && !($m instanceof Player && $o instanceof Player)) {
                    continue;                        // alliés : ni collision ni dégâts (sauf tanks coéquipiers qui se poussent)
                }
                $d = sqrt($d2);
                if ($d < 0.01) {
                    $dx = 1.0;
                    $dy = 0.0;
                    $d = 1.0;
                }
                $nx = $dx / $d;
                $ny = $dy / $d;
                $this->push($m, $o, $nx, $ny, $rr - $d);
                if ($friendly || $this->inert($m) || $this->inert($o)) {
                    continue;                        // alliés ou joueur en phase de choix : poussée seulement, aucun dégât
                }
                $this->dealDamage($o, $this->contactDmg($m), $this->attrib($m));
                $this->dealDamage($m, $this->contactDmg($o), $this->attrib($o));
            }
        }
    }

    /**
     * Balle contre balle : deux projectiles de joueurs (ou équipes) différents se percutent. Chacun perd
     * (dégâts de l'autre x CLASH_K) de durabilité et disparaît à 0 : un Sniper ou un Destroyer perce les
     * rafales de Machine Gun, qui s'écrasent sur lui. Les pièges sont des projectiles comme les autres (murs).
     *
     * Performance : grille de balles reconstruite à chaque tick, voisinage 3x3, chaque paire testée une seule fois
     * (id croissant), rejet rapide par distance, puis test exact de la trajectoire du tick (approche minimale de
     * deux mouvements linéaires) : pas de "tunnel" même entre deux balles de Ranger qui se croisent à 185 px/tick.
     */
    private function collideBulletClashes(): void
    {
        if (count($this->bullets) < 2) {
            return;
        }
        $last = $this->cols - 1;
        $bg = [];
        foreach ($this->bullets as $b) {
            $bg[$this->cellOf($b->x, $b->y)][] = $b;
        }
        $sparks = 0;
        foreach ($this->bullets as $b) {
            if ($b->hp <= 0.0) {
                continue;
            }
            $cx = min($last, max(0, (int) ($b->x / self::CELL)));
            $cy = min($last, max(0, (int) ($b->y / self::CELL)));
            for ($ny = max(0, $cy - 1); $ny <= min($last, $cy + 1); $ny++) {
                for ($nx = max(0, $cx - 1); $nx <= min($last, $cx + 1); $nx++) {
                    foreach ($bg[$ny * $this->cols + $nx] ?? [] as $o) {
                        if ($o->id <= $b->id || $o->hp <= 0.0 || $b->hp <= 0.0) {
                            continue;                // chaque paire une seule fois, balles déjà détruites ignorées
                        }
                        if ($o->owner === $b->owner || ($b->team !== 0 && $o->team === $b->team) || !$this->linked($b->tier, $o->tier)) {
                            continue;                // mêmes tireurs / même équipe : elles s'ignorent
                        }
                        $dx = $o->x - $b->x;
                        $dy = $o->y - $b->y;
                        $reach = $b->r + $o->r + $b->seg + $o->seg;
                        if ($dx * $dx + $dy * $dy > $reach * $reach || isset($b->clash[$o->id])) {
                            continue;
                        }
                        // approche minimale des deux trajectoires linéaires sur ce tick
                        $rx = $o->px - $b->px;
                        $ry = $o->py - $b->py;
                        $vx = ($o->x - $o->px) - ($b->x - $b->px);
                        $vy = ($o->y - $o->py) - ($b->y - $b->py);
                        $v2 = $vx * $vx + $vy * $vy;
                        $t = $v2 > 1e-6 ? max(0.0, min(1.0, -($rx * $vx + $ry * $vy) / $v2)) : 0.0;
                        $mx = $rx + $vx * $t;
                        $my = $ry + $vy * $t;
                        $rr = $b->r + $o->r;
                        if ($mx * $mx + $my * $my > $rr * $rr) {
                            continue;
                        }
                        $b->clash[$o->id] = true;
                        $b->hp -= $o->dmg * self::CLASH_K;
                        $o->hp -= $b->dmg * self::CLASH_K;
                        foreach ([$b, $o] as $x) {
                            if ($x->hp > 0.0) {
                                continue;
                            }
                            if ($x->kind === 3) {
                                $this->explode($x);  // un feu d'artifice abattu explose quand même
                            }
                            unset($this->bullets[$x->id]);
                            if ($sparks++ < 12) {    // étincelles côté client (plafonnées pour le réseau)
                                $this->addFx($x->tier, ['k', (int) (($x->x + $x->px) / 2), (int) (($x->y + $x->py) / 2)]);
                            }
                        }
                    }
                }
            }
        }
    }

    private function collideBullets(): void
    {
        foreach ($this->bullets as $bid => $b) {
            $trap = $b->kind === 1;
            $sx = $b->x - $b->px;
            $sy = $b->y - $b->py;
            $seg2 = $sx * $sx + $sy * $sy;
            $seg = sqrt($seg2);
            // Balayage du segment parcouru ce tick : une balle très rapide ne traverse plus les cibles
            foreach ($this->query($b->px + $sx / 2, $b->py + $sy / 2, $b->r + $seg / 2) as $t) {
                if ($t->hp <= 0.0 || $this->ownerOf($t) === $b->owner || ($b->team !== 0 && $t->team === $b->team)) {
                    continue;
                }
                if (!($t instanceof Shape) && !$this->linked($b->tier, $t->tier)) {
                    continue;                        // la balle traverse un joueur d'un palier incompatible
                }
                if ($t instanceof Player && (!$t->alive || $t->busy)) {
                    continue;                        // joueur en phase de choix : intouchable
                }
                $last = $b->hit[$t->id] ?? -999;
                if ($trap ? ($last + 12 > $this->tickNo) : ($last !== -999)) {
                    continue;                        // une balle touche une cible une fois ; un piège toutes les 12 ticks
                }
                $u = $seg2 > 0.0001
                    ? max(0.0, min(1.0, (($t->x - $b->px) * $sx + ($t->y - $b->py) * $sy) / $seg2))
                    : 1.0;
                $bx = $b->px + $sx * $u;             // point du segment le plus proche de la cible
                $by = $b->py + $sy * $u;
                $dx = $t->x - $bx;
                $dy = $t->y - $by;
                $rr = $t->r + $b->r;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 > $rr * $rr) {
                    continue;
                }
                $b->hit[$t->id] = $this->tickNo;
                $dmg = $trap ? $b->dmg * 0.6 : $b->dmg;

                if ($t instanceof Player) {
                    $res = 2.0;
                    $this->hurt($t, $dmg, $this->byId[$b->owner] ?? null);
                } else {
                    $res = $t instanceof Drone ? 1.0 : self::SHAPES[$t->type]['res'];
                    $t->hp -= $dmg;
                    $t->lastHit = $this->tickNo;
                    $t->lastAttacker = $b->owner;
                    if ($t instanceof Shape) {
                        $this->hitShapes[$t->id] = $t;
                    }
                }
                $k = 0.03 * $this->invMass($t);
                $t->vx += $b->vx * $k;
                $t->vy += $b->vy * $k;
                if ($trap) {                         // un piège fait aussi office de mur
                    $d = max(1.0, sqrt($d2));
                    $ov = $rr - $d;
                    $t->x += $dx / $d * $ov * 0.5 * min(1.0, $this->invMass($t));
                    $t->y += $dy / $d * $ov * 0.5 * min(1.0, $this->invMass($t));
                }
                $this->numEvent($b->owner, $t, $bx, $by, $dmg);

                $b->pen -= $res;
                if ($b->pen <= 0.0) {
                    if ($b->kind === 3) {
                        $this->explode($b);
                    }
                    unset($this->bullets[$bid]);
                    break;
                }
            }
        }
    }

    /** Chiffres de dégâts flottants : seulement pour l'attaquant et la victime. */
    private function numEvent(int $ownerId, object $t, float $x, float $y, float $dmg): void
    {
        $n = max(1, (int) round($dmg));
        $o = $this->byId[$ownerId] ?? null;
        if ($o !== null && count($o->ev) < 12) {
            $o->ev[] = ['d', (int) $x, (int) $y, $n, 0];
        }
        if ($t instanceof Player && $t->shield <= 0 && count($t->ev) < 12) {
            $t->ev[] = ['d', (int) $x, (int) $y, $n, 1];
        }
    }

    // ================================================================== morts, XP, progression

    private function resolveDeaths(): void
    {
        foreach ($this->hitShapes as $id => $s) {
            if ($s->hp > 0.0) {
                continue;
            }
            if ($s->lastAttacker >= 0) {
                $k = $this->byId[$s->lastAttacker] ?? null;
                if ($k !== null && $k->alive) {
                    $this->addXp($k, self::SHAPES[$s->type]['xp']);
                }
                $this->addFx(0, ['x', (int) $s->x, (int) $s->y, $s->type, (int) $s->r, 0]);
            }
            if ($s->type === 5) {
                $this->alpha = null;
                $this->bigs = [];
                $this->alphaAt = $this->tickNo + self::ALPHA_RESPAWN;
            } else {
                $this->counts[$s->type]--;
                unset($this->sgrid[$s->cell][$id], $this->crashers[$id]);
                if (empty($this->sgrid[$s->cell])) {
                    unset($this->sgrid[$s->cell]);
                }
            }
            unset($this->shapes[$id]);
        }
        $this->hitShapes = [];
        foreach ($this->drones as $id => $d) {
            if ($d->hp > 0.0) {
                continue;
            }
            $o = $this->byId[$d->owner] ?? null;
            if ($o !== null) {
                $o->drones = max(0, $o->drones - 1);
            }
            unset($this->drones[$id]);
        }
        foreach ($this->players as $p) {
            if ($p->alive && $p->hp <= 0.0) {
                $this->kill($p);
            }
        }
    }

    private function kill(Player $v): void
    {
        $v->alive = false;
        $this->removeDronesOf($v->id);
        unset($this->byId[$v->id]);
        $this->addFx($v->tier, ['x', (int) $v->x, (int) $v->y, 0, (int) $v->r, $v->team]);
        $k = $this->byId[$v->lastAttacker] ?? null;
        if ($k !== null && $k->alive) {
            $this->addXp($k, intdiv($v->xp, 2) + 30);
        }
        $v->conn->send(json_encode(
            ['t' => 'd', 'k' => $k?->name ?? '', 's' => $v->xp, 'l' => $v->level],
            JSON_UNESCAPED_UNICODE
        ));
    }

    private function addXp(Player $p, int $xp): void
    {
        $p->xp += $xp;
        while ($p->level < self::MAX_LEVEL && $p->xp >= $this->xpTable[$p->level + 1]) {
            $p->level++;
            $p->points += $this->pointsForLevel($p->level);
        }
        $this->recalc($p);
    }

    /**
     * Points gagnés en atteignant un niveau : 1 par niveau jusqu'au 100 = 99 points au total.
     * Maxer les 8 compétences (56) + 3 super-compétences (+12) = 68 ; Auto Smasher (10 partout) = 80 + 12 = 92.
     */
    private function pointsForLevel(int $level): int
    {
        return 1;
    }

    /** Maximum de base d'une compétence pour la classe (0 = verrouillée). */
    private function statBase(Player $p, int $stat): int
    {
        $cls = $this->effCls($p);
        return ($stat >= 3 && $stat <= 6) ? $cls['mxg'] : $cls['mxb'];
    }

    /** Maximum effectif : base + 4 crans pour les super-compétences choisies. */
    private function statMax(Player $p, int $stat): int
    {
        return $this->statBase($p, $stat) + (in_array($stat, $p->supers, true) ? self::SUPER_BONUS : 0);
    }

    private function recalc(Player $p): void
    {
        $cls = $this->effCls($p);
        $old = $p->maxHp;
        $p->maxHp = (50.0 + 2.0 * ($p->level - 1) + 20.0 * $p->stats[1]) * $cls['hp'];
        $p->hp = min($p->maxHp, $p->hp + max(0.0, $p->maxHp - $old));
        $p->r = (26.0 + 0.4 * min($p->level, 45) + 0.1 * max(0, $p->level - 45)) * $cls['rad'];
    }

    private function resetBarrels(Player $p): void
    {
        $n = max(1, count($this->effCls($p)['bar']));
        $p->bcd = array_fill(0, $n, 0.0);
        $p->ta = array_fill(0, $n, $p->angle);
        $p->tt = [];
    }

    // ================================================================== apparition des PNJ

    private function spawnShapes(bool $all): void
    {
        foreach ([2, 3, 4, 6] as $type) {
            $missing = $this->targets[$type] - $this->counts[$type];
            if ($missing <= 0) {
                continue;
            }
            $n = $all ? $missing : ($this->rnd() < 0.25 ? min($missing, 1 + intdiv($missing, 40)) : 0);
            for ($i = 0; $i < $n; $i++) {
                $this->spawnShape($type);
            }
        }
        if (!$all && $this->alpha === null && $this->tickNo >= $this->alphaAt) {
            $this->spawnAlpha();
        }
    }

    private function spawnShape(int $type): void
    {
        $def = self::SHAPES[$type];
        $c = self::WORLD / 2;
        $sc = self::WORLD / 4000;                    // la zone des pentagones / crashers grandit avec la carte
        $x = $y = 0.0;
        for ($try = 0; $try < 8; $try++) {
            $a = $this->rnd() * 2 * M_PI;
            if ($type === 4) {                       // pentagones : concentrés vers le centre
                $d = (260.0 + 740.0 * ($this->rnd() ** 1.5)) * $sc;
            } elseif ($type === 3) {
                $d = (500.0 + 1100.0 * sqrt($this->rnd())) * $sc;
            } elseif ($type === 6) {
                $d = (200.0 + 700.0 * sqrt($this->rnd())) * $sc;
            } else {
                $d = -1.0;
            }
            if ($d < 0) {
                $x = 80 + $this->rnd() * (self::WORLD - 160);
                $y = 80 + $this->rnd() * (self::WORLD - 160);
            } else {
                $x = $c + cos($a) * $d;
                $y = $c + sin($a) * $d;
            }
            if (!$this->teams || ($x > self::BASE_W + 70 && $x < self::WORLD - self::BASE_W - 70)) {
                break;
            }
        }
        $s = new Shape($this->nextId++, $type, $x, $y, $def['r'], $def['hp'], $def['hp']);
        $s->tx = $x;
        $s->ty = $y;
        if ($type !== 6) {
            $s->a = $this->rnd() * 2 * M_PI;
            $s->spin = ($this->rnd() - 0.5) * 1.0;
            $s->ox = ($this->rnd() - 0.5) * 16;
            $s->oy = ($this->rnd() - 0.5) * 16;
        }
        $this->addShape($s);
        $this->counts[$type]++;
    }

    /** Enregistre un polygone dans la grille persistante (et le registre des Crashers). */
    private function addShape(Shape $s): void
    {
        $s->cell = $this->cellOf($s->x, $s->y);
        $this->shapes[$s->id] = $s;
        $this->sgrid[$s->cell][$s->id] = $s;
        if ($s->type === 6) {
            $this->crashers[$s->id] = $s;
        }
    }

    private function spawnAlpha(): void
    {
        $def = self::SHAPES[5];
        $c = self::WORLD / 2;
        $s = new Shape($this->nextId++, 5, (float) $c, (float) $c, $def['r'], $def['hp'], $def['hp']);
        $s->a = $this->rnd() * 2 * M_PI;
        $s->spin = 0.12;
        $this->shapes[$s->id] = $s;
        $this->alpha = $s;
        $this->bigs = [$s];                           // l'Alpha est trop gros pour la grille
    }

    // ================================================================== mode Équipes

    private function buildBases(): void
    {
        $w = self::WORLD;
        $bw = self::BASE_W;
        $this->bases = [[0, 0, $bw, $w, 1], [$w - $bw, 0, $w, $w, 2]];
        for ($i = 0; $i < 8; $i++) {
            $y = $w * ($i + 0.5) / 8;
            $this->turrets[] = new Turret($this->nextId++, 1, $bw - 40.0, (float) $y);
            $this->turrets[] = new Turret($this->nextId++, 2, $w - $bw + 40.0, (float) $y);
        }
    }

    private function smallerTeam(): int
    {
        $n = [1 => 0, 2 => 0];
        foreach ($this->players as $p) {
            if ($p->team > 0) {
                $n[$p->team]++;
            }
        }
        return $n[1] <= $n[2] ? 1 : 2;
    }

    /** @return array{0:float,1:float} */
    private function spawnPos(int $team): array
    {
        $w = self::WORLD;
        $y = 200 + $this->rnd() * ($w - 400);
        if (!$this->teams) {
            return [200 + $this->rnd() * ($w - 400), $y];
        }
        $x = $team === 1 ? 60 + $this->rnd() * (self::BASE_W - 160) : $w - self::BASE_W + 100 + $this->rnd() * (self::BASE_W - 160);
        return [$x, $y];
    }

    private function inOwnBase(Player $p): bool
    {
        return $p->team === 1 ? $p->x < self::BASE_W : $p->x > self::WORLD - self::BASE_W;
    }

    // ================================================================== réseau : envoi par viewport

    private function broadcast(float $now): void
    {
        $ts = (int) (($now - $this->t0) * 1000);
        $sendLb = $this->tickNo % 6 === 0;
        $lbCache = [];   // un classement par palier (chacun ne voit que les joueurs compatibles)
        $fx = $this->fx;
        $this->fx = [];

        foreach ($this->players as $p) {
            if (!$p->alive) {
                $p->ev = [];
                continue;
            }
            $cls = $this->effCls($p);
            $fov = $cls['fov'];

            // Centre de vue (Predator : le clic droit décale la caméra vers le curseur)
            $vx = $p->x;
            $vy = $p->y;
            if ($cls['zm'] === 1 && $p->rmb) {
                $off = min($p->dist, 900.0) * 0.9;
                $vx += cos($p->angle) * $off;
                $vy += sin($p->angle) * $off;
            }
            $hw = self::VIEW_W / 2 * $fov + self::VIEW_MARGIN;
            $hh = self::VIEW_H / 2 * $fov + self::VIEW_MARGIN;
            $x0 = $vx - $hw;
            $x1 = $vx + $hw;
            $y0 = $vy - $hh;
            $y1 = $vy + $hh;

            $ents = [];
            foreach ($this->queryBox($x0, $y0, $x1, $y1) as $e) {
                if ($e->x < $x0 || $e->x > $x1 || $e->y < $y0 || $e->y > $y1) {
                    continue;
                }
                if ($e instanceof Player) {
                    if ($e->stealth && $e !== $p) {
                        continue;                    // tank furtif : invisible pour les autres
                    }
                    if ($e !== $p && !$this->sees($p, $e->tier, $e->team)) {
                        continue;                    // palier incompatible : le tank n'existe pas pour ce joueur
                    }
                    $en = [
                        'i' => $e->id, 't' => 0, 'x' => (int) round($e->x), 'y' => (int) round($e->y),
                        'a' => round($e->angle, 2), 'hp' => (int) round($e->hp / $e->maxHp * 100),
                        'r' => round($e->r, 1), 'c' => $e->cls, 'n' => $e->name, 'l' => $e->level, 'tm' => $e->team,
                    ];
                    if ($e->cls2 >= 0) {
                        $en['c2'] = $e->cls2;        // seconde classe fusionnée (rendu des canons)
                    }
                    if ($e->shield > 0) {
                        $en['sh'] = 1;
                    }
                    if ($e->busy) {
                        $en['bz'] = 1;               // bulle d'immunité (phase de choix)
                    }
                    if ($e->stealth) {
                        $en['sl'] = 1;               // le joueur furtif se voit lui-même en transparence
                    }
                    $ecls = $this->effCls($e);
                    if ($ecls['tsp'] > 0.0 || $this->hasAuto($ecls)) {
                        $en['ta'] = array_map(static fn (float $v): float => round($v, 2), $e->ta);
                        $en['sa'] = round($e->spinA, 2);
                    }
                    $ents[] = $en;
                } elseif ($e instanceof Drone) {
                    if (!$this->sees($p, $e->tier, $e->team)) {
                        continue;
                    }
                    $ents[] = [
                        'i' => $e->id, 't' => 8, 'x' => (int) round($e->x), 'y' => (int) round($e->y), 'a' => round($e->a, 2),
                        'hp' => (int) round($e->hp / $e->maxHp * 100), 'r' => round($e->r, 1), 'o' => $e->owner,
                        'k' => $e->kind, 'tm' => $e->team,
                    ];
                } else {
                    $en = [
                        'i' => $e->id, 't' => $e->type, 'x' => (int) round($e->x), 'y' => (int) round($e->y),
                        'a' => round($e->a, 2),
                    ];
                    $php = (int) round($e->hp / $e->maxHp * 100);
                    if ($php < 100) {
                        $en['hp'] = $php;             // absent = 100 % ; le rayon est déduit du type côté client
                    }
                    $ents[] = $en;
                }
            }
            foreach ($this->bullets as $b) {
                if ($b->x >= $x0 && $b->x <= $x1 && $b->y >= $y0 && $b->y <= $y1 && $this->sees($p, $b->tier, $b->team)) {
                    $en = [
                        'i' => $b->id, 't' => $b->kind === 0 ? 1 : ($b->kind === 1 ? 9 : 10),
                        'x' => (int) round($b->x), 'y' => (int) round($b->y), 'r' => round($b->r, 1),
                        'o' => $b->owner, 'tm' => $b->team,
                    ];
                    if ($b->kind === 1) {
                        $en['a'] = round($b->a, 2);
                    } elseif ($b->kind >= 2) {
                        $en['a'] = round(atan2($b->vy, $b->vx), 2);
                    }
                    $ents[] = $en;
                }
            }
            foreach ($this->turrets as $t) {
                if ($t->x >= $x0 && $t->x <= $x1 && $t->y >= $y0 && $t->y <= $y1) {
                    $ents[] = ['i' => $t->id, 't' => 7, 'x' => (int) $t->x, 'y' => (int) $t->y, 'a' => round($t->a, 2), 'r' => $t->r, 'tm' => $t->team];
                }
            }

            $mx = [];
            for ($i = 0; $i < 8; $i++) {
                $mx[] = $this->statMax($p, $i);
            }
            $msg = [
                't' => 'u', 'ts' => $ts, 'e' => $ents,
                'me' => [
                    'x' => (int) $p->x, 'y' => (int) $p->y, 'hp' => (int) $p->hp, 'mh' => (int) $p->maxHp,
                    'xp' => $p->xp, 'lo' => $this->xpTable[$p->level],
                    'nx' => $this->xpTable[$p->level + 1] ?? $this->xpTable[$p->level],
                    'lv' => $p->level, 'pt' => $p->points, 'st' => $p->stats, 'mx' => $mx,
                    'c' => $p->cls, 'cc' => $this->choices($p), 'fv' => $fov, 'dr' => $p->drones,
                    'sp' => $p->supers, 'ss' => $this->canPickSupers($p) ? 1 : 0, 'bz' => $p->busy ? 1 : 0,
                    'c2' => $p->cls2, 'fu' => $this->canFuse($p) ? 1 : 0, 'tr' => $p->tier,
                ],
            ];

            $evs = $p->ev;
            $p->ev = [];
            foreach ($fx as [$ft, $f]) {
                if ($this->linked($p->tier, $ft) && $f[1] >= $x0 && $f[1] <= $x1 && $f[2] >= $y0 && $f[2] <= $y1) {
                    $evs[] = $f;
                }
            }
            if ($evs) {
                $msg['ev'] = $evs;
            }
            if ($sendLb) {
                $lb = $lbCache[$p->tier] ??= $this->leaderboard($p->tier);
                $msg['lb'] = $lb['top'];
                if ($lb['teams'] !== null) {
                    $msg['ts2'] = $lb['teams'];
                }
            }
            $p->conn->send(json_encode($msg, JSON_UNESCAPED_UNICODE));
        }
    }

    private function hasAuto(array $cls): bool
    {
        foreach ($cls['bar'] as $b) {
            if ($b['k'] === 'a') {
                return true;
            }
        }
        return false;
    }

    /** @return int[] classes que le joueur peut choisir maintenant */
    private function choices(Player $p): array
    {
        $out = [];
        foreach (ClassTree::get($p->cls)['up'] as $id) {
            if ($p->level >= ClassTree::get($id)['lv']) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return array{top:array,teams:?array} */
    private function leaderboard(int $viewerTier): array
    {
        $alive = array_filter($this->players, fn (Player $p): bool => $p->alive && $this->linked($viewerTier, $p->tier));
        usort($alive, static fn (Player $a, Player $b): int => $b->xp <=> $a->xp);
        $top = array_map(
            static fn (Player $p): array => [$p->name, $p->xp, $p->id, $p->team],
            array_slice($alive, 0, 5)
        );
        $teams = null;
        if ($this->teams) {
            $teams = [0, 0];
            foreach ($alive as $p) {
                $teams[$p->team - 1] += $p->xp;
            }
        }
        return ['top' => $top, 'teams' => $teams];
    }

    /** Évènement visuel (explosion, étincelles) visible seulement des paliers compatibles avec $tier. */
    private function addFx(int $tier, array $ev): void
    {
        $this->fx[] = [$tier, $ev];
    }

    private function rnd(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
