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

namespace local_taskflow\local\requests\request_types;

use local_taskflow\local\requests\request_types\requests_interface;

/**
 * Class requests
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer <georg.maißer@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class requests_base implements requests_interface {
    /** @var int $id The target ID. */
    public const ID = null;

    /** @var string $settingkey The name of the target. */
    public const SETTINGKEY = 'allowselfnotrelevant';

    /**
     * Get the receiver of the request.
     * @return string
     */
    public function get_id(): int {
        return static::ID;
    }

    /**
     * Get the receiver of the request.
     * @return string
     */
    public function get_title(): string {
        return get_string(static::SETTINGKEY . '_title', 'local_taskflow');
    }

    /**
     * Get the receiver of the request.
     * @return string
     */
    public function get_type(): string {
        return static::SETTINGKEY;
    }

    /**
     * Get the receiver of the request.
     * @return bool
     */
    public function is_active(): bool {
        return get_config('local_taskflow', static::SETTINGKEY);
    }
}
