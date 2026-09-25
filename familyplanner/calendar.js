/* Familie Planner: the calendar (agenda.php).
   Views: day, 3 days, week, month, year, list. Google Calendar-style interactions:
   - drag on an empty spot to create (click/tap = 1 hour), month: drag across days = multi-day
   - drag an event to move it (other time or day), drag its top/bottom edge to change start/end
   - all-day and multi-day bars: drag to move, drag the right edge to lengthen/shorten
   - click an event for details, tick it off, edit, duplicate or delete
   On touch screens: hold an event (or empty spot) briefly, then drag. */
(function () {
  'use strict';
  const { api, toast, openEditor, deleteEvent, askScope, avatarHtml, esc, pad, ymd, hm, parse, local, DAYS, MONTHS, DATA, memberById } = window.FP;
  window.FP_CAL = true;
  const root = document.getElementById('calendar');
  if (!root) return;

  const HOUR = 48; // px per hour in the time grid
  const SNAP = 15; // minutes
  const DAY_MS = 86400000;
  const LS = (k, v) => { try { if (v === undefined) return JSON.parse(localStorage.getItem('fp.cal.' + k)); localStorage.setItem('fp.cal.' + k, JSON.stringify(v)); } catch (e) { return null; } };
  const params = new URLSearchParams(location.search);
  const isSmall = () => window.innerWidth < 700;

  const state = {
    view: params.get('view') || LS('view') || (isSmall() ? '3day' : 'week'),
    date: params.get('date') ? parse(params.get('date')) : startOfDay(new Date()),
    members: params.get('member') ? [Number(params.get('member'))] : (LS('members') || []),
    type: params.get('type') || '',
    colorBy: LS('colorBy') || 'type',
    birthdays: LS('birthdays') !== false,
    events: [],
    birthdayItems: [],
    loadedKey: '',
  };

  // ---------- Date helpers ----------
  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function addMinutes(d, n) { return new Date(d.getTime() + n * 60000); }
  function startOfWeek(d) { const x = startOfDay(d); const w = (x.getDay() + 6) % 7; return addDays(x, -w); } // Monday
  function sameDay(a, b) { return ymd(a) === ymd(b); }
  function dayDiff(a, b) { return Math.round((startOfDay(b) - startOfDay(a)) / DAY_MS); }
  function minutesOfDay(d) { return d.getHours() * 60 + d.getMinutes(); }
  function snap(min) { return Math.round(min / SNAP) * SNAP; }
  function isToday(d) { return sameDay(d, new Date()); }
  function weekNumber(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dayNum = t.getUTCDay() || 7;
    t.setUTCDate(t.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
    return Math.ceil(((t - yearStart) / DAY_MS + 1) / 7);
  }
  const fmtTime = (d) => d.getHours() + ':' + pad(d.getMinutes());
  const dayLong = (d) => DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()];

  // ---------- Range for the current view ----------
  function viewDays() {
    const d = state.date;
    switch (state.view) {
      case 'day': return [startOfDay(d)];
      case '3day': return [0, 1, 2].map((i) => addDays(startOfDay(d), i));
      case 'week': { const s = startOfWeek(d); return [0, 1, 2, 3, 4, 5, 6].map((i) => addDays(s, i)); }
      default: return [];
    }
  }
  function range() {
    const d = state.date;
    if (state.view === 'month') {
      const first = new Date(d.getFullYear(), d.getMonth(), 1);
      const from = startOfWeek(first);
      const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
      const to = addDays(startOfWeek(last), 7);
      return { from, to };
    }
    if (state.view === 'year') return { from: new Date(d.getFullYear(), 0, 1), to: new Date(d.getFullYear() + 1, 0, 1) };
    if (state.view === 'list') return { from: startOfDay(d), to: addDays(startOfDay(d), 42) };
    const days = viewDays();
    return { from: days[0], to: addDays(days[days.length - 1], 1) };
  }
  function title() {
    const d = state.date;
    if (state.view === 'month') return MONTHS[d.getMonth()] + ' ' + d.getFullYear();
    if (state.view === 'year') return String(d.getFullYear());
    if (state.view === 'list') return 'Vanaf ' + dayLong(d);
    const days = viewDays();
    const a = days[0];
    const b = days[days.length - 1];
    if (days.length === 1) return dayLong(a) + (a.getFullYear() !== new Date().getFullYear() ? ' ' + a.getFullYear() : '');
    const wk = state.view === 'week' ? ` <span class="muted small">week ${weekNumber(a)}</span>` : '';
    if (a.getMonth() === b.getMonth()) return `${a.getDate()} – ${b.getDate()} ${MONTHS[a.getMonth()]} ${b.getFullYear()}${wk}`;
    return `${a.getDate()} ${MONTHS[a.getMonth()].slice(0, 3)} – ${b.getDate()} ${MONTHS[b.getMonth()].slice(0, 3)} ${b.getFullYear()}${wk}`;
  }
  function step(dir) {
    const d = state.date;
    switch (state.view) {
      case 'day': state.date = addDays(d, dir); break;
      case '3day': state.date = addDays(d, 3 * dir); break;
      case 'week': state.date = addDays(d, 7 * dir); break;
      case 'month': state.date = new Date(d.getFullYear(), d.getMonth() + dir, 1); break;
      case 'year': state.date = new Date(d.getFullYear() + dir, 0, 1); break;
      case 'list': state.date = addDays(d, 28 * dir); break;
    }
    refresh();
  }

  // ---------- Toolbar & filters ----------
  const VIEWS = [['day', 'Dag'], ['3day', '3 dagen'], ['week', 'Week'], ['month', 'Maand'], ['year', 'Jaar'], ['list', 'Lijst']];
  root.innerHTML = `
    <div class="cal-toolbar">
      <button class="btn secondary small" data-act="today">Vandaag</button>
      <span class="cal-nav"><button class="btn secondary icon small" data-act="prev" aria-label="Vorige">‹</button><button class="btn secondary icon small" data-act="next" aria-label="Volgende">›</button></span>
      <h1 class="cal-title"></h1>
      <span class="spacer"></span>
      <div class="tabs cal-views">${VIEWS.map(([k, l]) => `<button data-view="${k}">${l}</button>`).join('')}</div>
      <button class="btn" data-act="new">＋ Nieuw</button>
    </div>
    <div class="cal-filters">
      ${DATA.members.map((m) => `<label class="pick" style="--c:${m.color}"><input type="checkbox" value="${m.id}" data-member><span>${esc(m.emoji)} ${esc(m.name)}</span></label>`).join('')}
      <select class="cal-type" aria-label="Soort" style="width:auto;min-height:34px;padding:5px 10px">
        <option value="">Alle soorten</option>
        ${Object.entries(DATA.types).map(([k, t]) => `<option value="${k}">${t.emoji} ${esc(t.label)}</option>`).join('')}
      </select>
      <label class="check small" style="margin:0 6px"><input type="checkbox" data-bdays> 🎂 Verjaardagen</label>
      <div class="tabs" style="margin-left:auto" title="Kleur van afspraken"><button data-color="type">Kleur per soort</button><button data-color="member">per persoon</button></div>
    </div>
    <div class="cal"></div>`;
  const cal = root.querySelector('.cal');

  function syncToolbar() {
    root.querySelector('.cal-title').innerHTML = title();
    root.querySelectorAll('[data-view]').forEach((b) => b.classList.toggle('on', b.dataset.view === state.view));
    root.querySelectorAll('[data-color]').forEach((b) => b.classList.toggle('on', b.dataset.color === state.colorBy));
    root.querySelectorAll('[data-member]').forEach((c) => { c.checked = state.members.length === 0 || state.members.includes(Number(c.value)); });
    root.querySelector('[data-bdays]').checked = state.birthdays;
    root.querySelector('.cal-type').value = state.type;
    const url = new URL(location.href);
    url.searchParams.set('view', state.view);
    url.searchParams.set('date', ymd(state.date));
    url.searchParams.delete('member');
    history.replaceState(null, '', url);
  }

  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act],[data-view],[data-color]');
    if (!b) return;
    if (b.dataset.view) { state.view = b.dataset.view; LS('view', state.view); refresh(); }
    if (b.dataset.color) { state.colorBy = b.dataset.color; LS('colorBy', state.colorBy); render(); syncToolbar(); }
    if (b.dataset.act === 'today') { state.date = startOfDay(new Date()); refresh(); }
    if (b.dataset.act === 'prev') step(-1);
    if (b.dataset.act === 'next') step(1);
    if (b.dataset.act === 'new') createAt(defaultStart(), null, false);
  });
  root.querySelectorAll('[data-member]').forEach((c) => c.addEventListener('change', () => {
    const all = Array.from(root.querySelectorAll('[data-member]'));
    let on = all.filter((x) => x.checked).map((x) => Number(x.value));
    if (on.length === all.length || on.length === 0) on = [];
    state.members = on;
    LS('members', on);
    refresh(true);
  }));
  root.querySelector('.cal-type').addEventListener('change', (e) => { state.type = e.target.value; refresh(true); });
  root.querySelector('[data-bdays]').addEventListener('change', (e) => { state.birthdays = e.target.checked; LS('birthdays', state.birthdays); render(); });

  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input,textarea,select,dialog') || e.metaKey || e.ctrlKey || e.altKey) return;
    const map = { d: 'day', '3': '3day', w: 'week', m: 'month', y: 'year', j: 'year', l: 'list' };
    if (map[e.key]) { state.view = map[e.key]; LS('view', state.view); refresh(); }
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'ArrowRight') step(1);
    else if (e.key === 't') { state.date = startOfDay(new Date()); refresh(); }
    else if (e.key === 'n') createAt(defaultStart(), null, false);
    else if (e.key === 'Escape') closePopover();
  });
  document.addEventListener('fp:changed', () => refresh(true));

  function defaultStart() {
    const now = new Date();
    const base = state.view === 'month' || state.view === 'year' || state.view === 'list' ? state.date : (viewDays().find(isToday) || viewDays()[0]);
    const d = startOfDay(base);
    d.setHours(sameDay(d, now) ? Math.min(now.getHours() + 1, 22) : 9);
    return d;
  }

  // ---------- Loading ----------
  let loadSeq = 0;
  async function refresh(force) {
    closePopover();
    syncToolbar();
    const r = range();
    const key = [ymd(r.from), ymd(r.to), state.members.join(','), state.type].join('|');
    if (!force && key === state.loadedKey) { render(); return; }
    const seq = ++loadSeq;
    if (!state.events.length) cal.innerHTML = '<div class="empty">Agenda laden…</div>';
    try {
      const q = { from: ymd(r.from), to: ymd(r.to) };
      if (state.members.length) q.members = state.members.join(',');
      if (state.type) q.types = state.type;
      const data = await api('events', undefined, q);
      if (seq !== loadSeq) return;
      state.events = data.events.map(prep);
      state.birthdayItems = data.birthdays.map(prep);
      state.loadedKey = key;
      render();
    } catch (e) {
      cal.innerHTML = `<div class="empty"><div class="empty-icon">😕</div><p>${esc(e.message)}</p></div>`;
    }
  }
  function prep(ev) {
    ev.s = parse(ev.start);
    ev.e = parse(ev.end);
    ev.key = ev.birthday ? ev.id : ev.id + '@' + ev.occ;
    return ev;
  }
  function visibleEvents() {
    return state.birthdays ? state.events.concat(state.birthdayItems) : state.events;
  }
  function colorOf(ev) {
    if (state.colorBy === 'member' && !ev.birthday && !ev.customColor) {
      const m = memberById(ev.members[0]);
      if (m) return m.color;
    }
    return ev.color;
  }
  const isSpan = (ev) => ev.allDay || !sameDay(ev.s, addMinutes(ev.e, -1)) && (ev.e - ev.s) >= DAY_MS;

  // ---------- Rendering ----------
  function render() {
    closePopover();
    cal.className = 'cal view-' + state.view;
    if (state.view === 'month') renderMonth();
    else if (state.view === 'year') renderYear();
    else if (state.view === 'list') renderList();
    else renderTimeGrid();
  }

  function eventInner(ev, compact) {
    const who = ev.members.map(memberById).filter(Boolean).map((m) => m.emoji).join('');
    const guests = (ev.contacts || []).slice(0, 4).map((c) => avatarHtml(c, 18)).join('');
    const time = ev.allDay ? '' : fmtTime(ev.s) + '–' + fmtTime(ev.e);
    return `<span class="t">${ev.emoji || ''} ${esc(ev.title)}</span>` +
      (compact ? '' : `<span class="m">${time}${who ? ' · ' + who : ''}${ev.location ? ' · ' + esc(ev.location) : ''}</span>`) +
      (!compact && guests ? `<span class="faces-mini">${guests}</span>` : '');
  }

  // --- Week / day / 3-day time grid ---
  let gridDays = [];
  function renderTimeGrid() {
    const days = viewDays();
    gridDays = days;
    const n = days.length;
    const cols = `56px repeat(${n}, minmax(0,1fr))`;
    const evs = visibleEvents();
    const spans = evs.filter(isSpan);
    const timed = evs.filter((ev) => !isSpan(ev));

    // All-day lanes
    const first = days[0];
    const lanes = [];
    const placed = [];
    spans.slice().sort((a, b) => a.s - b.s || (b.e - b.s) - (a.e - a.s)).forEach((ev) => {
      const a = Math.max(0, dayDiff(first, ev.s));
      const b = Math.min(n - 1, dayDiff(first, addMinutes(ev.e, -1)));
      if (b < 0 || a > n - 1) return;
      let lane = 0;
      while (lanes[lane] && lanes[lane].some(([x, y]) => !(b < x || a > y))) lane++;
      (lanes[lane] = lanes[lane] || []).push([a, b]);
      placed.push({ ev, a, b, lane });
    });
    const alldayH = Math.max(1, lanes.length) * 25 + 6;

    let html = `<div class="cal-head" style="grid-template-columns:${cols}"><div class="gutter"></div>` +
      days.map((d) => `<div class="cal-dayhead${isToday(d) ? ' today' : ''}" data-goto="${ymd(d)}"><div class="dn">${DAYS[d.getDay()].slice(0, 2)}</div><div class="dd">${d.getDate()}</div></div>`).join('') +
      `</div><div class="cal-allday" style="grid-template-columns:${cols};height:${alldayH}px;--gutter:56px"><div class="gutter">hele dag</div>` +
      days.map((d) => `<div class="cell" data-date="${ymd(d)}"></div>`).join('') +
      `<div class="cal-allday-events">` +
      placed.map(({ ev, a, b, lane }) => {
        const contL = dayDiff(first, ev.s) < 0;
        const contR = dayDiff(first, addMinutes(ev.e, -1)) > n - 1;
        return `<div class="cal-aev${ev.birthday ? ' bday' : ''}${ev.done ? ' done' : ''}" data-key="${esc(ev.key)}" style="--c:${colorOf(ev)};left:calc(${a / n * 100}% + 2px);width:calc(${(b - a + 1) / n * 100}% - 4px);top:${3 + lane * 25}px">${contL ? '‹ ' : ''}${ev.birthday ? '' : (ev.emoji || '') + ' '}${esc(ev.title)}${!ev.allDay ? ' <span class="muted">' + fmtTime(ev.s) + '</span>' : ''}${contR ? ' ›' : ''}${!ev.birthday && !contR ? '<span class="rz right"></span>' : ''}</div>`;
      }).join('') + `</div></div>`;

    html += `<div class="cal-scroll"><div class="cal-body" style="grid-template-columns:${cols};--hour:${HOUR}px"><div class="cal-times">` +
      Array.from({ length: 24 }, (_, h) => `<div>${h ? `<span>${h}:00</span>` : ''}</div>`).join('') + '</div>' +
      days.map((d) => `<div class="cal-col${isToday(d) ? ' today' : ''}${d.getDay() % 6 === 0 ? ' weekend' : ''}" data-date="${ymd(d)}" style="height:${24 * HOUR}px"></div>`).join('') +
      '</div></div>';
    cal.innerHTML = html;

    // Timed events per day, with side-by-side layout for overlaps
    const colEls = cal.querySelectorAll('.cal-col');
    days.forEach((d, i) => {
      const dayStart = startOfDay(d);
      const dayEnd = addDays(dayStart, 1);
      const segs = timed.filter((ev) => ev.s < dayEnd && ev.e > dayStart).map((ev) => ({
        ev, s: Math.max(0, (ev.s - dayStart) / 60000), e: Math.min(1440, (ev.e - dayStart) / 60000),
        startsHere: ev.s >= dayStart, endsHere: ev.e <= dayEnd,
      })).sort((a, b) => a.s - b.s || b.e - a.e);
      layoutColumns(segs);
      colEls[i].innerHTML = segs.map((sg) => {
        const h = Math.max(18, (sg.e - sg.s) / 60 * HOUR - 2);
        const short = h < 36;
        return `<div class="cal-ev${short ? ' short' : ''}${sg.ev.done ? ' done' : ''}" data-key="${esc(sg.ev.key)}" style="--c:${colorOf(sg.ev)};top:${sg.s / 60 * HOUR + 1}px;height:${h}px;left:calc(${sg.col / sg.cols * 100}% + 1px);width:calc(${sg.span / sg.cols * 100}% - 3px)">` +
          (sg.startsHere ? '<span class="rz top"></span>' : '') + eventInner(sg.ev, short) + (sg.endsHere ? '<span class="rz bottom"></span>' : '') + '</div>';
      }).join('') + (isToday(d) ? `<div class="cal-now" style="top:${minutesOfDay(new Date()) / 60 * HOUR}px"></div>` : '');
    });

    const scroller = cal.querySelector('.cal-scroll');
    const firstHour = days.some(isToday) ? Math.max(0, new Date().getHours() - 2) : 7;
    scroller.scrollTop = (state.scrollTop != null ? state.scrollTop : firstHour * HOUR);
    scroller.addEventListener('scroll', () => { state.scrollTop = scroller.scrollTop; }, { passive: true });
  }

  /** Assign col/cols/span to overlapping segments (Google-style columns). */
  function layoutColumns(segs) {
    let cluster = [];
    let clusterEnd = -1;
    const flush = () => {
      const colsEnd = [];
      cluster.forEach((sg) => {
        let c = colsEnd.findIndex((end) => end <= sg.s);
        if (c === -1) { c = colsEnd.length; colsEnd.push(0); }
        colsEnd[c] = sg.e;
        sg.col = c;
      });
      cluster.forEach((sg) => {
        sg.cols = colsEnd.length;
        // Stretch to the right while the next columns are free
        let span = 1;
        while (sg.col + span < colsEnd.length && !cluster.some((o) => o.col === sg.col + span && o.s < sg.e && o.e > sg.s)) span++;
        sg.span = span;
      });
      cluster = [];
    };
    segs.forEach((sg) => {
      if (cluster.length && sg.s >= clusterEnd) { flush(); clusterEnd = -1; }
      cluster.push(sg);
      clusterEnd = Math.max(clusterEnd, Math.max(sg.e, sg.s + 20));
    });
    if (cluster.length) flush();
  }

  // --- Month ---
  function renderMonth() {
    const { from, to } = range();
    const weeks = Math.round((to - from) / DAY_MS / 7);
    const month = state.date.getMonth();
    const maxLanes = isSmall() ? 3 : 4;
    const laneH = isSmall() ? 19 : 23;
    const evs = visibleEvents();
    let html = '<div class="cal-month">' + ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'].map((d) => `<div class="mh">${d}</div>`).join('');
    for (let w = 0; w < weeks; w++) {
      const ws = addDays(from, w * 7);
      const we = addDays(ws, 7);
      const items = evs.filter((ev) => ev.s < we && ev.e > ws).map((ev) => {
        const span = isSpan(ev) || ev.birthday;
        const a = Math.max(0, dayDiff(ws, ev.s));
        const b = span ? Math.min(6, dayDiff(ws, addMinutes(ev.e, -1))) : a;
        return { ev, a, b, span };
      }).filter((it) => it.b >= 0 && it.a <= 6)
        .sort((x, y) => (y.span - x.span) || (x.a - y.a) || ((y.b - y.a) - (x.b - x.a)) || (x.ev.birthday ? -1 : 0) || (x.ev.s - y.ev.s));
      const lanes = [];
      const hidden = [0, 0, 0, 0, 0, 0, 0];
      let bars = '';
      items.forEach((it) => {
        let lane = 0;
        while (lanes[lane] && lanes[lane].some(([x, y]) => !(it.b < x || it.a > y))) lane++;
        (lanes[lane] = lanes[lane] || []).push([it.a, it.b]);
        if (lane >= maxLanes) { for (let k = it.a; k <= it.b; k++) hidden[k]++; return; }
        const ev = it.ev;
        const cls = ev.birthday ? 'bday' : it.span ? 'span' : 'timed';
        const label = it.span ? esc(ev.title) : isSmall() ? `${ev.emoji || ''} ${esc(ev.title)}` : `<b>${fmtTime(ev.s)}</b> ${ev.emoji || ''} ${esc(ev.title)}`;
        bars += `<div class="cal-mev ${cls}${ev.done ? ' done' : ''}" data-key="${esc(ev.key)}" style="--c:${colorOf(ev)};top:${lane * laneH}px;left:calc(${it.a / 7 * 100}% + 3px);width:calc(${(it.b - it.a + 1) / 7 * 100}% - 6px)">${label}${it.span && !ev.birthday ? '<span class="rz"></span>' : ''}</div>`;
      });
      hidden.forEach((n, k) => {
        if (n) bars += `<div class="cal-more" data-goto="${ymd(addDays(ws, k))}" style="top:${maxLanes * laneH}px;left:calc(${k / 7 * 100}%)">+${n} meer</div>`;
      });
      html += `<div class="cal-week-row" style="min-height:${30 + (maxLanes + 1) * laneH}px">` +
        [0, 1, 2, 3, 4, 5, 6].map((k) => {
          const d = addDays(ws, k);
          return `<div class="cal-mcell${d.getMonth() !== month ? ' other' : ''}${isToday(d) ? ' today' : ''}${k >= 5 ? ' weekend' : ''}" data-date="${ymd(d)}"><span class="mnum" data-goto="${ymd(d)}">${d.getDate() === 1 ? d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3) : d.getDate()}</span></div>`;
        }).join('') + `<div class="cal-week-events">${bars}</div></div>`;
    }
    cal.innerHTML = html + '</div>';
  }

  // --- Year ---
  function renderYear() {
    const y = state.date.getFullYear();
    const byDay = {};
    visibleEvents().forEach((ev) => {
      for (let d = startOfDay(ev.s); d < ev.e && d.getFullYear() <= y; d = addDays(d, 1)) {
        const k = ymd(d);
        (byDay[k] = byDay[k] || []).push(ev);
      }
    });
    let html = '<div class="cal-year"><div class="year-grid">';
    for (let m = 0; m < 12; m++) {
      const first = new Date(y, m, 1);
      const start = startOfWeek(first);
      html += `<div class="year-month"><h3><a href="#" data-month="${m}">${MONTHS[m]}</a></h3><table><tr>${['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'].map((d) => `<th>${d}</th>`).join('')}</tr>`;
      for (let w = 0; w < 6; w++) {
        html += '<tr>';
        for (let k = 0; k < 7; k++) {
          const d = addDays(start, w * 7 + k);
          const list = byDay[ymd(d)] || [];
          const other = d.getMonth() !== m;
          const cls = [other ? 'other' : '', isToday(d) && !other ? 'today' : '', list.length ? 'has' : '',
            list.some((e) => e.birthday) ? 'bday' : '', list.some((e) => e.type === 'HOLIDAY') ? 'holiday' : ''].join(' ');
          const tip = list.map((e) => e.title).join('\n');
          const ec = list.find((e) => !e.birthday);
          html += `<td class="${cls}"><a href="#" data-goto="${ymd(d)}" title="${esc(tip)}" style="${ec ? '--ec:' + colorOf(ec) : ''}">${d.getDate()}</a></td>`;
        }
        html += '</tr>';
      }
      html += '</table></div>';
    }
    cal.innerHTML = html + '</div></div>';
  }

  // --- List ---
  function renderList() {
    const { from, to } = range();
    const evs = visibleEvents();
    let html = '<div class="cal-agenda">';
    let any = false;
    for (let d = new Date(from); d < to; d = addDays(d, 1)) {
      const next = addDays(d, 1);
      const list = evs.filter((ev) => ev.s < next && ev.e > d).sort((a, b) => (b.allDay - a.allDay) || (a.s - b.s));
      if (!list.length) continue;
      any = true;
      html += `<div class="day-group"><h3 class="${isToday(d) ? 'today' : ''}">${isToday(d) ? 'Vandaag · ' : ''}${dayLong(d)}</h3>` + list.map((ev) => {
        const who = ev.members.map(memberById).filter(Boolean).map((m) => avatarHtml(m, 24)).join('');
        const guests = (ev.contacts || []).map((c) => avatarHtml(c, 24)).join('');
        return `<div class="ev${ev.done ? ' done' : ''}" data-key="${esc(ev.key)}" style="--c:${colorOf(ev)};cursor:pointer">
          <div class="ev-time">${ev.allDay || ev.birthday ? 'hele dag' : fmtTime(ev.s) + '<br>' + fmtTime(ev.e)}</div>
          <div class="ev-body"><span class="ev-title">${ev.birthday ? '' : (ev.emoji || '') + ' '}${esc(ev.title)}</span>
          <span class="ev-meta">${esc([ev.typeLabel, ev.location].filter(Boolean).join(' · '))}</span>
          ${who || guests ? `<div class="avatars">${who}${guests}</div>` : ''}</div></div>`;
      }).join('') + '</div>';
    }
    cal.innerHTML = html + (any ? '' : '<div class="empty"><div class="empty-icon">🌤️</div><p>Niets gepland in deze periode.</p></div>') + '</div>';
  }

  // ---------- Popover ----------
  let pop = null;
  function closePopover() { if (pop) { pop.remove(); pop = null; } }
  function findEvent(key) { return visibleEvents().find((ev) => ev.key === key); }

  function showPopover(ev, anchor) {
    closePopover();
    pop = document.createElement('div');
    pop.className = 'popover';
    pop.setAttribute('role', 'dialog');
    if (ev.birthday) {
      pop.innerHTML = `<div class="pop-head">${avatarHtml({ name: ev.name, photo: ev.photo, color: '#E0568A' }, 44)}<h3>${esc(ev.title)}</h3><button class="x" data-pop="close">×</button></div>
        <p class="pop-meta">${dayLong(ev.s)}</p>
        <div class="pop-actions"><a class="btn small" href="${esc(ev.link)}">Bekijk ${esc(ev.name)}</a><a class="btn secondary small" href="verjaardagen.php">🎁 Verjaardagen</a></div>`;
    } else {
      const members = ev.members.map(memberById).filter(Boolean).map((m) => `<span class="chip small" style="--c:${m.color}">${esc(m.emoji)} ${esc(m.name)}</span>`).join(' ');
      const guests = (ev.contacts || []).map((c) => `<span class="person-tag">${avatarHtml(c, 22)} ${esc(c.name)}${c.rsvp ? ' <small class="muted">· ' + esc(DATA.rsvps[c.rsvp]) + '</small>' : ''}</span>`).join(' ');
      const drop = memberById(ev.dropMember);
      const pick = memberById(ev.pickupMember);
      const when = ev.allDay
        ? (sameDay(ev.s, ev.e) ? dayLong(ev.s) + ' · hele dag' : dayLong(ev.s) + ' t/m ' + dayLong(ev.e))
        : dayLong(ev.s) + ' · ' + fmtTime(ev.s) + '–' + fmtTime(ev.e) + (sameDay(ev.s, ev.e) ? '' : ' (' + dayLong(ev.e) + ')');
      pop.innerHTML = `<div class="pop-head"><span class="pop-color" style="background:${colorOf(ev)}"></span><h3>${esc(ev.emoji)} ${esc(ev.title)}</h3><button class="x" data-pop="close" aria-label="Sluiten">×</button></div>
        <p class="pop-meta">${esc(when)}${ev.recurring ? '<br>🔁 ' + esc(DATA.recurrences[ev.recurrence] || '') : ''}</p>
        ${members ? `<div class="pop-row">${members}</div>` : ''}
        ${guests ? `<div class="pop-row">${guests}</div>` : ''}
        ${ev.location ? `<div class="pop-row">📍 <a href="https://maps.google.com/?q=${encodeURIComponent(ev.location)}" target="_blank" rel="noopener">${esc(ev.location)}</a></div>` : ''}
        ${ev.host ? `<div class="pop-row">${esc(DATA.hosts[ev.host])}</div>` : ''}
        ${drop || pick ? `<div class="pop-row">${drop ? '🚗 brengen: <b>' + esc(drop.name) + '</b>' : ''} ${pick ? '🏠 halen: <b>' + esc(pick.name) + '</b>' : ''}</div>` : ''}
        ${ev.cost != null ? `<div class="pop-row">💶 € ${ev.cost.toFixed(2).replace('.', ',')} ${ev.paid ? '<span class="badge ok">betaald</span>' : '<span class="badge warn">nog betalen</span>'}</div>` : ''}
        ${ev.description ? `<div class="pop-row" style="white-space:pre-line">${esc(ev.description)}</div>` : ''}
        <div class="pop-actions">
          <button class="btn small ${ev.done ? 'secondary' : 'ok'}" data-pop="done">${ev.done ? '↺ Niet gedaan' : '✓ Afvinken'}</button>
          <button class="btn small soft" data-pop="edit">✏️ Bewerken</button>
          <a class="btn small secondary" href="event.php?id=${ev.id}&occ=${ev.occ}">📋 Details</a>
          <button class="btn small secondary" data-pop="copy" title="Dupliceren">⧉</button>
          <button class="btn small danger" data-pop="delete" title="Verwijderen">🗑</button>
        </div>`;
    }
    document.body.appendChild(pop);
    const r = anchor.getBoundingClientRect();
    const pw = pop.offsetWidth;
    const ph = pop.offsetHeight;
    const W = window.innerWidth;
    const H = window.innerHeight;
    let left;
    let top;
    if (r.right + 8 + pw <= W - 8) { left = r.right + 8; top = r.top; }
    else if (r.left - 8 - pw >= 8) { left = r.left - 8 - pw; top = r.top; }
    else { // no room beside it (phone): below or above
      left = Math.max(8, Math.min(W - pw - 8, r.left));
      top = r.bottom + 6 + ph <= H - 8 ? r.bottom + 6 : r.top - ph - 6;
    }
    pop.style.left = left + 'px';
    pop.style.top = Math.max(8, Math.min(top, H - ph - 8)) + 'px';

    pop.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-pop]');
      if (!b) return;
      const act = b.dataset.pop;
      if (act === 'close') closePopover();
      if (act === 'edit') { closePopover(); edit(ev); }
      if (act === 'delete') { closePopover(); if (await deleteEvent(ev)) refresh(true); }
      if (act === 'done') {
        closePopover();
        try {
          await api('done', { id: ev.id, occ: ev.occ, done: !ev.done });
          ev.done = !ev.done;
          render();
          toast(ev.done ? 'Afgevinkt ✓' : 'Weer open gezet');
        } catch (x) { toast(x.message); }
      }
      if (act === 'copy') {
        closePopover();
        try {
          const r2 = await api('duplicate', { id: ev.id, occ: ev.occ });
          await refresh(true);
          toast('Kopie gemaakt: sleep hem naar een andere dag');
          const copy = state.events.find((x) => x.id === r2.id);
          if (copy) edit(copy);
        } catch (x) { toast(x.message); }
      }
    });
  }
  document.addEventListener('pointerdown', (e) => {
    if (pop && !pop.contains(e.target) && !e.target.closest('[data-key]')) closePopover();
  });

  async function edit(ev) {
    try {
      const r = await api('event', undefined, { id: ev.id, occ: ev.occ });
      const saved = await openEditor(r.event);
      if (saved) refresh(true);
    } catch (e) { toast(e.message); }
  }

  async function createAt(start, end, allDay, extra) {
    const s = start;
    const e = end || (allDay ? s : addMinutes(s, 60));
    const preset = Object.assign({
      start: allDay ? ymd(s) + 'T00:00' : local(s),
      end: allDay ? ymd(e) + 'T23:59' : local(e),
      allDay,
      members: state.members.length === 1 ? state.members.slice() : [],
      type: state.type || 'OTHER',
    }, extra || {});
    const saved = await openEditor(preset);
    removeSelection();
    if (saved) {
      if (saved.start) {
        const d = parse(saved.start);
        const r = range();
        if (d < r.from || d >= r.to) state.date = startOfDay(d);
      }
      refresh(true);
    }
  }

  // ---------- Drag & drop ----------
  // One gesture object at a time. Mouse: drag starts after 4px. Touch: hold 350 ms first.
  let g = null;
  const LONG_PRESS = 350;

  cal.addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || g) return;
    const goto = e.target.closest('[data-goto]');
    if (goto) return; // handled on click
    const evEl = e.target.closest('[data-key]');
    const target = evEl ? 'event' : (e.target.closest('.cal-col') ? 'col' : e.target.closest('.cal-mcell') ? 'mcell' : e.target.closest('.cal-allday .cell') ? 'acell' : null);
    if (!target) return;
    if (state.view === 'year' || state.view === 'list') return;
    const ev = evEl ? findEvent(evEl.dataset.key) : null;
    const rz = e.target.closest('.rz');
    g = {
      id: e.pointerId, touch: e.pointerType !== 'mouse', x: e.clientX, y: e.clientY, target, el: evEl, ev,
      mode: rz ? (rz.classList.contains('top') ? 'resize-start' : 'resize-end') : (evEl ? 'move' : 'create'),
      active: false, armed: e.pointerType === 'mouse',
    };
    if (ev && ev.birthday) g.mode = 'click';
    g.cells = collectCells();
    g.origin = pointToSlot(e.clientX, e.clientY);
    if (g.touch) {
      g.timer = setTimeout(() => {
        if (!g) return;
        g.armed = true;
        if (g.el) g.el.classList.add('pending-touch');
        if (navigator.vibrate) navigator.vibrate(15);
      }, LONG_PRESS);
    }
  });

  window.addEventListener('pointermove', (e) => {
    if (!g || e.pointerId !== g.id) return;
    const dist = Math.hypot(e.clientX - g.x, e.clientY - g.y);
    if (!g.active) {
      if (!g.armed) {
        if (dist > 8) cancelGesture(); // touch moved before the hold: it's a scroll
        return;
      }
      if (dist < 4 || g.mode === 'click') return;
      startDrag();
    }
    e.preventDefault();
    updateDrag(e.clientX, e.clientY);
    autoScroll(e.clientY);
  }, { passive: false });

  // While dragging on touch, stop the page from scrolling
  window.addEventListener('touchmove', (e) => { if (g && (g.active || g.armed)) e.preventDefault(); }, { passive: false });

  window.addEventListener('pointerup', (e) => {
    if (!g || e.pointerId !== g.id) return;
    clearTimeout(g.timer);
    const gesture = g;
    g = null;
    stopAutoScroll();
    if (gesture.el) gesture.el.classList.remove('pending-touch');
    if (!gesture.active) {
      // A click / tap
      if (gesture.ev) { showPopover(gesture.ev, gesture.el); return; }
      const slot = gesture.origin;
      if (!slot) return;
      if (gesture.target === 'col') createAt(addMinutes(slot.day, Math.floor(slot.min / 30) * 30), null, false);
      else if (gesture.target === 'acell') createAt(slot.day, slot.day, true);
      else if (gesture.target === 'mcell') { const s = new Date(slot.day); s.setHours(9); createAt(s, null, false); }
      return;
    }
    finishDrag(gesture);
  });
  window.addEventListener('pointercancel', (e) => { if (g && e.pointerId === g.id) cancelGesture(); });

  function cancelGesture() {
    if (!g) return;
    clearTimeout(g.timer);
    if (g.el) g.el.classList.remove('pending-touch', 'ghost');
    if (g.active) render();
    g = null;
    stopAutoScroll();
    removeSelection();
  }

  /** Positions of the day columns / cells at drag start (the page doesn't re-render while dragging). */
  function collectCells() {
    const sel = state.view === 'month' ? '.cal-mcell' : '.cal-col';
    const cols = Array.from(cal.querySelectorAll(sel)).map((el) => ({ el, day: parse(el.dataset.date), rect: el.getBoundingClientRect() }));
    const acells = Array.from(cal.querySelectorAll('.cal-allday .cell')).map((el) => ({ el, day: parse(el.dataset.date), rect: el.getBoundingClientRect() }));
    return { cols, acells, scrollTop: (cal.querySelector('.cal-scroll') || {}).scrollTop || 0 };
  }

  /** Which day (and minute, in the time grid) is under the pointer. */
  function pointToSlot(x, y) {
    if (!g) return null;
    const { cols, acells } = g.cells;
    if (state.view === 'month') {
      const c = cols.find((c) => x >= c.rect.left && x < c.rect.right && y >= c.rect.top && y < c.rect.bottom)
        || nearest(cols, x, y);
      return c ? { day: c.day, min: null, el: c.el } : null;
    }
    if (g.target === 'acell' || (g.ev && isSpan(g.ev))) {
      const c = acells.find((c) => x >= c.rect.left && x < c.rect.right) || nearest(acells, x, y);
      return c ? { day: c.day, min: null, el: c.el } : null;
    }
    const scroller = cal.querySelector('.cal-scroll');
    const scrollDelta = (scroller ? scroller.scrollTop : 0) - g.cells.scrollTop;
    const c = cols.find((c) => x >= c.rect.left && x < c.rect.right) || nearest(cols, x, y);
    if (!c) return null;
    const min = Math.max(0, Math.min(1440, (y - c.rect.top + scrollDelta) / HOUR * 60));
    return { day: c.day, min, el: c.el };
  }
  function nearest(list, x, y) {
    let best = null;
    let bd = Infinity;
    list.forEach((c) => {
      const cx = Math.max(c.rect.left, Math.min(x, c.rect.right));
      const cy = Math.max(c.rect.top, Math.min(y, c.rect.bottom));
      const d = Math.hypot(cx - x, cy - y);
      if (d < bd) { bd = d; best = c; }
    });
    return best;
  }

  function startDrag() {
    g.active = true;
    closePopover();
    if (g.el) {
      g.el.classList.remove('pending-touch');
      g.el.classList.add('ghost');
      g.orig = { s: g.ev.s, e: g.ev.e };
    }
    document.body.style.cursor = g.mode === 'move' ? 'grabbing' : g.mode === 'create' ? 'crosshair' : (state.view === 'month' || isSpan(g.ev || {}) ? 'ew-resize' : 'ns-resize');
  }

  function updateDrag(x, y) {
    const slot = pointToSlot(x, y);
    if (!slot) return;
    const o = g.origin;
    let s;
    let e;
    let allDay = false;
    if (g.mode === 'create') {
      if (slot.min == null) { // all-day / month: day range
        const a = slot.day < o.day ? slot.day : o.day;
        const b = slot.day < o.day ? o.day : slot.day;
        s = a; e = addMinutes(addDays(b, 1), -1); allDay = true;
      } else {
        const t1 = addMinutes(o.day, snap(o.min - 7));
        const t2 = addMinutes(slot.day, snap(slot.min + 7));
        s = t1 < t2 ? t1 : addMinutes(slot.day, snap(slot.min - 7));
        e = t1 < t2 ? t2 : addMinutes(o.day, snap(o.min + 7));
        if (e - s < SNAP * 60000) e = addMinutes(s, SNAP);
      }
    } else {
      const ev = g.ev;
      allDay = ev.allDay;
      const dayShift = o ? dayDiff(o.day, slot.day) : 0;
      if (g.mode === 'move') {
        if (slot.min == null || o.min == null) {
          s = addDays(g.orig.s, dayShift); e = addDays(g.orig.e, dayShift);
        } else {
          const delta = dayShift * 1440 + snap(slot.min - o.min);
          s = addMinutes(g.orig.s, delta); e = addMinutes(g.orig.e, delta);
        }
      } else if (g.mode === 'resize-end') {
        s = g.orig.s;
        if (slot.min == null) {
          const endDay = slot.day < startOfDay(s) ? startOfDay(s) : slot.day;
          e = allDay ? addMinutes(addDays(endDay, 1), -1) : new Date(endDay.getFullYear(), endDay.getMonth(), endDay.getDate(), g.orig.e.getHours(), g.orig.e.getMinutes());
          if (e <= s) e = addMinutes(s, SNAP);
        } else {
          e = addMinutes(slot.day, snap(slot.min));
          if (e - s < SNAP * 60000) e = addMinutes(s, SNAP);
        }
      } else if (g.mode === 'resize-start') {
        e = g.orig.e;
        s = addMinutes(slot.day, snap(slot.min));
        if (e - s < SNAP * 60000) s = addMinutes(e, -SNAP);
      }
    }
    g.result = { s, e, allDay };
    drawPreview(s, e, allDay);
  }

  function removeSelection() { cal.querySelectorAll('.selection,.drag-preview').forEach((x) => x.remove()); cal.querySelectorAll('.cal-mcell.sel,.cal-mcell.drop').forEach((x) => x.classList.remove('sel', 'drop')); }

  function drawPreview(s, e, allDay) {
    removeSelection();
    const label = allDay ? '' : fmtTime(s) + '–' + fmtTime(e);
    const color = g.ev ? colorOf(g.ev) : 'var(--accent)';
    const titleText = g.ev ? (g.ev.emoji || '') + ' ' + g.ev.title : 'Nieuwe afspraak';
    if (state.view === 'month') {
      g.cells.cols.forEach((c) => {
        if (c.day >= startOfDay(s) && c.day < e) c.el.classList.add(g.mode === 'create' ? 'sel' : 'drop');
      });
      return;
    }
    if (allDay || (g.ev && isSpan(g.ev))) {
      g.cells.acells.forEach((c) => {
        if (c.day >= startOfDay(s) && c.day < e) {
          const p = document.createElement('div');
          p.className = 'drag-preview cal-ev selection';
          const cr = cal.getBoundingClientRect();
          p.style.cssText = `position:absolute;left:${c.rect.left - cr.left + 2}px;width:${c.rect.width - 4}px;top:${c.rect.top - cr.top + 2}px;height:22px`;
          cal.appendChild(p);
        }
      });
      return;
    }
    g.cells.cols.forEach((c) => {
      const ds = c.day;
      const de = addDays(ds, 1);
      if (s >= de || e <= ds) return;
      const a = Math.max(0, (s - ds) / 60000);
      const b = Math.min(1440, (e - ds) / 60000);
      const p = document.createElement('div');
      p.className = 'cal-ev ' + (g.ev ? 'dragging drag-preview' : 'selection');
      p.style.cssText = `--c:${color};top:${a / 60 * HOUR + 1}px;height:${Math.max(16, (b - a) / 60 * HOUR - 2)}px;left:2px;right:4px;width:auto`;
      p.innerHTML = `<span class="t">${esc(titleText)}</span><span class="m">${label}</span>`;
      c.el.appendChild(p);
    });
  }

  let scrollTimer = null;
  function autoScroll(y) {
    const sc = cal.querySelector('.cal-scroll');
    if (!sc) return;
    const r = sc.getBoundingClientRect();
    const speed = y < r.top + 40 ? -12 : y > r.bottom - 40 ? 12 : 0;
    if (!speed) { stopAutoScroll(); return; }
    if (scrollTimer) return;
    scrollTimer = setInterval(() => { sc.scrollTop += speed; }, 30);
  }
  function stopAutoScroll() { clearInterval(scrollTimer); scrollTimer = null; document.body.style.cursor = ''; }

  async function finishDrag(gesture) {
    const res = gesture.result;
    if (!res) { render(); return; }
    if (gesture.mode === 'create') {
      createAt(res.s, res.e, res.allDay);
      return;
    }
    const ev = gesture.ev;
    if (+res.s === +ev.s && +res.e === +ev.e) { render(); return; }
    let scope = 'all';
    if (ev.recurring) {
      scope = await askScope('move');
      if (!scope) { render(); return; }
    }
    const before = { s: ev.s, e: ev.e };
    // Optimistic: show the new position right away
    ev.s = res.s; ev.e = res.e;
    render();
    const fmt = (d) => res.allDay ? ymd(d) : local(d);
    try {
      const r = await api('move', { id: ev.id, occ: ev.occ, start: fmt(res.s), end: fmt(res.e), allDay: res.allDay, scope });
      const what = gesture.mode === 'move' ? 'Verplaatst naar ' + dayLong(res.s) + (res.allDay ? '' : ' ' + fmtTime(res.s)) : 'Tijd aangepast: ' + (res.allDay ? dayLong(res.e) : fmtTime(res.s) + '–' + fmtTime(res.e));
      if (!ev.recurring) {
        toast(what, 'Ongedaan maken', async () => {
          try {
            await api('move', { id: r.id, occ: ymd(res.s), start: res.allDay ? ymd(before.s) : local(before.s), end: res.allDay ? ymd(before.e) : local(before.e), allDay: res.allDay, scope: 'all' });
          } catch (x) { toast(x.message); }
          refresh(true);
        });
      } else {
        toast(what);
      }
      refresh(true);
    } catch (e) {
      ev.s = before.s; ev.e = before.e;
      render();
      toast(e.message);
    }
  }

  // Clicks on day headers / month day numbers / year days go to that day
  cal.addEventListener('click', (e) => {
    const goto = e.target.closest('[data-goto]');
    const month = e.target.closest('[data-month]');
    if (goto) {
      e.preventDefault();
      state.date = parse(goto.dataset.goto);
      state.view = state.view === 'year' || state.view === 'month' ? (isSmall() ? '3day' : 'day') : 'day';
      refresh();
    } else if (month) {
      e.preventDefault();
      state.date = new Date(state.date.getFullYear(), Number(month.dataset.month), 1);
      state.view = 'month';
      refresh();
    } else if (state.view === 'list') {
      const el = e.target.closest('[data-key]');
      if (el) showPopover(findEvent(el.dataset.key), el);
    }
  });

  // Keep the "now" line moving
  setInterval(() => {
    const line = cal.querySelector('.cal-now');
    if (line) line.style.top = (minutesOfDay(new Date()) / 60 * HOUR) + 'px';
  }, 60000);

  let resizeTimer;
  window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => { if (!g) render(); }, 200); });

  refresh();
})();
