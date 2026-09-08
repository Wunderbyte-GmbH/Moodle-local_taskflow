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
use local_taskflow\local\wizard\taskflow\skills\list_settings_skill;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Tests for the local_taskflow.list_settings skill.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\list_settings_skill
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_settings_catalog
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_settings_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /**
     * Setup: engine required, tuines adapter configured.
     */
    protected function setUp(): void {
        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('tuines');
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Preflight + execute as admin with the given input.
     *
     * @param array $input
     * @return array
     */
    private function run_as_admin(array $input = []): array {
        global $USER;
        $this->setAdminUser();
        return $this->run_as((int)$USER->id, $input);
    }

    /**
     * Preflight + execute as the given user (preflight must pass).
     *
     * @param int $userid
     * @param array $input
     * @return array
     */
    private function run_as(int $userid, array $input = []): array {
        $skill = new list_settings_skill();
        $dto = $skill->preflight($input, context_system::instance()->id, $userid);
        $this->assertSame('pass', $dto->status);
        return $skill->execute($dto->preparedinput, context_system::instance()->id, $userid);
    }

    /**
     * A non-admin user holding only the given capabilities in the system context.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    private function user_with(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Index settings by "component/name".
     *
     * @param array $result
     * @return array<string,array>
     */
    private function index(array $result): array {
        $indexed = [];
        foreach ($result['settings'] as $setting) {
            $indexed[$setting['component'] . '/' . $setting['name']] = $setting;
        }
        return $indexed;
    }

    /**
     * Contract: name, capability, schema shape.
     */
    public function test_contract(): void {
        $skill = new list_settings_skill();
        $this->assertSame('local_taskflow.list_settings', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(['local/taskflow:viewreports'], $skill->get_required_native_capabilities());
        $this->assertSame(CONTEXT_SYSTEM, $skill->get_required_context_level());

        $schema = $skill->get_schema();
        $this->assertArrayHasKey('filter', $schema['properties']);
        $this->assertArrayHasKey('includeadapter', $schema['properties']);
        $this->assertArrayHasKey('outputlang', $schema['properties']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertNotEmpty($schema['example_utterances']);
    }

    /**
     * Admin gets the full catalog incl. the tuines adapter values.
     */
    public function test_execute_lists_core_and_adapter_settings(): void {
        $result = $this->run_as_admin();

        foreach (
            ['status', 'detail', 'usermessage', 'observation_full', 'resultid', 'links', 'debugmessage', 'issue_codes',
            'settings', 'adapter', 'count', 'preview'] as $key
        ) {
            $this->assertArrayHasKey($key, $result, "Missing result key $key");
        }
        $this->assertSame('executed', $result['status']);
        $this->assertSame('tuines', $result['adapter']);
        $this->assertSame(count($result['settings']), $result['count']);

        $indexed = $this->index($result);
        $this->assertArrayHasKey('local_taskflow/external_api_option', $indexed);
        $this->assertSame('tuines', $indexed['local_taskflow/external_api_option']['current_value']);
        $this->assertSame('select', $indexed['local_taskflow/external_api_option']['type']);
        $this->assertNotSame('', $indexed['local_taskflow/external_api_option']['label']);

        $this->assertArrayHasKey('taskflowadapter_tuines/usingprolongedstate', $indexed);
        $this->assertTrue($indexed['taskflowadapter_tuines/usingprolongedstate']['current_value']);
        $this->assertSame('checkbox', $indexed['taskflowadapter_tuines/usingprolongedstate']['type']);

        $this->assertArrayHasKey('taskflowadapter_tuines/excludestatus', $indexed);
        $this->assertSame(['3', '7'], $indexed['taskflowadapter_tuines/excludestatus']['current_value']);

        $this->assertStringContainsString('external_api_option', $result['observation_full']);
        $this->assertStringContainsString('usingprolongedstate', $result['observation_full']);
        $this->assertStringContainsString('section=local_taskflow_settings', (string)$result['links']['page']);
        $this->assertNotEmpty($result['links']['docs']);
        $this->assertSame('taskflow_catalog', $result['preview']['type']);
    }

    /**
     * The filter narrows on name/label; includeadapter=false drops the adapter component.
     */
    public function test_filter_and_includeadapter_narrow_the_result(): void {
        $result = $this->run_as_admin(['filter' => 'prolonged']);
        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['settings']);
        foreach ($result['settings'] as $setting) {
            $this->assertTrue(
                stripos($setting['name'], 'prolonged') !== false || stripos($setting['label'], 'prolonged') !== false,
                $setting['name'] . ' does not match the filter'
            );
        }

        $result = $this->run_as_admin(['includeadapter' => false]);
        $this->assertNotEmpty($result['settings']);
        foreach ($result['settings'] as $setting) {
            $this->assertSame('local_taskflow', $setting['component']);
        }
        $this->assertFalse($result['includeadapter']);

        $result = $this->run_as_admin(['filter' => 'zzz-no-such-setting']);
        $this->assertSame('executed', $result['status']);
        $this->assertSame([], $result['settings']);
        $this->assertStringContainsString('zzz-no-such-setting', $result['usermessage']);
    }

    /**
     * Without local/taskflow:viewreports preflight blocks and execute answers gracefully.
     */
    public function test_user_without_viewreports_is_blocked(): void {
        $user = $this->user_with(['local/taskflow:editassignment']);
        $this->setUser($user);
        $skill = new list_settings_skill();

        $dto = $skill->preflight([], context_system::instance()->id, (int)$user->id);
        $this->assertSame('hard_block', $dto->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $dto->issuecodes);

        $result = $skill->execute([], context_system::instance()->id, (int)$user->id);
        $this->assertSame('error', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result['issue_codes']);
    }

    /**
     * A reporting user (local/taskflow:viewreports, no site:config) gets the catalog (F1).
     */
    public function test_viewreports_user_gets_the_catalog(): void {
        $user = $this->user_with(['local/taskflow:viewreports']);
        $this->assertFalse(has_capability('moodle/site:config', context_system::instance(), $user));
        $this->setUser($user);

        $result = $this->run_as((int)$user->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame('tuines', $result['adapter']);
        $this->assertArrayHasKey('local_taskflow/external_api_option', $this->index($result));
    }

    /**
     * Secret-bearing settings never expose their value: catalog-flagged entries, secret-like
     * names and URLs with embedded credentials are masked for admins and reporters alike.
     */
    public function test_secret_values_are_masked(): void {
        set_config(
            'dwhurl',
            'https://dwhuser:s3cr3tpass@dwh.example.org/rest/persons?apitoken=t0k3n',
            'taskflowadapter_tuines'
        );
        set_config('shortcodespassword', 'hunter2', 'local_taskflow');

        foreach ([(int)get_admin()->id, (int)$this->user_with(['local/taskflow:viewreports'])->id] as $userid) {
            $result = $this->run_as($userid);
            $indexed = $this->index($result);

            $this->assertTrue($indexed['taskflowadapter_tuines/dwhurl']['secret']);
            $this->assertSame(
                taskflow_settings_catalog::MASK,
                $indexed['taskflowadapter_tuines/dwhurl']['current_value']
            );
            $this->assertTrue($indexed['local_taskflow/shortcodespassword']['secret']);
            $this->assertSame(
                taskflow_settings_catalog::MASK,
                $indexed['local_taskflow/shortcodespassword']['current_value']
            );
            $this->assertFalse($indexed['local_taskflow/external_api_option']['secret']);

            $serialized = json_encode($result);
            $this->assertStringNotContainsString('s3cr3tpass', $serialized);
            $this->assertStringNotContainsString('t0k3n', $serialized);
            $this->assertStringNotContainsString('hunter2', $serialized);
            $this->assertStringContainsString('secret', $result['observation_full']);

            $preview = (new list_settings_skill())->get_result_preview(
                $result,
                context_system::instance()->id,
                $userid
            );
            $this->assertStringNotContainsString('hunter2', $preview['html']);
            $this->assertStringNotContainsString('s3cr3tpass', $preview['html']);
        }

        // An unset secret stays visibly empty (so "not configured" remains answerable).
        set_config('shortcodespassword', '', 'local_taskflow');
        $indexed = $this->index($this->run_as_admin());
        $this->assertSame('', $indexed['local_taskflow/shortcodespassword']['current_value']);
    }

    /**
     * The catalog derives secrecy structurally: flag, name suffix, credential URL.
     */
    public function test_catalog_secret_detection_and_url_masking(): void {
        $this->assertTrue(taskflow_settings_catalog::is_secret(['name' => 'anything', 'secret' => true]));
        $this->assertTrue(taskflow_settings_catalog::is_secret(['name' => 'blscertificatekey']));
        $this->assertTrue(taskflow_settings_catalog::is_secret_name('apitoken'));
        $this->assertTrue(taskflow_settings_catalog::is_secret_name('clientsecret'));
        $this->assertTrue(taskflow_settings_catalog::is_secret_name('ShortcodesPassword'));
        $this->assertFalse(taskflow_settings_catalog::is_secret_name('key'));
        $this->assertFalse(taskflow_settings_catalog::is_secret_name('external_api_option'));
        $this->assertFalse(taskflow_settings_catalog::is_secret(['name' => 'hrusers']));

        $this->assertSame(
            'https://***@dwh.example.org:8443/rest/persons?apitoken=***&format=***',
            taskflow_settings_catalog::mask_url_credentials(
                'https://dwhuser:s3cr3tpass@dwh.example.org:8443/rest/persons?apitoken=t0k3n&format=json'
            )
        );
        $this->assertSame(
            'https://dwh.example.org/rest',
            taskflow_settings_catalog::mask_url_credentials('https://dwh.example.org/rest')
        );
        $this->assertSame('plain text', taskflow_settings_catalog::mask_url_credentials('plain text'));

        $this->assertSame(
            taskflow_settings_catalog::MASK,
            taskflow_settings_catalog::masked_value(['name' => 'x', 'secret' => true], 'v')
        );
        $this->assertSame('', taskflow_settings_catalog::masked_value(['name' => 'x', 'secret' => true], ''));
        $this->assertSame(
            'https://***@h.example.org/p',
            taskflow_settings_catalog::masked_value(['name' => 'hrusers'], 'https://u:p@h.example.org/p')
        );
        $this->assertSame(['a', 'https://***@h.example.org/'], taskflow_settings_catalog::masked_value(
            ['name' => 'hrusers'],
            ['a', 'https://u:p@h.example.org/']
        ));
    }

    /**
     * The preview renders as taskflow_catalog with the setting names.
     */
    public function test_result_preview_renders_catalog(): void {
        global $USER;
        $result = $this->run_as_admin();
        $skill = new list_settings_skill();
        $preview = $skill->get_result_preview($result, context_system::instance()->id, (int)$USER->id);

        $this->assertNotNull($preview);
        $this->assertSame('taskflow_catalog', $preview['type']);
        $this->assertStringContainsString('taskflow-ai-preview-item', $preview['html']);
        $this->assertStringContainsString('external_api_option', $preview['html']);
        $this->assertStringContainsString('usingprolongedstate', $preview['html']);
        $this->assertStringContainsString('<details', $preview['html']);
        $this->assertStringContainsString('section=local_taskflow_settings', $preview['html']);
        $this->assertSame([], $preview['payload']);
    }
}
