'use strict';

(() => {
  // ==================================================================== constantes
  const WS_PORT = 8080;
  const INTERP_DELAY = 90;               // ms de retard volontaire pour interpoler entre 2 snapshots
  const VIEW_W = 1920, VIEW_H = 1080;    // zone visible de référence (identique au serveur)
  const GRID_STEP = 50;
  const T_TANK = 0, T_BULLET = 1, T_TURRET = 7, T_DRONE = 8, T_TRAP = 9, T_MISSILE = 10;

  const BLUE = { fill: '#00b2e1', stroke: '#0085a8' };
  const RED = { fill: '#f14e54', stroke: '#b43a3f' };
  const TEAM = { 1: BLUE, 2: RED };
  const GREY = { fill: '#999999', stroke: '#727272' };
  const DARK = { fill: '#5d5d5d', stroke: '#3f3f3f' };
  // k : échelle de dessin par rapport au rayon de collision serveur
  const SHAPE_STYLE = {
    2: { sides: 4, k: 1.2, fill: '#ffe869', stroke: '#bfae4e' },   // carré
    3: { sides: 3, k: 1.1, fill: '#fc7677', stroke: '#bd5758' },   // triangle
    4: { sides: 5, k: 1.05, fill: '#768dfc', stroke: '#5869bd' },  // pentagone
    5: { sides: 5, k: 1.05, fill: '#768dfc', stroke: '#5869bd' },  // Alpha Pentagone
    6: { sides: 3, k: 1.15, fill: '#f177dd', stroke: '#b85aa5' },  // Crasher
  };
  const SHAPE_R = { 2: 24, 3: 26, 4: 44, 5: 135, 6: 16 };   // rayons des polygones (non envoyés par le serveur)
  const TIER_NAMES = { 1: 'Débutants', 2: 'Évolués', 3: 'Maîtres' };
  const STAT_NAMES = ['Régénération', 'PV max', 'Dégâts corporels', 'Vitesse des balles',
    'Pénétration', 'Dégâts des balles', 'Rechargement', 'Vitesse de déplacement'];
  const STAT_COLORS = ['#e5ad8e', '#e664e6', '#9a6df0', '#6d9bf0', '#f0e65f', '#f06d6d', '#8fe05c', '#5fe0e0'];
  const BRANCH_COLORS = ['#ffe58a', '#aef5a8', '#ff9a9a', '#9ff3f3', '#c4a8ff', '#9ec3ff'];
  const KEYMAP = {
    KeyW: 1, ArrowUp: 1,       // KeyW = touche Z sur un clavier AZERTY
    KeyS: 2, ArrowDown: 2,
    KeyA: 4, ArrowLeft: 4,     // KeyA = touche Q sur un clavier AZERTY
    KeyD: 8, ArrowRight: 8,
  };

  // ==================================================================== DOM
  const $ = (id) => document.getElementById(id);
  const canvas = $('game'), ctx = canvas.getContext('2d');
  const mm = $('minimap'), mctx = mm.getContext('2d');
  const hud = $('hud'), menu = $('menu');
  const nameInput = $('nameInput'), playBtn = $('playBtn');
  const statusEl = $('status'), menuInfo = $('menuInfo');
  const lbList = $('lbList'), teamScore = $('teamScore');
  const skillsEl = $('skills'), skillPts = $('skillPts');
  const xpFill = $('xpFill'), levelInfo = $('levelInfo'), scoreText = $('scoreText');
  const evoBtn = $('evoBtn'), toastEl = $('toast'), superBtn = $('superBtn'), busyBadge = $('busyBadge');
  const superPanel = $('superPanel'), superGrid = $('superGrid'), superCount = $('superCount'), superOk = $('superOk');
  const treeEl = $('tree'), treeCanvas = $('treeCanvas'), tctx = treeCanvas.getContext('2d'), treeTip = $('treeTip'), treeMsg = $('treeMsg');

  // ==================================================================== état
  let ws = null;
  let myId = 0, myTeam = 0, world = 4000, mode = 'ffa', selMode = 'ffa';
  let playing = false;
  let classes = [], bases = [];
  let snaps = [];                // [{t, ents: Map(id -> entité)}] (t = temps serveur en ms)
  let clockOffset = null;        // estimation de (temps local - temps serveur)
  let meState = null, hudSig = '';
  let knownBullets = new Set();
  let W = 0, H = 0, dpr = 1, scale = 0, shake = 0, zoomOff = 0, lastFrame = 0;
  const cam = { x: 2000, y: 2000 };
  const flash = new Map();       // id -> expiration du flash blanc (ms)
  const particles = [], floaters = [];

  const held = new Set();
  const mouse = { x: innerWidth / 2, y: innerHeight / 2 };
  let mouseDown = false, rmbDown = false, spaceDown = false, shiftDown = false;
  let lastSig = '', lastSent = 0, toastTimer = 0;
  let overSkills = false, choiceUntil = 0;   // interaction avec le panneau de compétences (immunité côté serveur)

  // ==================================================================== audio procédural (Web Audio)
  const Sfx = (() => {
    let ac = null, master = null, noiseBuf = null, muted = false;
    const last = {};

    function init() {
      if (ac) { if (ac.state === 'suspended') ac.resume(); return; }
      try {
        ac = new (window.AudioContext || window.webkitAudioContext)();
        master = ac.createGain();
        master.gain.value = 0.3;
        master.connect(ac.destination);
        noiseBuf = ac.createBuffer(1, ac.sampleRate, ac.sampleRate);
        const d = noiseBuf.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
      } catch { ac = null; }
    }
    const gate = (k, gap) => {
      const n = performance.now();
      if (n - (last[k] || 0) < gap) return false;
      last[k] = n;
      return true;
    };
    function tone(type, f0, f1, dur, vol, delay = 0) {
      if (!ac || muted) return;
      const t = ac.currentTime + delay;
      const o = ac.createOscillator(), g = ac.createGain();
      o.type = type;
      o.frequency.setValueAtTime(f0, t);
      o.frequency.exponentialRampToValueAtTime(Math.max(1, f1), t + dur);
      g.gain.setValueAtTime(vol, t);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g);
      g.connect(master);
      o.start(t);
      o.stop(t + dur + 0.02);
    }
    function noise(dur, vol, freq, q, type = 'bandpass', delay = 0) {
      if (!ac || muted) return;
      const t = ac.currentTime + delay;
      const s = ac.createBufferSource(), f = ac.createBiquadFilter(), g = ac.createGain();
      s.buffer = noiseBuf;
      f.type = type;
      f.frequency.value = freq;
      f.Q.value = q;
      g.gain.setValueAtTime(vol, t);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      s.connect(f);
      f.connect(g);
      g.connect(master);
      s.start(t);
      s.stop(t + dur + 0.02);
    }
    return {
      init,
      toggle() { muted = !muted; return muted; },
      shoot(v, big) {
        if (!gate('shoot', 40)) return;
        noise(big ? 0.16 : 0.07, 0.3 * v, big ? 700 : 1800, 1.2);
        tone('square', big ? 260 : 420, big ? 60 : 120, big ? 0.18 : 0.09, 0.1 * v);
      },
      hit(v) {
        if (!gate('hit', 35)) return;
        tone('triangle', 320, 110, 0.06, 0.18 * v);
        noise(0.04, 0.1 * v, 2500, 1);
      },
      boom(v, size) {
        if (!gate('boom', 60)) return;
        noise(0.25 + size * 0.4, 0.5 * v, 400, 0.7, 'lowpass');
        tone('sawtooth', 110, 30, 0.3 + size * 0.3, 0.22 * v);
      },
      levelUp() {
        [523, 659, 784].forEach((f, i) => tone('triangle', f, f * 1.01, 0.18, 0.2, i * 0.08));
      },
      evolve() {
        tone('sine', 200, 1200, 0.5, 0.22);
        [392, 523, 659, 784].forEach((f, i) => tone('triangle', f, f, 0.3, 0.14, 0.1 + i * 0.07));
      },
      death() {
        tone('sawtooth', 300, 40, 0.7, 0.25);
        noise(0.5, 0.3, 300, 0.7, 'lowpass');
      },
    };
  })();

  // ==================================================================== classes fusionnées (niveau 100)
  const WEAPON_K = ['b', 'm', 'f', 't', 'd', 'a'], SPECIAL_K = ['d', 't', 'a', 'm', 'f'];
  const fuseCache = new Map();
  /** Classe réellement jouée par (c, c2) : miroir du serveur pour le rendu (canons, corps, propulseurs, drones). */
  function clsOf(c, c2) {
    if (c2 === undefined || c2 < 0) return classes[c] || classes[0];
    const key = c * 1000 + c2;
    let d = fuseCache.get(key);
    if (!d) {
      const A = classes[c], B = classes[c2];
      const hasWeapon = A.bar.some((b) => WEAPON_K.includes(b.k));
      const extra = hasWeapon ? B.bar.filter((b) => SPECIAL_K.includes(b.k) || (b.k === 'n' && B.nec === 1)) : B.bar;
      d = {
        ...A, n: `${A.n} + ${B.n}`, bar: [...A.bar, ...extra], body: A.body || B.body, th: Math.max(A.th, B.th),
        shk: Math.max(A.shk, B.shk), zm: Math.max(A.zm, B.zm), dc: Math.max(A.dc, B.dc), nec: Math.max(A.nec, B.nec),
      };
      fuseCache.set(key, d);
    }
    return d;
  }

  // ==================================================================== réseau
  function send(obj) {
    if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
  }

  function connect() {
    ws = new WebSocket(`ws://${location.hostname || 'localhost'}:${WS_PORT}`);
    ws.onopen = () => { statusEl.textContent = 'Connecté au serveur'; playBtn.disabled = false; };
    ws.onclose = () => {
      playing = false;
      playBtn.disabled = true;
      hud.classList.add('hidden');
      closeTree();
      closeSuper();
      menu.classList.remove('hidden');
      menuInfo.textContent = '';
      statusEl.textContent = 'Connexion perdue, nouvelle tentative…';
      setTimeout(connect, 2000);
    };
    ws.onmessage = (ev) => {
      let m;
      try { m = JSON.parse(ev.data); } catch { return; }
      if (m.t === 'sp') onSpawn(m);
      else if (m.t === 'u') onUpdate(m);
      else if (m.t === 'd') onDeath(m);
    };
  }

  function onSpawn(m) {
    myId = m.id; world = m.w; mode = m.md; myTeam = m.tm; bases = m.bs || []; classes = m.cl;
    snaps = [];
    clockOffset = null;
    meState = null;
    hudSig = '';
    knownBullets = new Set();
    flash.clear();
    particles.length = 0;
    floaters.length = 0;
    scale = 0;
    playing = true;
    menu.classList.add('hidden');
    hud.classList.remove('hidden');
    teamScore.classList.toggle('hidden', mode !== 'teams');
    layoutTree();
    Sfx.init();
  }

  function onDeath(m) {
    playing = false;
    held.clear();
    mouseDown = rmbDown = spaceDown = shiftDown = false;
    Sfx.death();
    closeTree();
    closeSuper();
    hud.classList.add('hidden');
    menuInfo.textContent = `Détruit par ${m.k || 'un polygone'} · niveau ${m.l} · score ${m.s}`;
    playBtn.textContent = 'Rejouer';
    menu.classList.remove('hidden');
  }

  function onUpdate(m) {
    const now = performance.now();
    const ents = new Map();
    for (const e of m.e) {
      if (e.t >= 2 && e.t <= 6) {                  // le serveur omet r, et hp quand il vaut 100 %
        e.r = SHAPE_R[e.t];
        if (e.hp === undefined) e.hp = 100;
      }
      ents.set(e.i, e);
    }

    const d = now - m.ts;
    clockOffset = clockOffset === null || d < clockOffset ? d : clockOffset + (d - clockOffset) * 0.01;

    const prev = snaps.length ? snaps[snaps.length - 1].ents : null;
    const me = m.me, mcls = clsOf(me.c, me.c2);
    const bulletIds = new Set();
    let others = 0;
    for (const e of m.e) {
      if (e.t === T_BULLET || e.t === T_MISSILE) {
        bulletIds.add(e.i);
        if (!knownBullets.has(e.i)) {
          if (e.o === myId) {
            Sfx.shoot(1, mcls.shk > 0.3);
            shake = Math.max(shake, mcls.shk * 10);
          } else if (others < 3) {
            const vol = 1 - Math.hypot(e.x - me.x, e.y - me.y) / 1500;
            if (vol > 0.05) { others++; Sfx.shoot(vol * 0.5, false); }
          }
        }
      } else if (prev && (e.t === T_TANK || (e.t >= 2 && e.t <= 6))) {
        const p = prev.get(e.i);
        if (p && e.hp < p.hp) {
          flash.set(e.i, now + 110);                       // flash blanc de l'entité touchée
          if (e.t !== T_TANK && particles.length < 260) debris(e);
        }
      }
    }
    knownBullets = bulletIds;
    if (flash.size > 300) for (const [k, v] of flash) if (v < now) flash.delete(k);

    if (m.ev) for (const ev of m.ev) onEvent(ev, me);

    snaps.push({ t: m.ts, ents });
    if (snaps.length > 12) snaps.shift();

    const pm = meState;
    meState = me;
    if (pm) {
      if (me.lv > pm.lv) { Sfx.levelUp(); toast(`Niveau ${me.lv}`); }
      if (me.c !== pm.c || me.c2 !== pm.c2) { Sfx.evolve(); toast(`Évolution : ${clsOf(me.c, me.c2).n}`); closeTree(); }
      if (me.fu && !pm.fu) { toast('Niveau 100 ! Choisis une seconde classe à fusionner'); openTree(); Sfx.evolve(); }
      if (me.tr !== pm.tr) toast(`Palier ${me.tr} · ${TIER_NAMES[me.tr]} : tu affrontes désormais d'autres joueurs`);
      if (me.cc.length && !pm.cc.length) { toast('Évolution disponible ! Choisis ta classe'); openTree(); Sfx.evolve(); }
      if (me.ss && !pm.ss) { toast('Spécialisation finale disponible !'); openSuper(); Sfx.evolve(); }
    }
    if (m.lb) renderLeaderboard(m.lb, m.ts2);
    updateHud();
  }

  /** Évènements serveur : 'd' chiffre de dégâts, 'x' explosion. */
  function onEvent(ev, me) {
    if (ev[0] === 'd') {
      const [, x, y, n, recv] = ev;
      floaters.push({ x, y, n, recv, life: 0.85, max: 0.85 });
      Sfx.hit(recv ? 0.9 : 0.55);
      if (recv) shake = Math.max(shake, Math.min(12, n * 0.5));
    } else if (ev[0] === 'k') {                              // une balle a été détruite par une autre
      const [, x, y] = ev;
      for (let i = 0; i < 5 && particles.length < 400; i++) {
        const a = Math.random() * Math.PI * 2, sp = 60 + Math.random() * 120;
        particles.push({ x, y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, rot: 0, vr: 8, s: 2 + Math.random() * 3,
          life: 0.28, max: 0.28, c: Math.random() < 0.5 ? '#ffffff' : '#ffd54a', sq: false });
      }
      const vol = Math.max(0, 1 - Math.hypot(x - me.x, y - me.y) / 900);
      if (vol > 0.05) Sfx.hit(0.22 * vol);
    } else if (ev[0] === 'x') {
      const [, x, y, kind, r, tm] = ev;
      burst(kind, x, y, r, tm);
      const dd = Math.hypot(x - me.x, y - me.y);
      const vol = Math.max(0, 1 - dd / 1400);
      if (vol > 0.03) Sfx.boom(vol, Math.min(1, r / 120));
      if (dd < 700) shake = Math.max(shake, (r > 60 ? 14 : 5) * (1 - dd / 700));
    }
  }

  // ==================================================================== inputs
  const keyMask = () => { let k = 0; for (const c of held) k |= KEYMAP[c] || 0; return k; };

  addEventListener('keydown', (e) => {
    if (!playing) return;
    if (e.code in KEYMAP || e.code === 'Space') e.preventDefault();
    if (e.code in KEYMAP) held.add(e.code);
    else if (e.code === 'Space') spaceDown = true;
    else if (e.code === 'ShiftLeft' || e.code === 'ShiftRight') shiftDown = true;
    else if (e.code === 'KeyT') toggleTree();
    else if (e.code === 'KeyY') toggleSuper();
    else if (e.code === 'Escape') { closeTree(); closeSuper(); }
    else if (e.code === 'KeyM') toast(Sfx.toggle() ? 'Son coupé' : 'Son activé');
    else if (e.code === 'KeyC') { send({ t: 'z' }); toast('Rotation automatique'); }
    else {
      const d = /^(?:Digit|Numpad)([1-8])$/.exec(e.code);
      if (d) { send({ t: 's', i: Number(d[1]) - 1 }); noteChoice(); }
    }
  });
  addEventListener('keyup', (e) => {
    held.delete(e.code);
    if (e.code === 'Space') spaceDown = false;
    if (e.code === 'ShiftLeft' || e.code === 'ShiftRight') shiftDown = false;
  });
  addEventListener('blur', () => { held.clear(); mouseDown = rmbDown = spaceDown = shiftDown = false; });
  addEventListener('mousemove', (e) => { mouse.x = e.clientX; mouse.y = e.clientY; });
  addEventListener('mousedown', (e) => {
    if (e.target !== canvas) return;
    if (e.button === 0) mouseDown = true;
    else if (e.button === 2) rmbDown = true;
  });
  addEventListener('mouseup', (e) => {
    if (e.button === 0) mouseDown = false;
    else if (e.button === 2) rmbDown = false;
  });
  addEventListener('contextmenu', (e) => { if (e.target === canvas) e.preventDefault(); });

  // Envoi des inputs à 30 Hz (si changement, + un battement toutes les 250 ms)
  setInterval(() => {
    if (!playing) return;
    const k = keyMask();
    const a = Math.round(Math.atan2(mouse.y - H / 2, mouse.x - W / 2) * 100) / 100;
    const d = Math.round(Math.hypot(mouse.x - W / 2, mouse.y - H / 2) / (scale || 1) / 5) * 5;
    const busy = uiBusy();
    const f = !busy && (mouseDown || spaceDown) ? 1 : 0;
    const g = !busy && (rmbDown || shiftDown) ? 1 : 0;
    const sig = `${k}|${a}|${d}|${f}|${g}`;
    const now = performance.now();
    if (sig !== lastSig || now - lastSent > 250) {
      send({ t: 'i', k, a, d, f, g });
      lastSig = sig;
      lastSent = now;
    }
  }, 1000 / 30);

  // ==================================================================== menu
  function play() {
    const name = nameInput.value.trim();
    try { localStorage.setItem('diep-name', name); localStorage.setItem('diep-mode', selMode); } catch { /* ignoré */ }
    Sfx.init();
    send({ t: 'j', n: name, m: selMode });
  }
  playBtn.addEventListener('click', play);
  nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !playBtn.disabled) play(); });
  document.querySelectorAll('.mode').forEach((b) => b.addEventListener('click', () => {
    selMode = b.dataset.mode;
    document.querySelectorAll('.mode').forEach((x) => x.classList.toggle('on', x === b));
  }));
  try {
    nameInput.value = localStorage.getItem('diep-name') || '';
    const sm = localStorage.getItem('diep-mode');
    if (sm === 'teams') document.querySelector('.mode[data-mode="teams"]').click();
  } catch { /* ignoré */ }

  // ==================================================================== HUD
  const skillRows = [];
  STAT_NAMES.forEach((name, i) => {
    const row = document.createElement('div');
    row.className = 'skill';
    row.style.setProperty('--c', STAT_COLORS[i]);
    const lbl = document.createElement('span');
    lbl.className = 'lbl';
    lbl.textContent = name;
    const pips = document.createElement('div');
    pips.className = 'pips';
    const pipEls = [];
    for (let j = 0; j < 14; j++) {                  // 10 (Smasher) + 4 crans de super-compétence
      const p = document.createElement('span');
      p.className = 'pip';
      pips.appendChild(p);
      pipEls.push(p);
    }
    const key = document.createElement('span');
    key.className = 'key';
    key.textContent = String(i + 1);
    row.append(lbl, pips, key);
    row.addEventListener('click', () => { send({ t: 's', i }); noteChoice(); });
    skillsEl.appendChild(row);
    skillRows.push({ row, pips: pipEls, lbl });
  });

  function updateHud() {
    const m = meState;
    if (!m) return;
    const sig = [m.lv, m.xp, m.pt, m.st.join(), m.mx.join(), m.c, m.cc.join(), m.dr, m.sp.join(), m.ss, m.bz, m.c2, m.fu, m.tr].join('|');
    if (sig === hudSig) return;
    hudSig = sig;

    const span = m.nx - m.lo;
    xpFill.style.width = `${((span > 0 ? Math.min(1, Math.max(0, (m.xp - m.lo) / span)) : 1) * 100).toFixed(1)}%`;
    const cd = clsOf(m.c, m.c2);
    levelInfo.textContent = `Niveau ${m.lv} ${cd.n}` + (cd.dc > 0 ? ` · Drones ${m.dr}` : '') + ` · Palier ${m.tr}`;
    scoreText.textContent = `Score : ${m.xp}`;

    skillsEl.classList.toggle('has-points', m.pt > 0);
    skillPts.textContent = m.pt > 0 ? `Points disponibles : ${m.pt}` : '';
    skillRows.forEach((r, i) => {
      const max = m.mx[i], isSuper = m.sp.includes(i), base = max - (isSuper ? 4 : 0);
      r.pips.forEach((p, j) => {
        p.style.display = j < max ? '' : 'none';
        p.classList.toggle('on', j < m.st[i]);
        p.classList.toggle('sup', j >= base);          // crans bonus (or)
      });
      r.row.classList.toggle('maxed', m.st[i] >= max);
      r.row.classList.toggle('locked', max === 0);
      r.row.classList.toggle('super', isSuper);
      r.lbl.textContent = STAT_NAMES[i] + (isSuper ? ' ★' : '');
      r.row.title = max === 0 ? 'Verrouillé pour cette classe' : isSuper ? 'Super-compétence : +4 crans' : '';
    });
    evoBtn.classList.toggle('hidden', !m.cc.length);
    superBtn.classList.toggle('hidden', !m.ss);
    busyBadge.classList.toggle('hidden', !m.bz);
    if (!m.ss) closeSuper();
    else if (!superPanel.classList.contains('hidden')) refreshSuper();
    if (!treeEl.classList.contains('hidden')) drawTree();
  }

  function renderLeaderboard(lb, ts2) {
    lbList.textContent = '';
    for (const [name, xp, id, team] of lb) {
      const li = document.createElement('li');
      li.textContent = `${name} — ${xp}`;
      li.className = (id === myId ? 'me ' : '') + (team ? `t${team}` : '');
      lbList.appendChild(li);
    }
    if (ts2) {
      $('tsBlue').textContent = `Bleu ${ts2[0]}`;
      $('tsRed').textContent = `Rouge ${ts2[1]}`;
    }
  }

  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.add('hidden'), 2600);
  }

  // ==================================================================== phase de choix (immunité côté serveur)
  const noteChoice = () => { if (meState && meState.pt > 0) choiceUntil = performance.now() + 1200; };
  /** vrai tant que le joueur gère ses améliorations : arbre / spécialisation ouverts, panneau survolé, achat récent */
  const uiBusy = () => playing && (!treeEl.classList.contains('hidden') || !superPanel.classList.contains('hidden')
    || (overSkills && !!meState && meState.pt > 0) || performance.now() < choiceUntil);
  $('skillsWrap').addEventListener('mouseenter', () => { overSkills = true; });
  $('skillsWrap').addEventListener('mouseleave', () => { overSkills = false; });
  // Ping 5x/s : le serveur accorde l'immunité (0,4 s renouvelée) tant que quelque chose reste à choisir
  setInterval(() => { if (uiBusy()) send({ t: 'p' }); }, 200);

  // ==================================================================== spécialisation finale (3 super-compétences)
  const superSel = new Set();
  const superBtns = STAT_NAMES.map((name, i) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = `${i + 1}. ${name}`;
    b.style.setProperty('--c', STAT_COLORS[i]);
    b.addEventListener('click', () => {
      if (superSel.has(i)) superSel.delete(i);
      else if (superSel.size < 3) superSel.add(i);
      refreshSuper();
    });
    superGrid.appendChild(b);
    return b;
  });
  function refreshSuper() {
    superBtns.forEach((b, i) => {
      b.classList.toggle('on', superSel.has(i));
      b.disabled = !meState || meState.mx[i] === 0;   // compétence verrouillée pour cette classe
    });
    superCount.textContent = `${superSel.size} / 3 choisies`;
    superOk.disabled = superSel.size !== 3;
  }
  function openSuper() { if (!meState || !meState.ss) return; refreshSuper(); superPanel.classList.remove('hidden'); }
  function closeSuper() { superPanel.classList.add('hidden'); }
  function toggleSuper() { if (superPanel.classList.contains('hidden')) openSuper(); else closeSuper(); }
  superOk.addEventListener('click', () => {
    if (superSel.size !== 3) return;
    send({ t: 'x', s: [...superSel] });
    superSel.clear();
    closeSuper();
    toast('Spécialisation enregistrée ★');
  });
  $('superClose').addEventListener('click', closeSuper);
  superBtn.addEventListener('click', openSuper);

  // ==================================================================== arbre d'évolution circulaire
  const TREE = { size: 760, bands: [0, 62, 152, 256, 366], nodes: [], hover: -1 };
  const tierOf = (lv) => (lv <= 1 ? 0 : lv <= 15 ? 1 : lv <= 30 ? 2 : 3);

  /** Place chaque classe sur un anneau (palier) et un secteur angulaire proportionnel à ses descendants. */
  function layoutTree() {
    TREE.nodes = [];
    const leaves = (id) => {
      const up = classes[id].up;
      return up.length ? up.reduce((s, c) => s + leaves(c), 0) : 1;
    };
    const place = (id, a0, a1, color, parent) => {
      const node = { id, tier: tierOf(classes[id].lv), a0, a1, color, parent };
      TREE.nodes.push(node);
      const up = classes[id].up;
      const total = up.reduce((s, c) => s + leaves(c), 0);
      let a = a0;
      for (const c of up) {
        const w = ((a1 - a0) * leaves(c)) / total;
        place(c, a, a + w, color, node);
        a += w;
      }
    };
    const root = { id: 0, tier: 0, a0: 0, a1: Math.PI * 2, color: '#ffffff', parent: null };
    TREE.nodes.push(root);
    const up = classes[0].up;
    const total = up.reduce((s, c) => s + leaves(c), 0);
    let a = -Math.PI / 2;
    up.forEach((c, i) => {
      const w = (Math.PI * 2 * leaves(c)) / total;
      place(c, a, a + w, BRANCH_COLORS[i % BRANCH_COLORS.length], root);
      a += w;
    });
  }

  /** ids atteignables depuis la classe courante (descendants) et ancêtres (chemin déjà parcouru). */
  function relatives(cur) {
    const down = new Set(), upSet = new Set();
    const walkDown = (id) => classes[id].up.forEach((c) => { if (!down.has(c)) { down.add(c); walkDown(c); } });
    const walkUp = (id) => classes[id].from.forEach((p) => { if (!upSet.has(p)) { upSet.add(p); walkUp(p); } });
    walkDown(cur);
    walkUp(cur);
    return { down, up: upSet };
  }

  function drawTree() {
    const S = TREE.size, cx = S / 2, cy = S / 2, B = TREE.bands;
    const cur = meState ? meState.c : 0;
    const cc = meState ? meState.cc : [];
    const rel = relatives(cur);
    const fuse = !!(meState && meState.fu);              // seconde évolution : toutes les classes sont éligibles
    const c2 = meState ? meState.c2 : -1;
    treeMsg.textContent = fuse ? 'Niveau 100 : choisis une seconde classe à fusionner avec la tienne (définitif).' : '';
    treeMsg.classList.toggle('hidden', !fuse);
    tctx.setTransform(1, 0, 0, 1, 0, 0);
    tctx.clearRect(0, 0, S, S);
    tctx.fillStyle = '#f1f1f1';
    tctx.fillRect(0, 0, S, S);
    tctx.lineJoin = 'round';

    TREE.nodes.forEach((n, idx) => {
      const r0 = B[n.tier], r1 = B[n.tier + 1];
      const isCur = (n.id === cur && (n.parent === null || n.parent.id !== cur)) || n.id === c2;
      const avail = fuse ? n.tier > 0 && n.id !== cur : !!(cc.includes(n.id) && n.parent && n.parent.id === cur);
      const dim = !fuse && !isCur && !avail && !rel.down.has(n.id) && !rel.up.has(n.id) && n.id !== cur;
      tctx.globalAlpha = dim ? 0.45 : 1;
      if (n.tier > 0) {
        tctx.beginPath();
        tctx.arc(cx, cy, r1, n.a0, n.a1);
        tctx.arc(cx, cy, r0, n.a1, n.a0, true);
        tctx.closePath();
        tctx.fillStyle = n.color;
        tctx.fill();
        tctx.lineWidth = avail || isCur || idx === TREE.hover ? 4 : 1.5;
        tctx.strokeStyle = avail ? (fuse ? '#9c27b0' : '#ff8a00') : isCur ? '#0085a8' : idx === TREE.hover ? '#222' : '#555';
        tctx.stroke();
      } else {
        tctx.beginPath();
        tctx.arc(cx, cy, r1, 0, Math.PI * 2);
        tctx.fillStyle = '#ffffff';
        tctx.fill();
        tctx.lineWidth = 2;
        tctx.strokeStyle = '#555';
        tctx.stroke();
      }
      tctx.globalAlpha = dim ? 0.55 : 1;
      const am = (n.a0 + n.a1) / 2, rm = n.tier === 0 ? 0 : (r0 + r1) / 2;
      const arc = rm * (n.a1 - n.a0);
      const ir = n.tier === 0 ? 20 : Math.max(7, Math.min(15, arc / 3.2, (r1 - r0) / 3.2));
      paintTank(tctx, cx + Math.cos(am) * rm, cy + Math.sin(am) * rm, ir, 0, classes[n.id], isCur ? BLUE : { fill: '#00b2e1', stroke: '#0085a8' }, {});
      tctx.globalAlpha = 1;
    });
  }

  function treeHit(ev) {
    const rect = treeCanvas.getBoundingClientRect();
    const S = TREE.size;
    const x = ((ev.clientX - rect.left) / rect.width) * S - S / 2;
    const y = ((ev.clientY - rect.top) / rect.height) * S - S / 2;
    const r = Math.hypot(x, y);
    let a = Math.atan2(y, x);
    for (let i = 0; i < TREE.nodes.length; i++) {
      const n = TREE.nodes[i];
      if (n.tier === 0) { if (r <= TREE.bands[1]) return i; continue; }
      if (r < TREE.bands[n.tier] || r > TREE.bands[n.tier + 1]) continue;
      // normalise l'angle dans [a0, a0 + 2π)
      let aa = a;
      while (aa < n.a0) aa += Math.PI * 2;
      while (aa >= n.a0 + Math.PI * 2) aa -= Math.PI * 2;
      if (aa <= n.a1) return i;
    }
    return -1;
  }

  treeCanvas.addEventListener('mousemove', (ev) => {
    const i = treeHit(ev);
    if (i !== TREE.hover) { TREE.hover = i; drawTree(); }
    if (i < 0) { treeTip.classList.add('hidden'); return; }
    const n = TREE.nodes[i], c = classes[n.id];
    const from = c.from.map((p) => classes[p].n).join(', ');
    const avail = meState && (meState.fu ? n.tier > 0 && n.id !== meState.c : meState.cc.includes(n.id) && n.parent && n.parent.id === meState.c);
    treeTip.innerHTML = '';
    const b = document.createElement('b');
    b.textContent = c.n;
    const lv = document.createElement('div');
    lv.textContent = `Niveau ${c.lv}` + (from ? ` · depuis ${from}` : '');
    const ds = document.createElement('div');
    ds.textContent = c.ds;
    treeTip.append(b, lv, ds);
    if (avail) {
      const em = document.createElement('em');
      em.textContent = meState.fu ? `Clique pour fusionner avec ${classes[meState.c].n}` : 'Clique pour évoluer';
      treeTip.appendChild(em);
    }
    treeTip.classList.remove('hidden');
  });
  treeCanvas.addEventListener('mouseleave', () => { TREE.hover = -1; treeTip.classList.add('hidden'); drawTree(); });
  treeCanvas.addEventListener('click', (ev) => {
    const i = treeHit(ev);
    if (i < 0 || !meState) return;
    const n = TREE.nodes[i];
    if (meState.fu) { if (n.tier > 0 && n.id !== meState.c) send({ t: 'f', c: n.id }); return; }
    if (meState.cc.includes(n.id) && n.parent && n.parent.id === meState.c) send({ t: 'c', c: n.id });
  });

  function openTree() { if (!classes.length) return; treeEl.classList.remove('hidden'); drawTree(); }
  function closeTree() { treeEl.classList.add('hidden'); treeTip.classList.add('hidden'); }
  function toggleTree() { if (treeEl.classList.contains('hidden')) openTree(); else closeTree(); }
  $('treeClose').addEventListener('click', closeTree);
  evoBtn.addEventListener('click', openTree);

  // ==================================================================== interpolation (LERP)
  const lerpAngle = (a0, a1, k) => {
    const d = ((a1 - a0 + Math.PI) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI) - Math.PI;
    return a0 + d * k;
  };

  /** Entités interpolées à l'instant serveur `rt` (entre les deux snapshots qui l'encadrent). */
  function sample(rt) {
    const n = snaps.length;
    if (!n) return null;
    if (rt <= snaps[0].t) return [...snaps[0].ents.values()];
    if (rt >= snaps[n - 1].t) return [...snaps[n - 1].ents.values()];

    let i = n - 2;
    while (i > 0 && snaps[i].t > rt) i--;
    const a = snaps[i], b = snaps[i + 1];
    const k = (rt - a.t) / (b.t - a.t), dtS = (b.t - a.t) / 1000;

    const out = [];
    for (const [id, e1] of b.ents) {
      const e0 = a.ents.get(id);
      if (!e0) { out.push(e1); continue; }
      const e = { ...e1, x: e0.x + (e1.x - e0.x) * k, y: e0.y + (e1.y - e0.y) * k };
      if (e1.a !== undefined && e0.a !== undefined) e.a = lerpAngle(e0.a, e1.a, k);
      if (e1.t === T_TANK) {
        e.vx = (e1.x - e0.x) / dtS;
        e.vy = (e1.y - e0.y) / dtS;
        e.sp = Math.hypot(e.vx, e.vy);
        if (e1.ta && e0.ta) e.ta = e1.ta.map((v, j) => lerpAngle(e0.ta[j] ?? v, v, k));
        if (e1.sa !== undefined && e0.sa !== undefined) e.sa = e0.sa + (e1.sa - e0.sa) * k;
      }
      out.push(e);
    }
    return out;
  }

  // ==================================================================== effets : particules, chiffres, secousses
  function burst(kind, x, y, r, tm) {
    const c1 = kind === 0 ? (TEAM[tm] || RED).fill : SHAPE_STYLE[kind] ? SHAPE_STYLE[kind].fill : '#ffe869';
    const c2 = kind === 0 ? GREY.fill : SHAPE_STYLE[kind] ? SHAPE_STYLE[kind].stroke : '#bfae4e';
    const n = Math.min(46, 8 + Math.round(r / 2.5));
    for (let i = 0; i < n && particles.length < 400; i++) {
      const a = Math.random() * Math.PI * 2, sp = 80 + Math.random() * (160 + r);
      const life = 0.45 + Math.random() * 0.55;
      particles.push({
        x: x + (Math.random() - 0.5) * r, y: y + (Math.random() - 0.5) * r,
        vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, rot: Math.random() * 6.28, vr: (Math.random() - 0.5) * 12,
        s: 3 + Math.random() * Math.max(3, r * 0.2), life, max: life, c: Math.random() < 0.7 ? c1 : c2, sq: Math.random() < 0.5,
      });
    }
  }

  function debris(e) {
    const st = SHAPE_STYLE[e.t];
    for (let i = 0; i < 2; i++) {
      const a = Math.random() * Math.PI * 2, sp = 60 + Math.random() * 90;
      particles.push({
        x: e.x, y: e.y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, rot: 0, vr: 6, s: 3 + Math.random() * 3,
        life: 0.35, max: 0.35, c: st.fill, sq: true,
      });
    }
  }

  function stepEffects(dt) {
    for (let i = particles.length - 1; i >= 0; i--) {
      const p = particles[i];
      p.life -= dt;
      if (p.life <= 0) { particles.splice(i, 1); continue; }
      p.x += p.vx * dt; p.y += p.vy * dt;
      p.vx *= 0.94; p.vy *= 0.94;
      p.rot += p.vr * dt;
    }
    for (let i = floaters.length - 1; i >= 0; i--) {
      const f = floaters[i];
      f.life -= dt;
      if (f.life <= 0) { floaters.splice(i, 1); continue; }
      f.y -= 55 * dt;
    }
    shake *= Math.pow(0.88, dt * 60);
    if (shake < 0.2) shake = 0;
  }

  // ==================================================================== dessin : tanks et canons
  function polyStar(c, n, outer, inner, rot, col) {
    c.beginPath();
    const pts = inner === outer ? n : n * 2;
    for (let i = 0; i < pts; i++) {
      const rad = inner === outer ? outer : (i % 2 === 0 ? outer : inner);
      const ang = rot + (i * 2 * Math.PI) / pts;
      const px = Math.cos(ang) * rad, py = Math.sin(ang) * rad;
      if (i === 0) c.moveTo(px, py); else c.lineTo(px, py);
    }
    c.closePath();
    c.fillStyle = col.fill;
    c.strokeStyle = col.stroke;
    c.fill();
    c.stroke();
  }

  /** Dessine un canon décrit par les données du serveur (longueur, largeur, décalage, angle, évasement). */
  function paintBarrel(c, r, b) {
    c.save();
    c.rotate(b.a);
    const hw = (b.w * r) / 2, L = b.l * r, off = b.o * r;
    c.fillStyle = GREY.fill;
    c.strokeStyle = GREY.stroke;
    c.beginPath();
    if (b.k === 't') {                       // lanceur de pièges : base fine + bloc évasé
      const s = L * 0.62, hw2 = hw * 1.5;
      c.moveTo(0, off - hw); c.lineTo(s, off - hw); c.lineTo(s, off - hw2); c.lineTo(L, off - hw2);
      c.lineTo(L, off + hw2); c.lineTo(s, off + hw2); c.lineTo(s, off + hw); c.lineTo(0, off + hw);
    } else {
      const hw2 = hw + b.fl * r;
      c.moveTo(0, off - hw); c.lineTo(L, off - hw2); c.lineTo(L, off + hw2); c.lineTo(0, off + hw);
    }
    c.closePath();
    c.fill();
    c.stroke();
    c.restore();
  }

  function paintAuto(c, r, b, ta, sa, pal) {
    const ang = b.an + (b.ro ? sa : 0);
    c.save();
    c.translate(Math.cos(ang) * b.ra * r, Math.sin(ang) * b.ra * r);
    c.rotate(ta);
    c.fillStyle = GREY.fill;
    c.strokeStyle = GREY.stroke;
    c.fillRect(0, -b.w * r * 0.5, b.l * r, b.w * r);
    c.strokeRect(0, -b.w * r * 0.5, b.l * r, b.w * r);
    c.beginPath();
    c.arc(0, 0, r * 0.42, 0, Math.PI * 2);
    c.fillStyle = GREY.fill;
    c.fill();
    c.stroke();
    c.restore();
  }

  function thrusters(c, r, o) {
    const k = Math.min(1, (o.sp - 40) / 260);
    c.save();
    c.rotate(Math.atan2(o.vy, o.vx) + Math.PI);   // à l'opposé du déplacement
    for (const side of [-0.5, 0.5]) {
      const len = r * (0.7 + 1.4 * k) * (0.85 + Math.random() * 0.3);
      for (const [w, col] of [[0.32, '#ff9f1c'], [0.16, '#fff3b0']]) {
        c.beginPath();
        c.moveTo(r * 0.7, side * r - w * r);
        c.lineTo(r * 0.7 + len * (w > 0.2 ? 1 : 0.65), side * r);
        c.lineTo(r * 0.7, side * r + w * r);
        c.closePath();
        c.fillStyle = col;
        c.fill();
      }
    }
    c.restore();
  }

  /** Dessine un tank complet (canons, corps spécial, tourelles). Sert au jeu comme aux icônes de l'arbre. */
  function paintTank(c, x, y, r, a, cls, pal, o) {
    c.save();
    c.translate(x, y);
    if (o.alpha !== undefined) c.globalAlpha *= o.alpha;
    c.lineJoin = 'round';
    c.lineWidth = Math.max(1.5, r * 0.12);

    if (cls.th && o.sp > 50) thrusters(c, r, o);

    c.save();
    c.rotate(a);
    for (const b of cls.bar) if (b.k !== 'a') paintBarrel(c, r, b);
    c.restore();

    const t = o.t || 0;
    if (cls.body === 1) polyStar(c, 6, r * 1.3, r * 1.3, t * 0.0015, DARK);
    else if (cls.body === 2) polyStar(c, 12, r * 1.55, r * 1.1, t * 0.0025, DARK);
    else if (cls.body === 3) {
      polyStar(c, 6, r * 1.32, r * 1.32, t * 0.0012, DARK);
      polyStar(c, 6, r * 1.14, r * 1.14, -t * 0.0006, { fill: '#6f6f6f', stroke: '#3f3f3f' });
    }

    c.beginPath();
    c.arc(0, 0, r, 0, Math.PI * 2);
    c.fillStyle = pal.fill;
    c.strokeStyle = pal.stroke;
    c.fill();
    c.stroke();
    if (o.flash) { c.fillStyle = 'rgba(255,255,255,0.55)'; c.fill(); }

    cls.bar.forEach((b, i) => { if (b.k === 'a') paintAuto(c, r, b, o.ta ? o.ta[i] : 0, o.sa || 0, pal); });
    c.restore();
  }

  // ==================================================================== dessin : monde
  function palOf(e) {
    if (mode === 'teams' && e.tm) return TEAM[e.tm] || RED;
    return e.i === myId || e.o === myId ? BLUE : RED;
  }

  function drawPoly(x, y, r, a, sides) {
    ctx.beginPath();
    for (let i = 0; i < sides; i++) {
      const ang = a + (i * 2 * Math.PI) / sides;
      const px = x + Math.cos(ang) * r, py = y + Math.sin(ang) * r;
      if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
    }
    ctx.closePath();
  }

  function rrect(c, x, y, w, h, r) {
    if (c.roundRect) c.roundRect(x, y, w, h, r); else c.rect(x, y, w, h);
  }

  function drawHpBar(x, y, w, pct) {
    ctx.fillStyle = 'rgba(40, 40, 40, 0.75)';
    ctx.beginPath();
    rrect(ctx, x - w / 2 - 2, y - 2, w + 4, 10, 5);
    ctx.fill();
    ctx.fillStyle = '#85e37d';
    ctx.beginPath();
    rrect(ctx, x - w / 2, y, Math.max(0, (w * pct) / 100), 6, 3);
    ctx.fill();
  }

  function drawShape(s, now) {
    const st = SHAPE_STYLE[s.t];
    if (!st) return;
    const R = s.r * st.k;
    if (s.t === 5) {                                  // Alpha : halo pulsé
      ctx.beginPath();
      ctx.arc(s.x, s.y, R * (1.18 + 0.04 * Math.sin(now * 0.003)), 0, Math.PI * 2);
      ctx.lineWidth = 10;
      ctx.strokeStyle = 'rgba(118,141,252,0.22)';
      ctx.stroke();
    }
    drawPoly(s.x, s.y, R, s.a || 0, st.sides);
    ctx.fillStyle = st.fill;
    ctx.strokeStyle = st.stroke;
    ctx.lineWidth = s.t === 5 ? 8 : 4;
    ctx.lineJoin = 'round';
    ctx.fill();
    ctx.stroke();
    if ((flash.get(s.i) || 0) > now) { ctx.fillStyle = 'rgba(255,255,255,0.5)'; ctx.fill(); }
    if (s.hp < 100) drawHpBar(s.x, s.y + R + 12, s.t === 5 ? 260 : s.r * 1.4, s.hp);
    if (s.t === 5) {
      ctx.font = 'bold 22px Ubuntu, "Segoe UI", sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      ctx.fillStyle = 'rgba(40,40,60,0.55)';
      ctx.fillText('Alpha Pentagone', s.x, s.y - R - 14);
    }
  }

  function drawBullet(b) {
    const p = palOf(b);
    if (b.t === T_MISSILE) {                          // missile / feu d'artifice : traînée de flamme
      ctx.save();
      ctx.translate(b.x, b.y);
      ctx.rotate(b.a || 0);
      ctx.beginPath();
      ctx.moveTo(-b.r * 0.6, -b.r * 0.7);
      ctx.lineTo(-b.r * (2.4 + Math.random() * 0.8), 0);
      ctx.lineTo(-b.r * 0.6, b.r * 0.7);
      ctx.fillStyle = '#ffb142';
      ctx.fill();
      ctx.restore();
    }
    ctx.beginPath();
    ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
    ctx.fillStyle = p.fill;
    ctx.strokeStyle = p.stroke;
    ctx.lineWidth = 3;
    ctx.fill();
    ctx.stroke();
  }

  /** Piège : polygone fixe au sol (triangle rotatif aux couleurs de l'équipe). */
  function drawTrap(t) {
    const p = palOf(t);
    drawPoly(t.x, t.y, t.r * 1.35, t.a || 0, 3);
    ctx.fillStyle = p.fill;
    ctx.globalAlpha = 0.75;
    ctx.fill();
    ctx.globalAlpha = 1;
    ctx.strokeStyle = p.stroke;
    ctx.lineWidth = 3.5;
    ctx.lineJoin = 'round';
    ctx.stroke();
  }

  /** Drones : triangles (0), carrés (1), mini-tanks (2), partisans (3). */
  function drawDrone(d) {
    const p = palOf(d);
    ctx.fillStyle = p.fill;
    ctx.strokeStyle = p.stroke;
    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    if (d.k === 1) {
      drawPoly(d.x, d.y, d.r * 1.3, (d.a || 0) + Math.PI / 4, 4);
    } else if (d.k === 2) {
      ctx.save();
      ctx.translate(d.x, d.y);
      ctx.rotate(d.a || 0);
      ctx.fillStyle = GREY.fill;
      ctx.strokeStyle = GREY.stroke;
      ctx.fillRect(0, -d.r * 0.3, d.r * 1.4, d.r * 0.6);
      ctx.strokeRect(0, -d.r * 0.3, d.r * 1.4, d.r * 0.6);
      ctx.restore();
      ctx.fillStyle = p.fill;
      ctx.strokeStyle = p.stroke;
      ctx.beginPath();
      ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
    } else {
      drawPoly(d.x, d.y, d.r * (d.k === 3 ? 1.4 : 1.35), d.a || 0, 3);
    }
    ctx.fill();
    ctx.stroke();
  }

  function drawTurret(t) {
    const p = TEAM[t.tm] || RED;
    ctx.save();
    ctx.translate(t.x, t.y);
    ctx.rotate(t.a || 0);
    ctx.fillStyle = GREY.fill;
    ctx.strokeStyle = GREY.stroke;
    ctx.lineWidth = 4;
    ctx.fillRect(0, -t.r * 0.32, t.r * 1.45, t.r * 0.64);
    ctx.strokeRect(0, -t.r * 0.32, t.r * 1.45, t.r * 0.64);
    ctx.restore();
    ctx.beginPath();
    ctx.arc(t.x, t.y, t.r, 0, Math.PI * 2);
    ctx.fillStyle = '#7b7b84';
    ctx.strokeStyle = p.stroke;
    ctx.lineWidth = 6;
    ctx.fill();
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(t.x, t.y, t.r * 0.55, 0, Math.PI * 2);
    ctx.fillStyle = p.fill;
    ctx.fill();
  }

  function drawTankEntity(t, now) {
    const cls = clsOf(t.c, t.c2);
    const pal = palOf(t);
    let alpha;
    if (t.sh) alpha = 0.55 + 0.25 * Math.sin(now * 0.02);   // protection de spawn clignotante
    if (t.sl) alpha = 0.3;                                  // furtivité (visible de soi-même seulement)
    paintTank(ctx, t.x, t.y, t.r, t.a || 0, cls, pal, {
      flash: (flash.get(t.i) || 0) > now, t: now, alpha,
      sp: t.sp || 0, vx: t.vx || 0, vy: t.vy || 0, ta: t.ta, sa: t.sa,
    });
    if (t.bz) {                                             // bulle d'immunité (phase de choix)
      ctx.beginPath();
      ctx.arc(t.x, t.y, t.r * 1.5 + Math.sin(now * 0.006) * 2, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(120,230,255,0.16)';
      ctx.fill();
      ctx.lineWidth = 4;
      ctx.strokeStyle = 'rgba(120,230,255,0.85)';
      ctx.stroke();
    }
    if (t.n) {
      ctx.font = 'bold 18px Ubuntu, "Segoe UI", sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      ctx.lineWidth = 4;
      ctx.strokeStyle = 'rgba(0,0,0,0.55)';
      ctx.strokeText(t.n, t.x, t.y - t.r * (cls.body ? 1.5 : 1) - 10);
      ctx.fillStyle = '#fff';
      ctx.fillText(t.n, t.x, t.y - t.r * (cls.body ? 1.5 : 1) - 10);
    }
    if (t.hp < 100) drawHpBar(t.x, t.y + t.r * (cls.body ? 1.5 : 1) + 12, t.r * 1.6, t.hp);
  }

  function drawGrid(vw, vh) {
    const x0 = Math.max(0, Math.floor((cam.x - vw / 2) / GRID_STEP) * GRID_STEP);
    const x1 = Math.min(world, Math.ceil((cam.x + vw / 2) / GRID_STEP) * GRID_STEP);
    const y0 = Math.max(0, Math.floor((cam.y - vh / 2) / GRID_STEP) * GRID_STEP);
    const y1 = Math.min(world, Math.ceil((cam.y + vh / 2) / GRID_STEP) * GRID_STEP);
    ctx.beginPath();
    for (let x = x0; x <= x1; x += GRID_STEP) { ctx.moveTo(x, y0); ctx.lineTo(x, y1); }
    for (let y = y0; y <= y1; y += GRID_STEP) { ctx.moveTo(x0, y); ctx.lineTo(x1, y); }
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(0,0,0,0.1)';
    ctx.stroke();
  }

  function drawMinimap(tanks, vw, vh) {
    const w = mm.width, k = w / world;
    mctx.clearRect(0, 0, w, w);
    mctx.fillStyle = 'rgba(205,205,205,0.85)';
    mctx.fillRect(0, 0, w, w);
    for (const b of bases) {
      mctx.fillStyle = b[4] === 1 ? 'rgba(0,178,225,0.3)' : 'rgba(241,78,84,0.3)';
      mctx.fillRect(b[0] * k, b[1] * k, (b[2] - b[0]) * k, (b[3] - b[1]) * k);
    }
    mctx.fillStyle = 'rgba(118,141,252,0.6)';          // nid du pentagone Alpha
    mctx.beginPath();
    mctx.arc(w / 2, w / 2, 4, 0, Math.PI * 2);
    mctx.fill();
    mctx.strokeStyle = 'rgba(0,0,0,0.35)';
    mctx.strokeRect((cam.x - vw / 2) * k, (cam.y - vh / 2) * k, vw * k, vh * k);
    for (const t of tanks) {
      if (t.i === myId) continue;
      mctx.fillStyle = (mode === 'teams' && t.tm === 1 ? BLUE : RED).fill;
      mctx.beginPath();
      mctx.arc(t.x * k, t.y * k, 2.5, 0, Math.PI * 2);
      mctx.fill();
    }
    mctx.fillStyle = BLUE.fill;
    mctx.beginPath();
    mctx.arc(cam.x * k, cam.y * k, 3.5, 0, Math.PI * 2);
    mctx.fill();
  }

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = innerWidth;
    H = innerHeight;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
  }
  addEventListener('resize', resize);
  resize();

  function frame() {
    requestAnimationFrame(frame);
    const now = performance.now();
    const dt = Math.min(0.05, (now - lastFrame) / 1000 || 0.016);
    lastFrame = now;
    stepEffects(dt);

    const ents = clockOffset === null ? null : sample(now - clockOffset - INTERP_DELAY);
    const shapes = [], bullets = [], traps = [], drones = [], turrets = [], tanks = [];
    let me = null;
    if (ents) {
      for (const e of ents) {
        switch (e.t) {
          case T_TANK: tanks.push(e); if (e.i === myId) me = e; break;
          case T_BULLET: case T_MISSILE: bullets.push(e); break;
          case T_TRAP: traps.push(e); break;
          case T_DRONE: drones.push(e); break;
          case T_TURRET: turrets.push(e); break;
          default: shapes.push(e);
        }
      }
    }
    shapes.sort((a, b) => b.r - a.r);                 // Alpha d'abord (dessous)

    // Caméra : suit le joueur ; le Predator décale la vue vers le curseur quand on maintient le clic droit
    const cd = meState ? clsOf(meState.c, meState.c2) : null;
    const wantZoom = cd && cd.zm && (rmbDown || shiftDown);
    const zt = wantZoom ? Math.min(Math.hypot(mouse.x - W / 2, mouse.y - H / 2) / (scale || 1), 900) * 0.9 : 0;
    zoomOff += (zt - zoomOff) * 0.15;
    const ang = Math.atan2(mouse.y - H / 2, mouse.x - W / 2);
    if (me) { cam.x = me.x + Math.cos(ang) * zoomOff; cam.y = me.y + Math.sin(ang) * zoomOff; }

    const target = Math.max(W / VIEW_W, H / VIEW_H) / (meState ? meState.fv : 1);
    scale = scale === 0 ? target : scale + (target - scale) * 0.1;
    const vw = W / scale, vh = H / scale;

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#b9b9b9';                         // zone hors arène
    ctx.fillRect(0, 0, W, H);

    ctx.save();
    if (shake > 0) ctx.translate((Math.random() - 0.5) * shake, (Math.random() - 0.5) * shake);
    ctx.translate(W / 2, H / 2);
    ctx.scale(scale, scale);
    ctx.translate(-cam.x, -cam.y);

    ctx.fillStyle = '#cdcdcd';                         // arène
    ctx.fillRect(0, 0, world, world);
    for (const b of bases) {                           // bases d'équipe
      ctx.fillStyle = b[4] === 1 ? 'rgba(0,178,225,0.18)' : 'rgba(241,78,84,0.18)';
      ctx.fillRect(b[0], b[1], b[2] - b[0], b[3] - b[1]);
    }
    drawGrid(vw, vh);

    for (const t of traps) drawTrap(t);
    for (const s of shapes) drawShape(s, now);
    for (const b of bullets) drawBullet(b);
    for (const d of drones) drawDrone(d);
    for (const t of turrets) drawTurret(t);
    for (const t of tanks) if (t !== me) drawTankEntity(t, now);
    if (me) drawTankEntity(me, now);

    for (const p of particles) {                       // débris colorés
      ctx.globalAlpha = Math.max(0, p.life / p.max);
      ctx.fillStyle = p.c;
      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      if (p.sq) ctx.fillRect(-p.s / 2, -p.s / 2, p.s, p.s);
      else { ctx.beginPath(); ctx.arc(0, 0, p.s / 2, 0, Math.PI * 2); ctx.fill(); }
      ctx.restore();
    }
    ctx.globalAlpha = 1;

    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const f of floaters) {                        // chiffres de dégâts flottants
      const a = Math.min(1, (f.life / f.max) * 1.6);
      ctx.globalAlpha = a;
      ctx.font = `bold ${Math.round(15 + Math.min(14, f.n * 0.5))}px Ubuntu, "Segoe UI", sans-serif`;
      ctx.lineWidth = 4;
      ctx.strokeStyle = 'rgba(0,0,0,0.6)';
      ctx.strokeText(String(f.n), f.x, f.y);
      ctx.fillStyle = f.recv ? '#ff6b6b' : '#fff7b0';
      ctx.fillText(String(f.n), f.x, f.y);
    }
    ctx.globalAlpha = 1;
    ctx.restore();

    if (playing) drawMinimap(tanks, vw, vh);
  }

  connect();
  requestAnimationFrame(frame);
})();
