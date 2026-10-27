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
 * Inserts text at the cursor of a text box.
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
    box.dispatchEvent(new Event('input', {bubbles: true}));
};

/**
 * Sets up the writing screen.
 *
 * @param {number} cmid
 * @param {number} subid
 */
export const init = (cmid, subid) => {
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
    let target = answer;
    let timer = null;
    let dirty = false;
    let busy = false;

    const count = () => {
        const t = answer.value.trim();
        words.textContent = t === '' ? 0 : t.split(/\s+/).length;
    };

    const save = async() => {
        if (!dirty || busy || answer.readOnly) {
            return;
        }
        dirty = false;
        try {
            await Ajax.call([{methodname: 'mod_aispeakwrite_save_draft', args: {
                cmid: cmid, subid: subid, content: answer.value, notes: notes ? notes.value : '',
            }}])[0];
            if (saved) {
                saved.textContent = await getString('draftsaved', 'mod_aispeakwrite');
            }
        } catch (e) {
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
            save();
        }
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
            try {
                const result = await Ajax.call([{methodname: 'mod_aispeakwrite_submit', args: {
                    cmid: cmid, subid: subid, content: answer.value, notes: notes ? notes.value : '',
                }}])[0];
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
                status.textContent = e.message || await getString('marknetworkerror', 'mod_aispeakwrite');
            }
            submit.disabled = false;
            busy = false;
        });
    }
};
