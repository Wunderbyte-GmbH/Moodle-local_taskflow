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
 * Taskflowadapter info class.
 *
 * @package   local_taskflow
 * @copyright Wunderbyte GmbH 2025
 * @author    David Ala
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_taskflow\plugininfo;
use admin_setting_configtext;
use admin_setting_description;
use core\plugininfo\base;
use stdClass;

/**
 * Models taskflowadapter define classes.
 */
class taskflowadapter extends base {
    // Target group constants.
    /**
     * TRANSLATOR_TARGET_GROUP_NAME
     *
     * @var string
     */
    public const TRANSLATOR_TARGET_GROUP_NAME = 'translator_target_group_name';
    /**
     * TRANSLATOR_TARGET_GROUP_DESCRIPTION
     *
     * @var string
     */
    public const TRANSLATOR_TARGET_GROUP_DESCRIPTION = 'translator_target_group_description';
    /**
     * TRANSLATOR_TARGET_GROUP_UNITID
     *
     * @var string
     */
    public const TRANSLATOR_TARGET_GROUP_UNITID = 'translator_target_group_unitid';
    /**
     * TRANSLATOR_TARGET_GROUP_PARENT
     *
     * @var string
     */
    public const TRANSLATOR_TARGET_GROUP_PARENT = 'translator_target_group_parent';

    /**
     * TRANSLATOR_USER_UNITS
     *
     * @var string
     */
    public const TRANSLATOR_USER_UNITS = 'translator_user_units';
    /**
     * TRANSLATOR_USER_ORGUNIT
     *
     * @var string
     */
    public const TRANSLATOR_USER_ORGUNIT = 'translator_user_orgunit';
    /**
     * TRANSLATOR_USER_SUPERVISOR
     *
     * @var string
     */
    public const TRANSLATOR_USER_SUPERVISOR = 'translator_user_supervisor';
    /**
     * TRANSLATOR_USER_LONG_LEAVE
     *
     * @var string
     */
    public const TRANSLATOR_USER_LONG_LEAVE = 'translator_user_long_leave';
    /**
     * TRANSLATOR_USER_END
     *
     * @var string
     */
    public const TRANSLATOR_USER_END = 'translator_user_end';
    /**
     * TRANSLATOR_USER_INTERNALID
     *
     * @var string
     */
    public const TRANSLATOR_USER_EXTERNALID = 'translator_user_externalid';

    /**
     * Returns the information about plugin availability
     *
     * True means that the plugin is enabled. False means that the plugin is
     * disabled. Null means that the information is not available, or the
     * plugin does not support configurable availability or the availability
     * can not be changed.
     *
     * @return null|bool
     */
    public function is_enabled() {
        return true;
    }

    /**
     * Should there be a way to uninstall the plugin via the administration UI.
     *
     * By default uninstallation is not allowed, plugin developers must enable it explicitly!
     *
     * @return bool
     */
    public function is_uninstall_allowed() {
        return true;
    }

    /**
     * Pre-uninstall hook.
     */
    public function uninstall_cleanup() {
        parent::uninstall_cleanup();
    }

    /**
     * Returns the target label settings for subplugins.
     *
     * @return array
     */
    protected function return_target_label_settings() {
        return [
            self::TRANSLATOR_TARGET_GROUP_NAME => get_string('name', 'local_taskflow'),
            self::TRANSLATOR_TARGET_GROUP_DESCRIPTION => get_string('description', 'local_taskflow'),
            self::TRANSLATOR_TARGET_GROUP_UNITID => get_string('unit', 'local_taskflow'),
        ];
    }

    /**
     * Returns the user label settings for subplugins.
     *
     * @return array
     */
    protected function return_user_label_settings() {
        return [
            // Empty as standard.
            "" => get_string('nofunction', 'local_taskflow'),
            self::TRANSLATOR_USER_UNITS => get_string('targetgroup', 'local_taskflow'),
            self::TRANSLATOR_USER_ORGUNIT => get_string('unit', 'local_taskflow'),
            self::TRANSLATOR_USER_SUPERVISOR => get_string('supervisor', 'local_taskflow'),
            self::TRANSLATOR_USER_LONG_LEAVE => get_string('longleave', 'local_taskflow'),
            self::TRANSLATOR_USER_END => get_string('contractend', 'local_taskflow'),
            self::TRANSLATOR_USER_EXTERNALID => get_string('externalid', 'local_taskflow'),
        ];
    }
    /**
     * Checks number of functions used and displays error message in the settings.
     *
     * @param array $usercustomfields
     * @param string $componentname
     * @param object $settings
     *
     * @return void
     *
     */
    protected function check_functions_usage(array $usercustomfields, string $componentname, object $settings) {
        $validation = 1;
        $userlabelsettings = $this->return_user_label_settings();
        foreach ($usercustomfields as $key => $label) {
            if (!empty(get_config($componentname, $key))) {
                $validation++;
            }
        }
        if ($validation < count($userlabelsettings)) {
            $settings->add(
                new admin_setting_description(
                    $componentname . '/lessfunctions',
                    '',
                    get_string('lessfunctions', $componentname)
                )
            );
        }
        if ($validation > count($userlabelsettings)) {
            $settings->add(
                new admin_setting_description(
                    $componentname . '/manyfunctions',
                    '',
                    get_string('manyfunctions', $componentname)
                )
            );
        }
    }
    /**
     * Returns desciption of the mapping.
     *
     * @param object $settings
     * @param string $componentname
     *
     * @return void
     *
     */
    protected function return_setting_mappingdescription(object $settings, string $componentname) {
    }
    /**
     * Firstname, Lastname, E-Mail and mapping description get a special treatment since they are always needed.
     *
     * @param object $settings
     * @param string $component
     *
     * @return void
     *
     */
    protected function return_setting_special_treatment_fields(object $settings, string $component) {

        $settings->add(
            new admin_setting_description(
                $component . '/' . 'mappingdescription',
                get_string('mappingdescription', $component),
                get_string('mappingdescription_desc', $component)
            )
        );
        $settings->add(
            new admin_setting_configtext(
                $component . '/' . 'translator_user_firstname',
                get_string('jsonkey', 'local_taskflow') . get_string('firstname', 'local_taskflow'),
                get_string('enter_value', 'local_taskflow'),
                '',
                PARAM_TEXT
            )
        );
        $settings->add(
            new admin_setting_configtext(
                $component . '/' . 'translator_user_lastname',
                get_string('jsonkey', 'local_taskflow') . get_string('lastname', 'local_taskflow'),
                get_string('enter_value', 'local_taskflow'),
                '',
                PARAM_TEXT
            )
        );
        $settings->add(
            new admin_setting_configtext(
                $component . '/' . 'translator_user_email',
                get_string('jsonkey', 'local_taskflow') . get_string('email', 'local_taskflow'),
                get_string('enter_value', 'local_taskflow'),
                '',
                PARAM_TEXT
            )
        );
    }
    /**
     * Get the instance of the class for a specific ID.
     * @param int $userid
     * @return stdClass
     */
    public static function get_supervisor_for_user(int $userid) {
        global $DB;

        $fieldname = get_config('local_taskflow', 'supervisor_field');
        if (empty($fieldname)) {
            return (object)[];
        }

        $sql = "SELECT su.*
                FROM {user} u
                JOIN {user_info_data} uid ON uid.userid = u.id
                JOIN {user_info_field} uif ON uif.id = uid.fieldid
                JOIN {user} su ON su.id = CAST(uid.data AS INT)
                WHERE u.id = :userid
                AND uif.shortname = :supervisor";
        $parms = [
            'userid' => $userid,
            'supervisor' => $fieldname,
        ];
        return $DB->get_record_sql($sql, $parms, IGNORE_MISSING);
    }
}
