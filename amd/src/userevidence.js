/**
 * JS for handling the upload users modal in a form.
 *
 * @module local_taskflow/userevidence
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';

/**
 * Initialize the upload users modal.
 *
 * @param {number} userid - The user ID to pass to the modal form.
 */
export const init = (userid) => {
    initUploadModal(userid);
    initDeleteButtons(userid);
};

const initUploadModal = (userid) => {
    const triggers = document.querySelectorAll('[data-action="openuserevidencemodal"]');

    if (!triggers.length) {
        return;
    }

    triggers.forEach(function(trigger) {
        trigger.addEventListener('click', async function(e) {
            e.preventDefault();

            const title = await getString('uploadevidence', 'local_taskflow');

            const args = {};
            Object.entries(this.dataset).forEach(([key, value]) => {
                args[key] = value;
            });

            // Always ensure userid is included
            args.userid = userid;

            const modal = new ModalForm({
                formClass: 'local_taskflow\\form\\userevidence',
                args: args,
                modalConfig: {
                    title: title,
                }
            });

            modal.addEventListener(modal.events.FORM_SUBMITTED, () => {
                Notification.addNotification({
                    message: 'Your upload was successful!',
                    type: 'success',
                    closeButton: true,
                });

                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            });

            modal.show();
        });
    });
};

const initDeleteButtons = (userid) => {
    const triggers = document.querySelectorAll('[data-action="deleteuserevidence"]');

    if (!triggers.length) {
        return;
    }

    triggers.forEach(function(trigger) {
        trigger.addEventListener('click', async function(e) {
            e.preventDefault();

            const title = await getString('uploadevidence', 'local_taskflow');

            const args = {};
            Object.entries(this.dataset).forEach(([key, value]) => {
                args[key] = value;
            });

            // Always ensure userid is included
            args.userid = userid;

            const modal = new ModalForm({
                formClass: 'local_taskflow\\form\\delete_userevidence',
                args: args,
                modalConfig: {
                    title: title,
                }
            });

            modal.addEventListener(modal.events.FORM_SUBMITTED, () => {
                Notification.addNotification({
                    message: 'Your upload was successful!',
                    type: 'success',
                    closeButton: true,
                });

                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            });

            modal.show();
        });
    });
};
