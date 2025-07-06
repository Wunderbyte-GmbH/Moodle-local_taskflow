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

use core_plugin_manager;
use local_taskflow\local\external_adapter\external_api_interface;
use local_taskflow\local\external_adapter\adapters\external_api_user_data;
use local_taskflow\local\personas\unit_members\moodle_unit_member_facade;
use local_taskflow\local\personas\moodle_users\moodle_user_factory;
use local_taskflow\local\units\organisational_unit_factory;

/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class external_api_repository {
    /**
     * Factory for the organisational units
     *
     * @param string $data
     * @return mixed
     */
    public static function create(string $data): external_api_interface {
        $type = get_config('local_taskflow', name: 'external_api_option');
        $userrepo = new moodle_user_factory();
        $unitrepo = new organisational_unit_factory();
        $unitmemberrepo = new moodle_unit_member_facade();

        $class = "\\taskflowadapter_{$type}\\adapter";

        if (!class_exists($class)) {
            throw new \moodle_exception("Invalid external API type: $type");
        }
        return new $class($data, $userrepo, $unitmemberrepo, $unitrepo);
    }
}
