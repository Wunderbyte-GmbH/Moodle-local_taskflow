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
    if ($ADMIN->fulltree) {
        $settings->add(
            new admin_setting_heading(
                'local_taskflow_group',
                get_string('taskflowsettings', $componentname),
                get_string('taskflowsettings_desc', $componentname)
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
        $externalapioptions = [];

        foreach (core_plugin_manager::instance()->get_plugins_of_type('taskflowadapter') as $plugin) {
            $component = core_component::get_component_from_classname("taskflowadapter_{$plugin->name}");
            $externalapioptions["{$plugin->name}"] = get_string("{$plugin->name}", $component);
        }
        $settings->add(new admin_setting_configselect(
            $componentname . "/external_api_option",
            get_string('externalapi', $componentname),
            get_string('externalapi_desc', $componentname),
            'standard',
            $externalapioptions
        ));

        // Fetch all roles from the system.
        $roles = role_get_names(null, ROLENAME_ORIGINAL); // Use ROLENAME_ORIGINAL for untranslated names.

        $roleoptions = [];
        foreach ($roles as $role) {
            $roleoptions[$role->id] = $role->localname; // Or use $role->shortname if preferred.
        }

        // Add setting: role selector.
        $settings->add(new admin_setting_configselect(
            $componentname . '/supervisorrole',
            get_string('supervisorrole', 'local_taskflow'),
            get_string('supervisorrole_desc', 'local_taskflow'),
            0, // Default value (no role selected).
            $roleoptions
        ));


        $userprofilefieldsoptions = user_profile_field::get_userprofilefields();
        if (empty(core_plugin_manager::instance()->get_plugins_of_type('taskflowadapter'))) {
            $settings->add(new admin_setting_configselect(
                $componentname . "/supervisor_field",
                get_string('supervisor', $componentname),
                get_string('supervisordesc', $componentname),
                null,
                $userprofilefieldsoptions
            ));
        }
        foreach (core_plugin_manager::instance()->get_plugins_of_type('taskflowadapter') as $plugin) {
            $fullclassname = "\\taskflowadapter_{$plugin->name}\\taskflowadapter_{$plugin->name}";
            $plugin = new $fullclassname();
            $plugin->load_settings($ADMIN, 'local_taskflow_settings', $hassiteconfig);
        }

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
                get_string('inheritancehandling', $componentname),
                get_string('inheritancehandling_desc', $componentname),
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
                get_string('organisationalunits', $componentname),
                get_string('organisationalunits_desc', $componentname)
            )
        );

        $organisationalunitoptions = [
           'unit' => 'Units',
           'cohort' => 'Cohorts',
        ];

        $settings->add(new admin_setting_configselect(
            $componentname . "/organisational_unit_option",
            get_string('organisationalunit', $componentname),
            get_string('organisationalunit_desc', $componentname),
            'unit',
            $organisationalunitoptions
        ));

        $settings->add(
            new admin_setting_heading(
                'local_taskflow_assignment_display_field',
                get_string('assignmentsdisplay', $componentname),
                get_string('assignmentsdisplay_desc', $componentname),
            )
        );

        $settings->add(new admin_setting_configmultiselect(
            $componentname . "/assignment_fields",
            get_string('profilecustomfield', $componentname),
            get_string('profilecustomfielddesc', $componentname),
            [],
            $userprofilefieldsoptions
        ));

        // Shortcode settings.
        $settings->add(
            new admin_setting_heading(
                $componentname . '/shortcodesettingsheading',
                get_string('shortcodesettings', 'local_taskflow'),
                get_string('shortcodesettings_desc', 'local_taskflow')
            )
        );

        $settings->add(new admin_setting_configtext(
            $componentname . '/shortcodespassword',
            get_string('shortcodespassword', 'local_taskflow'),
            get_string('shortcodespassword_desc', 'local_taskflow'),
            '' // Default is empty.
        ));
    }
}
