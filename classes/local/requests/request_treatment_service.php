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
 * Service to treat (confirm / decline) a request of any type.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\requests;

use local_taskflow\local\assignments\assignment_manual_update_service;
use local_taskflow\local\competencies\assignment_competency;
use local_taskflow\local\requests as requests_facade;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowuploadevidence;
use moodle_exception;
use stdClass;

/**
 * Treats requests the same way the UI does, no matter who triggers it.
 *
 * The three request types are handled as follows:
 * - allowselfnotrelevant (1): requests::treat_request(), which sets the assignment
 *   to the status notrelevant on confirmation.
 * - allowselfextension (2): the request is marked as treated; when a newduedate is
 *   handed over on confirmation, the extension is granted via the
 *   assignment_manual_update_service.
 * - allowuploadevidence (3): the assignment competency is approved / rejected
 *   (the semantics of local_taskflow\form\userevidence::process_set_status).
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_treatment_service {
    /**
     * Confirms a request.
     *
     * Supported options:
     * - newduedate (int) only type 2: grant the extension to this due date.
     * - comment (string) comment for the history entry of the extension.
     * - assingmentcompetencyid (int) only type 3, defaults to the id stored in the request json.
     * - validationondate (int) only type 3.
     *
     * @param int $requestid
     * @param int $actorid
     * @param array $options
     * @return stdClass
     */
    public function confirm(int $requestid, int $actorid, array $options = []): stdClass {
        return $this->treat($requestid, requests_facade::TREATED_STATUS_CONFIRMED, $actorid, $options);
    }

    /**
     * Declines a request. See confirm() for the supported options.
     *
     * @param int $requestid
     * @param int $actorid
     * @param array $options
     * @return stdClass
     */
    public function decline(int $requestid, int $actorid, array $options = []): stdClass {
        return $this->treat($requestid, requests_facade::TREATED_STATUS_DECLINED, $actorid, $options);
    }

    /**
     * Treats one request.
     *
     * @param int $requestid
     * @param int $treatedstatus
     * @param int $actorid
     * @param array $options
     * @return stdClass
     */
    private function treat(int $requestid, int $treatedstatus, int $actorid, array $options): stdClass {
        global $DB;

        // Note: requests::get() declares ?stdClass but returns false for a missing
        // record, which throws a TypeError, so the record is read directly here.
        $request = $DB->get_record('local_taskflow_requests', ['id' => $requestid], '*', IGNORE_MISSING);
        if (empty($request)) {
            throw new moodle_exception('invalidrecordunknown', 'error');
        }

        $requesttype = (int)$request->request;
        $assignmentid = (int)$request->assignmentid;
        $userid = (int)$request->userid;

        $result = (object)[
            'requestid' => $requestid,
            'requesttype' => $requesttype,
            'assignmentid' => $assignmentid,
            'userid' => $userid,
            'treated' => $treatedstatus,
            'success' => false,
            'assignment' => null,
            'evidencestatus' => null,
        ];

        if ($requesttype === allowuploadevidence::ID) {
            $evidencestatus = $treatedstatus === requests_facade::TREATED_STATUS_CONFIRMED ? 'approved' : 'rejected';
            $assigncompetencyid = (int)($options['assingmentcompetencyid']
                ?? $this->get_assignment_competency_id_of_request($request));
            $this->apply_evidence_status(
                $assigncompetencyid,
                $evidencestatus,
                $userid,
                $assignmentid,
                $options['validationondate'] ?? 0,
                $actorid
            );
            $result->evidencestatus = $evidencestatus;
            $result->success = true;
            return $result;
        }

        $result->success = (new requests_facade())->treat_request(
            $requestid,
            $assignmentid,
            $userid,
            $treatedstatus
        );

        if (
            $result->success
            && $requesttype === allowselfextension::ID
            && $treatedstatus === requests_facade::TREATED_STATUS_CONFIRMED
            && !empty($options['newduedate'])
        ) {
            $result->assignment = (new assignment_manual_update_service())->grant_extension(
                $assignmentid,
                (int)$options['newduedate'],
                $actorid,
                $options['comment'] ?? null
            );
        }

        return $result;
    }

    /**
     * Sets the status of an uploaded competency evidence and treats the belonging request.
     *
     * This is the logic of local_taskflow\form\userevidence::process_set_status().
     *
     * @param int $assigncompetencyid
     * @param string $status approved | rejected | underreview
     * @param int $userid
     * @param int $assignmentid
     * @param int|null $validationondate
     * @param int $actorid
     * @return stdClass The assignment competency data.
     */
    public function apply_evidence_status(
        int $assigncompetencyid,
        string $status,
        int $userid,
        int $assignmentid,
        $validationondate,
        int $actorid
    ): stdClass {
        $assigncompetency = new assignment_competency();
        $assigncompetency->load_from_db($assigncompetencyid);
        if (!$assigncompetency->id) {
            throw new moodle_exception('invaliduserevidenceid', 'tool_lp');
        }
        $assigncompetency->set('id', $assigncompetencyid);
        $assigncompetency->read();
        $assigncompetency->set('status', $status);
        $assigncompetency->set('validationondate', $validationondate ?? 0);
        $assigncompetency->update();

        $requestid = self::get_request_id_by_assignment_competency($userid, $assignmentid, $assigncompetencyid);

        if ($assigncompetency->get('status') == 'approved') {
            $assigncompetency->set_competency();
            (new requests_facade())->treat_request(
                $requestid,
                $assignmentid,
                $userid,
                requests_facade::TREATED_STATUS_CONFIRMED
            );
        }
        if ($assigncompetency->get('status') == 'rejected' || $assigncompetency->get('status') == 'underreview') {
            $assigncompetency->delete_competency();
            if ($assigncompetency->get('status') == 'rejected') {
                (new requests_facade())->treat_request(
                    $requestid,
                    $assignmentid,
                    $userid,
                    requests_facade::TREATED_STATUS_DECLINED
                );
            }
        }

        return $assigncompetency->return_class_data();
    }

    /**
     * Finds the request id for a specific assignment competency by scanning the JSON field.
     *
     * @param int $userid
     * @param int $assignmentid
     * @param int $assingmentcompetencyid
     * @return int|null
     */
    public static function get_request_id_by_assignment_competency(
        int $userid,
        int $assignmentid,
        int $assingmentcompetencyid
    ): ?int {
        global $DB;
        $records = $DB->get_records('local_taskflow_requests', [
            'userid'       => $userid,
            'assignmentid' => $assignmentid,
            'request'      => allowuploadevidence::ID,
        ]);
        foreach ($records as $record) {
            $json = json_decode($record->json ?? '{}');
            if (($json->assingmentcompetencyid ?? null) == $assingmentcompetencyid) {
                return $record->id;
            }
        }
        return null;
    }

    /**
     * Reads the assignment competency id out of the json of an evidence request.
     *
     * @param stdClass $request
     * @return int
     */
    private function get_assignment_competency_id_of_request(stdClass $request): int {
        $json = json_decode($request->json ?? '{}');
        return (int)($json->assingmentcompetencyid ?? 0);
    }
}
