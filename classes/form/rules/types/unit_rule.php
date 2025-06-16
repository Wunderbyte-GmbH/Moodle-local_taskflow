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

namespace local_taskflow\form\rules\types;

use context_system;
use MoodleQuickForm;
use stdClass;

/**
 * Demo step 1 form.
 */
class unit_rule {
    /**
     * This class passes on the fields for the mform.
     *
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     *
     * @return void
     *
     */
    public static function definition_after_data(MoodleQuickForm &$mform, stdClass &$data) {
        global $DB, $CFG;
        $useroptions = [];
        if (!empty($data->userid)) {
            $user = $DB->get_record('user', ['id' => $data->userid], '*', IGNORE_MISSING);
            if ($user) {
                require_once($CFG->dirroot . '/user/lib.php');
                $useroptions[$user->id] = fullname($user);
            }
        }

        $mform->addElement(
            'autocomplete',
            'userid',
            get_string('user', 'core'),
            $useroptions,
            [
                'ajax' => 'core_user/form_user_selector',
                'noselectionstring' => get_string('chooseuser', 'local_taskflow'),
                'multiple' => false,
            ]
        );
        $mform->setType('userid', PARAM_INT);
        $context = context_system::instance();
        $cohorts = cohort_get_cohorts($context->id);

        $cohortoptions = [];
        foreach ($cohorts['cohorts'] as $cohort) {
            $cohortoptions[$cohort->id] = $cohort->name;
        }

        $mform->addElement(
            'autocomplete',
            'unitid',
            get_string('cohort', 'cohort'),
            $cohortoptions,
            [
                'noselectionstring' => get_string('choosecohort', 'local_taskflow'),
                'multiple' => false,
            ],
        );
        $mform->setType('unitid', PARAM_INT);
    }

    /**
     * Implement get data function to return data from the form.
     *
     * @param array $steps
     *
     * @return array
     *
     */
    public static function get_data(array $steps): array {
        global $USER;
        // Extract the data from the first step.
        $ruledata = [
            'id' => $steps[1]['recordid'] ?? null,
            'unitid' => $steps[1]['unitid'] ?? null,
            'userid' => $steps[1]['userid'] ?? null,
            'rulename' => $steps[1]['name'],
            'isactive' => $steps[1]['enabled'],
        ];

        $now = time();

        // First we add all the values we need here.
        $rulejson['rulejson']['rule'] = [
            "name" => $steps[1]['name'],
            "description" => $steps[1]['description'],
            "type" => $steps[1]['ruletype'],
            "enabled" => $steps[1]['enabled'],
            "timemodified" => $now,
            "timecreated" => !empty($steps[1]['timecreated']) ? $now : $steps[1]['timecreated'],
            "usermodified" => $USER->id,
            'targetduedatetype' => $steps[1]['targetduedatetype'],
            'duration' => $steps[1]['duration'] ?? 0,
            'fixeddate' => $steps[1]['fixeddate'] ?? 0,
        ];

        // While step one always deals with the general rule type, form here on, everything is generic.
        // Each stepclass has to implement the get_data function.
        $counter = 2;
        while (isset($steps[$counter])) {
            $classname = str_replace('\\\\', '\\', $steps[$counter]['formclass']);
            $stepclass = new $classname();
            $stepclass->set_data_to_persist($steps[$counter], $rulejson['rulejson']['rule']);
            $counter++;
        }

        $ruledata['rulejson'] = json_encode($rulejson);

        return $ruledata;
    }
}
