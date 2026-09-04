[Back to chapter overview](README.md)

# Step "Rule" — every field

The first step of the rule editor (`/local/taskflow/editrule.php?id=0` for a new rule) defines *who* the rule addresses and *by when* the targets have to be completed. All labels below are the English UI texts; none of the shipped adapters (standard, ksw, tuines) overrides them.

## Field reference

| Field (UI label) | Type | Default | Meaning |
|------------------|------|---------|---------|
| **Enable rule** (help: *Check to active*) | checkbox | checked | Sets the rule active. Only active rules create assignments and trigger enrolments/messages. See [Activation](README.md#3-activation-enable-rule). |
| **Enable or disable rule will also effect exsisting assignments** | checkbox | unchecked | Stored as `recursive`. When checked, saving the rule re-processes users who already have an assignment (update targets/due date, or drop them out when the rule is disabled or the filter no longer matches). When unchecked, only users without an assignment are processed. Also makes the rule apply to members added to child units. Details: [README §5](README.md#5-what-happens-to-existing-assignments-when-a-rule-is-edited-or-disabled). |
| **Name** | text (max. 128) | – | Mandatory. Shown as **Rulename** in the rules dashboard and in assignment lists and messages. |
| **Description** | textarea | – | Free text, shown in the **Description** column of the rules dashboard. |
| **Type** | select | *Select a rule type...* | Mandatory. *Rule for target group* — addresses the members of one unit; *Rule for specific user* — addresses exactly one user. |
| **User** | user autocomplete (*Select a user...*) | – | Only shown for *Rule for specific user*; mandatory in that case. |
| **Cohort** | autocomplete (*Select a cohort...*) | – | Only shown for *Rule for target group*; mandatory in that case. Lists all organisational units. With cohort-based units the list shows the full path (e.g. `Company/Sales/Team A`); with Taskflow's own units it shows the unit name. See [Units and users](../units_and_users/README.md). |
| **Regelvererbung** (German for "rule inheritance") | checkbox | unchecked | Stored as `inheritance`. When checked, the rule also applies to the members of all child units of the selected unit. |
| **Due date type** | select | *Duration* | *Duration* — the due date is the assignment date plus **Duration**. *Fixed date* — every assignment is due on the same calendar date/time. |
| **Duration** | duration (number + unit) | 4 weeks | Shown for *Duration*. Time between the assignment date and the due date. |
| **Fixed date** | date/time selector | now + 4 weeks | Shown for *Fixed date*. The absolute due date. Assignments created after this date are overdue immediately. |
| **Extension period** | duration | 4 weeks | Time by which a due date is extended when an assignment is prolonged. Used by the placeholder `<due_date_with_extension>` and — with the setting *Use prolonged state* of the tuines adapter — for the automatic first extension when an assignment runs over its due date. See [../assignments/05-due-dates-prolongation-overdue.md](../assignments/05-due-dates-prolongation-overdue.md). |
| **Does rule need cyclic validation?** | checkbox | unchecked | Makes the rule recurring. After completion the assignment is reopened when the **Validation duration** has passed; completions older than the validation duration do not count. See [../assignments/06-cyclic-assignments.md](../assignments/06-cyclic-assignments.md). |
| **Validation duration** | duration | 1 year (365 days) | Shown when cyclic validation is checked. Length of one cycle. |
| **Delay of activation** | duration (units: days, hours; default unit hours) | 0 | If greater than 0, new assignments start in status **Planned** (inactive, no enrolment, no messages) and are switched to **Assigned** by a background task after the delay. The assignment date — and therefore a *Duration* due date — is set when the assignment is opened, not when it was planned. |

Mandatory fields show *"This field is mandatory"* when left empty.

## How the fields interact

### Type, User and Cohort

The rule is stored either with a unit id (*Rule for target group*) or with a user id (*Rule for specific user*). When you reopen an existing rule the **Type** is derived from which of the two is set. Switching the type of an existing rule replaces the addressed audience; use **Enable or disable rule will also effect exsisting assignments** if the previous users' assignments should be dropped out.

### Due dates

| Due date type | Due date of a new assignment | Due date when an existing assignment is re-processed (recursive) |
|---------------|-----------------------------|------------------------------------------------------------------|
| Duration | assignment date + Duration | assignment date + current Duration; kept unchanged for assignments in status *Prolonged* |
| Fixed date | the Fixed date | the current Fixed date; kept for *Prolonged* |

When an assignment reaches its due date a background task sets it to **Overdue** (unless it is paused or already completed). What happens then — prolongation, counters, overdue completion — is described in [../assignments/05-due-dates-prolongation-overdue.md](../assignments/05-due-dates-prolongation-overdue.md).

### Delay of activation and messages

Time-based messages (e.g. "7 days after assignment date") are scheduled relative to the assignment date. For planned assignments this is the moment the delay ends, so a delay does not shorten the notice period.

## Quick recipes for this step

- **Deadline 30 days after joining the unit**: Type *Rule for target group*, Due date type *Duration*, Duration `30 days`.
- **Everyone must finish by 31 December**: Due date type *Fixed date*, pick the date.
- **Yearly refresher**: *Does rule need cyclic validation?* checked, Validation duration `365 days`.
- **Give new joiners a week before the clock starts**: Delay of activation `7 days`.

## Related

- [02-filters.md](02-filters.md) — narrow the audience further
- [README §7 — who is evaluated](README.md#7-who-is-evaluated-unit-members-inheritance-suspended-users)
- [../assignments/01-status-lifecycle.md](../assignments/01-status-lifecycle.md)
