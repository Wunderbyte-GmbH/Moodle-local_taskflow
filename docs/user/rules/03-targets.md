[Back to chapter overview](README.md)

# Step "Targets" — what the user has to complete

A **target** is the thing the assignee must finish. A rule has at least one target row (the form always shows one); further rows are added with **Add target** and removed with **Delete element**. The order of the rows is the order in which targets are processed.

## Fields per target row

| Field (UI label) | Type | Meaning |
|------------------|------|---------|
| **Target type** | select | *Select a target type...*, **Moodle course**, **Competency**, **Booking option** (the last only if the plugin `mod_booking` is installed). |
| **Moodle course** | autocomplete | Shown for type Moodle course. Lists every course as `Full name (id)`. |
| **Competency** | autocomplete | Shown for type Competency. Lists every competency of the competency frameworks as `Short name (id)`. |
| **Booking option** | autocomplete | Shown for type Booking option. Lists every booking option as `Title (id)`. |
| **This target must be completed before user can continue with targets below** (help: *Check to active*) | checkbox, default unchecked | Sequencing flag (`completebeforenext`). See [Sequential targets](#sequential-targets-complete-before-next). |

The target's display name is resolved and stored when the rule is saved; the assignment shows it in the target list and in the `<targets>` / `<opentargets>` placeholders.

## What happens per target type

| Target type | When the assignment is created / reprocessed ("enrolment") | How completion is detected |
|-------------|------------------------------------------------------------|----------------------------|
| **Moodle course** | The user is enrolled through the course's **manual enrolment** method with the role *student*. Preconditions: the course exists and has an **enabled** manual enrolment instance (a user who is already enrolled by any method is accepted as is). If there is no enabled manual enrolment method the enrolment fails with an error and the target stays open. | Moodle **course completion** (*Course completed* event; completion tracking must be enabled in the course). Setting the completion back to incomplete or resetting the course reverts the target. |
| **Booking option** | The user is booked into the option (as if clicking *Book now*). Precondition: the option is currently bookable for this user — book-it button or confirmation step available. If the option is full, closed, outside its booking period, requires payment, or otherwise blocked by an availability condition, nothing happens and the target stays open. Being booked sets the assignment to **Enrolled**. | The booking option is marked **completed** for the user (activity completion in mod_booking). For cyclic rules the completion must be younger than the **Validation duration**. |
| **Competency** | Nothing is enrolled. The competency is acquired indirectly. | Either (a) the user has a completed booking option whose *competencies* list contains this competency (cyclic rules: completed within the validation duration), or (b) an **evidence upload** for this competency was **approved** and its *Valid until date* is empty or in the future (see [Competencies and certificates](../competencies_and_certificates/README.md)). |

Completion events are processed immediately (course completed, competency rated, booking booked/completed); the assignment status then becomes **At least one target completed** or **Completed** — see [../assignments/01-status-lifecycle.md](../assignments/01-status-lifecycle.md).

### Cyclic rules and targets

When a cyclic assignment is reopened after the **Validation duration**, the user is **unenrolled** from every target: manual course enrolment removed together with the user's course completion data, booking answer deleted, competency and its evidence links removed. Then the targets are enrolled again and the cycle restarts. See [../assignments/06-cyclic-assignments.md](../assignments/06-cyclic-assignments.md).

## Sequential targets ("complete before next")

Targets are processed in the order of the rows. When a target has **This target must be completed before user can continue with targets below** checked and is not yet completed, processing **stops** after it: the following targets are neither enrolled nor booked. As soon as the flagged target is completed, the assignment is re-evaluated and the next targets are enrolled — up to the next flagged, uncompleted target.

Example — three rows:

1. *Moodle course* "Basics" — flag checked
2. *Booking option* "Workshop" — flag checked
3. *Moodle course* "Refresher" — flag unchecked

The user is first only enrolled into "Basics". After completing it, the user is booked into "Workshop". After completing the workshop the user is enrolled into "Refresher". A target without the flag that follows a completed one is enrolled immediately.

> **Note:** The flag on the **last** target has no effect. A flagged target that cannot be enrolled (e.g. full booking option) blocks all following targets until it is completed some other way.

## Messages and targets

Time-based messages attached to the rule are scheduled only when at least one target action was actually executed for the user (enrolment or booking). For a rule whose only targets are competencies the "enrolment" is a no-op that counts as executed, so messages are scheduled normally.

## Quick recipes

- **One mandatory course**: one row, *Moodle course*. Make sure the course has an enabled *Manual enrolments* method and completion tracking with a completion condition.
- **Course first, then a seminar**: row 1 *Moodle course* with the "complete before next" flag, row 2 *Booking option*.
- **Prove a competency by course or certificate**: one row, *Competency*; enable the request type *Upload evidence of competencies* in [05-requests-step.md](05-requests-step.md) so users can upload a certificate instead of attending.

## Related

- [04-messages-step.md](04-messages-step.md)
- [../assignments/02-assignment-detail-page.md](../assignments/02-assignment-detail-page.md) — how targets are shown to the assignee
- [../competencies_and_certificates/README.md](../competencies_and_certificates/README.md)
