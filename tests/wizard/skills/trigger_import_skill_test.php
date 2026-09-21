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
use context_system;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\trigger_import_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Dry run, override gate, adapter gate and counters of local_taskflow.trigger_import.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\trigger_import_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trigger_import_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /**
     * Setup: engine, standard adapter and the profile fields the adapter maps.
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

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->create_custom_profile_fields([
            'supervisor',
            'supervisor_external',
            'externalid',
            'orgunit',
            'contractend',
            'contractstart',
            'deputy',
        ]);
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * A small payload in the shape the standard adapter maps.
     *
     * @return string
     */
    private function payload(): string {
        return (string)json_encode([
            [
                'userID' => 900001,
                'Firstname' => 'Ida',
                'LastName' => 'Import',
                'DefaultEmailAddress' => 'ida.import@example.com',
                'Organisation' => 'Taskflow\\Testunit',
            ],
            [
                'userID' => 900002,
                'Firstname' => 'Ivo',
                'LastName' => 'Import',
                'DefaultEmailAddress' => 'ivo.import@example.com',
                'Organisation' => 'Taskflow\\Testunit',
            ],
        ]);
    }

    /**
     * Number of user accounts that are not deleted.
     *
     * @return int
     */
    private function user_count(): int {
        global $DB;
        return (int)$DB->count_records('user', ['deleted' => 0]);
    }

    /**
     * Preflight as the admin user.
     *
     * @param array $input
     * @return object
     */
    private function preflight(array $input) {
        global $USER;
        return (new trigger_import_skill())->preflight($input, context_system::instance()->id, (int)$USER->id);
    }

    /**
     * The dry run only reports the read-only checks and writes nothing.
     */
    public function test_dry_run_writes_nothing(): void {
        global $USER;

        $before = $this->user_count();
        $preflight = $this->preflight(['payload' => $this->payload(), 'dryrun' => true]);
        $this->assertSame('pass', $preflight->status);
        $this->assertTrue((bool)$preflight->preparedinput['dryrun']);

        $result = (new trigger_import_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$USER->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertTrue((bool)$result['dryrun']);
        $this->assertSame(2, (int)$result['filecount']);
        $this->assertSame($before, $this->user_count());
        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT,
            (string)$result['preview']['type']
        );

        // Without any payload the skill also only diagnoses.
        $result = (new trigger_import_skill())->execute(
            ['dryrun' => true],
            context_system::instance()->id,
            (int)$USER->id
        );
        $this->assertTrue((bool)$result['dryrun']);
        $this->assertSame($before, $this->user_count());
    }

    /**
     * Without the override token the import is only proposed, never executed.
     */
    public function test_without_override_token_nothing_is_written(): void {
        global $USER;

        $before = $this->user_count();
        $preflight = $this->preflight(['payload' => $this->payload(), 'dryrun' => false]);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(trigger_import_skill::ISSUE_OVERRIDE_REQUIRED, $preflight->issuecodes);
        $this->assertContains(
            trigger_import_skill::OVERRIDE_SUSPEND,
            (array)($preflight->issues[0]['remedy_options'] ?? [])
        );
        // The confirmation names the volume of the file and of the site.
        $this->assertStringContainsString((string)$before, (string)$preflight->issues[0]['user_question']);

        $skill = new trigger_import_skill();
        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $this->assertStringContainsString(
            trigger_import_skill::OVERRIDE_SUSPEND,
            implode(' ', array_column($description['rows'], 'value'))
        );

        $result = $skill->execute(
            ['payload' => $this->payload(), 'dryrun' => false],
            context_system::instance()->id,
            (int)$USER->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(trigger_import_skill::ISSUE_OVERRIDE_REQUIRED, $result['issue_codes']);
        $this->assertSame($before, $this->user_count());
    }

    /**
     * With the override token the payload really is imported and counted.
     */
    public function test_import_with_override_creates_users(): void {
        global $DB, $USER;

        $before = $this->user_count();
        $preflight = $this->preflight([
            'payload' => $this->payload(),
            'dryrun' => false,
            'override' => [trigger_import_skill::OVERRIDE_SUSPEND],
        ]);
        $this->assertSame('pass', $preflight->status);

        $result = (new trigger_import_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$USER->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertFalse((bool)$result['dryrun']);
        $this->assertSame(2, (int)$result['filecount']);
        $this->assertSame($before + 2, $this->user_count());
        $this->assertSame($before, (int)$result['counters_before']['users']);
        $this->assertSame($before + 2, (int)$result['counters_after']['users']);
        $this->assertTrue($DB->record_exists('user', ['email' => 'ida.import@example.com']));
        $this->assertTrue($DB->record_exists('user', ['email' => 'ivo.import@example.com']));
        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT,
            (string)$result['preview']['type']
        );
    }

    /**
     * An unusable payload is refused before anything runs.
     */
    public function test_invalid_payload_is_refused(): void {
        global $USER;

        $before = $this->user_count();
        $preflight = $this->preflight([
            'payload' => 'this is not json',
            'dryrun' => false,
            'override' => [trigger_import_skill::OVERRIDE_SUSPEND],
        ]);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(trigger_import_skill::ISSUE_PAYLOAD_INVALID, $preflight->issuecodes);
        $this->assertSame($before, $this->user_count());

        $result = (new trigger_import_skill())->execute([
            'payload' => 'this is not json',
            'dryrun' => false,
            'override' => [trigger_import_skill::OVERRIDE_SUSPEND],
        ], context_system::instance()->id, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(trigger_import_skill::ISSUE_PAYLOAD_INVALID, $result['issue_codes']);
        $this->assertSame($before, $this->user_count());
    }

    /**
     * With the tuines adapter the payload path is refused and the sub-plugin skill is named.
     */
    public function test_tuines_adapter_points_at_the_subplugin_skill(): void {
        global $USER;

        $this->generator->set_config_values('tuines');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        $before = $this->user_count();

        $preflight = $this->preflight([
            'payload' => $this->payload(),
            'dryrun' => false,
            'override' => [trigger_import_skill::OVERRIDE_SUSPEND],
        ]);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(trigger_import_skill::ISSUE_ADAPTER_UNSUPPORTED, $preflight->issuecodes);
        $this->assertStringContainsString(
            trigger_import_skill::ADAPTER_SKILLS['tuines'],
            (string)$preflight->issues[0]['message']
        );

        $result = (new trigger_import_skill())->execute([
            'payload' => $this->payload(),
            'dryrun' => false,
            'override' => [trigger_import_skill::OVERRIDE_SUSPEND],
        ], context_system::instance()->id, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(trigger_import_skill::ISSUE_ADAPTER_UNSUPPORTED, $result['issue_codes']);
        $this->assertSame(trigger_import_skill::ADAPTER_SKILLS['tuines'], (string)$result['adapter_skill']);
        $this->assertSame($before, $this->user_count());
    }
}
