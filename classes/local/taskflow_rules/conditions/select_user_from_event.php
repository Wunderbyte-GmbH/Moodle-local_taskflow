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
 * Condition to identify the user who triggered an event or the user who was affected by an event.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\taskflow_rules\conditions;

use local_taskflow\taskflow_rules\taskflow_rule_condition;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/taskflow/lib.php');

/**
 * Condition to identify the user who triggered an event (userid of event).
 *
 * @package local_taskflow
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class select_user_from_event implements taskflow_rule_condition {
    /** @var string $conditionname */
    public $conditionname = 'select_user_from_event';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectuserfromevent';

    /** @var string $conditiontype */
    public $userfromeventtype = '0';

    /** @var int $userid the user who triggered an event */
    public $userid = 0;

    /** @var int $relateduserid the user affected by an event */
    public $relateduserid = 0;

    /** @var string $rulejson a json string for a taskflow rule */
    public $rulejson = '';

    /**
     * Function to tell if a condition can be combined with a certain taskflow rule type.
     * @param string $taskflowruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_taskflowruletype(string $taskflowruletype): bool {
        // This rule cannot be combined with the "days before" rule as it has no event.
        if ($taskflowruletype == 'rule_daysbefore') {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule condition record from DB
     */
    public function set_conditiondata(stdClass $record) {
        $this->set_conditiondata_from_json($record->rulejson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a taskflow rule
     */
    public function set_conditiondata_from_json(string $json) {
        $this->rulejson = $json;
        $ruleobj = json_decode($json);

        if (!empty($ruleobj->conditiondata->userfromeventtype)) {
            $this->userfromeventtype = $ruleobj->conditiondata->userfromeventtype;
        }

        $event = $ruleobj->ruledata->boevent::restore((array)$ruleobj->datafromevent, []);

        $datafromevent = $event->get_data();

        // The user who triggered the event.
        if (!empty($datafromevent['userid'])) {
            $this->userid = $datafromevent['userid'];
        }

        // The user affected by the event.
        if (!empty($datafromevent['relateduserid'])) {
            $this->relateduserid = $datafromevent['relateduserid'];
        }
    }

    /**
     * Add condition to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {

        // The event selected in the form.
        $eventnameonly = '';
        if (!empty($ajaxformdata["rule_react_on_event_event"])) {
            $eventnameonly = str_replace("\\local_taskflow\\event\\", "", $ajaxformdata["rule_react_on_event_event"]);
        }

        // This is a list of events supporting relateduserid (affected user of the event).
        $eventssupportingrelateduserid = [
            'taskflowoption_completed',
            'custom_message_sent',
            'taskflowanswer_confirmed',
            'taskflowanswer_cancelled',
            'taskflowoptionwaitinglist_booked',
            'taskflowoption_booked',
            'taskflowanswer_waitingforconfirmation',
            '\local_shopping_cart\event\item_bought',
            '\local_shopping_cart\event\item_canceled',
            '\local_shopping_cart\event\payment_confirmed',
            // More events yet to come...
        ];

        $userfromeventoptions["0"] = get_string('choose...', 'local_taskflow');
        if (empty($eventnameonly) || in_array($eventnameonly, $eventssupportingrelateduserid)) {
            $userfromeventoptions["relateduserid"] = get_string('useraffectedbyevent', 'local_taskflow');
        }
        // Userid (user who triggered) must be supported by every event. If not, the event was not created correctly.
        $userfromeventoptions["userid"] = get_string('userwhotriggeredevent', 'local_taskflow');
    }

    /**
     * Get the name of the condition.
     *
     * @param bool $localized
     * @return string the name of the condition
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionnamestringid, 'local_taskflow') : $this->conditionname;
    }

    /**
     * Saves the JSON for the condition into the $data object.
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data) {
        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();
        $jsonobject->conditiondata->userfromeventtype = $data->condition_select_user_from_event_type ?? '0';

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from taskflow_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->taskflowruleconditiontype = $this->conditionname;

        $jsonobject = json_decode($record->rulejson);
        $data->condition_select_user_from_event_type = $jsonobject->conditiondata->userfromeventtype;
    }

    /**
     * Execute the condition.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params) {

        global $DB;

        switch ($this->userfromeventtype) {
            case "userid":
                // The user who triggered the event.
                $chosenuserid = $this->userid;
                break;
            case "relateduserid":
                $chosenuserid = $this->relateduserid;
                // The user affected by the event.
                break;
            default:
                throw new moodle_exception('error: missing userid type for userfromevent condition');
        }

        $concat = $DB->sql_concat("bo.id", "'-'", "u.id");
        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql->select = " $concat uniqueid, " . $sql->select;
        $sql->select .= ", u.id userid";
        $sql->from .= " JOIN {user} u ON u.id = :chosenuserid "; // We want to join only the chosen user.

        $params['chosenuserid'] = $chosenuserid;
    }
}
