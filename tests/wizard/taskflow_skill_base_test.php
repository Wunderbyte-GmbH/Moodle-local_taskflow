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

namespace local_taskflow\wizard;

use advanced_testcase;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local_wizard_dependency.php');

/**
 * User-lookup issue helpers of the taskflow skill base class.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_skill_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_skill_base_test extends advanced_testcase {
    /**
     * Setup: engine required, DB reset.
     */
    protected function setUp(): void {
        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Minimal concrete skill exposing the protected lookup helpers.
     *
     * @return taskflow_skill_base
     */
    private function skill(): taskflow_skill_base {
        return new class extends taskflow_skill_base {
            /**
             * Constructor.
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
                return 'local_taskflow.test_base';
            }

            /**
             * Schema.
             *
             * @return array
             */
            protected function define_schema(): array {
                return ['version' => 1, 'description' => 'test', 'readonly' => true, 'properties' => []];
            }

            /**
             * Execute (unused).
             *
             * @param array $input
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $input, int $contextid, int $userid): array {
                return $this->base_result(self::STATUS_EXECUTED);
            }

            /**
             * Expose user_query_label().
             *
             * @param array $input
             * @param int $fallbackuserid
             * @return string
             */
            public function label(array $input, int $fallbackuserid = 0): string {
                return $this->user_query_label($input, $fallbackuserid);
            }

            /**
             * Expose user_lookup_issue().
             *
             * @param array $input
             * @param int $fallbackuserid
             * @return array
             */
            public function issue(array $input, int $fallbackuserid = 0): array {
                return $this->user_lookup_issue($input, '', $fallbackuserid);
            }
        };
    }

    /**
     * The label echoes the original query, then the given user id, and never the resolved id 0.
     */
    public function test_user_query_label_prefers_query_text(): void {
        $skill = $this->skill();
        $this->assertSame('Herr Kowalczyk', $skill->label(['userquery' => ' Herr Kowalczyk '], 0));
        $this->assertSame('4021', $skill->label(['userid' => '4021'], 0));
        $this->assertSame('17', $skill->label([], 17));
        $this->assertSame('Madame Duval', $skill->label(['userquery' => 'Madame Duval', 'userid' => 0], 0));
    }

    /**
     * Not found: the message names the query, not "0"; ambiguous: candidates are attached.
     */
    public function test_user_lookup_issue_not_found_and_ambiguous(): void {
        $skill = $this->skill();

        $issue = $skill->issue(['userquery' => 'Herr Kowalczyk'], 0);
        $this->assertSame(taskflow_skill_base::ISSUE_USER_NOT_FOUND, $issue['code']);
        $this->assertSame('needs_clarification', $issue['severity']);
        $this->assertSame('userquery', $issue['field']);
        $this->assertStringContainsString('Herr Kowalczyk', $issue['message']);
        $this->assertStringNotContainsString('"0"', $issue['message']);
        $this->assertArrayNotHasKey('candidates', $issue);

        $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->getDataGenerator()->create_user(['firstname' => 'Max', 'lastname' => 'Muster']);
        $issue = $skill->issue(['userquery' => 'Muster'], 0);
        $this->assertSame(taskflow_skill_base::ISSUE_USER_AMBIGUOUS, $issue['code']);
        $this->assertStringContainsString('Muster', $issue['message']);
        $this->assertCount(2, $issue['candidates']);
        foreach ($issue['candidates'] as $candidate) {
            $this->assertSame('Muster', $candidate['lastname']);
            $this->assertGreaterThan(0, $candidate['userid']);
        }
    }
}
