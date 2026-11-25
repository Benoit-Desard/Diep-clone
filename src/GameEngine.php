<?php
declare(strict_types=1);

namespace Diep;

use Ratchet\ConnectionInterface;

final class Player
{
    public int $id = 0;
    public float $x = 0.0;
    public float $y = 0.0;
    public float $vx = 0.0;
    public float $vy = 0.0;
    public float $angle = 0.0;
    public float $hp = 0.0;
    public float $maxHp = 0.0;
    public float $r = 26.0;
    public int $level = 1;
    public int $xp = 0;
    public int $points = 0;
    /** @var int[] */
    public array $stats = [0, 0, 0, 0, 0, 0, 0, 0];
    public int $cls = 0;
    public int $keys = 0;
    public bool $fire = false;
    public float $cd = 0.0;
    public int $barrel = 0;
    public int $shield = 0;
    public int $lastHitTick = 0;
    public int $lastAttacker = 0;
    public bool $alive = true;

    public function __construct(
        public readonly int $cid,
        public readonly string $name,
        public readonly ConnectionInterface $conn
    ) {
    }
}

final class Shape
{
    public float $vx = 0.0;
    public float $vy = 0.0;
    public float $a = 0.0;
    public float $spin = 0.0;
    public float $ox = 0.0;
    public float $oy = 0.0;
    public int $lastAttacker = 0;

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

final class Bullet
{
    /** @var array<int,bool> entités déjà touchées par cette balle */
    public array $hit = [];

    public function __construct(
        public int $id,
        public int $owner,
        public float $x,
        public float $y,
        public float $vx,
        public float $vy,
        public float $r,
        public float $dmg,
        public float $pen,
        public float $life
    ) {
    }
}

/**
 * Moteur de jeu autoritaire. Toute la simulation se passe ici, côté serveur.
 * Types d'entités envoyés au client : 0 tank, 1 balle, 2 carré, 3 triangle, 4 pentagone.
 */
final class GameEngine
{
    public const TICK = 30;
    public const WORLD = 4000;

    private const CELL = 256;          // taille d'une cellule de la grille spatiale
    private const MAX_R = 56;          // rayon max d'une entité (marge de requête)
    private const MAX_LEVEL = 45;
    private const CLASS_LEVEL = 15;
    private const VIEW_W = 1920;       // zone visible de référence côté client (unités monde)
    private const VIEW_H = 1080;
    private const VIEW_MARGIN = 150;
    private const SHIELD_TICKS = 90;   // protection de 3 s au spawn

    private const SHAPES = [
        2 => ['hp' => 10.0,  'r' => 24.0, 'xp' => 10,  'dmg' => 0.20, 'res' => 1.0, 'target' => 160],
        3 => ['hp' => 30.0,  'r' => 26.0, 'xp' => 25,  'dmg' => 0.45, 'res' => 1.5, 'target' => 50],
        4 => ['hp' => 100.0, 'r' => 44.0, 'xp' => 130, 'dmg' => 0.60, 'res' => 2.5, 'target' => 14],
    ];

    // len : longueur du canon (x rayon du tank), br : rayon de balle (x rayon du tank)
    private const CLASSES = [
        0 => ['len' => 1.75, 'br' => 0.38, 'reload' => 1.0,  'dmg' => 1.0,  'speed' => 1.0,  'pen' => 1.0, 'life' => 1.0, 'spread' => 0.0,  'recoil' => 45.0, 'fov' => 1.0],
        1 => ['len' => 1.75, 'br' => 0.30, 'reload' => 0.5,  'dmg' => 0.85, 'speed' => 1.0,  'pen' => 1.0, 'life' => 1.0, 'spread' => 0.0,  'recoil' => 30.0, 'fov' => 1.0],
        2 => ['len' => 2.3,  'br' => 0.33, 'reload' => 1.7,  'dmg' => 2.0,  'speed' => 1.5,  'pen' => 1.4, 'life' => 1.5, 'spread' => 0.0,  'recoil' => 95.0, 'fov' => 1.4],
        3 => ['len' => 1.7,  'br' => 0.30, 'reload' => 0.38, 'dmg' => 0.6,  'speed' => 0.95, 'pen' => 0.8, 'life' => 1.0, 'spread' => 0.2,  'recoil' => 16.0, 'fov' => 1.0],
    ];

    /** @var array<int,Player> cid => joueur */
    private array $players = [];
    /** @var array<int,Player> id d'entité => joueur vivant */
    private array $byId = [];
    /** @var array<int,Shape> */
    private array $shapes = [];
    /** @var array<int,Bullet> */
    private array $bullets = [];
    /** @var array<int,array<int,Player|Shape>> grille spatiale : cellule => entités ciblables */
    private array $grid = [];
    /** @var array<int,int> */
    private array $counts = [2 => 0, 3 => 0, 4 => 0];
    /** @var int[] */
    private array $xpTable = [0, 0];

    private int $cols;
    private int $nextId = 1;
    private int $tickNo = 0;
    private float $t0;
    private float $lastTime;

    public function __construct()
    {
        $this->cols = (int) ceil(self::WORLD / self::CELL);
        for ($l = 2; $l <= self::MAX_LEVEL; $l++) {
            $this->xpTable[$l] = (int) floor(8 * ($l - 1) ** 1.8);
        }
        $this->t0 = $this->lastTime = microtime(true);
        $this->spawnShapes(true);
    }

    // ------------------------------------------------------------------ API réseau

    public function join(int $cid, ConnectionInterface $conn, string $name): void
    {
        if (isset($this->players[$cid]) && $this->players[$cid]->alive) {
            return;
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        $name = mb_substr($name, 0, 15);
        if ($name === '') {
            $name = 'Tank' . $cid;
        }

        $p = new Player($cid, $name, $conn);
        $p->id = $this->nextId++;
        $p->x = 200 + $this->rnd() * (self::WORLD - 400);
        $p->y = 200 + $this->rnd() * (self::WORLD - 400);
        $p->shield = self::SHIELD_TICKS;
        $p->lastHitTick = $this->tickNo;
        $this->recalc($p);

        $this->players[$cid] = $p;
        $this->byId[$p->id] = $p;

        $conn->send(json_encode(['t' => 'sp', 'id' => $p->id, 'w' => self::WORLD], JSON_UNESCAPED_UNICODE));
    }

    public function leave(int $cid): void
    {
        if (isset($this->players[$cid])) {
            unset($this->byId[$this->players[$cid]->id], $this->players[$cid]);
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
        if (is_int($a) || is_float($a)) {
            $a = (float) $a;
            if (is_finite($a)) {
                $p->angle = $a;
            }
        }
        $p->fire = !empty($d['f']);
    }

    public function upgrade(int $cid, int $stat): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive || $stat < 0 || $stat > 7 || $p->points < 1 || $p->stats[$stat] >= 7) {
            return;
        }
        $p->stats[$stat]++;
        $p->points--;
        $this->recalc($p);
    }

    public function evolve(int $cid, int $cls): void
    {
        $p = $this->players[$cid] ?? null;
        if ($p === null || !$p->alive || $p->cls !== 0 || $p->level < self::CLASS_LEVEL || $cls < 1 || $cls > 3) {
            return;
        }
        $p->cls = $cls;
    }

    // ------------------------------------------------------------------ boucle de jeu

    public function tick(): void
    {
        $now = microtime(true);
        $dt = min(0.1, max(0.001, $now - $this->lastTime));
        $this->lastTime = $now;
        $this->tickNo++;

        $this->updatePlayers($dt);
        $this->updateShapes($dt);
        $this->updateBullets($dt);
        $this->buildGrid();
        $this->collideBodies();
        $this->collideBullets();
        $this->resolveDeaths();
        $this->spawnShapes(false);
        $this->broadcast($now);
    }

    private function updatePlayers(float $dt): void
    {
        $k = 5.0; // frottement (1/s) : vitesse max = accélération / k
        $friction = exp(-$k * $dt);
        $max = (float) self::WORLD;

        foreach ($this->players as $p) {
            if (!$p->alive) {
                continue;
            }
            $ax = (($p->keys & 8) ? 1 : 0) - (($p->keys & 4) ? 1 : 0);
            $ay = (($p->keys & 2) ? 1 : 0) - (($p->keys & 1) ? 1 : 0);
            if ($ax !== 0 && $ay !== 0) {
                $ax *= M_SQRT1_2;
                $ay *= M_SQRT1_2;
            }
            $acc = (240.0 + 22.0 * $p->stats[7]) * $k;
            $p->vx = ($p->vx + $ax * $acc * $dt) * $friction;
            $p->vy = ($p->vy + $ay * $acc * $dt) * $friction;
            $p->x = min($max - $p->r, max($p->r, $p->x + $p->vx * $dt));
            $p->y = min($max - $p->r, max($p->r, $p->y + $p->vy * $dt));

            if ($p->shield > 0) {
                $p->shield--;
            }
            if ($p->hp < $p->maxHp && $this->tickNo - $p->lastHitTick > 90) {
                $p->hp = min($p->maxHp, $p->hp + $p->maxHp * (0.01 + 0.012 * $p->stats[0]) * $dt);
            }

            $p->cd -= $dt;
            if ($p->cd < -$dt) {
                $p->cd = -$dt;
            }
            if ($p->fire && $p->cd <= 0.0) {
                $p->shield = 0;
                $this->shoot($p);
                $p->cd += 0.5 * (0.87 ** $p->stats[6]) * self::CLASSES[$p->cls]['reload'];
            }
        }
    }

    private function shoot(Player $p): void
    {
        $c = self::CLASSES[$p->cls];
        $a = $p->angle;
        if ($c['spread'] > 0.0) {
            $a += ($this->rnd() - 0.5) * 2.0 * $c['spread'];
        }
        $cos = cos($a);
        $sin = sin($a);

        $off = 0.0;
        if ($p->cls === 1) {                       // Twin : canons alternés
            $p->barrel ^= 1;
            $off = ($p->barrel === 1 ? 0.42 : -0.42) * $p->r;
        }
        $len = $p->r * $c['len'];
        $x = $p->x + $cos * $len - $sin * $off;
        $y = $p->y + $sin * $len + $cos * $off;

        $speed = (560.0 + 75.0 * $p->stats[3]) * $c['speed'];
        $bullet = new Bullet(
            $this->nextId++,
            $p->id,
            $x,
            $y,
            $cos * $speed,
            $sin * $speed,
            $p->r * $c['br'],
            (7.0 + 3.0 * $p->stats[5]) * $c['dmg'],
            (1.0 + 0.9 * $p->stats[4]) * $c['pen'],
            1.8 * $c['life']
        );
        $this->bullets[$bullet->id] = $bullet;

        // Recul
        $p->vx -= $cos * $c['recoil'];
        $p->vy -= $sin * $c['recoil'];
    }

    private function updateShapes(float $dt): void
    {
        $f = exp(-2.5 * $dt);
        $w = (float) self::WORLD;
        foreach ($this->shapes as $s) {
            $s->x += ($s->vx + $s->ox) * $dt;
            $s->y += ($s->vy + $s->oy) * $dt;
            $s->vx *= $f;
            $s->vy *= $f;
            $s->a += $s->spin * $dt;

            if ($s->x < $s->r)      { $s->x = $s->r;      $s->ox = abs($s->ox); }
            if ($s->x > $w - $s->r) { $s->x = $w - $s->r; $s->ox = -abs($s->ox); }
            if ($s->y < $s->r)      { $s->y = $s->r;      $s->oy = abs($s->oy); }
            if ($s->y > $w - $s->r) { $s->y = $w - $s->r; $s->oy = -abs($s->oy); }
        }
    }

    private function updateBullets(float $dt): void
    {
        $lim = self::WORLD + 50;
        foreach ($this->bullets as $id => $b) {
            $b->x += $b->vx * $dt;
            $b->y += $b->vy * $dt;
            $b->life -= $dt;
            if ($b->life <= 0.0 || $b->x < -50 || $b->y < -50 || $b->x > $lim || $b->y > $lim) {
                unset($this->bullets[$id]);
            }
        }
    }

    // ------------------------------------------------------------------ grille spatiale

    private function buildGrid(): void
    {
        $this->grid = [];
        foreach ($this->shapes as $s) {
            $this->grid[$this->cellOf($s->x, $s->y)][] = $s;
        }
        foreach ($this->players as $p) {
            if ($p->alive) {
                $this->grid[$this->cellOf($p->x, $p->y)][] = $p;
            }
        }
    }

    private function cellOf(float $x, float $y): int
    {
        $cx = min($this->cols - 1, max(0, (int) ($x / self::CELL)));
        $cy = min($this->cols - 1, max(0, (int) ($y / self::CELL)));
        return $cy * $this->cols + $cx;
    }

    /** @return array<int,Player|Shape> entités dont le centre est proche du cercle (x, y, rad) */
    private function query(float $x, float $y, float $rad): array
    {
        $m = $rad + self::MAX_R;
        return $this->queryBox($x - $m, $y - $m, $x + $m, $y + $m);
    }

    /** @return array<int,Player|Shape> */
    private function queryBox(float $x0, float $y0, float $x1, float $y1): array
    {
        $last = $this->cols - 1;
        $cx0 = max(0, (int) ($x0 / self::CELL));
        $cx1 = min($last, (int) ($x1 / self::CELL));
        $cy0 = max(0, (int) ($y0 / self::CELL));
        $cy1 = min($last, (int) ($y1 / self::CELL));
        $out = [];
        for ($cy = $cy0; $cy <= $cy1; $cy++) {
            for ($cx = $cx0; $cx <= $cx1; $cx++) {
                $cell = $this->grid[$cy * $this->cols + $cx] ?? null;
                if ($cell !== null) {
                    foreach ($cell as $e) {
                        $out[] = $e;
                    }
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ collisions

    private function collideBodies(): void
    {
        foreach ($this->players as $p) {
            if (!$p->alive) {
                continue;
            }
            foreach ($this->query($p->x, $p->y, $p->r) as $o) {
                if ($o === $p) {
                    continue;
                }
                $dx = $o->x - $p->x;
                $dy = $o->y - $p->y;
                $rr = $p->r + $o->r;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 >= $rr * $rr) {
                    continue;
                }
                if ($o instanceof Player && $o->id < $p->id) {
                    continue; // paire déjà traitée depuis l'autre joueur
                }
                $d = sqrt($d2);
                if ($d < 0.01) {
                    $dx = 1.0;
                    $dy = 0.0;
                    $d = 1.0;
                }
                $nx = $dx / $d;
                $ny = $dy / $d;
                $ov = $rr - $d;

                if ($o instanceof Player) {
                    $p->x -= $nx * $ov * 0.5;
                    $p->y -= $ny * $ov * 0.5;
                    $o->x += $nx * $ov * 0.5;
                    $o->y += $ny * $ov * 0.5;
                    $p->vx -= $nx * $ov * 8;
                    $p->vy -= $ny * $ov * 8;
                    $o->vx += $nx * $ov * 8;
                    $o->vy += $ny * $ov * 8;
                    $this->hurt($o, $this->bodyDamage($p), $p);
                    $this->hurt($p, $this->bodyDamage($o), $o);
                } else {
                    $p->x -= $nx * $ov * 0.3;
                    $p->y -= $ny * $ov * 0.3;
                    $o->x += $nx * $ov * 0.7;
                    $o->y += $ny * $ov * 0.7;
                    $p->vx -= $nx * $ov * 4;
                    $p->vy -= $ny * $ov * 4;
                    $o->vx += $nx * $ov * 8;
                    $o->vy += $ny * $ov * 8;
                    $o->hp -= $this->bodyDamage($p);
                    $o->lastAttacker = $p->id;
                    $this->hurt($p, self::SHAPES[$o->type]['dmg'], null);
                }
            }
        }
    }

    private function collideBullets(): void
    {
        foreach ($this->bullets as $bid => $b) {
            foreach ($this->query($b->x, $b->y, $b->r) as $t) {
                if (isset($b->hit[$t->id])) {
                    continue;
                }
                if ($t instanceof Player) {
                    if ($t->id === $b->owner) {
                        continue;
                    }
                    $res = 2.0;
                } else {
                    $res = self::SHAPES[$t->type]['res'];
                }
                $dx = $t->x - $b->x;
                $dy = $t->y - $b->y;
                $rr = $t->r + $b->r;
                if ($dx * $dx + $dy * $dy > $rr * $rr) {
                    continue;
                }

                $b->hit[$t->id] = true;
                if ($t instanceof Player) {
                    $this->hurt($t, $b->dmg, $this->byId[$b->owner] ?? null);
                    $t->vx += $b->vx * 0.02;
                    $t->vy += $b->vy * 0.02;
                } else {
                    $t->hp -= $b->dmg;
                    $t->lastAttacker = $b->owner;
                    $t->vx += $b->vx * 0.05;
                    $t->vy += $b->vy * 0.05;
                }
                $b->pen -= $res;
                if ($b->pen <= 0.0) {
                    unset($this->bullets[$bid]);
                    break;
                }
            }
        }
    }

    private function bodyDamage(Player $p): float
    {
        return 0.5 + 0.3 * $p->stats[2]; // par tick, tant que le contact dure
    }

    private function hurt(Player $p, float $dmg, ?Player $by): void
    {
        if ($p->shield > 0) {
            return;
        }
        $p->hp -= $dmg;
        $p->lastHitTick = $this->tickNo;
        if ($by !== null) {
            $p->lastAttacker = $by->id;
        }
    }

    private function resolveDeaths(): void
    {
        foreach ($this->shapes as $id => $s) {
            if ($s->hp > 0.0) {
                continue;
            }
            $k = $this->byId[$s->lastAttacker] ?? null;
            if ($k !== null && $k->alive) {
                $this->addXp($k, self::SHAPES[$s->type]['xp']);
            }
            $this->counts[$s->type]--;
            unset($this->shapes[$id]);
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
        unset($this->byId[$v->id]);
        $k = $this->byId[$v->lastAttacker] ?? null;
        if ($k !== null && $k->alive) {
            $this->addXp($k, intdiv($v->xp, 2) + 30);
        }
        $v->conn->send(json_encode(
            ['t' => 'd', 'k' => $k?->name ?? '', 's' => $v->xp, 'l' => $v->level],
            JSON_UNESCAPED_UNICODE
        ));
    }

    // ------------------------------------------------------------------ progression

    private function addXp(Player $p, int $xp): void
    {
        $p->xp += $xp;
        while ($p->level < self::MAX_LEVEL && $p->xp >= $this->xpTable[$p->level + 1]) {
            $p->level++;
            $p->points++;
        }
        $this->recalc($p);
    }

    private function recalc(Player $p): void
    {
        $old = $p->maxHp;
        $p->maxHp = 50.0 + 2.0 * ($p->level - 1) + 20.0 * $p->stats[1];
        $p->hp = min($p->maxHp, $p->hp + max(0.0, $p->maxHp - $old));
        $p->r = 26.0 + 0.4 * $p->level;
    }

    // ------------------------------------------------------------------ apparition des polygones

    private function spawnShapes(bool $all): void
    {
        foreach (self::SHAPES as $type => $def) {
            $missing = $def['target'] - $this->counts[$type];
            if ($missing <= 0) {
                continue;
            }
            $n = $all ? $missing : ($this->rnd() < 0.1 ? 1 : 0);
            for ($i = 0; $i < $n; $i++) {
                $this->spawnShape($type);
            }
        }
    }

    private function spawnShape(int $type): void
    {
        $def = self::SHAPES[$type];
        $c = self::WORLD / 2;
        $a = $this->rnd() * M_PI * 2;

        if ($type === 4) {            // pentagones : concentrés vers le centre
            $d = 950.0 * $this->rnd();
            $x = $c + cos($a) * $d;
            $y = $c + sin($a) * $d;
        } elseif ($type === 3) {      // triangles : anneau intermédiaire
            $d = 450.0 + 1150.0 * sqrt($this->rnd());
            $x = $c + cos($a) * $d;
            $y = $c + sin($a) * $d;
        } else {                      // carrés : partout
            $x = 80 + $this->rnd() * (self::WORLD - 160);
            $y = 80 + $this->rnd() * (self::WORLD - 160);
        }

        $s = new Shape($this->nextId++, $type, $x, $y, $def['r'], $def['hp'], $def['hp']);
        $s->a = $this->rnd() * M_PI * 2;
        $s->spin = ($this->rnd() - 0.5) * 1.0;
        $s->ox = ($this->rnd() - 0.5) * 16;
        $s->oy = ($this->rnd() - 0.5) * 16;
        $this->shapes[$s->id] = $s;
        $this->counts[$type]++;
    }

    // ------------------------------------------------------------------ réseau : envoi par viewport

    private function broadcast(float $now): void
    {
        $ts = (int) (($now - $this->t0) * 1000);
        $lb = ($this->tickNo % 6 === 0) ? $this->leaderboard() : null;

        foreach ($this->players as $p) {
            if (!$p->alive) {
                continue;
            }
            $fov = self::CLASSES[$p->cls]['fov'];
            $hw = self::VIEW_W / 2 * $fov + self::VIEW_MARGIN;
            $hh = self::VIEW_H / 2 * $fov + self::VIEW_MARGIN;
            $x0 = $p->x - $hw;
            $x1 = $p->x + $hw;
            $y0 = $p->y - $hh;
            $y1 = $p->y + $hh;

            $ents = [];
            foreach ($this->queryBox($x0, $y0, $x1, $y1) as $e) {
                if ($e->x < $x0 || $e->x > $x1 || $e->y < $y0 || $e->y > $y1) {
                    continue;
                }
                if ($e instanceof Player) {
                    $ents[] = [
                        'i' => $e->id, 't' => 0,
                        'x' => (int) round($e->x), 'y' => (int) round($e->y),
                        'a' => round($e->angle, 2),
                        'hp' => (int) round($e->hp / $e->maxHp * 100),
                        'r' => round($e->r, 1),
                        'c' => $e->cls, 'n' => $e->name, 'l' => $e->level,
                    ];
                } else {
                    $ents[] = [
                        'i' => $e->id, 't' => $e->type,
                        'x' => (int) round($e->x), 'y' => (int) round($e->y),
                        'a' => round($e->a, 2),
                        'hp' => (int) round($e->hp / $e->maxHp * 100),
                        'r' => $e->r,
                    ];
                }
            }
            foreach ($this->bullets as $b) {
                if ($b->x >= $x0 && $b->x <= $x1 && $b->y >= $y0 && $b->y <= $y1) {
                    $ents[] = [
                        'i' => $b->id, 't' => 1,
                        'x' => (int) round($b->x), 'y' => (int) round($b->y),
                        'r' => round($b->r, 1), 'o' => $b->owner,
                    ];
                }
            }

            $msg = [
                't' => 'u',
                'ts' => $ts,
                'e' => $ents,
                'me' => [
                    'x' => (int) $p->x, 'y' => (int) $p->y,
                    'hp' => (int) $p->hp, 'mh' => (int) $p->maxHp,
                    'xp' => $p->xp,
                    'lo' => $this->xpTable[$p->level],
                    'nx' => $this->xpTable[$p->level + 1] ?? $this->xpTable[$p->level],
                    'lv' => $p->level, 'pt' => $p->points, 'st' => $p->stats,
                    'c' => $p->cls,
                    'cc' => ($p->cls === 0 && $p->level >= self::CLASS_LEVEL) ? 1 : 0,
                    'fv' => $fov,
                ],
            ];
            if ($lb !== null) {
                $msg['lb'] = $lb;
            }
            $p->conn->send(json_encode($msg, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array<int,array{0:string,1:int,2:int}> */
    private function leaderboard(): array
    {
        $alive = array_filter($this->players, static fn (Player $p): bool => $p->alive);
        usort($alive, static fn (Player $a, Player $b): int => $b->xp <=> $a->xp);
        return array_map(
            static fn (Player $p): array => [$p->name, $p->xp, $p->id],
            array_slice($alive, 0, 5)
        );
    }

    private function rnd(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
