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

namespace local_taskflow\wizard\skills;

use advanced_testcase;
use local_taskflow\local\wizard\skill_provider;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * The selector sees only the first 240 characters of a skill description (#471).
 *
 * The engine's planner catalog (planner_catalog_service::compact_catalog_description) truncates
 * every description sentence-aware at 240 characters. Runs 7/8 (2026-09-16) routed TSA-4 to
 * supervisor_overview, TDP-4 to core.diagnose_permissions and DMD-1 to the booking diagnosis because
 * the sentences that distinguish these skills came after the limit. The discriminating
 * identifiers (property names, capability prefix) must therefore sit inside the retained window.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\wizard\skill_provider
 */
final class skill_description_budget_test extends advanced_testcase {
    /** @var int Character budget of the planner catalog description. */
    private const BUDGET = 240;

    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
    }

    /**
     * Replica of the engine rule: whitespace-normalised, cut at the last sentence boundary within 240.
     *
     * @param string $description Raw description.
     * @return string Retained text ('' when no sentence boundary fits the window).
     */
    public static function retained(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if (\core_text::strlen($normalized) <= self::BUDGET) {
            return $normalized;
        }
        $window = \core_text::substr($normalized, 0, self::BUDGET);
        if (preg_match('/^(.*[.!?]["\'\)\]]*)(?:\s|$)/us', $window, $matches)) {
            return rtrim($matches[1]);
        }
        return '';
    }

    /**
     * Every description keeps at least one full sentence inside the selector window.
     */
    public function test_first_sentence_of_every_description_fits_the_window(): void {
        $broken = [];
        foreach ((new skill_provider())->get_skills() as $skill) {
            $retained = self::retained((string)($skill->get_schema()['description'] ?? ''));
            if ($retained === '' || !preg_match('/[.!?]["\'\)\]]*$/u', $retained)) {
                $broken[] = $skill->get_name();
            }
        }
        $this->assertSame([], $broken, 'no sentence boundary within 240 characters: ' . implode(', ', $broken));
    }

    /**
     * The identifiers that discriminate the confusable skills are inside the retained window.
     *
     * @return array<string,array{string,string[]}>
     */
    public static function discriminator_provider(): array {
        return [
            'search_assignments' => ['local_taskflow.search_assignments', ['unitquery', 'overdueonly', 'duebefore']],
            'supervisor_overview' => ['local_taskflow.supervisor_overview', ['supervisor']],
            'diagnose_permissions' => ['local_taskflow.diagnose_permissions', ['local/taskflow']],
            'diagnose_message_delivery' => ['local_taskflow.diagnose_message_delivery', ['assignment']],
            // Ticket 473: the WHY-diagnosis for person + rule, not the booking diagnosis / profile / list.
            'diagnose_user_assignments' => [
                'local_taskflow.diagnose_user_assignments',
                ['rule', 'unit', 'search_assignments'],
            ],
            // Ticket 472: status diagnosis (why) vs. assignment details (facts); both targetable by person + rule.
            'diagnose_assignment_status' => [
                'local_taskflow.diagnose_assignment_status',
                ['assignmentid', 'userquery', 'rulequery'],
            ],
            'get_assignment_details' => ['local_taskflow.get_assignment_details', ['history', 'diagnose_assignment_status']],
        ];
    }

    /**
     * Discriminating identifiers reach the selector.
     *
     * Since wave 17 (#2453) a SIBLING'S NAME no longer belongs in the description: the description is
     * embedding anchor #0, and a vector carries no negation, so a boundary sentence there pulled the skill
     * towards the very requests it was written to repel. The name now travels in the IS:/NOT: card lines,
     * which the selector reads and the embedding anchor builder does not. The guarantee is unchanged — the
     * identifier must reach the selector — so a sibling's name is asserted against the whole card, and
     * subject vocabulary still against the 240-character description window.
     *
     * @dataProvider discriminator_provider
     * @param string $skillname Skill name.
     * @param string[] $identifiers Property / capability identifiers expected inside the window.
     */
    public function test_discriminating_identifiers_are_inside_the_window(string $skillname, array $identifiers): void {
        $skill = null;
        $siblingnames = [];
        foreach ((new skill_provider())->get_skills() as $candidate) {
            if ($candidate->get_name() === $skillname) {
                $skill = $candidate;
                continue;
            }
            $name = $candidate->get_name();
            $siblingnames[$name] = true;
            $siblingnames[substr($name, (int)strrpos($name, '.') + 1)] = true;
        }
        $this->assertNotNull($skill, $skillname . ' not provided');
        $schema = (array)$skill->get_schema();
        $retained = self::retained((string)($schema['description'] ?? ''));
        $card = $retained . ' ' . trim((string)($schema['is'] ?? '')) . ' ' . trim((string)($schema['not'] ?? ''));
        foreach ($identifiers as $identifier) {
            if (isset($siblingnames[$identifier])) {
                $this->assertStringContainsString($identifier, $card, $skillname . ' card: ' . $card);
                continue;
            }
            $this->assertStringContainsString($identifier, $retained, $skillname . ' window: ' . $retained);
        }
    }
}
