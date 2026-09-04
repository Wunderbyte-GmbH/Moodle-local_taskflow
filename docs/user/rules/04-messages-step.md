[Back to chapter overview](README.md)

# Step "Messages" — attaching message templates to a rule

Messages are **not written inside the rule**. They are reusable **message templates** managed at `/local/taskflow/message_form/editmessage.php` (capability `local/taskflow:editmessages`), each carrying its own subject, body, recipients and sending time. In this step you only pick which templates belong to the rule.

## Fields

| Field (UI label) | Type | Meaning |
|------------------|------|---------|
| **Message packages** (*Select a package...*) | autocomplete, single | A **package** is a tag from the tag collection *Taskflow* that has been attached to one or more templates (field **Message package** in the template editor). Selecting a package **preselects** every template carrying that tag in the field below. The package itself is not stored — only the resulting message list is. |
| **Messages** (*Select messages...*) | autocomplete, multiple | The templates attached to this rule. Shown by template name (or subject). You can combine a package with additional single messages, or deselect single ones. |
| **Manage messages** | link | Opens the template list in a new tab so you can create a missing template and come back. |

Saving stores the list of template ids in the rule. Assignments created or re-processed afterwards carry this list; assignments that already exist only receive the new list when the rule is saved with **Enable or disable rule will also effect exsisting assignments** checked ([README §5](README.md#5-what-happens-to-existing-assignments-when-a-rule-is-edited-or-disabled)).

## When are the attached messages sent?

The *when* and *to whom* come from the template's **sending settings**, documented field by field in [../messages/01-message-templates.md](../messages/01-message-templates.md). In short:

| Template type / **Send when?** | Trigger inside this rule |
|--------------------------------|--------------------------|
| Standard · *Assignment date* + N minutes/hours/days *after* | Scheduled when the assignment is created or re-processed and at least one target action (enrolment/booking) ran. Fires N units after the assignment date; if that moment is already past it fires right away. |
| Standard · *Due date* ± N units | Scheduled the same way, relative to the due date (*before* is only allowed with *Due date*). No due date → nothing is scheduled. |
| Standard · *Status change* with a list of statuses | Sent when the assignment enters one of the listed statuses (e.g. *Completed*, *Overdue*), subject to the template's sending condition (always / only manual changes / only automatic changes). |
| Request · *Request opened* / *Request closed* | Sent when an assignee raises a request for an assignment of this rule (to the receiver chosen in [05-requests-step.md](05-requests-step.md)) or when the request is decided (to the assignee). |
| Chat | Sent as a Moodle notification when a new internal chat message is posted on an assignment of this rule. See [../messages/03-internal-communication.md](../messages/03-internal-communication.md). |

Rules that apply:

- **Once per assignment.** Every (template, rule, user) combination is sent at most once; the sent log is cleared when the assignment is dropped out or a cyclic assignment is reopened, so the message can go out again in the next cycle. Manually triggered status changes may resend if the admin setting *Manually triggered mails are always sent* (`sendmanualmailsmultipletimes`) is on.
- **Still relevant?** A time-based message is skipped at sending time if the assignment is meanwhile *Completed*, *Droppedout*, *Paused* or *Not relevant*. A status-change message is skipped if the assignment has already left the listed status.
- **Recipients** — assignee, supervisor (plus deputies when *Mails to supervisor are also forwarded to deputy* is on), or a specific user, with optional CC — are defined in the template, not in the rule.
- **Placeholders** such as `<firstname>`, `<due_date>`, `<targets>`, `<opentargets>` are resolved per assignment; see [../messages/02-placeholders.md](../messages/02-placeholders.md).
- Messages are sent by a background task; delivery happens on the next cron run after the scheduled time. Each delivery is logged in the assignment history.

## Quick recipes

- **Reminder package for all rules**: create templates "Welcome (1 day after assignment date)", "Reminder (7 days before due date)", "Overdue (status change → Overdue)", tag all three with the package `standard-reminders`. In every rule select **Message packages** = `standard-reminders`.
- **Inform the supervisor on completion**: template of type Standard, **Send when?** = *Status change*, statuses = *Completed*, recipient = *Supervisor*; attach it under **Messages**.
- **Notify HR when a user asks for an extension**: template of type Request, *Request opened*; attach it here and set the extension request receiver to *HR* in [05-requests-step.md](05-requests-step.md).

## Related

- [../messages/README.md](../messages/README.md) — sending mechanism, deduplication, notification preferences
- [../messages/01-message-templates.md](../messages/01-message-templates.md) — the template form
- [../messages/02-placeholders.md](../messages/02-placeholders.md)
