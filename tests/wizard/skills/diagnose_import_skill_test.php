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
use local_taskflow\local\units\organisational_units\unit;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\diagnose_import_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Adapter awareness, mapping gaps and warnings of local_taskflow.diagnose_import.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\diagnose_import_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_import_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $withsupervisor;

    /** @var \stdClass */
    private \stdClass $withoutsupervisor;

    /** @var int */
    private int $unitid = 0;

    /**
     * Setup: standard adapter, one unit with two members, one of them without a supervisor.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard', ['organisational_unit_option' => 'unit']);
        $fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $boss = $this->getDataGenerator()->create_user();
        $this->withsupervisor = $this->getDataGenerator()->create_user();
        $this->withoutsupervisor = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->withsupervisor->id,
            'fieldid' => $fields['supervisor'],
            'data' => (string)$boss->id,
            'dataformat' => 0,
        ]);

        $this->unitid = (int)unit::create_unit((object)['name' => 'Administration'])->get_id();
        unit::create_unit((object)['name' => 'Empty department']);
        foreach ([$this->withsupervisor->id, $this->withoutsupervisor->id] as $userid) {
            $DB->insert_record('local_taskflow_unit_members', (object)[
                'unitid' => $this->unitid,
                'userid' => $userid,
                'active' => 1,
                'timeadded' => time(),
                'timemodified' => time(),
                'usermodified' => 0,
            ]);
        }
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new diagnose_import_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * The standard configuration leaves the long leave function unmapped and one member
     * without a supervisor; both are reported and turned into recommendations.
     */
    public function test_standard_configuration_reports_mapping_gap_and_missing_supervisor(): void {
        $run = $this->run_skill([], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame('standard', $result['adapter']);
        $this->assertSame('', $result['adapter_skill']);

        // The standard fixture maps no profile field to the long leave function.
        $functions = array_column($result['unmapped_functions'], 'function');
        $this->assertContains(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $functions);
        $this->assertNotContains(taskflowadapter::TRANSLATOR_USER_SUPERVISOR, $functions);

        $this->assertSame(1, $result['users_without_supervisor']);
        $this->assertSame('supervisor', $result['supervisor_field']);
        $this->assertSame(0, $result['suspended_by_import']);
        $this->assertSame(1, $result['units_without_members']);

        $counters = array_column($result['counters'], 'value', 'label');
        $this->assertSame(2, $counters[get_string('agent_import_counter_members', 'local_taskflow')]);
        $this->assertSame(2, $counters[get_string('agent_import_counter_users', 'local_taskflow')]);

        $recommendations = implode("\n", $result['recommendations']);
        $this->assertStringContainsString(
            get_string('agent_import_recommendation_supervisor', 'local_taskflow', 1),
            $recommendations
        );
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('standard', $result['observation_full']);

        $preview = (new diagnose_import_skill())->get_result_preview(
            $result,
            context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT, $preview['type']);
        $this->assertStringContainsString(
            get_string('agent_import_unmapped', 'local_taskflow'),
            $preview['html']
        );
    }

    /**
     * Suspended members are counted and the tuines adapter points to its own skill.
     */
    public function test_suspended_members_and_adapter_skill_pointer(): void {
        global $DB;

        $DB->set_field('user', 'suspended', 1, ['id' => $this->withoutsupervisor->id]);
        $this->generator->set_config_values('tuines');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $result = $this->run_skill([], (int)get_admin()->id)['result'];
        $this->assertSame('tuines', $result['adapter']);
        $this->assertSame(1, $result['suspended_by_import']);
        $this->assertSame(
            diagnose_import_skill::ADAPTER_SKILLS['tuines'],
            $result['adapter_skill']
        );
        $this->assertStringContainsString(
            diagnose_import_skill::ADAPTER_SKILLS['tuines'],
            implode("\n", $result['recommendations'])
        );
    }

    /**
     * The observed period is normalized; an uninterpretable value is refused.
     */
    public function test_since_is_normalized(): void {
        $run = $this->run_skill(['since' => '2026-01-01'], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(strtotime('2026-01-01'), $run['result']['since']);

        $run = $this->run_skill(['since' => 'not a date at all'], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(diagnose_import_skill::ISSUE_DATE_INVALID, $run['preflight']->issuecodes);
    }

    /**
     * The skill declares moodle/site:config as a native capability, so the engine gates it.
     */
    public function test_native_capability_is_declared(): void {
        $skill = new diagnose_import_skill();
        $this->assertSame(
            [diagnose_import_skill::CAP_SITECONFIG],
            $skill->get_required_native_capabilities()
        );
        $this->assertTrue($skill->is_read_only());
    }
}
