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
 * Requests table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;
use context_system;
use core_user;
use html_writer;
use local_taskflow\form\userevidence;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\rules\rules;
use local_taskflow\task\removed_rule;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use core\task\manager;
use moodle_url;

/**
 * Requests table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests_table extends wunderbyte_table {
    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_act($values) {
        global $OUTPUT;

        $capabilitytotreatrequests = has_capability('local/taskflow:treatrequests', context_system::instance());

        $html = "";
        if (!empty($values->json) && empty($values->treated)) {
            $requestjson = json_decode($values->json);
            if (isset($requestjson->assignmentid)) {
                $infolinkurl = new moodle_url('/local/taskflow/assignment.php', ['id' => $requestjson->assignmentid]);
                $html = html_writer::div(html_writer::link(
                    $infolinkurl->out(),
                    '<i class="fa fa-external-link"></i>',
                    ['target' => '_blank']
                ));
            }
        }

        $label = requests::resolve_treated($values->treated);
        switch ($values->treated) {
            case requests::TREATED_STATUS_CONFIRMED:
                $returnvalue = '<i class="fa fa-check" style="color:#28a745;" role="img" aria-label="' . $label . '"></i>';
                break;
            case requests::TREATED_STATUS_DECLINED:
                $returnvalue = '<i class="fa fa-times" style="color:#d9534f;" role="img" aria-label="' . $label . '"></i>';
                break;
            case requests::TREATED_STATUS_UNTREATED && $capabilitytotreatrequests:
                if ($values->status == allowselfnotrelevant::ID) {
                    // Use constant.
                    $confirmmethod = 'confirmrequest';
                    $declinemmethod = 'declinerequest';
                    $bodystring = 'confirmdatabody';
                } else if ($values->status == allowselfextension::ID) {
                    $confirmmethod = 'confirmprolongation';
                    $declinemmethod = 'declineprolongation';
                    $bodystring = 'confirmprolongationbody';
                }

                if (isset($confirmmethod)) {
                    $data[] = [
                        'label' => '',
                        'href' => '#',
                        'iclass' => 'fa fa-check',
                        'arialabel' => 'confirm',
                        'title' => get_string('requestconfirm', 'local_taskflow'),
                        'id' => $values->id . '-'  . $this->uniqueid,
                        'name' => $this->uniqueid . '-' . $values->id,
                        'methodname' => $confirmmethod ?? '',
                        'nomodal' => false,
                        'selectionmandatory' => true,
                        'data' => [
                            'id' => "$values->id",
                            'titlestring' => 'confirmrequesttitle',
                            'requestid' => $values->id,
                            'bodystring' => $bodystring ?? '',
                            'submitbuttonstring' => 'confirmdatasubmit',
                            'component' => 'local_taskflow',
                            'labelcolumn' => 'rulename',
                            'assignmentid' => $values->assignmentid,
                            'userofrequest' => $values->userid,
                            'otherdata' => $values->json ?? '',
                        ],
                    ];
                }

                if (isset($declinemmethod)) {
                    $data[] = [
                        'label' => '',
                        'href' => '#',
                        'iclass' => 'fa fa-thumbs-down',
                        'arialabel' => 'decline',
                        'title' => get_string('requestdecline', 'local_taskflow'),
                        'id' => $values->id . '-'  . $this->uniqueid,
                        'name' => $this->uniqueid . '-' . $values->id,
                        'methodname' => $declinemmethod ?? '',
                        'nomodal' => false,
                        'selectionmandatory' => true,
                        'data' => [
                            'id' => "$values->id",
                            'titlestring' => 'declinerequesttitle',
                            'requestid' => $values->id,
                            'bodystring' => 'declinedatabody',
                            'submitbuttonstring' => 'declinedatasubmit',
                            'component' => 'local_taskflow',
                            'labelcolumn' => 'assignmentid',
                            'assignmentid' => $values->assignmentid,
                            'userofrequest' => $values->userid,
                        ],
                    ];
                }

                if (empty($data)) {
                    $returnvalue = "";
                    break;
                }
                table::transform_actionbuttons_array($data);
                $returnvalue = $OUTPUT->render_from_template(
                    'local_wunderbyte_table/component_actionbutton',
                    ['showactionbuttons' => $data]
                );
                break;
            default:
                $returnvalue = "";
        }
        return $returnvalue . $html;
    }

    /**
     * Description.
     * @param mixed $values
     * @return string
     */
    public function col_description($values) {
        $jsonobject = json_decode($values->rulejson);
        return html_writer::div($jsonobject->rulejson->rule->description);
    }

    /**
     * Returns Fullname.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_fullname($values) {
        $user = core_user::get_user($values->userid);
        return $user->firstname . " " . $user->lastname;
    }
    /**
     * Is active.
     * @param mixed $values
     * @return string
     */
    public function col_isactive($values) {
        return html_writer::div($values->isactive ? get_string('yes') : get_string('no'));
    }

    /**
     * Link to corresponding assignment
     * @param mixed $values
     * @return string
     */
    public function col_assignmentid($values) {
        $assignment = new assignment($values->assignmentid);
        $rule = '';
        if (isset($assignment->rulejson)) {
            $rule = $assignment->rulejson ?? '';
        }
        $rule = json_decode($rule);

        $rulename = '';
        if (isset($rule->rulejson->rule->name)) {
            $rulename = $rule->rulejson->rule->name;
        }
        $url = new moodle_url(
            '/local/taskflow/assignment.php',
            ['id' => $values->assignmentid]
        );

        return html_writer::div(html_writer::link(
            $url->out(),
            $rulename,
            ['target' => '_blank']
        ));
    }

    /**
     * Returns a human readable timestamp of the time created.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_timecreated($values): string {
        return userdate($values->timecreated, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Returns a human readable timestamp of the time created.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_status($values): string {
        return requests::resolve_status($values->status);
    }

    /**
     * Confirming the request.
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_confirmrequest(int $id, string $data) {
        require_capability('local/taskflow:treatrequests', context_system::instance());

        $data = json_decode($data);
        $request = new requests();
        $feedback = $request->treat_request(
            $data->requestid,
            $data->assignmentid,
            $data->userofrequest,
            requests::TREATED_STATUS_CONFIRMED
        );
        if (!$feedback) {
            return [
                'success' => 0,
                'feedback' => get_string('error'),
            ];
        }
        return [
           'success' => 1,
           'feedback' => get_string('requestconfirmsuccess', 'local_taskflow'),
        ];
    }

    /**
     * Declining the request.
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_declinerequest(int $id, string $data) {
        require_capability('local/taskflow:treatrequests', context_system::instance());

        $data = json_decode($data);
        $request = new requests();
        $feedback = $request->treat_request(
            $data->requestid,
            $data->assignmentid,
            $data->userofrequest,
            requests::TREATED_STATUS_DECLINED
        );
        if (!$feedback) {
            return [
                'success' => 0,
                'feedback' => get_string('error'),
            ];
        }
        return [
           'success' => 1,
           'feedback' => get_string('requestdeclinesuccess', 'local_taskflow'),
        ];
    }

    /**
     * Confirm prolongation.
     *
     * @param int $id
     * @param string $data
     *
     * @return void
     *
     */
    public function action_confirmprolongation(int $id, string $data) {
        require_capability('local/taskflow:treatrequests', context_system::instance());
        $data = json_decode($data);
        $request = new requests();
        $request->update_request_treated(
            $data->requestid,
            $data->assignmentid,
            $data->userofrequest,
            requests::TREATED_STATUS_CONFIRMED
        );
    }
    /**
     * Decline prolongation.
     *
     * @param int $id
     * @param string $data
     *
     * @return void
     *
     */
    public function action_declineprolongation(int $id, string $data) {
        require_capability('local/taskflow:treatrequests', context_system::instance());
        $data = json_decode($data);
        $request = new requests();
        $request->update_request_treated(
            $data->requestid,
            $data->assignmentid,
            $data->userofrequest,
            requests::TREATED_STATUS_DECLINED
        );
    }
}
