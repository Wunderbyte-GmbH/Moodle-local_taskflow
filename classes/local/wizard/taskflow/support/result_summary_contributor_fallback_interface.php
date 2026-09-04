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

namespace local_taskflow\local\wizard\taskflow\support;

/**
 * Structural twin of the engine's summarizer\result_summary_contributor_interface.
 *
 * Used as alias target only when no engine plugin is installed.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface result_summary_contributor_fallback_interface {
    /**
     * Whether this contributor can summarize the given result entry.
     *
     * @param string $category Result category detected by the engine.
     * @param array $entry Raw skill result entry.
     * @return bool
     */
    public function supports(string $category, array $entry): bool;

    /**
     * Summarize the result entry in one plain-text line.
     *
     * @param array $entry Raw skill result entry.
     * @param int $step Step index within the execution chain.
     * @return string
     */
    public function summarize(array $entry, int $step = 0): string;
}
