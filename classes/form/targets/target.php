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

use local_taskflow\form\form_base;
use local_taskflow\local\actions\targets\targets_factory;
use MoodleQuickForm;
use stdClass;

/**
 * Demo step 1 form.
 */
class target extends form_base {
    /** @var string Event name for user updated. */
    private const PATH = __DIR__ . '/types';

    /** @var string Event name for user updated. */
    private const PREFIX = 'local_taskflow\\form\\targets\\types\\';

    /**
     * Definition.
     *
     * @return void
     *
     */
    protected function definition(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];
        $this->define_manager();

        if ($formdata) {
            if (!empty($formdata['targets'])) {
                $repeatcount = count($formdata['targets']);
            } else {
                $repeatcount = $formdata['target_repeats'] ?? 1;
            }
            $repeatelements = $this->definition_subelement($mform, $formdata);
            $repeateloptions = $this->definition_options();

            $this->repeat_elements(
                $repeatelements,
                $repeatcount,
                $repeateloptions,
                'target_repeats',
                'target_add',
                1,
                get_string('addtarget', 'local_taskflow'),
                true,
                'deleteelement'
            );

            $ids = $this->get_element_ids($mform->_elements, 'targettype');
            // Loop over repeats and apply condition.
            foreach ($ids as $id) {
                foreach (glob(self::PATH . '/*.php') as $file) {
                    $basename = basename($file, '.php');
                    $classname = self::PREFIX . $basename;
                    if (class_exists($classname)) {
                        $instance = new $classname();
                        $instance->hide_and_disable($mform, $id);
                    }
                }
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
            // phpcs:ignore
            // "fixeddate",
            // "duration",
        ];
        foreach ($elements as $element) {
            $mform->hideIf(
                $element . "[$elementcounter]",
                "duedatetype[$elementcounter]",
                'neq',
                $element
            );
            $mform->disabledIf(
                $element . "[$elementcounter]",
                "duedatetype[$elementcounter]",
                'neq',
                $element
            );
        }
    }

    /**
     * This class passes on the fields for the mform.
     * @return array
     */
    private function definition_options() {
        $repeateloptions = [];
        foreach (glob(self::PATH . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = self::PREFIX . $basename;
            if (class_exists($classname)) {
                $instance = new $classname();
                $repeateloptions = array_merge($repeateloptions, $instance->get_options());
            }
        }
        return $repeateloptions;
    }

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param array $data
     * @return array
     */
    private function definition_subelement(MoodleQuickForm &$mform, array &$data) {
        $repeatarray = [];
        $targetoptions = [
            '' => get_string('choosetargettype', 'local_taskflow'),
            'moodlecourse' => get_string('targettype:moodlecourse', 'local_taskflow'),
            'competency' => get_string('targettype:competency', 'local_taskflow'),
        ];
        if (class_exists('mod_booking\\booking')) {
            $targetoptions['bookingoption'] = get_string('targettype:bookingoption', 'local_taskflow');
        }

        $repeatarray[] = $mform->createElement(
            'select',
            'targettype',
            get_string('targettype', 'local_taskflow'),
            $targetoptions
        );

        foreach (glob(self::PATH . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = self::PREFIX . $basename;
            if (class_exists($classname)) {
                $instance = new $classname();
                $instance->definition($repeatarray, $mform);
            }
        }

        $repeatarray[] = $mform->createElement(
            'advcheckbox',
            'completebeforenext',
            get_string('completebeforenext', 'local_taskflow'),
            get_string('checktoactivate', 'local_taskflow')
        );
        $mform->setDefault('completebeforenext', 0);

        $this->add_remove_element_button($repeatarray, $mform);
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
                                $data['duedatetype'][$targetmainkey] = $datekey;
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
            $newtarget['targetname'] = targets_factory::get_name($newtarget['targettype'], $newtarget['targetid']);
            $newtarget['actiontype'] = 'enroll';
            $targets[] = $newtarget;
        }
        if (!isset($rulejson['actions'])) {
            $rulejson['actions'] = [];
        }
        $rulejson['actions'][0]['targets'] = $targets;
    }

    /**
     * Implement get data function to return data from the form.
     * @param array $step
     * @param string $targettype
     * @return array
     */
    private function get_target_data(array &$step, $targettype): array {
        $completebeforenext = $step['completebeforenext'] ?? [];
        $targetdata = [
            'targettype' => array_shift($step['targettype']),
            'targetid' => array_shift($step[$targettype . '_targetid']),
            'completebeforenext' => array_shift($completebeforenext),
        ];
        // We currently don't use the target due date, so we skip the saving of it.
        if (isset($step['duedatetype'])) {
            $datetype = array_shift($step['duedatetype']);
            $dumpdatetype = $datetype == 'duration' ? 'fixeddate' : 'duration';
            array_shift($step[$dumpdatetype]);

            $targetdata['duedate'] = [
                "fixeddate" => $datetype == "fixeddate" ? array_shift($step[$datetype]) : null,
                "duration" => $datetype == "duration" ? array_shift($step[$datetype]) : null,
            ];
        }
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
