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

use local_taskflow\local\wizard\engine\result_summary_contributor_interface;
use local_taskflow\local\wizard\skill_provider;

/**
 * Summarizes local_taskflow skill results for the engine's synchronizer.
 *
 * Deterministic: supports() decides on the skill name prefix carried in the result
 * entry (engine state), summarize() reads usermessage/detail/links only.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_result_summary_contributor implements result_summary_contributor_interface {
    /**
     * Whether the entry belongs to a taskflow skill.
     *
     * @param string $category Result category detected by the engine.
     * @param array $entry Raw skill result entry.
     * @return bool
     */
    public function supports(string $category, array $entry): bool {
        $skill = trim((string)($entry['skill'] ?? ($entry['skillname'] ?? '')));
        return strpos($skill, skill_provider::SKILL_NAMESPACE . '.') === 0;
    }

    /**
     * One-line summary from usermessage (preferred), detail and the page link.
     *
     * @param array $entry Raw skill result entry.
     * @param int $step Step index within the execution chain.
     * @return string
     */
    public function summarize(array $entry, int $step = 0): string {
        $parts = [];
        foreach (['usermessage', 'detail'] as $key) {
            $text = trim((string)($entry[$key] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
                break;
            }
        }

        $links = is_array($entry['links'] ?? null) ? (array)$entry['links'] : [];
        $page = trim((string)($links['page'] ?? ''));
        if ($page !== '') {
            $parts[] = 'url=' . $page;
        }

        return implode(' ', $parts);
    }
}
