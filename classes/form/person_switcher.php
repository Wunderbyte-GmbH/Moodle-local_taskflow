<?php
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

namespace local_taskflow\form;

use local_taskflow\taskflow_stringmanager;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Person switcher on the person page: the same scoped user search as on the dashboard
 * (everyone with viewreports sees all users, supervisors only their team).
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class person_switcher extends moodleform {
    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement(
            'autocomplete',
            'id',
            taskflow_stringmanager::get_string('selectuser'),
            [],
            [
                'multiple' => false,
                'noselectionstring' => '',
                'ajax' => 'local_taskflow/form_users_selector',
            ]
        );
        $mform->setType('id', PARAM_INT);
        $mform->addElement('submit', 'go', get_string('go'), ['class' => 'local-taskflow-person-switcher-go']);
    }
}
