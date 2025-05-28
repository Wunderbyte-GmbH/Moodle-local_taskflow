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

namespace local_taskflow\form\filters;

use core\output\html_writer;
use local_multistepform\local\cachestore;
use local_taskflow\form\form_base;
use MoodleQuickForm;
use stdClass;

/**
 * Demo step 1 form.
 */
class filter extends form_base {
    /** @var string Event name for user updated. */
    private const PATH = __DIR__ . '/types';

    /** @var string Event name for user updated. */
    private const PREFIX = 'local_taskflow\\form\\filters\\types\\';

    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];
        $uniqueid = $formdata['uniqueid'] ?? 0;
        $recordid = $formdata['recordid'] ?? 0;
        $this->define_manager();

        $cachestore = new cachestore();
        $cachedata = $cachestore->get_multiform($uniqueid, $recordid);
        // Set default values for the form.
        $targettype = $cachedata['steps'][1]['targettype'] ?? null;
        if (
            !is_null($targettype) &&
            $targettype == 'user_target'
        ) {
            $mform->addElement(
                'html',
                html_writer::div(
                    get_string('nofurtherinputs', 'local_taskflow'),
                    'alert alert-info'
                )
            );
        } else if ($formdata) {
            if (!empty($formdata['filter'])) {
                $repeatcount = count($formdata['filter']);
            } else {
                $repeatcount = count($formdata['filtertype'] ?? []) + 1;
            }
            $repeatelements = $this->definition_subelement($mform, $formdata);
            $repeateloptions = $this->definition_options();

            $this->repeat_elements(
                $repeatelements,
                $repeatcount,
                $repeateloptions,
                'filter_repeats',
                'filter_add',
                1,
                get_string('addfilter', 'local_taskflow'),
                true,
            );
            // Loop over repeats and apply condition.
            for ($i = 0; $i < $repeatcount; $i++) {
                $path = __DIR__ . '/types';
                $prefix = 'local_taskflow\\form\\filters\\types\\';
                foreach (glob($path . '/*.php') as $file) {
                    $basename = basename($file, '.php');
                    $classname = $prefix . $basename;
                    if (class_exists($classname)) {
                        $classname::hide_and_disable($mform, $i);
                    }
                }
            }
        }
    }

    /**
     * This class passes on the fields for the mform.
     * @return array
     */
    protected function definition_options() {
        $repeateloptions = [];
        foreach (glob(self::PATH . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = self::PREFIX . $basename;
            if (class_exists($classname)) {
                $repeateloptions = array_merge($repeateloptions, $classname::get_options());
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
    protected function definition_subelement(MoodleQuickForm &$mform, array &$data) {
        $repeatarray = [];
        $repeatarray[] = $mform->createElement(
            'select',
            'filtertype',
            get_string('filtertype', 'local_taskflow'),
            [
                'user_profile_field' => get_string('filteruserprofilefield', 'local_taskflow'),
                'user_field' => get_string('filteruserfield', 'local_taskflow'),
            ]
        );

        foreach (glob(self::PATH . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = self::PREFIX . $basename;
            if (class_exists($classname)) {
                $classname::definition($repeatarray, $mform);
            }
        }
        $repeatarray[] = $mform->createElement('html', '<hr>');

        return $repeatarray;
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        if ($data) {
            foreach ($data['filter'] as $filtervalues) {
                foreach ($filtervalues as $filterkey => $filtervalue) {
                    $flattendkey = $filterkey;
                    if ($filterkey != 'filtertype') {
                        $flattendkey = $filtervalues->filtertype . '_' . $filterkey;
                    }
                    $data[$flattendkey][] = $filtervalue;
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
    public function set_data_to_persist(array $step, &$rulejson) {
        // We need to extract the right filter type.
        $filter = [];
        foreach ($step['filtertype'] as $filtertype) {
            $filtertypeclassname = 'local_taskflow\\form\\filters\\types\\' . $filtertype;
            if (class_exists($filtertypeclassname)) {
                $filtertypeclass = new $filtertypeclassname();
                $newfilter = $filtertypeclass->get_data($step);
                $newfilter['key'] = 'role';
                $filter[] = $newfilter;
            }
        }
        $rulejson['filter'] = $filter;
    }

    /**
     * With this, we transform the saved data to the right format.
     *
     * @param array $step
     * @param array|stdClass $object
     * @return array
     */
    public static function load_data_for_form(array $step, $object): array {
        $filters = $object->filter;
        foreach ($filters as $key => $filter) {
            $step['filter'][$key] = $filter;
        }
        return $step;
    }
}
