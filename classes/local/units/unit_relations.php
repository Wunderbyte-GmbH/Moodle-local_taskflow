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
class unit_relations {
    /**
     * The instances of the class.
     *
     * @var array
     */
    private static $instances = [];

    /** @var int $id The unique ID of the unit. */
    private $id;

    /** @var string $childid The name of the unit. */
    private $childid;

    /** @var int $parentid The timestamp when the unit was created. */
    private $parentid;

    /** @var int|null $timecreated The timestamp when the unit was last modified. */
    private $timecreated;

    /** @var int|null $timemodified The timestamp when the unit was last modified. */
    private $timemodified;

    /** @var int|null $usermodified The user ID who last modified the unit. */
    private $usermodified;

    /** @var int $active The user ID who last modified the unit. */
    private $active;

    /** @var string */
    private const TABLENAME = 'local_taskflow_unit_relations';

    /**
     * Resets the static instances (for testing purposes).
     */
    public static function reset_instances(): void {
        self::$instances = [];
    }

    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param stdClass|bool $data The record from the database.
     */
    private function __construct($data) {
        $this->id = $data->id ?? null;
        $this->childid = $data->childid ?? null;
        $this->parentid = $data->parentid ?? null;
        $this->timecreated = $data->timecreated ?? null;
        $this->timemodified = $data->timemodified ?? null;
        $this->usermodified = $data->usermodified ?? null;
        $this->active = $data->active ?? null;
    }

    /**
     * Get the instance of the class for a specific ID.
     *
     * @param int $id
     * @return unit_relations
     * @throws \moodle_exception
     */
    public static function instance($childid) {
        global $DB;
        if (!isset(self::$instances[$childid])) {
            $data = $DB->get_record(self::TABLENAME, ['childid' => $childid], '*');
            self::$instances[$childid] = new self($data);
        }
        return self::$instances[$childid];
    }

    /**
     * Create a new unit and return its instance.
     * @param string $childid
     * @param string $parentid
     * @param int|null $usermodified User ID of the creator (nullable)
     * @return unit
     */
    public static function create($childid, $parentid, $usermodified = null) {
        global $DB, $USER;
        $record = new stdClass();
        $record->childid = $childid;
        $record->parentid = $parentid;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->usermodified = $usermodified ?? $USER->id;
        $record->active = 1;

        $id = $DB->insert_record(self::TABLENAME, $record);
        $record->id = $id;

        self::$instances[$childid] = new self($record);
        return self::$instances[$childid];
    }

    /**
     * Delete the current unit.
     *
     * @return void
     */
    public function delete() {
        global $DB;
        $DB->delete_records(self::TABLENAME, ['id' => $this->id]);
        unset(self::$instances[$this->childid]);
    }

    /**
     * Delete the current unit.
     *
     * @return void
     */
    public function change_activision() {
        global $DB;
        $this->set_active($this->get_active() == 1 ? 0 : 1);
        $this->update();
    }

    /**
     * Update the current unit.
     * @return void
     */
    public function update() {
        global $DB, $USER;
        $this->timemodified = time();
        $this->usermodified = $usermodified ?? $USER->id;
        $DB->update_record('local_taskflow_units', (object) [
            'id' => $this->id,
            'childid' => $this->childid,
            'parentid' => $this->parentid,
            'timecreated' => $this->timecreated,
            'timemodified' => $this->timemodified,
            'usermodified' => $this->usermodified,
            'active' => $this->active,
        ]);
    }

    /**
     * Get the ID of the unit.
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the name of the unit.
     * @return string
     */
    public function get_childid() {
        return $this->childid;
    }

    /**
     * Get the criteria of the unit.
     * @return string|null
     */
    public function get_parentid() {
        return $this->parentid;
    }

    /**
     * Get the criteria of the unit.
     * @return string|null
     */
    public function get_active() {
        return $this->active;
    }

    /**
     * Get the criteria of the unit.
     * @param string $activestatus
     */
    public function set_active($activestatus) {
        $this->active = $activestatus;
    }

    /**
     * Update the current unit.
     * @param string $unitid
     * @param string $parentid
     * @return mixed
     */
    public static function create_or_update_relations($unitid, $parentid) {
        if (!self::is_new_relation($unitid, $parentid)) {
            return self::create($unitid, $parentid);
        }
        return null;
    }

    /**
     * Update the current unit.
     * @param string $childid
     * @param string $parentid
     * @return mixed
     */
    public static function is_new_relation($childid, $parentid) {
        global $DB;
        return $DB->get_record(
            self::TABLENAME,
            [
                'childid' => $childid,
                'parentid' => $parentid,
            ]
        );
    }

    /**
     * Update the current unit.
     * @return array
     */
    public static function get_all_active_unit_relations() {
        global $DB;
        return $DB->get_records(
            self::TABLENAME,
            ['active' => 1],
            '',
            'childid, parentid'
        );
    }
}
