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

namespace local_taskflow\local\external_adapter;

use local_taskflow\local\personas\unit_member;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_api_user_data extends external_api_base {
    /** @var string|null Stores the external user data. */
    private stdClass $externaldata;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $data
     */
    public function __construct($data) {
        $this->externaldata = (object) json_decode($data);
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function process_incoming_data() {
        $translateduserdata = [];
        foreach ($this->externaldata as $user) {
            $translateduserdata[] = $this->translate_incoming_data($user);
        }
        foreach ($translateduserdata as $persondata) {
            unit_member::handle_external_data_implementation($persondata);
            // TO DO include unit::handle.
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function get_external_data() {
        return $this->externaldata;
    }
}
