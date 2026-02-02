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
 * @module     local_taskflow/internalcommunicationform
 * @copyright  2025 Wunderbyte GmbH
 * @author     Jacob Viertel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';

export const init = (selector, formClass) => {
    const formelement = document.querySelector(selector);
    const form = new DynamicForm(formelement, formClass);

    const id = formelement.getAttribute('data-assignmentid');
    const scrollChatToBottom = () => {
        // Scroll chat down if it is too long
        const chatContainer = document.getElementById('local-taskflow-chat-history-container');
        if (chatContainer) {
            chatContainer.scrollTo({
                top: chatContainer.scrollHeight,
                behavior: 'smooth'
            });
        }
    };

    const waitForDomUpdate = () => {
        return new Promise(resolve => {
            const observer = new MutationObserver((mutationsList, observerInstance) => {
                observerInstance.disconnect(); // Only want the first mutation
                resolve();
            });

            observer.observe(formelement, {
                childList: true,
                subtree: true,
            });
        });
    };

    // On initial load
    scrollChatToBottom();

    // After message submission scroll down
    form.addEventListener(form.events.FORM_SUBMITTED, async(e) => {
        e.preventDefault();
        const domUpdated = waitForDomUpdate();
        await form.load({id});
        await domUpdated;

        form.notifyResetFormChanges();
        scrollChatToBottom();
    });
};
