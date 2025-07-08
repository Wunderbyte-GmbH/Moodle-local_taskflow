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
 * Module booking data generator
 *
 * @package local_taskflow
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\booking;
use local_taskflow\booking_rules\booking_rules;
use local_taskflow\booking_rules\rules_info;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\output\view;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\table\bookingoptions_wbtable;
use local_taskflow\booking_option;
use local_taskflow\booking_campaigns\campaigns_info;
use local_taskflow\singleton_service;
use local_taskflow\semester;
use local_taskflow\bo_availability\bo_info;
use local_taskflow\price as local_taskflowPrice;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use local_taskflow\bo_actions\actions_info;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Class to handle module booking data generator
 *
 * @package local_taskflow
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2023 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_taskflow_generator extends testing_module_generator {
    // phpcs:disable
    /**
     * To be called from data reset code only, do not use in tests.
     *
     * @return void
     */
    public function reset() {
        parent::reset();
    }
    // phpcs:enable

    /**
     * Creates a standard assignemnt for a user.
     * @param int $userid
     * @param int $ruleid
     *
     * @return \local_taskflow\local\assignments\assignment
     *
     */
    public function create_user_assignment(int $userid, int $ruleid) {

        $data = [
            'userid' => $userid,
            'ruleid' => $ruleid,
            'unitid' => 1,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
        ];

        $assignment = new assignment();
        $result = $assignment->add_or_update_assignment($data);

        return $assignment;
    }

    /**
     * Creates more or less empty rule.
     * @param array $options
     *
     * @return int
     *
     */
    public function create_rule(array $options = []) {

        global $DB;

        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'rulename' => 'Test Rule',
            'rulejson' => '{}',
        ]);

        return $ruleid;
    }

    /**
     * Creates custom user profile fields in Moodle using the provided shortnames.
     *
     * @param array $shortnames Array of strings to use as shortnames for custom fields.
     * @return array Array of created field IDs indexed by shortname.
     * @throws moodle_exception If a field could not be created.
     */
    public function create_custom_profile_fields(array $shortnames): array {
        global $DB, $CFG;

        $createdfields = [];

        foreach ($shortnames as $shortname) {
            // Skip if field with this shortname already exists.
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                continue;
            }

            // Define the field data.
            $data = (object)[
                'shortname' => $shortname,
                'name' => ucfirst($shortname),
                'datatype' => 'text',
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'categoryid' => 1, // Default category (you might want to ensure this exists or create your own).
                'sortorder' => 0,
                'required' => 0,
                'locked' => 0,
                'visible' => 1,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_HTML,
                'param1' => 30, // Text field max length.
            ];

            // Create the field.
            require_once($CFG->dirroot . '/user/profile/definelib.php');
            $handler = new profile_define_base();

            $handler->define_save($data);

            // Get the ID of the created field.
            $record = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id', MUST_EXIST);
            $createdfields[$shortname] = $record->id;
        }

        return $createdfields;
    }

    /**
     * Set config values
     * @param string $type
     * @param array $override
     * @param array $overridesubplugin
     *
     * @return void
     *
     */
    public function set_config_values(
        string $type = 'standard',
        array $override = [],
        array $overridesubplugin = []
    ): void {

        // First, we set the general taskflow settings.
        $taskflowsettings = [
            'organisational_unit_option' => 'cohort',
            'supervisor_field' => 'supervisor',
            'external_api_option' => $type,
        ];

        // Now, set the settings for the specific type.
        switch ($type) {
            case 'tuines':
                $taskflowadaptersettings = [
                    taskflowadapter::TRANSLATOR_USER_FIRSTNAME => "firstName",
                    taskflowadapter::TRANSLATOR_USER_LASTNAME => "lastName",
                    taskflowadapter::TRANSLATOR_USER_EMAIL => "eMailAddress",
                    taskflowadapter::TRANSLATOR_USER_TARGETGROUP => "targetGroup",
                    "units" => taskflowadapter::TRANSLATOR_USER_TARGETGROUP,
                    taskflowadapter::TRANSLATOR_USER_ORGUNIT => "orgUnit",
                    "organisation" => taskflowadapter::TRANSLATOR_USER_ORGUNIT,
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR => "directSupervisor",
                    "supervisor" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
                    taskflowadapter::TRANSLATOR_USER_LONG_LEAVE => "currentlyOnLongLeave",
                    "longleave" => taskflowadapter::TRANSLATOR_USER_LONG_LEAVE,
                    taskflowadapter::TRANSLATOR_USER_EXTERNALID => "tissId",
                    "externalid" => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
                    taskflowadapter::TRANSLATOR_TARGET_GROUP_NAME => "displayNameDE",
                    taskflowadapter::TRANSLATOR_TARGET_GROUP_DESCRIPTION => "descriptionDE",
                    taskflowadapter::TRANSLATOR_TARGET_GROUP_UNITID => "number",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTEND => "contractEnd",
                    "contractend" => taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
                    'organisational_unit_option' => 'cohort',
                    'user_profile_option' => 'tuines',
                    'supervisor_field' => 'supervisor',
                ];
                break;
            case 'ksw':
                                $taskflowadaptersettings = [
                    taskflowadapter::TRANSLATOR_USER_FIRSTNAME => "Firstname",
                    taskflowadapter::TRANSLATOR_USER_LASTNAME => "LastName",
                    taskflowadapter::TRANSLATOR_USER_EMAIL => "DefaultEmailAddress",
                    taskflowadapter::TRANSLATOR_USER_ORGUNIT => "Organisation",
                    "orgunit" => taskflowadapter::TRANSLATOR_USER_ORGUNIT,
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR => "Manager_Email",
                    "supervisor" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
                    "externalid" => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
                    taskflowadapter::TRANSLATOR_USER_CONTRACTEND => "ExitDate",
                    "contractend" => taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
                    'organisational_unit_option' => 'cohort',
                    'user_profile_option' => 'thour',
                    'supervisor_field' => 'supervisor',
                                ];
                break;
            case 'standard':
            default:
                $taskflowadaptersettings = [
                    "translator_user_firstname" => "name->firstname",
                    "translator_user_lastname" => "name->lastname",
                    "translator_user_email" => "mail",
                    "translator_user_units" => "ou",
                    "units" => "translator_user_units",
                    "supervisor" => "translator_user_supervisor",
                    "translator_target_group_name" => "unit",
                    "translator_target_group_description" => "unit",
                    "translator_target_group_unitid" => "id",
                    "translator_target_group_parent" => "parent",
                ];
        }
        foreach ($taskflowsettings as $key => $value) {
            $value = $override[$key] ?? $value;
            set_config($key, $value, 'local_taskflow');
        }
        foreach ($taskflowadaptersettings as $key => $value) {
            $value = $overridesubplugin[$key] ?? $value;
            set_config($key, $value, 'taskflowadapter_' . $type);
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }
}
