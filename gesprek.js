// Conversation on activiteit.php: emoji reactions without reload, Enter to send,
// and images via paste (Ctrl+V), drag & drop or the 📎 button (resized in the browser first).
(function () {
  var chat = document.getElementById('chat');
  if (chat) {
    chat.scrollTop = chat.scrollHeight;
  }

  // ---------- Reactions ----------
  function closePickers(except) {
    document.querySelectorAll('.picker').forEach(function (p) {
      if (p !== except) p.hidden = true;
    });
  }

  document.addEventListener('click', function (e) {
    var open = e.target.closest('.react-open');
    if (open) {
      var picker = open.parentNode.querySelector('.picker');
      closePickers(picker);
      picker.hidden = !picker.hidden;
      return;
    }
    if (!e.target.closest('.picker')) closePickers();
  });

  document.querySelectorAll('.react-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var button = e.submitter || document.activeElement;
      if (!button || button.name !== 'emoji') return;
      e.preventDefault();
      var data = new FormData(form);
      data.append('emoji', button.value);
      data.append('ajax', '1');
      closePickers();
      fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok || r.redirected) throw new Error(r.status); // e.g. logged out
          return r.text();
        })
        .then(function (html) {
          form.querySelector('.reactions').innerHTML = html;
        })
        .catch(function () {
          form.submit(); // fall back to a normal page load
        });
    });
  });

  // ---------- Composer ----------
  var composer = document.getElementById('composer');
  if (!composer) return;

  var textarea = composer.querySelector('textarea');
  var fileInput = composer.querySelector('input[type=file]');
  var preview = composer.querySelector('.composer-preview');
  var previewImg = preview.querySelector('img');
  var sendButton = composer.querySelector('.send');
  var pendingImage = null; // Blob to upload instead of the raw file input
  var MAX_SIDE = 1600;

  function autosize() {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
  }
  textarea.addEventListener('input', autosize);

  textarea.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.matchMedia('(pointer: fine)').matches) {
      e.preventDefault();
      composer.requestSubmit ? composer.requestSubmit() : composer.submit();
    }
  });

  // Downscale big photos in the browser so uploads stay small and fast
  function resize(file) {
    return new Promise(function (resolve) {
      if (file.type === 'image/gif') return resolve(file);
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, MAX_SIDE / Math.max(img.width, img.height));
        if (scale === 1 && file.size < 1500000) return resolve(file);
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function setImage(file) {
    if (!file || !/^image\//.test(file.type)) return;
    resize(file).then(function (blob) {
      pendingImage = blob;
      if (previewImg.src) URL.revokeObjectURL(previewImg.src);
      previewImg.src = URL.createObjectURL(blob);
      preview.hidden = false;
      textarea.placeholder = 'Voeg een bericht toe (optioneel)…';
      textarea.focus();
    });
  }

  function clearImage() {
    pendingImage = null;
    fileInput.value = '';
    preview.hidden = true;
    textarea.placeholder = 'Bericht…';
  }

  preview.querySelector('.preview-remove').addEventListener('click', clearImage);
  fileInput.addEventListener('change', function () { setImage(fileInput.files[0]); });

  // Ctrl+V anywhere on the page (not only in the text box)
  document.addEventListener('paste', function (e) {
    var items = (e.clipboardData || {}).items || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
        e.preventDefault();
        setImage(items[i].getAsFile());
        return;
      }
    }
  });

  composer.addEventListener('dragover', function (e) { e.preventDefault(); composer.classList.add('dragging'); });
  composer.addEventListener('dragleave', function () { composer.classList.remove('dragging'); });
  composer.addEventListener('drop', function (e) {
    e.preventDefault();
    composer.classList.remove('dragging');
    if (e.dataTransfer.files.length) setImage(e.dataTransfer.files[0]);
  });

  composer.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!textarea.value.trim() && !pendingImage) return;
    var data = new FormData(composer);
    data.delete('image');
    if (pendingImage) data.append('image', pendingImage, 'afbeelding.jpg');
    sendButton.disabled = true;
    fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function () {
        location.hash = 'gesprek';
        location.reload();
      })
      .catch(function () {
        sendButton.disabled = false;
        alert('Versturen is niet gelukt. Controleer je internetverbinding en probeer het opnieuw.');
      });
  });
})();
