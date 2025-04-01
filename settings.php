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

defined('MOODLE_INTERNAL') || die();

$componentname = 'local_taskflow';

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        $componentname . '_settings',
        new lang_string('pluginname', 'local_taskflow')
    );
    $ADMIN->add('localplugins', new admin_category($componentname, get_string('pluginname', $componentname)));
    $ADMIN->add('localplugins', $settings);


    if ($ADMIN->fulltree) {
        $settings->add(
            new admin_setting_heading(
                'local_taskflow_group',
                get_string('taskflowsettings', $componentname),
                get_string('taskflowsettings_desc', $componentname)
            )
        );

        $labelsettings = [
            'translator_first_name' => get_string('first_name', $componentname),
            'translator_second_name' => get_string('second_name', $componentname),
            'translator_email' => get_string('email', $componentname),
            'translator_units' => get_string('unit', $componentname),
        ];

        foreach ($labelsettings as $key => $label) {
            $settings->add(
                new admin_setting_configtext(
                    $componentname . '/' . $key,
                    $label,
                    get_string('enter_value', $componentname),
                    '',
                    PARAM_TEXT
                )
            );
        }

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

        $settings->add(new admin_setting_configselect(
            $componentname . "/external_api_option",
            'External api with user data',
            'Choose how the external data will be received',
            'user_data',
            $externalapioptions
        ));
    }
}
