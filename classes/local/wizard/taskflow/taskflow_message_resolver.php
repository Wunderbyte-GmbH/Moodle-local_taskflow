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

use stdClass;

/**
 * Shared resolution of a message template reference for the taskflow agent skills.
 *
 * One catalogue for search_message_templates, preview_message and diagnose_message_delivery:
 * a template is addressed by its id (messageid) or by a distinctive part of its NAME
 * (messagequery, case-insensitive substring matched against the stored rows — structural
 * matching against DB records, never against user text patterns). Exactly one match resolves;
 * several matches are reported as ambiguous with the candidates; none as not found. A skill
 * that knows the assignment may additionally infer the template from the templates attached
 * to the assignment's rule (optionally narrowed to one persisted class), again only when that
 * leaves exactly one candidate.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_message_resolver {
    /** Issue code: no template with the given id or matching the query. */
    public const ISSUE_MESSAGE_NOT_FOUND = 'TASKFLOW_MESSAGE_NOT_FOUND';

    /** Issue code: several templates match the query (or the rule carries several). */
    public const ISSUE_MESSAGE_AMBIGUOUS = 'TASKFLOW_MESSAGE_AMBIGUOUS';

    /** Issue code: neither messageid nor messagequery given (and nothing to infer from). */
    public const ISSUE_MESSAGE_REFERENCE_MISSING = 'TASKFLOW_MESSAGE_REFERENCE_MISSING';

    /** Resolution status: exactly one template. */
    public const STATUS_FOUND = 'found';
    /** Resolution status: more than one candidate. */
    public const STATUS_AMBIGUOUS = 'ambiguous';
    /** Resolution status: no candidate. */
    public const STATUS_NOT_FOUND = 'not_found';
    /** Resolution status: no reference given at all. */
    public const STATUS_MISSING = 'missing';

    /** Persisted values of {local_taskflow_messages}.class (message_form_entity::set_messagetype()). */
    public const MESSAGE_CLASSES = ['standard', 'onevent', 'request', 'onrequestcreated', 'onrequestclosed', 'chat'];

    /** Upper bound of candidates listed in an ambiguity issue. */
    public const MAX_CANDIDATES = 10;

    /**
     * WHERE fragment + params selecting template rows whose name contains the query.
     *
     * A purely numeric query additionally matches the id. Empty query = every row.
     *
     * @param string $query
     * @return array{0:string,1:array}
     */
    public static function name_where(string $query): array {
        global $DB;

        $query = trim($query);
        if ($query === '') {
            return ['1 = 1', []];
        }
        $like = $DB->sql_like('name', ':query', false, false);
        $params = ['query' => '%' . $DB->sql_like_escape($query) . '%'];
        if (preg_match('/^\d+$/', $query)) {
            $params['queryid'] = (int)$query;
            return ['(' . $like . ' OR id = :queryid)', $params];
        }
        return [$like, $params];
    }

    /**
     * Template rows (id, name, class) whose name contains the query, ordered by name.
     *
     * @param string $query
     * @param int $limit 0 = unlimited.
     * @return array<int,array{id:int,name:string,class:string}>
     */
    public static function candidates(string $query, int $limit = 0): array {
        global $DB;

        [$where, $params] = self::name_where($query);
        $rows = $DB->get_records_select(
            'local_taskflow_messages',
            $where,
            $params,
            'name ASC, id ASC',
            'id, name, class',
            0,
            $limit
        );
        return array_values(array_map(static fn(stdClass $row): array => self::candidate_row($row), $rows));
    }

    /**
     * Raw template record, or null when it does not exist.
     *
     * @param int $messageid
     * @return stdClass|null
     */
    public static function load(int $messageid): ?stdClass {
        global $DB;

        if ($messageid <= 0) {
            return null;
        }
        $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid]);
        return $record ?: null;
    }

    /**
     * Resolve the template from the skill input (messageid first, then messagequery).
     *
     * @param array $input
     * @return array{status:string,messageid:int,template:stdClass|null,query:string,
     *               candidates:array<int,array{id:int,name:string,class:string}>}
     */
    public static function resolve(array $input): array {
        $messageid = taskflow_input_normalizer::to_int($input['messageid'] ?? null);
        if ($messageid !== null && $messageid > 0) {
            $template = self::load($messageid);
            return self::resolution(
                $template === null ? self::STATUS_NOT_FOUND : self::STATUS_FOUND,
                $template,
                '#' . $messageid,
                $template === null ? [] : [self::candidate_row($template)]
            );
        }

        $query = trim((string)($input['messagequery'] ?? ''));
        if ($query === '') {
            return self::resolution(self::STATUS_MISSING, null, '', []);
        }

        $candidates = self::candidates($query);
        if (count($candidates) === 1) {
            return self::resolution(self::STATUS_FOUND, self::load($candidates[0]['id']), $query, $candidates);
        }
        return self::resolution(
            empty($candidates) ? self::STATUS_NOT_FOUND : self::STATUS_AMBIGUOUS,
            null,
            $query,
            $candidates
        );
    }

    /**
     * Ids of the templates a rule document attaches (actions[].messages[].messageid), in order.
     *
     * @param array $ruledocument Decoded rule document (the 'rule' node).
     * @return int[]
     */
    public static function rule_message_ids(array $ruledocument): array {
        $ids = [];
        foreach ((array)($ruledocument['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            foreach ((array)($action['messages'] ?? []) as $message) {
                $id = is_array($message) ? (int)($message['messageid'] ?? 0) : (int)$message;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Infer the template from the ones attached to a rule, optionally narrowed to one class.
     *
     * @param array $ruledocument Decoded rule document (the 'rule' node).
     * @param string $class One of MESSAGE_CLASSES or '' for any class.
     * @return array{status:string,messageid:int,template:stdClass|null,query:string,
     *               candidates:array<int,array{id:int,name:string,class:string}>}
     */
    public static function resolve_from_rule(array $ruledocument, string $class = ''): array {
        $class = trim($class);
        $candidates = [];
        $templates = [];
        foreach (self::rule_message_ids($ruledocument) as $id) {
            $template = self::load($id);
            if ($template === null) {
                continue;
            }
            if ($class !== '' && (string)$template->class !== $class) {
                continue;
            }
            $candidates[] = self::candidate_row($template);
            $templates[$id] = $template;
        }

        if (count($candidates) === 1) {
            return self::resolution(self::STATUS_FOUND, $templates[$candidates[0]['id']], $class, $candidates);
        }
        return self::resolution(
            empty($candidates) ? self::STATUS_NOT_FOUND : self::STATUS_AMBIGUOUS,
            null,
            $class,
            $candidates
        );
    }

    /**
     * Preflight issue describing a failed resolution, null when it succeeded.
     *
     * @param array $resolution Result of resolve() or resolve_from_rule().
     * @param string $field Input field the issue refers to.
     * @param string $lang Output language ('' = current).
     * @param int $assignmentid Set when the resolution was inferred from the assignment's rule.
     * @return array|null {code, severity, field, message, candidates}
     */
    public static function issue(array $resolution, string $field, string $lang = '', int $assignmentid = 0): ?array {
        $status = (string)($resolution['status'] ?? '');
        if ($status === self::STATUS_FOUND) {
            return null;
        }
        $candidates = (array)($resolution['candidates'] ?? []);
        $query = (string)($resolution['query'] ?? '');
        $list = self::candidate_list($candidates);

        if ($assignmentid > 0) {
            $a = (object)[
                'assignmentid' => $assignmentid,
                'class' => $query === '' ? '-' : $query,
                'candidates' => $list,
            ];
            $code = $status === self::STATUS_AMBIGUOUS ? self::ISSUE_MESSAGE_AMBIGUOUS : self::ISSUE_MESSAGE_NOT_FOUND;
            $key = $status === self::STATUS_AMBIGUOUS ? 'agent_message_rule_ambiguous' : 'agent_message_rule_none';
        } else if ($status === self::STATUS_MISSING) {
            $a = null;
            $code = self::ISSUE_MESSAGE_REFERENCE_MISSING;
            $key = 'agent_message_reference_missing';
        } else if ($status === self::STATUS_AMBIGUOUS) {
            $a = (object)['query' => $query, 'candidates' => $list];
            $code = self::ISSUE_MESSAGE_AMBIGUOUS;
            $key = 'agent_message_ambiguous';
        } else {
            $a = $query;
            $code = self::ISSUE_MESSAGE_NOT_FOUND;
            $key = strpos($query, '#') === 0 ? 'agent_notfound_message' : 'agent_message_query_notfound';
            if ($key === 'agent_notfound_message') {
                $a = substr($query, 1);
            }
        }

        return [
            'code' => $code,
            'severity' => 'needs_clarification',
            'field' => $field,
            'message' => self::string($key, $a, $lang),
            'candidates' => array_slice($candidates, 0, self::MAX_CANDIDATES),
        ];
    }

    /**
     * Human readable candidate list: #5 "Reminder 7 days" (standard), ...
     *
     * @param array<int,array{id:int,name:string,class:string}> $candidates
     * @return string
     */
    public static function candidate_list(array $candidates): string {
        $parts = [];
        foreach (array_slice($candidates, 0, self::MAX_CANDIDATES) as $candidate) {
            $parts[] = '#' . (int)$candidate['id'] . ' "' . (string)$candidate['name'] . '" ('
                . (string)$candidate['class'] . ')';
        }
        if (count($candidates) > self::MAX_CANDIDATES) {
            $parts[] = '…';
        }
        return implode(', ', $parts);
    }

    /**
     * Normalized candidate row of a template record.
     *
     * @param stdClass $record
     * @return array{id:int,name:string,class:string}
     */
    private static function candidate_row(stdClass $record): array {
        return [
            'id' => (int)$record->id,
            'name' => (string)$record->name,
            'class' => (string)$record->class,
        ];
    }

    /**
     * Resolution array skeleton.
     *
     * @param string $status
     * @param stdClass|null $template
     * @param string $query
     * @param array $candidates
     * @return array{status:string,messageid:int,template:stdClass|null,query:string,candidates:array}
     */
    private static function resolution(string $status, ?stdClass $template, string $query, array $candidates): array {
        return [
            'status' => $status,
            'messageid' => $template === null ? 0 : (int)$template->id,
            'template' => $template,
            'query' => $query,
            'candidates' => $candidates,
        ];
    }

    /**
     * local_taskflow string in the requested language.
     *
     * @param string $key
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    private static function string(string $key, $a, string $lang): string {
        $lang = trim($lang);
        return get_string_manager()->get_string($key, 'local_taskflow', $a, $lang === '' ? null : $lang);
    }
}
