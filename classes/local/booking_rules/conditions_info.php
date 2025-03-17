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
 * Base class for taskflow conditions information.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\taskflow_rules;

use MoodleQuickForm;

/**
 * Class for additional information of taskflow conditions.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conditions_info {
    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public static function add_conditions_to_mform(
        MoodleQuickForm &$mform,
        ?array &$ajaxformdata = null
    ) {

        $conditions = self::get_conditions();

        $conditionsforselect = [];
        foreach ($conditions as $condition) {
            if (
                !empty($ajaxformdata['taskflowruletype']) &&
                !$condition->can_be_combined_with_taskflowruletype($ajaxformdata['taskflowruletype'])
            ) {
                continue;
            }
            $fullclassname = get_class($condition);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            $conditionsforselect[$shortclassname] = $condition->get_name_of_condition();
        }

        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->registerNoSubmitButton('btn_taskflowruleconditiontype');
        $mform->addElement(
            'select',
            'taskflowruleconditiontype',
            get_string('taskflowrulecondition', 'local_taskflow'),
            $conditionsforselect
        );
        $mform->addElement(
            'submit',
            'btn_taskflowruleconditiontype',
            get_string('taskflowrulecondition', 'local_taskflow'),
            $buttonargs
        );
        $mform->setType('btn_taskflowruleconditiontype', PARAM_NOTAGS);

        if (isset($ajaxformdata['taskflowruleconditiontype'])) {
            $condition = self::get_condition($ajaxformdata['taskflowruleconditiontype']);
        } else {
            list($condition) = $conditions;
        }
        $condition->add_condition_to_mform($mform, $ajaxformdata);
    }

    /**
     * Get all taskflow conditions.
     * @return array an array of taskflow conditions (instances of class taskflow_condition).
     */
    public static function get_conditions() {
        global $CFG;
        $path = $CFG->dirroot . '/local/taskflow/classes/taskflow_rules/conditions/*.php';
        $filelist = glob($path);
        $conditions = [];

        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'local_taskflow\\taskflow_rules\\conditions\\' . $path['filename'];
            if (class_exists($filename)) {
                $instance = new $filename();
                $conditions[] = $instance;
            }
        }

        return $conditions;
    }

    /**
     * Get taskflow rule condition by name.
     * @param string $conditionname
     * @return mixed
     */
    public static function get_condition(string $conditionname) {
        global $CFG;

        $filename = 'local_taskflow\\taskflow_rules\\conditions\\' . $conditionname;
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }
}
