'use strict';
// Focused DOM/fetch/timer doubles, not a browser/iPad test. No network or camera access.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/app.js'), 'utf8');

class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; this.textContent = ''; this.value = ''; this.required = false; this.style = {}; this.classList = {add() {}, remove() {}}; this.scrollTop = 0; this.scrollLeft = 0; this.scrollCalls = 0; }
  append(...nodes) { this.children.push(...nodes); nodes.forEach(node => node.parent = this); }
  replaceChildren(...nodes) { this.children = nodes; }
  addEventListener(type, handler) { this.listeners[type] = handler; }
  setAttribute() {}
  removeAttribute() {}
  querySelectorAll() { return []; }
  contains(node) { return this === node || this.children.some(child => child.contains(node)); }
  matches() { return this.tag === 'textarea' || (this.tag === 'input' && this.type !== 'hidden'); }
  scrollIntoView() { this.scrollCalls++; }
  focus() {}
  checkValidity() { return true; }
  reportValidity() {}
  showModal() { this.open = true; }
  close() { this.open = false; }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); }
  async play() { this.videoWidth = 1280; this.videoHeight = 960; }
}
async function fixture(code = 'REST_JSON', initialState = null, options = {}) {
  const screen = new Element('main'), staff = new Element('button'), version = new Element('span'), shell = new Element('div');
  const config = {version: '1.6.4', title: 'TEST', messages: {next_card_button: 'Nächste Karte', read_button: 'Karte einlesen',
    reception_error: 'CHECKIN_FAILED', photo_error: 'PHOTO_FAILED_ONLY', remove_card: 'Bitte Karte entfernen'}, timeout: 180,
    completionSeconds: 15, noteMaxLength: 250, categories: {acute: {question: 'WHY_ACUTE'}, other: {question: 'WHY_OTHER'}},
    contacts: {question:'CONTACT_CHECK', new_question:'NEW_CONTACT', hint:'OPTIONAL', address_question:'ADDRESS_CHECK'},
    selfie: {question: 'PHOTO_QUESTION', frame_text: 'Bitte Gesicht in den Rahmen', preview_side: 'right', lens_arrow: 'right', frame_height_percent: 100, capture_text:'Bitte Auslöser drücken'}};
  Object.assign(config.selfie, options.selfie || {});
  const server = {state: initialState || {stage: 'blocked', id: 'old-flow', error_code: code}, actions: []};
  const cameraCalls = [], cameraTracks = [], redirects = [], documentEvents = {}, windowEvents = {};
  let now = 100000, timerId = 0;
  const timers = new Map();
  const viewportStyles = {}, viewportEvents = {}, frames = [], windowScrolls = [];
  const visualViewport = options.viewport ? {...options.viewport,addEventListener(name,callback){viewportEvents[name]=callback;}} : undefined;
  const document = {getElementById: id => ({screen, staff, version})[id],
    querySelector: selector => selector === '.app-shell' ? shell : null,
    createElement: tag => new Element(tag), createElementNS: (_ns, tag) => new Element(tag), hidden:false,
    addEventListener(name,handler) { documentEvents[name] = handler; }, documentElement: {classList:{add(){},remove(){}},style: {setProperty(name,value) {viewportStyles[name]=value;}}}};
  const window = {addEventListener(name,handler) { windowEvents[name] = handler; }, innerHeight: 768, innerWidth: 1024, isSecureContext: true, visualViewport,
    requestAnimationFrame: callback => frames.push(callback), scrollTo: (x,y) => windowScrolls.push([x,y])};
  vm.runInNewContext(source, {
    document, window,
    navigator: options.camera ? {mediaDevices: {getUserMedia: async constraints => {
      cameraCalls.push(constraints);
      const track = {stopped:false,stop() { this.stopped = true; }}; cameraTracks.push(track);
      const media = {getTracks: () => [track]};
      return options.cameraProvider ? options.cameraProvider(media) : media;
    }}} : {}, location: {replace: url => redirects.push(url)},
    Date: {now: () => now},
    setTimeout: (callback, delay) => { timers.set(++timerId, {callback, at: now + delay}); return timerId; },
    clearTimeout: id => timers.delete(id),
    fetch: async (_url, options) => {
      if (options.method === 'POST') {
        const input = JSON.parse(options.body); server.actions.push(input);
        assert.equal(input.flowId, server.state.id || '');
        if (input.action === 'logout') { assert.equal(input.confirmations,3); server.state = {stage:'login'}; if (options.lostLogoutReply) throw new Error('Lost response'); }
        else if (input.action === 'login') { server.state = {stage:'idle'}; }
        else if (input.action === 'note') { server.state = {stage: 'selfie', id: input.flowId, checkin_complete: true, message: 'CHECKIN_MESSAGE'}; }
        else if (['selfie', 'contacts'].includes(input.action)) { server.state = {stage: 'done', id: input.flowId, checkin_complete: true, message: 'CHECKIN_MESSAGE', completionRemaining: 15}; }
        else { assert.ok(['next', 'reset'].includes(input.action)); server.state = {stage: 'idle'}; }
      }
      return {ok: true, status: 200, json: async () => ({ok: true, csrf: 'csrf', config,
        state: {...server.state}})};
    },
  });
  await new Promise(setImmediate);
  const nodes = () => {
    const result = [];
    function walk(node) { result.push(node); node.children.forEach(walk); }
    walk(screen); return result;
  };
  const buttons = () => nodes().filter(node => node.tag === 'button');
  const advance = async milliseconds => {
    now += milliseconds;
    for (let i = 0; i < 100; i++) {
      const due = [...timers].find(([, timer]) => timer.at <= now);
      if (!due) return;
      timers.delete(due[0]); await due[1].callback(); await new Promise(setImmediate);
    }
    throw new Error('Timer loop');
  };
  const flushFrames = () => frames.splice(0).forEach(callback => callback());
  const focus = node => { document.activeElement = node; screen.listeners.focusin(); };
  return {server, buttons, nodes, advance, timers, cameraCalls, cameraTracks, redirects, viewportStyles, viewportEvents, visualViewport, staff, documentEvents, windowEvents,
    screen, shell, document, window, windowScrolls, flushFrames, focus};
}
(async () => {
  const normal = await fixture();
  assert.equal(normal.staff.hidden,true);
  assert.equal(normal.viewportStyles['--view-height'],'768px');assert.equal(normal.viewportStyles['--view-top'],'0px');
  const panned=await fixture('',null,{viewport:{height:380,offsetTop:120}});
  assert.equal(panned.viewportStyles['--view-height'],'380px');assert.equal(panned.viewportStyles['--view-top'],'0px');
  panned.visualViewport.offsetTop=0;panned.visualViewport.height=768;panned.viewportEvents.resize();panned.viewportEvents.scroll();
  assert.equal(panned.viewportStyles['--view-height'],'768px');assert.equal(panned.viewportStyles['--view-top'],'0px');
  assert.deepEqual(normal.buttons().map(button => button.textContent), ['Nächste Karte']);
  await normal.buttons()[0].listeners.click();
  assert.deepEqual(normal.buttons().map(button => button.textContent), ['Karte einlesen']);
  assert.equal(normal.server.actions.length, 1);
  assert.equal(normal.server.actions[0].action, 'next');
  assert.equal(normal.staff.hidden,false,'Staff control appears only on card-start page');
  console.log('OK: Fehlerbildschirm -> Nächste Karte -> Kartenstart, ohne Login oder automatisches Lesen');

  const stale = await fixture();
  stale.server.state = {stage: 'blocked', id: 'newer-flow', error_code: 'TEST_NEWER_ERROR'};
  await stale.buttons()[0].listeners.click();
  assert.equal(stale.server.actions.length, 0);
  assert.equal(stale.server.state.id, 'newer-flow');
  console.log('OK: Veralteter Knopf verwirft nach Zustandsabgleich keinen neueren Vorgang');

  const expired = await fixture('T2_AUTH');
  assert.deepEqual(expired.buttons().map(button => button.textContent), ['Mitarbeiteranmeldung']);
  console.log('OK: Tatsächlich ungültige T2med-Anmeldung verlangt weiterhin Login');

  for (const cancelAt of [1,2,3]) {
    const f = await fixture('',{stage:'idle'},{camera:true});
    f.staff.listeners.click();
    for (let step=1;step<cancelAt;step++) f.buttons().find(b=>b.textContent==='Ja, weiter').listeners.click();
    assert.ok(f.nodes().some(n=>n.textContent===`Bestätigung ${cancelAt}/3: Wirklich abmelden?`));
    f.buttons().find(b=>b.textContent==='Abbrechen').listeners.click();
    assert.equal(f.server.actions.length,0);assert.equal(f.server.state.stage,'idle');
    assert.equal(f.cameraCalls.length,0);assert.ok(!f.nodes().some(n=>n.tag==='dialog'));
  }
  const cancelInput = await fixture('',{stage:'note',id:'note-flow',category:'acute'});
  const draft = cancelInput.nodes().find(n=>n.name==='note');draft.value='Entwurf bleibt';
  assert.equal(cancelInput.staff.hidden,true);cancelInput.staff.listeners.click();
  assert.ok(!cancelInput.nodes().some(n=>n.tag==='dialog'),'No logout dialog outside idle, including direct event calls');
  assert.equal(cancelInput.nodes().find(n=>n.name==='note'),draft);assert.equal(draft.value,'Entwurf bleibt');
  for (const lostLogoutReply of [false,true]) {
    const f = await fixture('',{stage:'idle'},{camera:true,lostLogoutReply});
    f.staff.listeners.click();f.staff.listeners.click();assert.equal(f.nodes().filter(n=>n.tag==='dialog').length,1);
    f.buttons().find(b=>b.textContent==='Ja, weiter').listeners.click();
    f.buttons().find(b=>b.textContent==='Ja, weiter').listeners.click();assert.equal(f.server.actions.length,0);
    f.buttons().find(b=>b.textContent==='Ja, Gerät abmelden').listeners.click();await new Promise(setImmediate);
    assert.deepEqual(f.server.actions.map(a=>a.action),['logout']);assert.equal(f.server.state.stage,'login');
    assert.equal(f.staff.hidden,true);
    assert.equal(f.cameraCalls.length,1);assert.equal(f.cameraCalls[0].audio,false);
    assert.equal(f.cameraTracks[0].stopped,false);assert.ok(f.nodes().some(n=>n.textContent.startsWith('Kamera bereit.')));
    f.staff.listeners.click();assert.equal(f.cameraCalls.length,1,'Staff click on login does not restart permission request');
    f.nodes().find(n=>n.name==='username').value='TEST_USER';
    f.nodes().find(n=>n.tag==='form').listeners.submit({preventDefault(){}});await new Promise(setImmediate);
    assert.equal(f.server.state.stage,'idle');assert.equal(f.cameraTracks[0].stopped,true,'Login stops preview');
    assert.equal(f.staff.hidden,false);
    assert.deepEqual(f.server.actions.map(a=>a.action),['logout','login'],'No image upload or automatic replay');
  }
  const off = await fixture('',{stage:'login'},{camera:true,selfie:{enabled:false}});
  assert.equal(off.cameraCalls.length,0,'Disabled photo feature never asks for camera');
  let resolveCamera;
  const late = await fixture('',{stage:'login'},{camera:true,cameraProvider:media=>new Promise(resolve=>{resolveCamera=()=>resolve(media);})});
  late.windowEvents.pagehide();resolveCamera();await new Promise(setImmediate);
  assert.equal(late.cameraTracks[0].stopped,true,'Late permission result after leaving is stopped');
  const hidden = await fixture('',{stage:'login'},{camera:true});hidden.document.hidden=true;hidden.documentEvents.visibilitychange();
  assert.equal(hidden.cameraTracks[0].stopped,true,'Hidden page does not keep camera running');
  const denied = await fixture('',{stage:'login'},{camera:true,cameraProvider:()=>Promise.reject(new Error('Denied'))});
  const retry = denied.buttons().find(b=>b.textContent==='Kamera erneut prüfen');assert.equal(retry.hidden,false);assert.equal(retry.disabled,false);
  assert.equal(denied.buttons().find(b=>b.textContent==='Gerät anmelden').disabled,undefined,'Denied camera does not block staff login');
  assert.equal(denied.server.actions.length,0);
  console.log('OK: Mitarbeiterkamera nur lokal, Ablehnung/Abbruch/Seitenwechsel; drei Abmeldebestätigungen, jeder Abbruch ohne Datenverlust, verlorene Antwort ohne Wiederholung');

  const keyboard = await fixture('', {stage:'login'}, {viewport:{height:768,offsetTop:0,scale:1}});
  const email = keyboard.nodes().find(node=>node.name==='username');
  email.value='erika@example.invalid';
  const loginViewport={...keyboard.viewportStyles};
  const resize = (height, offsetTop=0, scale=1) => {
    Object.assign(keyboard.visualViewport,{height,offsetTop,scale});keyboard.viewportEvents.resize();
  };
  keyboard.focus(email);keyboard.flushFrames();
  resize(380,120);keyboard.flushFrames();
  keyboard.screen.scrollTop=240;keyboard.shell.scrollTop=100;
  keyboard.viewportEvents.scroll();resize(768,120);keyboard.flushFrames();
  await keyboard.advance(250);
  assert.equal(keyboard.screen.scrollTop,240);assert.equal(keyboard.shell.scrollTop,100);
  assert.equal(email.scrollCalls,0);assert.equal(keyboard.windowScrolls.length,0);
  assert.equal(email.value,'erika@example.invalid','Input is preserved');
  resize(380,90,2);keyboard.flushFrames();
  assert.deepEqual(keyboard.viewportStyles,loginViewport,'No login viewport manipulation');
  assert.equal(keyboard.staff.hidden,true);
  assert.equal(keyboard.server.actions.length,0,'Viewport changes never submit data');
  console.log('OK: Mitarbeiterseite ohne Scroll-/Tastatur-Manipulation; Daten bleiben erhalten');

  const natural = await fixture('', {stage:'contacts',id:'native-contacts',contacts:{new:false,
    rows:[{key:'email',label:'E-Mail',known:false,type:'email'}]}}, {viewport:{height:768,offsetTop:0,scale:1}});
  const naturalEmail=natural.nodes().find(node=>node.type==='email');naturalEmail.value='test@example.invalid';
  natural.focus(naturalEmail);natural.screen.scrollTop=123;
  Object.assign(natural.visualViewport,{height:380,offsetTop:120});natural.viewportEvents.resize();natural.viewportEvents.scroll();
  natural.flushFrames();
  Object.assign(natural.visualViewport,{height:768,offsetTop:120});natural.viewportEvents.resize();natural.viewportEvents.scroll();
  natural.flushFrames();await natural.advance(300);
  assert.equal(naturalEmail.scrollCalls,0,'No scrollIntoView on contacts');
  assert.equal(natural.windowScrolls.length,0,'No programmatic document scrolling on contacts');
  assert.equal(natural.screen.scrollTop,123,'No automatic position reset on contacts');
  assert.equal(natural.viewportStyles['--view-height'],'768px','No contact viewport-height updates');
  assert.equal(natural.viewportStyles['--view-top'],'0px','No contact viewport-panning updates');
  assert.equal(naturalEmail.value,'test@example.invalid');assert.equal(natural.server.actions.length,0);
  console.log('OK: Kontaktseite ohne Tastatur-/Scroll-Manipulation; Eingaben erhalten');

  const contacts = await fixture('', {stage:'contacts', id:'contacts-flow', contacts:{new:false,
    name:'Erika Testperson', address:'Testweg 12 12345 Testort', address_lines:['Testweg 12','12345 Testort'], rows:[{key:'p0', label:'Mobilfunk', known:true, masked:'017*****567', type:'tel'},
      {key:'addEmail', label:'E-Mail', known:false, masked:'', type:'email'}]}});
  const contactInputs = contacts.nodes().filter(node => node.tag === 'input');
  assert.ok(contactInputs.every(node => node.value === '' && !node.required));
  assert.ok(contacts.nodes().some(node => node.tag === 'strong' && node.textContent === '017*****567'));
  assert.equal(contactInputs[0].hidden,true);assert.ok(!contactInputs[1].hidden);
  const phoneCorrection=contacts.buttons().find(node=>node.textContent==='Korrigieren');
  await phoneCorrection.listeners.click();assert.equal(contactInputs[0].hidden,false);
  contactInputs[0].value='0123456';
  await phoneCorrection.listeners.click();assert.equal(contactInputs[0].hidden,true);assert.equal(contactInputs[0].value,'');
  assert.ok(contacts.nodes().some(node => node.textContent === 'Testweg 12\n12345 Testort'));
  assert.ok(contacts.nodes().some(node => node.textContent === 'Erika Testperson'));
  assert.ok(!contacts.nodes().some(node => ['Ihre Kontaktdaten', 'Ja, stimmt'].includes(node.textContent)));
  const css = fs.readFileSync(path.join(__dirname, '../public/assets/app.css'), 'utf8');
  assert.match(css, /\.contact-grid\{display:grid;grid-template-columns:1fr;/);
  const correction = contacts.nodes().find(node => node.name === 'address_correction');
  assert.equal(correction.hidden, true);
  await contacts.buttons().find(node => node.textContent === 'Adresse korrigieren').listeners.click();
  assert.equal(correction.hidden, false); assert.equal(correction.required, false);
  await contacts.nodes().find(node => node.tag === 'form').listeners.submit({preventDefault() {}});
  await new Promise(setImmediate);
  assert.deepEqual(contacts.server.actions[0].values, {p0:'', addEmail:''});
  assert.equal(contacts.server.actions[0].address_ok, 'no');
  assert.equal(contacts.server.actions[0].address_correction, '');
  console.log('OK: Hervorgehobene maskierte Kontakte mit Korrektur/Verwerfen; neue Werte freiwillig; Adresse unverändert');

  const contactState = {stage:'contacts', id:'more-contacts', contacts:{new:false, rows:Array.from({length:7},(_,i)=>({key:'p'+i,label:'Mobilfunk',type:'tel',known:true,masked:'017*****567'}))}};
  const multiple = await fixture('', contactState);
  const labels = multiple.nodes().filter(n=>n.tag==='label');
  assert.equal(labels.filter(n=>!n.hidden).length,7);
  assert.ok(!multiple.buttons().some(n=>n.textContent==='Weitere'),'No artificial contact pagination');
  await multiple.buttons().filter(n=>n.textContent==='Korrigieren')[0].listeners.click();
  multiple.nodes().find(n=>n.name==='p0').value='0123456789';
  await multiple.buttons().filter(n=>n.className.includes('contact-correct'))[4].listeners.click();
  multiple.nodes().find(n=>n.name==='p4').value='0987654321';
  assert.equal(labels.filter(n=>!n.hidden).length,7);
  // An invalid edited field must be visible before reporting it.
  const first = multiple.nodes().find(n=>n.name==='p0');first.checkValidity=()=>false;let reported=false;first.reportValidity=()=>{reported=true;};
  await multiple.nodes().find(n=>n.tag==='form').listeners.submit({preventDefault(){}});
  assert.equal(reported,true);assert.equal(first.hidden,false);assert.equal(multiple.server.actions.length,0);
  first.checkValidity=()=>true;
  await multiple.nodes().find(n=>n.tag==='form').listeners.submit({preventDefault(){}});await new Promise(setImmediate);
  assert.equal(Object.keys(multiple.server.actions[0].values).length,7);
  assert.equal(multiple.server.actions[0].values.p0,'0123456789');assert.equal(multiple.server.actions[0].values.p4,'0987654321');
  assert.equal(multiple.server.actions[0].address_ok,'');
  const discard=await fixture('',contactState);
  await discard.buttons().find(n=>n.textContent==='Adresse korrigieren').listeners.click();
  discard.nodes().find(n=>n.name==='address_correction').value='Wird verworfen';
  await discard.buttons().find(n=>n.textContent==='Korrektur verwerfen').listeners.click();
  await discard.nodes().find(n=>n.tag==='form').listeners.submit({preventDefault(){}});await new Promise(setImmediate);
  assert.equal(discard.server.actions[0].address_ok,'');assert.equal(discard.server.actions[0].address_correction,'');
  console.log('OK: Alle Kontakte ohne Blättern sichtbar; Bearbeitungen gemeinsam übermittelt; unveränderte Werte bleiben leer; Validierung und Adresskorrektur erhalten');

  const rejection = await fixture('', {stage:'blocked', id:'rejected-flow', error_code:'REST_REJECTED',
    error_diagnostic:{operation:'TEXT_SAVE',http_status:200,reason:'ROLE_LOCATION_MISMATCH',report_id:'a'.repeat(32)}});
  assert.ok(rejection.nodes().some(node => node.textContent === 'Hinweis: REST_REJECTED · TEXT_SAVE · HTTP 200 · ROLE_LOCATION_MISMATCH'));
  assert.ok(rejection.nodes().some(node => node.textContent === 'Prüfkennung: ' + 'a'.repeat(32)));
  console.log('OK: Ablehnung zeigt feste Fehlerkategorie und Prüfkennung, keine T2med-Rohmeldung');

  const questionnaire = await fixture('', {stage:'questionnaire', id:'PRIVATE_FLOW'});
  assert.deepEqual(questionnaire.redirects, ['questionnaire.php']);
  console.log('OK: Fragebogenübergabe ohne Patientendaten oder Token in der URL');
  const privacy = await fixture('', {stage:'privacy', id:'PRIVATE_FLOW'});
  assert.deepEqual(privacy.redirects, ['privacy.php']);
  const privacyError = await fixture('', {stage:'blocked', id:'PRIVATE_FLOW', checkin_complete:true, error_area:'privacy', message:'WAITING_CONFIRMED', error_code:'PRIVACY_VERIFY'});
  assert.ok(privacyError.nodes().some(node=>node.textContent==='Datenschutzformular nicht bestätigt'));
  assert.ok(privacyError.nodes().some(node=>node.textContent==='WAITING_CONFIRMED'));
  console.log('OK: Datenschutzübergabe und separate Fehlermeldung erhalten bestätigten Check-in');

  for (const [side, direction, percent] of [['right','right',100], ['left','left',60], ['right','top',80], ['left','off',40]]) {
    const live = await fixture('', {stage:'camera', id:'camera-config', checkin_complete:true, message:'CHECKIN_MESSAGE'},
      {camera:true, selfie:{preview_side:side, lens_arrow:direction, frame_height_percent:percent}});
    assert.ok(live.nodes().some(node => node.className === 'camera-layout preview-' + side));
    assert.equal(live.nodes().find(node => node.className === 'capture-guide').style.inset, ((100 - percent) / 2) + '%');
    assert.equal(live.nodes().filter(node => node.className?.startsWith('lens-arrow')).length, direction === 'off' ? 0 : 1);
    if (direction !== 'off') assert.ok(live.nodes().some(node => node.className === 'lens-arrow arrow-' + direction));
    assert.ok(live.nodes().some(node => node.textContent === 'Bitte Auslöser drücken'));
    const copy = live.nodes().find(node => node.className === 'camera-copy');
    assert.ok(copy.children.some(node => node.className === 'completion-notice'));
    assert.equal(live.nodes().find(node => node.className === 'photo-page').children.length, 1);
    assert.equal(live.cameraCalls.length, 1);
    assert.equal(JSON.stringify(live.cameraCalls[0]), JSON.stringify({video:{facingMode:'user',width:{ideal:1280},height:{ideal:960}},audio:false}));
    assert.equal(live.buttons().find(node => node.className.includes('capture-button')).disabled, false);
  }
  console.log('OK: Kameraseiten, Rahmenhöhe und alle Pfeiloptionen; unveränderte Kameraanforderung ohne Zoom');

  for (const category of ['acute', 'other']) {
    const note = await fixture('', {stage: 'note', category, id: 'note-flow'}, {viewport:{height:768,offsetTop:0,scale:1}});
    const input = note.nodes().find(node => node.name === 'note');
    assert.equal(input.tag,'input');assert.equal(input.type,'text');assert.equal(input.maxLength,250);
    assert.ok(!note.nodes().some(node=>node.tag==='textarea'));assert.equal(note.staff.hidden,true);
    note.focus(input);note.screen.scrollTop=123;
    Object.assign(note.visualViewport,{height:380,offsetTop:120});note.viewportEvents.resize();note.viewportEvents.scroll();note.flushFrames();
    Object.assign(note.visualViewport,{height:768,offsetTop:0});note.viewportEvents.resize();note.flushFrames();await note.advance(300);
    assert.equal(input.scrollCalls,0);assert.equal(note.windowScrolls.length,0);assert.equal(note.screen.scrollTop,123);
    assert.equal(note.viewportStyles['--view-height'],'768px');
    assert.equal(input.required, false);
    await note.nodes().find(node => node.tag === 'form').listeners.submit({preventDefault() {}});
    await new Promise(setImmediate);
    assert.equal(note.server.actions[0].note, '');
    const texts = note.nodes().map(node => node.textContent);
    assert.ok(texts.indexOf('CHECKIN_MESSAGE') < texts.indexOf('PHOTO_QUESTION'));
    assert.ok(texts.includes('CHECKIN_MESSAGE'));
  }
  console.log('OK: Beide Freitexte sind optional; Abschlussmeldung steht oberhalb der Fotoabfrage');

  const camera = await fixture('', {stage: 'camera', id: 'photo-flow', checkin_complete: true, message: 'CHECKIN_MESSAGE'});
  assert.ok(camera.nodes().some(node => node.textContent === 'CHECKIN_MESSAGE'));
  const photoError = await fixture('REST_HTTP_400', {stage: 'blocked', id: 'photo-flow', checkin_complete: true, message: 'CHECKIN_MESSAGE'});
  const texts = photoError.nodes().map(node => node.textContent);
  assert.ok(texts.includes('CHECKIN_MESSAGE') && texts.includes('PHOTO_FAILED_ONLY') && !texts.includes('CHECKIN_FAILED'));
  assert.deepEqual(photoError.buttons().map(node => node.textContent), ['Nächste Karte']);
  console.log('OK: Meldung bleibt bei Kamera und Fotofehler erhalten; Check-in wird nicht als fehlgeschlagen dargestellt');

  const done = await fixture('', {stage: 'done', id: 'done-flow', completionRemaining: 15});
  assert.equal(done.buttons()[0].textContent, 'Fertig (15)');
  await done.advance(1000); assert.equal(done.buttons()[0].textContent, 'Fertig (14)');
  await done.advance(14000);
  assert.equal(done.buttons()[0].textContent, 'Karte einlesen');
  assert.equal(done.server.actions.length, 1); assert.equal(done.server.actions[0].action, 'reset');
  assert.equal(done.timers.size, 0);
  const clicked = await fixture('', {stage: 'done', id: 'done-flow', completionRemaining: 7});
  assert.equal(clicked.buttons()[0].textContent, 'Fertig (7)');
  await clicked.buttons()[0].listeners.click(); await clicked.advance(20000);
  assert.equal(clicked.server.actions.length, 1); assert.equal(clicked.buttons()[0].textContent, 'Karte einlesen');
  const elapsed = await fixture('', {stage: 'done', id: 'done-flow', completionRemaining: 0});
  await elapsed.advance(0); assert.equal(elapsed.buttons()[0].textContent, 'Karte einlesen');
  console.log('OK: Countdown 15 -> 14 -> Rückkehr, sofortiger Klick und Restzeit nach Neuladen');
})().catch(error => { console.error(error); process.exitCode = 1; });
