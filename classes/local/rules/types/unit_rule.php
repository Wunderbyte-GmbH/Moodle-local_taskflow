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

namespace local_taskflow\local\rules\types;

use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/cohort/lib.php");

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_rule {
    /** @var array */
    private static $instances = [];

    /** @var int $id */
    private $id;

    /** @var int $unitid */
    private $unitid;

    /** @var int $isactive */
    private $isactive;

    /** @var int $assigneddate */
    private $assigneddate;

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
        $this->unitid = $rule->unitid;
        $this->rulesjson = $rule->rulejson;
        $this->isactive = $rule->isactive;
        $jsonobject = json_decode($rule->rulejson);
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $unitid
     * @return unit_rule
     */
    public static function instance(int $unitid) {
        global $DB;
        if (!isset(self::$instances[$unitid])) {
            $rules = $DB->get_records(self::TABLENAME, ['unitid' => $unitid]);
            self::$instances[$unitid] = [];

            foreach ($rules as $rule) {
                self::$instances[$unitid][] = new self($rule);
            }
        }
        return self::$instances[$unitid];
    }

    /**
     * Update the current unit.
     * @param stdClass $rule
     * @return mixed \local_taskflow\local\units\organisational_units\unit
     */
    public static function create_rule(stdClass $rule) {
        $exsistingrule = self::get_unit_by_unitid_rulejson($rule);
        if (!$exsistingrule) {
            return self::create($rule);
        }
        if (!self::is_rule_inside_instance($exsistingrule)) {
            self::$instances[$exsistingrule->unitid][] = new self($exsistingrule);
        }
        return self::$instances[$exsistingrule->unitid];
    }

    /**
     * Update the current unit.
     * @param stdClass $rule
     * @return mixed
     */
    private static function get_unit_by_unitid_rulejson(stdClass $rule) {
        global $DB;

        $sql = "SELECT * FROM {" . self::TABLENAME . "}
                WHERE unitid = :unitid
                AND " . $DB->sql_compare_text('rulejson') . " = " .
                $DB->sql_compare_text(':rulejson');

        return $DB->get_record_sql($sql, [
            'unitid' => $rule->unitid,
            'rulejson' => $rule->rulejson,
        ]);
    }

    /**
     * Update the current unit.
     * @param stdClass $rule
     * @return mixed
     */
    private static function is_rule_inside_instance(stdClass $rule) {
        $unitid = $rule->unitid ?? null;
        $ruleid = $rule->id ?? null;

        if (!isset(self::$instances[$unitid])) {
            return false;
        }

        foreach (self::$instances[$unitid] as $instance) {
            if ($instance->id == $ruleid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a new unit and return its instance.
     * @param stdClass $rule
     * @return unit_rule
     */
    private static function create(stdClass $rule) {
        global $DB;

        $record = new stdClass();
        $record->unitid = $rule->unitid;
        $record->rulejson = $rule->rulejson;
        $record->isactive = $rule->isactive;

        $id = $DB->insert_record(self::TABLENAME, $record);
        $record->id = $id;

        self::$instances[$rule->unitid][] = new self($record);
        return self::$instances[$rule->unitid];
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
     * Get the id of the rule.
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the id of the unit.
     * @return int
     */
    public function get_unitid() {
        return $this->unitid;
    }
}
