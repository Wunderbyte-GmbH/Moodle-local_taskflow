/**
 * JS for handling the upload users modal in a form.
 *
 * @module local_taskflow/uploadusers
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
    const trigger = document.querySelector('[data-action="openuserevidencemodal"]');
    if (!trigger) {
        return;
    }

    trigger.addEventListener('click', async(e) => {
        e.preventDefault();

        const title = await getString('uploadevidence', 'local_taskflow');

        const modal = new ModalForm({
            formClass: 'local_taskflow\\form\\userevidence',
            args: {userid: userid},
            modalConfig: {
                title: title,
            }
        });

        modal.addEventListener(modal.events.FORM_SUBMITTED, function() {
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
};
