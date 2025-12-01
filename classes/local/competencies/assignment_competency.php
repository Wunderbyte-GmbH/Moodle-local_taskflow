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
 * @author Thomas Winkler
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\competencies;

use core_competency\api;
use core_competency\user_evidence;
use core_competency\user_evidence_competency;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\task\update_assignment;
use stdClass;
/**
 * Class unit
 * @author Thomas Winkler
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Class assignment_competency
 * Handles CRUD operations on the assignment-competency relation.
 */
class assignment_competency extends \core\persistent {
    /** @var int */
    public $id;

    /** @var string */
    const TABLE = 'local_taskflow_assgin_comp';

    /** @var int */
    public $userid;

    /** @var int */
    public $competencyid;

    /** @var int */
    public $competencyevidenceid;

    /** @var int|null */
    public $timecreated;

    /** @var int|null */
    public $timemodified;

    /** @var int|null */
    public $validationondate;

    /**
     * Constructor - optionally load from DB by ID.
     * @param int $id
     */
    public function __construct(int $id = 0) {
        if ($id > 0) {
            $this->load_from_db($id);
        }
    }

    /**
     * Load from DB.
     * @param int $id
     * @return void
     */
    public function load_from_db(int $id): void {
        global $DB;

        $record = $DB->get_record('local_taskflow_assgin_comp', ['id' => $id], '*', MUST_EXIST);

        $this->id = $record->id;
        $this->userid = $record->userid;
        $this->competencyid = $record->competencyid;
        $this->competencyevidenceid = $record->competencyevidenceid;
        $this->timecreated = $record->timecreated;
        $this->timemodified = $record->timemodified;
        $this->validationondate = $record->validationondate;
    }

    /**
     * Return data as a stdClass.
     * @return stdClass
     */
    public function return_class_data(): stdClass {
        $data = new stdClass();
        $data->id = $this->id;
        $data->userid = $this->userid;
        $data->competencyid = $this->competencyid;
        $data->competencyevidenceid = $this->competencyevidenceid;
        $data->timecreated = $this->timecreated;
        $data->timemodified = $this->timemodified;
        $data->validationondate = $this->validationondate;
        return $data;
    }

    /**
     * Add or update the record.
     * @param array $data
     * @return stdClass
     */
    public function add_or_update(array $data): stdClass {
        global $DB;

        $data['timemodified'] = time();

        if (empty($data['id'])) {
            $data['timecreated'] = time();
            $this->id = $DB->insert_record('local_taskflow_assgin_comp', (object)$data);
        } else {
            $DB->update_record('local_taskflow_assgin_comp', (object)$data);
            $this->id = $data['id'];
        }

        $this->load_from_db($this->id);
        return $this->return_class_data();
    }

    /**
     * Checks if a user has a competency.
     * @param int $userid
     * @param int $competencyid
     * @return bool
     */
    public static function user_has_competency(int $userid, int $competencyid): bool {
        global $DB;
        return $DB->record_exists('local_taskflow_assgin_comp', [
            'userid' => $userid,
            'competencyid' => $competencyid,
        ]);
    }

    /**
     * Checks if a user has a competency.
     * @return void
     */
    public function set_competency(): void {
        global $DB;
        $userid = $this->get('userid');
        $competencyid = $this->get('competencyid');
        $evidenceid = $this->get('competencyevidenceid');
        $assignmentid = $this->get('assignmentid');
        $validationondate = $this->get('validationondate');

        $userevidence = new user_evidence($evidenceid);
        if ($userevidence->get('id')) {
            api::create_user_evidence_competency($evidenceid, $competencyid);
            api::get_user_competency($userid, $competencyid);
            $assignment = standard_assignment::get_assignment_record_by_assignmentid($assignmentid);
            if (isset($assignment->ruleid)) {
                $taskupdate = new update_assignment();
                $customdata = [
                    'userid' => (string) $userid,
                    'id' => (string) $assignment->ruleid,
                ];
                $taskupdate->set_custom_data($customdata);
                $taskupdate->execute();
            }
        }
    }

    /**
     * Delete competency from user evidence.
     * @return void
     */
    public function delete_competency(): void {
        global $DB;

        $userid = $this->get('userid');
        $competencyid = $this->get('competencyid');
        $evidenceid = $this->get('competencyevidenceid');
        $userevidence = new user_evidence($evidenceid);

        if ($userevidence && $userevidence->get('id')) {
            $record = $DB->get_record('competency_userevidencecomp', [
                'userevidenceid' => $userevidence->get('id'),
                'competencyid' => $competencyid,
            ]);
            if ($record) {
                $link = new user_evidence_competency($record->id);
                $link->delete();
            }
            $uc = api::get_user_competency($userid, $competencyid);
            $uc->delete();
        }
    }

    /**
     * Checks if a user has a competency.
     * @param string $method
     * @return void
     */
    public function handle_competency(string $method): void {
        if ($method === 'approved') {
            $this->set_competency();
        } else if ($method === 'rejected') {
            $this->delete_competency();
        } else {
            throw new \moodle_exception('invalidmethod', 'local_taskflow');
        }
    }

    /**
     * Get a single assignment competency record with evidence info by user and competency.
     *
     * @param int $userid
     * @param int $competencyid
     * @param bool $resolvestatus
     * @return stdClass|null
     */
    public static function get_with_evidence_by_user_and_competency(
        int $userid,
        int $competencyid,
        bool $resolvestatus = false
    ): ?stdClass {
        global $DB;
        $sql = "
            SELECT ac.id,
                ac.userid,
                ac.competencyid,
                ac.competencyevidenceid,
                ac.timecreated,
                ac.timemodified,
                ac.status as ac_status,
                cue.name AS evidence_name,
                cue.description AS evidence_description,
                cue.timecreated AS evidence_timecreated
            FROM {local_taskflow_assgin_comp} ac
            JOIN {competency_userevidence} cue ON ac.competencyevidenceid = cue.id
            WHERE ac.userid = :userid
            AND ac.competencyid = :competencyid
            LIMIT 1
        ";

        $record = $DB->get_record_sql($sql, [
            'userid' => $userid,
            'competencyid' => $competencyid,
        ]);
        if ($resolvestatus && isset($record->ac_status)) {
            $identifier = "userevidencestatus_$record->ac_status";
            $string = get_string($identifier, 'local_taskflow');
            if ($string) {
                $record->ac_status = $string;
            }
        }
        return $record ?: new stdClass();
    }

    /**
     * Summary of define_properties
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'assignmentid' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_ALLOWED,
            ],
            'userid' => [
                'type' => PARAM_INT,
            ],
            'competencyid' => [
                'type' => PARAM_INT,
            ],
            'competencyevidenceid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
            ],
            'status' => [
                'type' => PARAM_ALPHANUMEXT,
                'default' => 'underreview',
                'null' => NULL_ALLOWED,
            ],
            'timecreated' => [
                'type' => PARAM_INT,
            ],
            'timemodified' => [
                'type' => PARAM_INT,
            ],
            'validationondate' => [
                'type' => PARAM_INT,
            ],
        ];
    }
}
