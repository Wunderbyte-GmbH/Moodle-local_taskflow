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

use MoodleQuickForm;
use local_taskflow\taskflow_rules\taskflow_rule_action;

/**
 * Class for additional information of taskflow rules.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actions_info {
    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @param array|null $ajaxformdata
     * @return void
     */
    public static function add_actions_to_mform(
        MoodleQuickForm &$mform,
        array &$repeateloptions,
        ?array &$ajaxformdata = null
    ) {

        $actions = self::get_actions();

        $actionsforselect = [];
        foreach ($actions as $action) {
            $fullclassname = get_class($action);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            if (!$action->is_compatible_with_ajaxformdata($ajaxformdata)) {
                continue;
            }
            $actionsforselect[$shortclassname] = $action->get_name_of_action();
        }
        $actionsforselect = array_reverse($actionsforselect);
        $mform->registerNoSubmitButton('btn_taskflowruleactiontype');
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement(
            'select',
            'taskflowruleactiontype',
            get_string('taskflowruleaction', 'local_taskflow'),
            $actionsforselect
        );
        if (isset($ajaxformdata['taskflowruleactiontype'])) {
            $mform->setDefault('taskflowruleactiontype', $ajaxformdata['taskflowruleactiontype']);
        }
        $mform->addElement(
            'submit',
            'btn_taskflowruleactiontype',
            get_string('taskflowruleaction', 'local_taskflow'),
            $buttonargs
        );
        $mform->setType('btn_taskflowruleactiontype', PARAM_NOTAGS);

        foreach ($actions as $action) {
            if ($ajaxformdata && isset($ajaxformdata['taskflowruleactiontype'])) {
                $actionname = $action->get_name_of_action();
                $localicedactionname = get_string(str_replace("_", "", $ajaxformdata['taskflowruleactiontype']), 'local_taskflow');
                if (
                    $ajaxformdata['taskflowruleactiontype'] &&
                    $actionname == $localicedactionname
                ) {
                    $action->add_action_to_mform($mform, $repeateloptions);
                }
            } else {
                // We only render the first rule.
                $action->add_action_to_mform($mform, $repeateloptions);
                break;
            }
        }
    }

    /**
     * Get all taskflow rules actions.
     * @return array an array of taskflow rule actions (instances of class taskflow_rule_action).
     */
    public static function get_actions() {
        global $CFG;

        // First, we get all the available rules from our directory.
        $path = $CFG->dirroot . '/local/taskflow/classes/taskflow_rules/actions/*.php';
        $filelist = glob($path);

        $actions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'local_taskflow\\taskflow_rules\\actions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $actions[] = $instance;
            }
        }

        return $actions;
    }

    /**
     * Get taskflow rule action by name.
     * @param string $actionname
     * @return mixed
     */
    public static function get_action(string $actionname) {
        global $CFG;

        $filename = 'local_taskflow\\taskflow_rules\\actions\\' . $actionname;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }
}
