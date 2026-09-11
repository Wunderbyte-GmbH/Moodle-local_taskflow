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
 * Organisation page: opens the "assign rule to unit" modal.
 *
 * @module local_taskflow/unitspage
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';
import {init as initSwitcher} from 'local_taskflow/personswitcher';

const STORAGEKEY = 'local_taskflow_units_open';
const SELECTORS = {
    CHILDREN: '.local-taskflow-unit-children',
    TOGGLE: '.local-taskflow-unit-toggle',
};

/**
 * Ids of the units whose child list was left open by this browser.
 *
 * @returns {Array<string>}
 */
const readOpenUnits = () => {
    try {
        return JSON.parse(window.localStorage.getItem(STORAGEKEY) || '[]');
    } catch (e) {
        return [];
    }
};

/**
 * Remembers the open child lists in this browser.
 *
 * @param {Array<string>} unitids
 */
const writeOpenUnits = (unitids) => {
    try {
        window.localStorage.setItem(STORAGEKEY, JSON.stringify(unitids));
    } catch (e) {
        // Storage may be unavailable (private mode); the page still works without persistence.
    }
};

/**
 * Opens or closes one child list and keeps the toggle arrow in sync.
 *
 * @param {HTMLElement} children The .local-taskflow-unit-children element.
 * @param {boolean} open
 */
const setOpen = (children, open) => {
    children.classList.toggle('show', open);
    const toggle = document.querySelector(`${SELECTORS.TOGGLE}[aria-controls="${children.id}"]`);
    if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
};

/**
 * Stores the current open/closed state of every child list.
 */
const persistState = () => {
    const open = [...document.querySelectorAll(SELECTORS.CHILDREN)]
        .filter((el) => el.classList.contains('show'))
        .map((el) => el.dataset.unitid);
    writeOpenUnits(open);
};

/**
 * Restores the remembered state (everything closed on the first visit) and wires the expand/collapse buttons.
 */
const initCollapseState = () => {
    const open = readOpenUnits();
    document.querySelectorAll(SELECTORS.CHILDREN).forEach((children) => {
        setOpen(children, open.includes(children.dataset.unitid));
        // Bootstrap fires these after the user clicks a toggle arrow.
        children.addEventListener('shown.bs.collapse', persistState);
        children.addEventListener('hidden.bs.collapse', persistState);
    });
    // A focused unit (?id=) must be reachable: open every ancestor list.
    const focus = document.querySelector('.local-taskflow-unit.local-taskflow-unit-focus');
    if (focus) {
        let parent = focus.parentElement.closest(SELECTORS.CHILDREN);
        while (parent) {
            setOpen(parent, true);
            parent = parent.parentElement.closest(SELECTORS.CHILDREN);
        }
        persistState();
    }

    const setAll = (openall) => {
        document.querySelectorAll(SELECTORS.CHILDREN).forEach((children) => setOpen(children, openall));
        persistState();
    };
    document.querySelectorAll('[data-action="expandallunits"]').forEach((btn) => {
        btn.addEventListener('click', () => setAll(true));
    });
    document.querySelectorAll('[data-action="collapseallunits"]').forEach((btn) => {
        btn.addEventListener('click', () => setAll(false));
    });
};

/**
 * Initialise the organisation page: collapse state, assign buttons, focus.
 */
export const init = () => {
    initCollapseState();
    initSwitcher(0);

    document.querySelectorAll('[data-action="assignruletounit"]').forEach((trigger) => {
        trigger.addEventListener('click', async(e) => {
            e.preventDefault();
            const unitid = parseInt(trigger.dataset.unitid, 10);
            const title = await getString('assignruletounit', 'local_taskflow');
            const success = await getString('assignruletounit_success', 'local_taskflow');

            const modal = new ModalForm({
                formClass: 'local_taskflow\\form\\assign_rule_to_unit',
                args: {unitid: unitid},
                modalConfig: {title: title},
                saveButtonText: title,
            });

            modal.addEventListener(modal.events.FORM_SUBMITTED, () => {
                Notification.addNotification({message: success, type: 'success', closeButton: true});
                setTimeout(() => {
                    window.location.href = M.cfg.wwwroot + '/local/taskflow/units.php?id=' + unitid;
                }, 800);
            });

            modal.show();
        });
    });

    const focus = document.querySelector('.local-taskflow-unit.local-taskflow-unit-focus');
    if (focus) {
        focus.scrollIntoView({behavior: 'smooth', block: 'center'});
    }
};
