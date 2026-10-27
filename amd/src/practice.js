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
 * Practice library: filters the prompt cards by status.
 *
 * @module     mod_aispeakwrite/practice
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Sets up the status filter.
 */
export const init = () => {
    const root = document.getElementById('asw-practice');
    if (!root) {
        return;
    }
    const filters = root.querySelector('[data-region="filters"]');
    const cards = root.querySelectorAll('[data-region="cards"] [data-status]');
    filters.addEventListener('click', (e) => {
        const button = e.target.closest('[data-filter]');
        if (!button) {
            return;
        }
        const want = button.dataset.filter;
        filters.querySelectorAll('[data-filter]').forEach((b) => b.setAttribute('aria-pressed', b === button ? 'true' : 'false'));
        cards.forEach((card) => card.classList.toggle('asw-hidden', want !== 'all' && card.dataset.status !== want));
    });
};
