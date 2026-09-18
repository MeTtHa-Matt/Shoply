const ui = document.querySelector('[data-siri-ui]');
const trigger = document.querySelector('.gemini-trigger');
const island = document.querySelector('[data-siri-island]');
const result = document.querySelector('[data-siri-result]');
const conversation = document.querySelector('[data-siri-conversation]');
const status = document.querySelector('[data-siri-status]');
const transcript = document.querySelector('[data-siri-transcript]');
const answer = document.querySelector('[data-siri-answer]');
const retry = document.querySelector('[data-siri-retry]');
const expand = document.querySelector('[data-siri-expand]');
const thread = document.querySelector('[data-siri-thread]');
const composer = document.querySelector('[data-siri-composer]');
const csrf = document.querySelector('input[name="csrf_token"]');
const list = document.querySelector('[data-list-id]');
const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

if (ui && trigger && csrf) {
    const states = Object.freeze({ idle: 'idle', activating: 'activating', listening: 'listening', thinking: 'thinking', result: 'result', conversation: 'conversation' });
    let state = states.idle;
    let recognition = null;
    let pending = null;
    let lastFocus = trigger;
    let pointerStart = 0;
    let conversationMode = false;
    let requestController = null;
    let closing = false;

    function setState(next) {
        state = next;
        ui.dataset.state = next;
        ui.hidden = next === states.idle;
        ui.setAttribute('aria-hidden', next === states.idle ? 'true' : 'false');
        ui.classList.toggle('is-open', next !== states.idle);
        ui.classList.toggle('is-conversation', conversationMode || next === states.conversation);
        if (island) island.setAttribute('aria-hidden', next === states.idle ? 'true' : 'false');
        if (conversation) conversation.hidden = !(conversationMode || next === states.conversation);
    }

    function speak(text, done) {
        if (!('speechSynthesis' in window)) { if (done) done(); return; }
        window.speechSynthesis.cancel();
        const speechText = String(text)
            .replace(/["“”«»„‟]/g, '')
            .replace(/[\u2018\u2019]/g, "'")
            .replace(/\s+/g, ' ')
            .trim();
        if (!speechText) { if (done) done(); return; }
        const utterance = new SpeechSynthesisUtterance(speechText);
        utterance.lang = 'fr-FR';
        utterance.rate = 1.14;
        utterance.onend = done || null;
        window.speechSynthesis.speak(utterance);
    }

    function addMessage(text, role) {
        const message = document.createElement('p');
        message.className = 'siri-message ' + role;
        message.textContent = text;
        thread.appendChild(message);
        thread.scrollTop = thread.scrollHeight;
    }

    function addTyping() {
        const typing = document.createElement('p');
        typing.className = 'siri-message assistant siri-typing';
        for (let index = 0; index < 3; index++) typing.appendChild(document.createElement('i'));
        thread.appendChild(typing);
        thread.scrollTop = thread.scrollHeight;
        return typing;
    }

    function renderResponse(text) {
        answer.textContent = text || '';
        answer.hidden = !text;
    }

    function close() {
        closing = true;
        if (recognition) recognition.abort();
        recognition = null;
        pending = null;
        if (requestController) requestController.abort();
        requestController = null;
        conversationMode = false;
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();
        setState(states.idle);
        (lastFocus || trigger).focus();
    }

    function focusableElements() {
        return Array.from(ui.querySelectorAll('button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])')).filter(function (element) {
            return !element.hidden && element.offsetParent !== null;
        });
    }

    function trapFocus(event) {
        if (state === states.idle || event.key !== 'Tab') return;
        const elements = focusableElements();
        if (!elements.length) return;
        const first = elements[0];
        const last = elements[elements.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function requestMicrophone() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject(new Error('Le microphone n’est pas disponible dans ce navigateur.'));
        return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
        });
    }

    function sendCommand(text) {
        setState(states.thinking);
        status.textContent = 'Je réfléchis...';
        const body = { csrf_token: csrf.value, list_id: list ? list.dataset.listId : '0', transcription: text };
        if (pending) {
            body.confirm_create = 'true';
            body.pending_target_list = pending.target_list || '';
            body.pending_item = pending.item || '';
            body.pending_context = JSON.stringify(pending);
        }
        pending = null;
        if (requestController) requestController.abort();
        requestController = new AbortController();
        const timeout = window.setTimeout(function () { requestController.abort(); }, 25000);
        return fetch('api/voice-command.php', {
            method: 'POST',
            credentials: 'same-origin',
            signal: requestController.signal,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
            body: new URLSearchParams(body)
        }).then(function (response) {
            return response.text().then(function (raw) {
                let data;
                try { data = JSON.parse(raw); } catch (error) { throw new Error('Je n’ai pas pu traiter votre demande.'); }
                if (!response.ok || !data || data.ok !== true) throw new Error(data.message || 'Je n’ai pas pu traiter votre demande.');
                return data;
            });
        }).catch(function (error) {
            if (error.name === 'AbortError') throw new Error('La demande a pris trop de temps ou a été annulée.');
            if (error instanceof TypeError) throw new Error('Je n’ai pas pu joindre le service. Vérifiez votre connexion.');
            throw error;
        }).finally(function () {
            window.clearTimeout(timeout);
            requestController = null;
        });
    }

    function handleResult(text) {
        if (closing) return;
        transcript.textContent = text;
        addMessage(text, 'user');
        const typing = addTyping();
        sendCommand(text).then(function (data) {
            if (closing || state === states.idle) return;
            typing.remove();
            setState(states.result);
            renderResponse(data.message_to_user);
            addMessage(data.message_to_user, 'assistant');
            speak(data.message_to_user, function () {
                if (closing) return;
                if (data.pending) {
                    pending = data.pending;
                    startListening();
                } else if (data.action === 'add') {
                    close();
                }
            });
        }).catch(function (error) {
            if (state === states.idle) return;
            typing.remove();
            renderResponse(error.message || 'Je n’ai pas pu traiter votre demande.');
            retry.hidden = false;
            addMessage(error.message || 'Je n’ai pas pu traiter votre demande.', 'assistant');
            speak(error.message || 'Je n’ai pas pu traiter votre demande.');
            console.error('[Shoply voice]', error);
            setState(states.result);
        });
    }

    function startListening() {
        closing = false;
        if (!Recognition) { renderResponse('La reconnaissance vocale n’est pas disponible.'); setState(states.result); return; }
        setState(states.activating);
        status.textContent = 'Autorisation du microphone...';
        renderResponse('');
        requestMicrophone().then(function () {
            if (closing || state === states.idle) return;
            recognition = new Recognition();
            recognition.lang = 'fr-FR';
            recognition.interimResults = true;
            recognition.maxAlternatives = 3;
            let finalText = '';
            setState(states.listening);
            status.textContent = 'Je t’écoute...';
            recognition.onresult = function (event) {
                let text = '';
                for (let index = 0; index < event.results.length; index++) text += event.results[index][0].transcript;
                text = text.trim();
                transcript.textContent = text;
                if (event.results[event.results.length - 1].isFinal) finalText = text;
            };
            recognition.onerror = function () { if (closing) return; renderResponse('Je n’ai pas pu traiter votre demande.'); setState(states.result); };
            recognition.onend = function () { recognition = null; if (closing || state === states.idle) return; if (finalText) handleResult(finalText); else { renderResponse('Je n’ai pas compris ce que vous souhaitez.'); setState(states.result); } };
            recognition.start();
        }).catch(function (error) { if (closing || state === states.idle) return; renderResponse(error.message || 'Je n’ai pas pu accéder au microphone.'); setState(states.result); });
    }

    function openConversation() { conversationMode = true; setState(states.conversation); conversation.hidden = false; conversation.querySelector('input').focus(); }
    trigger.addEventListener('click', function () { lastFocus = document.activeElement; conversationMode = false; startListening(); });
    expand.addEventListener('click', openConversation);
    retry.addEventListener('click', function () { retry.hidden = true; startListening(); });
    composer.addEventListener('submit', function (event) { event.preventDefault(); const input = composer.querySelector('input'); const text = input.value.trim(); if (!text) return; input.value = ''; handleResult(text); });
    composer.querySelector('[data-siri-compose-mic]').addEventListener('click', startListening);
    ui.querySelectorAll('[data-siri-close]').forEach(function (button) { button.addEventListener('click', close); });
    result.addEventListener('pointerdown', function (event) { pointerStart = event.clientY; result.setPointerCapture(event.pointerId); });
    result.addEventListener('pointerup', function (event) { const delta = event.clientY - pointerStart; if (delta > 60) openConversation(); if (delta < -60) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && state !== states.idle) close(); trapFocus(event); });
}