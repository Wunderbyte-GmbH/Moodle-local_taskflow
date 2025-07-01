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
 * Plugin administration pages are defined here.
 *
 * @package     local_taskflow
 * @category    admin
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\form\filters\types\user_profile_field;

defined('MOODLE_INTERNAL') || die();

$componentname = 'local_taskflow';

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        $componentname . '_settings',
        new lang_string('pluginname', 'local_taskflow')
    );
    $ADMIN->add('localplugins', $settings);
    foreach (core_plugin_manager::instance()->get_plugins_of_type('taskflowadapter') as $plugin) {
        $fullclassname = "\\taskflowadapter_{$plugin->name}\\taskflowadapter_{$plugin->name}";
        $plugin = new $fullclassname();
        $plugin->load_settings($ADMIN, 'local_taskflow_settings', $hassiteconfig);
    }

    if ($ADMIN->fulltree) {
        $settings->add(
            new admin_setting_heading(
                'local_taskflow_group',
                get_string('taskflowsettings', $componentname),
                get_string('taskflowsettings_desc', $componentname)
            )
        );
         $settings->add(
             new admin_setting_description(
                 'local_taksflow_mapptingdescription',
                 get_string('mappingdescription', $componentname),
                 get_string('mappingdescription_desc', $componentname)
             )
         );
        $settings->add(
            new admin_setting_configcheckbox(
                $componentname . '/allowuploadevidence',
                get_string('allowuploadevidence', $componentname),
                get_string('allowuploadevidence_desc', $componentname),
                0
            )
        );
        $settings->add(
            new admin_setting_heading(
                'local_taskflow_includedsteps',
                get_string('includedsteps', $componentname),
                get_string('includedsteps_desc', $componentname),
            )
        );

        $options = [
           'filter' => get_string('filter', $componentname),
           'target' => get_string('target', $componentname),
           'message' => get_string('messages', $componentname),
        ];

        $settings->add(new admin_setting_configmultiselect(
            $componentname . '/includedsteps',
            get_string('includedsteps', $componentname),
            get_string('includedstepssetting_desc', $componentname),
            [],
            $options
        ));

        $settings->add(
            new admin_setting_heading(
                'local_taskflow_settings',
                'Inheritage handeling',
                'handelinghandelinghandeling'
            )
        );

        $inheritanceoptions = [
           'noinheritance' => get_string('settingnoinheritance', $componentname),
           'parentinheritance' => get_string('settingparentinheritance', $componentname),
           'allaboveinheritance' => get_string('settingallaboveinheritance', $componentname),
        ];

        $settings->add(new admin_setting_configselect(
            $componentname . "/inheritance_option",
            get_string('settingruleinheritance', $componentname),
            get_string('settingruleinheritancedescription', $componentname),
            'noinheritance',
            $inheritanceoptions
        ));

        $settings->add(
            new admin_setting_heading(
                'local_taskflow_organisational_unit',
                'Organisational units',
                'Handel the organisational units'
            )
        );

        $organisationalunitoptions = [
           'unit' => 'Units',
           'cohort' => 'Cohorts',
        ];

        $settings->add(new admin_setting_configselect(
            $componentname . "/organisational_unit_option",
            'Organisational unit',
            'Choose organisational unit',
            'unit',
            $organisationalunitoptions
        ));

        $settings->add(
            new admin_setting_heading(
                'local_taskflow_external_api',
                'External Api',
                'Handel the external api'
            )
        );

        $externalapioptions = [
           'user_data' => 'Only user data',
        ];

        foreach (core_plugin_manager::instance()->get_plugins_of_type('taskflowadapter') as $plugin) {
            $component = core_component::get_component_from_classname("taskflowadapter_{$plugin->name}");
            $externalapioptions["{$plugin->name}"] = get_string("{$plugin->name}", $component);
        }

        $settings->add(new admin_setting_configselect(
            $componentname . "/external_api_option",
            'External api with user data',
            'Choose how the external data will be received',
            'user_data',
            $externalapioptions
        ));

        $settings->add(
            new admin_setting_heading(
                'local_taskflow_supervisor_field',
                'Supervisor Field',
                'Set the field for the supervisor'
            )
        );

        $userprofilefieldsoptions = user_profile_field::get_userprofilefields();

        $settings->add(new admin_setting_configselect(
            $componentname . "/supervisor_field",
            get_string('supervisor', $componentname),
            get_string('supervisordesc', $componentname),
            null,
            $userprofilefieldsoptions
        ));

        $settings->add(
            new admin_setting_heading(
                'local_taskflow_assignment_display_field',
                get_string('assignmentsdisplay', $componentname),
                'Set the field for the assignment'
            )
        );

        $settings->add(new admin_setting_configmultiselect(
            $componentname . "/assignment_fields",
            get_string('profilecustomfield', $componentname),
            get_string('profilecustomfielddesc', $componentname),
            [],
            $userprofilefieldsoptions
        ));
    }
}
