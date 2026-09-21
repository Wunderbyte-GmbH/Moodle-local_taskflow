[Back to chapter overview](README.md)

# Person page — training profile and individual assignment of rules and curricula

The **person page** shows the complete training profile of one person on a single page and is the place where HR
assigns **rules and curricula to individual persons** — in addition to the target-group rules that reach a person
through their organisational unit. It opens as a **person tab** of the [dashboard page](02-dashboard-page.md#person-tabs)
(`/local/taskflow/dashboard.php?view=person&id=<userid>`); links to `/local/taskflow/person.php?id=<userid>` forward there.

---

## Quick path

1. Choose the person in **Switch person** on the [dashboard page](02-dashboard-page.md), click a team tile, or open
   the person's Moodle profile and click **Person page** under *Miscellaneous*. The person stays open as a tab.
2. Read the header (units, supervisor, personnel number, contract dates) and the counters
   (*Completed*, *Open*, *Overdue*, *Completed courses*, *Certificates*).
3. Click **Assign rule / curriculum**, pick one or more rules, optionally add a note, save. The assignment is
   created immediately and the person is booked or enrolled into the targets right away.
4. **Remove** next to an individually assigned rule takes it away again; the assignment becomes *Droppedout*
   (unless the person still reaches the rule through a unit).

## Who can open the page

| Viewer | Access |
|--------|--------|
| supervisor or deputy of the person (`local/taskflow:issupervisor`) | the members of their team, read-only |
| `local/taskflow:viewreports` (manager) | every person, read-only |
| `local/taskflow:assignrulestouser` (manager archetype) | additionally **Assign rule / curriculum** and **Remove** |
| `local/taskflow:viewpersonnotes` / `local/taskflow:createpersonnotes` | read / write the **Notes** about the person - always limited to the same scope (own team, or everybody as manager). The upgrade grants both to the supervisor role |
| `local/taskflow:deletepersonnotes` | delete **own** notes, only within the time window of the setting *Time window for deleting own notes* (`personnotesdeletewindow`, default 15 minutes, 0 = unlimited). Granted to the supervisor role by the upgrade |
| `local/taskflow:deleteotherspersonnotes` | delete notes written by **others**, without time limit (manager archetype only) |

The person themselves cannot open their own person page as a person tab; their own profile is the **Me** tab of the
dashboard, without assign buttons and without notes.

## Sections

| Section | Content |
|---------|---------|
| Header | picture, name, e-mail, id, link to the Moodle profile; organisational units, supervisor (linked to their own person page), and the adapter-mapped profile fields personnel number, contract start and contract end (*open-ended* when the end lies more than 50 years ahead) |
| Counters | completed assignments, open assignments, overdue assignments, completed bookings (`mod_booking`), certificates (`tool_certificate`) — the last two link to the respective lists |
| Individually assigned rules and curricula | one row per rule assigned on this page: rule name (linked to the assignment), current status badge, who assigned it when, the note; **Remove** for users who may assign |
| Assignments | the [assignments table](README.md#the-assignments-table) with **all** assignments of the person (active and inactive), columns Rulename, Targets, Due date, Status; filters *Status* and *Hide completed* |
| Completions and evidence | timeline, newest first: completed assignments, completed booking options, certificates (with *valid until*) |
| Competencies | the person's user competencies; proficient ones carry a check mark |
| Notes | free-text notes HR and supervisors keep about the person (**Add note**); author and date are shown; deleting follows the capabilities above. Never shown to the person themselves |

## Curricula

A rule of type **Curriculum (assigned to persons individually)** has no target group of its own. It is only
ever applied to the persons it is assigned to on the person page. Target-group rules and rules for a specific
user can be assigned to additional persons on the person page as well.

Technically the personal assignments live in the table `local_taskflow_rule_users`. The assignment pipeline
treats them like a unit membership: the rule's filters still apply, targets are enrolled/booked through the
normal actions, and when the rule is saved again the personally assigned persons are re-processed together
with the unit members.

## Related

- [Dashboard](README.md) — user search and user tabs
- [Rules — step Rule](../rules/01-rule-step.md) — the rule type *Curriculum*
- [Competencies and certificates](../competencies_and_certificates/README.md)
- [Capabilities](../capabilities/README.md) — `local/taskflow:assignrulestouser`

---

## For AI / explain-docs routing

Questions that belong here: where to see everything about one person, how to assign a rule or curriculum to a
single person, how to remove such an assignment, what the counters on the person page mean, who may open the
page, what a curriculum is.
