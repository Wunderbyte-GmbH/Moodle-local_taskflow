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
 * Condition to identify users by entering a value which should match a custom user profile field.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\taskflow_rules\conditions;

use local_taskflow\taskflow_rules\taskflow_rule_condition;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/taskflow/lib.php');

/**
 * Class to handle condition to identify users by entering a value which should match a custom user profile field.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enter_userprofilefield implements taskflow_rule_condition {
    /** @var string $conditionname */
    public $conditionname = 'enter_userprofilefield';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'enteruserprofilefield';

    /** @var string $cpfield */
    public $cpfield = null;

    /** @var string $operator */
    public $operator = null;

    /** @var string $textfield */
    public $textfield = null;

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
        $this->textfield = $conditiondata->textfield;
    }

    /**
     * Add condition to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        global $DB;

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('choose...', 'local_taskflow');

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }
            $operators = [
                '=' => get_string('equals', 'local_taskflow'),
                '~' => get_string('contains', 'local_taskflow'),
            ];
            $mform->setType('condition_enter_userprofilefield_textfield', PARAM_TEXT);
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
     * Saves the JSON for the condition into the $data object.
     *
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
        $jsonobject->conditiondata->cpfield = $data->condition_enter_userprofilefield_cpfield ?? '';
        $jsonobject->conditiondata->operator = $data->condition_enter_userprofilefield_operator ?? '';
        $jsonobject->conditiondata->textfield = $data->condition_enter_userprofilefield_textfield ?? '';

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

        $data->condition_enter_userprofilefield_cpfield = $conditiondata->cpfield;
        $data->condition_enter_userprofilefield_operator = $conditiondata->operator;
        $data->condition_enter_userprofilefield_textfield = $conditiondata->textfield;
    }

    /**
     * Execute the condition.
     *
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params) {
        global $DB;

        $sqlcomparepart = "";
        switch ($this->operator) {
            case '~':
                $concat = $DB->sql_concat("'%'", ":conditiontextfield", "'%'");
                $sqlcomparepart = $DB->sql_compare_text("ud.data") .
                    " LIKE $concat
                      AND :conditiontextfield1 <> ''";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = :conditiontextfield";
                break;
        }

        $params['conditiontextfield'] = $this->textfield;
        $params['conditiontextfield1'] = $this->textfield;

        $anduserid = '';
        if (!empty($params['userid'])) {
            // We cannot use params twice, so we need to use userid2.
            $params['userid2'] = $params['userid'];
            $anduserid = "AND ud.userid = :userid2";
        }

        $concat = $DB->sql_concat("bo.id", "'-'", "ud.userid");
        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql->select = " $concat uniqueid, " . $sql->select;
        $sql->select .= ", ud.userid userid";

        $sql->from .= " JOIN {user_info_data} ud ON $sqlcomparepart";

        $sql->where .= " AND ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                $anduserid ";

        $params['cpfield'] = $this->cpfield;
    }
}
