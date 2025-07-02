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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\history\types;

use local_taskflow\local\history\history;
use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Course type to manage output history.
 */
class base {
    /**
     * Summary of __construct
     * @var string $json
     */
    public string $json;

    /**
     * Summary of __construct
     * @var stdClass $jsonobject
     */
    public stdClass $jsonobject;

    /**
     * Type of history
     * @var string $type
     */
    public string $type;

    /**
     * Summary of __construct
     * @param string $type
     * @param string $json
     */
    public function __construct($type, $json = null) {
        $this->json = $json;
        $this->jsonobject = json_decode($json);
        $this->type = $type;
    }

    /**
     * Check if has additional data
     * @return bool
     */
    public function has_additional_data(): bool {
        return false;
    }

    /**
     * Render the ouput
     * @return string
     */
    public function render_additional_data(): string {
        return '';
    }

    /**
     * Render the ouput
     * @param stdClass $assignmentdata
     * @return string
     */
    public function log($assignmentdata): string {
        $data = $this->jsonobject;
        $assignmentid = (int) $assignmentdata->id;
        $userid = (int) $data->userid;
        $type = $this->type;
        $createdby = $data->releateduserid ?? 0;
        $dataarray = json_decode(json_encode($data), true);
        history::log($assignmentid, $userid, $type, $dataarray, $createdby);
        return '';
    }

    /**
     * Output function
     * @return string
     */
    public function output(): string {
        if ($this->has_additional_data()) {
            return $this->render_additional_data();
        }
        return '';
    }
}
