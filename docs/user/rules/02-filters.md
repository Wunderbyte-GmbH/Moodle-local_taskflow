[Back to chapter overview](README.md)

# Step "Filter" — restricting the audience

A filter is a condition on the **user's profile** that a member of the unit must satisfy to receive the assignment. Filters are only offered for rules of type *Rule for target group*; for *Rule for specific user* the step shows the notice *"As the rule is for a specific user, no further filter settings are needed."*

- **No filter** → every (active, non-suspended, not-on-long-leave) member of the unit matches.
- **Several filters** → all of them must match (logical AND). There is no OR between filter rows; create a second rule if you need alternatives.
- Filter rows are added with **Add filter** and removed with **Delete element**.

## Filter types

| Filter type (UI) | What it compares | Status |
|------------------|------------------|--------|
| **User profil field filter** | A **custom** user profile field (Site administration → Users → User profile fields). All custom fields are offered except one with the shortname `idnumber`. | Fully supported. |
| **Moodle user field filter** | The core fields **First access** / **Last access**. | > **Note:** This filter type can be saved but is **not evaluated** in the current version — a rule with only such a filter behaves as if it had no filter. Do not rely on it. |

### Fields of "User profil field filter"

| Field (UI label) | Type | Meaning |
|------------------|------|---------|
| **User profile field** | select | The custom profile field (shown by its name). |
| **Operator** | select | One of the operators below. |
| **Value** | text (max. 500) | The comparison value. Hidden for the operator *since*. |
| **Value** (date selector) | date | Shown only for the operator *since*. |

Validation: *since* is only accepted on custom fields of type **Date/Time**; otherwise the form shows *"This field is not of type datetype"* / *"This operation can only be used on customfields of type datetype"*.

## Operators — exact semantics

"Field" is the user's stored value of the selected profile field (always a string, `''` when empty). "Value" is what you typed. Comparisons are case-sensitive, exact string comparisons; surrounding spaces count.

| Operator (UI label) | Matches when | Value format | Typical use |
|---------------------|--------------|--------------|-------------|
| **equals** | field is exactly identical to Value | plain text | Menu/text field, e.g. `nurse`. Checkbox fields store `1` / `0`. |
| **does not equal** | field is not identical to Value (also matches an empty field) | plain text | Exclude one group. |
| **contains** | Value occurs somewhere inside field | plain text | Field `Sales-EMEA` contains `EMEA`. Empty Value matches everyone. |
| **does not contain** | Value does not occur inside field | plain text | |
| **list contains** | field equals **one** of the entries of a semicolon-separated list | `A;B;C` (no spaces around `;` unless they are part of the entry) | Several allowed roles: `nurse;midwife`. |
| **list does not contain** | field equals **none** of the list entries | `A;B;C` | |
| **since** | field date ≥ selected date (the selected day, 00:00, is included) | date picker; the field must be a Date/Time profile field (stores a Unix timestamp) | Users hired on or after 1 Jan 2026. |
| **before** | field date ≤ the comparison date | (see note) | |
| **before now minus value in days** | field date is **at least Value days in the past**: `today − Value days ≥ field` | whole number of days, e.g. `90` | Users employed for at least 90 days; users whose last training is older than a year. Re-evaluated daily (see below). |
| **before now plus value in days** | field date is not later than **Value days in the future**: `today + Value days ≥ field` | whole number of days | Contract end within the next 30 days (matches also every past date). Not re-evaluated daily. |

> **Note on "before":** The runtime compares the field against a *date*, but the editor shows the date picker only for *since*. A *before* filter created through the form therefore has no comparison date and matches only users whose field is empty. Use *since* with the opposite logic, or *before now plus value in days*, instead.

> **Note on JSON-array fields:** Some adapters store structured data (e.g. a list of unit roles) as a JSON array in a profile field. For such fields only the **first** array element's `role` entry is compared, and the operator *since* is not evaluated (the filter fails). If the typed Value is a Unix timestamp, the comparison switches to numeric equality for *equals*; other operators return no match.

### Rolling time windows — what is and is not possible

- "At least N days ago" (older than N days) → *before now minus value in days*. Rolling, checked daily.
- "Within the next N days" → *before now plus value in days* (also includes all past dates).
- "Within the **last** N days" (e.g. hired during the last 90 days) is **not** available as a rolling window. Use *since* with a fixed date and move the date periodically, or combine *before now minus value in days* on a different field.

## Daily re-evaluation

Filters are evaluated whenever the rule or the user is (re-)processed — on rule save, on unit membership changes, on Moodle user updates (not during imports and not with the tuines adapter), on the re-check action of the assignment page (`assignment.php?id=…&action=checkstatus`), and on the scheduled task below. Time-relative filters need periodic re-checking:

- The scheduled task **Rules with filters are regularly checked** (`\local_taskflow\task\reschedule_rules`, default 02:00 daily; see [Scheduled tasks](../scheduled_tasks/README.md)) looks for rules that contain at least one filter with the operator **before now minus value in days** and re-triggers those rules exactly as if they had been saved. Users who newly cross the threshold get their assignment on the next run.
- Rules that only use *before now plus value in days*, *since* or *before* are **not** re-triggered by this task.

## What happens when a user stops or starts matching

Every evaluation ends in one of two outcomes per (user, rule):

| Outcome | Effect on the user's assignment |
|---------|---------------------------------|
| **Stops matching** (filter no longer true, or user removed from the unit, or rule disabled) | Every active assignment of the user for this rule goes to status **Droppedout**: prolonged/overdue counters are reset, assigned date and due date are cleared, scheduled messages are removed. A *Completed* assignment is only marked inactive and keeps its dates. The user stays enrolled in courses/booking options — Taskflow does not unenrol. |
| **Starts matching again** | The **same** assignment record is reactivated (history is kept): its status is recomputed from the actual completion of the targets (normally *Assigned*, or *At least one target completed* / *Completed* if the user finished the targets meanwhile), the due date is recalculated (for *Duration* rules from now), the targets are (re-)enrolled and time-based messages are scheduled again. |
| **Starts matching for the first time** | A new assignment is created; see [README §4](README.md#4-rule-change-propagation). |

Whether a rule edit is applied to users who already have an assignment depends on **Enable or disable rule will also effect exsisting assignments** — without it, filter changes only affect users who have no assignment yet ([README §5](README.md#5-what-happens-to-existing-assignments-when-a-rule-is-edited-or-disabled)).

## Quick recipes

- **Only nurses**: *User profil field filter* · field `Role` · *equals* · `nurse`.
- **Nurses and midwives**: field `Role` · *list contains* · `nurse;midwife`.
- **Everyone except externals**: field `Employment type` · *does not equal* · `external`.
- **Employed for at least 90 days**: field `Contract start` (Date/Time) · *before now minus value in days* · `90`.
- **Hired since 1 January 2026**: field `Contract start` (Date/Time) · *since* · pick 1 Jan 2026.
- **Contract ends within 60 days**: field `Contract end` (Date/Time) · *before now plus value in days* · `60`.

## Related

- [01-rule-step.md](01-rule-step.md) — audience (unit / user) and inheritance
- [../units_and_users/README.md](../units_and_users/README.md) — which profile fields Taskflow and the adapters create
- [../assignments/01-status-lifecycle.md](../assignments/01-status-lifecycle.md) — status *Droppedout*
