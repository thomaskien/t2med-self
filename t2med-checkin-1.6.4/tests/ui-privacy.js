'use strict';
// Isolated DOM/pointer/fetch doubles; no browser, network or real signature data.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const code=fs.readFileSync(path.join(__dirname,'../public/assets/privacy.js'),'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function fixture(mode='ok') {
  const elements={},requests=[],redirects=[],timers=new Map(),docEvents={},winEvents={};let timer=0;
  for(const id of ['privacy-context','signature','privacy-status','privacy-email','privacy-sms','privacy-submit','privacy-decline','clear-signature']) {
    elements[id]={checked:false,disabled:false,textContent:'',events:{},addEventListener(n,f){this.events[n]=f;}};
  }
  elements['privacy-context'].value=JSON.stringify({flowId:'FLOW',formToken:'TOKEN',csrf:'CSRF',timeout:900});
  const pen={clearRect(){},beginPath(){},moveTo(){},lineTo(){},stroke(){}};
  Object.assign(elements.signature,{width:1000,height:500,getContext:()=>pen,setPointerCapture(){},getBoundingClientRect:()=>({left:0,top:0,width:1000,height:500})});
  const document={hidden:false,getElementById:id=>elements[id],addEventListener(n,f){docEvents[n]=f;}};
  vm.runInNewContext(code,{document,window:{addEventListener(n,f){winEvents[n]=f;}},location:{replace:url=>redirects.push(url),reload:()=>redirects.push('reload')},
    setTimeout:fn=>{timers.set(++timer,fn);return timer;},clearTimeout:id=>timers.delete(id),Date,JSON,Math,Error,
    fetch:async(url,options)=>{const input=JSON.parse(options.body);requests.push({url,options,input});
      if(input.action==='privacy_submit' && mode==='lost')throw new Error('synthetic lost response');
      if(input.action==='privacy_submit' && mode==='invalid')return {ok:false,json:async()=>({ok:false,code:'PRIVACY_INPUT',message:'BAD_SIGNATURE'})};
      return {ok:true,json:async()=>({ok:true,state:{stage:input.action==='touch'?'privacy':'done'}})};
    }});
  await tick();
  function sign(){const event={pointerId:1,preventDefault(){},clientX:100,clientY:100};elements.signature.events.pointerdown(event);
    for(let i=0;i<5;i++)elements.signature.events.pointermove({...event,clientX:200+i*100,clientY:60+i*20});elements.signature.events.pointerup(event);}
  return {elements,requests,redirects,document,docEvents,winEvents,timers,sign};
}
(async()=>{
  const html=fs.readFileSync(path.join(__dirname,'../public/privacy.php'),'utf8');
  assert.ok(html.includes('Bitte im Feld unterschreiben'));
  assert.ok(html.includes('width="1000" height="500"'));
  assert.match(fs.readFileSync(path.join(__dirname,'../public/assets/privacy.css'),'utf8'),/aspect-ratio:2\/1/);
  assert.ok(!html.includes('Die beiden Einwilligungen sind freiwillig und nicht vorausgewählt.'));
  for(const file of ['public/index.php','public/privacy.php','fragebogenpi/tablet-checkin.php']) {
    const page=fs.readFileSync(path.join(__dirname,'..',file),'utf8');
    assert.ok(page.includes('name="apple-mobile-web-app-capable" content="yes"'));
    assert.ok(page.includes('rel="manifest" href="manifest.webmanifest"'));
  }
  for(const file of ['public/privacy.php','public/questionnaire.php']) {
    assert.ok(fs.readFileSync(path.join(__dirname,'..',file),'utf8').includes("manifest-src 'self'"));
  }
  const manifest=JSON.parse(fs.readFileSync(path.join(__dirname,'../public/manifest.webmanifest'),'utf8'));
  assert.equal(manifest.display,'standalone');assert.equal(manifest.scope,'./');assert.equal(manifest.start_url,'./');
  const x=await fixture();assert.equal(x.elements['privacy-email'].checked,false);assert.equal(x.elements['privacy-sms'].checked,false);
  x.elements['privacy-submit'].events.click();await tick();assert.equal(x.requests.length,1); // Only initial keepalive, no blank signature write.
  x.sign();x.elements['privacy-sms'].checked=true;x.elements['privacy-submit'].events.click();x.elements['privacy-submit'].events.click();await tick();
  const submits=x.requests.filter(r=>r.input.action==='privacy_submit');assert.equal(submits.length,1);
  assert.equal(submits[0].input.email,false);assert.equal(submits[0].input.sms,true);assert.equal(submits[0].options.headers['X-CSRF-Token'],'CSRF');
  assert.equal(submits[0].input.flowId,'FLOW');assert.equal(submits[0].input.formToken,'TOKEN');assert.equal(submits[0].url,'api.php');
  for(const stroke of submits[0].input.strokes)for(const point of stroke)assert.ok(point.every(v=>v>=0&&v<=1));
  assert.deepEqual(x.redirects,['./']);
  const bad=await fixture('invalid');bad.sign();bad.elements['privacy-submit'].events.click();await tick();
  assert.equal(bad.elements['privacy-submit'].disabled,false);assert.equal(bad.elements['privacy-status'].textContent,'BAD_SIGNATURE');assert.equal(bad.redirects.length,0);
  const lost=await fixture('lost');lost.sign();lost.elements['privacy-submit'].events.click();await tick();
  assert.equal(lost.requests.filter(r=>r.input.action==='privacy_submit').length,1);assert.deepEqual(lost.redirects,['./']);
  const abort=await fixture();abort.elements['privacy-decline'].events.click();await tick();
  assert.equal(abort.requests.at(-1).input.action,'privacy_decline');assert.equal('strokes' in abort.requests.at(-1).input,false);
  const hidden=await fixture();hidden.sign();hidden.elements['privacy-email'].checked=true;hidden.document.hidden=true;hidden.docEvents.visibilitychange();
  hidden.elements['privacy-submit'].events.click();assert.equal(hidden.elements['privacy-email'].checked,false);assert.equal(hidden.requests.length,1);
  hidden.winEvents.pageshow({persisted:true});assert.deepEqual(hidden.redirects,['reload']);
  const expired=await fixture();expired.sign();expired.elements['privacy-email'].checked=true;
  await [...expired.timers.values()][0]();assert.equal(expired.requests.at(-1).input.action,'reset');assert.equal(expired.elements['privacy-email'].checked,false);
  console.log('Privacy UI: optional unchecked consents, signature normalization, single submit, CSRF/tokens, validation, lost-response no retry, decline, hide/BFcache, idle reset OK.');
})().catch(error=>{console.error(error);process.exitCode=1;});
