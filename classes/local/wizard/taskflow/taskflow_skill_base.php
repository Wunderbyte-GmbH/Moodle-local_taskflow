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

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\rules\rules;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\wizard\engine\base_skill;
use local_taskflow\local\wizard\engine\localized_string_service;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\engine_component;
use local_taskflow\local\wizard\skill_provider;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\taskflow_stringmanager;
use stdClass;

/**
 * Base class of every local_taskflow Wunderbyte-agent skill.
 *
 * Conventions (implementation plan §1.4 / §2):
 * - skill names are local_taskflow.<name>; the governance capability derives from it;
 * - all skills operate in CONTEXT_SYSTEM, prompt_meta.context_scopes = ['system'];
 * - native capabilities passed to the constructor are enforced hard by the engine, so only
 *   admin-only skills declare them; scoped access (admin > supervisor > self) is checked in
 *   run_preflight() through taskflow_permission_resolver and answered with TASKFLOW_SCOPE_DENIED;
 * - results carry the base keys status|detail|usermessage|observation_full|resultid|links|
 *   debugmessage|issue_codes (see base_result()), previews are declared as data only
 *   ($result['preview'] = ['type', 'data', 'payload']) and rendered by get_result_preview();
 * - no lexical gates anywhere: every decision derives from engine/DB state.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class taskflow_skill_base extends base_skill {
    /** Issue code: the acting user has no scope on the requested data. */
    public const ISSUE_SCOPE_DENIED = 'TASKFLOW_SCOPE_DENIED';
    /** Issue code: assignment does not exist. */
    public const ISSUE_ASSIGNMENT_NOT_FOUND = 'TASKFLOW_ASSIGNMENT_NOT_FOUND';
    /** Issue code: rule does not exist. */
    public const ISSUE_RULE_NOT_FOUND = 'TASKFLOW_RULE_NOT_FOUND';
    /** Issue code: user query matched nobody. */
    public const ISSUE_USER_NOT_FOUND = 'TASKFLOW_USER_NOT_FOUND';
    /** Issue code: user query matched several users. */
    public const ISSUE_USER_AMBIGUOUS = 'TASKFLOW_USER_AMBIGUOUS';
    /** Issue code: a post-mutation verification failed. */
    public const ISSUE_VERIFICATION_FAILED = 'TASKFLOW_VERIFICATION_FAILED';

    /** Result status: executed and (for mutations) fully verified. */
    public const STATUS_EXECUTED = 'executed';
    /** Result status: error / not executed. */
    public const STATUS_ERROR = 'error';
    /** Result status: effect is asynchronous (adhoc task queued), not yet verifiable. */
    public const STATUS_QUEUED = 'queued';

    /** Prompt-facing schema fields treated as identity anchors. */
    private const ANCHOR_FIELD_CANDIDATES = ['query', 'userquery', 'rulequery', 'assignmentid', 'ruleid', 'userid'];

    /** @var string[] Native Moodle capabilities enforced hard by the engine at the operating context. */
    protected array $nativecapabilities;

    /** @var taskflow_permission_resolver|null Lazily created scope resolver. */
    private ?taskflow_permission_resolver $permissions = null;

    /**
     * Constructor.
     *
     * @param bool $readonly
     * @param string $riskclass One of skill_risk_class::R0..R3.
     * @param string[] $nativecapabilities Only for admin-only skills (e.g. 'moodle/site:config').
     */
    public function __construct(bool $readonly, string $riskclass, array $nativecapabilities = []) {
        if (!skill_risk_class::is_valid($riskclass)) {
            throw new \coding_exception('Invalid risk class declared for taskflow skill: ' . trim($riskclass));
        }
        parent::__construct($readonly, $riskclass);
        $this->nativecapabilities = array_values(array_filter(array_map(
            static fn($cap): string => trim((string)$cap),
            $nativecapabilities
        )));
    }

    /**
     * Skill name, e.g. 'local_taskflow.search_assignments'.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Raw JSON-schema-like definition of the skill input (without prompt_meta).
     *
     * Every schema declares 'outputlang' (string, optional); enrich_schema_with_prompt_meta()
     * adds it when missing.
     *
     * @return array
     */
    abstract protected function define_schema(): array;

    /**
     * Schema with prompt_meta (see enrich_schema_with_prompt_meta()).
     *
     * @return array
     */
    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta($this->define_schema());
    }

    /**
     * Skill-specific prompt metadata overriding the derived defaults.
     *
     * Keys: intent, input_fields_for_prompt, anchor_fields, context_scopes (always forced to
     * ['system']). Override per skill; the default derives everything from the schema.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [];
    }

    /**
     * Native capabilities declared in the constructor (Gate 2, enforced by the engine).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return $this->nativecapabilities;
    }

    /**
     * Every taskflow capability lives in the system context.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * Contextual prompt packs: unconditional instruction blocks only, never trigger lists.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [];
    }

    /**
     * Short name without the namespace (e.g. 'search_assignments').
     *
     * @return string
     */
    protected function short_skill_name(): string {
        $name = trim($this->get_name());
        $prefix = skill_provider::SKILL_NAMESPACE . '.';
        return strpos($name, $prefix) === 0 ? (string)substr($name, strlen($prefix)) : parent::short_skill_name();
    }

    /**
     * Add prompt_meta to a schema: required fields, anchor fields, context_scopes ['system'],
     * merged with prompt_meta() overrides; also guarantees the optional 'outputlang' property.
     *
     * @param array $schema
     * @return array
     */
    protected function enrich_schema_with_prompt_meta(array $schema): array {
        $properties = is_array($schema['properties'] ?? null) ? (array)$schema['properties'] : [];
        if (!isset($properties['outputlang'])) {
            $properties['outputlang'] = [
                'type' => 'string',
                'description' => 'Language code of the answer (e.g. de, en). Optional.',
            ];
        }
        $schema['properties'] = $properties;
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        $existing = is_array($schema['prompt_meta'] ?? null) ? (array)$schema['prompt_meta'] : [];
        if (!empty($existing)) {
            $existing['context_scopes'] = ['system'];
            $schema['prompt_meta'] = $existing;
            return $schema;
        }

        $requiredfields = [];
        $declaredrequired = array_values(array_filter((array)($schema['required'] ?? []), 'is_string'));
        foreach ($properties as $name => $spec) {
            if (!is_string($name) || !is_array($spec)) {
                continue;
            }
            if (!empty($spec['required']) || in_array($name, $declaredrequired, true)) {
                $requiredfields[] = $name;
            }
        }

        $anchorfields = [];
        foreach (self::ANCHOR_FIELD_CANDIDATES as $field) {
            if (array_key_exists($field, $properties)) {
                $anchorfields[] = $field;
            }
        }

        $meta = array_merge([
            'input_fields_for_prompt' => array_values($requiredfields),
            'anchor_fields' => array_values($anchorfields),
        ], $this->prompt_meta());
        $meta['context_scopes'] = ['system'];
        $schema['prompt_meta'] = $meta;

        return $schema;
    }

    /**
     * Output language requested by the caller ('' = current language).
     *
     * @param array $input Skill input or result entry.
     * @return string
     */
    protected function get_output_language(array $input): string {
        return trim((string)($input['outputlang'] ?? ''));
    }

    /**
     * Localized string: adapter override > local_taskflow > active engine strings.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang Empty = current language.
     * @return string
     */
    protected function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $lang = trim($lang);
        $manager = get_string_manager();
        $adaptercomponent = 'taskflowadapter_' . taskflow_settings_catalog::active_adapter();
        if ($manager->string_exists($identifier, $adaptercomponent) || $manager->string_exists($identifier, 'local_taskflow')) {
            return taskflow_stringmanager::get_string($identifier, $a, $lang === '' ? null : $lang);
        }

        $engine = engine_component::active();
        if ($engine !== null && $manager->string_exists($identifier, $engine) && class_exists(localized_string_service::class)) {
            return localized_string_service::get($identifier, $engine, $a, $lang);
        }

        return taskflow_stringmanager::get_string($identifier, $a, $lang === '' ? null : $lang);
    }

    /**
     * Resolve the target user: userid > userquery (id, e-mail, username, name search).
     *
     * Empty input means the acting user. A name search must be unique; otherwise 0.
     *
     * @param array $input
     * @param int $currentuserid
     * @return int 0 when nothing (unique) matched.
     */
    protected function resolve_userid(array $input, int $currentuserid): int {
        $userid = taskflow_input_normalizer::to_int($input['userid'] ?? null);
        if ($userid !== null && $userid > 0) {
            return $userid;
        }

        $query = trim((string)($input['userquery'] ?? ''));
        if ($query === '') {
            return $currentuserid;
        }

        $candidates = $this->search_user_candidates($query, 2);
        return count($candidates) === 1 ? (int)$candidates[0]['userid'] : 0;
    }

    /**
     * User candidates for a query: exact id, e-mail or username first, then a name search.
     *
     * @param string $query
     * @param int $limit
     * @return array<int,array{userid:int,firstname:string,lastname:string,email:string}>
     */
    protected function search_user_candidates(string $query, int $limit = 10): array {
        global $DB;

        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, $limit);

        $exact = null;
        if (preg_match('/^\d+$/', $query)) {
            $exact = \core_user::get_user((int)$query, 'id, firstname, lastname, email', IGNORE_MISSING);
        } else if (strpos($query, '@') !== false) {
            $exact = \core_user::get_user_by_email($query, 'id, firstname, lastname, email', null, IGNORE_MISSING);
        }
        if (!$exact) {
            $exact = \core_user::get_user_by_username($query, 'id, firstname, lastname, email', null, IGNORE_MISSING);
        }
        if ($exact && !empty($exact->id) && empty($exact->deleted)) {
            return [$this->user_candidate($exact)];
        }

        $found = supervisor::load_users($query, 0);
        $candidates = [];
        foreach ((array)($found['list'] ?? []) as $user) {
            $candidates[] = $this->user_candidate((object)$user);
            if (count($candidates) >= $limit) {
                break;
            }
        }
        return $candidates;
    }

    /**
     * Normalized user candidate row.
     *
     * @param stdClass $user
     * @return array{userid:int,firstname:string,lastname:string,email:string}
     */
    private function user_candidate(stdClass $user): array {
        return [
            'userid' => (int)($user->id ?? 0),
            'firstname' => (string)($user->firstname ?? ''),
            'lastname' => (string)($user->lastname ?? ''),
            'email' => (string)($user->email ?? ''),
        ];
    }

    /**
     * Assignment data (assignment::return_class_data()) for input['assignmentid'], or null.
     *
     * @param array $input
     * @return stdClass|null
     */
    protected function resolve_assignment(array $input): ?stdClass {
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null);
        if ($assignmentid === null || $assignmentid <= 0) {
            return null;
        }
        try {
            $instance = assignment::get_instance($assignmentid);
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($instance->id)) {
            return null;
        }
        return $instance->return_class_data();
    }

    /**
     * Rule row plus decoded rule document, or [] when the rule does not exist.
     *
     * @param int $ruleid
     * @return array{id:int,unitid:int,userid:int,rulename:string,isactive:bool,rulejson:string,rule:array}|array
     */
    protected function resolve_rule(int $ruleid): array {
        global $DB;

        if ($ruleid <= 0) {
            return [];
        }
        $instance = rules::instance($ruleid);
        if (!is_object($instance)) {
            return [];
        }
        $row = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], 'id, unitid, userid, rulename, isactive');
        if (!$row) {
            return [];
        }
        $rulejson = (string)$instance->get_rulesjson();
        $decoded = json_decode($rulejson, true);
        $rule = is_array($decoded) ? (array)($decoded['rulejson']['rule'] ?? []) : [];

        return [
            'id' => (int)$row->id,
            'unitid' => (int)$row->unitid,
            'userid' => (int)$row->userid,
            'rulename' => (string)$row->rulename,
            'isactive' => (bool)$row->isactive,
            'rulejson' => $rulejson,
            'rule' => $rule,
        ];
    }

    /**
     * Localized status name (adapter override aware).
     *
     * @param int $status
     * @param string $lang Empty = current language.
     * @return string
     */
    protected function status_label(int $status, string $lang = ''): string {
        return assignment_status_facade::get_specific_names($status, trim($lang) === '' ? null : trim($lang));
    }

    /**
     * Scope resolver (admin > supervisor > self).
     *
     * @return taskflow_permission_resolver
     */
    protected function permissions(): taskflow_permission_resolver {
        if ($this->permissions === null) {
            $this->permissions = new taskflow_permission_resolver();
        }
        return $this->permissions;
    }

    /**
     * Preflight issue for a denied scope (severity needs_clarification).
     *
     * @param string $lang
     * @param array $extra Additional issue fields (e.g. 'field').
     * @return array
     */
    protected function scope_denied_issue(string $lang = '', array $extra = []): array {
        return $extra + [
            'code' => self::ISSUE_SCOPE_DENIED,
            'severity' => 'needs_clarification',
            'message' => $this->localized_string('agent_scope_denied', null, $lang),
        ];
    }

    /**
     * Preflight issue for a missing object.
     *
     * @param string $code One of the ISSUE_* constants.
     * @param string $message Localized message.
     * @param array $extra Additional issue fields.
     * @return array
     */
    protected function not_found_issue(string $code, string $message, array $extra = []): array {
        return $extra + ['code' => $code, 'severity' => 'needs_clarification', 'message' => $message];
    }

    /**
     * Text a user-lookup message should echo: the original query, else the given user id.
     *
     * Never the resolved id (which is 0 exactly when the lookup failed).
     *
     * @param array $input Skill input (raw or prepared).
     * @param int $fallbackuserid Used when neither userquery nor userid is present.
     * @return string
     */
    protected function user_query_label(array $input, int $fallbackuserid = 0): string {
        $query = trim((string)($input['userquery'] ?? ''));
        if ($query !== '') {
            return $query;
        }
        $userid = taskflow_input_normalizer::to_int($input['userid'] ?? null);
        if ($userid !== null && $userid > 0) {
            return (string)$userid;
        }
        return (string)$fallbackuserid;
    }

    /**
     * Issue for a user lookup that resolved nobody: ambiguous (several candidates) or not found.
     *
     * The message echoes the original query; ambiguous issues carry the candidates so the
     * planner can ask which person is meant instead of widening the scope.
     *
     * @param array $input Skill input (raw or prepared).
     * @param string $lang Output language.
     * @param int $fallbackuserid See user_query_label().
     * @return array Preflight issue (code, severity, field, message[, candidates]).
     */
    protected function user_lookup_issue(array $input, string $lang = '', int $fallbackuserid = 0): array {
        $query = trim((string)($input['userquery'] ?? ''));
        $candidates = $query === '' ? [] : $this->search_user_candidates($query, 5);
        $ambiguous = count($candidates) > 1;
        $label = $this->user_query_label($input, $fallbackuserid);
        $issue = $this->not_found_issue(
            $ambiguous ? self::ISSUE_USER_AMBIGUOUS : self::ISSUE_USER_NOT_FOUND,
            $this->localized_string($ambiguous ? 'agent_user_ambiguous' : 'agent_user_notfound', $label, $lang),
            ['field' => 'userquery']
        );
        if ($ambiguous) {
            $issue['candidates'] = $candidates;
        }
        return $issue;
    }

    /**
     * Map input keys a planner may spell differently (unit_id, due-before, DueBefore) onto the
     * schema property names, so a filter is never dropped silently.
     *
     * Purely structural: a key is only remapped when, ignoring case, underscores and hyphens,
     * it equals exactly one declared property that is not already set. Unknown keys stay.
     *
     * @param array $input Raw input.
     * @return array
     */
    protected function canonical_input(array $input): array {
        $properties = array_keys((array)($this->define_schema()['properties'] ?? []));
        $bystripped = [];
        foreach ($properties as $property) {
            $bystripped[self::strip_key((string)$property)][] = (string)$property;
        }
        foreach ($input as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $properties, true)) {
                continue;
            }
            $targets = $bystripped[self::strip_key($key)] ?? [];
            if (count($targets) !== 1) {
                continue;
            }
            $target = $targets[0];
            if (!array_key_exists($target, $input)) {
                $input[$target] = $value;
            }
            unset($input[$key]);
        }
        return $input;
    }

    /**
     * Comparison form of an input key: lower-case without underscores and hyphens.
     *
     * @param string $key
     * @return string
     */
    private static function strip_key(string $key): string {
        return str_replace(['_', '-'], '', \core_text::strtolower($key));
    }

    /**
     * Debug message line block for results (skill + input + extra lines).
     *
     * @param string $skillname
     * @param array $input
     * @param string[] $extra
     * @return string
     */
    protected function build_task_debug_message(string $skillname, array $input, array $extra = []): string {
        $lines = ['Skill: ' . $skillname];
        $pairs = [];
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $pairs[] = $key . '=' . $this->stringify_debug_value($value);
        }
        if (!empty($pairs)) {
            $lines[] = 'Input: ' . implode(', ', $pairs);
        }
        foreach ($extra as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Result skeleton with every base key present.
     *
     * @param string $status One of STATUS_* (or any engine status).
     * @param array $fields Overrides / additional keys.
     * @return array
     */
    protected function base_result(string $status, array $fields = []): array {
        return array_merge([
            'status' => $status,
            'detail' => '',
            'usermessage' => '',
            'observation_full' => '',
            'resultid' => 0,
            'links' => taskflow_result_link_builder::links(null),
            'debugmessage' => '',
            'issue_codes' => [],
        ], $fields);
    }

    /**
     * Error result (status 'error') with a single issue code.
     *
     * @param string $code Issue code (ISSUE_* or skill-specific).
     * @param string $message Localized user-facing message.
     * @param array $extra Additional result keys (links, debugmessage, ...).
     * @return array
     */
    protected function error_result(string $code, string $message, array $extra = []): array {
        return $this->base_result(self::STATUS_ERROR, array_merge([
            'detail' => $message,
            'usermessage' => $message,
            'observation_full' => $message,
            'issue_codes' => [$code],
        ], $extra));
    }

    /**
     * Post-mutation verification (plan §3.4): compare the expected field values with a fresh read.
     *
     * The reader must invalidate caches itself (assignment::destroy_instance(),
     * rules::reset_instances(), ...) and return the fresh record as array; null = record gone.
     * Status is 'executed' only when every expected field verifies, otherwise 'error' with
     * 'verification' => ['verified' => [...], 'unverified' => [...]] and a detail naming both.
     *
     * @param array $expected Field => expected value (loose comparison, scalars/arrays).
     * @param callable $reader fn(): ?array — fresh read of the persisted record.
     * @param array $fields Result fields for the success case (usermessage, links, resultid, ...).
     * @param string $lang Output language for the detail text.
     * @return array
     */
    protected function verified_result(array $expected, callable $reader, array $fields = [], string $lang = ''): array {
        try {
            $actual = $reader();
        } catch (\Throwable $e) {
            $actual = null;
        }

        $verified = [];
        $unverified = [];
        foreach ($expected as $field => $value) {
            $field = (string)$field;
            if (!is_array($actual) || !array_key_exists($field, $actual)) {
                $unverified[] = $field;
                continue;
            }
            $stored = $actual[$field];
            $same = is_array($value) || is_array($stored)
                ? json_encode($value) === json_encode($stored)
                : (string)$value === (string)$stored;
            if ($same) {
                $verified[] = $field;
            } else {
                $unverified[] = $field;
            }
        }

        $verification = ['verified' => $verified, 'unverified' => $unverified];
        if (empty($unverified)) {
            return $this->base_result(self::STATUS_EXECUTED, $fields + ['verification' => $verification]);
        }

        $detail = $this->localized_string('agent_verification_failed', (object)[
            'verified' => implode(', ', $verified),
            'unverified' => implode(', ', $unverified),
        ], $lang);
        return $this->error_result(self::ISSUE_VERIFICATION_FAILED, $detail, [
            'verification' => $verification,
            'links' => $fields['links'] ?? taskflow_result_link_builder::links(null),
            'resultid' => $fields['resultid'] ?? 0,
        ]);
    }

    /**
     * Documentation URL for an anchor key (see taskflow_result_link_builder::DOCS_ANCHORS).
     *
     * @param string $key
     * @return string Empty when unknown.
     */
    protected function docs_link(string $key): string {
        return taskflow_result_link_builder::docs_link($key);
    }

    /**
     * Standard links block of a result.
     *
     * @param string|null $page
     * @param string[] $docskeys
     * @param array<string,string> $extra
     * @return array
     */
    protected function links(?string $page, array $docskeys = [], array $extra = []): array {
        return taskflow_result_link_builder::links($page, $docskeys, $extra);
    }

    /**
     * Render the side-pane preview declared as data by the skill (concept §3.2).
     *
     * Skills set $result['preview'] = ['type' => <taskflow_*>, 'data' => [...], 'payload' => [...]];
     * the executor replaces it with the rendered block {type, html, payload}.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $spec = $resultentry['preview'] ?? null;
        if (!is_array($spec) || trim((string)($spec['type'] ?? '')) === '') {
            return null;
        }
        $renderer = taskflow_preview_renderer_factory::for_type((string)$spec['type']);
        if ($renderer === null) {
            return null;
        }
        $data = is_array($spec['data'] ?? null) ? (array)$spec['data'] : [];
        // The renderer needs the acting user to gate capability-bound links (rules dashboard).
        $data['_userid'] = $userid;
        return $renderer->render(
            $data,
            $this->get_output_language($resultentry),
            is_array($spec['payload'] ?? null) ? (array)$spec['payload'] : []
        );
    }

    /**
     * Compact scalar/array representation for debug lines.
     *
     * @param mixed $value
     * @return string
     */
    private function stringify_debug_value($value): string {
        if (is_array($value) || is_object($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return trim((string)$value);
    }
}
