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
/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_member {
    /** @var array instances of the class */
    private static $instances = [];
    /** @var int $id The unique ID of the unit. */
    private $id;
    /** @var int $unitid The unique ID of the unit. */
    private $unitid;
    /** @var int $userid The unique ID of the unit. */
    private $userid;
    /** @var int $timeadded The timestamp when the unit was created. */
    private $timeadded;
    /** @var int|null $timemodified The timestamp when the unit was last modified. */
    private $timemodified;
    /** @var int|null $usermodified The user ID who last modified the unit. */
    private $usermodified;

    /** @var string */
    private const TABLENAME = 'local_taskflow_unit_members';

    /**
     * The record from the database.
     * @param stdClass $data
     */
    private function __construct(stdClass $data) {
        $this->id = $data->id;
        $this->unitid = $data->unitid;
        $this->userid = $data->userid;
        $this->timeadded = $data->timeadded;
        $this->timemodified = $data->timemodified;
        $this->usermodified = $data->usermodified;
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
     * @return int
     */
    public function get_unitid() {
        return $this->unitid;
    }

    /**
     * Get the ID of the unit.
     * @return int
     */
    public function get_userid() {
        return $this->userid;
    }

    /**
     * Get the instance of the class for a specific ID.
     *
     * @param int $id
     * @return unit_member
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
     * @param string $userid
     * @param string|null $unitid JSON-encoded criteria (nullable)
     * @param int|null $usermodified User ID of the creator (nullable)
     * @return unit_member
     */
    public static function create($userid, $unitid, $usermodified = null) {
        global $DB, $USER;

        $record = new stdClass();
        $record->userid = $userid;
        $record->unitid = $unitid;
        $record->timeadded = time();
        $record->timemodified = time();
        $record->usermodified = $usermodified ?? $USER->id;

        $id = $DB->insert_record(self::TABLENAME, $record);
        $record->id = $id;

        self::$instances[$id] = new self($record);
        return self::$instances[$id];
    }

    /**
     * Update the current unit.
     * @return \local_taskflow\local\personas\unit_member
     */
    public function update() {
        global $DB, $USER;

        $this->timemodified = time();
        $this->usermodified = $USER->id;

        $DB->update_record(self::TABLENAME, (object) [
            'id' => $this->id,
            'timemodified' => $this->timemodified,
            'usermodified' => $this->usermodified,
        ]);
        return self::$instances[$this->id];
    }

    /**
     * Update the current unit.
     * @param stdClass $persondata
     * @param string $unitid
     * @return mixed \local_taskflow\local\personas\unit_member
     */
    public static function update_or_create($persondata, $unitid) {
        $unitmember = self::get_unit_member($persondata->id, $unitid);
        if ($unitmember) {
            $unitmember = new unit_member($unitmember);
            $unitmember->update();
            return null;
        } else {
            return self::create($persondata->id, $unitid);
        }
    }

    /**
     * Update the current unit.
     * @param string $userid
     * @param string $unitid
     *
     */
    public static function get_unit_member($userid, $unitid) {
        global $DB;
        return $DB->get_record(
            self::TABLENAME,
            [
                'unitid' => $unitid,
                'userid' => $userid,
                ]
        );
    }
}
