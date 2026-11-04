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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\completion_process\types;

use local_taskflow\local\rules\rules;
use mod_booking\booking_option;
use mod_booking\singleton_service;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competency extends types_base implements types_interface {
    /**
     * Checks whether the user holds this competency: through a completed booking option that carries it
     * (within the cyclic duration for cyclic rules) or through an approved certificate that is still valid.
     * @param object $affectedassignment
     * @return bool
     */
    public function is_completed($affectedassignment) {
        $answers = $this->get_last_completions();
        $cyclicduration = $this->get_cyclic_duration($affectedassignment);

        // Booking options.
        if ($cyclicduration !== null) {
            foreach ($answers as $lastcompletion) {
                if ($lastcompletion->timemodified > (time() - $cyclicduration)) {
                    return true;
                }
            }
        } else if (!empty($answers)) {
            return true;
        }

        // Assignment competency.
        foreach ($this->get_approved_certificates() as $assignmentcompetency) {
            if (
                empty($assignmentcompetency->validationondate) ||
                $assignmentcompetency->validationondate > time()
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The moment the user's completion of this competency expires.
     * A booking completion expires one cyclic duration after the completion, a certificate on its validation date.
     * Null when no completion is known or when a completion never expires (non-cyclic rule, undated certificate).
     * A value in the past is returned as such: it tells when the completion ran out.
     * @param object $affectedassignment
     * @return int|null
     */
    public function get_valid_until($affectedassignment): ?int {
        $cyclicduration = $this->get_cyclic_duration($affectedassignment);
        $expiries = [];
        foreach ($this->get_last_completions() as $lastcompletion) {
            if ($cyclicduration === null) {
                return null;
            }
            $expiries[] = (int)$lastcompletion->timemodified + $cyclicduration;
        }
        foreach ($this->get_approved_certificates() as $assignmentcompetency) {
            if (empty($assignmentcompetency->validationondate)) {
                return null;
            }
            $expiries[] = (int)$assignmentcompetency->validationondate;
        }
        if (empty($expiries)) {
            return null;
        }
        return max($expiries);
    }

    /**
     * The last completion of the user per booking option that carries this competency.
     * @return array
     */
    private function get_last_completions(): array {
        global $DB;
        $answers = [];
        $records = $DB->get_records_select(
            'booking_options',
            $DB->sql_like($DB->sql_concat("','", 'competencies', "','"), ':needle'),
            ['needle' => '%,' . $this->targetid . ',%']
        );

        if (
            CLI_SCRIPT
            && !PHPUNIT_TEST
        ) {
            mtrace("Found the following booking options for competencyid {$this->targetid}: " . json_encode(array_keys($records)));
        }

        foreach ($records as $record) {
            $settings = singleton_service::get_instance_of_booking_option_settings($record->id);
            if (!isset($settings->bookingid)) {
                continue;
            }
            booking_option::purge_cache_for_answers($settings->id);
            $ba = singleton_service::get_instance_of_booking_answers($settings);

            if (method_exists('\mod_booking\booking_answers\booking_answers', 'return_last_completion')) {
                $lastcompletion = $ba->return_last_completion($this->userid);
                if (!empty(get_object_vars($lastcompletion))) {
                    $answers[$settings->id] = $lastcompletion;
                }
            }
        }

        if (
            CLI_SCRIPT
            && !PHPUNIT_TEST
        ) {
            mtrace("Found last completion for assignmentid " . json_encode($answers));
        }
        return $answers;
    }

    /**
     * The approved certificates of the user for this competency.
     * @return array
     */
    private function get_approved_certificates(): array {
        global $DB;
        return $DB->get_records(
            'local_taskflow_assgin_comp',
            [
                'competencyid' => $this->targetid,
                'userid' => $this->userid,
                'status' => 'approved',
            ]
        );
    }

    /**
     * The cyclic duration of the assignment's rule in seconds, null when the rule is not cyclic.
     * @param object $affectedassignment
     * @return int|null
     */
    private function get_cyclic_duration($affectedassignment): ?int {
        $rule = rules::instance($affectedassignment->ruleid);
        if (!is_object($rule)) {
            return null;
        }
        $rulejson = json_decode($rule->get_rulesjson() ?? '');
        if (
            isset($rulejson->rulejson->rule->cyclicvalidation) &&
            $rulejson->rulejson->rule->cyclicvalidation == '1' &&
            !empty($rulejson->rulejson->rule->cyclicduration)
        ) {
            return (int)$rulejson->rulejson->rule->cyclicduration;
        }
        return null;
    }
}
