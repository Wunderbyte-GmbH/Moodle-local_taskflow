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
 * Person page: opens the "assign rule / curriculum" modal.
 *
 * @module local_taskflow/personpage
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';
import {init as initSwitcher} from 'local_taskflow/personswitcher';

/**
 * Initialise the assign buttons of the person page.
 *
 * @param {number} userid The user shown on the page.
 */
export const init = (userid) => {
    initSwitcher(userid);

    document.querySelectorAll('[data-action="addpersonnote"]').forEach((trigger) => {
        trigger.addEventListener('click', async(e) => {
            e.preventDefault();
            const title = await getString('addpersonnote', 'local_taskflow');
            const success = await getString('addpersonnote_success', 'local_taskflow');
            const modal = new ModalForm({
                formClass: 'local_taskflow\\form\\add_person_note',
                args: {userid: userid},
                modalConfig: {title: title},
                saveButtonText: title,
            });
            modal.addEventListener(modal.events.FORM_SUBMITTED, () => {
                Notification.addNotification({message: success, type: 'success', closeButton: true});
                setTimeout(() => window.location.reload(), 800);
            });
            modal.show();
        });
    });

    document.querySelectorAll('[data-action="assignrule"]').forEach((trigger) => {
        trigger.addEventListener('click', async(e) => {
            e.preventDefault();
            const title = await getString('assignrule', 'local_taskflow');
            const success = await getString('assignrule_success', 'local_taskflow');

            const modal = new ModalForm({
                formClass: 'local_taskflow\\form\\assign_rule_to_user',
                args: {userid: userid},
                modalConfig: {title: title},
                saveButtonText: title,
            });

            modal.addEventListener(modal.events.FORM_SUBMITTED, () => {
                Notification.addNotification({message: success, type: 'success', closeButton: true});
                setTimeout(() => window.location.reload(), 800);
            });

            modal.show();
        });
    });
};
