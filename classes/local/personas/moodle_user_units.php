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

namespace local_taskflow\local\personas;

use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_user_units {
    /** @var string */
    private const TABLENAME = 'local_taskflow_unit_members';

    /** @var int $userid The unique ID of the unit. */
    public $userid;

    /**
     * Update the current unit.
     * @param int $moodleuserid
     */
    public function __construct($moodleuserid) {
        $this->userid = $moodleuserid;
    }

    /**
     * Update the current unit.
     * @return array
     */
    public function get_user_units() {
        global $DB;
        return $DB->get_records(
            self::TABLENAME,
            [
                'userid' => $this->userid,
            ],
            '',
            'unitid'
        );
    }
}
