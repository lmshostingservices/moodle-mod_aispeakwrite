// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The speaking screen.
 *
 * Training mode: tap Speak, then Done, for each turn. Exam mode: listening starts by itself and a turn ends after a
 * pause. With a conversation task the AI partner answers each turn (text from LMS Labs, voice from the browser). Speech
 * is recognised by the learner's browser; only text and timing figures are sent to Moodle.
 *
 * @module     mod_aispeakwrite/speaker
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';
import {Listener, supported, meter, say} from 'mod_aispeakwrite/speech';

/** Level above which the learner counts as speaking. */
const SPEAKING = 0.06;
/** Seconds of silence that count as a long pause. */
const LONGPAUSE = 3;
/** Seconds of silence that end a turn in exam mode. */
const ENDTURN = 2.2;
/** Seconds per bar in the flow chart. */
const BUCKET = 5;

/**
 * Sets up the speaking screen.
 *
 * @param {string} selector
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const cfg = JSON.parse(root.dataset.config || '{}');
    const q = (name) => root.querySelector('[data-region="' + name + '"]');
    const els = {
        setup: q('setup'), session: q('session'), live: q('live'), clock: q('clock'), status: q('status'),
        interim: q('interim'), transcript: q('transcript'), remaining: q('remaining'), timebar: q('timebar'),
        result: q('result'), micstatus: q('micstatus'), meter: q('meter'), meter2: q('meter2'),
        nodeLearner: q('node-learner'), nodePartner: q('node-partner'), unsupported: q('unsupported'),
        turn: root.querySelector('[data-action="turn"]'), finish: root.querySelector('[data-action="finish"]'),
    };

    const state = {
        mode: 'training', turns: [], started: 0, listening: false, listener: null, mic: null, partnerOn: cfg.partner &&
            cfg.conversation, busy: false, silence: 0, pauseCounted: false, longpauses: 0, speakingSeconds: 0,
        flow: [], bucketSpeech: 0, bucketTime: 0, lastFrame: 0, timer: null, finished: false, heardInTurn: false,
    };

    if (!supported()) {
        els.unsupported.classList.remove('asw-hidden');
        root.querySelectorAll('[data-action="start"]').forEach((b) => {
            b.disabled = true;
        });
    }

    const bars = (container, level) => {
        if (!container) {
            return;
        }
        container.querySelectorAll('span').forEach((span, i) => {
            const shape = 0.4 + 0.6 * Math.abs(Math.sin((i + 1) * 1.7));
            span.style.height = Math.max(6, Math.round(level * 40 * shape + 6)) + 'px';
        });
        container.classList.toggle('asw-is-live', level > SPEAKING);
    };

    const fmt = (secs) => Math.floor(secs / 60) + ':' + String(Math.max(0, Math.floor(secs % 60))).padStart(2, '0');

    const setStatus = async(key, a) => {
        els.status.textContent = await getString(key, 'mod_aispeakwrite', a);
    };

    const active = (who) => {
        els.nodeLearner.classList.toggle('asw-is-active', who === 'learner');
        if (els.nodePartner) {
            els.nodePartner.classList.toggle('asw-is-active', who === 'partner');
        }
    };

    const addTurn = (role, text) => {
        state.turns.push({role: role, text: text});
        const row = document.createElement('div');
        row.className = 'asw-turn' + (role === 'partner' ? ' asw-turn--partner' : '');
        const who = document.createElement('span');
        who.className = 'asw-turn__who';
        who.setAttribute('aria-hidden', 'true');
        who.textContent = role === 'partner' ? 'AI' : '';
        if (role !== 'partner') {
            getString('you_short', 'mod_aispeakwrite').then((s) => {
                who.textContent = s;
                return s;
            }).catch(() => null);
        }
        const p = document.createElement('p');
        p.className = 'asw-turn__text';
        p.lang = cfg.locale;
        p.textContent = text;
        row.append(who, p);
        els.transcript.append(row);
    };

    // Microphone level: drives the meters, pause detection, speaking time and the flow chart.
    const onLevel = (level) => {
        bars(state.started ? els.meter2 : els.meter, level);
        const now = performance.now();
        const dt = state.lastFrame ? (now - state.lastFrame) / 1000 : 0;
        state.lastFrame = now;
        if (!state.listening || dt > 1) {
            return;
        }
        state.bucketTime += dt;
        if (level > SPEAKING) {
            state.speakingSeconds += dt;
            state.bucketSpeech += dt;
            state.silence = 0;
            state.pauseCounted = false;
            state.heardInTurn = true;
        } else {
            state.silence += dt;
            if (state.heardInTurn && state.silence >= LONGPAUSE && !state.pauseCounted) {
                state.longpauses++;
                state.pauseCounted = true;
            }
            if (state.mode === 'exam' && state.heardInTurn && state.silence >= ENDTURN && !state.busy) {
                endLearnerTurn();
            }
        }
        if (state.bucketTime >= BUCKET) {
            state.flow.push(Math.round(Math.min(1, state.bucketSpeech / state.bucketTime) * 9));
            state.bucketTime = 0;
            state.bucketSpeech = 0;
        }
    };

    const openMic = async() => {
        if (state.mic) {
            return true;
        }
        try {
            state.mic = await meter(onLevel);
            els.micstatus.textContent = await getString('micok', 'mod_aispeakwrite');
            return true;
        } catch (e) {
            els.micstatus.textContent = await getString('micblocked', 'mod_aispeakwrite');
            return false;
        }
    };

    const listen = async() => {
        state.listener = new Listener(cfg.locale, (text) => {
            els.interim.textContent = text;
        });
        state.listener.start();
        state.listening = true;
        state.silence = 0;
        state.heardInTurn = false;
        active('learner');
        els.turn.textContent = await getString('donespeaking', 'mod_aispeakwrite');
        await setStatus('listeningyourturn');
    };

    const partnerTurn = async() => {
        state.busy = true;
        active(null);
        els.turn.disabled = true;
        await setStatus('partnerthinking');
        let reply = '';
        try {
            const res = await Ajax.call([{methodname: 'mod_aispeakwrite_converse', args: {
                cmid: cfg.cmid, subid: cfg.subid, turns: state.turns,
            }}])[0];
            if (res.ok && res.reply) {
                reply = res.reply;
            } else if (!res.ok) {
                state.partnerOn = false;
                els.result.textContent = res.message;
            }
        } catch (e) {
            state.partnerOn = false;
        }
        if (reply !== '') {
            addTurn('partner', reply);
            active('partner');
            await setStatus('partnerspeaking');
            await say(reply, cfg.locale);
        }
        state.busy = false;
        els.turn.disabled = false;
        active(null);
        if (state.finished) {
            return;
        }
        if (state.mode === 'exam') {
            await listen();
        } else {
            els.turn.textContent = await getString('speak', 'mod_aispeakwrite');
            await setStatus('yourturn');
        }
    };

    const endLearnerTurn = async() => {
        if (!state.listening || state.busy) {
            return;
        }
        state.busy = true;
        state.listening = false;
        const text = await state.listener.stop();
        els.interim.textContent = '';
        active(null);
        state.busy = false;
        if (text !== '') {
            addTurn('learner', text);
        }
        if (state.finished) {
            return;
        }
        if (state.partnerOn && text !== '') {
            await partnerTurn();
        } else if (state.mode === 'exam') {
            await listen();
        } else {
            els.turn.textContent = await getString('speak', 'mod_aispeakwrite');
            await setStatus('yourturn');
        }
    };

    const tick = () => {
        const secs = (Date.now() - state.started) / 1000;
        const limit = cfg.seconds * (state.mode === 'exam' ? 1 : 2);
        els.clock.textContent = fmt(secs);
        els.remaining.textContent = fmt(limit - secs);
        els.timebar.style.width = Math.min(100, secs / limit * 100) + '%';
        if (secs >= limit && !state.finished) {
            finish();
        }
    };

    const start = async(who) => {
        if (!(await openMic())) {
            return;
        }
        els.setup.classList.add('asw-hidden');
        els.session.classList.remove('asw-hidden');
        els.live.classList.remove('asw-hidden');
        state.started = Date.now();
        state.timer = window.setInterval(tick, 500);
        tick();
        if (state.mode === 'exam') {
            els.turn.classList.add('asw-hidden');
        }
        if (who === 'partner' && state.partnerOn) {
            await partnerTurn();
        } else if (state.mode === 'exam') {
            await listen();
        } else {
            els.turn.textContent = await getString('speak', 'mod_aispeakwrite');
            await setStatus('yourturn');
        }
    };

    const finish = async() => {
        if (state.finished) {
            return;
        }
        state.finished = true;
        window.clearInterval(state.timer);
        if (state.listening) {
            state.listening = false;
            const text = await state.listener.stop();
            if (text !== '') {
                addTurn('learner', text);
            }
        }
        if (window.speechSynthesis) {
            window.speechSynthesis.cancel();
        }
        active(null);
        els.turn.disabled = true;
        els.finish.disabled = true;
        if (state.bucketTime > 1) {
            state.flow.push(Math.round(Math.min(1, state.bucketSpeech / state.bucketTime) * 9));
        }
        await submit();
    };

    const submit = async() => {
        if (!state.turns.some((t) => t.role === 'learner')) {
            els.result.textContent = await getString('nothingheard', 'mod_aispeakwrite');
            state.finished = false;
            els.finish.disabled = false;
            els.turn.disabled = false;
            return;
        }
        els.result.textContent = await getString('marking', 'mod_aispeakwrite');
        try {
            const res = await Ajax.call([{methodname: 'mod_aispeakwrite_submit_speaking', args: {
                cmid: cfg.cmid, subid: cfg.subid, turns: state.turns,
                seconds: Math.round((Date.now() - state.started) / 1000),
                speakingseconds: Math.round(state.speakingSeconds), longpauses: state.longpauses,
                flow: state.flow.join(','),
            }}])[0];
            if (res.ok) {
                if (state.mic) {
                    state.mic.stop();
                }
                window.location.href = res.url;
                return;
            }
            els.result.textContent = res.message;
        } catch (e) {
            els.result.textContent = e.message || '';
        }
        // Let the learner try again: the same request is sent, so nothing is charged twice.
        els.finish.disabled = false;
        els.finish.textContent = await getString('checkagain', 'mod_aispeakwrite');
        els.finish.onclick = (e) => {
            e.stopImmediatePropagation();
            els.finish.disabled = true;
            submit();
        };
    };

    root.addEventListener('click', async(e) => {
        const mode = e.target.closest('[data-mode]');
        if (mode) {
            state.mode = mode.dataset.mode;
            root.querySelectorAll('[data-mode]').forEach((b) => b.setAttribute('aria-pressed', b === mode ? 'true' : 'false'));
            return;
        }
        const action = e.target.closest('[data-action]');
        if (!action || action.disabled) {
            return;
        }
        switch (action.dataset.action) {
            case 'mictest':
                await openMic();
                break;
            case 'start':
                await start(action.dataset.who);
                break;
            case 'turn':
                if (state.busy) {
                    return;
                }
                if (state.listening) {
                    await endLearnerTurn();
                } else {
                    await listen();
                }
                break;
            case 'finish':
                await finish();
                break;
            case 'restart':
                window.location.reload();
                break;
        }
    });

    if (cfg.pending) {
        // A marking was sent but no answer arrived: offer to ask again with the same request.
        root.querySelectorAll('[data-action="start"]').forEach((b) => {
            b.disabled = true;
        });
        els.setup.classList.add('asw-hidden');
        els.session.classList.remove('asw-hidden');
        state.started = Date.now();
        state.finished = true;
        els.turn.classList.add('asw-hidden');
        els.finish.onclick = (e) => {
            e.stopImmediatePropagation();
            els.finish.disabled = true;
            state.turns = [{role: 'learner', text: '-'}];
            submit();
        };
    }
};
