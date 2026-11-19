// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Search field and "hide completed" checkbox of the [myassignmentsdashboard] shortcode.
 *
 * Filters the assignment cards in the browser, the key figures above stay unchanged.
 *
 * @module     local_taskflow/myassignmentsdashboard
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    SEARCH: '[data-region="tf-search"]',
    HIDECOMPLETED: '[data-region="tf-hidecompleted"]',
    ASSIGNMENT: '[data-region="tf-assignment"]',
    NOMATCH: '[data-region="tf-nomatch"]',
};

/**
 * Initialise the filter of one dashboard.
 *
 * @param {string} uniqid Unique id of the dashboard, see the template.
 */
export const init = (uniqid) => {
    const root = document.getElementById('local-taskflow-mydashboard-' + uniqid);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';

    const search = root.querySelector(SELECTORS.SEARCH);
    const hidecompleted = root.querySelector(SELECTORS.HIDECOMPLETED);
    if (!search || !hidecompleted) {
        return;
    }

    const apply = () => filterAssignments(root, search.value, hidecompleted.checked);
    search.addEventListener('input', apply);
    hidecompleted.addEventListener('change', apply);
    apply();
};

/**
 * Shows the assignments that contain all search words and, if wanted, are not completed.
 *
 * @param {HTMLElement} root The dashboard.
 * @param {string} query Text of the search field.
 * @param {boolean} hidecompleted Whether completed assignments are hidden.
 */
const filterAssignments = (root, query, hidecompleted) => {
    const words = query.trim().toLowerCase().split(/\s+/).filter((word) => word !== '');
    let visible = 0;

    root.querySelectorAll(SELECTORS.ASSIGNMENT).forEach((assignment) => {
        const text = assignment.dataset.search || '';
        const show = !(hidecompleted && assignment.dataset.completed === '1')
            && words.every((word) => text.includes(word));
        assignment.hidden = !show;
        if (show) {
            visible++;
        }
    });

    // Role status: the text is read out when it appears.
    const nomatch = root.querySelector(SELECTORS.NOMATCH);
    if (nomatch) {
        nomatch.textContent = visible === 0 ? nomatch.dataset.text : '';
    }
};
