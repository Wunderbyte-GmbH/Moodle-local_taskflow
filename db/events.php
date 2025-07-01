<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin event observers are registered here.
 *
 * @package     local_taskflow
 * @category    event
 * @copyright   2021 Wunderbyte GmbH<info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\local_taskflow\event\user_externally_updated',
        'callback' => '\local_taskflow\observer::call_event_handler',
    ],
    [
        'eventname' => '\local_taskflow\event\unit_relation_updated',
        'callback' => '\local_taskflow\observer::call_event_handler',
    ],
    [
        'eventname'   => '\core\event\user_created',
        'callback'    => '\local_taskflow\observer::core_user_created_updated',
    ],
    [
        'eventname'   => '\core\event\user_updated',
        'callback'    => '\local_taskflow\observer::core_user_created_updated',
    ],
    [
        'eventname' => '\local_taskflow\event\rule_created_updated',
        'callback' => '\local_taskflow\observer::call_event_handler',
    ],
    [
        'eventname' => '\core\event\cohort_member_added',
        'callback' => '\local_taskflow\observer::cohort_member_added',
    ],
    [
        'eventname' => '\core\event\cohort_member_removed',
        'callback' => '\local_taskflow\observer::cohort_member_removed',
    ],
    [
        'eventname' => '\core\event\cohort_deleted',
        'callback' => '\local_taskflow\observer::cohort_removed',
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\local_taskflow\observer::course_completed',
    ],
    [
        'eventname' => '\local_taskflow\event\assignment_completed',
        'callback' => '\local_taskflow\observer::call_event_handler',
    ],
    [
        'eventname' => '\local_taskflow\event\assignment_status_changed',
        'callback' => '\local_taskflow\observer::call_event_handler',
    ],
    [
        'eventname' => '\core\event\competency_user_competency_rated',
        'callback' => '\local_taskflow\observer::competency_completed',
    ],
    [
        'eventname' => '\mod_booking\event\bookingoption_booked',
        'callback' => '\local_taskflow\observer::bookingoption_booked',
    ],
 ];
