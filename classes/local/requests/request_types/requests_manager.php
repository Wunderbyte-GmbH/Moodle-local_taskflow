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

/**
 * Class requests
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer <georg.maißer@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests_manager {
    /** @var array Request types */
    private $activerequests = [];

    /** @var array Request types */
    private $inactiverequests = [];

    /** @var array Request types */
    private $requesttypes = [];

    /**
     * Create a new request entry.
     *
     * @return void
     * @throws \dml_exception
     */
    public function __construct() {
        $this->set_request_types();
        return;
    }

    /**
     * Set all request types.
     * @return void
     */
    private function set_request_types(): void {
        $path = __DIR__ . '/types';
        $prefix = 'local_taskflow\\local\\requests\\request_types\\types\\';
        foreach (glob($path . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = $prefix . $basename;
            if (class_exists($classname)) {
                $instance = new $classname();
                $this->requesttypes[$instance->get_id()] = $instance->get_type();
                if ($instance->is_active()) {
                    $this->activerequests[$instance->get_type()] = $instance->get_title();
                } else {
                    $this->inactiverequests[$instance->get_type()] = $instance->get_title();
                }
            }
        }
    }

    /**
     * Get all active request types.
     * @return array
     */
    public function get_active_request_types(): array {
        return $this->activerequests;
    }

    /**
     * Get all inactive request types.
     * @return array
     */
    public function get_inactive_request_types(): array {
        return $this->inactiverequests;
    }

    /**
     * Get all request types with matching ids.
     * @return array
     */
    public function get_request_types_with_ids(): array {
        return $this->requesttypes;
    }
}
