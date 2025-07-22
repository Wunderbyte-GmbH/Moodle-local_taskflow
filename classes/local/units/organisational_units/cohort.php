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

namespace local_taskflow\local\units\organisational_units;

use local_taskflow\event\unit_updated;
use local_taskflow\local\units\organisational_unit_interface;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/cohort/lib.php');

use local_taskflow\local\units\unit_relations;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort implements organisational_unit_interface {
    /** @var array The instances of the class. */
    private static $instances = [];

    /** @var int $id The unique ID of the unit. */
    private $id;

    /** @var string $name The name of the unit. */
    private $name;

    /** @var string $contextid The name of the unit. */
    private $contextid;

    /** @var string $component The name of the unit. */
    private $component;

    /** @var string */
    private const TABLENAME = 'cohort_members';

    /**
     * Resets the static instances (for testing purposes).
     */
    public static function reset_instances(): void {
        self::$instances = [];
    }

    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param stdClass $data The record from the database.
     */
    private function __construct(stdClass $data) {
        $this->id = $data->id;
        $this->name = $data->name ?? '';
        $this->contextid = $data->contextid ?? '';
        $this->component = $data->contextid ?? '';
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $id
     * @return unit
     * @throws \moodle_exception
     */
    public static function instance($id) {
        global $DB;
        if (!isset(self::$instances[$id])) {
            $data = $DB->get_record('cohort', [ 'id' => $id]);
            if ($data == false) {
                $data = new stdClass();
                $data->id = $id;
            }
            self::$instances[$id] = new self($data);
        }
        return self::$instances[$id];
    }

    /**
     * Update the current unit.
     * @param stdClass $cohort
     * @return mixed \local_taskflow\local\units\organisational_units\unit
     */
    public static function create_unit($cohort) {
        global $DB;
        $parentid = (int)($cohort->parentunitid ?? 0);
        $existingcohort = self::get_unit_with_parent_by_id($cohort->unitid, $parentid);
        if ($existingcohort == false) {
            $existingcohort = self::create($cohort);
        } else if (
            $existingcohort->name != $cohort->name ||
            $existingcohort->parent != $cohort->parent
        ) {
            self::$instances[$existingcohort->id] = new self($existingcohort);
            self::$instances[$existingcohort->id]->update($cohort->name);
        } else {
            self::$instances[$existingcohort->id] = new self($existingcohort);
        }
        if (!empty($cohort->parent)) {
            $cohortrelation = self::create_parent_update_relation(
                $existingcohort->id,
                $cohort->parent ?? null,
                $parentid,
            );
            // I don't think we should return the relation here.
            if (!is_null($cohortrelation) && $cohortrelation === 'false') {
                return $cohortrelation;
            }
        }
        return self::$instances[$existingcohort->id];
    }

    /**
     * Create a new unit and return its instance.
     * @param stdClass $cohort
     * @return unit
     */
    public static function create($cohort) {
        global $DB;

        $record = new stdClass();
        $record->name = $cohort->name;
        $record->contextid = \context_system::instance()->id;
        $record->idnumber = $cohort->unitid ?? '';
        $record->description = $cohort->description ?? '';
        $record->descriptionformat = FORMAT_HTML;
        $record->component = '';

        $id = cohort_add_cohort($record);
        $record->id = $id;

        self::$instances[$id] = new self($record);
        return self::$instances[$id];
    }

    /**
     * Update the current unit.
     * @param string|null $name Updated name (nullable)
     * @return void
     */
    public function update($name = null) {
        global $DB;

        if ($name !== null) {
            $this->name = $name;
        }

        $cohort = $DB->get_record('cohort', [ 'id' => $this->get_id()]);
        $cohort->name = $this->get_name();
        cohort_update_cohort($cohort);

        $event = unit_updated::create([
            'objectid' => $this->get_id(),
            'context'  => \context_system::instance(),
            'userid'   => $this->get_id(),
            'other'    => [
                'unitid' => $this->get_id(),
            ],
        ]);
        $event->trigger();
    }

    /**
     * Delete the current unit.
     *
     * @return void
     */
    public function delete() {
        global $DB;
        $cohort = $DB->get_record('cohort', [ 'id' => $this->get_id()]);
        if ($cohort) {
            cohort_delete_cohort($cohort);
            unset(self::$instances[$this->get_id()]);
        }
    }

    /**
     * Add a user to the unit.
     *
     * @param int $userid The ID of the user to add.
     * @return bool True if the user was added, false otherwise.
     * @throws \moodle_exception
     */
    public function add_member($userid) {
        cohort_add_member($this->get_id(), $userid);
        return true;
    }

    /**
     * Remove a user from the unit.
     *
     * @param int $userid The ID of the user to remove.
     * @return bool True if the user was removed, false otherwise.
     * @throws \moodle_exception
     */
    public function delete_member($userid) {
        cohort_remove_member($this->get_id(), $userid);
        return true;
    }

    /**
     * Get unit members
     * @return array
     */
    public function get_members() {
        global $DB;
        $records = $DB->get_records(
            'local_taskflow_unit_members',
            ['unitid' => $this->get_id()],
            '',
            'userid'
        );
        return array_map(fn($r) => $r->userid, $records);
    }

    /**
     * Check if a user is a member of the unit.
     *
     * @param int $userid The ID of the user to check.
     * @return bool True if the user is a member, false otherwise.
     */
    public function is_member($userid) {
        return cohort_is_member($this->get_id(), $userid);
    }

    /**
     * Get the number of members in the unit.
     *
     * @return int The number of members.
     */
    public function count_members() {
        global $DB;
        return $DB->count_records(self::TABLENAME, ['cohortid' => $this->get_id()]);
    }

    /**
     * Get the ID of the unit.
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the ID of the unit.
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Get the ID of the unit.
     * @return string
     */
    public function get_contextid() {
        return $this->contextid;
    }

    /**
     * Get the ID of the unit.
     * @return string
     */
    public function get_component() {
        return $this->component;
    }

    /**
     * Update the current unit.
     * @param string $childunitid
     * @param string $parentunitname
     * @param int $parentunitid
     * @return mixed
     */
    public static function create_parent_update_relation(string $childunitid, string $parentunitname, int $parentunitid) {
        global $DB;
        if (!empty($parentunitid)) {
            $parentinstance = $DB->get_record('cohort', ['id' => $parentunitid]);
        } else {
            $parentinstance = self::get_unit_by_name($parentunitname);
        }
        if (!$parentinstance) {
            $parentcohort = new stdClass();
            $parentcohort->name = $parentunitname;
            $parentinstance = self::create($parentcohort);
        } else {
            $parentinstance = self::instance($parentinstance->id);
        }
        return unit_relations::create_or_update_relations(
            $childunitid,
            $parentinstance->get_id()
        );
    }

    /**
     * Gets unit with parentname
     * @param string $tissid
     * @param int $parentunitid
     *
     * @return mixed
     *
     */
    public static function get_unit_with_parent_by_id(string $idnumber, int $parentunitid = 0) {
        global $DB;
        $sql = "SELECT
                    c.*,
                    p.name AS parent
                FROM {cohort} c
                LEFT JOIN {local_taskflow_unit_rel} r ON c.id = r.childid
                LEFT JOIN {cohort} p ON r.parentid = p.id
                WHERE c.idnumber = :idnumber";
        $params = ['idnumber' => $idnumber];
        if (!empty($parentunitid)) {
            $sql .= ' AND p.id = :parentid';
            $params['parentid'] = $parentunitid;
        } else {
            $sql .= ' AND r.parentid IS NULL ';
        }
        return $DB->get_record_sql($sql, $params);
    }

    /**
     * Gets unit by name.
     *
     * @param string $unitname
     *
     * @return mixed
     *
     */
    public static function get_unit_by_name(string $unitname) {
        global $DB;
        return $DB->get_record('cohort', ['name' => $unitname]);
    }
    /**
     * Teardown static array.
     *
     * @return void
     *
     */
    public static function teardown() {
        self::$instances = [];
    }
}
