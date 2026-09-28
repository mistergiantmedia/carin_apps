/* Familie Planner: relations web (netwerk.php).
   Nodes: our family (around "Ons gezin"), groups (class, team, work, family…), households and people from
   the address book. Links: member/contact → group (with role), contact → household, contact → "vriend van" member.
   Small force-directed layout drawn in SVG: drag nodes, drag the background to pan, scroll to zoom,
   click to see someone's connections, double-click to open their page. */
(function () {
  'use strict';
  const svg = document.getElementById('net');
  if (!svg || !window.FP) return;
  const { api, esc } = window.FP;
  const NS = 'http://www.w3.org/2000/svg';
  const XLINK = 'http://www.w3.org/1999/xlink';
  const RADIUS = { home: 30, member: 26, group: 22, household: 13, contact: 17 };
  const LENGTH = { home: 85, group: 95, household: 45, friend: 140 };
  const panel = document.getElementById('net-panel');
  const filterBox = document.getElementById('net-filters');
  const search = document.getElementById('net-search');

  let nodes = [];
  let links = [];
  let byId = {};
  const hidden = { friend: false, household: false }; // plus group types: hidden[type] = true
  let focusSet = null; // ids shown in focus mode (someone + their neighbourhood)
  let selected = null;
  let alpha = 1;
  let running = false;
  const view = { x: 0, y: 0, k: 1 };
  let userMoved = false; // after the user zooms or pans we stop re-fitting automatically

  const toggleClass = (node, cls, on) => {
    const list = (node.getAttribute('class') || '').split(' ').filter((c) => c && c !== cls);
    if (on) list.push(cls);
    node.setAttribute('class', list.join(' '));
  };
  const el = (name, attrs, parent) => {
    const n = document.createElementNS(NS, name);
    Object.keys(attrs || {}).forEach((k) => n.setAttribute(k, attrs[k]));
    if (parent) parent.appendChild(n);
    return n;
  };

  // ---------- Build ----------
  const defs = el('defs', {}, svg);
  const clip = el('clipPath', { id: 'netclip', clipPathUnits: 'objectBoundingBox' }, defs);
  el('circle', { cx: '0.5', cy: '0.5', r: '0.5' }, clip);
  const viewport = el('g', { class: 'net-viewport' }, svg);
  const linkLayer = el('g', { class: 'net-links' }, viewport);
  const nodeLayer = el('g', { class: 'net-nodes' }, viewport);

  api('graph').then((data) => {
    nodes = data.nodes;
    links = data.links.filter((l) => l.source !== l.target);
    nodes.forEach((n) => { byId[n.id] = n; n.links = []; });
    links = links.filter((l) => byId[l.source] && byId[l.target]);
    links.forEach((l) => {
      l.s = byId[l.source];
      l.t = byId[l.target];
      l.s.links.push(l);
      l.t.links.push(l);
    });
    placeInitially();
    buildFilters(data.groupTypes);
    draw();
    const focus = svg.dataset.focus;
    if (focus && byId[focus]) {
      select(byId[focus]);
      setFocus(byId[focus]);
    }
    start(1);
    setTimeout(() => { if (!userMoved) fit(); }, 700);
  }).catch((e) => { svg.insertAdjacentHTML('afterend', `<p class="flash error">${esc(e.message)}</p>`); });

  function placeInitially() {
    const groups = nodes.filter((n) => n.kind === 'group');
    const members = nodes.filter((n) => n.kind === 'member');
    nodes.forEach((n) => { n.x = (Math.random() - 0.5) * 60; n.y = (Math.random() - 0.5) * 60; n.vx = 0; n.vy = 0; n.r = RADIUS[n.kind] || 16; });
    members.forEach((n, i) => { const a = (i / members.length) * Math.PI * 2; n.x = Math.cos(a) * 90; n.y = Math.sin(a) * 90; });
    groups.forEach((n, i) => { const a = (i / Math.max(1, groups.length)) * Math.PI * 2; n.x = Math.cos(a) * 280; n.y = Math.sin(a) * 280; });
    // People start next to the first thing they are linked to
    nodes.forEach((n) => {
      if (n.kind === 'contact' || n.kind === 'household') {
        const other = n.links.map((l) => (l.s === n ? l.t : l.s)).find((o) => o.kind !== 'contact');
        if (other) { n.x = other.x + (Math.random() - 0.5) * 120; n.y = other.y + (Math.random() - 0.5) * 120; }
        else { const a = Math.random() * Math.PI * 2; n.x = Math.cos(a) * 420; n.y = Math.sin(a) * 420; }
      }
    });
  }

  // ---------- Visibility (filters and focus) ----------
  function linkVisible(l) {
    if (hidden[l.kind]) return false;
    if (l.kind === 'group' && (hidden[l.s.type] || hidden[l.t.type])) return false;
    return nodeShown(l.s) && nodeShown(l.t);
  }
  function nodeShown(n) {
    if (focusSet && !focusSet[n.id]) return false;
    if (n.kind === 'group') return !hidden[n.type];
    if (n.kind === 'household') return !hidden.household;
    return true;
  }
  function visible(n) {
    if (!nodeShown(n)) return false;
    if (n.kind === 'home' || n.kind === 'member') return true;
    // People and places without any visible connection would just float around: leave them out
    return n.links.some((l) => linkVisible(l));
  }

  function buildFilters(types) {
    const present = {};
    nodes.forEach((n) => { if (n.kind === 'group') present[n.type] = true; });
    let html = '';
    Object.keys(types).forEach((k) => {
      if (!present[k]) return;
      html += `<label class="pick sm" style="--c:${types[k].color}"><input type="checkbox" checked data-f="${k}"><span>${types[k].emoji} ${esc(types[k].label)}</span></label>`;
    });
    html += '<label class="pick sm" style="--c:#A0522D"><input type="checkbox" checked data-f="household"><span>🏠 Huishoudens</span></label>';
    html += '<label class="pick sm" style="--c:#8E6CDF"><input type="checkbox" checked data-f="friend"><span>👫 Vriend van</span></label>';
    filterBox.innerHTML = html;
    filterBox.addEventListener('change', (e) => {
      const f = e.target.dataset.f;
      if (!f) return;
      hidden[f] = !e.target.checked;
      refreshVisibility();
      start(0.5);
    });
  }

  // ---------- Drawing ----------
  function draw() {
    links.forEach((l) => {
      const c = l.kind === 'group' ? (l.s.kind === 'group' ? l.s.color : l.t.color) : l.kind === 'household' ? '#A0522D' : l.kind === 'friend' ? '#8E6CDF' : '#6C5CE7';
      l.el = el('line', { class: 'net-link ' + l.kind, stroke: c }, linkLayer);
      if (l.role) { const t = el('title', {}, l.el); t.textContent = l.role; }
    });
    nodes.forEach((n) => {
      const g = el('g', { class: 'net-node ' + n.kind + (n.child ? ' child' : '') }, nodeLayer);
      el('circle', { r: n.r, fill: n.kind === 'group' || n.kind === 'household' || n.kind === 'home' ? '#fff' : n.color, stroke: n.color, 'stroke-width': n.kind === 'member' || n.kind === 'home' ? 4 : 3 }, g);
      if (n.photo) {
        const img = el('image', { x: -n.r, y: -n.r, width: n.r * 2, height: n.r * 2, 'clip-path': 'url(#netclip)', preserveAspectRatio: 'xMidYMid slice' }, g);
        img.setAttributeNS(XLINK, 'xlink:href', 'foto.php?f=' + encodeURIComponent(n.photo) + '&s=t');
      } else {
        const t = el('text', { class: 'net-emoji', 'text-anchor': 'middle', dy: '0.35em', 'font-size': Math.round(n.r * (n.emoji ? 1.05 : 0.9)) }, g);
        t.textContent = n.emoji || (n.label || '?').charAt(0).toUpperCase();
        if (!n.emoji) t.setAttribute('fill', '#fff');
      }
      const label = el('text', { class: 'net-label', 'text-anchor': 'middle', y: n.r + 13 }, g);
      label.textContent = n.label;
      const title = el('title', {}, g);
      title.textContent = (n.full || n.label) + (n.sub ? ' · ' + n.sub : '');
      g.addEventListener('pointerdown', (e) => nodeDown(e, n));
      g.addEventListener('dblclick', () => { if (n.url) location.href = n.url; });
      n.el = g;
    });
    refreshVisibility();
    applyView();
  }

  function refreshVisibility() {
    nodes.forEach((n) => { n.visible = visible(n); n.el.style.display = n.visible ? '' : 'none'; });
    links.forEach((l) => { l.visible = linkVisible(l) && l.s.visible && l.t.visible; l.el.style.display = l.visible ? '' : 'none'; });
    highlight();
  }

  function render() {
    links.forEach((l) => {
      if (!l.visible) return;
      l.el.setAttribute('x1', l.s.x.toFixed(1)); l.el.setAttribute('y1', l.s.y.toFixed(1));
      l.el.setAttribute('x2', l.t.x.toFixed(1)); l.el.setAttribute('y2', l.t.y.toFixed(1));
    });
    nodes.forEach((n) => { if (n.visible) n.el.setAttribute('transform', `translate(${n.x.toFixed(1)},${n.y.toFixed(1)})`); });
  }

  // ---------- Simulation ----------
  function start(a) {
    alpha = Math.max(alpha, a);
    if (running) return;
    running = true;
    requestAnimationFrame(loop);
  }
  function loop() {
    for (let i = 0; i < 2; i++) tick();
    render();
    if (alpha > 0.02) requestAnimationFrame(loop); else { running = false; if (!userMoved) fit(); }
  }
  function tick() {
    const vis = nodes.filter((n) => n.visible);
    const k = alpha;
    // Repulsion + collision
    for (let i = 0; i < vis.length; i++) {
      const a = vis[i];
      for (let j = i + 1; j < vis.length; j++) {
        const b = vis[j];
        let dx = b.x - a.x;
        let dy = b.y - a.y;
        let d2 = dx * dx + dy * dy;
        if (d2 < 0.01) { dx = Math.random() - 0.5; dy = Math.random() - 0.5; d2 = 0.25; }
        if (d2 > 360000) continue;
        const d = Math.sqrt(d2);
        let f = (2600 / d2) * k;
        const min = a.r + b.r + 14;
        if (d < min) f += ((min - d) / d) * 0.5;
        const fx = dx * f;
        const fy = dy * f;
        a.vx -= fx; a.vy -= fy;
        b.vx += fx; b.vy += fy;
      }
    }
    // Springs
    links.forEach((l) => {
      if (!l.visible) return;
      const dx = l.t.x - l.s.x;
      const dy = l.t.y - l.s.y;
      const d = Math.sqrt(dx * dx + dy * dy) || 1;
      const len = LENGTH[l.kind] || 100;
      const f = ((d - len) / d) * 0.06 * (l.kind === 'friend' ? 0.4 : 1) * (k + 0.3);
      l.s.vx += dx * f; l.s.vy += dy * f;
      l.t.vx -= dx * f; l.t.vy -= dy * f;
    });
    vis.forEach((n) => {
      n.vx -= n.x * 0.003 * k;
      n.vy -= n.y * 0.003 * k;
      if (n.fixed) { n.vx = 0; n.vy = 0; return; }
      n.vx *= 0.55; n.vy *= 0.55;
      n.x += Math.max(-40, Math.min(40, n.vx));
      n.y += Math.max(-40, Math.min(40, n.vy));
    });
    const home = byId.home;
    if (home && !home.fixed) { home.x *= 0.9; home.y *= 0.9; }
    alpha *= 0.99;
  }

  // ---------- Pan, zoom, drag ----------
  function applyView() { viewport.setAttribute('transform', `translate(${view.x},${view.y}) scale(${view.k})`); }
  function toWorld(cx, cy) {
    const r = svg.getBoundingClientRect();
    return { x: (cx - r.left - view.x) / view.k, y: (cy - r.top - view.y) / view.k };
  }
  function fit() {
    const vis = nodes.filter((n) => n.visible);
    if (!vis.length) return;
    let x0 = Infinity; let y0 = Infinity; let x1 = -Infinity; let y1 = -Infinity;
    vis.forEach((n) => { x0 = Math.min(x0, n.x - n.r); y0 = Math.min(y0, n.y - n.r); x1 = Math.max(x1, n.x + n.r); y1 = Math.max(y1, n.y + n.r + 18); });
    const r = svg.getBoundingClientRect();
    const k = Math.min(1.8, Math.max(0.25, Math.min((r.width - 40) / (x1 - x0 || 1), (r.height - 40) / (y1 - y0 || 1))));
    view.k = k;
    view.x = r.width / 2 - ((x0 + x1) / 2) * k;
    view.y = r.height / 2 - ((y0 + y1) / 2) * k;
    applyView();
  }
  document.getElementById('net-fit').addEventListener('click', () => { userMoved = false; setFocus(null); fit(); });

  svg.addEventListener('wheel', (e) => {
    e.preventDefault();
    const r = svg.getBoundingClientRect();
    const px = e.clientX - r.left;
    const py = e.clientY - r.top;
    userMoved = true;
    const k = Math.max(0.15, Math.min(4, view.k * (e.deltaY < 0 ? 1.12 : 1 / 1.12)));
    view.x = px - ((px - view.x) / view.k) * k;
    view.y = py - ((py - view.y) / view.k) * k;
    view.k = k;
    applyView();
  }, { passive: false });

  let drag = null;
  function nodeDown(e, n) {
    e.stopPropagation();
    drag = { node: n, x: e.clientX, y: e.clientY, moved: false, id: e.pointerId };
    svg.setPointerCapture(e.pointerId);
  }
  svg.addEventListener('pointerdown', (e) => {
    if (drag) return;
    drag = { pan: true, x: e.clientX, y: e.clientY, vx: view.x, vy: view.y, moved: false, id: e.pointerId };
    svg.setPointerCapture(e.pointerId);
  });
  svg.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const dist = Math.hypot(e.clientX - drag.x, e.clientY - drag.y);
    if (dist > 4) drag.moved = true;
    if (!drag.moved) return;
    if (drag.pan) {
      userMoved = true;
      view.x = drag.vx + (e.clientX - drag.x);
      view.y = drag.vy + (e.clientY - drag.y);
      applyView();
    } else {
      const p = toWorld(e.clientX, e.clientY);
      drag.node.x = p.x; drag.node.y = p.y;
      drag.node.fixed = true;
      start(0.3);
      render();
    }
  });
  const up = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const d = drag;
    drag = null;
    if (d.node) {
      d.node.fixed = false;
      if (!d.moved) select(d.node === selected ? null : d.node);
    } else if (!d.moved) {
      select(null);
    }
  };
  svg.addEventListener('pointerup', up);
  svg.addEventListener('pointercancel', up);

  // ---------- Selection, panel, focus ----------
  function neighbours(n) {
    const out = {};
    out[n.id] = true;
    n.links.forEach((l) => { if (linkVisible(l)) { out[l.s.id] = true; out[l.t.id] = true; } });
    return out;
  }
  function highlight() {
    const near = selected ? neighbours(selected) : null;
    nodes.forEach((n) => {
      toggleClass(n.el, 'dim', !!near && !near[n.id]);
      toggleClass(n.el, 'selected', n === selected);
    });
    links.forEach((l) => toggleClass(l.el, 'dim', !!near && !(l.s === selected || l.t === selected)));
  }
  function select(n) {
    selected = n;
    highlight();
    if (!n) { panel.hidden = true; return; }
    const groups = [];
    const people = [];
    n.links.forEach((l) => {
      if (!linkVisible(l)) return;
      const o = l.s === n ? l.t : l.s;
      const item = { o, role: l.role, kind: l.kind };
      (o.kind === 'group' || o.kind === 'household' || o.kind === 'home' ? groups : people).push(item);
    });
    const row = (it) => `<li><a href="#" data-node="${esc(it.o.id)}">${it.o.emoji && !it.o.photo ? esc(it.o.emoji) + ' ' : ''}${esc(it.o.full || it.o.label)}</a>`
      + `${it.role ? ' <span class="muted">· ' + esc(it.role) + '</span>' : ''}${it.kind === 'friend' ? ' <span class="muted">· vriend van</span>' : ''}</li>`;
    panel.innerHTML = `<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
        <div><h3>${n.emoji && n.kind !== 'member' ? esc(n.emoji) + ' ' : ''}${esc(n.full || n.label)}</h3>${n.sub ? `<p class="muted small" style="margin:2px 0 0">${esc(n.sub)}</p>` : ''}</div>
        <button class="x" data-close aria-label="Sluiten">×</button></div>
      ${groups.length ? `<h4>Hoort bij</h4><ul>${groups.map(row).join('')}</ul>` : ''}
      ${people.length ? `<h4>${n.kind === 'group' || n.kind === 'household' ? 'Mensen' : 'Verbonden met'} (${people.length})</h4><ul>${people.map(row).join('')}</ul>` : ''}
      <div class="pop-actions">
        ${n.url ? `<a class="btn small" href="${esc(n.url)}">Openen</a>` : ''}
        <button class="btn small secondary" data-focus>${focusSet ? 'Alles tonen' : '🔎 Alleen dit netwerk'}</button>
      </div>`;
    panel.hidden = false;
  }
  panel.addEventListener('click', (e) => {
    const a = e.target.closest('[data-node]');
    if (a) { e.preventDefault(); const n = byId[a.dataset.node]; if (n) { select(n); center(n); } return; }
    if (e.target.closest('[data-close]')) select(null);
    if (e.target.closest('[data-focus]')) { setFocus(focusSet ? null : selected); select(selected); }
  });
  /** Show only someone plus everything within two steps (or everything again with null). */
  function setFocus(n) {
    if (!n) { focusSet = null; refreshVisibility(); start(0.4); return; }
    const set = {};
    set[n.id] = true;
    let frontier = [n];
    for (let depth = 0; depth < 2; depth++) {
      const next = [];
      frontier.forEach((f) => f.links.forEach((l) => {
        const o = l.s === f ? l.t : l.s;
        if (!set[o.id] && o.kind !== 'home') { set[o.id] = true; next.push(o); }
      }));
      frontier = next.filter((o) => o.kind !== 'member' || o === n); // don't fan out through the whole family
    }
    focusSet = set;
    refreshVisibility();
    start(0.6);
    userMoved = false;
    setTimeout(fit, 900);
  }
  function center(n) {
    const r = svg.getBoundingClientRect();
    view.x = r.width / 2 - n.x * view.k;
    view.y = r.height / 2 - n.y * view.k;
    applyView();
  }

  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    if (q.length < 2) return;
    const n = nodes.find((x) => x.visible && (x.full || x.label || '').toLowerCase().indexOf(q) > -1);
    if (n) { select(n); center(n); }
  });
})();
