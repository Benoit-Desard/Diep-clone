'use strict';

(() => {
  // ------------------------------------------------------------------ constantes
  const WS_PORT = 8080;
  const INTERP_DELAY = 90;               // ms de retard volontaire pour interpoler entre 2 snapshots
  const VIEW_W = 1920, VIEW_H = 1080;    // zone visible de référence (doit correspondre au serveur)
  const GRID_STEP = 50;

  const BLUE = { fill: '#00b2e1', stroke: '#0085a8' };
  const RED = { fill: '#f14e54', stroke: '#b43a3f' };
  const BARREL = { fill: '#999999', stroke: '#727272' };
  // k : échelle de dessin par rapport au rayon de collision serveur
  const SHAPES = {
    2: { sides: 4, k: 1.2, fill: '#ffe869', stroke: '#bfae4e' },
    3: { sides: 3, k: 1.1, fill: '#fc7677', stroke: '#bd5758' },
    4: { sides: 5, k: 1.05, fill: '#768dfc', stroke: '#5869bd' },
  };
  const STAT_NAMES = ['Régénération', 'PV max', 'Dégâts corporels', 'Vitesse des balles',
    'Pénétration', 'Dégâts des balles', 'Rechargement', 'Vitesse de déplacement'];
  const STAT_COLORS = ['#e5ad8e', '#e664e6', '#9a6df0', '#6d9bf0', '#f0e65f', '#f06d6d', '#8fe05c', '#5fe0e0'];
  const CLASS_NAMES = ['Tank', 'Twin', 'Sniper', 'Machine Gun'];
  const KEYMAP = {
    KeyW: 1, ArrowUp: 1,       // KeyW = touche Z sur un clavier AZERTY
    KeyS: 2, ArrowDown: 2,
    KeyA: 4, ArrowLeft: 4,     // KeyA = touche Q sur un clavier AZERTY
    KeyD: 8, ArrowRight: 8,
  };

  // ------------------------------------------------------------------ DOM
  const $ = (id) => document.getElementById(id);
  const canvas = $('game'), ctx = canvas.getContext('2d');
  const mm = $('minimap'), mctx = mm.getContext('2d');
  const hud = $('hud'), menu = $('menu');
  const nameInput = $('nameInput'), playBtn = $('playBtn');
  const statusEl = $('status'), menuInfo = $('menuInfo');
  const lbList = $('lbList'), classPick = $('classPick');
  const skillsEl = $('skills'), skillPts = $('skillPts');
  const xpFill = $('xpFill'), levelInfo = $('levelInfo'), scoreText = $('scoreText');

  // ------------------------------------------------------------------ état
  let ws = null;
  let myId = 0;
  let world = 4000;
  let playing = false;
  let snaps = [];            // [{t, ents: Map(id -> entité)}] (t = temps serveur en ms)
  let clockOffset = null;    // estimation de (temps local - temps serveur)
  let meState = null;        // dernier état personnel reçu (xp, niveau, stats...)
  let hudSig = '';
  let W = 0, H = 0, dpr = 1;
  let scale = 0;
  const cam = { x: 2000, y: 2000 };

  const held = new Set();
  const mouse = { x: innerWidth / 2, y: innerHeight / 2 };
  let mouseDown = false, spaceDown = false;
  let lastSig = '', lastSent = 0;

  // ------------------------------------------------------------------ réseau
  function send(obj) {
    if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
  }

  function connect() {
    ws = new WebSocket(`ws://${location.hostname || 'localhost'}:${WS_PORT}`);
    ws.onopen = () => {
      statusEl.textContent = 'Connecté au serveur';
      playBtn.disabled = false;
    };
    ws.onclose = () => {
      playing = false;
      playBtn.disabled = true;
      hud.classList.add('hidden');
      menu.classList.remove('hidden');
      menuInfo.textContent = '';
      statusEl.textContent = 'Connexion perdue, nouvelle tentative…';
      setTimeout(connect, 2000);
    };
    ws.onmessage = (ev) => {
      let m;
      try { m = JSON.parse(ev.data); } catch { return; }
      handle(m);
    };
  }

  function handle(m) {
    switch (m.t) {
      case 'sp':
        myId = m.id;
        world = m.w;
        snaps = [];
        clockOffset = null;
        meState = null;
        hudSig = '';
        playing = true;
        menu.classList.add('hidden');
        hud.classList.remove('hidden');
        break;
      case 'u': {
        const ents = new Map();
        for (const e of m.e) ents.set(e.i, e);
        const d = performance.now() - m.ts;
        clockOffset = clockOffset === null || d < clockOffset ? d : clockOffset + (d - clockOffset) * 0.01;
        snaps.push({ t: m.ts, ents });
        if (snaps.length > 12) snaps.shift();
        meState = m.me;
        if (m.lb) renderLeaderboard(m.lb);
        updateHud();
        break;
      }
      case 'd':
        playing = false;
        held.clear();
        mouseDown = spaceDown = false;
        hud.classList.add('hidden');
        menuInfo.textContent = `Détruit par ${m.k || 'un polygone'} · niveau ${m.l} · score ${m.s}`;
        playBtn.textContent = 'Rejouer';
        menu.classList.remove('hidden');
        break;
    }
  }

  // ------------------------------------------------------------------ inputs
  function keyMask() {
    let k = 0;
    for (const code of held) k |= KEYMAP[code] || 0;
    return k;
  }

  addEventListener('keydown', (e) => {
    if (!playing) return;
    if (e.code in KEYMAP || e.code === 'Space') e.preventDefault();
    if (e.code in KEYMAP) held.add(e.code);
    else if (e.code === 'Space') spaceDown = true;
    else {
      const d = /^(?:Digit|Numpad)([1-8])$/.exec(e.code);
      if (d) send({ t: 's', i: Number(d[1]) - 1 });
    }
  });
  addEventListener('keyup', (e) => {
    held.delete(e.code);
    if (e.code === 'Space') spaceDown = false;
  });
  addEventListener('blur', () => { held.clear(); mouseDown = spaceDown = false; });
  addEventListener('mousemove', (e) => { mouse.x = e.clientX; mouse.y = e.clientY; });
  addEventListener('mousedown', (e) => { if (e.button === 0 && e.target === canvas) mouseDown = true; });
  addEventListener('mouseup', (e) => { if (e.button === 0) mouseDown = false; });
  canvas.addEventListener('contextmenu', (e) => e.preventDefault());

  // Envoi des inputs à 30 Hz (uniquement s'ils changent, + un battement toutes les 250 ms)
  setInterval(() => {
    if (!playing) return;
    const k = keyMask();
    const a = Math.round(Math.atan2(mouse.y - H / 2, mouse.x - W / 2) * 100) / 100;
    const f = mouseDown || spaceDown ? 1 : 0;
    const sig = `${k}|${a}|${f}`;
    const now = performance.now();
    if (sig !== lastSig || now - lastSent > 250) {
      send({ t: 'i', k, a, f });
      lastSig = sig;
      lastSent = now;
    }
  }, 1000 / 30);

  // ------------------------------------------------------------------ menu
  function play() {
    const name = nameInput.value.trim();
    try { localStorage.setItem('diep-name', name); } catch { /* ignoré */ }
    send({ t: 'j', n: name });
  }
  playBtn.addEventListener('click', play);
  nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !playBtn.disabled) play(); });
  try { nameInput.value = localStorage.getItem('diep-name') || ''; } catch { /* ignoré */ }

  classPick.querySelectorAll('button').forEach((b) =>
    b.addEventListener('click', () => send({ t: 'c', c: Number(b.dataset.c) })));

  // ------------------------------------------------------------------ HUD
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
    for (let j = 0; j < 7; j++) {
      const p = document.createElement('span');
      p.className = 'pip';
      pips.appendChild(p);
      pipEls.push(p);
    }
    const key = document.createElement('span');
    key.className = 'key';
    key.textContent = String(i + 1);
    row.append(lbl, pips, key);
    row.addEventListener('click', () => send({ t: 's', i }));
    skillsEl.appendChild(row);
    skillRows.push({ row, pips: pipEls });
  });

  function updateHud() {
    const m = meState;
    if (!m) return;
    const sig = [m.lv, m.xp, m.pt, m.st.join(), m.c, m.cc].join('|');
    if (sig === hudSig) return;
    hudSig = sig;

    const span = m.nx - m.lo;
    const pct = span > 0 ? Math.min(1, Math.max(0, (m.xp - m.lo) / span)) : 1;
    xpFill.style.width = `${(pct * 100).toFixed(1)}%`;
    levelInfo.textContent = `Niveau ${m.lv} ${CLASS_NAMES[m.c]}`;
    scoreText.textContent = `Score : ${m.xp}`;

    skillsEl.classList.toggle('has-points', m.pt > 0);
    skillPts.textContent = m.pt > 0 ? `Points disponibles : ${m.pt}` : '';
    skillRows.forEach((r, i) => {
      r.pips.forEach((p, j) => p.classList.toggle('on', j < m.st[i]));
      r.row.classList.toggle('maxed', m.st[i] >= 7);
    });
    classPick.classList.toggle('hidden', !m.cc);
  }

  function renderLeaderboard(lb) {
    lbList.textContent = '';
    for (const [name, xp, id] of lb) {
      const li = document.createElement('li');
      li.textContent = `${name} — ${xp}`;
      if (id === myId) li.className = 'me';
      lbList.appendChild(li);
    }
  }

  // ------------------------------------------------------------------ interpolation
  function lerpAngle(a0, a1, k) {
    const d = ((a1 - a0 + Math.PI) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI) - Math.PI;
    return a0 + d * k;
  }

  /** Renvoie la liste des entités interpolées à l'instant serveur `rt`. */
  function sample(rt) {
    const n = snaps.length;
    if (!n) return null;
    if (rt <= snaps[0].t) return [...snaps[0].ents.values()];
    if (rt >= snaps[n - 1].t) return [...snaps[n - 1].ents.values()];

    let i = n - 2;
    while (i > 0 && snaps[i].t > rt) i--;
    const a = snaps[i], b = snaps[i + 1];
    const k = (rt - a.t) / (b.t - a.t);

    const out = [];
    for (const [id, e1] of b.ents) {
      const e0 = a.ents.get(id);
      if (!e0) { out.push(e1); continue; }
      const e = { ...e1, x: e0.x + (e1.x - e0.x) * k, y: e0.y + (e1.y - e0.y) * k };
      if (e1.a !== undefined && e0.a !== undefined) e.a = lerpAngle(e0.a, e1.a, k);
      out.push(e);
    }
    return out;
  }

  // ------------------------------------------------------------------ rendu
  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = innerWidth;
    H = innerHeight;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
  }
  addEventListener('resize', resize);
  resize();

  function drawGrid(vw, vh) {
    const x0 = Math.max(0, Math.floor((cam.x - vw / 2) / GRID_STEP) * GRID_STEP);
    const x1 = Math.min(world, Math.ceil((cam.x + vw / 2) / GRID_STEP) * GRID_STEP);
    const y0 = Math.max(0, Math.floor((cam.y - vh / 2) / GRID_STEP) * GRID_STEP);
    const y1 = Math.min(world, Math.ceil((cam.y + vh / 2) / GRID_STEP) * GRID_STEP);
    ctx.beginPath();
    for (let x = x0; x <= x1; x += GRID_STEP) { ctx.moveTo(x, y0); ctx.lineTo(x, y1); }
    for (let y = y0; y <= y1; y += GRID_STEP) { ctx.moveTo(x0, y); ctx.lineTo(x1, y); }
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(0, 0, 0, 0.1)';
    ctx.stroke();
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

  function drawHpBar(x, y, w, pct) {
    const h = 6, top = y;
    ctx.fillStyle = 'rgba(40, 40, 40, 0.75)';
    ctx.beginPath();
    ctx.roundRect(x - w / 2 - 2, top - 2, w + 4, h + 4, 5);
    ctx.fill();
    ctx.fillStyle = '#85e37d';
    ctx.beginPath();
    ctx.roundRect(x - w / 2, top, Math.max(0, (w * pct) / 100), h, 3);
    ctx.fill();
  }

  function drawShape(s) {
    const st = SHAPES[s.t];
    if (!st) return;
    drawPoly(s.x, s.y, s.r * st.k, s.a || 0, st.sides);
    ctx.fillStyle = st.fill;
    ctx.strokeStyle = st.stroke;
    ctx.lineWidth = 4;
    ctx.lineJoin = 'round';
    ctx.fill();
    ctx.stroke();
    if (s.hp < 100) drawHpBar(s.x, s.y + s.r * st.k + 10, s.r * 1.4, s.hp);
  }

  function drawBullet(b) {
    const c = b.o === myId ? BLUE : RED;
    ctx.beginPath();
    ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
    ctx.fillStyle = c.fill;
    ctx.strokeStyle = c.stroke;
    ctx.lineWidth = 3;
    ctx.fill();
    ctx.stroke();
  }

  function drawTank(t) {
    const c = t.i === myId ? BLUE : RED;
    const r = t.r;
    ctx.save();
    ctx.translate(t.x, t.y);
    ctx.rotate(t.a || 0);
    ctx.fillStyle = BARREL.fill;
    ctx.strokeStyle = BARREL.stroke;
    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    const rect = (x, y, w, h) => { ctx.fillRect(x, y, w, h); ctx.strokeRect(x, y, w, h); };
    switch (t.c) {
      case 1: // Twin
        rect(0, -0.42 * r - 0.31 * r, 1.75 * r, 0.62 * r);
        rect(0, 0.42 * r - 0.31 * r, 1.75 * r, 0.62 * r);
        break;
      case 2: // Sniper
        rect(0, -0.375 * r, 2.3 * r, 0.75 * r);
        break;
      case 3: // Machine Gun : canon évasé
        ctx.beginPath();
        ctx.moveTo(0, -0.34 * r);
        ctx.lineTo(1.7 * r, -0.58 * r);
        ctx.lineTo(1.7 * r, 0.58 * r);
        ctx.lineTo(0, 0.34 * r);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();
        break;
      default:
        rect(0, -0.42 * r, 1.75 * r, 0.84 * r);
    }
    ctx.restore();

    ctx.beginPath();
    ctx.arc(t.x, t.y, r, 0, Math.PI * 2);
    ctx.fillStyle = c.fill;
    ctx.strokeStyle = c.stroke;
    ctx.lineWidth = 4;
    ctx.fill();
    ctx.stroke();

    if (t.n) {
      ctx.font = 'bold 18px Ubuntu, "Segoe UI", sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      ctx.lineWidth = 4;
      ctx.strokeStyle = 'rgba(0, 0, 0, 0.55)';
      ctx.strokeText(t.n, t.x, t.y - r - 10);
      ctx.fillStyle = '#fff';
      ctx.fillText(t.n, t.x, t.y - r - 10);
    }
    if (t.hp < 100) drawHpBar(t.x, t.y + r + 12, r * 1.6, t.hp);
  }

  function drawMinimap(tanks, vw, vh) {
    const w = mm.width, k = w / world;
    mctx.clearRect(0, 0, w, w);
    mctx.fillStyle = 'rgba(205, 205, 205, 0.8)';
    mctx.fillRect(0, 0, w, w);
    mctx.strokeStyle = 'rgba(0, 0, 0, 0.35)';
    mctx.lineWidth = 1;
    mctx.strokeRect((cam.x - vw / 2) * k, (cam.y - vh / 2) * k, vw * k, vh * k);
    mctx.fillStyle = RED.fill;
    for (const t of tanks) {
      if (t.i === myId) continue;
      mctx.beginPath();
      mctx.arc(t.x * k, t.y * k, 2.5, 0, Math.PI * 2);
      mctx.fill();
    }
    mctx.fillStyle = BLUE.fill;
    mctx.beginPath();
    mctx.arc(cam.x * k, cam.y * k, 3.5, 0, Math.PI * 2);
    mctx.fill();
  }

  function frame() {
    requestAnimationFrame(frame);

    const ents = clockOffset === null ? null : sample(performance.now() - clockOffset - INTERP_DELAY);
    const shapes = [], bullets = [], tanks = [];
    let me = null;
    if (ents) {
      for (const e of ents) {
        if (e.t === 0) { tanks.push(e); if (e.i === myId) me = e; }
        else if (e.t === 1) bullets.push(e);
        else shapes.push(e);
      }
    }
    if (me) { cam.x = me.x; cam.y = me.y; }

    const target = Math.max(W / VIEW_W, H / VIEW_H) / (meState ? meState.fv : 1);
    scale = scale === 0 ? target : scale + (target - scale) * 0.1;
    const vw = W / scale, vh = H / scale;

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#b9b9b9';                       // zone hors arène
    ctx.fillRect(0, 0, W, H);

    ctx.save();
    ctx.translate(W / 2, H / 2);
    ctx.scale(scale, scale);
    ctx.translate(-cam.x, -cam.y);

    ctx.fillStyle = '#cdcdcd';                       // arène
    ctx.fillRect(0, 0, world, world);
    drawGrid(vw, vh);

    for (const s of shapes) drawShape(s);
    for (const b of bullets) drawBullet(b);
    for (const t of tanks) if (t !== me) drawTank(t);
    if (me) drawTank(me);
    ctx.restore();

    if (playing) drawMinimap(tanks, vw, vh);
  }
  requestAnimationFrame(frame);

  connect();
})();
