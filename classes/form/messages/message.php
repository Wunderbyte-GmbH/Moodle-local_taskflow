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
use stdClass;

/**
 * Demo step 1 form.
 */
class message extends form_base {
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
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        // This is needed so data is set correctly.
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];

        // You can add more data to be set here.
        if ($data) {
            $data['filtertype'] = 'user_profile_field'; // Default rule type.
            $this->set_data($data);
        }
    }

    /**
     * Depending on the chosen class type, we pass on the extraction.
     * @param array $step
     * @return array
     *
     */
    public function get_data_to_persist(array $step): array {
        // We need to extract the right target type.
        $data = [];
        $targetdata = $step;
        // We might have a couple of filters with different types.
        // Also, targettype comes in an array.
        foreach ($step['targettype'] as $key => $value) {
            foreach ($step as $stepkey => $stepvalue) {
                if (
                    is_array($step[$stepkey])
                    && isset($step[$stepkey][$key])
                ) {
                    $targetdata[$stepkey] = $step[$stepkey][$key];
                }
            }
            $filtertypeclass = 'local_taskflow\\local\\actions\\targets\\types\\' . $step['targettype'][$key];
            if (class_exists($filtertypeclass)) {
                $targettypedata = $filtertypeclass::get_data($targetdata);
                $data[] = $targettypedata;
            }
        }

        return $data;
    }

    /**
     * With this, we transform the saved data to the right format.
     *
     * @param array $step
     * @param stdClass|array $object
     *
     * @return array
     *
     */
    public static function load_data_for_form(array $step, $object): array {
        // We might have an array of objects.
        if (!is_array($object)) {
            $object = [$object];
        }

        foreach ($object as $item) {
            foreach ($item as $key => $value) {
                if ($key == 'target_repeats') {
                    $step[$key] = $value;
                } else if (isset($step[$key])) {
                    $step[$key][] = $value;
                } else {
                    $step[$key] = [$value];
                }
            }
        }

        return $step;
    }
}
