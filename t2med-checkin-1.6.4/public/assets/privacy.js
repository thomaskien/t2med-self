'use strict';
(() => {
  const context = JSON.parse(document.getElementById('privacy-context').value);
  const canvas = document.getElementById('signature'), pen = canvas.getContext('2d');
  const status = document.getElementById('privacy-status');
  const email = document.getElementById('privacy-email'), sms = document.getElementById('privacy-sms');
  const submit = document.getElementById('privacy-submit'), decline = document.getElementById('privacy-decline');
  const clear = document.getElementById('clear-signature');
  let strokes = [], active = null, pointer = null, busy = false, count = 0, lastTouch = 0, idle;
  function wipe() { pen.clearRect(0, 0, canvas.width, canvas.height); strokes = []; active = null; pointer = null; count = 0; }
  function point(e) { const r = canvas.getBoundingClientRect(); return [Math.max(0, Math.min(1, (e.clientX-r.left)/r.width)), Math.max(0, Math.min(1, (e.clientY-r.top)/r.height))]; }
  function draw(a, b) { pen.lineWidth=3.5;pen.lineCap='round';pen.lineJoin='round';pen.strokeStyle='#142c26';pen.beginPath();pen.moveTo(a[0]*canvas.width,a[1]*canvas.height);pen.lineTo(b[0]*canvas.width,b[1]*canvas.height);pen.stroke(); }
  canvas.addEventListener('pointerdown', e => { if(busy || pointer !== null) return;e.preventDefault();if(strokes.length>=100 || count>=6000){status.textContent='Bitte Unterschrift löschen und neu unterschreiben.';return;}pointer=e.pointerId;active=[point(e)];strokes.push(active);count++;canvas.setPointerCapture(e.pointerId); });
  canvas.addEventListener('pointermove', e => { if(busy || e.pointerId!==pointer || !active) return;e.preventDefault();if(count>=6000){status.textContent='Bitte Unterschrift löschen und neu unterschreiben.';return;}const p=point(e);draw(active[active.length-1],p);active.push(p);count++; });
  function end(e) { if(e.pointerId===pointer){active=null;pointer=null;} }
  canvas.addEventListener('pointerup',end);canvas.addEventListener('pointercancel',end);canvas.addEventListener('lostpointercapture',end);
  clear.addEventListener('click',()=>{if(!busy){wipe();status.textContent='';}});
  function disabled(value) { busy=value;[submit,decline,clear,email,sms].forEach(el=>el.disabled=value); }
  async function send(action, extras={}) {
    const response = await fetch('api.php',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{'Content-Type':'application/json','X-CSRF-Token':context.csrf},
      body:JSON.stringify({action,flowId:context.flowId,formToken:context.formToken,...extras})});
    const result=await response.json();
    if(!response.ok || !result.ok){const error=new Error(result.message || 'Übertragung nicht bestätigt.');error.code=result.code;error.blocked=result.blocked;throw error;}
    return result;
  }
  async function finish(action) {
    if(busy)return;
    if(action==='privacy_submit' && count<5){status.textContent='Bitte im Unterschriftsfeld unterschreiben.';return;}
    disabled(true);clearTimeout(idle);status.textContent='Wird gespeichert …';
    try { await send(action, action==='privacy_submit'?{email:email.checked,sms:sms.checked,strokes}:{});wipe();location.replace('./'); }
    catch(error){
      if(error.code==='PRIVACY_INPUT'){disabled(false);status.textContent=error.message;activity();return;}
      // Never resend after a lost response. Main page reconciles the authoritative server state.
      wipe();location.replace('./');
    }
  }
  submit.addEventListener('click',()=>finish('privacy_submit'));
  decline.addEventListener('click',()=>finish('privacy_decline'));
  function activity() {
    if(busy)return;clearTimeout(idle);idle=setTimeout(async()=>{disabled(true);wipe();email.checked=sms.checked=false;try{await send('reset');}catch(_){}location.replace('./');},context.timeout*1000);
    if(Date.now()-lastTouch>45000){lastTouch=Date.now();send('touch').then(r=>{if(r.state.stage!=='privacy')location.replace('./');}).catch(()=>{wipe();location.replace('./');});}
  }
  ['pointerdown','pointermove','keydown','scroll'].forEach(name=>document.addEventListener(name,activity,{passive:true,capture:true}));
  window.addEventListener('pagehide',()=>{wipe();email.checked=sms.checked=false;clearTimeout(idle);});
  window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden&&!busy){wipe();email.checked=sms.checked=false;}});
  activity();
})();
