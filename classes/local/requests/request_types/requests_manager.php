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
    private $requesttypes = [
        'allowuploadevidence',
        'allowselfextension',
        'allowselfnotrelevant',
    ];

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
        foreach ($this->requesttypes as $requesttype) {
            $active = get_config('local_taskflow', $requesttype);
            $title = get_string($requesttype . '_title', 'local_taskflow');
            if ($active) {
                $this->activerequests[$requesttype] = $title;
            } else {
                $this->inactiverequests[$requesttype] = $title;
            }
        }
    }

    /**
     * Get all request types.
     * @return array
     */
    public function get_active_request_types(): array {
        return $this->activerequests;
    }
}
