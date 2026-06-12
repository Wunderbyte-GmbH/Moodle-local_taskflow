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

/**
 * Event class for assignment created.
 *
 * @package local_taskflow
 * @author David Ala-Flucher
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\event;

use local_taskflow\taskflow_stringmanager;

/**
 * The assignment created event class.
 * @package     local_taskflow
 * @author      David Ala-Flucher
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_created extends \core\event\base {
    /**
     * Init parameters.
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_taskflow_assignment';
    }

    /**
     * Init parameters.
     * @return string
     */
    public static function get_name() {
        return taskflow_stringmanager::get_string('eventassignmentcreated');
    }
    /**
     * Init parameters.
     * @return string
     */
    public function get_description() {
        $a = (object)[
            'id' => $this->data['objectid'],
            'userid' => $this->data['other']['userid'] ?? 0,
            'ruleid' => $this->data['other']['ruleid'] ?? 0,
        ];
        return taskflow_stringmanager::get_string('eventassignmentcreateddescription', $a);
    }

    /**
     * Init parameters.
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/taskflow/view.php', ['id' => $this->objectid]);
    }
}
