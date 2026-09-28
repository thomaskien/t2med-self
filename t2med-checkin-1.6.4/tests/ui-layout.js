'use strict';
// Optional local browser check. Requires existing Playwright and Chrome; installs nothing.
// Every browser request is intercepted. Synthetic records/canvas camera only, no T2med.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.argv[3] || 'playwright');
const root = path.join(__dirname, '..');
const output = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8').replace(/^<\?php[\s\S]*?\?>\s*/, '');
const privacyAside = fs.readFileSync(path.join(root, 'public/privacy.php'), 'utf8').match(/<aside[\s\S]*?<\/aside>/)[0]
  .replace(/<\?= privacy_h\(\$yaml\['consent'\]\['checkbox_label'\]\) \?>/, 'Unverschl. E-Mail erlauben (optional)')
  .replace(/<\?= privacy_h\(\$yaml\['consent'\]\['sms_checkbox_label'\]\) \?>/, 'SMS erlauben (optional)');
const privacyHtml = '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/privacy.css"><body><header><span>Praxis Check-in</span><span>Datenschutz</span></header><main><article><h1>Datenschutzerklärung</h1><p>Synthetische Layoutprüfung ohne Patientendaten.</p></article>'+privacyAside+'</main></body></html>';
const config = {
  version:'1.6.4', title:'TEST', timeout:180, completionSeconds:15, messages:{camera_error:'Kamera nicht verfügbar',read_button:'Karte einlesen'},
  noteMaxLength:250,categories:{acute:{question:'In aller Kürze: Warum sind Sie hier?'},other:{question:'In aller Kürze: Um was geht es?'}},
  contacts:{question:'Stimmen Ihre Kontaktdaten noch?',new_question:'Wie können wir Sie erreichen?',
    address_question:'Stimmt Ihre Adresse auf der Karte noch?',
    hint:'Alle Angaben sind freiwillig. Zum Ändern bitte den vollständigen neuen Wert eingeben. Leere Felder lassen vorhandene Angaben unverändert.'},
  selfie:{preview_side:'right',lens_arrow:'right',frame_height_percent:100,output_size:800,jpeg_quality_percent:88,
    frame_text:'Bitte Gesicht in den Rahmen',capture_text:'Bitte Auslöser drücken',
    adjust_text:'Verschieben Sie das Bild mit einem Finger. Mit zwei Fingern können Sie es vergrößern oder verkleinern.'},
};
const contact = (fresh=false, count=3) => ({stage:'contacts',id:'SYNTHETIC',contacts:{new:fresh,name:'Erika Testperson',
  address_lines:['Teststraße 12','12345 Testort'],rows:Array.from({length:count},(_,i)=>({key:'field'+i,
    type:i%3===2?'email':'tel',label:['Festnetz','Mobilfunk','E-Mail'][i%3],known:!fresh,
    masked:fresh?'':(i%3===2?'e***@t***':'017*****567')}))}});
const longMessage = 'Sie sind anscheinend ein neuer Patient, wir brauchen von Ihnen noch eine Unterschrift und einen ausgefüllten Fragebogen. Bitte melden Sie sich für die nächsten Schritte am Empfang.';

(async()=>{
  const browser = await chromium.launch({channel:'chrome',headless:true});
  try {
    const context = await browser.newContext({viewport:{width:1080,height:810},hasTouch:true});
    const page = await context.newPage(); const errors=[],requests=[];
    page.on('pageerror',e=>errors.push(e.message));
    let state=contact();
    await page.addInitScript(()=>{
      // Canvas stands in for the front camera; never asks for camera permission.
      Object.defineProperty(navigator.mediaDevices,'getUserMedia',{value:async()=>{
        const canvas=document.createElement('canvas');canvas.width=960;canvas.height=720;
        const ctx=canvas.getContext('2d');ctx.fillStyle='#adc6bd';ctx.fillRect(0,0,960,720);
        ctx.fillStyle='#708e83';ctx.beginPath();ctx.ellipse(480,620,240,220,0,0,Math.PI*2);ctx.fill();
        ctx.fillStyle='#e7cbb0';ctx.beginPath();ctx.ellipse(480,290,140,180,0,0,Math.PI*2);ctx.fill();
        const media=canvas.captureStream(1);(window.testCameraStreams??=[]).push(media);return media;
      }});
    });
    await context.route('**/*', async route=>{
      const req=route.request(),url=new URL(req.url());
      if(url.hostname!=='checkin.invalid')throw new Error('Unexpected host: '+url.hostname);
      if(url.pathname==='/api.php'){
        if(req.method()==='POST'){
          const input=req.postDataJSON();requests.push(input);
          if(input.action==='logout')state={stage:'login'};
          if(input.action==='login')state={stage:'idle'};
        }
        return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,csrf:'SYNTHETIC',config,state})});
      }
      if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
      if(url.pathname==='/privacy.php')return route.fulfill({contentType:'text/html',body:privacyHtml});
      if(['/assets/app.js','/assets/app.css','/assets/privacy.css','/manifest.webmanifest'].includes(url.pathname)) {
        return route.fulfill({contentType:url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.css')?'text/css':'application/manifest+json',
          body:fs.readFileSync(path.join(root,'public',url.pathname))});
      }
      return route.fulfill({status:404,body:''});
    });
    async function load(next){state=next;await page.goto('https://checkin.invalid/');await page.locator('main h1').waitFor();}
    async function fits(label){
      const bounds=await page.evaluate(()=>({height:innerHeight,width:innerWidth,
        scrollHeight:document.documentElement.scrollHeight,scrollWidth:document.documentElement.scrollWidth}));
      assert.ok(bounds.scrollHeight<=bounds.height+1, label+' vertical overflow: '+JSON.stringify(bounds));
      assert.ok(bounds.scrollWidth<=bounds.width+1, label+' horizontal overflow');
      for(const box of await page.locator('main button:visible,main input:visible,main textarea:visible').all()){
        const b=await box.boundingBox();assert.ok(b.y>=0&&b.y+b.height<=bounds.height+1,label+' unreachable control');
      }
      const footer=await page.locator('footer').boundingBox();
      if(await page.locator('html.natural-scroll').count()){
        assert.equal(footer,null,'No footer on input pages');
        assert.equal(await page.locator('.topbar').boundingBox(),null,'No header on input pages');
      }
      else assert.ok(footer.y>=bounds.height-50,label+' footer stays at bottom');
      assert.equal(await page.locator('#staff').isVisible(),state.stage==='idle','Staff button only on card-start page');
      if(await page.locator('.contacts-screen').count())assert.ok(await page.locator('.contacts-screen').evaluate(n=>n.scrollHeight<=n.clientHeight+1),label+' contact panel requires scrolling');
      if(await page.locator('.camera-copy').count())assert.ok(await page.locator('.camera-copy').evaluate(n=>n.scrollHeight<=n.clientHeight+1),label+' text column requires scrolling');
    }
    async function shot(name){if(output)await page.screenshot({path:path.join(output,name+'.png')});}
    async function frameLocked(label){
      const header=await page.locator('.topbar').boundingBox();
      const footer=await page.locator('footer').boundingBox();
      await page.evaluate(()=>window.scrollTo(0,500));
      await page.mouse.move(200,40);await page.mouse.wheel(0,500);
      await page.evaluate(()=>new Promise(requestAnimationFrame));
      assert.equal(await page.evaluate(()=>window.scrollY),0,label+' document cannot scroll');
      assert.deepEqual(await page.locator('.topbar').boundingBox(),header,label+' header does not move');
      assert.deepEqual(await page.locator('footer').boundingBox(),footer,label+' footer does not move');
    }
    for(const size of [{width:1080,height:810},{width:1024,height:768},{width:1024,height:664}]){
      await page.setViewportSize(size);
      for(const fresh of [false,true]){
        await load(contact(fresh));await fits('contacts '+JSON.stringify(size));
        assert.equal(await page.locator('.contact-grid input:visible').count(),fresh?3:0);
        const title = await page.locator('.contacts-page h1').boundingBox();
        assert.ok(title.y<70,'Heading occupies former header area');
        const fieldBox=await page.locator('.contact-fields').boundingBox(), addressBox=await page.locator('.address-check').boundingBox();
        assert.ok(fieldBox.x<addressBox.x,'Phone fields left, address centre');
        if(size.height===810&&!fresh)await shot('contacts');
        const phone=page.locator('input[type=tel]').first();
        if(!fresh)await page.getByRole('button',{name:'Festnetz korrigieren',exact:true}).click();
        await phone.click(); await fits('phone keypad');
        assert.equal(await phone.evaluate(n=>n.readOnly&&n.inputMode==='none'),true,'No system keyboard for phone');
        for(const digit of ['+','4','9','1','2']) await page.getByRole('button',{name:digit,exact:true}).click();
        await page.getByRole('button',{name:'Ziffer löschen',exact:true}).click();
        assert.equal(await phone.inputValue(),'+491');
        await phone.evaluate(n=>n.setSelectionRange(1,3));
        await page.getByRole('button',{name:'3',exact:true}).click();assert.equal(await phone.inputValue(),'+31');
        if(size.height===810&&!fresh)await shot('phone-keypad');
        assert.deepEqual(await page.locator('.contacts-page h1').boundingBox(),title,'Keypad cannot move heading');
        await page.getByRole('button',{name:'Fertig',exact:true}).click();
        await phone.click();
        if(!fresh)await page.getByRole('button',{name:'E-Mail korrigieren',exact:true}).click();
        await page.locator('input[type=email]').first().focus();
        assert.equal(await page.locator('.phone-keypad').isVisible(),false,'Email closes keypad');
        assert.equal(await page.locator('input[type=email]').first().evaluate(n=>!n.readOnly&&n.inputMode==='email'),true);
        await page.getByRole('button',{name:'Adresse korrigieren',exact:true}).click();
        await fits('address correction');
        assert.deepEqual(await page.locator('.contacts-page h1').boundingBox(),title,'Address correction cannot move heading');
        if(size.height===810&&!fresh)await shot('address-correction');
        if(!fresh){
          await page.getByRole('button',{name:'Festnetz: Korrektur verwerfen',exact:true}).click();
          assert.equal(await phone.isVisible(),false);assert.equal(await phone.inputValue(),'');
        }
      }
      await load(contact(false,12));
      assert.equal(await page.locator('.contact-row').count(),12,'All contacts directly on page');
      assert.equal(await page.getByRole('button',{name:'Weitere',exact:true}).count(),0,'No pagination');
      assert.deepEqual(await page.evaluate(()=>({body:getComputedStyle(document.body).position,
        overflow:getComputedStyle(document.documentElement).overflowY,shell:getComputedStyle(document.querySelector('.app-shell')).position,
        main:getComputedStyle(document.querySelector('main')).overflowY})),
        {body:'static',overflow:'visible',shell:'static',main:'visible'},'Contacts have ordinary document scrolling');
      await page.mouse.move(400,400);await page.mouse.wheel(0,700);
      await page.waitForFunction(()=>window.scrollY>100);
      await page.locator('.contact-correct').last().click();
      const lastEmail=page.locator('input[type=email]').last();await lastEmail.fill('last@example.invalid');
      assert.equal(await lastEmail.inputValue(),'last@example.invalid','Last contact reachable by scrolling');
      const rectangles=[];
      for(const message of ['',longMessage]){
        await load({stage:'camera',id:'SYNTHETIC',checkin_complete:true,message});
        await page.getByRole('button',{name:'Foto aufnehmen',exact:true}).waitFor();
        await page.waitForFunction(()=>!document.querySelector('.capture-button').disabled);
        await fits('camera '+JSON.stringify(size));
        const frame=await page.locator('.camera-viewport').boundingBox(),arrow=await page.locator('.lens-arrow').boundingBox();
        assert.ok(Math.abs(frame.y+frame.height/2-size.height/2)<1,'frame centred on screen');
        assert.ok(Math.abs(arrow.y+arrow.height/2-size.height/2)<1,'arrow centred on camera edge');
        rectangles.push(frame);
        if(size.height===810&&message)await shot('camera');
      }
      assert.deepEqual(rectangles[0],rectangles[1],'notice must not move or shrink preview');
      await page.getByRole('button',{name:'Foto aufnehmen',exact:true}).click();
      await page.getByRole('button',{name:'Diesen Bildausschnitt verwenden',exact:true}).waitFor();
      await fits('crop editor');
      assert.deepEqual(await page.locator('.camera-viewport').boundingBox(),rectangles[1],'editor preserves frame position');
      await shot('crop-editor-'+size.height);
      await page.goto('https://checkin.invalid/privacy.php');
      const signature=await page.locator('#signature').boundingBox();
      assert.ok(Math.abs(signature.width/signature.height-2)<.01,'Signature twice as tall at same width');
      assert.ok(await page.locator('aside').evaluate(n=>n.scrollHeight<=n.clientHeight+1),'Privacy controls fit without scrolling at '+size.height);
      if(size.height===810)await shot('signature');
    }
    config.selfie.preview_side='left';config.selfie.lens_arrow='left';
    for(const category of ['acute','other']){
      await page.setViewportSize({width:1024,height:768});
      await load({stage:'note',category,id:'SYNTHETIC'});await fits('reason '+category);
      for(const control of await page.locator('.note-page button').all()){
        const b=await control.boundingBox();assert.ok(b.y+b.height<=320,'Actions already high before any keyboard/layout resize');
      }
      const before=await page.evaluate(()=>document.documentElement.style.cssText);
      const note=page.locator('input[name=note]');await note.fill('Synthetisches Anliegen');
      assert.equal(await page.locator('.note-form textarea').count(),0,'Single-line reason input');
      await page.setViewportSize({width:1024,height:320});
      await page.evaluate(()=>new Promise(requestAnimationFrame));
      assert.equal(await page.locator('.topbar').isVisible(),false);assert.equal(await page.locator('footer').isVisible(),false);
      assert.equal(await page.locator('.note-form .actions').evaluate(n=>getComputedStyle(n).position),'static','No sticky actions');
      await fits('reason with keyboard '+category);await shot('reason-'+category+'-keyboard');
      assert.ok((await note.boundingBox()).height<=68,'Short single line leaves room for actions');
      assert.equal(await page.evaluate(()=>getComputedStyle(document.body).position),'static','Native scrolling remains available');
      await page.setViewportSize({width:1024,height:768});await page.evaluate(()=>new Promise(requestAnimationFrame));
      assert.equal(await note.inputValue(),'Synthetisches Anliegen');
      assert.equal(await page.evaluate(()=>document.documentElement.style.cssText),before,'No reason viewport manipulation');
      await fits('reason after keyboard close');await shot('reason-'+category);
      state={stage:'done',id:'SYNTHETIC',checkin_complete:true,message:'Fertig',completionRemaining:15};
      await page.getByRole('button',{name:'Weiter',exact:true}).click();await page.getByRole('button',{name:/Fertig/}).waitFor();
      assert.equal(await page.locator('.topbar').isVisible(),true);assert.equal(await page.locator('footer').isVisible(),true);
      await frameLocked('after reason');
    }
    // Smaller viewport approximates keyboard space, not an actual iPad keyboard.
    await page.setViewportSize({width:1024,height:768});await load(contact());
    const initialViewport=await page.evaluate(()=>document.documentElement.style.cssText);
    const email=page.locator('input[type=email]');
    await page.getByRole('button',{name:'E-Mail korrigieren',exact:true}).click();
    await email.fill('erika@example.invalid');
    await page.setViewportSize({width:1024,height:430});
    await page.evaluate(()=>new Promise(requestAnimationFrame));
    assert.equal(await page.locator('footer').isVisible(),false,'Footer absent with email focused in short viewport');
    await email.scrollIntoViewIfNeeded();
    const emailBox=await page.locator('input[type=email]').boundingBox();
    assert.ok(emailBox.y>=0&&emailBox.y+emailBox.height<=430,'Email reachable by native scrolling');
    await page.setViewportSize({width:1024,height:768});
    await page.evaluate(()=>new Promise(requestAnimationFrame));
    assert.equal(await page.evaluate(()=>document.documentElement.style.cssText),initialViewport,'No contact viewport manipulation');
    assert.equal(await email.inputValue(),'erika@example.invalid','Closing keyboard preserves email');
    assert.equal(await email.evaluate(n=>document.activeElement===n),true,'Restoration works with focus retained');
    await fits('email after keyboard closed');
    await shot('contacts-keyboard-closed');
    // Repeat with the correction textarea and a blur before keyboard dismissal.
    await page.getByRole('button',{name:'Adresse korrigieren',exact:true}).click();
    const correction=page.locator('textarea');await correction.fill('Teststraße 34\n12345 Testort');
    await page.setViewportSize({width:1024,height:430});
    await page.evaluate(()=>new Promise(requestAnimationFrame));
    await correction.evaluate(n=>n.blur());await page.setViewportSize({width:1024,height:768});
    await page.evaluate(()=>new Promise(requestAnimationFrame));
    assert.equal(await page.evaluate(()=>document.documentElement.style.cssText),initialViewport,'No contact viewport manipulation after blur');
    assert.equal(await correction.inputValue(),'Teststraße 34\n12345 Testort','Correction preserved');
    await fits('correction after keyboard closed');
    await page.setViewportSize({width:1080,height:810});
    // Continue without reloading: the normal kiosk frame must be restored.
    state={stage:'camera',id:'SYNTHETIC',checkin_complete:true,message:longMessage};
    await page.getByRole('button',{name:'Weiter',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('.capture-button')?.disabled===false);
    await fits('left camera');
    assert.equal(await page.locator('.topbar').isVisible(),true,'Header returns on next page');
    assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('natural-scroll')),false);
    assert.equal(await page.locator('footer').isVisible(),true,'Other pages still show footer');
    await frameLocked('camera');
    assert.ok((await page.locator('.camera-viewport').boundingBox()).x<100);
    await shot('camera-left');
    assert.deepEqual(errors,[]);assert.ok(requests.every(r=>['touch','contacts','note'].includes(r.action)),'only simulated form submits');
    assert.equal(requests.filter(r=>r.action==='note' && r.note==='Synthetisches Anliegen').length,2);
    const submitted=requests.filter(r=>r.action==='contacts');assert.equal(submitted.length,1);
    assert.equal(submitted[0].values.field2,'erika@example.invalid');
    assert.equal(submitted[0].values.field0,'');assert.equal(submitted[0].values.field1,'');
    assert.equal(submitted[0].address_correction,'Teststraße 34\n12345 Testort');
    for(const height of [810,768,664]){
      await page.setViewportSize({width:1024,height});await load({stage:'login'});
      await page.getByText('Kamera bereit.',{exact:false}).waitFor();await fits('staff camera '+height);
      const box=await page.locator('.staff-camera video').boundingBox();assert.equal(Math.round(box.width),144);assert.equal(Math.round(box.height),108);
      assert.equal(await page.evaluate(()=>window.testCameraStreams.length),1);
      assert.equal(await page.locator('#staff').isVisible(),false,'Staff control absent on login');
      assert.equal(await page.locator('.topbar').isVisible(),false);assert.equal(await page.locator('footer').isVisible(),false);
      assert.equal(await page.evaluate(()=>window.testCameraStreams.length),1);
      if(height===768)await shot('staff-camera');
    }
    await page.locator('input[name=username]').fill('TEST_USER');
    const loginViewport=await page.evaluate(()=>document.documentElement.style.cssText);
    await page.locator('input[name=password]').fill('SYNTHETIC_PASSWORD');
    await page.setViewportSize({width:1024,height:320});await page.evaluate(()=>new Promise(requestAnimationFrame));
    assert.equal(await page.locator('.topbar').isVisible(),false);assert.equal(await page.locator('footer').isVisible(),false);
    assert.equal(await page.evaluate(()=>getComputedStyle(document.body).position),'static');
    const loginSubmit=page.getByRole('button',{name:'Gerät anmelden',exact:true});await loginSubmit.scrollIntoViewIfNeeded();
    const submitBox=await loginSubmit.boundingBox();assert.ok(submitBox.y>=0&&submitBox.y+submitBox.height<=320,'Staff login reachable with keyboard');
    assert.equal(await page.evaluate(()=>document.documentElement.style.cssText),loginViewport,'No login viewport manipulation');
    await page.setViewportSize({width:1024,height:768});await page.evaluate(()=>new Promise(requestAnimationFrame));
    assert.equal(await page.locator('input[name=username]').inputValue(),'TEST_USER');
    assert.equal(await page.locator('input[name=password]').inputValue(),'SYNTHETIC_PASSWORD');
    await fits('login after keyboard');
    await page.getByRole('button',{name:'Gerät anmelden',exact:true}).click();await page.getByRole('button',{name:'Karte einlesen',exact:true}).waitFor();
    assert.equal(await page.locator('#staff').isVisible(),true,'Staff button returns at card-start');
    assert.equal(await page.locator('.topbar').isVisible(),true);assert.equal(await page.locator('footer').isVisible(),true);
    assert.equal(await page.evaluate(()=>window.testCameraStreams.every(s=>s.getTracks().every(t=>t.readyState==='ended'))),true,'No background camera after login');
    const beforeStaff=requests.length;
    for(const cancelAt of [1,2,3]){
      await page.locator('#staff').click();
      for(let n=1;n<cancelAt;n++)await page.getByRole('button',{name:'Ja, weiter',exact:true}).click();
      assert.equal(await page.locator('dialog h2').textContent(),`Bestätigung ${cancelAt}/3: Wirklich abmelden?`);
      assert.equal(await page.locator('dialog').evaluate(n=>n.open),true);
      await page.getByRole('button',{name:'Abbrechen',exact:true}).click();
      assert.equal(await page.locator('dialog').count(),0);assert.equal(requests.length,beforeStaff);
    }
    await page.locator('#staff').click();await page.keyboard.press('Escape');assert.equal(await page.locator('dialog').count(),0);
    await page.locator('#staff').click();await page.getByRole('button',{name:'Ja, weiter',exact:true}).click();
    await page.getByRole('button',{name:'Ja, weiter',exact:true}).click();await fits('logout confirmation');await shot('staff-confirm-3');
    assert.equal(requests.length,beforeStaff);
    await page.getByRole('button',{name:'Ja, Gerät abmelden',exact:true}).click();await page.getByText('Kamera bereit.',{exact:false}).waitFor();
    assert.equal(requests.filter(r=>r.action==='logout').length,1);assert.equal(requests.at(-1).confirmations,3);
    assert.deepEqual(errors,[]);
    console.log('OK: Miniature staff camera at three iPad sizes, stream stopped after login; three real modal confirmations, cancel/Escape preserve session, one logout after step 3.');
    console.log('OK: 1080×810, 1024×768, 1024×664: contacts without header/footer, correction controls, large masked values; long contact lists scroll naturally; keypad, signature and camera unchanged.');
    console.log('OK: Keyboard-sized viewport opens/closes on same page; email and address correction preserved without contact viewport manipulation.');
    console.log('Chromium only; iPad Safari, on-screen keyboard and Home Screen require real-device confirmation.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
