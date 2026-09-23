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
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\engine\skill_trigger_provider_interface;
use local_taskflow\local\wizard\skill_provider;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Every taskflow skill declares WHEN the selector should route to it.
 *
 * The engine renders the first message trigger of a skill as the card's WHEN line, and the selector
 * prompt tells the model to follow it. Baseline runs 21-30 (2026-09-23): no taskflow skill declared a
 * trigger, so no taskflow card had a WHEN line while the engine's own skills had one — LRP-4 went to
 * wizard.explain_docs ("User asks how something works ...") in nine of ten runs. The base class exposes
 * a declarative prompt_meta 'when' as that trigger; each skill writes the situation, not synonyms.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_skill_base
 */
final class skill_when_trigger_test extends advanced_testcase {
    /** @var int What the engine keeps of a WHEN line. */
    private const WHEN_CAP = 180;

    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
    }

    /**
     * Every provided skill is a trigger provider with one non-empty situation that the renderer keeps whole.
     */
    public function test_every_skill_declares_when(): void {
        $skills = (new skill_provider())->get_skills();
        $this->assertNotEmpty($skills);

        $missing = [];
        foreach ($skills as $skill) {
            if (!$skill instanceof taskflow_skill_base) {
                continue;
            }
            $name = $skill->get_name();
            if (!$skill instanceof skill_trigger_provider_interface) {
                $missing[] = $name . ' (no trigger provider)';
                continue;
            }
            $triggers = $skill->get_message_triggers();
            $when = trim((string)($triggers[0]['description'] ?? ''));
            if ($when === '') {
                $missing[] = $name . ' (empty when)';
                continue;
            }
            $this->assertLessThanOrEqual(self::WHEN_CAP, \core_text::strlen($when), $name . ' WHEN would be cut');
            $this->assertSame($name . '_request', (string)($triggers[0]['id'] ?? ''), $name . ' trigger id');
        }

        $this->assertSame([], $missing, "skills without WHEN:\n" . implode("\n", $missing));
    }

    /**
     * A skill without a declared situation contributes no trigger rather than an empty one.
     */
    public function test_a_skill_without_when_has_no_trigger(): void {
        $skill = new class extends taskflow_skill_base {
            /**
             * A read-only R0 skill.
             */
            public function __construct() {
                parent::__construct(true, skill_risk_class::R0);
            }

            /**
             * Name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'local_taskflow.nameless';
            }

            /**
             * Schema.
             *
             * @return array
             */
            protected function define_schema(): array {
                return ['version' => 1, 'description' => 'x', 'input' => ['type' => 'object', 'properties' => []]];
            }

            /**
             * Never called.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }
        };

        $this->assertSame([], $skill->get_message_triggers());
    }
}
