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
 * Class for managing multi-step forms.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\multistepform;

use local_multistepform\manager;

/**
 * Submit data to the server.
 * @package local_multistepform
 * @category external
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright 2025 Wunderbyte GmbH
 */
class editrulesmanager extends manager {
    /**
     * Persist the data to the database.
     * This method needs to be overriden in the child class to save the data the way it's needed.
     *
     * @return void
     *
     */
    public function persist(): void {

        global $DB;
        $steps = $this->get_data();

        // We know which data we can expect from which step.
        // For the first step, we need to instantiate the correct rule type and get back the data.
        $ruleclassname = "local_taskflow\\local\\rules\\types\\" . $steps[1]['ruletype'];
        $ruledata = $ruleclassname::get_data($steps);

        if (!empty($steps[1]->id)) {
            $data['id'] = $steps[1]->id;
            $DB->update_record('local_taskflow_rules', $ruledata);
        } else {
            $DB->insert_record('local_taskflow_rules', $ruledata);
        }
    }

    /**
     * Load the data from the database.
     *
     * @return void
     *
     */
    protected function load_data(): void {
        global $DB;
    }
}
