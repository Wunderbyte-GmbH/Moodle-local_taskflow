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

namespace local_taskflow\local\wizard;

use local_taskflow\local\wizard\engine\issue_code_provider_interface;
use local_taskflow\local\wizard\engine\result_summary_provider_interface;
use local_taskflow\local\wizard\engine\skill_catalog_discovery;
use local_taskflow\local\wizard\engine\skill_input_normalizer_interface;
use local_taskflow\local\wizard\engine\skill_input_normalizer_provider_interface;
use local_taskflow\local\wizard\engine\skill_interface;
use local_taskflow\local\wizard\engine\skill_provider_interface;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_summary_contributor;

/**
 * local_taskflow Wunderbyte-agent skill provider entrypoint.
 *
 * Skills are discovered by the engine below classes/local/wizard/taskflow/skills/ and
 * named local_taskflow.<name>; the governance capability derives from that name
 * (local/taskflow:skill_local_taskflow_<name>). Contextual prompt packs never carry
 * trigger word lists (HARD RULE: no lexical matching); all guidance is unconditional.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_provider implements
    result_summary_provider_interface,
    skill_input_normalizer_provider_interface,
    skill_provider_interface {
    /** Skill name namespace prefix of every core taskflow skill. */
    public const SKILL_NAMESPACE = 'local_taskflow';

    /**
     * Return the component name (capability prefix notation).
     *
     * @return string
     */
    public function get_component(): string {
        return 'local/taskflow';
    }

    /**
     * Return concrete skill instances, sorted by name.
     *
     * @return array<int,skill_interface>
     */
    public function get_skills(): array {
        $skills = array_values((new skill_catalog_discovery())->instances('local_taskflow'));

        usort($skills, static fn(skill_interface $a, skill_interface $b): int => strcmp($a->get_name(), $b->get_name()));
        return $skills;
    }

    /**
     * Return discovery diagnostics from the last get_skills() call.
     *
     * @return array<int,string>
     */
    public function get_discovery_diagnostics(): array {
        return (new skill_catalog_discovery())->diagnostics();
    }

    /**
     * Return contextual prompt packs collected from the skills.
     *
     * Packs are unconditional instruction blocks; any 'triggers' key a skill might
     * declare is stripped here so no lexical gate can ever reach the engine.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->get_skills() as $skill) {
            if (!method_exists($skill, 'get_contextual_prompt_packs')) {
                continue;
            }

            foreach ((array)$skill->get_contextual_prompt_packs() as $pack) {
                if (!is_array($pack)) {
                    continue;
                }

                $id = (string)($pack['id'] ?? '');
                if ($id === '' || isset($seenids[$id])) {
                    continue;
                }

                unset($pack['triggers']);
                $seenids[$id] = true;
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Return optional issue code provider.
     *
     * @return issue_code_provider_interface|null
     */
    public function get_issue_code_provider(): ?issue_code_provider_interface {
        return null;
    }

    /**
     * Return optional prompt guidance (unconditional instructions only).
     *
     * @return array<string,mixed>
     */
    public function get_prompt_guidance(): array {
        return [];
    }

    /**
     * Return the provider-owned skill input normalizer.
     *
     * @return skill_input_normalizer_interface|null
     */
    public function get_skill_input_normalizer(): ?skill_input_normalizer_interface {
        return new taskflow_input_normalizer();
    }

    /**
     * Return result summary contributors for taskflow skill results.
     *
     * @return array<int,object>
     */
    public function get_result_summary_contributors(): array {
        return [new taskflow_result_summary_contributor()];
    }
}
