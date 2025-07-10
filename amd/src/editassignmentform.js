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
 * @package    mod_booking
 * @author     Bernhard Fischer
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic assignments form.
 *
 * @module     local_taskflow/editassignmentform
 * @copyright  2024 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';
import {reloadAllTables} from 'local_wunderbyte_table/reload';

export const init = (selector, formClass) => {

    const formelement = document.querySelector(selector);
    const form = new DynamicForm(formelement, formClass);
    const id = formelement.getAttribute('data-assignmentid');
    const backButton = document.querySelector('[data-region="editassignment-smartback"]');

    form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        form.load({id});
        form.notifyResetFormChanges();
        reloadAllTables(false);
    });

    if (backButton) {
        backButton.addEventListener('click', (e) => {
        e.preventDefault();
        smartBack();
        });
    }

};

/**
 * Look for the last page before the current one and redirect there.
 *
 * @return void
 *
 */
function smartBack() {
  const current = window.location.href;
  const referrer = document.referrer;

  if (referrer && referrer !== current) {
    window.location.href = referrer;
  } else {
    window.history.go(-2);
  }
}

