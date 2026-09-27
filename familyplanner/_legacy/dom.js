/* Hand-written ES5 for old browsers (LG webOS 3.x TVs = Chrome 38). Bundled into legacy/polyfills.js
   by build.js, after core-js, fetch and the pointer events polyfill. Loaded only by browsers that
   don't support <script type="module"> (so never by modern browsers).
   - Small DOM polyfills core-js doesn't cover (closest, before/after/append/remove, toBlob).
   - A minimal <dialog> showModal/close.
   - Colours: old browsers ignore CSS variables such as style="--c:#E0568A", so paint those
     colours directly on the elements that use them. */
(function () {
  'use strict';
  window.FP_LEGACY = true;
  var EP = Element.prototype;

  if (!EP.matches) EP.matches = EP.webkitMatchesSelector || EP.msMatchesSelector;
  if (!EP.closest) {
    EP.closest = function (sel) {
      var el = this;
      while (el && el.nodeType === 1) {
        if (el.matches(sel)) return el;
        el = el.parentNode;
      }
      return null;
    };
  }
  function inserter(place) {
    return function () {
      var frag = document.createDocumentFragment();
      for (var i = 0; i < arguments.length; i++) {
        var n = arguments[i];
        frag.appendChild(typeof n === 'string' ? document.createTextNode(n) : n);
      }
      place(this, frag);
    };
  }
  if (!EP.before) EP.before = inserter(function (el, f) { if (el.parentNode) el.parentNode.insertBefore(f, el); });
  if (!EP.after) EP.after = inserter(function (el, f) { if (el.parentNode) el.parentNode.insertBefore(f, el.nextSibling); });
  if (!EP.append) EP.append = inserter(function (el, f) { el.appendChild(f); });
  if (!EP.prepend) EP.prepend = inserter(function (el, f) { el.insertBefore(f, el.firstChild); });
  if (!EP.remove) EP.remove = function () { if (this.parentNode) this.parentNode.removeChild(this); };

  if (window.HTMLCanvasElement && !HTMLCanvasElement.prototype.toBlob) {
    HTMLCanvasElement.prototype.toBlob = function (cb, type, q) {
      var bin = atob(this.toDataURL(type, q).split(',')[1]);
      var arr = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      cb(new Blob([arr], { type: type || 'image/png' }));
    };
  }

  // ---------- <dialog> ----------
  var hasDialog = typeof HTMLDialogElement === 'function' && typeof HTMLDialogElement.prototype.showModal === 'function';
  if (!hasDialog) {
    var backdrop = null;
    var openDialogs = [];
    HTMLElement.prototype.showModal = function () {
      this.setAttribute('open', '');
      this.className += ' dlg-poly';
      if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'dlg-backdrop';
        document.body.appendChild(backdrop);
      }
      backdrop.style.display = 'block';
      openDialogs.push(this);
    };
    HTMLElement.prototype.close = function () {
      this.removeAttribute('open');
      var i = openDialogs.indexOf(this);
      if (i > -1) openDialogs.splice(i, 1);
      if (backdrop && !openDialogs.length) backdrop.style.display = 'none';
      var ev = document.createEvent('Event');
      ev.initEvent('close', false, false);
      this.dispatchEvent(ev);
    };
    document.addEventListener('keydown', function (e) {
      if ((e.keyCode === 27 || e.key === 'Escape') && openDialogs.length) {
        var d = openDialogs[openDialogs.length - 1];
        var ev = document.createEvent('Event');
        ev.initEvent('cancel', false, true);
        if (d.dispatchEvent(ev)) d.close();
      }
    });
  }

  // ---------- Colours from CSS variables ----------
  function readVar(style, name) {
    var m = new RegExp(name + '\\s*:\\s*(#[0-9a-fA-F]{3,6}|rgba?\\([^)]*\\))').exec(style || '');
    return m ? m[1] : null;
  }
  function rgb(c) {
    if (c.charAt(0) === '#') {
      var h = c.slice(1);
      if (h.length === 3) h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2);
      return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
    }
    var m = c.match(/\d+(\.\d+)?/g) || [0, 0, 0];
    return [+m[0], +m[1], +m[2]];
  }
  /** Colour c at strength p (0..1) on a white background. */
  function soft(c, p) {
    var v = rgb(c);
    return 'rgb(' + Math.round(255 + (v[0] - 255) * p) + ',' + Math.round(255 + (v[1] - 255) * p) + ',' + Math.round(255 + (v[2] - 255) * p) + ')';
  }
  function has(el, cls) { return (' ' + el.className + ' ').indexOf(' ' + cls + ' ') > -1; }

  function paintPick(label) {
    var c = label.getAttribute('data-c');
    var input = label.querySelector('input');
    var span = label.querySelector('span');
    if (!c || !input || !span) return;
    span.style.background = input.checked ? c : '';
    span.style.borderColor = input.checked ? c : '';
    span.style.color = input.checked ? '#fff' : '';
  }

  function paintOne(el) {
    if (!el.getAttribute || typeof el.className !== 'string') return;
    var style = el.getAttribute('style');
    if (!style || style.indexOf('--') === -1) return;
    var c = readVar(style, '--c') || readVar(style, '--ec');
    if (!c) return;
    if (has(el, 'chip')) { el.style.background = c; el.style.color = '#fff'; }
    if (has(el, 'ev')) {
      el.style.borderLeftColor = c;
      var t = el.querySelector('.ev-time');
      if (t) t.style.color = c;
    }
    if (has(el, 'fam')) el.style.borderTopColor = c;
    if (has(el, 'kid-hero')) el.style.borderLeft = '10px solid ' + c;
    if (has(el, 'kid-item')) { el.style.borderColor = c; el.style.background = soft(c, 0.14); }
    if (has(el, 'mtag') || has(el, 'mev')) el.style.background = soft(c, 0.2);
    if (has(el, 'dot')) el.style.background = c;
    if (has(el, 'kid-week')) {
      var today = el.querySelector('.kid-day.today');
      if (today) today.style.borderColor = c;
    }
    if (has(el, 'cal-ev') && !has(el, 'selection')) {
      el.style.background = soft(c, 0.28);
      el.style.borderLeftColor = c;
      el.style.color = '#1d1b24';
    }
    if ((has(el, 'cal-aev') || (has(el, 'cal-mev') && has(el, 'span'))) && !has(el, 'bday')) {
      el.style.background = soft(c, 0.32);
      el.style.borderLeftColor = c;
      el.style.color = '#1d1b24';
    }
    if (has(el, 'cal-mev') && has(el, 'timed')) el.style.borderLeft = '4px solid ' + c;
    if (el.tagName === 'A' && el.parentNode && has(el.parentNode, 'has')) el.style.borderBottom = '3px solid ' + c; // year view day
    if (has(el, 'pick')) { el.setAttribute('data-c', c); paintPick(el); }
  }

  function paint(root) {
    if (!root || root.nodeType !== 1) return;
    paintOne(root);
    var list = root.querySelectorAll('[style*="--"]');
    for (var i = 0; i < list.length; i++) paintOne(list[i]);
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    var group = document.querySelectorAll('input[name="' + t.name + '"]');
    for (var i = 0; i < group.length; i++) {
      var label = group[i].closest('.pick');
      if (label) paintPick(label);
    }
  }, true);

  function start() {
    paint(document.body);
    if (window.MutationObserver) {
      new MutationObserver(function (list) {
        for (var i = 0; i < list.length; i++) {
          for (var j = 0; j < list[i].addedNodes.length; j++) paint(list[i].addedNodes[j]);
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
