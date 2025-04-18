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
use local_taskflow\local\contracts\unit_member_repository_interface;
use local_taskflow\local\contracts\user_repository_interface;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class external_api_base {
    /** @var string|null Stores the external user data. */
    protected stdClass $externaldata;

    /** @var user_repository_interface Stores the external user data. */
    protected user_repository_interface $userrepo;

    /** @var unit_member_repository_interface Stores the external user data. */
    protected unit_member_repository_interface $unitmemberrepo;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $data
     * @param user_repository_interface $userrepo
     * @param unit_member_repository_interface $unitmemberrepo
     */
    public function __construct(
        string $data,
        user_repository_interface $userrepo,
        unit_member_repository_interface $unitmemberrepo
    ) {
        $this->externaldata = (object) json_decode($data);
        $this->userrepo = $userrepo;
        $this->unitmemberrepo = $unitmemberrepo;
    }
    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $incominguserdata
     * @return array
     */
    protected function translate_incoming_data($incominguserdata) {
        $translationsmap = $this->local_taskflow_get_label_settings();
        $user = [];
        foreach ($translationsmap as $label => $value) {
            $internallabel = str_replace('translator_', '', $label);
            if (empty($value)) {
                $value = $internallabel;
            }
            $externalpath = explode('->', $value);
            $translatedvalue = $incominguserdata;
            foreach ($externalpath as $key) {
                $translatedvalue = $translatedvalue->$key ?? '';
            }
            $user[$internallabel] = $translatedvalue;
        }
        return $user;
    }

    /**
     * Retrieve only the label-value settings dynamically.
     *
     * @return array Filtered settings for label-value pairs.
     */
    private function local_taskflow_get_label_settings(): array {
        $allsettings = (array) get_config('local_taskflow');
        return array_filter(
            $allsettings,
            fn($key) => str_starts_with($key, 'translator_'),
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
                \local_taskflow\observer::call_event_handler($event);
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
                \local_taskflow\observer::call_event_handler($event);
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function get_external_data() {
        return $this->externaldata;
    }
}
