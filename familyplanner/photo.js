/* Familie Planner: photo picker with crop, zoom and rotate, used by every image upload field.
   - Clicking any <input type="file" accept="image/*"> opens this dialog instead of the file chooser.
   - Choose a file, drag one in, or paste with Ctrl+V (also works anywhere on a page with a photo field).
   - The cropped result (JPEG) is put back into the original input, so forms submit as before.
   - Multiple inputs (photos[]) collect several cropped photos, shown as thumbnails under the field.
   Aspect default: square for single photo fields (faces), the photo's own shape for multiple ones;
   override with data-aspect="1|0.8|1.5|orig" on the input. */
(function () {
  'use strict';
  const MAX_OUT = 1600; // px, longest side of the result (the server resizes again anyway)
  const ASPECTS = [['1', 'Vierkant'], ['0.8', 'Staand'], ['1.5', 'Liggend'], ['orig', 'Origineel']];
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const toast = (t) => (window.FP ? window.FP.toast(t) : alert(t));
  const isPhotoInput = (el) => el && el.matches && el.matches('input[type=file]') && /image/.test(el.accept || '');
  let open = null; // the dialog that is currently open

  // Chosen photos per multiple input (DataTransfer can only be replaced as a whole)
  const collected = new WeakMap();

  // Replace the browser's English "Choose file" control with a Dutch button (hidden inputs stay as they are)
  function enhance() {
    document.querySelectorAll('input[type=file]').forEach((input) => {
      if (!isPhotoInput(input) || input.hidden || input.dataset.native || input.dataset.enhanced) return;
      input.dataset.enhanced = '1';
      input.required = false; // a hidden required field would block the form without a message
      input.style.display = 'none';
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn secondary photo-btn';
      b.textContent = input.multiple ? "📷 Foto's kiezen of plakken" : '📷 Foto kiezen of plakken';
      b.onclick = () => openPicker(input);
      input.before(b);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance); else enhance();

  document.addEventListener('click', (e) => {
    const input = e.target.closest && e.target.closest('input[type=file]');
    if (!isPhotoInput(input) || input.dataset.native) return;
    e.preventDefault();
    openPicker(input);
  }, true);

  // Ctrl+V anywhere: open the picker for the first photo field on the page
  document.addEventListener('paste', (e) => {
    if (open) return; // the dialog handles its own paste
    const file = imageFromClipboard(e);
    if (!file) return;
    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA)$/.test(t.tagName)) && !isPhotoInput(t)) return;
    const inputs = Array.from(document.querySelectorAll('input[type=file]')).filter(isPhotoInput);
    if (!inputs.length) return;
    e.preventDefault();
    if (inputs.length === 1 || !window.FP) { openPicker(inputs[0], file); return; }
    // Several photo fields on this page: ask which one first
    window.FP.choose('Voor welke foto?', 'Er staan meerdere fotovelden op deze pagina.',
      inputs.map((inp, i) => ({ value: String(i), label: photoLabel(inp), primary: i === 0 })))
      .then((v) => { if (v !== null) openPicker(inputs[Number(v)], file); });
  });

  /** A readable name for a photo field: data-photo-label, the form's heading, or a name nearby. */
  function photoLabel(input) {
    if (input.dataset.photoLabel) return input.dataset.photoLabel;
    const form = input.closest('form');
    const h = form && form.querySelector('h2');
    if (h && h.textContent.trim()) return (input.multiple ? '' : 'Foto: ') + h.textContent.trim();
    const face = input.closest('.face');
    const name = face && face.querySelector('b');
    if (name) return 'Foto van ' + name.textContent.trim();
    return input.multiple ? "Foto's" : 'Foto';
  }

  function imageFromClipboard(e) {
    const items = (e.clipboardData && e.clipboardData.items) || [];
    for (const it of items) {
      if (it.kind === 'file' && /^image\//.test(it.type)) return it.getAsFile();
    }
    return null;
  }

  function openPicker(input, initialFile) {
    const multiple = input.multiple;
    let aspect = input.dataset.aspect || (multiple ? 'orig' : '1');
    const d = document.createElement('dialog');
    d.className = 'modal photo-modal';
    d.innerHTML = `
      <div class="modal-head"><h2>📷 ${esc(photoLabel(input))}</h2><button type="button" class="x" data-close aria-label="Sluiten">×</button></div>
      <div class="modal-body">
        <div class="photo-drop">
          <div class="pd-icon">🖼️</div>
          <p><b>Sleep een foto hierheen</b>, plak met <kbd>Ctrl</kbd>+<kbd>V</kbd>,<br>of</p>
          <button type="button" class="btn" data-choose>Foto kiezen</button>
          <input type="file" accept="image/*" data-native="1" hidden>
        </div>
        <div class="photo-edit" hidden>
          <div class="crop-frame"><img alt="" draggable="false"><div class="crop-grid"></div></div>
          <div class="crop-tools">
            <span class="zoom-row">🔍 <input type="range" min="1" max="4" step="0.01" value="1" aria-label="Zoomen"></span>
            <div class="picker">${ASPECTS.map(([k, l]) => `<label class="pick sm"><input type="radio" name="aspect" value="${k}"${k === aspect ? ' checked' : ''}><span>${l}</span></label>`).join('')}</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="btn small secondary" data-rotate title="Draaien">↻ Draaien</button>
              <button type="button" class="btn small secondary" data-other>Andere foto</button>
            </div>
          </div>
          <p class="hint">Sleep de foto om hem te verschuiven, zoom met de schuifbalk of het muiswiel.</p>
        </div>
      </div>
      <div class="modal-foot">
        <span class="spacer"></span>
        <button type="button" class="btn secondary" data-close>Annuleren</button>
        <button type="button" class="btn" data-use disabled>${multiple ? 'Toevoegen' : 'Gebruiken'}</button>
      </div>`;
    document.body.appendChild(d);
    open = d;

    const drop = d.querySelector('.photo-drop');
    const edit = d.querySelector('.photo-edit');
    const frame = d.querySelector('.crop-frame');
    const imgEl = frame.querySelector('img');
    const zoom = d.querySelector('input[type=range]');
    const fileInput = d.querySelector('input[data-native]');
    const useBtn = d.querySelector('[data-use]');
    let src = null; // canvas holding the (rotated) source image
    let st = { scale: 1, min: 1, x: 0, y: 0, fw: 0, fh: 0 };

    const close = () => { d.close(); d.remove(); open = null; };
    d.querySelectorAll('[data-close]').forEach((b) => { b.onclick = close; });
    d.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    d.querySelector('[data-choose]').onclick = () => fileInput.click();
    d.querySelector('[data-other]').onclick = () => fileInput.click();
    fileInput.onchange = () => { if (fileInput.files[0]) load(fileInput.files[0]); fileInput.value = ''; };
    d.addEventListener('paste', (e) => { const f = imageFromClipboard(e); if (f) { e.preventDefault(); load(f); } });
    ['dragenter', 'dragover'].forEach((t) => d.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('over'); }));
    d.addEventListener('dragleave', (e) => { if (e.target === drop) drop.classList.remove('over'); });
    d.addEventListener('drop', (e) => {
      e.preventDefault();
      drop.classList.remove('over');
      const f = Array.from(e.dataTransfer.files || []).find((x) => /^image\//.test(x.type));
      if (f) load(f); else toast('Dat is geen foto.');
    });

    function load(file) {
      if (!/^image\//.test(file.type)) { toast('Dat is geen foto.'); return; }
      const url = URL.createObjectURL(file);
      const im = new Image();
      im.onload = () => {
        src = document.createElement('canvas');
        // Don't keep giant camera images around in the browser: 3000px is plenty to crop from
        const k = Math.min(1, 3000 / Math.max(im.naturalWidth, im.naturalHeight));
        src.width = Math.round(im.naturalWidth * k);
        src.height = Math.round(im.naturalHeight * k);
        src.getContext('2d').drawImage(im, 0, 0, src.width, src.height);
        URL.revokeObjectURL(url);
        drop.hidden = true;
        edit.hidden = false;
        useBtn.disabled = false;
        show();
      };
      im.onerror = () => { URL.revokeObjectURL(url); toast('Deze foto kan niet worden geopend. Probeer een jpg of png.'); };
      im.src = url;
    }

    // Size the frame for the chosen shape and fit the photo so it covers the frame
    function show() {
      const ratio = aspect === 'orig' ? src.width / src.height : Number(aspect);
      const maxW = Math.min(460, d.querySelector('.modal-body').clientWidth - 4);
      const maxH = Math.min(420, window.innerHeight * 0.5);
      let fw = maxW;
      let fh = fw / ratio;
      if (fh > maxH) { fh = maxH; fw = fh * ratio; }
      st.fw = Math.round(fw);
      st.fh = Math.round(fh);
      frame.style.width = st.fw + 'px';
      frame.style.height = st.fh + 'px';
      frame.classList.toggle('round', aspect === '1' && !multiple);
      imgEl.src = src.toDataURL('image/jpeg', 0.92);
      st.min = Math.max(st.fw / src.width, st.fh / src.height);
      st.scale = st.min;
      st.x = (st.fw - src.width * st.scale) / 2;
      st.y = (st.fh - src.height * st.scale) / 2;
      zoom.value = 1;
      apply();
    }

    function clamp() {
      const w = src.width * st.scale;
      const h = src.height * st.scale;
      st.x = Math.min(0, Math.max(st.fw - w, st.x));
      st.y = Math.min(0, Math.max(st.fh - h, st.y));
    }
    function apply() {
      clamp();
      imgEl.style.width = src.width * st.scale + 'px';
      imgEl.style.height = src.height * st.scale + 'px';
      imgEl.style.transform = `translate(${st.x}px, ${st.y}px)`;
    }
    // Zoom keeping the point (cx, cy) in the frame where it is
    function zoomTo(factor, cx, cy) {
      const ns = st.min * Math.max(1, Math.min(4, factor));
      const k = ns / st.scale;
      st.x = cx - (cx - st.x) * k;
      st.y = cy - (cy - st.y) * k;
      st.scale = ns;
      zoom.value = ns / st.min;
      apply();
    }
    zoom.addEventListener('input', () => zoomTo(Number(zoom.value), st.fw / 2, st.fh / 2));
    frame.addEventListener('wheel', (e) => {
      e.preventDefault();
      const r = frame.getBoundingClientRect();
      zoomTo((st.scale / st.min) * (e.deltaY < 0 ? 1.08 : 1 / 1.08), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });

    // Drag to move; two fingers to pinch-zoom
    const pointers = new Map();
    let last = null;
    let pinch = null;
    frame.addEventListener('pointerdown', (e) => {
      frame.setPointerCapture(e.pointerId);
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      last = { x: e.clientX, y: e.clientY };
      if (pointers.size === 2) {
        const [a, b] = Array.from(pointers.values());
        pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y), zoom: st.scale / st.min };
      }
    });
    frame.addEventListener('pointermove', (e) => {
      if (!pointers.has(e.pointerId)) return;
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pointers.size === 2 && pinch) {
        const [a, b] = Array.from(pointers.values());
        const r = frame.getBoundingClientRect();
        zoomTo(pinch.zoom * Math.hypot(a.x - b.x, a.y - b.y) / pinch.dist, (a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top);
        return;
      }
      st.x += e.clientX - last.x;
      st.y += e.clientY - last.y;
      last = { x: e.clientX, y: e.clientY };
      apply();
    });
    const up = (e) => { pointers.delete(e.pointerId); if (pointers.size < 2) pinch = null; const p = Array.from(pointers.values())[0]; if (p) last = { x: p.x, y: p.y }; };
    frame.addEventListener('pointerup', up);
    frame.addEventListener('pointercancel', up);

    d.querySelectorAll('[name=aspect]').forEach((r) => r.addEventListener('change', () => { aspect = r.value; show(); }));
    d.querySelector('[data-rotate]').onclick = () => {
      const c = document.createElement('canvas');
      c.width = src.height;
      c.height = src.width;
      const ctx = c.getContext('2d');
      ctx.translate(c.width, 0);
      ctx.rotate(Math.PI / 2);
      ctx.drawImage(src, 0, 0);
      src = c;
      show();
    };

    useBtn.onclick = () => {
      // Cut out what is visible in the frame
      const sx = -st.x / st.scale;
      const sy = -st.y / st.scale;
      const sw = st.fw / st.scale;
      const sh = st.fh / st.scale;
      const k = Math.min(1, MAX_OUT / Math.max(sw, sh));
      const out = document.createElement('canvas');
      out.width = Math.max(1, Math.round(sw * k));
      out.height = Math.max(1, Math.round(sh * k));
      const ctx = out.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, out.width, out.height);
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(src, sx, sy, sw, sh, 0, 0, out.width, out.height);
      out.toBlob((blob) => {
        const file = new File([blob], 'foto-' + Date.now() + '.jpg', { type: 'image/jpeg' });
        setFiles(input, file, multiple);
        close();
      }, 'image/jpeg', 0.9);
    };

    d.showModal();
    if (initialFile) load(initialFile);
  }

  /** Put the cropped photo into the real input and show a preview next to it. */
  function setFiles(input, file, multiple) {
    const list = multiple ? (collected.get(input) || []).concat(file) : [file];
    collected.set(input, list);
    const dt = new DataTransfer();
    list.forEach((f) => dt.items.add(f));
    input.files = dt.files;
    renderPreview(input, list, multiple);
    if (!multiple) {
      const remove = input.form && input.form.querySelector('[name=remove_photo]');
      if (remove) remove.checked = false;
    }
    input.dispatchEvent(new Event('change', { bubbles: true })); // e.g. smoelenboek submits on change
  }

  function renderPreview(input, list, multiple) {
    let box = input.nextElementSibling && input.nextElementSibling.classList.contains('photo-previews') ? input.nextElementSibling : null;
    if (!box) {
      box = document.createElement('div');
      box.className = 'photo-previews';
      input.after(box);
    }
    box.innerHTML = '';
    list.forEach((f, i) => {
      const fig = document.createElement('span');
      fig.className = 'pp';
      const url = URL.createObjectURL(f);
      fig.innerHTML = `<img src="${url}" alt="">` + (multiple ? '<button type="button" aria-label="Verwijderen">×</button>' : '');
      const b = fig.querySelector('button');
      if (b) b.onclick = () => {
        const rest = list.filter((_, j) => j !== i);
        collected.set(input, rest);
        const dt = new DataTransfer();
        rest.forEach((x) => dt.items.add(x));
        input.files = dt.files;
        renderPreview(input, rest, multiple);
      };
      box.appendChild(fig);
    });
    const label = document.createElement('span');
    label.className = 'small muted';
    label.textContent = list.length ? (multiple ? list.length + ' foto' + (list.length === 1 ? '' : "'s") + ' klaar · klik op het veld voor nog een' : 'Nieuwe foto klaar om op te slaan') : '';
    box.appendChild(label);
    input.classList.toggle('has-photo', list.length > 0);
  }
})();
