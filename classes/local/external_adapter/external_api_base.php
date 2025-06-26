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

namespace local_taskflow\local\external_adapter;

use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_relation_updated;
use local_taskflow\local\personas\unit_members\unit_member_repository_interface;
use local_taskflow\local\personas\moodle_users\user_repository_interface;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\plugininfo\taskflowadapter;
use XHProfRuns_Default;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class external_api_base extends external_api_error_logger {
    /** @var string|null Stores the external user data. */
    protected stdClass $externaldata;

    /** @var user_repository_interface Stores the external user data. */
    protected user_repository_interface $userrepo;

    /** @var unit_member_repository_interface Stores the external user data. */
    protected unit_member_repository_interface $unitmemberrepo;

    /** @var organisational_unit_factory Stores the external user data. */
    protected organisational_unit_factory $unitrepo;

    /** @var array Stores the external user data. */
    protected array $unitmapping;

    /**
     * [Description for $fullmap]
     *
     * @var array
     */
    protected array $fullmap;

    /**
     * Array of Userobjects with profilefields.
     *
     * @var array
     */
    protected array $users;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $data
     * @param user_repository_interface $userrepo
     * @param unit_member_repository_interface $unitmemberrepo
     * @param organisational_unit_factory|null $unitrepo
     */
    public function __construct(
        string $data,
        user_repository_interface $userrepo,
        unit_member_repository_interface $unitmemberrepo,
        ?organisational_unit_factory $unitrepo = null
    ) {
        $this->externaldata = (object) json_decode($data);
        $this->userrepo = $userrepo;
        $this->unitmemberrepo = $unitmemberrepo;
        $this->unitrepo = $unitrepo;
        $this->unitmapping = [];
    }
    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $incominguserdata
     * @return array
     */
    protected function translate_incoming_data($incominguserdata) {
        $prefix = 'translator_user_';
        $this->fullmap = $this->local_taskflow_get_label_settings($prefix);
        $user = [];
        foreach ($this->fullmap as $label => $value) {
            // For the special treatment fields.
            $internallabel = str_replace('translator_user_', '', $label);
            if (empty($value)) {
                $value = $internallabel;
            }
            $externalpath = explode('->', $value);
            $translatedvalue = $incominguserdata;
            foreach ($externalpath as $key) {
                $translatedvalue = $translatedvalue->$key ?? '';
                $this->value_validation($key, $translatedvalue);
            }
            $user[$internallabel] = $translatedvalue;
        }
        return $user;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $incomingtargetgroup
     * @return array
     */
    protected function translate_incoming_target_grous($incomingtargetgroup) {
        $prefix = 'translator_target_group_';
        $translationsmap = $this->local_taskflow_get_label_settings($prefix);
        $translatedtargetgroup = [];

        foreach ($translationsmap as $label => $value) {
            $internallabel = str_replace($prefix, '', $label);
            if (empty($value)) {
                $value = $internallabel;
            }
            $externalpath = explode('->', $value);
            $translatedvalue = $incomingtargetgroup;
            foreach ($externalpath as $key) {
                $translatedvalue = $translatedvalue->$key ?? '';
            }
            $this->string_validation($translatedvalue);
            $translatedtargetgroup[$internallabel] = $translatedvalue;
        }
        return $translatedtargetgroup;
    }

    /**
     * Retrieve only the label-value settings dynamically.
     * @param string $prefixkey
     * @return array Filtered settings for label-value pairs.
     */
    private function local_taskflow_get_label_settings($prefixkey): array {
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $allsettings = (array)get_config('taskflowadapter_' . $selectedadapter);
        return array_filter(
            $allsettings,
            fn($key) => str_starts_with($key, $prefixkey),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $relationupdate
     * @return void
     */
    protected function trigger_unit_relation_updated_events($relationupdate) {
        foreach ($relationupdate as $relationupdates) {
            foreach ($relationupdates as $relationupdate) {
                $event = unit_relation_updated::create([
                    'objectid' => $relationupdate['child'],
                    'context'  => \context_system::instance(),
                    'userid'   => $relationupdate['child'],
                    'other'    => [
                        'parent' => (int) $relationupdate['parent'],
                        'child' => (int) $relationupdate['child'],
                    ],
                ]);
                $event->trigger();
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $unitmembers
     * @return void
     */
    protected function trigger_unit_member_updated_events($unitmembers) {
        foreach ($unitmembers as $unitmemberid => $unitmember) {
            foreach ($unitmember as $unit) {
                $event = unit_member_updated::create([
                    'objectid' => $unitmemberid,
                    'context'  => \context_system::instance(),
                    'userid'   => $unitmemberid,
                    'other'    => [
                        'unitid' => $unit['unit'],
                        'unitmemberid' => $unitmemberid,
                    ],
                ]);
                $event->trigger();
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function get_external_data() {
        return $this->externaldata;
    }

    /**
     * [Description for return_value_for_functionfield]
     *
     * @param string $functionname
     * @param stdClass $user
     *
     * @return mixed
     *
     */
    public function return_value_for_functionname(string $functionname, stdClass $user) {
        $shortname = $this->return_shortname_for_functionname($functionname);
        $value = $user->profile[$shortname] ?? "";
        return $value;
    }
    /**
     * Saves all the translateduserdata to the users array.
     *
     * @param stdClass $user
     * @param array $translateduser
     *
     * @return void
     *
     */
    public function create_user_with_customfields(stdClass $user, array $translateduser) {
        global $CFG;
        require_once($CFG->dirroot . "/user/profile/lib.php");
        $customfields = (array)profile_user_record($user->id, false);
        $externalid = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID);
        foreach ($translateduser as $shortname => $value) {
            if (array_key_exists($shortname, $customfields)) {
                $user->profile[$shortname] = $value;
            }
        }
        $this->users[$user->profile[$externalid]] = $user;
    }
    /**
     * Returns the Shortname for the name of the function.
     *
     * @param string $functionname
     *
     * @return string
     *
     */
    public static function return_shortname_for_functionname(string $functionname) {
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $subpluginconfig = get_config('taskflowadapter_' . $selectedadapter);
        $configsflip = array_flip((array)$subpluginconfig);
        $configname = $configsflip[$functionname];
        $shortname = str_replace('_translator', '', $configname);
        return $shortname;
    }
    /**
     * Saves the Data from the Customfields.
     *
     * @param array $users
     *
     * @return void
     *
     */
    public function save_all_user_infos(array $users) {
        foreach ($users as $user) {
            foreach ($user->profile as $key => $value) {
                if (is_array($value)) {
                    $user->profile[$key] = json_encode($value);
                }
            }
            profile_save_custom_fields($user->id, $user->profile);
        }
    }
}
