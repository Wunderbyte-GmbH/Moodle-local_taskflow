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

namespace local_taskflow\local\units;

use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit {
    /**
     * The instances of the class.
     *
     * @var array
     */
    private static $instances = [];

    /** @var int $id The unique ID of the unit. */
    private $id;

    /** @var string $name The name of the unit. */
    private $name;

    /** @var string|null $criteria JSON-encoded criteria for membership. */
    private $criteria;

    /** @var int $timecreated The timestamp when the unit was created. */
    private $timecreated;

    /** @var int|null $timemodified The timestamp when the unit was last modified. */
    private $timemodified;

    /** @var int|null $usermodified The user ID who last modified the unit. */
    private $usermodified;

    /** @var string */
    private const TABLENAME = 'local_taskflow_units';

    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param stdClass $data The record from the database.
     */
    private function __construct(stdClass $data) {
        $this->id = $data->id;
        $this->name = $data->name;
        $this->criteria = $data->criteria;
        $this->timecreated = $data->timecreated;
        $this->timemodified = $data->timemodified;
        $this->usermodified = $data->usermodified;
    }

    /**
     * Get the instance of the class for a specific ID.
     *
     * @param int $id
     * @return unit
     * @throws \moodle_exception
     */
    public static function instance($id) {
        global $DB;

        if (!isset(self::$instances[$id])) {
            $data = $DB->get_record(self::TABLENAME, ['id' => $id], '*', MUST_EXIST);
            self::$instances[$id] = new self($data);
        }

        return self::$instances[$id];
    }

    /**
     * Create a new unit and return its instance.
     *
     * @param string $name
     * @param string|null $criteria JSON-encoded criteria (nullable)
     * @param int|null $usermodified User ID of the creator (nullable)
     * @return unit
     */
    public static function create($name, $criteria = null, $usermodified = null) {
        global $DB, $USER;

        $record = new stdClass();
        $record->name = $name;
        $record->criteria = $criteria;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->usermodified = $usermodified ?? $USER->id;

        $id = $DB->insert_record(self::TABLENAME, $record);
        $record->id = $id;

        self::$instances[$id] = new self($record);
        return self::$instances[$id];
    }

    /**
     * Update the current unit.
     *
     * @param string|null $name Updated name (nullable)
     * @param string|null $criteria Updated criteria (nullable)
     * @param int|null $usermodified User ID of the modifier (nullable)
     * @return void
     */
    public function update($name = null, $criteria = null, $usermodified = null) {
        global $DB, $USER;

        if ($name !== null) {
            $this->name = $name;
        }
        if ($criteria !== null) {
            $this->criteria = $criteria;
        }

        $this->timemodified = time();
        $this->usermodified = $usermodified ?? $USER->id;

        $DB->update_record('local_taskflow_units', (object) [
            'id' => $this->id,
            'name' => $this->name,
            'criteria' => $this->criteria,
            'timemodified' => $this->timemodified,
            'usermodified' => $this->usermodified,
        ]);
    }

    /**
     * Delete the current unit.
     *
     * @return void
     */
    public function delete() {
        global $DB;

        $DB->delete_records('local_taskflow_units', ['id' => $this->id]);
        unset(self::$instances[$this->id]);
    }

    /**
     * Add a user to the unit.
     *
     * @param int $userid The ID of the user to add.
     * @return bool True if the user was added, false otherwise.
     * @throws \moodle_exception
     */
    public function add_member($userid) {
        global $DB;

        // Check if the user is already a member.
        if ($DB->record_exists('local_taskflow_unit_members', ['unitid' => $this->get_id(), 'userid' => $userid])) {
            throw new \moodle_exception('useralreadymember', 'local_taskflow', '', $userid);
        }

        // Add the user to the unit.
        $data = new stdClass();
        $data->unitid = $this->get_id();
        $data->userid = $userid;
        $data->timeadded = time();

        return $DB->insert_record('local_taskflow_unit_members', $data);
    }

    /**
     * Remove a user from the unit.
     *
     * @param int $userid The ID of the user to remove.
     * @return bool True if the user was removed, false otherwise.
     * @throws \moodle_exception
     */
    public function delete_member($userid) {
        global $DB;

        // Check if the user is a member.
        if (!$DB->record_exists('local_taskflow_unit_members', ['unitid' => $this->get_id(), 'userid' => $userid])) {
            throw new \moodle_exception('usernotfound', 'local_taskflow', '', $userid);
        }

        // Delete the user from the unit.
        return $DB->delete_records('local_taskflow_unit_members', ['unitid' => $this->get_id(), 'userid' => $userid]);
    }

    /**
     * Get a list of all members of the unit.
     *
     * @return array Array of user IDs who are members of the unit.
     */
    public function get_members() {
        global $DB;

        $members = $DB->get_records('local_taskflow_unit_members', ['unitid' => $this->get_id()], '', 'userid');

        return array_map(function ($record) {
            return $record->userid;
        }, $members);
    }

    /**
     * Check if a user is a member of the unit.
     *
     * @param int $userid The ID of the user to check.
     * @return bool True if the user is a member, false otherwise.
     */
    public function is_member($userid) {
        global $DB;

        return $DB->record_exists('local_taskflow_unit_members', ['unitid' => $this->get_id(), 'userid' => $userid]);
    }

    /**
     * Get the number of members in the unit.
     *
     * @return int The number of members.
     */
    public function count_members() {
        global $DB;

        return $DB->count_records('local_taskflow_unit_members', ['unitid' => $this->get_id()]);
    }

    /**
     * Get the ID of the unit.
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the name of the unit.
     *
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Get the criteria of the unit.
     *
     * @return string|null
     */
    public function get_criteria() {
        return $this->criteria;
    }

    /**
     * Get the time the unit was created.
     *
     * @return int
     */
    public function get_timecreated() {
        return $this->timecreated;
    }

    /**
     * Get the time the unit was last modified.
     *
     * @return int|null
     */
    public function get_timemodified() {
        return $this->timemodified;
    }

    /**
     * Get the user who last modified the unit.
     *
     * @return int|null
     */
    public function get_usermodified() {
        return $this->usermodified;
    }

    /**
     * Update the current unit.
     * @param stdClass $unit
     * @return \local_taskflow\local\units\unit
     */
    public static function create_unit($unit) {
        $exsistingunit = self::get_unit_by_name($unit->unit);
        if (!$exsistingunit) {
            units::create_or_delete($unit);
            return self::create($unit->unit);
        }
        self::$instances[$exsistingunit->id] = new self($exsistingunit);
        return self::$instances[$exsistingunit->id];
    }

    /**
     * Update the current unit.
     * @param string $unitname
     * @return mixed
     */
    public static function get_unit_by_name($unitname) {
        global $DB;
        return $DB->get_record(
            self::TABLENAME,
            ['name' => $unitname]
        );
    }
}
