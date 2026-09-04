[Back to user documentation index](../README.md)

# Messages — Overview

Taskflow can notify people about assignments: the assignee, their supervisor (and deputies), HR, or any specific user.
Every notification is based on a **message template** that you write once (subject, body, recipients, timing) and then
attach to one or more rules. Taskflow decides *when* to send it (relative to the assignment date or due date, on a
status change, or when a request is opened/closed), *who* gets it, and delivers it by **e-mail** and as a **Moodle
notification**. A separate, lighter mechanism — the **internal chat** on an assignment — has its own daily digest.

This chapter covers the message system itself. How templates are *attached to a rule* is described in
[Rules — Messages step](../rules/04-messages-step.md); the self-service requests that can trigger request messages are
described in [Requests](../requests/README.md).

## Quick path: create a message and attach it to a rule

1. Open the template manager: `/local/taskflow/message_form/editmessage.php` (needs the capability
   `local/taskflow:editmessages`; also linked from *Site administration → Plugins → Local plugins → Wunderbyte Taskflow →
   Manage messages*).
2. Click **Create message** (`/local/taskflow/message_form/editmessage_form.php?action=new`).
3. Choose the **Message type** (*Standard*, *Request*, or *Chat* — the latter only when internal communication is
   enabled), give it a **Name of message**, pick **Recipient(s)**, optionally **CC user(s)**, write **Message Subject**
   and **Message Body** (placeholders like `<firstname>` are listed under the body), set a **Message package** tag and
   the **Send when?** timing. Click **Save message**. Field-by-field reference: [Message templates](01-message-templates.md).
4. Open the rule (`/local/taskflow/editrule.php?id=0` for a new rule) and go to the step **Messages**. Select a
   **Message package** (preselects all templates carrying that tag) or pick individual **Messages**. Save the rule.
5. Messages are queued per assignment and sent by cron (adhoc task `send_taskflow_message`). Check the assignment's
   **History** for the entry *Mail send*.

## Pages in this chapter

| Page | Content |
|---|---|
| [01 — Message templates](01-message-templates.md) | The template editor field by field, message types, timing options, sending conditions, priority, packages. |
| [02 — Placeholders](02-placeholders.md) | Every placeholder (`<firstname>`, `<due_date>`, `<opentargets>`, …) with meaning and example. |
| [03 — Internal communication](03-internal-communication.md) | The assignment chat between assignee and supervisor, its settings, unread tracking and the daily digest. |

## How a message gets sent

Whatever the trigger, delivery always follows the same path:

1. **Trigger** — something happens to an assignment (it is created/re-evaluated, its status changes, a request is
   opened or closed, a chat message is posted).
2. **Scheduling** — Taskflow computes the sending time from the template's **Send when?** settings and queues one
   adhoc task `\local_taskflow\task\send_taskflow_message` for *this user, this rule, this message*. If an identical
   task is already queued, it is deleted and re-queued (so a changed due date or a re-saved rule is honoured). A
   sending time in the past is rounded up to "now" — the message goes out on the next cron run.
3. **Checks at sending time** — the task re-reads the template and the assignment and sends only if
   * the assignment still exists (exactly one assignment for this user and rule),
   * the message was **not already sent** for this user/rule/message (see *Once vs. always* below), and
   * the message is **still valid**: time-based messages are dropped when the assignment is meanwhile
     *Completed*, *Droppedout*, *Paused* or *Not relevant*; status-change messages are dropped when the current status
     is no longer one of the selected statuses.
4. **Delivery** — placeholders are rendered, recipients resolved, then:
   * one **e-mail** per recipient (sender: the site's no-reply address; only users with a valid e-mail address);
   * one **e-mail per CC user**, subject `[CC]: <subject> (for: <recipient names>)`, body prefixed with
     *"This is a copy of the message that was sent to …"*. When CC users exist, the primary recipient's subject becomes
     `<subject> (cc: <CC names>)`;
   * one **Moodle notification** per recipient through the message provider **Taskflow notification**
     (`local_taskflow/notificationmessage`; web/popup and mobile enabled by default, e-mail output disallowed for this
     provider — the e-mail above is sent separately and cannot be turned off by the user);
   * a **history entry** *Mail send* on the assignment ([History](../assignments/04-history.md));
   * a row in the sent-messages log (`local_taskflow_sent_messages`), which is the deduplication memory.

*Chat*-type messages differ: they are delivered as a Moodle message only (no e-mail, no history entry) — see
[Internal communication](03-internal-communication.md).

## When: the triggers per message type

| Message type (form) | Stored class | Trigger | Scheduled by |
|---|---|---|---|
| Standard, **Send when?** = *Assignment date* or *Due date* | `standard` | The assignment is created or re-evaluated (rule saved, unit membership changed, import, cyclic reset) and at least one target action is executed. Sending time = assignment date / due date ± offset. | rule evaluation (action operator) |
| Standard, **Send when?** = *Status change* | `standard` (see note) | See the note below. | rule evaluation |
| Status-change message (class `onevent`) | `onevent` | The assignment enters one of the selected statuses (automatic status change by the engine, e.g. *Overdue*, *Completed*, *At least one target completed*, or a manual status change on the edit page), filtered by the sending condition *Send always / Automatically send / Manually send*. | status change handler |
| Request, *Request opened* | `request` | An assignee creates a request (not relevant / extension / evidence). Recipient = the rule's request receiver (supervisor + deputies, or HR users). | request created event |
| Request, *Request closed* | `request` | A reviewer confirms or declines the request. Recipient = the assignee. | request treated event |
| Chat | `chat` | A new message is posted in the assignment's internal chat. Recipient = the other side of the conversation. | new chat message event |

> **Note:** The template editor stores a *Standard* message as class `onevent` only when it is saved without a
> **Send when?** anchor. With the current form the anchor select always submits a value, so a template saved with
> **Status change** is stored as class `standard`: it is queued like a time-based message (offset counted from the
> moment the assignment is evaluated) and is sent only if the assignment is *at that moment* in one of the selected
> statuses; it is **not** re-triggered by later status changes. If you need a message that reliably fires when an
> assignment becomes overdue, use the time-based variant (*Due date*, *after*, e.g. 10 minutes) — see the recipe below.
> Templates imported with class `onevent` (e.g. from JSON fixtures or an earlier version) behave as true status-change
> messages.

## Who: recipients

| Recipient option | Resolves to |
|---|---|
| **Assignee** | The user the assignment belongs to. |
| **Supervisor Overview** (the option is labelled with the reused string `supervisor`) | The user whose Moodle id is stored in the assignee's profile field that carries the function *Supervisor* (adapter mapping, see [Units and users](../units_and_users/README.md)). If the setting **Send mails to deputy** (`sendmailstodeputy`) is on, the supervisor's deputies (comma-separated user ids in the supervisor's *Deputy* profile field) receive the same message as additional primary recipients. No supervisor configured → nobody is added. |
| **Choose specific user** + *Specific recipient* | One fixed Moodle user. |
| **CC user(s)** | Same three choices (*Assignee*, *Supervisor Overview*, *Choose specific user for CC* + *Specific CC-user*). CC users get their own e-mail copy, but no Moodle notification. |

Request messages ignore the **Recipient(s)** field: the receiver is taken from the rule's *Requests* step (supervisor
or HR) while the request is open, and is the assignee once it has been treated. CC settings still apply.
Chat messages ignore both recipient and CC settings.

## Once vs. always (deduplication)

* Every successful send writes a row *message × rule × user* into the sent-messages log. A message with an existing
  row is **not sent again** for that assignment — even if it is queued a second time (rule re-saved, unit re-imported,
  status entered twice).
* The log for an assignment is **cleared** when the assignment is reset or re-entered: cyclic reset
  ([Cyclic assignments](../assignments/06-cyclic-assignments.md)), reopening, the user being removed from and
  re-added to the unit (*Droppedout* → *Assigned*), the end of a long leave (*Paused* → *Assigned*). After such a
  reset the whole message set is sent again (a test scenario confirms: remove from cohort → no messages; re-add → new
  due date and messages sent again).
* When a booking option is *un*-completed and the assignment is therefore no longer *Completed*, the log rows of
  status-change messages that listed *Completed* are deleted, so the "completed" message can be sent again later.
* Setting **Send manual mails always** (`sendmanualmailsmultipletimes`): when a status is changed **manually** on the
  edit-assignment page, status-change messages are sent every time the status is entered again, ignoring the log.
  Without this setting, each status-change message is sent at most once per assignment — the per-message sending
  condition *Send always* on its own does **not** re-send.
* The sending condition (*Send always / Automatically send / Manually send*) decides whether a status-change message
  is queued at all, depending on whether the status change was made by a person or by the engine.

## Settings that affect messages

| Setting (`local_taskflow/…`) | UI label | Default | Effect |
|---|---|---|---|
| `sendmailstodeputy` | Send mails to deputy | off | Supervisor recipients (messages **and** request receivers) are extended by the supervisor's deputies. |
| `sendmanualmailsmultipletimes` | Send manual mails always | off | Manually triggered status-change messages bypass the sent-messages log. |
| `hrusers` | HR userids | `0` | Comma-separated Moodle user ids; receivers of requests routed to *HR*. Also used as *modified by* for manual status-change history entries. |
| `allowinternalcommunication` | Allow internal communication | on | Enables the *Chat* message type and the assignment chat (only offered when the active adapter provides a chat form — currently tuines). |
| `internalcommunicationpreviewlength` | Internal communication preview length | 300 | Characters of the newest chat message shown in the assignments table. |

The full list is in [Settings](../settings/README.md). Note that the HR **requests dashboard** is selected by a
different setting (`bookingextension_confirmation_supervisor/confirmation_supervisor_hrusers`), see
[Requests](../requests/README.md).

## Notification preferences of users

Taskflow registers four message providers (visible to each user under *Preferences → Notification preferences*):

| Provider | Used for | Defaults |
|---|---|---|
| Taskflow notification (`notificationmessage`) | Every rule message and every chat-type message | Web/popup and mobile on; e-mail output not allowed for this provider (the e-mail is sent separately by Taskflow). |
| Summary of internal Chat Message to assignees (`assigneenotification`) | Daily chat digest for assignees | Popup on; e-mail forced on. |
| Summary of internal Chat Message to supervisors (`supervisornotification`) | Daily chat digest for supervisors | Popup and e-mail on. |
| Summary of internal Chat Message to admin and chiefs (`adminnotification`) | Daily chat digest for site administrators | Popup on; e-mail permitted. |

Because rule messages are e-mailed directly, a user cannot opt out of Taskflow e-mails via notification preferences;
they can only switch the web/mobile copy. Adapter language packs may rename these strings (e.g. tuines).

## Recipes

### Remind the assignee 7 days before the due date

1. **Create message**: type *Standard*; **Recipient(s)** = *Assignee*; subject e.g. `Reminder: <targets> due on <due_date>`;
   body with `<firstname>`, `<opentargets>`, `<due_date>`.
2. **Send when?**: `7` · *days* · *before* · *Due date*. (*before* is only valid together with *Due date*.)
3. Optionally **CC user(s)** = *Supervisor Overview* so the supervisor gets a copy.
4. Save, then attach the template in the rule's **Messages** step.
5. Result: when the assignment is created, one task is queued for `due date − 7 days`. It is skipped if the
   assignment is completed, dropped out, paused or marked not relevant before then. If the due date is later extended
   (prolongation, admin edit), the task is re-queued at the new time — unless the message was already sent.

### Notify the supervisor when an assignment becomes overdue

1. **Create message**: type *Standard*; **Recipient(s)** = *Supervisor Overview* (turn on **Send mails to deputy** if
   deputies should get it too); subject e.g. `<firstname> <lastname>: <targets> overdue`; body with `<status>`,
   `<due_date>`, `<opentargets>`.
2. **Send when?**: `10` · *minutes* · *after* · *Due date*. The overdue check runs at the due date, so a few minutes
   after it the status is already *Overdue* (or *Prolonged* if the adapter uses the prolonged state).
3. Attach it to the rule. Because the message is invalid once the assignment is *Completed*, a supervisor is only
   notified for assignments that are really still open after the due date.
4. Variant with a status-change trigger (class `onevent`, statuses *Overdue*, condition *Send always*) works for
   templates that carry the class `onevent`; see the note in *When: the triggers per message type*.

### Let employees request an extension, approved by the supervisor

1. Site administration → **Taskflow Request Settings**: tick **Allow user to request extension of assignment duedate**.
2. In the rule's **Requests** step set **Requests go to:** *Supervisor* for *Assignment duedate extension*.
3. Create two *Request* templates: one with **Send when?** … *Request opened* (subject e.g.
   `Extension requested by <firstname> <lastname> for <targets>`), one with *Request closed*
   (`Your extension request for <targets> was processed`). Attach both to the rule.
4. Assignees now see **Request Prolongation** on their assignment page (`/local/taskflow/assignment.php?id=…`);
   the supervisor receives the *Request opened* mail, confirms or declines in the requests dashboard (or grants the
   extension on the edit-assignment page in the tuines adapter), and the assignee receives the *Request closed* mail.
   Full workflow and what confirming actually changes: [Requests](../requests/README.md).

## Related

* [Rules — Messages step](../rules/04-messages-step.md) — attaching templates and packages to a rule
* [Rules — Requests step](../rules/05-requests-step.md) — enabling request types per rule
* [Requests](../requests/README.md) — request workflow and request notifications
* [Assignments — History](../assignments/04-history.md) — where sent messages are logged
* [Assignments — Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md)
* [Scheduled tasks](../scheduled_tasks/README.md) — `send_taskflow_message`, `notification_internal_messages`
* [Settings](../settings/README.md), [Capabilities](../capabilities/README.md)

## For AI / explain-docs routing

Questions that belong in this chapter: what a message template is, the fields of the message editor, when and to whom
a message is sent, why a message was (not) sent or was sent twice, e-mail vs. Moodle notification, CC behaviour,
placeholders, deputies as recipients, notification preferences, the assignment chat and its daily digest.
Questions about *attaching* messages to a rule or choosing a package inside the rule editor belong to
[Rules — Messages step](../rules/04-messages-step.md). Questions about the *Not relevant / Request Prolongation /
Upload evidence* buttons, who approves them, the requests dashboard and what a confirmed request does belong to
[Requests](../requests/README.md). Questions about the status names themselves belong to
[Assignments — Status lifecycle](../assignments/01-status-lifecycle.md).
