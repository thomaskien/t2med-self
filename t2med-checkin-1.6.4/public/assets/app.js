'use strict';
(() => {
  const screen = document.getElementById('screen');
  const staff = document.getElementById('staff');
  let config = null, state = {stage: 'login'}, csrf = '', busy = false;
  let completionTimer = null, idleTimer = null, activityTimer = 0;
  let stream = null, source = null, crop = null, cameraEpoch = 0;
  const pointers = new Map();
  let gesture = null;
  let requestNumber = 0, appliedRequest = 0;
  let fullViewportHeight = window.innerHeight, viewportWidth = window.innerWidth;
  let keyboardOpen = false, viewportEpoch = 0, viewportResetTimer = null;
  let naturalScrollMode = false;
  let staffConfirmation = null;

  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (text, cls, handler) => {
    const node = el('button', 'button ' + cls, text);
    node.type = 'button'; node.addEventListener('click', handler); return node;
  };
  const message = key => config?.messages?.[key] || '';
  function clearCamera() {
    cameraEpoch++;
    if (stream) stream.getTracks().forEach(track => track.stop());
    stream = null;
    if (source) { source.width = source.height = 1; }
    source = null; crop = null; pointers.clear(); gesture = null;
  }
  function clearScreen() {
    staffConfirmation?.dismiss(false);
    staff.hidden = true;
    viewportEpoch++; clearTimeout(viewportResetTimer);
    naturalScrollMode = false; document.documentElement.classList.remove('natural-scroll');
    screen.scrollTop = 0; screen.scrollLeft = 0;
    clearTimeout(completionTimer); clearTimeout(idleTimer); clearCamera();
    screen.classList.remove('contacts-screen', 'photo-screen');
    screen.replaceChildren();
  }
  function page(center = false) {
    const node = el('section', 'page' + (center ? ' center' : ''));
    screen.append(node); return node;
  }
  function heading(node, label, title, body) {
    if (label) node.append(el('div', 'eyebrow', label));
    node.append(el('h1', '', title));
    if (body) node.append(el('p', 'intro', body));
  }
  async function request(action, data = {}, file = null) {
    const number = ++requestNumber;
    const options = {cache: 'no-store', credentials: 'same-origin', redirect: 'error'};
    if (action) {
      options.method = 'POST'; options.headers = {'X-CSRF-Token': csrf};
      if (file) {
        const form = new FormData(); form.append('action', action); form.append('flowId', state.id || '');
        form.append('image', file, 'selfie.jpg'); options.body = form;
      } else {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify({action, flowId: state.id || '', ...data});
      }
    }
    const response = await fetch('api.php', options);
    let result;
    try { result = await response.json(); } catch (_) { throw new Error('Der Server hat keine lesbare Antwort geliefert.'); }
    if (result.csrf && number >= appliedRequest) csrf = result.csrf;
    if (!response.ok || !result.ok) {
      const error = new Error(result.message || 'Die Verbindung ist fehlgeschlagen.');
      error.code = result.code; error.blocked = result.blocked; error.http = response.status; error.diagnostic = result.diagnostic; throw error;
    }
    if (number >= appliedRequest) {
      appliedRequest = number;
      if (result.config) config = result.config;
      if (action !== 'touch') state = result.state;
    }
    return result;
  }
  function waiting(text) {
    clearScreen(); const node = page(true);
    node.append(el('div', 'spinner')); heading(node, '', text || 'Einen Moment bitte …');
    screen.setAttribute('aria-busy', 'true');
  }
  async function perform(action, data = {}, file = null, inlineError = null) {
    if (busy) return;
    busy = true; staff.disabled = true; clearTimeout(idleTimer);
    if (inlineError) {
      inlineError.textContent = '';
      screen.querySelectorAll('button,input,textarea').forEach(node => node.disabled = true);
    } else waiting(action === 'begin' ? message('reading') : 'Einen Moment bitte …');
    try {
      // Read authoritative state first, including after a lost response. Never replay a write.
      if (action === 'next') {
        const previousId = state.id;
        await request(null);
        // An old tab must never discard a newer patient's flow after reconciliation.
        if ((previousId && state.id && previousId !== state.id)
            || (!previousId && !['idle', 'blocked', 'done', 'login'].includes(state.stage))) {
          render(); return;
        }
      }
      await request(action, data, file); render();
    }
    catch (error) {
      if (inlineError && !error.blocked && error.code) {
        inlineError.textContent = error.message;
        screen.querySelectorAll('button,input,textarea').forEach(node => node.disabled = false);
        armIdle();
      } else {
        // Reconcile only by reading the server state. Never repeat a possibly applied write.
        try {
          await request(null);
          if (state.stage === 'done') { render(); }
          else if (state.stage === 'login') { renderLogin(error.message); }
          else if (['FLOW_ID', 'FLOW_STEP'].includes(error.code) && state.stage !== 'blocked') { render(); }
          else { renderError(error); }
        } catch (_) { renderError(error); }
      }
    } finally {
      busy = false; staff.disabled = false; screen.removeAttribute('aria-busy');
    }
  }
  function renderLogin(errorText = '') {
    clearScreen();
    naturalScrollMode = true; document.documentElement.classList.add('natural-scroll');
    const node = page(); node.classList.add('hero-grid', 'login');
    const copy = el('div', 'hero-copy');
    heading(copy, 'Für das Praxisteam', 'Gerät anmelden', 'Melden Sie sich mit Ihrem T2med-Zugang an. Die Anmeldung gilt für alle Check-in-Seiten auf diesem Gerät.');
    const form = el('form', 'form');
    const usernameLabel = el('label', '', 'T2med-Benutzer');
    const username = el('input'); username.name = 'username'; username.autocomplete = 'off'; username.autocapitalize = 'none'; username.spellcheck = false; username.required = true;
    usernameLabel.append(username);
    const passwordLabel = el('label', '', 'Passwort');
    const password = el('input'); password.type = 'password'; password.name = 'password'; password.autocomplete = 'new-password';
    passwordLabel.append(password);
    const error = el('p', 'inline-error', errorText); error.setAttribute('role', 'alert');
    const submit = el('button', 'button primary wide', 'Gerät anmelden'); submit.type = 'submit';
    form.append(usernameLabel, passwordLabel, error, submit);
    form.addEventListener('submit', event => {
      event.preventDefault(); const credentials = {username: username.value.trim(), password: password.value};
      password.value = ''; perform('login', credentials, null, error);
    });
    if (state.stage !== 'login') {
      form.append(button('Zurück', 'quiet', render));
    }
    node.append(copy, form);
    if (config?.selfie?.enabled !== false) addStaffCamera(copy);
  }
  function addStaffCamera(parent) {
    const box = el('section', 'staff-camera');
    box.setAttribute('aria-label', 'Kameratest für Mitarbeiter');
    const video = el('video'); video.autoplay = true; video.muted = true; video.playsInline = true;
    video.setAttribute('aria-label', 'Miniatur-Kameravorschau');
    const info = el('div', 'staff-camera-info');
    const title = el('strong', '', 'Kamera freigeben');
    const hint = el('p', 'staff-camera-status'); hint.setAttribute('role', 'status');
    const retry = button('Kamera erneut prüfen', 'secondary', start);
    info.append(title, hint, retry); box.append(video, info); parent.append(box);
    let pending = false;
    async function start() {
      if (pending || document.hidden || !screen.contains(box)) return;
      clearCamera(); const epoch = cameraEpoch;
      pending = true; retry.disabled = true; retry.hidden = true;
      hint.textContent = 'Bitte den Kamerazugriff erlauben. Nur Vorschau, keine Aufnahme.';
      try {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('CAMERA');
        const media = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 960}}, audio: false});
        if (epoch !== cameraEpoch) { media.getTracks().forEach(track => track.stop()); return; }
        stream = media; video.srcObject = media; await video.play();
        if (epoch !== cameraEpoch) return;
        hint.textContent = 'Kamera bereit. Es wird kein Bild gespeichert oder übertragen.';
      } catch (_) {
        if (epoch !== cameraEpoch) return;
        clearCamera(); video.srcObject = null;
        hint.textContent = 'Kamera nicht freigegeben oder nicht verfügbar. Bitte die Berechtigung prüfen. Die Anmeldung ist trotzdem möglich.';
        retry.hidden = false; retry.disabled = false;
      } finally { pending = false; }
    }
    start();
  }
  function confirmStaffLogout() {
    if (busy || staffConfirmation || state.stage !== 'idle' || staff.hidden) return;
    const dialog = el('dialog', 'staff-dialog');
    const title = el('h2'); title.id = 'staff-confirm-title';
    const warning = el('p', '', 'Das Gerät ist dann nicht mehr benutzbar, wirklich nur für Mitarbeiter!');
    warning.id = 'staff-confirm-warning';
    const detail = el('p', 'small-note', 'Bis zur erneuten Mitarbeiteranmeldung ist kein Check-in möglich.');
    dialog.setAttribute('aria-labelledby', title.id); dialog.setAttribute('aria-describedby', warning.id);
    const actions = el('div', 'actions'); let step = 1;
    const cancel = button('Abbrechen', 'secondary', () => dismiss(true));
    const next = button('Ja, weiter', 'primary', () => {
      if (staffConfirmation?.dialog !== dialog) return;
      if (step < 3) { step++; update(); cancel.focus(); }
      else { dismiss(false); perform('logout', {confirmations: 3}); }
    });
    function update() { title.textContent = `Bestätigung ${step}/3: Wirklich abmelden?`; next.textContent = step === 3 ? 'Ja, Gerät abmelden' : 'Ja, weiter'; }
    function dismiss(resume) {
      if (staffConfirmation?.dialog !== dialog) return;
      staffConfirmation = null; dialog.close(); dialog.remove();
      if (resume) { armIdle(); staff.focus(); }
    }
    actions.append(cancel, next); dialog.append(title, warning, detail, actions); screen.append(dialog);
    staffConfirmation = {dialog, dismiss}; clearTimeout(idleTimer); update();
    dialog.addEventListener('cancel', event => { event.preventDefault(); dismiss(true); });
    dialog.showModal(); cancel.focus();
  }
  function renderIdle() {
    staff.hidden = false;
    const node = page(); node.classList.add('hero-grid');
    const copy = el('div', 'hero-copy');
    heading(copy, 'Schön, dass Sie da sind', config.title, message('insert_card'));
    copy.append(button(message('read_button'), 'primary wide', () => perform('begin')));
    const art = el('div', 'hero-art'); art.setAttribute('aria-hidden', 'true');
    const card = el('div', 'card-illustration'); card.append(el('div', 'card-chip'), el('div', 'card-line'), el('div', 'card-line short'));
    art.append(card, el('div', 'step-label', '↓')); node.append(copy, art);
  }
  function renderChoice() {
    const node = page(true); const category = config.categories[state.initial_category];
    node.append(el('div', 'eyebrow', 'Ihr Anliegen'), el('h1', 'question', category.question));
    const options = el('div', 'options');
    for (const key of ['acute', 'card_only', 'other']) {
      options.append(button(config.categories[key].label, 'choice', () => perform('choose', {choice: key})));
    }
    node.append(options); addCancel(node);
  }
  function renderContacts() {
    naturalScrollMode = true; document.documentElement.classList.add('natural-scroll');
    screen.classList.add('contacts-screen');
    const node = page(); node.classList.add('contacts-page'); const view = state.contacts;
    heading(node, '', view.new ? config.contacts.new_question : config.contacts.question);
    const form = el('form', 'form contact-form'); form.autocomplete = 'off'; form.noValidate = true;
    const fields = el('div', 'contact-fields');
    const grid = el('div', 'contact-grid'); const inputs = new Map(), editors = new Map();
    const keypad = el('section', 'phone-keypad'); keypad.hidden = true;
    keypad.setAttribute('aria-label', 'Telefontastatur');
    const keypadTitle = el('h2');
    const keys = el('div', 'phone-keys');
    let phone = null;
    function closePhone() {
      if (!phone) return;
      phone.classList.remove('phone-active'); phone = null;
      keypad.hidden = true; address.hidden = false;
    }
    function enterPhone(key) {
      if (!phone || phone.disabled) return;
      const start = phone.selectionStart ?? phone.value.length, end = phone.selectionEnd ?? start;
      const from = key === 'delete' && start === end ? Math.max(0, start - 1) : start;
      const inserted = key === 'delete' ? '' : key;
      const value = phone.value.slice(0, from) + inserted + phone.value.slice(end);
      if (value.length > phone.maxLength || !/^\+?[0-9]*$/.test(value)) return;
      phone.value = value; phone.setSelectionRange(from + inserted.length, from + inserted.length); activity();
    }
    for (const key of ['1','2','3','4','5','6','7','8','9','+','0','delete']) {
      const control = button(key === 'delete' ? '⌫' : key, 'secondary', () => enterPhone(key));
      control.setAttribute('aria-label', key === 'delete' ? 'Ziffer löschen' : key);
      // Keep the input selection without requesting the system keyboard.
      control.addEventListener('pointerdown', event => event.preventDefault());
      keys.append(control);
    }
    keypad.append(keypadTitle, keys, button('Fertig', 'secondary', () => { closePhone(); next.focus(); }));
    for (const row of view.rows) {
      const item = el('div', 'contact-row');
      const label = el('label', '', row.label);
      const input = el('input'); input.type = row.type; input.inputMode = row.type;
      input.id = 'contact-' + inputs.size; label.setAttribute('for', input.id);
      input.name = row.key; input.autocomplete = 'off'; input.autocapitalize = 'none'; input.spellcheck = false;
      input.maxLength = row.type === 'tel' ? 50 : 150;
      input.placeholder = row.known ? 'Neuer Wert bei Änderung' : 'Freiwillige Angabe';
      if (row.type === 'tel') {
        // inputmode alone is not reliable on iPadOS. Readonly prevents its keyboard;
        // the local keypad edits only this blank/new value, never the masked original.
        input.readOnly = true; input.inputMode = 'none';
        input.addEventListener('focus', () => {
          closePhone(); phone = input; input.classList.add('phone-active');
          keypadTitle.textContent = row.label; address.hidden = true; keypad.hidden = false;
        });
        input.addEventListener('click', () => {
          if (phone !== input) input.dispatchEvent(new Event('focus'));
        });
        input.addEventListener('keydown', event => {
          if (/^[0-9+]$/.test(event.key) || event.key === 'Backspace') {
            event.preventDefault(); enterPhone(event.key === 'Backspace' ? 'delete' : event.key);
          } else if (event.key === 'Escape' || event.key === 'Enter') {
            event.preventDefault(); closePhone(); next.focus();
          }
        });
      } else { input.addEventListener('focus', closePhone); }
      item.append(label);
      if (row.known) {
        const summary = el('div', 'contact-summary');
        const value = el('strong', 'masked-value', row.masked);
        const correct = button('Korrigieren', 'secondary contact-correct', () => {
          setEditing(input.hidden);
          if (!input.hidden) input.focus();
        });
        correct.setAttribute('aria-label', row.label + ' korrigieren');
        correct.setAttribute('aria-controls', input.id);
        function setEditing(editing) {
          input.hidden = !editing;
          correct.textContent = editing ? 'Verwerfen' : 'Korrigieren';
          correct.setAttribute('aria-label', row.label + (editing ? ': Korrektur verwerfen' : ' korrigieren'));
          correct.setAttribute('aria-expanded', String(editing));
          if (!editing) { input.value = ''; if (phone === input) closePhone(); }
        }
        setEditing(false); editors.set(row.key, setEditing);
        summary.append(value, correct); item.append(summary);
      }
      item.append(input); grid.append(item); inputs.set(row.key, input);
    }
    // Ordinary document flow: every contact stays on this page and can be scrolled.
    fields.append(grid);
    const address = el('section', 'address-check');
    address.append(el('h2', '', config.contacts.address_question));
    const addressBlock = el('div', 'postal-address');
    if (view.name) addressBlock.append(el('p', 'patient-name', view.name));
    addressBlock.append(el('p', '', view.address_lines?.join('\n') || view.address || 'Keine Adresse hinterlegt'));
    address.append(addressBlock);
    let addressOk = '';
    const correction = el('textarea'); correction.name = 'address_correction'; correction.maxLength = 500;
    correction.placeholder = 'Korrekte Adresse (freiwillig)'; correction.autocomplete = 'off'; correction.hidden = true;
    correction.setAttribute('aria-label', 'Korrekte Adresse');
    const correct = button('Adresse korrigieren', 'secondary address-correct', () => {
      addressOk = addressOk === 'no' ? '' : 'no'; correction.hidden = addressOk !== 'no';
      correct.setAttribute('aria-expanded', String(!correction.hidden));
      correct.textContent = correction.hidden ? 'Adresse korrigieren' : 'Korrektur verwerfen';
      if (correction.hidden) correction.value = '';
      else correction.focus();
    });
    correction.id = 'address-correction'; correct.setAttribute('aria-controls', correction.id); correct.setAttribute('aria-expanded', 'false');
    address.append(correct, correction);
    const error = el('p', 'inline-error'); error.setAttribute('role', 'alert');
    const actions = el('div', 'contact-actions');
    const next = el('button', 'button primary wide', 'Weiter'); next.type = 'submit';
    actions.append(el('p', 'small-note', config.contacts.hint), error, next); addCancel(actions);
    const middle = el('div', 'contact-middle'); middle.append(address, keypad);
    form.append(fields, middle, actions);
    form.addEventListener('submit', event => {
      event.preventDefault();
      for (const [key, input] of inputs) {
        if (!input.checkValidity()) { editors.get(key)?.(true); input.reportValidity(); return; }
      }
      const values = Object.fromEntries([...inputs].map(([key, input]) => [key, input.value]));
      perform('contacts', {values, address_ok: addressOk, address_correction: correction.value}, null, error);
    });
    node.append(form);
  }
  function renderNote() {
    naturalScrollMode = true; document.documentElement.classList.add('natural-scroll');
    const node = page(true); node.classList.add('note-page');
    node.append(el('div', 'eyebrow', 'Ihr Anliegen'), el('h1', 'question', config.categories[state.category].question));
    const form = el('form', 'form note-form');
    const input = el('input'); input.type = 'text'; input.name = 'note'; input.maxLength = config.noteMaxLength;
    input.placeholder = 'Optional – Sie können auch direkt weitergehen.';
    input.setAttribute('aria-label', config.categories[state.category].question); input.autocomplete = 'off';
    const count = el('div', 'count', `0 / ${config.noteMaxLength}`);
    input.addEventListener('input', () => { count.textContent = `${input.value.length} / ${config.noteMaxLength}`; activity(); });
    const error = el('p', 'inline-error'); error.setAttribute('role', 'alert');
    const actions = el('div', 'actions');
    const next = el('button', 'button primary', 'Weiter'); next.type = 'submit'; actions.append(next);
    form.append(input, count, error, actions);
    form.addEventListener('submit', event => { event.preventDefault(); perform('note', {note: input.value}, null, error); });
    node.append(form); addCancel(node);
  }
  function renderSelfie() {
    const node = page(true);
    completionNotice(node);
    heading(node, 'Ein Foto für Ihre Akte', 'Dürfen wir ein Foto aufnehmen?');
    node.append(el('p', 'question', config.selfie.question));
    const actions = el('div', 'actions');
    actions.append(button('Ja, gerne', 'primary', () => perform('selfie', {answer: 'yes'})), button('Nein, danke', 'secondary', () => perform('selfie', {answer: 'no'})));
    node.append(actions);
  }
  function completionNotice(node) {
    if (!state.checkin_complete || !state.message) return;
    const notice = el('div', 'completion-notice');
    notice.append(el('div', 'eyebrow', 'Anmeldung abgeschlossen'), el('p', '', state.message));
    node.append(notice);
  }
  function iconButton(label, path, handler, primary = false) {
    const node = el('button', 'icon-button' + (primary ? ' primary' : '')); node.type = 'button';
    node.setAttribute('aria-label', label); node.title = label;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
    const shape = document.createElementNS(svg.namespaceURI, 'path'); shape.setAttribute('d', path); svg.append(shape); node.append(svg);
    node.addEventListener('click', handler); return node;
  }
  async function renderCamera() {
    screen.classList.add('photo-screen');
    const wrapper = el('section', 'photo-page'); screen.append(wrapper);
    const node = el('div', 'camera-layout preview-' + config.selfie.preview_side); wrapper.append(node);
    const controls = el('div', 'camera-controls'); const viewport = el('div', 'camera-viewport');
    const video = el('video'); video.autoplay = true; video.muted = true; video.playsInline = true; video.setAttribute('aria-label', 'Kameravorschau');
    const guide = el('div', 'capture-guide');
    guide.style.inset = ((100 - config.selfie.frame_height_percent) / 2) + '%';
    viewport.append(video, guide);
    const frame = el('div', 'camera-frame'); frame.append(viewport);
    if (config.selfie.lens_arrow !== 'off') {
      const direction = config.selfie.lens_arrow;
      const arrow = el('div', 'lens-arrow arrow-' + direction, {left:'←', right:'→', top:'↑'}[direction]);
      arrow.setAttribute('aria-label', 'Hier ist die Kameralinse'); frame.append(arrow);
    }
    const capture = el('button', 'icon-button capture-button'); capture.type = 'button'; capture.disabled = true; capture.setAttribute('aria-label', 'Foto aufnehmen');
    const captureActions = el('div', 'capture-actions');
    captureActions.append(capture, el('p', 'capture-instruction', config.selfie.capture_text));
    controls.append(frame, captureActions);
    const copy = el('div', 'camera-copy'); completionNotice(copy); heading(copy, 'Ihr Foto', config.selfie.frame_text);
    const hint = el('p', 'camera-help', 'Wenn Ihr Gesicht gut im Rahmen zu sehen ist, tippen Sie auf den Auslöser.');
    copy.append(hint, button('Ohne Foto weiter', 'quiet', () => perform('selfie', {answer: 'no'})));
    node.append(controls, copy);
    const epoch = cameraEpoch;
    try {
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('CAMERA');
      const media = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 960}}, audio: false});
      if (epoch !== cameraEpoch) { media.getTracks().forEach(track => track.stop()); return; }
      stream = media; video.srcObject = stream; await video.play();
      if (epoch !== cameraEpoch) return;
      capture.disabled = !(video.videoWidth > 0 && video.videoHeight > 0);
      if (capture.disabled) video.addEventListener('loadeddata', () => { capture.disabled = false; }, {once: true});
    } catch (_) {
      if (epoch === cameraEpoch) { hint.textContent = message('camera_error'); capture.hidden = true; }
    }
    capture.addEventListener('click', () => {
      if (!video.videoWidth || !video.videoHeight) return;
      source = document.createElement('canvas'); source.width = video.videoWidth; source.height = video.videoHeight;
      const ctx = source.getContext('2d'); ctx.translate(source.width, 0); ctx.scale(-1, 1); ctx.drawImage(video, 0, 0);
      stream?.getTracks().forEach(track => track.stop()); stream = null; video.srcObject = null;
      renderEditor(node); activity();
    });
  }
  function renderEditor(node) {
    node.replaceChildren();
    const controls = el('div', 'camera-controls'); const viewport = el('div', 'camera-viewport');
    const canvas = el('canvas'); canvas.width = canvas.height = config.selfie.output_size; canvas.setAttribute('aria-label', 'Bildausschnitt mit einem Finger verschieben und mit zwei Fingern zoomen');
    viewport.append(canvas);
    const base = Math.max(canvas.width / source.width, canvas.height / source.height);
    // Use exactly the configured live guide, within the unchanged square preview.
    crop = {canvas, scale: base / (config.selfie.frame_height_percent / 100), min: base, max: base * 6, x: 0, y: 0};
    crop.x = (canvas.width - source.width * crop.scale) / 2; crop.y = (canvas.height - source.height * crop.scale) / 2;
    const buttons = el('div', 'camera-buttons');
    buttons.append(iconButton('Foto erneut aufnehmen', 'M3 10a9 9 0 1 1 2 8M3 4v6h6', render),
      iconButton('Diesen Bildausschnitt verwenden', 'm5 12 4 4L19 6', () => {
        canvas.toBlob(blob => { if (blob) perform('upload', {}, blob); else renderError(new Error('Das Foto konnte nicht verarbeitet werden.')); }, 'image/jpeg', config.selfie.jpeg_quality_percent / 100);
      }, true));
    const frame = el('div', 'camera-frame'); frame.append(viewport);
    const captureActions = el('div', 'capture-actions'); captureActions.append(buttons);
    controls.append(frame, captureActions);
    const copy = el('div', 'camera-copy'); completionNotice(copy); heading(copy, 'Fast fertig', 'So passt Ihr Foto.');
    copy.append(el('p', 'camera-help', config.selfie.adjust_text), el('p', 'small-note', 'Tippen Sie auf das Häkchen, um diesen Ausschnitt zu übernehmen.'), button('Ohne Foto weiter', 'quiet', () => perform('selfie', {answer: 'no'})));
    node.append(controls, copy); paint();
    const point = event => { const rect = canvas.getBoundingClientRect(); return {x: (event.clientX - rect.left) * canvas.width / rect.width, y: (event.clientY - rect.top) * canvas.height / rect.height}; };
    canvas.addEventListener('pointerdown', event => {
      if (busy) return; event.preventDefault(); canvas.setPointerCapture(event.pointerId); pointers.set(event.pointerId, point(event)); gesture = gestureSnapshot(); activity();
    });
    canvas.addEventListener('pointermove', event => {
      if (!pointers.has(event.pointerId) || !crop || !gesture) return;
      event.preventDefault(); pointers.set(event.pointerId, point(event));
      const points = [...pointers.values()];
      if (points.length === 1 && gesture.points.length === 1) {
        crop.x = gesture.x + points[0].x - gesture.points[0].x; crop.y = gesture.y + points[0].y - gesture.points[0].y;
      } else if (points.length >= 2 && gesture.points.length >= 2) {
        const mid = midpoint(points), oldMid = midpoint(gesture.points);
        const scale = Math.max(crop.min, Math.min(crop.max, gesture.scale * distance(points) / Math.max(1, distance(gesture.points))));
        const ratio = scale / gesture.scale; crop.x = mid.x - (oldMid.x - gesture.x) * ratio; crop.y = mid.y - (oldMid.y - gesture.y) * ratio; crop.scale = scale;
      }
      paint();
    });
    const release = event => { pointers.delete(event.pointerId); gesture = gestureSnapshot(); activity(); };
    canvas.addEventListener('pointerup', release); canvas.addEventListener('pointercancel', release);
    canvas.addEventListener('lostpointercapture', release);
    canvas.addEventListener('gesturestart', event => event.preventDefault());
    canvas.addEventListener('gesturechange', event => event.preventDefault());
  }
  const midpoint = points => ({x: (points[0].x + points[1].x) / 2, y: (points[0].y + points[1].y) / 2});
  const distance = points => Math.hypot(points[1].x - points[0].x, points[1].y - points[0].y);
  function gestureSnapshot() { return crop ? {points: [...pointers.values()].map(p => ({...p})), x: crop.x, y: crop.y, scale: crop.scale} : null; }
  function paint() {
    if (!crop || !source) return;
    const size = crop.canvas.width;
    crop.x = Math.max(size - source.width * crop.scale, Math.min(0, crop.x));
    crop.y = Math.max(size - source.height * crop.scale, Math.min(0, crop.y));
    const ctx = crop.canvas.getContext('2d'); ctx.clearRect(0, 0, size, size);
    ctx.drawImage(source, crop.x, crop.y, source.width * crop.scale, source.height * crop.scale);
  }
  function renderDone() {
    const node = page(true); node.append(el('div', 'success-icon', '✓'));
    heading(node, '', message('done_title'), state.message);
    const finish = button('Fertig', 'primary wide', () => perform('reset'));
    node.append(el('p', 'small-note', message('remove_card')), finish);
    // Server-supplied remaining time prevents a reload from restarting the countdown.
    const deadline = Date.now() + (state.completionRemaining ?? config.completionSeconds) * 1000;
    const tick = () => {
      const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      finish.textContent = `Fertig (${remaining})`;
      if (remaining === 0 && staffConfirmation) { completionTimer = setTimeout(tick, 1000); }
      else if (remaining === 0) { completionTimer = setTimeout(() => { if (staffConfirmation) tick(); else perform('reset'); }, 0); }
      else { completionTimer = setTimeout(tick, Math.min(1000, deadline - Date.now())); }
    };
    tick();
  }
  function renderError(error) {
    clearScreen(); const node = page(true);
    if (state.checkin_complete) {
      completionNotice(node);
      if (state.error_area === 'privacy') {
        heading(node, '', 'Datenschutzformular nicht bestätigt', 'Ihre Anmeldung ist abgeschlossen. Bitte melden Sie sich wegen des Datenschutzformulars am Empfang. Eine unklare Übertragung wird nicht automatisch wiederholt.');
      } else if (state.error_area === 'questionnaire') {
        heading(node, '', 'Fragebogen nicht vollständig bestätigt', 'Ihre Anmeldung ist abgeschlossen. Bitte melden Sie sich wegen der Fragebogenübernahme am Empfang. Die Übertragung wird nicht automatisch wiederholt.');
      } else {
        heading(node, '', 'Foto nicht bestätigt', message('photo_error') || 'Ihre Anmeldung ist abgeschlossen. Das Foto konnte nicht bestätigt werden. Bitte bei Bedarf am Empfang nachfragen.');
      }
    } else {
      node.append(el('div', 'status-symbol', '!'));
      heading(node, '', 'Bitte zum Empfang', message('reception_error') || 'Die Verbindung ist unterbrochen. Bitte melden Sie sich am Empfang.');
    }
    node.append(el('p', 'small-note', message('remove_card')));
    const code = error?.code || state.error_code;
    const diagnostic = error?.diagnostic || state.error_diagnostic;
    if (code) {
      let hint = 'Hinweis: ' + code;
      if (diagnostic?.operation) hint += ' · ' + diagnostic.operation + ' · HTTP ' + diagnostic.http_status;
      if (diagnostic?.reason) hint += ' · ' + diagnostic.reason;
      node.append(el('p', 'technical-code', hint));
      if (diagnostic?.report_id) node.append(el('p', 'technical-code', 'Prüfkennung: ' + diagnostic.report_id));
      if (diagnostic?.report_storage === 'UNAVAILABLE') node.append(el('p', 'technical-code', 'Diagnosekopie nicht verfügbar.'));
    }
    if (state.stage === 'login' || ['LOGIN_REQUIRED', 'T2_AUTH', 'SESSION_CRYPTO'].includes(code)) {
      node.append(button('Mitarbeiteranmeldung', 'secondary', () => renderLogin('Die Geräteanmeldung ist abgelaufen oder nicht mehr gültig. Bitte erneut anmelden.')));
    } else {
      node.append(button(message('next_card_button') || 'Nächste Karte', 'primary wide', () => perform('next')));
    }
  }
  function addCancel(node) { node.append(button('Anmeldung abbrechen', 'quiet', () => perform('reset'))); }
  function render() {
    clearScreen(); document.getElementById('version').textContent = 'Version ' + (config?.version || '1.6.4');
    if (!config || state.stage === 'login') renderLogin();
    else if (state.stage === 'idle') renderIdle();
    else if (state.stage === 'contacts') renderContacts();
    else if (state.stage === 'privacy') { location.replace('privacy.php'); return; }
    else if (state.stage === 'questionnaire') { location.replace('questionnaire.php'); return; }
    else if (state.stage === 'choice') renderChoice();
    else if (state.stage === 'note') renderNote();
    else if (state.stage === 'selfie') renderSelfie();
    else if (state.stage === 'camera') renderCamera();
    else if (state.stage === 'done') renderDone();
    else renderError();
    if (!naturalScrollMode) { viewportHeight(); screen.focus({preventScroll: true}); }
    armIdle();
  }
  function armIdle() {
    clearTimeout(idleTimer);
    if (staffConfirmation) return;
    if (config && ['contacts', 'choice', 'note', 'selfie', 'camera'].includes(state.stage)) {
      idleTimer = setTimeout(() => { if (!busy) perform('reset'); }, config.timeout * 1000);
    }
  }
  function activity() {
    if (busy || staffConfirmation || !['contacts', 'choice', 'note', 'selfie', 'camera'].includes(state.stage)) return;
    armIdle();
    if (Date.now() - activityTimer > 20000) {
      activityTimer = Date.now();
      request('touch').catch(error => {
        if (!busy && ['contacts', 'choice', 'note', 'selfie', 'camera'].includes(state.stage) && !['FLOW_ID', 'FLOW_STEP'].includes(error.code)) renderError(error);
      });
    }
  }
  function viewportHeight() {
    if (naturalScrollMode) return; // Login, contacts and reason text use native scrolling/keyboard handling.
    const viewport = window.visualViewport, height = viewport?.height || window.innerHeight;
    const zoomed = Math.abs((viewport?.scale || 1) - 1) > .02;
    const wasOpen = keyboardOpen;
    if (viewportWidth !== window.innerWidth) {
      viewportWidth = window.innerWidth; fullViewportHeight = window.innerHeight;
    }
    fullViewportHeight = Math.max(fullViewportHeight, window.innerHeight, zoomed ? 0 : height);
    const compressed = fullViewportHeight - height > Math.max(100, fullViewportHeight * .15);
    if (!zoomed) keyboardOpen = compressed && (wasOpen || !!editableInput());
    document.documentElement.style.setProperty('--view-height', `${height}px`);
    // A late Safari offset must not leave the closed-keyboard layout displaced.
    // Keep intentional page zoom separate from keyboard detection/restoration.
    document.documentElement.style.setProperty('--view-top', `${keyboardOpen || zoomed ? viewport?.offsetTop || 0 : 0}px`);
    if (wasOpen && !keyboardOpen && !zoomed) restoreViewport();
    else if (keyboardOpen && !zoomed) keepInputVisible();
  }
  function editableInput() {
    const input = document.activeElement;
    return input && screen.contains(input) && input.matches('input:not([type="hidden"]),textarea')
      && !input.readOnly && !input.disabled ? input : null;
  }
  function restoreViewport() {
    const epoch = ++viewportEpoch;
    clearTimeout(viewportResetTimer);
    const restore = () => {
      if (naturalScrollMode || epoch !== viewportEpoch || keyboardOpen || Math.abs((window.visualViewport?.scale || 1) - 1) > .02) return;
      document.documentElement.style.setProperty('--view-top', '0px');
      screen.scrollTop = 0; screen.scrollLeft = 0;
      const shell = document.querySelector('.app-shell');
      if (shell) { shell.scrollTop = 0; shell.scrollLeft = 0; }
      window.scrollTo(0, 0);
    };
    window.requestAnimationFrame?.(restore);
    // iPadOS may finish its focus-pan after the resize event. One bounded final
    // reset catches that; a new focus or page invalidates both old callbacks.
    viewportResetTimer = setTimeout(restore, 250);
  }
  function keepInputVisible() {
    if (naturalScrollMode) return;
    const epoch = viewportEpoch;
    window.requestAnimationFrame?.(() => {
      if (naturalScrollMode || epoch !== viewportEpoch || Math.abs((window.visualViewport?.scale || 1) - 1) > .02) return;
      const input = editableInput();
      if (input) {
        input.scrollIntoView({block:'nearest',inline:'nearest'});
      }
    });
  }
  screen.addEventListener('focusin', () => {
    viewportEpoch++; clearTimeout(viewportResetTimer); keepInputVisible();
  });
  staff.addEventListener('click', confirmStaffLogout);
  document.addEventListener('pointerdown', activity, {passive: true});
  document.addEventListener('keydown', activity, {passive: true});
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearCamera(); }
    else if (!busy) request(null).then(render).catch(renderError);
  });
  window.addEventListener('pagehide', clearScreen);
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  window.visualViewport?.addEventListener('resize', viewportHeight);
  window.visualViewport?.addEventListener('scroll', viewportHeight);
  window.addEventListener('resize', viewportHeight); viewportHeight();
  request(null).then(render).catch(error => renderLogin(error.message));
})();
