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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignments\types;

use local_taskflow\local\assignments\assignments_interface;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard_assignment implements assignments_interface {
    /** @var array The instances of the class. */
    private static $instances = [];

    /** @var int $id The unique ID of the unit. */
    private $id;

    /** @var string $targets The name of the unit. */
    private $targets;

    /** @var string $messages The name of the unit. */
    private $messages;

    /** @var string $userid The name of the unit. */
    private $userid;

    /** @var string $ruleid The name of the unit. */
    private $ruleid;

    /** @var string Event name for user updated. */
    private const TABLE = 'local_taskflow_assignment';

    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param stdClass $data The record from the database.
     */
    private function __construct(stdClass $data) {
        $this->id = $data->id;
        $this->targets = $data->targets;
        $this->messages = $data->messages;
        $this->userid = $data->userid;
        $this->ruleid = $data->ruleid;
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $id
     * @return standard_assignment
     */
    private static function instance($id) {
        global $DB;
        if (!isset(self::$instances[$id])) {
            $data = $DB->get_record(self::TABLE, [ 'id' => $id]);
            self::$instances[$id] = new self($data);
        }
        return self::$instances[$id];
    }

    /**
     * Factory for the organisational units
     * @param stdClass $assignment
     * @return int
     */
    public static function update_or_create_assignment($assignment) {
        $existing = self::get_assignment_by_userid_ruleid($assignment);
        if (!$existing) {
            $existing = self::create_assignment($assignment);
        } else {
            $existing = self::update_assignment($existing, $assignment);
        }
        return self::$instances[$existing->id];
    }

    /**
     * Update the current unit.
     * @param stdClass $assignment
     * @return mixed
     */
    private static function get_assignment_by_userid_ruleid($assignment) {
        global $DB;
        return $DB->get_record(self::TABLE, [
            'userid' => $assignment->userid,
            'ruleid' => $assignment->ruleid,
        ]);
    }

    /**
     * Create a new unit and return its instance.
     * @param stdClass $assignment
     * @return standard_assignment
     */
    private static function create_assignment($assignment) {
        global $DB;
        $id = $DB->insert_record(self::TABLE, $assignment);
        $assignment->id = $id;
        self::$instances[$id] = new self($assignment);
        return self::$instances[$id];
    }

    /**
     * Update the current unit.
     * @param stdClass $existing
     * @param stdClass $assignment
     * @return stdClass
     */
    private static function update_assignment($existing, $assignment) {
        global $DB;
        $existing->targets = $assignment->targets;
        $existing->messages = $assignment->messages;
        $existing->active = $assignment->active;
        $existing->usermodified = $assignment->usermodified;
        $existing->timemodified = $assignment->timemodified;
        $DB->update_record(self::TABLE, $existing);
        return $existing;
    }
}
