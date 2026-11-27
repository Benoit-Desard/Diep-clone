<?php
declare(strict_types=1);

namespace Diep;

/**
 * Arbre d'évolution officiel de diep.io (54 classes, 4 paliers : niv. 1 / 15 / 30 / 45).
 * C'est la SOURCE UNIQUE de vérité : le serveur s'en sert pour la physique et l'envoie tel quel
 * au client, qui s'en sert pour dessiner les canons et l'arbre circulaire.
 *
 * Canon (barrel) - clés :
 *   k  type : b balle | m missile | f feu d'artifice | t piège | d drone/spawner | a tourelle auto | n décor (aucun tir)
 *   l longueur et w largeur (x rayon du tank) | o décalage latéral (x rayon) | a angle (rad) | d retard 0..1 dans le cycle de tir
 *   fl évasement du bout | dm dégâts | sp vitesse | sz taille | sd dispersion | rl rechargement | rc recul | n projectiles par tir
 *   tg déclencheur (0 clic gauche, 1 clic droit, 2 les deux) | au drones automatiques | dk type de drone | lf durée de vie | pn pénétration
 *   ra/an/ro position de montage d'une tourelle (rayon x r, angle, tourne avec le tank) | rg portée de la tourelle
 */
final class ClassTree
{
    private static ?array $cache = null;
    /** @var array<string,array<string,mixed>> classes fusionnées déjà calculées ("a:b" => définition) */
    private static array $fusedCache = [];
    private const FUSION_RATIO = 0.5;  // part de l'écart comblée par la 2e classe quand elle est meilleure que la 1re
    private const FUSION_CAP = 1.8;    // gain maximal par caractéristique (x1,8)

    /** @return array<int,array<string,mixed>> classes indexées par id (0..53) */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /** @return array<string,mixed> */
    public static function get(int $id): array
    {
        return self::all()[$id] ?? self::all()[0];
    }

    /**
     * Seconde évolution (niveau 100) : fusion de deux classes.
     *  - 1re classe (ancre) : ses canons, son profil et ses caractéristiques de base.
     *  - 2e classe : améliore chaque caractéristique de la 1re de 50 % de l'écart quand elle est meilleure
     *    (portée, vitesse, pénétration, vision, dégâts, cadence...), plafonné à x1,8 ; jamais de pénalité.
     *    Elle apporte aussi ses capacités spéciales : spawners de drones, lanceurs de pièges, tourelles auto,
     *    missiles, nécromancie, furtivité, zoom. Si la 1re classe n'a aucun canon (Smasher...), elle prend ceux de la 2e.
     * Exemple : Machine Gun + Sniper = canon de mitrailleuse (cadence conservée) à la portée, la vitesse et la vision accrues.
     *
     * @return array<string,mixed> définition complète, utilisable comme n'importe quelle classe
     */
    public static function fused(int $a, int $b): array
    {
        return self::$fusedCache[$a . ':' . $b] ??= self::fuse(self::get($a), self::get($b));
    }

    /** Caractéristique de la 1re classe améliorée de FUSION_RATIO de l'écart avec la 2e (si elle est meilleure). */
    private static function blend(float $p, float $s, bool $higherIsBetter): float
    {
        if ($higherIsBetter) {
            return $s <= $p ? $p : min($p + self::FUSION_RATIO * ($s - $p), $p * self::FUSION_CAP);
        }
        return $s >= $p ? $p : max($p + self::FUSION_RATIO * ($s - $p), $p / self::FUSION_CAP);
    }

    /** @return array<string,mixed> */
    private static function fuse(array $p, array $s): array
    {
        $out = $p;
        $out['n'] = $p['n'] . ' + ' . $s['n'];
        $out['ds'] = 'Fusion : canons de ' . $p['n'] . ', caractéristiques et capacités de ' . $s['n'] . '.';

        // Canons : ceux de la 1re classe + les capacités spéciales de la 2e (ou tous ses canons si la 1re n'en a pas)
        $hasWeapon = array_filter($p['bar'], static fn (array $b): bool => $b['k'] !== 'n') !== [];
        $extra = $hasWeapon
            ? array_values(array_filter($s['bar'], static fn (array $b): bool => in_array($b['k'], ['d', 't', 'a', 'm', 'f'], true)
                || ($b['k'] === 'n' && $s['nec'] === 1)))
            : $s['bar'];
        $out['bar'] = array_merge($p['bar'], $extra);

        foreach (['dm', 'sp', 'pn', 'lf', 'bh', 'fov', 'mv', 'bd', 'hp'] as $k) {
            $out[$k] = self::blend((float) $p[$k], (float) $s[$k], true);
        }
        foreach (['rl', 'sd', 'rc'] as $k) {
            $out[$k] = self::blend((float) $p[$k], (float) $s[$k], false);
        }
        foreach (['rad', 'dc', 'dcs', 'shk', 'th', 'nec', 'zm', 'tsp', 'mxb', 'mxg'] as $k) {
            $out[$k] = max($p[$k], $s[$k]);
        }
        $stealth = array_filter([$p['st'], $s['st']]);
        $out['st'] = $stealth ? min($stealth) : 0;
        $out['body'] = $p['body'] ?: $s['body'];
        return $out;
    }

    public static function has(int $id): bool
    {
        return isset(self::all()[$id]);
    }

    private static function b(array $o = []): array
    {
        return $o + [
            'k' => 'b', 'l' => 1.75, 'w' => 0.84, 'o' => 0.0, 'a' => 0.0, 'd' => 0.0, 'fl' => 0.0,
            'dm' => 1.0, 'sp' => 1.0, 'sz' => 1.0, 'sd' => 0.0, 'rl' => 1.0, 'rc' => 1.0, 'n' => 1, 'bh' => 1.0,
            'tg' => 0, 'au' => 0, 'dk' => 0, 'lf' => 1.0, 'pn' => 1.0,
            'ra' => 0.0, 'an' => 0.0, 'ro' => 0, 'rg' => 480.0,
        ];
    }

    /** $n canons répartis radialement ; $alt alterne les retards (0 / 0.5) */
    private static function ring(int $n, array $t, float $start = 0.0, bool $alt = false): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::b(['a' => $start + 2 * M_PI * $i / $n, 'd' => $alt ? ($i % 2) * 0.5 : 0.0] + $t);
        }
        return $out;
    }

    /** paire de canons jumelés orientés vers l'angle $a */
    private static function twin(float $a, array $t = []): array
    {
        return [
            self::b(['a' => $a, 'o' => -0.42, 'w' => 0.6, 'dm' => 0.7, 'd' => 0.0] + $t),
            self::b(['a' => $a, 'o' => 0.42, 'w' => 0.6, 'dm' => 0.7, 'd' => 0.5] + $t),
        ];
    }

    private static function auto(array $o = []): array
    {
        return self::b(['k' => 'a', 'l' => 0.95, 'w' => 0.5, 'dm' => 0.7, 'rl' => 1.6, 'rc' => 0.0] + $o);
    }

    /** @return array<string,mixed> */
    private static function c(string $n, string $ds, int $lv, array $from, array $bar, array $o = []): array
    {
        return $o + [
            'n' => $n, 'ds' => $ds, 'lv' => $lv, 'from' => $from, 'bar' => $bar, 'up' => [],
            'rl' => 1.0, 'dm' => 1.0, 'sp' => 1.0, 'pn' => 1.0, 'lf' => 1.0, 'sd' => 0.0, 'rc' => 45.0,
            'fov' => 1.0, 'mv' => 1.0, 'bd' => 1.0, 'hp' => 1.0, 'rad' => 1.0,
            'dc' => 0, 'dcs' => 0, 'shk' => 0.0, 'th' => 0, 'st' => 0, 'body' => 0, 'nec' => 0,
            'mxb' => 7, 'mxg' => 7, 'zm' => 0, 'tsp' => 0.0, 'bh' => 1.0,
        ];
    }

    private static function build(): array
    {
        $P = M_PI;
        $d = [];

        // ---- Palier 1 (niveau 1)
        $d[0] = self::c('Tank', 'Le tank de départ : polyvalent.', 1, [], [self::b()]);

        // ---- Palier 2 (niveau 15)
        $d[1] = self::c('Twin', 'Deux canons alternés : cadence doublée, dégâts réduits.', 15, [0],
            self::twin(0.0), ['rc' => 30.0]);
        $d[2] = self::c('Sniper', 'Champ de vision élargi, balles rapides, tir plus lent.', 15, [0],
            [self::b(['l' => 2.35, 'w' => 0.72])],
            ['rl' => 1.5, 'dm' => 1.25, 'sp' => 1.5, 'pn' => 1.3, 'lf' => 1.5, 'fov' => 1.4, 'rc' => 80.0, 'shk' => 0.3]);
        $d[3] = self::c('Machine Gun', 'Rafales dispersées par un canon évasé.', 15, [0],
            [self::b(['l' => 1.7, 'w' => 0.62, 'fl' => 0.22, 'sd' => 0.15])],
            ['rl' => 0.5, 'dm' => 0.75, 'sp' => 0.95, 'pn' => 0.8, 'rc' => 18.0]);
        $d[4] = self::c('Flank Guard', 'Canon avant et canon arrière : recul quasi nul.', 15, [0],
            [self::b(['w' => 0.8, 'dm' => 0.85]), self::b(['w' => 0.8, 'dm' => 0.85, 'a' => $P, 'd' => 0.5])]);

        // ---- Palier 3 (niveau 30)
        $d[5] = self::c('Triple Shot', 'Trois canons en éventail : couvre une large zone.', 30, [1],
            [self::b(['l' => 1.75, 'w' => 0.56, 'a' => -0.5, 'dm' => 0.55, 'd' => 0.5]),
             self::b(['l' => 1.75, 'w' => 0.56, 'a' => 0.5, 'dm' => 0.55, 'd' => 0.5]),
             self::b(['l' => 1.95, 'w' => 0.56, 'dm' => 0.55])]);
        $d[6] = self::c('Quad Tank', 'Quatre canons en croix, recul annulé.', 30, [1, 4],
            self::ring(4, ['w' => 0.74, 'dm' => 0.6]), ['rl' => 1.1]);
        $d[7] = self::c('Twin Flank', 'Twin à l\'avant et à l\'arrière.', 30, [1, 4],
            array_merge(self::twin(0.0), self::twin($P, ['d' => 0.25])));
        $d[8] = self::c('Assassin', 'Vision et portée encore accrues, tir très lent.', 30, [2],
            [self::b(['l' => 2.6, 'w' => 0.74])],
            ['rl' => 2.0, 'dm' => 1.5, 'sp' => 1.7, 'pn' => 1.6, 'lf' => 1.6, 'fov' => 1.65, 'rc' => 110.0, 'shk' => 0.45]);
        $d[9] = self::c('Overseer', 'Deux spawners de drones (8 max) : clic gauche = attaquer, clic droit = fuir.', 30, [2],
            [self::b(['k' => 'd', 'l' => 1.3, 'w' => 1.0, 'fl' => 0.12, 'a' => $P / 2]),
             self::b(['k' => 'd', 'l' => 1.3, 'w' => 1.0, 'fl' => 0.12, 'a' => -$P / 2])],
            ['rl' => 2.0, 'dc' => 8, 'mv' => 0.92, 'fov' => 1.1]);
        $d[10] = self::c('Hunter', 'Canon à double charge : rafale de deux balles.', 30, [2],
            [self::b(['l' => 2.2, 'w' => 0.9, 'fl' => 0.1, 'dm' => 0.9]),
             self::b(['l' => 1.9, 'w' => 0.62, 'd' => 0.12, 'dm' => 0.9])],
            ['rl' => 1.6, 'sp' => 1.5, 'pn' => 1.3, 'lf' => 1.4, 'fov' => 1.3, 'rc' => 70.0, 'shk' => 0.3]);
        $d[11] = self::c('Trapper', 'Pose des pièges à très forte pénétration (clic gauche ou droit).', 30, [2],
            [self::b(['k' => 't', 'l' => 1.45, 'w' => 0.8, 'sp' => 0.75, 'pn' => 1.6, 'dm' => 1.2, 'tg' => 2])],
            ['rl' => 2.0, 'rc' => 40.0]);
        $d[12] = self::c('Destroyer', 'Un énorme projectile lent, au recul violent.', 30, [3],
            [self::b(['l' => 1.8, 'w' => 1.35, 'dm' => 4.5, 'sp' => 0.8, 'pn' => 2.5])],
            ['rl' => 3.2, 'rc' => 200.0, 'shk' => 0.7]);
        $d[13] = self::c('Gunner', 'Quatre mini-canons : tir très rapide, petites balles.', 30, [3],
            [self::b(['o' => -0.55, 'l' => 1.45, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.0, 'sd' => 0.04]),
             self::b(['o' => 0.55, 'l' => 1.45, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.5, 'sd' => 0.04]),
             self::b(['o' => -0.2, 'l' => 1.7, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.25, 'sd' => 0.04]),
             self::b(['o' => 0.2, 'l' => 1.7, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.75, 'sd' => 0.04])],
            ['rl' => 0.9, 'rc' => 10.0, 'pn' => 0.7]);
        $d[14] = self::c('Shotgun', 'Douze plombs à grande vitesse qui s\'écartent.', 30, [3],
            [self::b(['l' => 1.8, 'w' => 0.9, 'n' => 12, 'sd' => 0.16, 'dm' => 0.3, 'sz' => 0.45, 'lf' => 0.7])],
            ['rl' => 2.3, 'rc' => 90.0, 'sp' => 1.15, 'shk' => 0.3]);
        $d[15] = self::c('Tri-Angle', 'Canons arrière en propulseurs : accélère en tirant.', 30, [4],
            [self::b(['l' => 1.8, 'w' => 0.78, 'dm' => 0.85]),
             self::b(['l' => 1.4, 'w' => 0.6, 'a' => $P - 0.55, 'dm' => 0.2, 'd' => 0.5, 'rc' => 2.4, 'bh' => 0.4]),
             self::b(['l' => 1.4, 'w' => 0.6, 'a' => $P + 0.55, 'dm' => 0.2, 'd' => 0.5, 'rc' => 2.4, 'bh' => 0.4])],
            ['mv' => 1.15, 'th' => 1, 'rc' => 40.0]);
        $d[16] = self::c('Auto 3', 'Trois tourelles automatiques sur un anneau rotatif.', 30, [4],
            [self::auto(['ra' => 0.78, 'an' => 0.0, 'ro' => 1]),
             self::auto(['ra' => 0.78, 'an' => 2 * $P / 3, 'ro' => 1]),
             self::auto(['ra' => 0.78, 'an' => 4 * $P / 3, 'ro' => 1])],
            ['tsp' => 0.8]);
        $d[17] = self::c('Smasher', 'Sans canon : 10 niveaux en compétences corporelles, collisions dévastatrices.', 30, [0], [],
            ['bd' => 3.0, 'hp' => 1.3, 'rad' => 1.15, 'mv' => 1.08, 'body' => 1, 'mxb' => 10, 'mxg' => 0, 'fov' => 1.4]);

        // ---- Palier 4 (niveau 45)
        $d[18] = self::c('Triplet', 'Trois canons parallèles : une des cadences les plus élevées.', 45, [5],
            [self::b(['o' => -0.5, 'l' => 1.65, 'w' => 0.5, 'dm' => 0.6]),
             self::b(['o' => 0.5, 'l' => 1.65, 'w' => 0.5, 'dm' => 0.6]),
             self::b(['l' => 1.95, 'w' => 0.5, 'dm' => 0.6, 'd' => 0.5])],
            ['rl' => 0.9, 'rc' => 35.0]);
        $d[19] = self::c('Penta Shot', 'Cinq canons à l\'avant : barrage de balles, fort recul.', 45, [5],
            [self::b(['l' => 1.55, 'w' => 0.5, 'a' => -0.62, 'dm' => 0.5, 'd' => 0.66]),
             self::b(['l' => 1.55, 'w' => 0.5, 'a' => 0.62, 'dm' => 0.5, 'd' => 0.66]),
             self::b(['l' => 1.75, 'w' => 0.5, 'a' => -0.31, 'dm' => 0.5, 'd' => 0.33]),
             self::b(['l' => 1.75, 'w' => 0.5, 'a' => 0.31, 'dm' => 0.5, 'd' => 0.33]),
             self::b(['l' => 1.95, 'w' => 0.5, 'dm' => 0.5])],
            ['rl' => 1.1, 'rc' => 60.0]);
        $spread = [self::b(['l' => 1.8, 'w' => 0.7, 'dm' => 0.6])];
        for ($i = 1; $i <= 5; $i++) {
            $spread[] = self::b(['l' => 1.5, 'w' => 0.34, 'a' => -0.17 * $i, 'dm' => 0.25, 'pn' => 1.4]);
            $spread[] = self::b(['l' => 1.5, 'w' => 0.34, 'a' => 0.17 * $i, 'dm' => 0.25, 'pn' => 1.4]);
        }
        $d[20] = self::c('Spread Shot', 'Onze canons tirant en même temps : énorme éventail.', 45, [5],
            $spread, ['rl' => 1.6, 'rc' => 8.0]);
        $d[21] = self::c('Octo Tank', 'Huit canons : 360° de balles (touche C : rotation auto).', 45, [6],
            self::ring(8, ['w' => 0.6, 'dm' => 0.55], 0.0, true), ['rl' => 1.2]);
        $autos5 = [];
        for ($i = 0; $i < 5; $i++) {
            $autos5[] = self::auto(['ra' => 0.8, 'an' => 2 * $P * $i / 5, 'ro' => 1]);
        }
        $d[22] = self::c('Auto 5', 'Cinq tourelles automatiques sur un anneau rotatif.', 45, [6, 16],
            $autos5, ['tsp' => 0.6]);
        $d[23] = self::c('Triple Twin', 'Trois paires de canons jumelés à 120°.', 45, [7],
            array_merge(self::twin(0.0), self::twin(2 * $P / 3, ['d' => 0.17]), self::twin(4 * $P / 3, ['d' => 0.33])),
            ['rl' => 1.1]);
        $d[24] = self::c('Battleship', 'Mini-spawners de partisans (vie 4 s) : moitié manuels, moitié auto.', 45, [7, 9],
            [self::b(['k' => 'd', 'l' => 1.1, 'w' => 0.6, 'a' => $P / 2 - 0.3, 'dk' => 3, 'au' => 0, 'rl' => 0.4]),
             self::b(['k' => 'd', 'l' => 1.1, 'w' => 0.6, 'a' => $P / 2 + 0.3, 'dk' => 3, 'au' => 1, 'rl' => 0.4]),
             self::b(['k' => 'd', 'l' => 1.1, 'w' => 0.6, 'a' => -$P / 2 + 0.3, 'dk' => 3, 'au' => 0, 'rl' => 0.4]),
             self::b(['k' => 'd', 'l' => 1.1, 'w' => 0.6, 'a' => -$P / 2 - 0.3, 'dk' => 3, 'au' => 1, 'rl' => 0.4])],
            ['dc' => 14, 'mv' => 0.9]);
        $d[25] = self::c('Ranger', 'Le plus grand champ de vision du jeu.', 45, [8],
            [self::b(['l' => 3.0, 'w' => 0.8])],
            ['rl' => 2.2, 'dm' => 2.0, 'sp' => 2.0, 'pn' => 2.0, 'lf' => 1.9, 'fov' => 1.95, 'rc' => 150.0, 'shk' => 0.6, 'mv' => 0.9]);
        $d[26] = self::c('Stalker', 'Invisible si immobile et sans tirer pendant 2 s.', 45, [8],
            [self::b(['l' => 2.5, 'w' => 0.7])],
            ['rl' => 1.9, 'dm' => 1.7, 'sp' => 1.8, 'pn' => 1.6, 'lf' => 1.6, 'fov' => 1.65, 'rc' => 100.0, 'shk' => 0.4, 'st' => 60]);
        $d[27] = self::c('Overlord', 'Quatre spawners : cadence de drones doublée (8 max).', 45, [9],
            self::ring(4, ['k' => 'd', 'l' => 1.3, 'w' => 1.0, 'fl' => 0.12]),
            ['rl' => 1.0, 'dc' => 8, 'mv' => 0.9, 'fov' => 1.15]);
        $d[28] = self::c('Necromancer', 'Infecte les carrés au contact : armée de 22 à 36 carrés.', 45, [9],
            [self::b(['k' => 'n', 'l' => 1.0, 'w' => 1.15, 'a' => $P / 2]), self::b(['k' => 'n', 'l' => 1.0, 'w' => 1.15, 'a' => -$P / 2])],
            ['nec' => 1, 'dc' => 22, 'dcs' => 2, 'mv' => 0.95]);
        $d[29] = self::c('Manager', 'Un seul spawner (rapide), invisible à l\'arrêt.', 45, [9],
            [self::b(['k' => 'd', 'l' => 1.3, 'w' => 1.0, 'fl' => 0.12, 'a' => $P])],
            ['rl' => 1.1, 'dc' => 8, 'st' => 60, 'fov' => 1.1]);
        $d[30] = self::c('Overtrapper', 'Lanceur de pièges + 4 drones (2 manuels, 2 auto).', 45, [9, 11],
            [self::b(['k' => 't', 'l' => 1.45, 'w' => 0.8, 'sp' => 0.75, 'pn' => 1.6, 'dm' => 1.2, 'tg' => 2, 'rl' => 2.0]),
             self::b(['k' => 'd', 'l' => 1.2, 'w' => 0.9, 'a' => $P / 2, 'au' => 0]),
             self::b(['k' => 'd', 'l' => 1.2, 'w' => 0.9, 'a' => -$P / 2, 'au' => 0]),
             self::b(['k' => 'd', 'l' => 1.2, 'w' => 0.9, 'a' => $P - 0.5, 'au' => 1]),
             self::b(['k' => 'd', 'l' => 1.2, 'w' => 0.9, 'a' => $P + 0.5, 'au' => 1])],
            ['rl' => 1.0, 'dc' => 4, 'rc' => 40.0]);
        $d[31] = self::c('Factory', 'Fabrique jusqu\'à 6 mini-tanks qui suivent le curseur.', 45, [9],
            [self::b(['k' => 'd', 'l' => 1.45, 'w' => 1.3, 'fl' => 0.1, 'dk' => 2])],
            ['rl' => 4.0, 'dc' => 6, 'mv' => 0.9]);
        $d[32] = self::c('Predator', 'Trois canons empilés. Clic droit : zoom vers le curseur.', 45, [10],
            [self::b(['l' => 2.3, 'w' => 0.95, 'fl' => 0.1, 'dm' => 0.85]),
             self::b(['l' => 2.0, 'w' => 0.75, 'd' => 0.1, 'dm' => 0.85]),
             self::b(['l' => 1.7, 'w' => 0.55, 'd' => 0.2, 'dm' => 0.85])],
            ['rl' => 1.8, 'sp' => 1.6, 'pn' => 1.4, 'lf' => 1.4, 'fov' => 1.4, 'rc' => 80.0, 'shk' => 0.4, 'zm' => 1]);
        $stream = [];
        for ($i = 0; $i < 5; $i++) {
            $stream[] = self::b(['l' => 1.3, 'w' => 0.42, 'dm' => 0.32, 'd' => $i * 0.2, 'sd' => 0.03]);
        }
        $d[33] = self::c('Streamliner', 'Cinq canons courts en cascade : quasi-laser de petites balles.', 45, [10, 13],
            $stream, ['rl' => 1.0, 'fov' => 1.3, 'rc' => 8.0, 'pn' => 1.2]);
        $d[34] = self::c('Tri-Trapper', 'Trois lanceurs : pièges triplés, durée réduite.', 45, [11],
            [self::b(['k' => 't', 'l' => 1.4, 'w' => 0.75, 'sp' => 0.75, 'dm' => 1.1, 'tg' => 2]),
             self::b(['k' => 't', 'l' => 1.4, 'w' => 0.75, 'sp' => 0.75, 'dm' => 1.1, 'tg' => 2, 'a' => -0.7]),
             self::b(['k' => 't', 'l' => 1.4, 'w' => 0.75, 'sp' => 0.75, 'dm' => 1.1, 'tg' => 2, 'a' => 0.7])],
            ['rl' => 1.4, 'lf' => 0.6, 'rc' => 30.0]);
        $d[35] = self::c('Gunner Trapper', 'Deux mini-canons devant, lanceur de pièges derrière.', 45, [11, 13],
            [self::b(['o' => -0.4, 'l' => 1.65, 'w' => 0.38, 'dm' => 0.4, 'pn' => 2.0, 'rl' => 1.6]),
             self::b(['o' => 0.4, 'l' => 1.65, 'w' => 0.38, 'dm' => 0.4, 'pn' => 2.0, 'rl' => 1.6, 'd' => 0.5]),
             self::b(['k' => 't', 'l' => 1.4, 'w' => 0.9, 'a' => $P, 'sp' => 0.75, 'dm' => 1.0, 'tg' => 2, 'rl' => 1.4])],
            ['rc' => 12.0]);
        $d[36] = self::c('Mega Trapper', 'Lanceur géant : méga-pièges très résistants.', 45, [11],
            [self::b(['k' => 't', 'l' => 1.5, 'w' => 1.35, 'sz' => 1.5, 'sp' => 0.75, 'dm' => 2.0, 'pn' => 2.0, 'tg' => 2])],
            ['rl' => 2.4, 'lf' => 0.7, 'rc' => 60.0]);
        $d[37] = self::c('Auto Trapper', 'Trapper + tourelle automatique au centre.', 45, [11],
            [self::b(['k' => 't', 'l' => 1.45, 'w' => 0.8, 'sp' => 0.75, 'pn' => 1.6, 'dm' => 1.2, 'tg' => 2]),
             self::auto()],
            ['rl' => 2.0, 'rc' => 40.0]);
        $d[38] = self::c('Hybrid', 'Canon Destroyer + spawner arrière (2 drones auto).', 45, [12],
            [self::b(['l' => 1.8, 'w' => 1.35, 'dm' => 4.5, 'sp' => 0.8, 'pn' => 2.5, 'rl' => 1.0]),
             self::b(['k' => 'd', 'l' => 1.2, 'w' => 0.9, 'a' => $P, 'au' => 1, 'rl' => 1.0])],
            ['rl' => 3.2, 'rc' => 170.0, 'dc' => 2, 'shk' => 0.6]);
        $d[39] = self::c('Annihilator', 'Projectile encore plus gros et plus puissant.', 45, [12],
            [self::b(['l' => 1.7, 'w' => 1.75, 'dm' => 5.5, 'sp' => 0.8, 'pn' => 3.0])],
            ['rl' => 3.2, 'rc' => 260.0, 'shk' => 0.9]);
        $d[40] = self::c('Skimmer', 'Missile accélérant + deux petits canons latéraux.', 45, [12],
            [self::b(['k' => 'm', 'l' => 1.9, 'w' => 1.25, 'dm' => 2.4, 'pn' => 2.0]),
             self::b(['l' => 1.2, 'w' => 0.34, 'a' => $P / 2, 'dm' => 0.3, 'rl' => 1.2, 'rc' => 0.4]),
             self::b(['l' => 1.2, 'w' => 0.34, 'a' => -$P / 2, 'dm' => 0.3, 'rl' => 1.2, 'rc' => 0.4, 'd' => 0.5])],
            ['rl' => 3.0, 'rc' => 120.0, 'shk' => 0.5]);
        $d[41] = self::c('Rocketeer', 'Missiles ultra-pénétrants qui accélèrent.', 45, [12],
            [self::b(['k' => 'm', 'l' => 1.9, 'w' => 1.0, 'dm' => 1.8, 'pn' => 4.0, 'lf' => 1.3]),
             self::b(['k' => 'n', 'l' => 0.9, 'w' => 0.45, 'a' => $P])],
            ['rl' => 2.6, 'rc' => 120.0, 'shk' => 0.4]);
        $d[42] = self::c('Glider', 'Missiles à double propulseur arrière.', 45, [12],
            [self::b(['k' => 'm', 'l' => 1.9, 'w' => 1.0, 'dm' => 2.0, 'pn' => 2.5]),
             self::b(['k' => 'n', 'l' => 0.95, 'w' => 0.35, 'a' => $P - 0.6]),
             self::b(['k' => 'n', 'l' => 0.95, 'w' => 0.35, 'a' => $P + 0.6])],
            ['rl' => 2.4, 'rc' => 110.0, 'shk' => 0.4]);
        $d[43] = self::c('Firework', 'Explose en 16 balles en fin de course.', 45, [12],
            [self::b(['k' => 'f', 'l' => 1.9, 'w' => 1.0, 'dm' => 2.0, 'lf' => 0.8])],
            ['rl' => 2.6, 'rc' => 110.0, 'shk' => 0.4]);
        $d[44] = self::c('Auto Gunner', 'Gunner + tourelle automatique au centre.', 45, [13, 16],
            [self::b(['o' => -0.55, 'l' => 1.45, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.0, 'sd' => 0.04]),
             self::b(['o' => 0.55, 'l' => 1.45, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.5, 'sd' => 0.04]),
             self::b(['o' => -0.2, 'l' => 1.7, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.25, 'sd' => 0.04]),
             self::b(['o' => 0.2, 'l' => 1.7, 'w' => 0.36, 'dm' => 0.4, 'd' => 0.75, 'sd' => 0.04]),
             self::auto(['rg' => 600.0])],
            ['rl' => 1.0, 'rc' => 10.0, 'pn' => 0.7, 'fov' => 1.15]);
        $d[45] = self::c('Pellet Shot', 'Trente plombs d\'un coup.', 45, [14],
            [self::b(['l' => 1.8, 'w' => 1.1, 'n' => 30, 'sd' => 0.2, 'dm' => 0.22, 'sz' => 0.4, 'lf' => 0.7])],
            ['rl' => 2.4, 'rc' => 120.0, 'sp' => 1.15, 'fov' => 1.2, 'shk' => 0.4]);
        $d[46] = self::c('Dual-Barrel', 'Deux fusils : le second tire au clic droit.', 45, [14],
            [self::b(['o' => -0.45, 'l' => 1.8, 'w' => 0.7, 'n' => 12, 'sd' => 0.16, 'dm' => 0.3, 'sz' => 0.45, 'lf' => 0.7, 'tg' => 0]),
             self::b(['o' => 0.45, 'l' => 1.8, 'w' => 0.7, 'n' => 12, 'sd' => 0.16, 'dm' => 0.3, 'sz' => 0.45, 'lf' => 0.7, 'tg' => 1])],
            ['rl' => 2.0, 'rc' => 90.0, 'sp' => 1.15, 'shk' => 0.3]);
        $d[47] = self::c('Sprayer', 'Gros canon de mitrailleuse + petit canon précis.', 45, [3],
            [self::b(['l' => 1.75, 'w' => 0.7, 'fl' => 0.3, 'sd' => 0.2, 'dm' => 0.7]),
             self::b(['l' => 1.95, 'w' => 0.3, 'dm' => 0.12, 'rl' => 0.5, 'd' => 0.5])],
            ['rl' => 0.45, 'rc' => 22.0, 'pn' => 0.9]);
        $d[48] = self::c('Booster', 'Quatre propulseurs arrière : vitesse de pointe.', 45, [15],
            [self::b(['l' => 1.8, 'w' => 0.78, 'dm' => 0.85]),
             self::b(['l' => 1.4, 'w' => 0.55, 'a' => $P - 0.35, 'dm' => 0.2, 'd' => 0.5, 'rc' => 2.8, 'bh' => 0.4]),
             self::b(['l' => 1.4, 'w' => 0.55, 'a' => $P + 0.35, 'dm' => 0.2, 'd' => 0.5, 'rc' => 2.8, 'bh' => 0.4]),
             self::b(['l' => 1.4, 'w' => 0.55, 'a' => $P - 0.8, 'dm' => 0.2, 'd' => 0.0, 'rc' => 2.8, 'bh' => 0.4]),
             self::b(['l' => 1.4, 'w' => 0.55, 'a' => $P + 0.8, 'dm' => 0.2, 'd' => 0.0, 'rc' => 2.8, 'bh' => 0.4])],
            ['mv' => 1.25, 'th' => 1, 'rc' => 40.0]);
        $d[49] = self::c('Fighter', 'Tri-Angle avec deux canons latéraux.', 45, [15],
            [self::b(['l' => 1.8, 'w' => 0.78, 'dm' => 0.85]),
             self::b(['l' => 1.5, 'w' => 0.6, 'a' => $P / 2, 'dm' => 0.55, 'd' => 0.25]),
             self::b(['l' => 1.5, 'w' => 0.6, 'a' => -$P / 2, 'dm' => 0.55, 'd' => 0.75]),
             self::b(['l' => 1.4, 'w' => 0.6, 'a' => $P - 0.55, 'dm' => 0.2, 'd' => 0.5, 'rc' => 2.4, 'bh' => 0.4]),
             self::b(['l' => 1.4, 'w' => 0.6, 'a' => $P + 0.55, 'dm' => 0.2, 'd' => 0.5, 'rc' => 2.4, 'bh' => 0.4])],
            ['mv' => 1.2, 'th' => 1, 'rc' => 40.0]);
        $d[50] = self::c('Landmine', 'Smasher invisible après 13 s d\'immobilité.', 45, [17], [],
            ['bd' => 3.0, 'hp' => 1.3, 'rad' => 1.15, 'mv' => 1.08, 'body' => 3, 'mxb' => 10, 'mxg' => 0, 'fov' => 1.4, 'st' => 390]);
        $d[51] = self::c('Auto Smasher', 'Smasher + tourelle auto : 10 niveaux partout.', 45, [17],
            [self::auto(['rg' => 600.0])],
            ['bd' => 2.4, 'hp' => 1.2, 'rad' => 1.15, 'mv' => 1.05, 'body' => 1, 'mxb' => 10, 'mxg' => 10, 'fov' => 1.4]);
        $d[52] = self::c('Spike', 'Dégâts de collision maximaux : le plus résistant au corps à corps.', 45, [17], [],
            ['bd' => 4.5, 'hp' => 1.7, 'rad' => 1.2, 'mv' => 1.0, 'body' => 2, 'mxb' => 10, 'mxg' => 0, 'fov' => 1.4]);
        $d[53] = self::c('Auto Tank', 'Tank de base + tourelle automatique de soutien.', 45, [0],
            [self::b(), self::auto()]);

        // Robustesse des balles ('bh') : durabilité = 2 x pénétration x bh. Quand deux balles ennemies se percutent,
        // chacune perd (dégâts de l'autre x 0,15) de durabilité. > 1 : balles qui résistent et détruisent les
        // balles adverses (snipers, destroyers) ; < 1 : cadence élevée mais balles fragiles (mitrailleuses, jumeaux).
        $bh = [
            1 => 0.8, 2 => 2.0, 3 => 0.5, 5 => 0.7, 6 => 0.8, 7 => 0.8, 8 => 2.5, 10 => 1.8, 12 => 6.0, 13 => 0.4,
            14 => 0.35, 15 => 0.8, 16 => 0.6, 18 => 0.6, 19 => 0.5, 20 => 0.4, 21 => 0.6, 22 => 0.6, 23 => 0.7,
            25 => 3.0, 26 => 2.2, 32 => 2.0, 33 => 0.4, 35 => 0.6, 36 => 1.5, 38 => 6.0, 39 => 8.0, 40 => 4.0,
            41 => 3.0, 42 => 3.0, 43 => 2.5, 44 => 0.4, 45 => 0.3, 46 => 0.35, 47 => 0.5, 48 => 0.8, 49 => 0.8,
        ];
        foreach ($bh as $id => $v) {
            $d[$id]['bh'] = $v;
        }

        // Liens enfants
        foreach ($d as $id => $def) {
            foreach ($def['from'] as $parent) {
                $d[$parent]['up'][] = $id;
            }
        }
        ksort($d);
        return array_values($d);
    }
}
