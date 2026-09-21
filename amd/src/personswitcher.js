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
 * Person switcher of the navigation strip: jumps to the selected person's page.
 *
 * @module local_taskflow/personswitcher
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {markFormSubmitted, unWatchForm} from 'core_form/changechecker';

/**
 * Wire the switcher.
 *
 * @param {number} currentuserid The person shown on the page, 0 when none.
 */
export const init = (currentuserid) => {
    const switcher = document.querySelector('.local-taskflow-person-switcher select[name="id"]');
    if (!switcher) {
        return;
    }
    // The switcher is navigation, not data entry: keep it out of the form change checker so that
    // choosing a person never raises the "leave site?" prompt.
    unWatchForm(switcher.form);
    switcher.addEventListener('change', () => {
        const selected = parseInt(switcher.value, 10);
        if (selected && selected !== currentuserid) {
            markFormSubmitted(switcher.form);
            // Let the change event finish bubbling before leaving, so nothing re-marks the form.
            setTimeout(() => {
                window.location.href = M.cfg.wwwroot + '/local/taskflow/person.php?id=' + selected;
            }, 0);
        }
    });
};
