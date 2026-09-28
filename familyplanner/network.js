/* Familie Planner: relations web (netwerk.php).
   Nodes: our family (around "Ons gezin"), groups (class, team, work, family…), households and people from
   the address book. Links: member/contact → group (with role), contact → household, contact → "vriend van" member.
   Small force-directed layout drawn in SVG: drag nodes, drag the background to pan, scroll to zoom,
   click to see someone's connections, double-click to open their page. */
(function () {
  'use strict';
  const svg = document.getElementById('net');
  if (!svg || !window.FP) return;
  const { api, esc, toast } = window.FP;
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
  // People without any connection yet gather in their own corner, ready to be dragged onto someone
  const looseLabel = el('text', { class: 'net-loose-label', 'text-anchor': 'middle' }, viewport);
  looseLabel.textContent = 'Nog niet verbonden';
  const loosePoint = { x: 0, y: 0 };

  /** Load the web. Later reloads (after connecting people) keep everyone where they were. */
  function loadGraph(first) {
    return api('graph').then((data) => {
      const old = byId;
      byId = {};
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
      if (first) {
        placeInitially();
        buildFilters(data.groupTypes);
      } else {
        nodes.forEach((n) => {
          const o = old[n.id];
          n.r = RADIUS[n.kind] || 16;
          n.vx = 0; n.vy = 0;
          n.x = o ? o.x : (Math.random() - 0.5) * 300;
          n.y = o ? o.y : (Math.random() - 0.5) * 300;
        });
      }
      draw();
      if (selected) select(byId[selected.id] || null);
    });
  }

  loadGraph(true).then(() => {
    const focus = svg.dataset.focus;
    if (focus && byId[focus]) {
      select(byId[focus]);
      setFocus(byId[focus]);
    }
    // Back after changing a photo: select that person again
    const again = byId[svg.dataset.select];
    if (again) { select(again); setTimeout(() => center(again), 750); }
    // Just added (quick add): put them in the middle, selected, ready to be dragged onto a group
    const fresh = byId[svg.dataset.new];
    if (fresh) {
      fresh.x = 60; fresh.y = -60;
      fresh.fixed = true; // stays put (others make room) until it is dragged
      select(fresh);
    }
    start(1);
    setTimeout(() => {
      if (fresh) { view.k = 1; center(fresh); } else if (!userMoved && !again) fit();
    }, 700);
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
        else if (n.kind === 'contact' && !n.links.length) { n.x = 520 + Math.random() * 80; n.y = (Math.random() - 0.5) * 300; } // loose corner
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
    if (n.kind === 'contact' && !n.links.length) return true; // not connected yet: shown in the "Nog niet verbonden" corner
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
    while (linkLayer.firstChild) linkLayer.removeChild(linkLayer.firstChild);
    while (nodeLayer.firstChild) nodeLayer.removeChild(nodeLayer.firstChild);
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
    nodes.forEach((n) => {
      n.visible = visible(n);
      n.loose = n.kind === 'contact' && !n.links.length;
      n.el.style.display = n.visible ? '' : 'none';
      toggleClass(n.el, 'loose', n.loose);
    });
    // A tidy grid (alphabetical, 3 wide) in the loose corner
    nodes.filter((n) => n.visible && n.loose).sort((a, b) => (a.label || '').localeCompare(b.label || ''))
      .forEach((n, i, all) => { n.slot = { col: i % 3, row: Math.floor(i / 3), rows: Math.ceil(all.length / 3) }; });
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
    const loose = nodes.filter((n) => n.visible && n.loose);
    looseLabel.style.display = loose.length ? '' : 'none';
    if (loose.length) {
      let top = Infinity; let x = 0;
      loose.forEach((n) => { top = Math.min(top, n.y - n.r); x += n.x; });
      looseLabel.setAttribute('x', (x / loose.length).toFixed(1));
      looseLabel.setAttribute('y', (top - 14).toFixed(1));
    }
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
        if (a.dragging || b.dragging) continue; // don't push away what you're dragging onto
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
    // The corner for loose people: right of everything that is connected
    let right = 0;
    vis.forEach((n) => { if (!n.loose && n.x > right) right = n.x; });
    loosePoint.x = right + 220;
    vis.forEach((n) => {
      if (n.loose && n.slot) {
        const tx = loosePoint.x + n.slot.col * 70;
        const ty = loosePoint.y + (n.slot.row - (n.slot.rows - 1) / 2) * 70;
        n.vx = (tx - n.x) * 0.3;
        n.vy = (ty - n.y) * 0.3;
      } else {
        n.vx -= n.x * 0.003 * k;
        n.vy -= n.y * 0.003 * k;
      }
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
    // Zoom by how far the wheel / trackpad pinch moved (a pinch sends many small ctrl+wheel events)
    const dy = e.deltaY * (e.deltaMode === 1 ? 16 : 1);
    const k = Math.max(0.15, Math.min(4, view.k * Math.exp(-dy * (e.ctrlKey ? 0.004 : 0.0012))));
    view.x = px - ((px - view.x) / view.k) * k;
    view.y = py - ((py - view.y) / view.k) * k;
    view.k = k;
    applyView();
  }, { passive: false });

  // ---------- Connecting by dragging one node onto another ----------
  const PAIRS = { 'member|group': 1, 'contact|group': 1, 'contact|household': 1, 'contact|member': 1 };
  const canConnect = (a, b) => !!(PAIRS[a.kind + '|' + b.kind] || PAIRS[b.kind + '|' + a.kind]);
  const connected = (a, b) => a.links.some((l) => l.s === b || l.t === b);
  function dropTarget(n) {
    let best = null;
    let bestD = Infinity;
    nodes.forEach((o) => {
      if (o === n || !o.visible || !canConnect(n, o) || connected(n, o)) return;
      const d = Math.hypot(o.x - n.x, o.y - n.y);
      // Hit area: the target itself, but never smaller than ~28px on screen (small when zoomed out)
      if (d < Math.max(o.r + n.r * 0.7, 28 / view.k) && d < bestD) { best = o; bestD = d; }
    });
    return best;
  }
  function describe(a, b, undo) {
    const person = a.kind === 'group' || a.kind === 'household' || (a.kind === 'member' && b.kind === 'contact') ? b : a;
    const other = person === a ? b : a;
    const name = person.full || person.label;
    if (other.kind === 'group') return undo ? `${name} uit ${other.label} gehaald` : `${name} toegevoegd aan ${other.label}`;
    if (other.kind === 'household') return undo ? `${name} woont niet meer bij ${other.label}` : `${name} woont nu bij ${other.label}`;
    return undo ? `${name} is geen vriend meer van ${other.label}` : `${name} is nu vriend van ${other.label}`;
  }
  function connect(a, b) {
    api('graph.link', { a: a.id, b: b.id }).then(() => {
      toast('✓ ' + describe(a, b, false), 'Ongedaan maken', () => {
        api('graph.unlink', { a: a.id, b: b.id }).then(() => loadGraph(false)).then(() => start(0.3));
      });
      return loadGraph(false);
    }).then(() => start(0.5)).catch((e) => toast(e.message));
  }
  function disconnect(a, b) {
    api('graph.unlink', { a: a.id, b: b.id }).then(() => {
      toast(describe(a, b, true), 'Ongedaan maken', () => {
        api('graph.link', { a: a.id, b: b.id }).then(() => loadGraph(false)).then(() => start(0.3));
      });
      return loadGraph(false);
    }).then(() => start(0.4)).catch((e) => toast(e.message));
  }

  let drag = null;
  function nodeDown(e, n) {
    e.stopPropagation();
    drag = { node: n, x: e.clientX, y: e.clientY, moved: false, id: e.pointerId };
    svg.setPointerCapture(e.pointerId);
  }
  // Two-finger pinch on touch screens: zoom exactly as far as the fingers move
  const touches = new Map();
  let pinch = null;
  svg.addEventListener('pointerdown', (e) => {
    if (e.pointerType !== 'mouse') touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (touches.size === 2) {
      const [a, b] = Array.from(touches.values());
      pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y) || 1, k: view.k };
      drag = null;
      userMoved = true;
      return;
    }
    if (drag) return;
    drag = { pan: true, x: e.clientX, y: e.clientY, vx: view.x, vy: view.y, moved: false, id: e.pointerId };
    svg.setPointerCapture(e.pointerId);
  });
  svg.addEventListener('pointermove', (e) => {
    if (touches.has(e.pointerId)) touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pinch && touches.size === 2) {
      const [a, b] = Array.from(touches.values());
      const r = svg.getBoundingClientRect();
      const px = (a.x + b.x) / 2 - r.left;
      const py = (a.y + b.y) / 2 - r.top;
      const k = Math.max(0.15, Math.min(4, pinch.k * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.dist)));
      view.x = px - ((px - view.x) / view.k) * k;
      view.y = py - ((py - view.y) / view.k) * k;
      view.k = k;
      applyView();
      return;
    }
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
      drag.node.dragging = true;
      const target = dropTarget(drag.node);
      if (target !== drag.target) {
        if (drag.target) toggleClass(drag.target.el, 'drop-target', false);
        drag.target = target;
        if (target) toggleClass(target.el, 'drop-target', true);
      }
      render();
    }
  });
  const up = (e) => {
    touches.delete(e.pointerId);
    if (touches.size < 2) pinch = null;
    if (!drag || e.pointerId !== drag.id) return;
    const d = drag;
    drag = null;
    if (d.node) {
      d.node.fixed = false;
      d.node.dragging = false;
      if (d.target) {
        toggleClass(d.target.el, 'drop-target', false);
        // Step back from the target so they don't end up on top of each other
        const dx = d.node.x - d.target.x || 1;
        const dy = d.node.y - d.target.y || 1;
        const len = Math.hypot(dx, dy);
        d.node.x = d.target.x + (dx / len) * (d.target.r + d.node.r + 30);
        d.node.y = d.target.y + (dy / len) * (d.target.r + d.node.r + 30);
        connect(d.node, d.target);
        return;
      }
      if (d.moved) start(0.3); // let the web settle around the new spot
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
  // Photo of the selected person: the hidden field in netwerk.php (photo.js opens its crop dialog, Ctrl+V works too)
  const photoForm = document.getElementById('net-photo-form');
  const photoInput = photoForm && photoForm.querySelector('input[type=file]');
  function select(n) {
    selected = n;
    highlight();
    const editable = !!n && (n.kind === 'contact' || n.kind === 'member');
    if (photoInput) {
      photoInput.disabled = !editable; // Ctrl+V only goes here while someone is selected
      photoInput.dataset.photoLabel = editable ? 'Foto van ' + (n.full || n.label) : 'Foto';
      photoForm.elements.node.value = editable ? n.id : '';
    }
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
      + `${it.role ? ' <span class="muted">· ' + esc(it.role) + '</span>' : ''}${it.kind === 'friend' ? ' <span class="muted">· vriend van</span>' : ''}`
      + `${canConnect(n, it.o) ? ` <button class="link muted small" data-unlink="${esc(it.o.id)}" title="Verbinding weghalen">✕</button>` : ''}</li>`;
    const photo = editable && photoInput ? `<div class="net-photo">
        ${n.photo ? `<img src="foto.php?f=${encodeURIComponent(n.photo)}" alt="">` : `<span class="net-photo-empty" style="background:${esc(n.color || '#8E6CDF')}">${esc(n.emoji && n.kind === 'member' ? n.emoji : (n.label || '?').charAt(0))}</span>`}
        <button type="button" class="btn small" data-photo>✏️ ${n.photo ? 'Foto vervangen' : 'Foto toevoegen'}</button>
        <span class="muted small">of plak met Ctrl+V</span></div>` : '';
    panel.innerHTML = `${photo}<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
        <div><h3>${n.emoji && n.kind !== 'member' ? esc(n.emoji) + ' ' : ''}${esc(n.full || n.label)}</h3>${n.sub ? `<p class="muted small" style="margin:2px 0 0">${esc(n.sub)}</p>` : ''}</div>
        <button class="x" data-close aria-label="Sluiten">×</button></div>
      ${groups.length ? `<h4>Hoort bij</h4><ul>${groups.map(row).join('')}</ul>` : ''}
      ${people.length ? `<h4>${n.kind === 'group' || n.kind === 'household' ? 'Mensen' : 'Verbonden met'} (${people.length})</h4><ul>${people.map(row).join('')}</ul>` : ''}
      ${n.kind === 'contact' || n.kind === 'member' ? '<p class="muted small" style="margin:10px 0 0">Tip: sleep deze persoon op een groep, huishouden of gezinslid om ze te verbinden.</p>' : ''}
      <div class="pop-actions">
        ${n.url ? `<a class="btn small" href="${esc(n.url)}">Openen</a>` : ''}
        <button class="btn small secondary" data-focus>${focusSet ? 'Alles tonen' : '🔎 Alleen dit netwerk'}</button>
      </div>`;
    panel.hidden = false;
  }
  panel.addEventListener('click', (e) => {
    const a = e.target.closest('[data-node]');
    if (a) { e.preventDefault(); const n = byId[a.dataset.node]; if (n) { select(n); center(n); } return; }
    if (e.target.closest('[data-photo]') || e.target.closest('.net-photo img, .net-photo-empty')) { photoInput.click(); return; }
    const un = e.target.closest('[data-unlink]');
    if (un) { const o = byId[un.dataset.unlink]; if (o && selected) disconnect(selected, o); return; }
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
