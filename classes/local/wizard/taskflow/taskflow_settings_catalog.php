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

namespace local_taskflow\local\wizard\taskflow;

use core_component;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Static catalog of every local_taskflow admin setting and the known adapter settings.
 *
 * Derived from local/taskflow/settings.php and taskflowadapter/<x>/classes/taskflowadapter_<x>.php
 * load_settings(). Entries: name, component, labelkey, desckey, type, default (+ options for
 * selects). Dynamic per-profile-field adapter settings (translator_user_<field> / <field>) are
 * described by adapter_dynamic_entries(). Values are read live via current_value().
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_settings_catalog {
    /** Setting type constants (mirroring the admin_setting classes used). */
    public const TYPE_SELECT = 'select';
    /** Free text setting. */
    public const TYPE_TEXT = 'text';
    /** Checkbox setting. */
    public const TYPE_CHECKBOX = 'checkbox';
    /** Multi select setting (stored comma separated). */
    public const TYPE_MULTISELECT = 'multiselect';

    /**
     * Core local_taskflow settings in the order of settings.php.
     *
     * @var array<int,array<string,mixed>>
     */
    private const CORE = [
        ['name' => 'external_api_option', 'labelkey' => 'externalapi', 'desckey' => 'externalapi_desc',
            'type' => self::TYPE_SELECT, 'default' => 'standard', 'options' => 'taskflowadapter'],
        ['name' => 'supervisorrole', 'labelkey' => 'supervisorrole', 'desckey' => 'supervisorrole_desc',
            'type' => self::TYPE_SELECT, 'default' => 0, 'options' => 'role'],
        ['name' => 'hrusers', 'labelkey' => 'hrusers', 'desckey' => 'hrusers_desc',
            'type' => self::TYPE_TEXT, 'default' => 0],
        ['name' => 'cohortenrollment', 'labelkey' => 'cohortenrollment', 'desckey' => 'cohortenrollment_desc',
            'type' => self::TYPE_CHECKBOX, 'default' => 1],
        ['name' => 'defaultauth', 'labelkey' => 'defaultauth', 'desckey' => 'defaultauthdesc',
            'type' => self::TYPE_SELECT, 'default' => 'manual', 'options' => 'auth'],
        ['name' => 'allowuploadevidence', 'labelkey' => 'allowuploadevidence', 'desckey' => 'allowuploadevidence_desc',
            'type' => self::TYPE_CHECKBOX, 'default' => 0],
        ['name' => 'allowselfextension', 'labelkey' => 'allowselfextension', 'desckey' => 'allowselfextension_desc',
            'type' => self::TYPE_CHECKBOX, 'default' => 0],
        ['name' => 'allowselfnotrelevant', 'labelkey' => 'allowselfnotrelevant', 'desckey' => 'allowselfnotrelevant_desc',
            'type' => self::TYPE_CHECKBOX, 'default' => 0],
        ['name' => 'supervisor_field', 'labelkey' => 'supervisor', 'desckey' => 'supervisordesc',
            'type' => self::TYPE_SELECT, 'default' => null, 'options' => 'userprofilefield'],
        ['name' => 'includedsteps', 'labelkey' => 'includedsteps', 'desckey' => 'includedstepssetting_desc',
            'type' => self::TYPE_MULTISELECT, 'default' => [],
            'options' => ['filter', 'target', 'message', 'requests']],
        ['name' => 'inheritance_option', 'labelkey' => 'settingruleinheritance',
            'desckey' => 'settingruleinheritancedescription', 'type' => self::TYPE_SELECT, 'default' => 'noinheritance',
            'options' => ['noinheritance', 'parentinheritance', 'allaboveinheritance']],
        ['name' => 'organisational_unit_option', 'labelkey' => 'organisationalunit',
            'desckey' => 'organisationalunit_desc', 'type' => self::TYPE_SELECT, 'default' => 'unit',
            'options' => ['unit', 'cohort']],
        ['name' => 'assignment_fields', 'labelkey' => 'profilecustomfield', 'desckey' => 'profilecustomfielddesc',
            'type' => self::TYPE_MULTISELECT, 'default' => [], 'options' => 'userprofilefield'],
        ['name' => 'showassignmentslist', 'labelkey' => 'showassignmentslist', 'desckey' => 'showassignmentslist_desc',
            'type' => self::TYPE_CHECKBOX, 'default' => 0],
        ['name' => 'shortcodespassword', 'labelkey' => 'shortcodespassword', 'desckey' => 'shortcodespassword_desc',
            'type' => self::TYPE_TEXT, 'default' => ''],
        ['name' => 'sendmailstodeputy', 'labelkey' => 'sendmailstodeputy', 'desckey' => 'sendmailstodeputy_desc',
            'type' => self::TYPE_CHECKBOX, 'default' => 0],
        ['name' => 'sendmanualmailsmultipletimes', 'labelkey' => 'sendmanualmailsmultipletimes',
            'desckey' => 'sendmanualmailsmultipletimes_desc', 'type' => self::TYPE_CHECKBOX, 'default' => 0],
        ['name' => 'allowoverduecompletion', 'labelkey' => 'allowoverduecompletion',
            'desckey' => 'allowoverduecompletion_desc', 'type' => self::TYPE_CHECKBOX, 'default' => 1],
        ['name' => 'allowinternalcommunication', 'labelkey' => 'allowinternalcommunication',
            'desckey' => 'allowinternalcommunication_desc', 'type' => self::TYPE_CHECKBOX, 'default' => 1,
            'condition' => 'adapter_internal_communication_form'],
        ['name' => 'internalcommunicationpreviewlength', 'labelkey' => 'internalcommunicationpreviewlength',
            'desckey' => 'internalcommunicationpreviewlength_desc', 'type' => self::TYPE_SELECT, 'default' => 300,
            'options' => [0, 100, 150, 175, 200, 300, 400, 500, 600],
            'condition' => 'adapter_internal_communication_form'],
    ];

    /**
     * Static adapter settings per adapter (beyond the dynamic profile-field mappings).
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private const ADAPTER = [
        'standard' => [
            ['name' => 'necessaryuserprofilefields', 'labelkey' => 'necessaryuserprofilefields',
                'desckey' => 'necessaryuserprofilefieldsdesc', 'type' => self::TYPE_MULTISELECT, 'default' => [],
                'options' => 'userprofilefield'],
            ['name' => 'blscertificatekey', 'labelkey' => 'blscertificatekey', 'desckey' => 'blscertificatekey_desc',
                'type' => self::TYPE_TEXT, 'default' => ''],
        ],
        'tuines' => [
            ['name' => 'necessaryuserprofilefields', 'labelkey' => 'necessaryuserprofilefields',
                'desckey' => 'necessaryuserprofilefieldsdesc', 'type' => self::TYPE_MULTISELECT, 'default' => [],
                'options' => 'userprofilefield'],
            ['name' => 'usingprolongedstate', 'labelkey' => 'usingprolongedstate', 'desckey' => 'usingprolongedstate_desc',
                'type' => self::TYPE_CHECKBOX, 'default' => 0],
            ['name' => 'excludestatus', 'labelkey' => 'excludestatus', 'desckey' => 'excludestatus_desc',
                'type' => self::TYPE_MULTISELECT, 'default' => [], 'options' => 'assignmentstatus'],
            ['name' => 'dwhurl', 'labelkey' => 'dwhurl', 'desckey' => 'dwhurl_desc',
                'type' => self::TYPE_TEXT, 'default' => ''],
        ],
        'ksw' => [
            ['name' => 'necessaryuserprofilefields', 'labelkey' => 'necessaryuserprofilefields',
                'desckey' => 'necessaryuserprofilefieldsdesc', 'type' => self::TYPE_MULTISELECT, 'default' => [],
                'options' => 'userprofilefield'],
            ['name' => 'protectedcohorts', 'labelkey' => 'protectedcohorts', 'desckey' => 'protectedcohorts_desc',
                'type' => self::TYPE_MULTISELECT, 'default' => [], 'options' => 'cohort'],
            ['name' => 'blscertificatekey', 'labelkey' => 'blscertificatekey', 'desckey' => 'blscertificatekey_desc',
                'type' => self::TYPE_TEXT, 'default' => ''],
        ],
    ];

    /**
     * Fixed user mapping settings every adapter declares (return_setting_special_treatment_fields).
     *
     * @var string[]
     */
    private const ADAPTER_FIXED_USER_MAPPINGS = [
        taskflowadapter::TRANSLATOR_USER_FIRSTNAME,
        taskflowadapter::TRANSLATOR_USER_LASTNAME,
        taskflowadapter::TRANSLATOR_USER_EMAIL,
    ];

    /**
     * Target group mapping settings every adapter declares (return_target_label_settings).
     *
     * @var string[]
     */
    private const ADAPTER_TARGET_MAPPINGS = [
        taskflowadapter::TRANSLATOR_TARGET_GROUP_NAME,
        taskflowadapter::TRANSLATOR_TARGET_GROUP_DESCRIPTION,
        taskflowadapter::TRANSLATOR_TARGET_GROUP_UNITID,
    ];

    /**
     * All core settings with component 'local_taskflow'.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function core(): array {
        $entries = [];
        foreach (self::CORE as $entry) {
            $entry['component'] = 'local_taskflow';
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Installed adapter names (taskflowadapter subplugins).
     *
     * @return string[]
     */
    public static function installed_adapters(): array {
        return array_keys(core_component::get_plugin_list('taskflowadapter'));
    }

    /**
     * Name of the active adapter (external_api_option), 'standard' when unset.
     *
     * @return string
     */
    public static function active_adapter(): string {
        $adapter = trim((string)get_config('local_taskflow', 'external_api_option'));
        return $adapter === '' ? 'standard' : $adapter;
    }

    /**
     * Static settings of one adapter with component 'taskflowadapter_<adapter>'.
     *
     * Unknown adapters yield the common mapping settings only.
     *
     * @param string $adapter
     * @return array<int,array<string,mixed>>
     */
    public static function adapter(string $adapter): array {
        $component = 'taskflowadapter_' . $adapter;
        $entries = [];
        foreach (self::ADAPTER_FIXED_USER_MAPPINGS as $name) {
            $entries[] = ['name' => $name, 'component' => $component, 'labelkey' => 'jsonkey',
                'desckey' => 'enter_value', 'type' => self::TYPE_TEXT, 'default' => '', 'group' => 'usermapping'];
        }
        foreach (self::ADAPTER_TARGET_MAPPINGS as $name) {
            $entries[] = ['name' => $name, 'component' => $component, 'labelkey' => 'jsonkey',
                'desckey' => 'enter_value', 'type' => self::TYPE_TEXT, 'default' => '', 'group' => 'targetmapping'];
        }
        foreach (self::ADAPTER[$adapter] ?? [] as $entry) {
            $entry['component'] = $component;
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Dynamic per-profile-field adapter settings: translator_user_<field> (JSON key) and
     * <field> (function mapping, see taskflowadapter::TRANSLATOR_USER_* constants).
     *
     * @param string $adapter
     * @return array<int,array<string,mixed>>
     */
    public static function adapter_dynamic_entries(string $adapter): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $component = 'taskflowadapter_' . $adapter;
        $entries = [];
        foreach (profile_get_custom_fields() as $field) {
            $shortname = (string)$field->shortname;
            $entries[] = ['name' => 'translator_user_' . $shortname, 'component' => $component, 'labelkey' => 'jsonkey',
                'desckey' => 'enter_value', 'type' => self::TYPE_TEXT, 'default' => '', 'group' => 'usermapping',
                'profilefield' => $shortname];
            $entries[] = ['name' => $shortname, 'component' => $component, 'labelkey' => 'function',
                'desckey' => 'set:function', 'type' => self::TYPE_SELECT, 'default' => '', 'group' => 'userfunction',
                'profilefield' => $shortname, 'options' => 'userfunction'];
        }
        return $entries;
    }

    /**
     * Every catalogued setting: core plus static + dynamic settings of the given adapters.
     *
     * @param string[]|null $adapters Null = active adapter only; [] = core only.
     * @return array<int,array<string,mixed>>
     */
    public static function all(?array $adapters = null): array {
        if ($adapters === null) {
            $adapters = [self::active_adapter()];
        }
        $entries = self::core();
        foreach ($adapters as $adapter) {
            $adapter = trim((string)$adapter);
            if ($adapter === '') {
                continue;
            }
            $entries = array_merge($entries, self::adapter($adapter), self::adapter_dynamic_entries($adapter));
        }
        return $entries;
    }

    /**
     * Live value of a catalog entry (false when never set; arrays for multiselects).
     *
     * @param array $entry
     * @return mixed
     */
    public static function current_value(array $entry) {
        $value = get_config((string)$entry['component'], (string)$entry['name']);
        if ($value === false) {
            return $entry['default'] ?? null;
        }
        if (($entry['type'] ?? '') === self::TYPE_MULTISELECT) {
            return array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
        }
        if (($entry['type'] ?? '') === self::TYPE_CHECKBOX) {
            return (bool)$value;
        }
        return $value;
    }

    /**
     * Localized label of an entry (adapter strings first, then local_taskflow).
     *
     * @param array $entry
     * @param string $lang Empty = current language.
     * @return string
     */
    public static function label(array $entry, string $lang = ''): string {
        return self::resolve_string((string)($entry['labelkey'] ?? ''), (string)($entry['component'] ?? ''), $lang)
            . (isset($entry['profilefield']) ? ' ' . $entry['profilefield'] : '');
    }

    /**
     * Localized description of an entry.
     *
     * @param array $entry
     * @param string $lang Empty = current language.
     * @return string
     */
    public static function description(array $entry, string $lang = ''): string {
        return self::resolve_string((string)($entry['desckey'] ?? ''), (string)($entry['component'] ?? ''), $lang);
    }

    /**
     * Resolve a string key against the entry component, the active adapter and local_taskflow.
     *
     * @param string $key
     * @param string $component
     * @param string $lang
     * @return string Empty when the key exists nowhere.
     */
    private static function resolve_string(string $key, string $component, string $lang = ''): string {
        if ($key === '') {
            return '';
        }
        $manager = get_string_manager();
        $lang = trim($lang) === '' ? null : trim($lang);
        $candidates = array_unique(array_filter([
            $component,
            'taskflowadapter_' . self::active_adapter(),
            'local_taskflow',
        ]));
        foreach ($candidates as $candidate) {
            if ($manager->string_exists($key, $candidate)) {
                return $manager->get_string($key, $candidate, null, $lang);
            }
        }
        return '';
    }
}
