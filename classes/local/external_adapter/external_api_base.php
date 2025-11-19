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

use core\exception\moodle_exception;
use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_relation_updated;
use local_taskflow\form\filters\types\user_profile_field;
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
     * Array of users with the external id as index.
     *
     * @var array
     */
    private static array $users;

    /**
     * Array of users with the interal id as index.
     *
     * @var array
     */
    private static array $usersbyid;
    /**
     * Array of users with the email as index.
     *
     * @var array
     */
    private static array $usersbyemail;

    /**
     * Boolean for importing flag for observer.
     *
     * @var bool
     */
    public static bool $importing = false;

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
        foreach ($this->fullmap as $label => $jsonkey) {
            // For the special treatment fields.
            if (empty($jsonkey)) {
                continue;
            }
            $internallabel = str_replace('translator_user_', '', $label);
            if (empty($jsonkey)) {
                $jsonkey = $internallabel;
            }

            $externalpath = explode('->', $jsonkey);
            foreach ($externalpath as $key) {
                $translatedvalue = $incominguserdata->$key ?? '';
                $this->value_validation($key, $translatedvalue);
            }

            // Some values need transformation, eg to become unix timestamps.
            $translatedvalue = $this->map_value($translatedvalue, $jsonkey, $user);

            $user[$internallabel] = $translatedvalue;
        }
        return $user;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $incomingtargetgroup
     * @return array
     */
    protected function translate_incoming_target_groups($incomingtargetgroup) {
        $prefix = 'translator_target_group_';
        $translationsmap = $this->local_taskflow_get_label_settings($prefix);
        $translatedtargetgroup = [];

        foreach ($translationsmap as $label => $value) {
            // First, we get the key from the translation map.
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
     * Saves all the translateduserdata to the users array and uses external fieldname as a key.
     *
     * @param stdClass $user
     * @param array $translateduser
     * @param string $externalidfieldname
     *
     * @return void
     *
     */
    public function create_user_with_customfields(stdClass &$user, array $translateduser, string $externalidfieldname) {
        global $CFG, $DB;

        if (empty($user->profile)) {
            $customfields = user_profile_field::get_userprofilefields();
        } else {
            $customfields = $user->profile ?? [];
        }

        foreach ($translateduser as $shortname => $value) {
            if (array_key_exists($shortname, $customfields)) {
                $user->profile[$shortname] = $value;
            }
        }
        // We store the users in ways we need it.
        self::store_user_in_static($user);
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
        if (!isset($configsflip[$functionname])) {
            return '';
        }
        $configname = $configsflip[$functionname];
        $shortname = str_replace('_translator', '', $configname);
        return $shortname;
    }

    /**
     * Returns the set jsonkey in the current subpluginconfig for the functionname.
     *
     * @param string $functionname
     *
     * @return string
     *
     */
    public static function return_jsonkey_for_functionname(string $functionname) {

        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $subpluginconfig = get_config('taskflowadapter_' . $selectedadapter);

        if (
            strpos($functionname, 'translator_target_group_') !== false
            || $functionname === taskflowadapter::TRANSLATOR_USER_FIRSTNAME
            || $functionname === taskflowadapter::TRANSLATOR_USER_LASTNAME
            || $functionname === taskflowadapter::TRANSLATOR_USER_EMAIL
        ) {
            return $subpluginconfig->$functionname ?? '';
        }

        $shortname = self::return_shortname_for_functionname($functionname);

        if (empty($shortname)) {
            return '';
        }

        $key = 'translator_user_' . $shortname;
        return $subpluginconfig->$key ?? '';
    }

    /**
     * Returns the function in the current subpluginconfig for a given jsonkey.
     * @param string $jsonkey
     *
     * @return [type]
     *
     */
    public static function return_function_by_jsonkey(string $jsonkey) {
        $shortname = self::return_shortname_by_jsonkey($jsonkey);
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $subpluginconfig = get_config('taskflowadapter_' . $selectedadapter);
        return $subpluginconfig->$shortname ?? '';
    }

    /**
     * Returns the function in the current subpluginconfig for a given jsonkey.
     * @param string $jsonkey
     *
     * @return [type]
     *
     */
    public static function return_shortname_by_jsonkey(string $jsonkey) {
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $subpluginconfig = get_config('taskflowadapter_' . $selectedadapter);
        $configsflip = array_flip((array)$subpluginconfig);

        $shortname = str_replace('translator_user_', '', ($configsflip[$jsonkey] ?? ''));
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

    /**
     * We need a static to retrieve the users by mail.
     *
     * @param string $email
     *
     * @return stdClass
     */
    public static function get_user_by_mail(string $email): mixed {
        return self::$usersbyemail[$email] ?? (object)[];
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    protected function start_dynamic_report() {
        xhprof_enable();
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    protected function end_dynamic_report() {
        $data = xhprof_disable();
        global $CFG;
        include_once($CFG->dirroot . '/xhprof-ui/xhprof_lib/utils/xhprof_lib.php');
        include_once($CFG->dirroot . '/xhprof-ui/xhprof_lib/utils/xhprof_runs.php');

        $xhprofruns = new XHProfRuns_Default('/var/www/moodle/xhprof');
        $oldumask = umask(002);
        $runid = $xhprofruns->save_run($data, 'default');
        umask($oldumask);
    }

    /**
     * Tear down mainly for php unit tests.
     *
     * @return void
     *
     */
    public static function teardown(): void {
        // Reset the static arrays to prevent memory leaks.
        self::$users = [];
        self::$usersbyid = [];
        self::$usersbyemail = [];
    }

    /**
     * This function maps values to unix timestamps.
     * This can be overwritten in taskflowadapters to match more values.
     *
     * @param mixed $value
     * @param string $jsonkey
     * @param array $user
     *
     * @return string
     *
     */
    private function map_value($value, string $jsonkey, array &$user) {
        $functionname = self::return_function_by_jsonkey($jsonkey);
        switch ($functionname) {
            case taskflowadapter::TRANSLATOR_USER_LONG_LEAVE:
                $value = $value ? 1 : 0;
                break;
            case taskflowadapter::TRANSLATOR_USER_CONTRACTEND:
            case taskflowadapter::TRANSLATOR_USER_CONTRACTSTART:
                $timestamp = strtotime($value);
                // Only assign if it's a valid 10-digit timestamp
                if ($timestamp == false) {
                    $value = false;
                } else if (strlen((string)$timestamp) <= 10) {
                    $value = $timestamp;
                } else {
                    $value = 9999999999;
                }
                break;
        }
        return $value;
    }

    /**
     * Setter function for users array.
     *
     * @param stdClass $user
     *
     * @return void
     *
     */
    public function set_users(stdClass $user) {
        self::store_user_in_static($user);
    }

    /**
     * Return user object from static array by moodle id
     *
     * @param int $id
     *
     * @return stdClass
     *
     */
    public static function get_user_by_moodle_id(int $id) {
        return self::$usersbyid[$id] ?? (object)[];
    }

    /**
     * Return user object from from static array by externalid
     *
     * @param string $id
     *
     * @return stdClass
     *
     */
    public static function get_user_by_externalid(string $id) {
        return self::$users[$id] ?? (object)[];
    }

    /**
     * Return user object from static array by email
     *
     * @param string $id
     *
     * @return stdClass
     *
     */
    public static function get_user_by_email(string $email) {
        return self::$usersbyemail[$email] ?? (object)[];
    }

    /**
     * Store user in static array
     * It stores in three different arrays.
     * By externalid, by moodleid and by email.
     *
     * @param stdClass $user
     * @param string $externalid
     *
     * @return void
     *
     */
    public static function store_user_in_static(stdClass $user, string $externalid = '') {
        global $CFG;

        self::$usersbyemail[$user->email] = $user;
        self::$usersbyid[$user->id] = $user;

        if (empty($externalid)) {
            if (!isset($user->profile)) {
                require_once($CFG->dirroot . '/user/lib.php');
                profile_load_custom_fields($user);
            }
            // We need to get the externalid of the user.
            if (isset($user->profile[taskflowadapter::TRANSLATOR_USER_EXTERNALID])) {
                $externalid = $user->profile[taskflowadapter::TRANSLATOR_USER_EXTERNALID];
            } else {
                $externalid = $user->username;
            }
        }

        self::$users[$externalid] = $user;
    }

    /**
     * Returns the current array of users.
     *
     * @return array
     *
     */
    public function return_static_users() {
        return self::$users;
    }

    /**
     * Function to retrieve the moodle user by the external id.
     * If there is no external id defined, the function falls back on the username.
     *
     * @param string $externalid
     *
     * @return stdClass
     *
     */
    public static function get_user_from_db_by_externalid(string $externalid) {
        global $DB;

        if (empty(self::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID))) {
            $sql = "
                SELECT u.*
                FROM {user} u
                JOIN {user_info_data} d ON d.userid = u.id
                JOIN {user_info_field} f ON f.id = d.fieldid
                WHERE f.shortname = :shortname
                AND d.data = :data
            ";

            $params = [
                'shortname' => self::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID),
                'data' => $externalid,
            ];
        } else {
            $sql = "SELECT * FROM {user} WHERE username LIKE :data";
            $params = [
                'data' => $externalid,
            ];
        }

        // Get users having this custom profile field value
        $users = $DB->get_records_sql($sql, $params);

        if (count($users) > 1) {
            throw new moodle_exception('twouserswithsameexternalid', 'local_taskflow');
        } else if (empty($users)) {
            return (object)[];
        } else {
            return reset($users);
        }
    }

    /**
     * Destroys the singletons for testing.
     *
     * @return void
     *
     */
    public static function destroy_instance() {
        self::$usersbyid = [];
        self::$usersbyemail = [];
        self::$users = [];
    }
}
