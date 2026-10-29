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
 * The writing screen: autosave, word count, special characters, phrases, the time plan and submitting.
 *
 * @module     mod_aispeakwrite/writer
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';

/**
 * Plain text that Moodle's PARAM_TEXT keeps exactly: a "<" that could start a tag gets a space after it,
 * so "3<4" is sent as "3< 4" instead of losing text.
 *
 * @param {String} text
 * @returns {String}
 */
const safeText = (text) => text.replace(/<(?=\S|$)/g, '< ');

/**
 * Inserts text at the cursor of a text box.
 *
 * The insertion is marked as coming from the screen's own buttons (phrases, special characters), so a typing
 * monitor such as Essay Guard does not read it as pasted text.
 *
 * @param {HTMLTextAreaElement} box
 * @param {string} text
 */
const insert = (box, text) => {
    const start = box.selectionStart ?? box.value.length;
    const end = box.selectionEnd ?? box.value.length;
    const before = box.value.slice(0, start);
    let add = text;
    if (text.length > 1 && before !== '' && !/\s$/.test(before)) {
        add = ' ' + add;
    }
    box.value = before + add + box.value.slice(end);
    const pos = start + add.length;
    box.focus();
    box.setSelectionRange(pos, pos);
    box.dataset.essayguardBenign = 'ui';
    try {
        box.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertReplacementText', data: add}));
    } finally {
        delete box.dataset.essayguardBenign;
    }
};

/**
 * Line endings differ between what was copied and what the clipboard gives back.
 *
 * @param {string} text
 * @returns {string}
 */
const norm = (text) => (text || '').replace(/\r\n?/g, '\n');

/**
 * When a typing monitor (Essay Guard) runs on the page, lets it send what it has recorded before the answer is
 * submitted, so it scores the whole writing session. Waits five seconds at most.
 *
 * @returns {Promise}
 */
const drainMonitor = () => new Promise((resolve) => {
    if (document.documentElement.dataset.essayguardActive !== '1') {
        resolve();
        return;
    }
    window.setTimeout(resolve, 5000);
    document.dispatchEvent(new CustomEvent('essayguard:drain', {detail: {done: resolve}}));
});

/**
 * Sets up the writing screen.
 *
 * @param {number} cmid
 * @param {number} subid
 * @param {string} pastemode allow, log or block: pasting from outside the answer box
 */
export const init = (cmid, subid, pastemode = 'log') => {
    const root = document.getElementById('asw-writer-' + subid);
    if (!root) {
        return;
    }
    const answer = root.querySelector('[data-region="answer"]');
    const notes = root.querySelector('[data-region="notes"]');
    const words = root.querySelector('[data-region="words"]');
    const saved = root.querySelector('[data-region="saved"]');
    const status = root.querySelector('[data-region="status"]');
    const submit = root.querySelector('[data-action="submit"]');
    const elapsed = root.querySelector('[data-region="elapsed"]');
    const phases = root.querySelectorAll('[data-region="phases"] [data-phase]');
    const blockednote = root.querySelector('[data-region="pasteblocked"]');
    let target = answer;
    let timer = null;
    let dirty = false;
    let busy = false;

    // Writing integrity counts since the last save. Sent with the next save and added up on the server.
    const counts = {pastes: 0, pastechars: 0, pasteblocked: 0, tabswitches: 0};
    const take = () => {
        const sent = Object.assign({}, counts);
        Object.keys(counts).forEach((k) => {
            counts[k] = 0;
        });
        return sent;
    };
    const giveback = (sent) => {
        Object.keys(counts).forEach((k) => {
            counts[k] += sent[k];
        });
    };

    const count = () => {
        const t = answer.value.trim();
        words.textContent = t === '' ? 0 : t.split(/\s+/).length;
    };

    const save = async() => {
        if (!dirty || busy || answer.readOnly) {
            return;
        }
        dirty = false;
        const sent = take();
        try {
            await Ajax.call([{methodname: 'mod_aispeakwrite_save_draft', args: Object.assign({
                cmid: cmid, subid: subid, content: safeText(answer.value), notes: safeText(notes ? notes.value : ''),
            }, sent)}])[0];
            if (saved) {
                saved.textContent = await getString('draftsaved', 'mod_aispeakwrite');
            }
        } catch (e) {
            giveback(sent);
            dirty = true;
        }
    };

    const schedule = () => {
        dirty = true;
        window.clearTimeout(timer);
        timer = window.setTimeout(save, 1500);
    };

    answer.addEventListener('input', () => {
        count();
        schedule();
    });
    answer.addEventListener('focus', () => {
        target = answer;
    });
    if (notes) {
        notes.addEventListener('input', schedule);
        notes.addEventListener('focus', () => {
            target = notes;
        });
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            // eslint-disable-next-line no-use-before-define
            leave();
            save();
        }
    });

    // Pasting. Text cut or copied inside the answer box and pasted back is always allowed and never counted.
    // Pastes from anywhere else follow the activity's setting. Listening in the capture phase on the document
    // means a blocked paste is stopped before any other script, such as Essay Guard, sees it.
    let internal = null;
    let dragging = false;
    let lastpaste = 0;
    const remember = () => {
        internal = norm(answer.value.slice(answer.selectionStart, answer.selectionEnd));
    };
    answer.addEventListener('copy', remember);
    answer.addEventListener('cut', remember);
    answer.addEventListener('dragstart', () => {
        dragging = true;
    });
    answer.addEventListener('dragend', () => {
        dragging = false;
    });

    const benign = () => {
        answer.dataset.essayguardBenign = 'internal';
        window.setTimeout(() => {
            delete answer.dataset.essayguardBenign;
        }, 0);
    };
    const outside = async(e, length) => {
        if (pastemode === 'block') {
            e.preventDefault();
            e.stopImmediatePropagation();
            counts.pasteblocked++;
            schedule();
            if (blockednote) {
                blockednote.textContent = await getString('pasteblockedmsg', 'mod_aispeakwrite');
            }
            return;
        }
        if (pastemode === 'log') {
            counts.pastes++;
            counts.pastechars += length;
            schedule();
        }
    };

    document.addEventListener('paste', (e) => {
        if (e.target !== answer || answer.readOnly) {
            return;
        }
        lastpaste = Date.now();
        const cd = e.clipboardData || window.clipboardData;
        const text = norm(cd ? cd.getData('text/plain') || cd.getData('text') : '');
        if (text === '') {
            return;
        }
        if (internal !== null && text === internal) {
            benign();
            return;
        }
        outside(e, text.length);
    }, true);

    document.addEventListener('drop', (e) => {
        if (e.target !== answer || answer.readOnly) {
            return;
        }
        lastpaste = Date.now();
        if (dragging) {
            benign();
            return;
        }
        const text = norm(e.dataTransfer ? e.dataTransfer.getData('text/plain') || e.dataTransfer.getData('text') : '');
        if (text !== '') {
            outside(e, text.length);
        }
    }, true);

    // Some keyboards (phone clipboard suggestions) paste without a paste event.
    answer.addEventListener('beforeinput', (e) => {
        if ((e.inputType !== 'insertFromPaste' && e.inputType !== 'insertFromDrop') || Date.now() - lastpaste < 200) {
            return;
        }
        if (answer.dataset.essayguardBenign) {
            return;
        }
        outside(e, (e.data || '').length);
    });

    // Leaving the writing screen: another tab, another window or another app. Counted once per time away.
    let away = false;
    const leave = () => {
        if (!away && !answer.readOnly) {
            away = true;
            counts.tabswitches++;
            schedule();
        }
    };
    window.addEventListener('blur', leave);
    window.addEventListener('focus', () => {
        away = false;
    });

    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-insert]');
        if (!button || answer.readOnly) {
            return;
        }
        e.preventDefault();
        insert(target || answer, button.dataset.insert);
    });

    // Time plan: the clock counts up and the current phase is highlighted.
    const started = Date.now();
    const tick = () => {
        const secs = Math.floor((Date.now() - started) / 1000);
        elapsed.textContent = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
        let edge = 0;
        const minutes = secs / 60;
        phases.forEach((p) => {
            const from = edge;
            edge += Number(p.dataset.min);
            p.classList.toggle('asw-phase--done', minutes >= edge);
            p.classList.toggle('asw-phase--now', minutes >= from && minutes < edge);
        });
    };
    tick();
    window.setInterval(tick, 1000);

    if (submit) {
        submit.addEventListener('click', async() => {
            if (busy) {
                return;
            }
            busy = true;
            window.clearTimeout(timer);
            submit.disabled = true;
            status.textContent = await getString('marking', 'mod_aispeakwrite');
            await drainMonitor();
            const sent = take();
            try {
                const result = await Ajax.call([{methodname: 'mod_aispeakwrite_submit', args: Object.assign({
                    cmid: cmid, subid: subid, content: safeText(answer.value), notes: safeText(notes ? notes.value : ''),
                }, sent)}])[0];
                if (result.ok) {
                    window.location.href = result.url;
                    return;
                }
                status.textContent = result.message;
                if (result.pending) {
                    answer.readOnly = true;
                    submit.textContent = await getString('checkagain', 'mod_aispeakwrite');
                }
            } catch (e) {
                giveback(sent);
                status.textContent = e.message || await getString('marknetworkerror', 'mod_aispeakwrite');
            }
            submit.disabled = false;
            busy = false;
        });
    }
};
