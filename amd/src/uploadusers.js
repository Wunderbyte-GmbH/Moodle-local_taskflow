/**
 * JS for handling the upload users modal in a form.
 *
 * @module local_taskflow/uploadusers
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import ModalForm from 'core_form/modalform';

/**
 * Initializes the upload users modal trigger.
 *
 * Attaches a click event listener to the element with ID 'openuploadusersmodal',
 * which opens a modal form for uploading users and shows a success notification
 * upon form submission.
 */
export const init = () => {
    const trigger = document.querySelector('#openuploadusersmodal');
    if (!trigger) {
        return;
    }

    trigger.addEventListener('click', () => {
        const modal = new ModalForm({
            formClass: 'local_taskflow\\form\\uploaduser',
            modalConfig: {
                title: 'Upload Users (JSON)',
            }
        });

        modal.addEventListener(modal.events.FORM_SUBMITTED, function(e) {
            // eslint-disable-next-line no-console
            console.log('worked', e.detail);
            Notification.addNotification({
                message: 'Your upload was successful! ' + e.detail.time,
                type: 'success',
                closeButton: true,
            });

            setTimeout(() => {
                window.location.href = window.location.origin + window.location.pathname;
            }, 10000);
        });

        modal.show();
    });


    const fetchtrigger = document.querySelector('#triggerdwhimport');
    if (!fetchtrigger) {
        return;
    }

    fetchtrigger.addEventListener('click', () => {
        const modal = new ModalForm({
            formClass: 'local_taskflow\\form\\importdwh',
            modalConfig: {
                title: 'Trigger DWH Import manually',
            }
        });

        modal.addEventListener(modal.events.FORM_SUBMITTED, function() {
            // eslint-disable-next-line no-console
            console.log('wordfsfefseked');
            Notification.addNotification({
                message: 'Your import was triggered successfully!',
                type: 'success',
                closeButton: true,
            });

            setTimeout(() => {
                window.location.href = window.location.origin + window.location.pathname;
            }, 10000);
        });

        modal.show();
    });
};
