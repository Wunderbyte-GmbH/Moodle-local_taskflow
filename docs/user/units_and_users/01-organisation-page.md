[Back to chapter overview](README.md)

# Organisation page — organisation chart, rules per unit, inheritance

The **organisation page** (`/local/taskflow/units.php`, optionally `?id=<unitid>` to jump to one unit) shows the
organisational units as a tree (organisation chart). Every unit lists its members, the rules assigned to it,
the rules it inherits from parent units, and — for users who may create rules — a button to assign further
rules to the unit.

---

## Quick path

1. Open `/local/taskflow/units.php` (needs `local/taskflow:vieworganisation`, manager archetype), or click **Organisation chart** on a
   [person page](../dashboard/01-person-page.md), or a unit name in the header of a person page.
2. Read the badges of a unit: members (direct, plus members of child units in brackets), own rules, inherited
   rules, child units.
3. Click **Assign rule to unit**, pick one or more rules, decide on **Also apply the rule to all child units**
   (inheritance), save. The assignments of the members are created immediately.
4. **Remove from unit** takes a rule away from the unit; the members' assignments become *Droppedout* unless
   the person holds the rule individually.

## Opening and closing units

On the first visit every unit is collapsed; the arrow in a unit's header opens its child units, and the
buttons **Expand all** / **Collapse all** above the chart open or close everything. The browser remembers
which units you left open (local storage of this browser, not the server). Opening the page with `?id=<unitid>`
opens the path to that unit and highlights it.

## What is shown per unit

| Element | Meaning |
|---------|---------|
| **Members** badge | number of direct members; `(+n)` members of all child units |
| **Own rules** | rules whose unit is this unit. A rule with the **Regelvererbung** badge also applies to all child units. Each rule shows the number of active assignments and, if any, the number of persons it was assigned to individually |
| **Inherited rules** | rules of parent units that carry inheritance; the badge names the unit the rule comes from |
| **Members** list | up to 10 members are listed directly, each linking to their person page. Larger units show **Show all n members** instead; it opens a searchable, pageable table that is loaded only when opened, so units with thousands of members do not slow the page down |
| **Assign rule to unit** | `local/taskflow:createrules` only |

## Assigning a rule

Only **active rules that address no unit yet** are offered — curricula and rules whose unit was removed. A rule
addresses one unit; to move a rule to another unit, remove it from the current unit first. (The service behind
the page already speaks in terms of "the units of a rule", so several units per rule can follow without changing
the page.)

The inheritance question is asked on every assignment and is stored in the rule (*Regelvererbung*), exactly
as if it had been set in the rule editor. See [Rules — step Rule](../rules/01-rule-step.md).

## Related

- [Units and users](README.md) — how units, hierarchy and memberships come into being
- [Person page](../dashboard/01-person-page.md) — individual assignment of rules
- [Rules](../rules/README.md)

---

## For AI / explain-docs routing

Questions that belong here: where to see the organisation chart, how many rules a unit has, which rules a unit
inherits, how to assign a rule to a unit or cohort, what inheritance means on the organisation page, how to
remove a rule from a unit.
