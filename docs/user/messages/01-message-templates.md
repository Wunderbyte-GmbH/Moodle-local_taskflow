[Back to chapter overview](README.md)

# Message templates

A message template is one row in the Taskflow message store. It contains the text (subject and body), the recipients,
the timing and a type. Templates are managed centrally and attached to rules afterwards; the same template can be used
by many rules.

## Where

| Page | URL | Capability |
|---|---|---|
| Template list ("Taskflow Messages") | `/local/taskflow/message_form/editmessage.php` | `local/taskflow:editmessages` |
| Create / edit a template | `/local/taskflow/message_form/editmessage_form.php?id=<id>` (new: `?action=new`) | `local/taskflow:editmessages` |
| Link from the rule editor | Step **Messages** → *Manage messages* (opens the list in a new tab) | — |
| Link from site administration | *Plugins → Local plugins → Wunderbyte Taskflow → Manage messages* | site admin |

### The template list

| Column | Content |
|---|---|
| Name of message | The internal name. |
| Message type | The stored class: `standard`, `onevent`, `request` or `chat` (see *Message types* below). |
| Message Subject | The subject line. |
| Messages priority | `1`, `2` or `3` (Normal / Important / Warning). |
| Message package | Tags of the template, comma separated. |
| Actions | *Edit* and *Delete* (asks "Are you sure to delete this mesage?"; deletes the template — rules that still reference the id simply skip it). |

Without templates the page shows "No messages found. Create a new one below." and the **Create message** button.

## The editor, section by section

All labels below are the English strings of the plugin; adapters may override them.

### Message type

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Message type** | select | *Standard* | *Standard* — time-based or status-based messages to freely chosen recipients. *Request* — sent when a self-service request is opened or closed; recipients come from the rule. *Chat* — sent when an internal chat message is posted; only listed when the setting **Allow internal communication** is on. |

The type controls which of the following sections are shown (hidden sections are still saved with their default
values).

### General Settings for messages

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Name of message** | text | empty | Internal name; shown in the list, in the rule editor's *Messages* picker (falls back to the subject when empty) and in the assignment history entry *Mail send*. |

### Recipient settings (Standard only)

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Recipient(s)** | multi-select, required for Standard | none | *Assignee* — the assignment's user. *Supervisor Overview* — the assignee's supervisor from the profile field with the function *Supervisor*; deputies are added when **Send mails to deputy** is on. *Choose specific user* — one fixed user, selected in the next field. Several entries can be combined. |
| **Specific recipient** | user autocomplete ("Select a user...") | — | The fixed user for *Choose specific user*. |

For *Request* and *Chat* the section is replaced by the note "Message type requires no specific recipient adjustment.
Recipient is being specified inside rule." — the receiver is the rule's request receiver (open request) or the assignee
(closed request), respectively the other chat party.

### CC settings (Standard and Request)

| Field | Type | Default | Meaning |
|---|---|---|---|
| **CC user(s)** | multi-select | none | *Assignee*, *Supervisor Overview*, *Choose specific user for CC*. Every CC user receives a **separate e-mail** with subject `[CC]: <subject> (for: <primary recipients>)` and the grey introduction "This is a copy of the message that was sent to … The original message reads:". CC users get no Moodle notification. The primary recipient's subject is extended to `<subject> (cc: <CC names>)`. |
| **Specific CC-user** | user autocomplete | — | The fixed CC user. |

Not available for *Chat*.

### Message content

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Message Subject** | text, required | empty | E-mail subject and notification title. Placeholders allowed. |
| **Message Body** | HTML editor, required | empty | E-mail body (HTML; a plain-text version is generated automatically) and notification text. Placeholders allowed. |
| *Here you will find a list of all possible placeholders that can be used in messages:* | collapsible list | — | Lists every available placeholder, see [Placeholders](02-placeholders.md). |

### Message settings

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Message package** | tags | none | Free tags (tag collection *Taskflow*). A tag is a *package*: in the rule editor a package preselects all templates carrying that tag. Tags used here are marked as standard tags so they appear in the package picker. |
| **Messages priority** | select, required | *Normal priority* (1) | *Normal priority* (1), *Important priority* (2), *Warning priority* (3). Stored and shown in the list; no other part of the plugin evaluates it. |
| **Send when?** | group | see below | Timing of the message, made up of the elements in the next table. |

#### Send when? — elements of the group

| Element | Options | Meaning |
|---|---|---|
| *Number of timeunits* | integer | The offset. `0` = exactly at the anchor. |
| time unit | *minutes*, *hours*, *days* | Unit of the offset. |
| direction | *before*, *after* | `before` subtracts the offset, `after` adds it. **`before` is only allowed with the anchor *Due date*** — otherwise the form refuses with "Invalid sending combination. We cannot send messages before the assign date". |
| anchor (Standard) | *Assignment date*, *Due date*, *Status change* | *Assignment date* = the assignment's `assigneddate`. *Due date* = the current due date; if the assignment has no due date yet, the message is not scheduled. *Status change* shows the two extra elements below. |
| anchor (Request) | *Request opened*, *Request closed* | Which request event triggers the message. The offset is counted from the moment the request is created/treated. |
| statuses (only with *Status change*) | multi-select of all assignment statuses (minus the adapter's *Do not use status* list) | The statuses that trigger the message / that the assignment must have at sending time. |
| sending condition (only with *Status change*) | *Send always*, *Automatically send*, *Manually send* | *Send always*: regardless of who changed the status. *Automatically send*: only when the engine changed the status (due date passed, target completed, import). *Manually send*: only when a person changed the status on the edit-assignment page. |

Chat templates have no anchor; the offset (usually `0`) is counted from the moment the chat message is posted.

Save with **Save message**; the list shows "Message was saved successfully!".

## Message types and stored classes

The **Message type** you pick in the form is not exactly what is stored. The stored *class* decides how the message is
scheduled:

| Form type + anchor | Stored class | Scheduled when | Sent if |
|---|---|---|---|
| Standard + *Assignment date* / *Due date* | `standard` | Every time the rule is evaluated for the user and a target action runs (assignment created, rule saved, import, cyclic reset). Time = anchor ± offset. | Not yet sent for this user/rule/message; assignment not *Completed*, *Droppedout*, *Paused*, *Not relevant*. |
| Standard + *Status change* (saved with the form) | `standard` | Same as above; time = now + offset. | Not yet sent; current status is one of the selected statuses. Not re-triggered by later status changes — see the note in the [chapter overview](README.md#when-the-triggers-per-message-type). |
| Status-change template with class `onevent` (imported / legacy) | `onevent` | Each time the assignment enters one of the selected statuses and the sending condition matches (automatic vs. manual). | Not yet sent (or **Send manual mails always** is on and the change was manual); current status still in the list. |
| Request + *Request opened* / *Request closed* | `request` | When a request is created / treated on the assignment and the rule includes the template. | Always (no deduplication, no validity check). Receiver: open request → rule's request receiver (supervisor + deputies, or HR); treated request → the assignee. |
| Chat | `chat` | When a chat message is posted on the assignment and the rule includes the template. | Always. Receiver: sender is the assignee → the supervisor; otherwise the sender's own account (see [Internal communication](03-internal-communication.md)). Delivered as Moodle message only. |

Editing a template again maps the stored class back to the form type (`onevent` → Standard; `onrequestcreated` /
`onrequestclosed` → Request). Re-saving an `onevent` template through the form stores it as `standard` again.

## What happens after saving

* Nothing is sent until the template is attached to a rule ([Rules — Messages step](../rules/04-messages-step.md)) and
  the rule produces assignments.
* Changing a template's text affects all future sends (the text is read when the task runs, not when it is queued).
* Changing a template's timing affects assignments that are re-evaluated afterwards (re-saving the rule re-queues the
  tasks with the new time; already sent messages stay sent).
* Deleting a template does not remove it from rules; the rule simply has no matching template any more.

## Related

* [Placeholders](02-placeholders.md)
* [Chapter overview — how and when messages are sent](README.md)
* [Rules — Messages step](../rules/04-messages-step.md)
* [Assignments — Status lifecycle](../assignments/01-status-lifecycle.md) — the statuses offered for *Status change*
