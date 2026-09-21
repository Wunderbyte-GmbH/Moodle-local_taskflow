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

/**
 * Read-only stand-in for a not-yet-persisted rule, used for the filter dry run of the previews.
 *
 * local_taskflow\local\assignment_operators\filter_operator::is_rule_active_for_user() is the single
 * place that decides whether a rule applies to a user. It only reads get_isactive() and
 * get_rulesjson() from the rule and hands the object on to the filter classes, so the very same
 * evaluation can be run for a rule the agent is only *proposing* — no filter logic is reimplemented.
 * local\rules\rules has a private constructor and always reads from the database, which is why the
 * proposed rule needs this stand-in.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_rule_candidate {
    /** @var int Id of the rule (0 for a rule that does not exist yet). */
    private int $id;

    /** @var int 1 when the proposed rule would be active. */
    private int $isactive;

    /** @var int Organisational unit the proposed rule belongs to. */
    private int $unitid;

    /** @var string The encoded rulejson document. */
    private string $rulesjson;

    /**
     * Constructor.
     *
     * @param int $id
     * @param int $unitid
     * @param int $isactive
     * @param string $rulesjson Encoded rulejson document (as stored in local_taskflow_rules.rulejson).
     */
    public function __construct(int $id, int $unitid, int $isactive, string $rulesjson) {
        $this->id = $id;
        $this->unitid = $unitid;
        $this->isactive = $isactive;
        $this->rulesjson = $rulesjson;
    }

    /**
     * Rule id (0 when the rule does not exist yet).
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Organisational unit id.
     *
     * @return int
     */
    public function get_unitid(): int {
        return $this->unitid;
    }

    /**
     * Active flag, compared loosely against '1' by filter_operator.
     *
     * @return int
     */
    public function get_isactive(): int {
        return $this->isactive;
    }

    /**
     * Encoded rulejson document.
     *
     * @return string
     */
    public function get_rulesjson(): string {
        return $this->rulesjson;
    }
}
