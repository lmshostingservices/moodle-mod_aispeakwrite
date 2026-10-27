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
 * The feedback screen: links marks and notes, reveals examples, and checks "say it again" in the browser.
 *
 * @module     mod_aispeakwrite/feedback
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import {Listener, supported, match, say} from 'mod_aispeakwrite/speech';

const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Sets up the feedback screen.
 *
 * @param {string} selector
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    let locale = 'de-DE';
    try {
        locale = JSON.parse(root.dataset.speak || '{}').locale || locale;
    } catch (e) {
        // Keep the default.
    }

    const select = (id, scroll) => {
        root.querySelectorAll('.asw-is-selected').forEach((el) => el.classList.remove('asw-is-selected'));
        const mark = root.querySelector('[data-mark="' + id + '"]');
        const note = root.querySelector('[data-note="' + id + '"]');
        if (mark) {
            mark.classList.add('asw-is-selected');
        }
        if (note) {
            note.classList.add('asw-is-selected');
        }
        const target = scroll === 'note' ? note : mark;
        if (target) {
            target.scrollIntoView({block: 'nearest', behavior: reduce ? 'auto' : 'smooth'});
        }
    };

    const repeat = async(button) => {
        const out = button.parentElement.querySelector('[data-region="repeat-result"]');
        if (!supported()) {
            out.textContent = await getString('browserunsupported', 'mod_aispeakwrite');
            return;
        }
        button.disabled = true;
        out.textContent = await getString('listening', 'mod_aispeakwrite');
        const listener = new Listener(locale, () => null);
        listener.start();
        window.setTimeout(async() => {
            const heard = await listener.stop();
            button.disabled = false;
            if (heard === '') {
                out.textContent = await getString('nothingheard', 'mod_aispeakwrite');
            } else if (match(heard, button.dataset.repeat) >= 0.8) {
                out.textContent = await getString('gotit', 'mod_aispeakwrite', heard);
            } else {
                out.textContent = await getString('weheard', 'mod_aispeakwrite', heard);
            }
        }, 6000);
    };

    root.addEventListener('click', (e) => {
        const mark = e.target.closest('[data-mark]');
        if (mark) {
            select(mark.dataset.mark, 'note');
            return;
        }
        const pick = e.target.closest('[data-select]');
        if (pick) {
            select(pick.dataset.select, 'mark');
            return;
        }
        const reveal = e.target.closest('[data-reveal]');
        if (reveal) {
            const region = root.querySelector('[data-region="reveal-' + reveal.dataset.reveal + '"]');
            if (region) {
                region.classList.remove('asw-hidden');
                reveal.setAttribute('aria-expanded', 'true');
                reveal.closest('.asw-row').classList.add('asw-hidden');
            }
            return;
        }
        const again = e.target.closest('[data-repeat]');
        if (again) {
            repeat(again);
            return;
        }
        const listen = e.target.closest('[data-listen]');
        if (listen) {
            say(listen.dataset.listen, locale);
        }
    });
};
