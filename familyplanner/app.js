/* Familie Planner: shared front-end helpers.
   FP.api(), FP.toast(), FP.choose(), FP.openEditor() (the event editor dialog, used by the calendar
   and the "+" buttons on every page) and small progressive enhancements (task ticks without reload). */
(function () {
  'use strict';
  const DATA = window.FP_DATA || { members: [], types: {}, recurrences: {}, hosts: {}, rsvps: {} };
  const csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';

  // ---------- Small utilities ----------
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const hm = (d) => pad(d.getHours()) + ':' + pad(d.getMinutes());
  const parse = (s) => { // "YYYY-MM-DD" or "YYYY-MM-DDTHH:MM" as local time
    const [date, time] = String(s).split(/[T ]/);
    const [y, m, d] = date.split('-').map(Number);
    const [h, mi] = (time || '00:00').split(':').map(Number);
    return new Date(y, m - 1, d, h || 0, mi || 0);
  };
  const local = (d) => ymd(d) + 'T' + hm(d);
  const DAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
  const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
  const memberById = (id) => DATA.members.find((m) => m.id === id);

  function avatarHtml(p, size) {
    size = size || 28;
    const style = `width:${size}px;height:${size}px;font-size:${Math.round(size * 0.42)}px`;
    if (p.photo) return `<img class="avatar" style="${style}" src="foto.php?f=${encodeURIComponent(p.photo)}&amp;s=t" alt="${esc(p.name)}" title="${esc(p.fullName || p.name)}">`;
    const label = p.emoji && size >= 28 ? p.emoji : (p.name || '?').trim().charAt(0).toUpperCase();
    return `<span class="avatar" style="${style};background:${esc(p.color || '#999')}" title="${esc(p.fullName || p.name)}">${esc(label)}</span>`;
  }

  async function api(action, body, params) {
    const qs = new URLSearchParams(Object.assign({ a: action }, params || {}));
    const opt = body === undefined ? {} : {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    };
    let res;
    try {
      res = await fetch('api.php?' + qs.toString(), Object.assign({ credentials: 'same-origin' }, opt));
    } catch (e) {
      throw new Error('Geen verbinding. Controleer je internet en probeer het opnieuw.');
    }
    let data = {};
    try { data = await res.json(); } catch (e) { /* not json */ }
    if (!res.ok || data.error) throw new Error(data.error || 'Er ging iets mis (' + res.status + ').');
    return data;
  }

  let toastTimer;
  function toast(text, actionLabel, onAction) {
    document.querySelectorAll('.toast').forEach((t) => t.remove());
    const t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.innerHTML = `<span>${esc(text)}</span>`;
    if (actionLabel) {
      const b = document.createElement('button');
      b.textContent = actionLabel;
      b.onclick = () => { t.remove(); onAction(); };
      t.appendChild(b);
    }
    document.body.appendChild(t);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.remove(), actionLabel ? 7000 : 3500);
  }

  /** Ask a question with buttons. options: [{value, label, primary, danger}]. Resolves value or null. */
  function choose(title, text, options) {
    return new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.className = 'modal';
      d.style.width = 'min(420px, calc(100vw - 24px))';
      d.innerHTML = `<div class="modal-head"><h2>${esc(title)}</h2><button class="x" value="" aria-label="Sluiten">×</button></div>
        <div class="modal-body">${text ? `<p class="muted">${esc(text)}</p>` : ''}<div class="stack choose-opts"></div></div>`;
      const box = d.querySelector('.choose-opts');
      options.forEach((o) => {
        const b = document.createElement('button');
        b.className = 'btn wide ' + (o.primary ? '' : o.danger ? 'danger' : 'secondary');
        b.style.marginTop = '8px';
        b.textContent = o.label;
        b.onclick = () => { done(o.value); };
        box.appendChild(b);
      });
      let settled = false;
      const done = (v) => { if (settled) return; settled = true; d.close(); d.remove(); resolve(v); };
      d.querySelector('.x').onclick = () => done(null);
      d.addEventListener('cancel', (e) => { e.preventDefault(); done(null); });
      d.addEventListener('click', (e) => { if (e.target === d) done(null); });
      document.body.appendChild(d);
      d.showModal();
    });
  }

  function askScope(verb) {
    return choose(verb === 'delete' ? 'Herhalende afspraak verwijderen' : 'Herhalende afspraak wijzigen', '', [
      { value: 'one', label: 'Alleen deze keer', primary: true },
      verb === 'delete' ? { value: 'future', label: 'Deze en alle volgende' } : null,
      { value: 'all', label: 'Alle keren', danger: verb === 'delete' },
    ].filter(Boolean));
  }

  // ---------- Contact picker (guests / friends at an event) ----------
  function peopleSelect(container, chosen, withRsvp) {
    chosen = (chosen || []).map((c) => Object.assign({}, c));
    container.innerHTML = `<div class="people-chosen"></div>
      <input type="text" placeholder="Zoek vriendje, familie of vriend… (of typ een nieuwe naam)" autocomplete="off">
      <div class="people-results"></div>`;
    const list = container.querySelector('.people-chosen');
    const input = container.querySelector('input');
    const results = container.querySelector('.people-results');
    let items = [];
    let hl = -1;
    let timer;

    function render() {
      list.innerHTML = '';
      chosen.forEach((c, i) => {
        const tag = document.createElement('span');
        tag.className = 'person-tag';
        tag.innerHTML = avatarHtml(c, 24) + ' ' + esc(c.name) +
          (withRsvp ? ` <select aria-label="Reactie">${Object.entries(DATA.rsvps).map(([k, v]) => `<option value="${k}"${k === (c.rsvp || '') ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>` : '') +
          '<button type="button" aria-label="Verwijderen">×</button>';
        tag.querySelector('button').onclick = () => { chosen.splice(i, 1); render(); };
        const sel = tag.querySelector('select');
        if (sel) sel.onchange = () => { c.rsvp = sel.value; };
        list.appendChild(tag);
      });
    }

    async function search() {
      const q = input.value.trim();
      try {
        const data = await api('contacts', undefined, { q });
        items = data.contacts.filter((c) => !chosen.some((x) => x.id === c.id));
      } catch (e) { items = []; }
      hl = -1;
      showResults(q);
    }

    function showResults(q) {
      results.innerHTML = '';
      items.slice(0, 12).forEach((c, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = i === hl ? 'hl' : '';
        const friendOf = (c.members || []).map((id) => (memberById(id) || {}).name).filter(Boolean);
        b.innerHTML = avatarHtml(c, 30) + `<span><b>${esc(c.fullName)}</b><br><small>${esc([c.relation, c.household, friendOf.length ? 'van ' + friendOf.join(' & ') : ''].filter(Boolean).join(' · '))}</small></span>`;
        b.onmousedown = (e) => e.preventDefault();
        b.onclick = () => pick(c);
        results.appendChild(b);
      });
      if (q) {
        const b = document.createElement('button');
        b.type = 'button';
        b.innerHTML = `<span class="avatar" style="width:30px;height:30px;background:var(--accent)">＋</span><span><b>“${esc(q)}” toevoegen</b><br><small>Nieuw in het adresboek</small></span>`;
        b.onmousedown = (e) => e.preventDefault();
        b.onclick = async () => {
          const kids = (container.dataset.members || '').split(',').map(Number).filter((id) => (memberById(id) || {}).role === 'CHILD');
          try {
            const r = await api('contact.quick', { name: q, isChild: kids.length > 0, members: kids });
            pick(r.contact);
            toast(q + ' staat nu in het adresboek');
          } catch (e) { toast(e.message); }
        };
        results.appendChild(b);
      }
      results.classList.toggle('open', results.children.length > 0);
    }

    function pick(c) {
      chosen.push({ id: c.id, name: c.name, fullName: c.fullName, photo: c.photo, color: c.color, rsvp: '' });
      input.value = '';
      results.classList.remove('open');
      render();
      input.focus();
    }

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 150); });
    input.addEventListener('focus', search);
    input.addEventListener('blur', () => setTimeout(() => results.classList.remove('open'), 150));
    input.addEventListener('keydown', (e) => {
      const btns = results.querySelectorAll('button');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        hl = Math.max(0, Math.min(btns.length - 1, hl + (e.key === 'ArrowDown' ? 1 : -1)));
        btns.forEach((b, i) => b.classList.toggle('hl', i === hl));
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (btns[hl]) btns[hl].click(); else if (btns.length) btns[0].click();
      } else if (e.key === 'Escape') {
        results.classList.remove('open');
      }
    });
    render();
    return { value: () => chosen.map((c) => ({ id: c.id, rsvp: c.rsvp || '' })), names: () => chosen.map((c) => c.name) };
  }

  // ---------- Event editor ----------
  /**
   * Open the editor. ev: an event from the API (existing) or defaults for a new one:
   * {start, end, allDay, type, members, contacts, title}. Resolves with the saved event or null.
   */
  function openEditor(ev) {
    ev = Object.assign({ title: '', type: 'OTHER', members: [], contacts: [], allDay: false, recurrence: '', host: '' }, ev || {});
    const isNew = !ev.id;
    if (!ev.start) {
      const d = new Date();
      d.setMinutes(0, 0, 0);
      d.setHours(d.getHours() + 1);
      ev.start = local(d);
    }
    if (!ev.end) {
      const s = parse(ev.start);
      ev.end = ev.allDay ? ymd(s) + 'T23:59' : local(new Date(s.getTime() + 3600000));
    }
    const s = parse(ev.start);
    const e = parse(ev.end);
    return new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.className = 'modal editor';
      const typeChips = Object.entries(DATA.types).map(([k, t]) =>
        `<label class="pick" style="--c:${t.color}"><input type="radio" name="type" value="${k}"${k === ev.type ? ' checked' : ''}><span>${t.emoji} ${esc(t.label)}</span></label>`).join('');
      const memberChips = DATA.members.map((m) =>
        `<label class="pick" style="--c:${m.color}"><input type="checkbox" name="members" value="${m.id}"${ev.members.includes(m.id) ? ' checked' : ''}><span>${esc(m.emoji)} ${esc(m.name)}</span></label>`).join('');
      const memberOpts = (sel) => '<option value="">—</option>' + DATA.members.map((m) => `<option value="${m.id}"${m.id === sel ? ' selected' : ''}>${esc(m.emoji + ' ' + m.name)}</option>`).join('');
      const opts = (obj, sel) => Object.entries(obj).map(([k, v]) => `<option value="${k}"${k === (sel || '') ? ' selected' : ''}>${esc(v)}</option>`).join('');
      d.innerHTML = `
        <form method="dialog" class="form" novalidate>
          <div class="modal-head"><h2>${isNew ? 'Nieuwe afspraak' : 'Afspraak bewerken'}</h2><button type="button" class="x" data-close aria-label="Sluiten">×</button></div>
          <div class="modal-body">
            <input name="title" class="title-input" placeholder="Wat gaan jullie doen?" value="${esc(ev.title)}" style="font-size:18px;font-weight:700;margin-top:10px" autocomplete="off">
            <div class="label">Soort</div><div class="type-picker">${typeChips}</div>
            <div class="label">Wie <small>(van ons gezin)</small></div><div class="picker">${memberChips}</div>
            <label class="check" style="margin-top:14px"><input type="checkbox" name="allDay"${ev.allDay ? ' checked' : ''}> Hele dag</label>
            <div class="row2 when">
              <div><label>Begin</label><div class="row2" style="gap:6px"><input type="date" name="startDate" value="${ymd(s)}"><input type="time" name="startTime" step="300" value="${hm(s)}" class="t"></div></div>
              <div><label>Eind</label><div class="row2" style="gap:6px"><input type="date" name="endDate" value="${ymd(e)}"><input type="time" name="endTime" step="300" value="${ev.allDay ? '' : hm(e)}" class="t"></div></div>
            </div>
            <div class="label">🔁 Herhalen</div>
            <div class="picker repeat-picker">${Object.entries(DATA.recurrences).map(([k, v]) =>
              `<label class="pick sm"><input type="radio" name="recurrence" value="${k}"${k === (ev.recurrence || '') ? ' checked' : ''}><span>${esc(k ? v : 'Eenmalig')}</span></label>`).join('')}</div>
            <div class="repeat-until" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
              <span class="repeat-text small muted"></span>
              <label class="small" style="margin:0;font-weight:600">tot en met</label><input type="date" name="recurUntil" value="${esc(ev.recurUntil || '')}" style="width:auto;min-height:36px;padding:5px 10px" title="Leeg laten = blijft altijd herhalen"><span class="small muted">(leeg = altijd door)</span>
            </div>
            <div class="label">Met wie <small>(vriendjes, familie, gasten)</small></div>
            <div class="people-select" data-members="${ev.members.join(',')}"></div>
            <div class="row2">
              <div><label>Waar</label><input name="location" value="${esc(ev.location || '')}" placeholder="Adres of plek"></div>
              <div><label>Bij wie</label><select name="host">${opts(DATA.hosts, ev.host)}</select></div>
            </div>
            <details class="more"${ev.dropMember || ev.pickupMember || ev.cost || ev.description ? ' open' : ''}>
              <summary>Meer opties</summary>
              <div class="row2">
                <div><label>🚗 Wie brengt</label><select name="dropMember">${memberOpts(ev.dropMember)}</select></div>
                <div><label>🏠 Wie haalt op</label><select name="pickupMember">${memberOpts(ev.pickupMember)}</select></div>
              </div>
              <div class="row2">
                <div><label>Kosten (€)</label><input name="cost" inputmode="decimal" value="${ev.cost != null ? String(ev.cost).replace('.', ',') : ''}" placeholder="bijv. 25,00"></div>
                <div><label>Eigen kleur</label><div style="display:flex;gap:10px;align-items:center"><input type="color" name="color" value="${esc(ev.customColor || ev.color || '#6C5CE7')}"><label class="check"><input type="checkbox" name="useColor"${ev.customColor ? ' checked' : ''}> gebruiken</label></div></div>
              </div>
              <label class="check"><input type="checkbox" name="paid"${ev.paid ? ' checked' : ''}> Betaald</label>
              <label>Notities</label><textarea name="description" rows="3" placeholder="Wat meenemen, telefoonnummer, allergieën…">${esc(ev.description || '')}</textarea>
            </details>
            <p class="error-msg flash error" hidden></p>
          </div>
          <div class="modal-foot">
            ${isNew ? '' : '<button type="button" class="btn danger small" data-delete>🗑 Verwijderen</button><a class="btn secondary small" href="event.php?id=' + ev.id + '&occ=' + ev.occ + '">Details & lijstje</a>'}
            <span class="spacer"></span>
            <button type="button" class="btn secondary" data-close>Annuleren</button>
            <button type="submit" class="btn">Opslaan</button>
          </div>
        </form>`;
      document.body.appendChild(d);
      const f = d.querySelector('form');
      const people = peopleSelect(d.querySelector('.people-select'), ev.contacts, true);
      const title = f.elements.title;
      let titleTouched = !isNew && ev.title !== '';
      title.addEventListener('input', () => { titleTouched = true; });

      // Default title follows the type until the user types one
      const setDefaultTitle = () => {
        if (titleTouched) return;
        const t = DATA.types[f.elements.type.value];
        const names = people.names();
        title.value = f.elements.type.value === 'PLAYDATE' && names.length ? 'Spelen met ' + names.join(' & ') : '';
        title.placeholder = t ? t.label : 'Wat gaan jullie doen?';
      };
      f.querySelectorAll('[name=type]').forEach((r) => r.addEventListener('change', () => {
        setDefaultTitle();
        if (r.value === 'PLAYDATE' && !f.elements.host.value) f.elements.host.value = 'HOME';
      }));
      d.querySelector('.people-select').addEventListener('click', () => setTimeout(setDefaultTitle, 50));
      f.querySelectorAll('[name=members]').forEach((c) => c.addEventListener('change', () => {
        d.querySelector('.people-select').dataset.members = Array.from(f.querySelectorAll('[name=members]:checked')).map((x) => x.value).join(',');
      }));

      const syncAllDay = () => {
        const on = f.elements.allDay.checked;
        f.querySelectorAll('input.t').forEach((i) => { i.hidden = on; });
      };
      f.elements.allDay.addEventListener('change', syncAllDay);
      syncAllDay();
      // Repeat: readable summary ("Elke week op woensdag") and the end date only when repeating
      const syncRepeat = () => {
        const rule = f.elements.recurrence.value;
        const d = parse(f.elements.startDate.value || ymd(new Date()));
        const day = DAYS[d.getDay()];
        const texts = { DAILY: 'Elke dag', WEEKLY: 'Elke week op ' + day, BIWEEKLY: 'Om de week op ' + day,
          MONTHLY: 'Elke maand op de ' + d.getDate() + 'e', YEARLY: 'Elk jaar op ' + d.getDate() + ' ' + MONTHS[d.getMonth()] };
        const box = f.querySelector('.repeat-until');
        box.hidden = !rule;
        box.querySelector('.repeat-text').textContent = rule ? texts[rule] + ',' : '';
      };
      f.querySelectorAll('[name=recurrence]').forEach((r) => r.addEventListener('change', syncRepeat));
      f.elements.startDate.addEventListener('change', syncRepeat);
      syncRepeat();
      // Keep the length when the start moves (like Google Calendar)
      let lastStart = parse(f.elements.startDate.value + 'T' + (f.elements.startTime.value || '00:00'));
      const onStartChange = () => {
        const ns = parse(f.elements.startDate.value + 'T' + (f.elements.startTime.value || '00:00'));
        const ne = parse(f.elements.endDate.value + 'T' + (f.elements.endTime.value || '00:00'));
        if (isNaN(ns) || isNaN(ne)) return;
        const moved = new Date(ne.getTime() + (ns - lastStart));
        f.elements.endDate.value = ymd(moved);
        if (!f.elements.allDay.checked) f.elements.endTime.value = hm(moved);
        lastStart = ns;
      };
      f.elements.startDate.addEventListener('change', onStartChange);
      f.elements.startTime.addEventListener('change', onStartChange);

      let settled = false;
      const close = (val) => { if (settled) return; settled = true; d.close(); d.remove(); resolve(val); };
      d.querySelectorAll('[data-close]').forEach((b) => { b.onclick = () => close(null); });
      d.addEventListener('cancel', (x) => { x.preventDefault(); close(null); });

      const del = d.querySelector('[data-delete]');
      if (del) del.onclick = async () => {
        const ok = await deleteEvent(ev);
        if (ok) close({ deleted: true });
      };

      f.addEventListener('submit', async (x) => {
        x.preventDefault();
        const err = d.querySelector('.error-msg');
        err.hidden = true;
        const allDay = f.elements.allDay.checked;
        const body = {
          id: ev.id || 0,
          occ: ev.occ || '',
          title: title.value.trim(),
          type: f.elements.type.value || 'OTHER',
          members: Array.from(f.querySelectorAll('[name=members]:checked')).map((c) => Number(c.value)),
          all_day: allDay,
          start: f.elements.startDate.value + (allDay ? '' : ' ' + (f.elements.startTime.value || '00:00')),
          end: f.elements.endDate.value + (allDay ? '' : ' ' + (f.elements.endTime.value || '00:00')),
          location: f.elements.location.value,
          host: f.elements.host.value,
          recurrence: f.elements.recurrence.value,
          recur_until: f.elements.recurUntil.value,
          drop_member_id: f.elements.dropMember.value,
          pickup_member_id: f.elements.pickupMember.value,
          cost: f.elements.cost.value,
          paid: f.elements.paid.checked,
          color: f.elements.useColor.checked ? f.elements.color.value : '',
          description: f.elements.description.value,
          contacts: people.value(),
        };
        if (!f.elements.startDate.value) { err.textContent = 'Kies een datum.'; err.hidden = false; return; }
        if (ev.id && ev.recurring) {
          const scope = await askScope('edit');
          if (!scope) return;
          body.scope = scope;
        }
        const btn = f.querySelector('[type=submit]');
        btn.disabled = true;
        try {
          const r = await api('save', body);
          toast(isNew ? 'Afspraak toegevoegd' : 'Opgeslagen');
          close(r.event || { id: r.id });
        } catch (e2) {
          err.textContent = e2.message;
          err.hidden = false;
          btn.disabled = false;
        }
      });
      d.showModal();
      if (isNew) setTimeout(() => title.focus(), 50);
    });
  }

  /** Delete with the right scope question and an undo for single events. Resolves true when deleted. */
  async function deleteEvent(ev) {
    let scope = 'all';
    if (ev.recurring) {
      scope = await askScope('delete');
      if (!scope) return false;
    } else {
      const ok = await choose('Verwijderen?', '“' + ev.title + '” wordt uit de agenda gehaald.', [
        { value: 'yes', label: 'Ja, verwijderen', danger: true }, { value: null, label: 'Nee, laten staan' }]);
      if (!ok) return false;
    }
    try {
      await api('delete', { id: ev.id, occ: ev.occ, scope });
    } catch (e) { toast(e.message); return false; }
    if (!ev.recurring) {
      toast('Verwijderd', 'Ongedaan maken', async () => {
        await api('restore', {
          title: ev.title, type: ev.type, start: ev.start, end: ev.end, all_day: ev.allDay, location: ev.location, host: ev.host,
          description: ev.description, color: ev.customColor || '', drop_member_id: ev.dropMember, pickup_member_id: ev.pickupMember,
          cost: ev.cost, paid: ev.paid, members: ev.members, contacts: (ev.contacts || []).map((c) => ({ id: c.id, rsvp: c.rsvp })),
        });
        document.dispatchEvent(new CustomEvent('fp:changed'));
        if (!window.FP_CAL) location.reload();
      });
    } else {
      toast('Verwijderd');
    }
    document.dispatchEvent(new CustomEvent('fp:changed'));
    return true;
  }

  // ---------- Progressive enhancements ----------
  document.addEventListener('change', async (e) => {
    const box = e.target.closest('input.js-task');
    if (!box) return;
    const li = box.closest('[data-task]');
    e.stopImmediatePropagation();
    box.form.onsubmit = (x) => x.preventDefault();
    try {
      await api('task.toggle', { id: Number(li.dataset.task), done: box.checked });
      li.classList.toggle('is-done', box.checked);
      if (box.checked) toast('Afgevinkt ✓');
    } catch (err) {
      box.checked = !box.checked;
      toast(err.message);
    }
  }, true);

  // Birthday checklist toggles
  document.addEventListener('change', async (e) => {
    const box = e.target.closest('input.js-bday');
    if (!box) return;
    try {
      await api('birthday.check', { subject: box.dataset.subject, item: box.dataset.item, year: Number(box.dataset.year), done: box.checked });
    } catch (err) {
      box.checked = !box.checked;
      toast(err.message);
    }
  });

  // Buttons that open the editor: <button data-new-event='{"type":"PLAYDATE","members":[3]}'>
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-new-event]');
    if (!b) return;
    e.preventDefault();
    let preset = {};
    try { preset = JSON.parse(b.dataset.newEvent || '{}'); } catch (x) { /* ignore */ }
    const saved = await openEditor(preset);
    if (saved) {
      if (window.FP_CAL) document.dispatchEvent(new CustomEvent('fp:changed'));
      else location.reload();
    }
  });

  // Open an existing event in the editor: <a data-edit-event="12" data-occ="2026-10-01">
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('[data-edit-event]');
    if (!a || e.metaKey || e.ctrlKey) return;
    e.preventDefault();
    try {
      const r = await api('event', undefined, { id: a.dataset.editEvent, occ: a.dataset.occ || '' });
      const saved = await openEditor(r.event);
      if (saved) location.reload();
    } catch (err) { toast(err.message); }
  });

  // Confirm dangerous form submits: <form data-confirm="Zeker weten?">
  document.addEventListener('submit', async (e) => {
    const f = e.target;
    if (!f.dataset || !f.dataset.confirm || f.dataset.confirmed) return;
    e.preventDefault();
    const ok = await choose('Zeker weten?', f.dataset.confirm, [
      { value: 'yes', label: 'Ja', danger: true }, { value: null, label: 'Nee' }]);
    if (ok) { f.dataset.confirmed = '1'; f.requestSubmit ? f.requestSubmit(e.submitter) : f.submit(); }
  });

  window.FP = { api, toast, choose, askScope, openEditor, deleteEvent, peopleSelect, avatarHtml, esc, pad, ymd, hm, parse, local, DAYS, MONTHS, DATA, memberById };
})();
