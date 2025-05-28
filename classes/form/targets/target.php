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
 * Form to create rules.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\form\targets;

use local_multistepform\manager;
use local_taskflow\form\form_base;
use MoodleQuickForm;
use stdClass;

/**
 * Demo step 1 form.
 */
class target extends form_base {
    /**
     * Definition.
     *
     * @return void
     *
     */
    protected function definition(): void {
        global $DB;

        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];

        $uniqueid = $formdata['uniqueid'] ?? 0;
        $recordid = $formdata['recordid'] ?? 0;

        $manager = manager::return_class_by_uniqueid($uniqueid, $recordid);
        $manager->definition($mform, $formdata);
        if ($formdata) {
            if (!empty($formdata['targets'])) {
                $repeatcount = count($formdata['targets']);
            } else {
                $repeatcount = count($formdata['targettype'] ?? []) + 1;
            }
            $repeatelements = $this->definition_subelement($mform, $formdata);
            // Get the get_subelement_options!
            $repeateloptions = [
                'user_profile_field_userprofilefield' => ['type' => PARAM_TEXT],
                'user_profile_field_operator' => ['type' => PARAM_TEXT],
                'user_profile_field_value' => ['type' => PARAM_TEXT],
            ];

            $this->repeat_elements(
                $repeatelements,
                $repeatcount,
                $repeateloptions,
                'target_repeats',
                'target_add',
                1,
                get_string('addtarget', 'local_taskflow'),
                true,
            );
            // Loop over repeats and apply condition.
            for ($i = 0; $i < $repeatcount; $i++) {
                $path = __DIR__ . '/types';
                $prefix = 'local_taskflow\\form\\targets\\types\\';
                foreach (glob($path . '/*.php') as $file) {
                    $basename = basename($file, '.php');
                    $classname = $prefix . $basename;
                    if (class_exists($classname)) {
                        $classname::hide_and_disable($mform, $i);
                    }
                }
                $this->hide_and_disable($mform, $i);
            }
        }
    }

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param int $elementcounter
     */
    protected function hide_and_disable(&$mform, $elementcounter) {
        $elements = [
            "fixeddate",
            "duration",
        ];
        foreach ($elements as $element) {
            $mform->hideIf(
                $element . "[$elementcounter]",
                "targetduedatetype[$elementcounter]",
                'neq',
                $element
            );
            $mform->disabledIf(
                $element . "[$elementcounter]",
                "targetduedatetype[$elementcounter]",
                'neq',
                $element
            );
        }
    }

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param array $data
     * @return array
     */
    protected function definition_subelement(MoodleQuickForm &$mform, array &$data) {
        $path = __DIR__ . '/types';
        $prefix = 'local_taskflow\\form\\targets\\types\\';
        $repeatarray = [];
        $targetoptions = [
            'bookingoption' => get_string('targettype:bookingoption', 'local_taskflow'),
            'moodlecourse' => get_string('targettype:moodlecourse', 'local_taskflow'),
            'competency' => get_string('targettype:competency', 'local_taskflow'),
        ];
        $repeatarray[] = $mform->createElement(
            'select',
            'targettype',
            get_string('targettype', 'local_taskflow'),
            $targetoptions
        );

        foreach (glob($path . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = $prefix . $basename;
            if (class_exists($classname)) {
                $classname::definition($repeatarray, $mform);
            }
        }

        $dateoptions = [
            'fixeddate' => get_string('fixeddate', 'local_taskflow'),
            'duration' => get_string('duration', 'local_taskflow'),
        ];
        $repeatarray[] = $mform->createElement(
            'select',
            'targetduedatetype',
            get_string('duedatetype', 'local_taskflow'),
            $dateoptions
        );

        // Due Date - Fixed Date.
        $repeatarray[] =
            $mform->createElement('date_time_selector', 'fixeddate', get_string('fixeddate', 'local_taskflow'));
        $repeatarray[] =
            $mform->createElement('duration', 'duration', get_string('duration', 'local_taskflow'));

        $repeatarray[] = $mform->createElement('html', '<hr>');

        return $repeatarray;
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        // This is needed so data is set correctly.
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        if ($data) {
            foreach ($data['targets'] as $targetmainkey => $targetvalues) {
                foreach ($targetvalues as $targetkey => $targetvalue) {
                    if (!is_string($targetvalue)) {
                        foreach ($targetvalue as $datekey => $datevalue) {
                            $data[$datekey][$targetmainkey] = $datevalue;
                            if (!is_null($datevalue)) {
                                $data['targetduedatetype'][$targetmainkey] = $datekey;
                            }
                        }
                    } else if ($targetkey == 'targetid') {
                        $flattendkey = $targetvalues->targettype . '_' . $targetkey;
                        $data[$flattendkey][$targetmainkey] = $targetvalue;
                    } else {
                        $data[$targetkey][$targetmainkey] = $targetvalue;
                    }
                }
            }
            $this->set_data($data);
        }
    }

    /**
     * Depending on the chosen class type, we pass on the extraction.
     * @param array $step
     * @param array $rulejson
     */
    public function set_data_to_persist(array &$step, &$rulejson) {
        $targets = [];
        foreach ($step['targettype'] as &$targettype) {
            $newtarget = $this->get_target_data($step, $targettype);
            $newtarget['sortorder'] = 2;
            $newtarget['targetname'] = 'Testing Dies Das';
            $newtarget['actiontype'] = 'enroll';
            $newtarget['completebeforenext'] = false;
            $targets[] = $newtarget;
        }
        if (!isset($rulejson['actions'])) {
            $rulejson['actions'] = [];
        }
        $rulejson['actions'][] = [
            'targets' => $targets,
        ];
    }

    /**
     * Implement get data function to return data from the form.
     * @param array $step
     * @param string $targettype
     * @return array
     */
    private function get_target_data(array &$step, $targettype): array {
        $datetype = array_shift($step['targetduedatetype']);
        $dumpdatetype = $datetype == 'duration' ? 'fixeddate' : 'duration';
        array_shift($step[$dumpdatetype]);
        $targetdata = [
            'targettype' => array_shift($step['targettype']),
            'targetid' => array_shift($step[$targettype . '_targetid']),
            'duedate' => [
                "fixeddate" => $datetype == "fixeddate" ? array_shift($step[$datetype]) : null,
                "duration" => $datetype == "duration" ? array_shift($step[$datetype]) : null,
            ],
        ];
        return $targetdata;
    }

    /**
     * With this, we transform the saved data to the right format.
     * @param array $step
     * @param stdClass|array $object
     * @return array
     */
    public static function load_data_for_form(array $step, $object): array {
        $actions = $object->actions;
        foreach ($actions as $action) {
            foreach ($action->targets as $target) {
                $step['targets'][] = $target;
            }
        }
        return $step;
    }
}
