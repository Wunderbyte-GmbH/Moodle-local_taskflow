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

namespace local_taskflow\output;

use core_user;
use moodle_url;
use renderer_base;

/**
 * The "Me" view of the dashboard: the person page of the logged-in user inside the dashboard tabs.
 *
 * Same data as the person page, without assigning rights. Notes stay hidden, because the notes service never
 * shows a person the notes written about them.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class myoverview extends personpage {
    /**
     * Constructor.
     *
     * @param moodle_url $pageurl
     * @param int $userid The logged-in user.
     */
    public function __construct(moodle_url $pageurl, int $userid) {
        parent::__construct(core_user::get_user($userid, '*', MUST_EXIST), false, $pageurl);
    }

    /**
     * Every logged-in user may open their own overview.
     *
     * @param int $viewerid
     * @return bool
     */
    public static function can_view_own(int $viewerid): bool {
        return $viewerid > 0 && !isguestuser($viewerid);
    }

    /**
     * Rendered inside the dashboard page.
     *
     * @return string
     */
    public function get_template(): string {
        return 'local_taskflow/dashboardpage';
    }

    /**
     * Person page data, flagged as the "Me" view of the dashboard.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $data = parent::export_for_template($output);
        $data['isme'] = true;
        $data['personview'] = true;
        $data['isperson'] = false;
        return $data;
    }
}
