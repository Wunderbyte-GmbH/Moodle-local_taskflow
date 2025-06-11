/**
 * JS for handling the upload users modal in a form.
 *
 * @module local_taskflow/uploadusers
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core_form/modalform'], function(ModalForm) {

    return {
        init: function() {
            const trigger = document.querySelector('#openuploadusersmodal');
            if (!trigger) {
                return;
            }

            trigger.addEventListener('click', function() {
                const modal = new ModalForm({
                    formClass: 'local_taskflow\\form\\uploaduser',
                    modalConfig: {
                        title: 'Upload Users (JSON)',
                    }
                });

                modal.addEventListener(modal.events.FORM_SUBMITTED, (e) => {
                    const response = e.detail;
                    alert('Upload successful. Entries: ' + (response.data.count ?? 0));
                    // You can also do more, like refreshing part of the page.
                });

                modal.show();
            });
        }
    };
});