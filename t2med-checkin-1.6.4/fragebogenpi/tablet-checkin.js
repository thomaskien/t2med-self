'use strict';
(() => {
    var submitBtn = document.getElementById("submitBtn");
    var abortBtn  = document.getElementById("abortBtn");
    var statusEl  = document.getElementById("status");
    var formEl    = document.getElementById("anamForm");

    function setStatus(msg, isError) {
      statusEl.textContent = msg || "";
      statusEl.style.color = isError ? "#d00" : "#333";
    }

    function questionWrapForId(qid) {
      var wraps = formEl.querySelectorAll('[data-qwrap="1"]');
      for (var i = 0; i < wraps.length; i++) {
        if ((wraps[i].getAttribute('data-qid') || '') === qid) return wraps[i];
      }
      return null;
    }

    function getAnswerValue(qid) {
      var wrap = questionWrapForId(qid);
      if (!wrap) return "";

      var cbs = wrap.querySelectorAll('input[type="checkbox"]');
      if (cbs && cbs.length === 1 && (cbs[0].name || '').slice(-2) !== '[]') {
        return cbs[0].checked ? true : false;
      }
      if (cbs && cbs.length) {
        var vals = [];
        cbs.forEach(function(x){ if (x.checked) vals.push(x.value || ""); });
        return vals;
      }

      var r = wrap.querySelector('input[type="radio"]:checked');
      if (r) return r.value;

      var t = wrap.querySelector('input[type="hidden"], input:not([type]), input[type="text"], input[type="number"], textarea, select');
      if (t) return (t.value || "");
      return "";
    }

    function commitScale(range) {
      var targetId = range.getAttribute("data-answer-target");
      var target = targetId ? document.getElementById(targetId) : null;
      var wrap = range.closest('[data-qwrap="1"]');
      if (!target || !wrap) return;
      target.value = range.value;
      range.setAttribute("aria-valuetext", range.value);
      var valueEl = wrap.querySelector('[data-scale-value="1"]');
      if (valueEl) valueEl.textContent = range.value;
      wrap.querySelectorAll('[data-scale-tick="1"]').forEach(function(tick){
        var selected = String(tick.getAttribute('data-scale-value-option')) === String(range.value);
        tick.classList.toggle('selected', selected);
        tick.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
      wrap.classList.add("answered");
      wrap.classList.remove("invalid");
    }

    function setScaleFromPointer(range, event) {
      if (typeof event.clientX !== 'number') return;
      if (typeof event.button === 'number' && event.button !== 0) return;
      var rect = range.getBoundingClientRect();
      if (!rect || rect.width <= 0) return;

      var minimum = Number(range.min || 0);
      var maximum = Number(range.max || 100);
      var step = Number(range.step || 1);
      if (!Number.isFinite(minimum) || !Number.isFinite(maximum) || !Number.isFinite(step) || step <= 0 || maximum <= minimum) return;

      var ratio = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
      var rawValue = minimum + ratio * (maximum - minimum);
      var snappedValue = minimum + Math.round((rawValue - minimum) / step) * step;
      snappedValue = Math.max(minimum, Math.min(maximum, snappedValue));
      range.value = String(Number(snappedValue.toFixed(6)));
      commitScale(range);
    }

    formEl.querySelectorAll('[data-scale-range="1"]').forEach(function(range){
      range.addEventListener("pointerdown", function(event){ setScaleFromPointer(range, event); });
      range.addEventListener("input", function(){ commitScale(range); });
      range.addEventListener("change", function(){ commitScale(range); });
      range.addEventListener("click", function(event){ setScaleFromPointer(range, event); });
    });

    formEl.querySelectorAll('[data-scale-tick="1"]').forEach(function(tick){
      tick.addEventListener("click", function(){
        var rangeId = tick.getAttribute("data-range-target");
        var range = rangeId ? document.getElementById(rangeId) : null;
        if (!range) return;
        range.value = tick.getAttribute("data-scale-value-option") || range.value;
        commitScale(range);
        range.focus();
      });
    });

    var requiredValidationActive = false;

    function updateRequiredQuestionMarks() {
      var firstInvalid = null;
      var missingLabels = [];
      var required = formEl.querySelectorAll('[data-qwrap="1"][data-required="1"]');

      if (!requiredValidationActive) {
        required.forEach(function(wrap){ wrap.classList.remove('invalid'); });
        return { firstInvalid:null, missingLabels:[] };
      }

      required.forEach(function(wrap){
        var section = wrap.closest('.section');
        var hidden = wrap.classList.contains('hidden') || (section && section.classList.contains('hidden'));
        if (hidden) {
          wrap.classList.remove('invalid');
          return;
        }

        var qid = wrap.getAttribute('data-qid') || '';
        var value = getAnswerValue(qid);
        var answered = Array.isArray(value) ? value.length > 0 : (value === true || String(value || '').trim() !== '');
        wrap.classList.toggle('invalid', !answered);
        if (!answered) {
          if (!firstInvalid) firstInvalid = wrap;
          missingLabels.push(wrap.getAttribute('data-qlabel') || qid);
        }
      });

      return { firstInvalid:firstInvalid, missingLabels:missingLabels };
    }

    function requiredQuestionsValid() {
      requiredValidationActive = true;
      var state = updateRequiredQuestionMarks();

      if (state.firstInvalid) {
        state.firstInvalid.scrollIntoView({ behavior:"smooth", block:"center" });
        setStatus("Bitte alle Pflichtfragen beantworten. Erste offene Frage: " + state.missingLabels[0], true);
        return false;
      }
      return true;
    }

    function parseJsonArrayMaybe(s) {
      if (!s) return null;
      try { var v = JSON.parse(s); if (Array.isArray(v)) return v; } catch(e) {}
      return null;
    }

    function applyShowIf() {
      var nodes = formEl.querySelectorAll('[data-qwrap="1"][data-show-id]');
      nodes.forEach(function(el){
        var depId = el.getAttribute('data-show-id');
        var op = el.getAttribute('data-show-op') || 'equals';
        var val = el.getAttribute('data-show-val');

        var cur = getAnswerValue(depId);
        var show = true;

        if (op === 'equals') show = (String(cur) === String(val));
        else if (op === 'not_equals') show = (String(cur) !== String(val));
        else if (op === 'in') {
          var lst = parseJsonArrayMaybe(val) || [];
          show = (lst.map(String).indexOf(String(cur)) !== -1);
        } else if (op === 'any_selected_except') {
          var except = String(val || "");
          show = false;
          if (Array.isArray(cur)) {
            for (var i=0;i<cur.length;i++){
              var x = String(cur[i] || "");
              if (!x) continue;
              if (!except) { show = true; break; }
              if (x !== except) { show = true; break; }
            }
          }
        }

        el.classList.toggle('hidden', !show);
      });

      var secs = formEl.querySelectorAll('.section[data-show-id]');
      secs.forEach(function(el){
        var depId = el.getAttribute('data-show-id');
        var op = el.getAttribute('data-show-op') || 'equals';
        var val = el.getAttribute('data-show-val');

        var cur = getAnswerValue(depId);
        var show = true;

        if (op === 'equals') show = (String(cur) === String(val));
        else if (op === 'not_equals') show = (String(cur) !== String(val));
        else if (op === 'in') {
          var lst = parseJsonArrayMaybe(val) || [];
          show = (lst.map(String).indexOf(String(cur)) !== -1);
        } else if (op === 'any_selected_except') {
          var except = String(val || "");
          show = false;
          if (Array.isArray(cur)) {
            for (var i=0;i<cur.length;i++){
              var x = String(cur[i] || "");
              if (!x) continue;
              if (!except) { show = true; break; }
              if (x !== except) { show = true; break; }
            }
          }
        }

        el.classList.toggle('hidden', !show);
      });
    }

    formEl.addEventListener('change', applyShowIf);
    formEl.addEventListener('input', applyShowIf);
    formEl.addEventListener('change', updateRequiredQuestionMarks);
    formEl.addEventListener('input', updateRequiredQuestionMarks);
    applyShowIf();
    updateRequiredQuestionMarks();


    const POST_URL = 'questionnaire.php';
    let sending = false, idleTimer = null, lastTouch = 0, touching = false;
    const timeout = Number(formEl.dataset.timeout) * 1000;
    function clearAnswers() {
      formEl.reset();
      formEl.querySelectorAll('input:not([type=hidden]),textarea').forEach(x => {
        if (x.type === 'radio' || x.type === 'checkbox') x.checked = false;
        else x.value = '';
      });
    }
    function leave() { clearAnswers(); location.replace('./'); }
    function tokens(action) {
      const data = new FormData();
      for (const key of ['flowId', 'formToken', 'csrf']) data.append(key, formEl.elements[key].value);
      data.append('action', action); return data;
    }
    async function post(data) {
      const response = await fetch(POST_URL, {method:'POST', body:data, cache:'no-store', credentials:'same-origin', redirect:'error'});
      const result = await response.json();
      return {response, result};
    }
    async function submit(action) {
      if (sending || (action === 'form_submit' && !requiredQuestionsValid())) return;
      const data = action === 'form_submit' ? new FormData(formEl) : tokens(action);
      data.set('action', action);
      sending = true; clearTimeout(idleTimer);
      formEl.querySelectorAll('button,input,textarea').forEach(x => x.disabled = true);
      setStatus('Ihre Angaben werden übertragen …', false);
      try {
        const {response, result} = await post(data);
        if (response.ok && result.ok) {
          clearAnswers();
          location.replace(result.more === true ? 'questionnaire.php' : './');
        } else if (result.code === 'FORM_INPUT' && !result.blocked) {
          formEl.querySelectorAll('button,input,textarea').forEach(x => x.disabled = false);
          sending = false; setStatus(result.message, true); armIdle();
          statusEl.scrollIntoView({block:'center'});
        } else { leave(); }
      } catch (_) {
        // A lost response may hide a successful write. Reconcile at the main page; NEVER resend.
        leave();
      }
    }
    function armIdle() {
      clearTimeout(idleTimer);
      idleTimer = setTimeout(async () => {
        if (sending) return;
        sending = true; clearAnswers();
        try { await post(tokens('form_timeout')); } catch (_) {}
        leave();
      }, timeout);
    }
    function activity() {
      if (sending) return;
      armIdle();
      if (touching || Date.now() - lastTouch < 20000) return;
      lastTouch = Date.now(); touching = true;
      post(tokens('form_touch')).then(({response,result}) => {
        if ((!response.ok || !result.ok) && !sending) leave();
      }).catch(() => { if (!sending) leave(); }).finally(() => { touching = false; });
    }
    formEl.addEventListener('submit', event => { event.preventDefault(); submit('form_submit'); });
    submitBtn.addEventListener('click', () => submit('form_submit'));
    abortBtn.addEventListener('click', () => {
      if (confirm('Fragebögen überspringen? Ihre Anmeldung bleibt bestehen. Noch nicht gesendete Antworten werden verworfen.')) submit('form_abort');
    });
    document.addEventListener('pointerdown', activity, {passive:true});
    document.addEventListener('input', activity, {passive:true});
    document.addEventListener('keydown', activity, {passive:true});
    // Never restore a previous patient's answers from Safari's back-forward cache.
    window.addEventListener('pagehide', clearAnswers);
    window.addEventListener('pageshow', event => { if (event.persisted) leave(); });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden && !sending) { clearAnswers(); }
      else if (!sending) location.replace('./');
    });
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    window.scrollTo(0,0); armIdle();
})();
