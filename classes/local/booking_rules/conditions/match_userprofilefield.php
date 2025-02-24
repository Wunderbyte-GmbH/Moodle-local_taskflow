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

namespace local_taskflow\taskflow_rules\conditions;

use local_taskflow\taskflow_rules\taskflow_rule;
use local_taskflow\taskflow_rules\taskflow_rule_condition;
use local_taskflow\singleton_service;
use local_taskflow\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/taskflow/lib.php');

/**
 * Condition how to identify concerned users by matching taskflow option field and user profile field.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_userprofilefield implements taskflow_rule_condition {

    /** @var string $rulename */
    public $conditionname = 'match_userprofilefield';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'matchuserprofilefield';

    /** @var string $cpfield */
    public $cpfield = null;

    /** @var string $operator */
    public $operator = null;

    /** @var string $optionfield */
    public $optionfield = null;

    /** @var string $rulejson a json string for a taskflow rule */
    public $rulejson = '';

    /**
     * Function to tell if a condition can be combined with a certain taskflow rule type.
     * @param string $taskflowruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_taskflowruletype(string $taskflowruletype): bool {
        // This condition can currently be combined with any rule.
        return true;
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
        $conditiondata = $ruleobj->conditiondata;
        $this->cpfield = $conditiondata->cpfield;
        $this->operator = $conditiondata->operator;
        $this->optionfield = $conditiondata->optionfield;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        global $DB;

        // Get a list of allowed option fields to compare with custom user profile field.
        // Currently we only use fields containing VARCHAR in DB.
        $allowedoptionfields = [
            '0' => get_string('choose...', 'local_taskflow'),
            'text' => get_string('ruleoptionfieldtext', 'local_taskflow'),
            'location' => get_string('ruleoptionfieldlocation', 'local_taskflow'),
            'address' => get_string('ruleoptionfieldaddress', 'local_taskflow'),
        ];

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('choose...', 'local_taskflow');

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }

            $mform->addElement('select', 'condition_match_userprofilefield_cpfield',
                get_string('rulecustomprofilefield', 'local_taskflow'), $customuserprofilefieldsarray);

            $operators = [
                '=' => get_string('equals', 'local_taskflow'),
                '~' => get_string('contains', 'local_taskflow'),
            ];
            $mform->addElement('select', 'condition_match_userprofilefield_operator',
                get_string('ruleoperator', 'local_taskflow'), $operators);

            $mform->addElement('select', 'condition_match_userprofilefield_optionfield',
                get_string('ruleoptionfield', 'local_taskflow'), $allowedoptionfields);

        }

    }

    /**
     * Get the name of the rule.
     *
     * @param bool $localized
     * @return string the name of the rule
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionnamestringid, 'local_taskflow') : $this->conditionname;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     *
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data) {
        global $DB;

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();
        $jsonobject->conditiondata->optionfield = $data->condition_match_userprofilefield_optionfield ?? '';
        $jsonobject->conditiondata->operator = $data->condition_match_userprofilefield_operator ?? '';
        $jsonobject->conditiondata->cpfield = $data->condition_match_userprofilefield_cpfield ?? '';

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
        $conditiondata = $jsonobject->conditiondata;

        $data->condition_match_userprofilefield_optionfield = $conditiondata->optionfield;
        $data->condition_match_userprofilefield_operator = $conditiondata->operator;
        $data->condition_match_userprofilefield_cpfield = $conditiondata->cpfield;

    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optionid & cmid.
     * @param stdClass $sql
     * @param array $params
     * @return array
     */
    public function execute(stdClass &$sql, array &$params) {
        global $DB;

        $sqlcomparepart = "";

        $concat = $DB->sql_concat("'%'", "bo.$this->optionfield", "'%'");
        switch ($this->operator) {
            case '~':
                $sqlcomparepart = $DB->sql_compare_text("ud.data") .
                    " LIKE $concat
                      AND bo." . $this->optionfield . " <> ''
                      AND bo." . $this->optionfield . " IS NOT NULL";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = bo." . $this->optionfield;
                break;
        }

        // We pass the restriction to the userid in the params.
        // If its not 0, we add the restirction.
        $anduserid = '';
        if (!empty($params['userid'])) {
            // We cannot use params twice, so we need to use userid2.
            $params['userid2'] = $params['userid'];
            $anduserid = "AND ud.userid = :userid2";
        }

        $concat = $DB->sql_concat("bo.id", "'-'", "ud.userid");
        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql->select = " $concat uniqueid, " . $sql->select;
        $sql->select .= ", ud.userid userid ";

        $sql->from .= " JOIN {user_info_data} ud ON $sqlcomparepart ";

        $sql->where .= " AND ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                $anduserid ";

        $params['cpfield'] = $this->cpfield;
    }
}
