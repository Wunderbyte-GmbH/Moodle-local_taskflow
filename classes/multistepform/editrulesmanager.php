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

use cache_helper;
use local_multistepform\local\cachestore;
use local_multistepform\manager;
use local_taskflow\event\rule_created_updated;

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
        // We get the formclass from the first step. Normally, this is the rule. It will take care of things.

        $classname = str_replace('\\\\', '\\', $steps[1]['formclass']);
        $class = new $classname();
        $ruledata = $class->get_data_to_persist($steps);

        if (!empty($steps[1]['recordid'])) {
            $ruledata['id'] = $steps[1]['recordid'];
            $DB->update_record('local_taskflow_rules', $ruledata);
        } else {
            $id = $DB->insert_record('local_taskflow_rules', $ruledata);
            $ruledata['id'] = $id;
        }
        $event = rule_created_updated::create([
            'objectid' => $ruledata['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $ruledata,
            ],
        ]);
        \local_taskflow\observer::call_event_handler($event);
        cache_helper::purge_by_event('changesinruleslist');
    }

    /**
     * Load the data from the database.
     * @return void
     *
     */
    protected function load_data(): void {
        global $DB;

        // With this code, we first identify the stepsidentifiers.
        $recordid = 0;
        $stepidentifiers = [];
        foreach ($this->steps as $key => $step) {
            $stepidentifiers[$step['stepidentifier']] = $key;
            $recordid = empty($recordid) ? ($step['recordid'] ?? 0) : $recordid;
        }

        // Now we pass on the code from the stepsidentifiers to the steps.
        if (!empty($recordid)) {
            if ($rule = $DB->get_record('local_taskflow_rules', ['id' => $recordid])) {
                $cachestore = new cachestore();
                $cachedata = $cachestore->get_multiform($this->uniqueid, $this->recordid);

                $ruleobject = json_decode($rule->rulejson);

                // We need to distribute the data to the correct steps.
                foreach ($ruleobject->rulejson as $key => $value) {
                    // If we find the stepsidentifier, we also now the number of the step.
                    if (
                        !empty($stepidentifiers[$key])
                        && (
                            is_object($value)
                            || is_array($value)
                        )
                    ) {
                        $value->unitid = $rule->unitid;
                        $classname = $this->steps[$stepidentifiers[$key]]['formclass'];
                        $this->steps[$stepidentifiers[$key]] =
                            $classname::load_data_for_form($this->steps[$stepidentifiers[$key]], $value);
                    } else {
                        if (!isset($data[$key])) {
                            $this->steps[1][$key] = $value;
                        }
                    }
                }

                // We need to save the data in the cache, so we have also the futher steps saved.
                $cachedata['steps'] = $this->steps;
                $cachestore = new cachestore();
                $cachestore->set_multiform($this->uniqueid, $this->recordid, $cachedata);
            }
        }
    }
}
