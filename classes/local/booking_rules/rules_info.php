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
 * Base class for taskflow rules information.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\taskflow_rules;

use context_module;
use dml_exception;
use context_system;
use MoodleQuickForm;
use stdClass;

/**
 * Class for additional information of taskflow rules.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules_info {
    /**
     * Collect events to execute them at the end of the request.
     *
     * @var array
     */
    public static $rulestoexecute = [];

    /**
     * Collect events to execute them at the end of the request.
     *
     * @var array
     */
    public static $eventstoexecute = [];

    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @param ?array $ajaxformdata
     * @return void
     */
    public static function add_rules_to_mform(
        MoodleQuickForm &$mform,
        array &$repeateloptions,
        ?array &$ajaxformdata = null
    ) {

        // First, get all the type of rules there are.
        $rules = self::get_rules();

        $rulesforselect = [];
        $rulesforselect['0'] = get_string('choose...', 'local_taskflow');
        foreach ($rules as $rule) {
            $fullclassname = get_class($rule); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $rulesforselect[$shortclassname] = $rule->get_name_of_rule();
        }

        $mform->addElement('hidden', 'contextid');
        $mform->setType('contextid', PARAM_INT);

        // The custom name of the role has to be at this place, but every rule will implement save and set of rule_name.
        $mform->addElement(
            'text',
            'rule_name',
            get_string('rulename', 'local_taskflow'),
            ['size' => '50']
        );
        $mform->setType('rule_name', PARAM_TEXT);
        $repeateloptions['rule_name']['type'] = PARAM_TEXT;

        $templates = [];
        $buttonargs = ['class' => 'd-none'];

        $mform->registerNoSubmitButton('btn_taskflowruletemplates');
        $mform->addElement(
            'select',
            'taskflowruletemplate',
            get_string('taskflowruletemplates', 'local_taskflow'),
            $templates
        );
        $mform->addElement(
            'submit',
            'btn_taskflowruletemplates',
            get_string('taskflowruletemplates', 'local_taskflow'),
            $buttonargs
        );
        $mform->setType('btn_taskflowruletemplates', PARAM_NOTAGS);

        if (has_capability('mod/taskflow:manageoptiontemplates', context_system::instance())) {
            $mform->addElement(
                'advcheckbox',
                'useastemplate',
                get_string('taskflowuseastemplate', 'local_taskflow')
            );
        }
        $mform->addElement(
            'advcheckbox',
            'ruleisactive',
            get_string('taskflowruleapply', 'local_taskflow'),
            get_string('taskflowruleapplydesc', 'local_taskflow'),
            null,
            null,
            [0, 1]
        );
        // Fetch data for default value.
        $active = (isset($ajaxformdata['isactive']) && empty($ajaxformdata['isactive'])) ? 0 : 1;
        $mform->setDefault('ruleisactive', $active);

        $mform->registerNoSubmitButton('btn_taskflowruletype');
        $mform->addElement(
            'select',
            'taskflowruletype',
            get_string('taskflowrule', 'local_taskflow'),
            $rulesforselect
        );
        $mform->addElement(
            'submit',
            'btn_taskflowruletype',
            get_string('taskflowrule', 'local_taskflow'),
            $buttonargs
        );
        $mform->setType('btn_taskflowruletype', PARAM_NOTAGS);

        if (isset($ajaxformdata['taskflowruletype'])) {
            $rule = self::get_rule($ajaxformdata['taskflowruletype']);
        } else {
            [$rule] = $rules;
        }

        // We skip if no rule was selected.
        if (empty($rule)) {
            return;
        }

        $rule->add_rule_to_mform($mform, $repeateloptions, $ajaxformdata);

        $mform->addElement('html', '<hr>');

        // At this point, we also load the conditions.
        conditions_info::add_conditions_to_mform($mform, $ajaxformdata);

        $mform->addElement('html', '<hr>');

        // Finally, we load the actions.
        actions_info::add_actions_to_mform($mform, $repeateloptions, $ajaxformdata);
    }

    /**
     * Get all taskflow rules.
     * @return array an array of taskflow rules (instances of class taskflow_rule).
     */
    public static function get_rules() {
        global $CFG;

        // First, we get all the available rules from our directory.
        $path = $CFG->dirroot . '/local/taskflow/classes/taskflow_rules/rules/*.php';
        $filelist = glob($path);

        $rules = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'local_taskflow\\taskflow_rules\\rules\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $rules[] = $instance;
            }
        }

        return $rules;
    }

    /**
     * Get taskflow rule by name.
     * @param string $rulename
     * @return mixed
     */
    public static function get_rule(string $rulename) {

        $filename = 'local_taskflow\\taskflow_rules\\rules\\' . $rulename;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Prepare data to set data to form.
     *
     * @param object $data
     * @return object
     */
    public static function set_data_for_form(object &$data) {
        global $DB;

        if (empty($data->id)) {
            // Nothing to set, we return empty object.
            return new stdClass();
        }
        $record = $DB->get_record('taskflow_rules', ['id' => $data->id]);

        $data->contextid = $record->contextid;

        $rule = self::get_rule($record->rulename);

        $rulejsonobject = json_decode($record->rulejson);

        $condition = conditions_info::get_condition($rulejsonobject->conditionname);
        $action = actions_info::get_action($rulejsonobject->actionname);

        // These function just add their bits to the object.
        $data->useastemplate = $record->useastemplate;
        $data->ruleisactive = isset($record->ruleisactive) ? $record->ruleisactive : 1;
        $condition->set_defaults($data, $record);
        $action->set_defaults($data, $record);
        $rule->set_defaults($data, $record);
        return (object)$data;
    }

    /**
     * Save all taskflow rules.
     * @param stdClass $data reference to the form data
     * @return void
     */
    public static function save_taskflow_rule(stdClass &$data) {

        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one rule.
        $rule = self::get_rule($data->taskflowruletype);
        $condition = conditions_info::get_condition($data->taskflowruleconditiontype);
        $action = actions_info::get_action($data->taskflowruleactiontype);

        // These function don't really save to DB, they just add the values to the rulejson key.
        $condition->save_condition($data);
        $action->save_action($data);

        // Rule has to be saved last, because it actually writes to DB.
        $rule->save_rule($data);

        self::execute_taskflow_rules();

        return;
    }

    /**
     * Execute all taskflow rules.
     */
    public static function execute_taskflow_rules() {
        global $DB;
        if ($records = $DB->get_records('taskflow_rules')) {
            foreach ($records as $record) {
                if (!$rule = self::get_rule($record->rulename)) {
                    continue;
                }
                // Important: Load the rule data from JSON into the rule instance.
                $rule->set_ruledata($record);
                // Now the rule can be executed.
                $rule->execute();
            }
        }
    }

    /**
     * After an option has been added or updated,
     * we need to check if any rules need to be applied or changed.
     * Also, after a user has booked we run this.
     * @param int $optionid
     * @param int $userid
     * @return void
     * @throws dml_exception
     */
    public static function execute_rules_for_option(int $optionid, int $userid = 0) {
        global $DB;

        $settings = new stdClass();
        if (!$cmid = $settings->cmid) {
            return;
        }

        $context = context_module::instance($cmid);
        $contextid = $context->id;

        // Only fetch rules which need to be reapplied. At the moment, it's just one.
        // Eventbased rules don't have to be reapplied.
        if ($records = taskflow_rules::get_list_of_saved_rules_by_context($contextid, '')) {
            foreach ($records as $record) {
                if ($record->rulename != 'rule_daysbefore') {
                    continue;
                }

                if (!$rule = self::get_rule($record->rulename)) {
                    continue;
                }
                // Important: Load the rule data from JSON into the rule instance.
                $rule->set_ruledata($record);

                // Now the rule can be executed.
                $rule->execute($optionid, $userid);
            }
        }
    }

    /**
     * After a user has been added or updated,
     * we need to check if any rules need to be applied or changed.
     * @param int $userid
     */
    public static function execute_rules_for_user(int $userid) {
        global $DB;
        // Only fetch rules which need to be reapplied. At the moment, it's just one.
        // Eventbased rules don't have to be reapplied.
        if ($records = $DB->get_records('taskflow_rules', ['rulename' => 'rule_daysbefore'])) {
            foreach ($records as $record) {
                if (!$rule = self::get_rule($record->rulename)) {
                    continue;
                }
                // Important: Load the rule data into the rule instance.
                $rule->set_ruledata($record);
                // Now the rule can be executed.
                $rule->execute(0, $userid);
            }
        }
    }

    /**
     * Delete a taskflow rule by its ID.
     * @param int $ruleid the ID of the rule
     */
    public static function delete_rule(int $ruleid) {
        global $DB;
        $DB->delete_records('taskflow_rules', ['id' => (int)$ruleid]);
    }

    /**
     * Execute rules for event.
     *
     * @param \core\event\base $event
     *
     * @return void
     *
     */
    public static function collect_rules_for_execution(\core\event\base $event) {

        $data = $event->get_data();

        // Check if rule is from taskflow plugin or another.
        if ($data['component'] !== 'local_taskflow') {
            if (!self::proceed_with_event($event, $data)) {
                return;
            };
        }
        // Triggered again with optionid 1 ??
        $optionid = $event->objectid ?? $data['other']['itemid'] ?? 0;
        $eventname = "\\" . get_class($event);

        $contextid = $event->contextid;
        $records = taskflow_rules::get_list_of_saved_rules_by_context($contextid, $eventname);

        // Now we check all the existing rules from taskflow.
        foreach ($records as $record) {
            $rule = self::get_rule($record->rulename);

            // THIS is the place where we need to add event data to the rulejson!
            $ruleobj = json_decode($record->rulejson);

            $ruleobj->datafromevent = $data;
            // We save rulejson again with added event data.
            $record->rulejson = json_encode($ruleobj);
            // Save it into the rule.
            $rule->set_ruledata($record);

            // We only execute if the rule in question listens to the right event.
            if (!empty($rule->boevent)) {
                if ($data['eventname'] == $rule->boevent) {
                    self::$rulestoexecute[] = [
                        'optionid' => $optionid,
                        'rule' => $rule,
                        'ruleid' => $rule->ruleid,
                    ];
                }
            }
        }
    }

    /**
     * Run through all the collected events, filter them and execute them.
     *
     * @return void     *
     */
    public static function filter_rules_and_execute() {

        // 1. Determine which rules exclude each other and delete those rules.
        // 2. Execute remaing rules.

        $allrules = self::$rulestoexecute;

        if (empty($allrules)) {
            return;
        }

        $rulestoexecute = $allrules;

        foreach ($allrules as $ruleid => $rulearray) {
            // Run through all the excluded rules of this array and unset them.
            $rule = $rulearray['rule'];

            if (empty($rule->ruleisactive)) {
                // Inactive rules can't exculde others.
                continue;
            }
            $ruleobject = json_decode($rule->rulejson);
            $ruledata = $ruleobject->ruledata;
            if (!empty($ruledata->cancelrules)) {
                foreach ($ruledata->cancelrules as $cancelrule) {
                    foreach ($rulestoexecute as $key => $rulearray) {
                        if ($rulearray['ruleid'] == $cancelrule) {
                            unset($rulestoexecute[$key]);
                            unset(self::$rulestoexecute[$key]);
                        }
                    }
                }
            }
        }

        foreach ($rulestoexecute as $key => $rulearray) {
            $rule = $rulearray['rule'];
            if (empty($rule->ruleisactive)) {
                // Inactive rules are not executed.
                continue;
            }
            // Make sure we don't execute this multiple times.
            unset($rulestoexecute[$key]);
            unset(self::$rulestoexecute[$key]);
            $rule->execute($rulearray['optionid'], 0);
        }
    }

    /**
     * Check if taskflow rules are applicable for this type of event.
     *
     * @param \core\event\base $event
     * @param array $data
     *
     * @return bool
     *
     */
    private static function proceed_with_event(\core\event\base $event, array $data): bool {

        switch ($data['component']) {
            case 'local_shopping_cart':
                $acceptedeventsfromshoppingcart = [
                    'item_bought',
                    'item_canceled',
                    'payment_confirmed',
                ];
                foreach ($acceptedeventsfromshoppingcart as $accepted) {
                    if (
                        strpos($data['eventname'], $accepted) !== false
                        && $data['other']['component'] == 'local_taskflow'
                    ) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * Execute Events that need to be executed after executing rules.
     *
     * @return void
     *
     */
    public static function events_to_execute() {

        foreach (self::$eventstoexecute as $key => $event) {
            unset(self::$eventstoexecute[$key]);
            $event();
        }
    }

    /**
     * Destroy all singletons.
     *
     * @return void
     *
     */
    public static function destroy_singletons() {
        self::$rulestoexecute = [];
        self::$eventstoexecute = [];
    }
}
