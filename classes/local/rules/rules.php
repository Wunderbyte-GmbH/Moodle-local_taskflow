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
class rules {
    /** @var array */
    private static $instances = [];

    /** @var int $id */
    private $id;

    /** @var int $unitid */
    private $unitid;

    /** @var int $isactive */
    private $isactive;

    /** @var array $rulesjson */
    private $rulesjson;


    /** @var string */
    private const TABLENAME = 'local_taskflow_rules';

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $rule The record from the database.
     */
    private function __construct(stdClass $rule) {
        $this->id = $rule->id;
        $this->rulesjson = $rule->rulejson;
        $this->isactive = $rule->isactive;
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $ruleid
     * @return rules
     */
    public static function instance($ruleid) {
        global $DB;
        if (!isset(self::$instances[$ruleid])) {
            $rule = $DB->get_record(self::TABLENAME, ['id' => $ruleid]);
            self::$instances[$ruleid] = new self($rule);
        }
        return self::$instances[$ruleid];
    }

    /**
     * Get the criteria of the unit.
     * @return array
     */
    public function get_rulesjson() {
        return $this->rulesjson;
    }

    /**
     * Get the criteria of the unit.
     * @return int
     */
    public function get_isactive() {
        return $this->isactive;
    }

    /**
     * Get the criteria of the unit.
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the criteria of the unit.
     * @return int
     */
    public function get_unitid() {
        return $this->unitid;
    }
}
