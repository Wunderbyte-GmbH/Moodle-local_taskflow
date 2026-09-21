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

namespace local_taskflow\local\wizard\taskflow\preview;

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\taskflow_stringmanager;

/**
 * Base class of every taskflow side-pane preview renderer.
 *
 * Subclasses set PREVIEW_TYPE, turn raw skill data into a fully localized/escaped
 * template context (build_context) and name the id arrays for accumulation
 * (default_payload). Rendering is hardened with an output buffer and never throws
 * (pattern: engine diagnostic_checklist_preview). Renderers are read-only and never
 * depend on the engine, so they also run without local_wizard/bookingextension_agent.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class taskflow_preview_renderer_base {
    /** Preview type string the client dispatches on; set per subclass (e.g. 'taskflow_assignment'). */
    public const PREVIEW_TYPE = '';

    /** Common prefix of every taskflow preview type. */
    public const TYPE_PREFIX = 'taskflow_';

    /** Badge classes by status type-class name (assignment_status_facade::get_all()[id]['label']). */
    private const STATUS_BADGE_CLASSES = [
        'completed' => 'bg-success',
        'overdue' => 'bg-danger',
        'reprimand' => 'bg-danger',
        'sanction' => 'bg-danger',
        'prolonged' => 'bg-warning text-dark',
        'paused' => 'bg-warning text-dark',
        'planned' => 'bg-warning text-dark',
        'assigned' => 'bg-primary',
        'enrolled' => 'bg-primary',
        'partially_completed' => 'bg-primary',
        'notrelevant' => 'bg-secondary',
        'droppedout' => 'bg-secondary',
    ];

    /** Badge class when the status is unknown. */
    private const STATUS_BADGE_FALLBACK = 'bg-light text-dark';

    /** @var string Output language of the current render() call ('' = current language). */
    protected string $lang = '';

    /**
     * Render the preview block.
     *
     * @param array $data Raw data as filled by the skill into $result['preview']['data'].
     * @param string $lang Output language (outputlang of the skill result).
     * @param array $payload Extra payload merged over default_payload(); id arrays only.
     * @return array{type:string,html:string,payload:array}|null Null when nothing is renderable.
     */
    final public function render(array $data, string $lang = '', array $payload = []): ?array {
        $this->lang = trim($lang);

        ob_start();
        try {
            $context = $this->build_context($data);
            $html = $context === null ? '' : $this->render_template($context);
        } catch (\Throwable $e) {
            $html = '';
        } finally {
            ob_end_clean();
        }

        if (trim($html) === '') {
            return null;
        }

        return [
            'type' => static::PREVIEW_TYPE,
            'html' => $this->wrap($html),
            'payload' => $this->normalize_payload($payload + $this->default_payload($data)),
        ];
    }

    /**
     * Turn raw data into the template context (already localized, escaped, with final URLs).
     *
     * @param array $data
     * @return array|null Null when the data is unusable (the chat answer carries the error).
     */
    abstract protected function build_context(array $data): ?array;

    /**
     * Id arrays for accumulation across a confirm chain (e.g. ['assignmentids' => [4711]]).
     *
     * @param array $data
     * @return array<string,int[]>
     */
    abstract protected function default_payload(array $data): array;

    /**
     * Render the context through the type's mustache template local_taskflow/wizard/preview_<type>.
     *
     * Subclasses may override to build markup with html_writer instead.
     *
     * @param array $context
     * @return string
     */
    protected function render_template(array $context): string {
        global $OUTPUT;
        return (string)$OUTPUT->render_from_template('local_taskflow/wizard/preview_' . $this->short_type(), $context);
    }

    /**
     * Preview type without the 'taskflow_' prefix.
     *
     * @return string
     */
    final protected function short_type(): string {
        $type = (string)static::PREVIEW_TYPE;
        if (strpos($type, self::TYPE_PREFIX) === 0) {
            return substr($type, strlen(self::TYPE_PREFIX));
        }
        return $type;
    }

    /**
     * Localized string in the render language (adapter override first, then local_taskflow).
     *
     * @param string $identifier
     * @param mixed $a
     * @return string
     */
    final protected function str(string $identifier, $a = null): string {
        return taskflow_stringmanager::get_string($identifier, $a, $this->lang === '' ? null : $this->lang);
    }

    /**
     * Escape a raw value for HTML output.
     *
     * @param mixed $text
     * @return string
     */
    final protected function esc($text): string {
        return s((string)$text);
    }

    /**
     * Escape a name (rule name, unit name, fullname) through format_string().
     *
     * @param mixed $text
     * @return string
     */
    final protected function name($text): string {
        return format_string((string)$text);
    }

    /**
     * Status badge data: colour class from the status type class, text from the facade,
     * plus a screen-reader text so the state is never conveyed by colour alone.
     *
     * @param int $statusid
     * @return array{id:int,label:string,class:string,active:bool,srtext:string,glyph:string}
     */
    final protected function status_badge(int $statusid): array {
        $all = assignment_status_facade::get_all();
        $type = (string)($all[$statusid]['label'] ?? '');
        $active = (bool)($all[$statusid]['active'] ?? false);
        $label = assignment_status_facade::get_specific_names($statusid, $this->lang === '' ? null : $this->lang);
        $class = self::STATUS_BADGE_CLASSES[$type] ?? self::STATUS_BADGE_FALLBACK;

        return [
            'id' => $statusid,
            'label' => $this->esc($label),
            'class' => 'badge ' . $class,
            'active' => $active,
            'srtext' => $this->esc($this->str($active ? 'activityactive' : 'activityinactive')),
            'glyph' => $active ? '●' : '○',
        ];
    }

    /**
     * Due-date chip data: formatted date, relative days and a colour class by urgency.
     *
     * @param int|null $timestamp
     * @param int|null $now Reference time (default now).
     * @return array{text:string,relative:string,class:string,title:string,days:int}|null Null when unset.
     */
    final protected function due_chip(?int $timestamp, ?int $now = null): ?array {
        if (empty($timestamp)) {
            return null;
        }
        $now = $now ?? time();
        $days = (int)floor(($timestamp - $now) / DAYSECS);
        if ($days < 0) {
            $class = 'bg-danger';
        } else if ($days <= 7) {
            $class = 'bg-warning text-dark';
        } else {
            $class = 'bg-light text-dark';
        }
        $sign = $days > 0 ? '+' : ($days < 0 ? '−' : '');
        return [
            'text' => $this->esc(userdate($timestamp, get_string('strftimedate', 'langconfig'))),
            'relative' => $sign . abs($days) . ' ' . $this->esc($this->str('agent_preview_days')),
            'class' => 'badge ' . $class,
            'title' => $this->esc(userdate($timestamp, get_string('strftimedatetime', 'langconfig'))),
            'days' => $days,
        ];
    }

    /**
     * Progress bar data for done/total targets.
     *
     * @param int $done
     * @param int $total
     * @return array{done:int,total:int,percent:int,text:string}
     */
    final protected function progress(int $done, int $total): array {
        $done = max(0, $done);
        $total = max(0, $total);
        $percent = $total > 0 ? (int)round(min($done, $total) * 100 / $total) : 0;
        return ['done' => $done, 'total' => $total, 'percent' => $percent, 'text' => $done . '/' . $total];
    }

    /**
     * Link data for the assignment page.
     *
     * @param int $assignmentid
     * @return array{url:string,label:string}
     */
    final protected function link_assignment(int $assignmentid): array {
        return $this->link(
            taskflow_result_link_builder::assignment_url($assignmentid),
            $this->str('agent_preview_open_assignment')
        );
    }

    /**
     * Link data for the rule editor.
     *
     * @param int $ruleid
     * @return array{url:string,label:string}
     */
    final protected function link_rule(int $ruleid): array {
        return $this->link(taskflow_result_link_builder::edit_rule_url($ruleid), $this->str('agent_preview_open_rule'));
    }

    /**
     * Link data for a documentation anchor key; null when unknown.
     *
     * @param string $key
     * @return array{url:string,label:string}|null
     */
    final protected function link_docs(string $key): ?array {
        $url = taskflow_result_link_builder::docs_link($key);
        return $url === '' ? null : $this->link($url, $this->str('agent_preview_open_docs'));
    }

    /**
     * Link data for a user profile.
     *
     * @param int $userid
     * @param string $label Escaped label; empty = generic profile label.
     * @return array{url:string,label:string}
     */
    final protected function link_user(int $userid, string $label = ''): array {
        return $this->link(
            taskflow_result_link_builder::user_url($userid),
            $label !== '' ? $label : $this->str('agent_preview_open_user')
        );
    }

    /**
     * Generic link data (label is escaped here; url is taken as is).
     *
     * @param string $url
     * @param string $label
     * @return array{url:string,label:string}
     */
    final protected function link(string $url, string $label): array {
        return ['url' => $url, 'label' => $this->esc($label)];
    }

    /**
     * "Open rules dashboard" link for the user the preview is rendered for, null when that user
     * may not open the rules/admin dashboard (skill_base passes the acting user as _userid).
     *
     * @param array $data Preview data (may carry '_userid').
     * @return array{url:string,label:string}|null
     */
    final protected function link_dashboard(array $data): ?array {
        $userid = (int)($data['_userid'] ?? 0);
        $url = taskflow_result_link_builder::rules_dashboard_url_for($userid);
        if ($url === '') {
            return null;
        }
        return $this->link($url, $this->str('agent_preview_open_dashboard'));
    }

    /**
     * Shorten a plain text to $max characters (multibyte safe), appending an ellipsis.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    final protected function truncate(string $text, int $max = 200): string {
        $text = trim($text);
        if (\core_text::strlen($text) <= $max) {
            return $text;
        }
        return rtrim(\core_text::substr($text, 0, max(1, $max - 1))) . '…';
    }

    /**
     * Empty-state card body for a type (agent_preview_empty_<type>).
     *
     * @return string
     */
    final protected function empty_state_html(): string {
        return \html_writer::tag(
            'p',
            $this->esc($this->str('agent_preview_empty_' . $this->short_type())),
            ['class' => 'text-muted mb-0']
        );
    }

    /**
     * Wrap rendered inner HTML into the self-contained preview card.
     *
     * @param string $html
     * @return string
     */
    final protected function wrap(string $html): string {
        return \html_writer::div(
            \html_writer::div($html, 'card-body p-3'),
            'taskflow-ai-preview-item card mb-3',
            ['data-preview-type' => (string)static::PREVIEW_TYPE]
        );
    }

    /**
     * Ensure id arrays in the payload are zero-indexed int lists so the engine can merge them.
     *
     * @param array $payload
     * @return array
     */
    private function normalize_payload(array $payload): array {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = array_values(array_unique(array_map('intval', array_filter($value, 'is_numeric'))));
            }
        }
        return $payload;
    }
}
