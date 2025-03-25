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

namespace local_taskflow\local\rules;

use stdClass;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_rules {
    /** @var array */
    private static $instances = [];

    /** @var string $rulesjson */
    private $rulesjson;


    /** @var string */
    private const TABLENAME = 'local_taskflow_rules';

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $data The record from the database.
     */
    private function __construct(array $rules) {
        $this->rulesjson = $rules;
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $id
     * @return unit_rules
     */
    public static function instance($unitid) {
        global $DB;
        if (!isset(self::$instances[$unitid])) {
            $rules = $DB->get_records(self::TABLENAME, ['unitid' => $unitid]);
            self::$instances[$unitid] = new self($rules);
        }
        return self::$instances[$unitid];
    }
}
