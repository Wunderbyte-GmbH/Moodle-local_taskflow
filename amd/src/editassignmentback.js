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

/*
 * @package    local_taskflow
 * @author     Jacob Viertel
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic assignments form.
 *
 * @module     local_taskflow/editassignmentback
 * @copyright  2025 Wunderbyte GmbH
 * @author     Jacob Viertel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    const init = (selector) => {
        const btn = document.querySelector(selector);
        if (!btn) {
            return;
        }
        const state = window.history.state || {};
        let oldlength = state.oldlength;

        if (typeof oldlength !== 'number') {
            oldlength = window.history.length - 1;
            window.history.replaceState({...state, oldlength}, '', window.location.href);
        }
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (typeof oldlength === 'number') {
                const backsteps = oldlength - window.history.length;
                window.history.go(backsteps);
            } else {
                window.history.back();
            }
        });
    };

    return {init};
});
