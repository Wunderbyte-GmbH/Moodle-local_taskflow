[Back to user documentation index](../README.md)

# Getting started — What Taskflow is and how it fits together

**Wunderbyte Taskflow** (`local_taskflow`) is a Moodle local plugin that manages *obligatory trainings*. An organisation defines **rules** ("everyone in unit X must complete training Y within 4 weeks"); Taskflow applies these rules to the members of the organisational units, creates one **assignment** per user and rule, enrols or books the user into the **targets**, watches completion, tracks a **status** (assigned, overdue, completed …), sends **messages**, and lets employees raise **requests** (not relevant, extension, evidence upload) which supervisors or HR treat. Organisational data (units, supervisors, contract dates) can be imported from an HR system through an **adapter**.

Taskflow depends on `mod_booking` (booking options as targets), `local_wunderbyte_table` (all tables) and `local_multistepform` (the rule editor).

---

## Quick path

1. Configure the plugin: *Site administration → Plugins → Local plugins → Wunderbyte Taskflow* (`/admin/settings.php?section=local_taskflow_settings`) — see [Settings](../settings/README.md).
2. Get users into units (adapter import or cohorts) — see [Units and users](../units_and_users/README.md).
3. Create message templates: `/local/taskflow/message_form/editmessage.php` — see [Messages](../messages/README.md).
4. Create a rule: `/local/taskflow/editrule.php?id=0` — see [Rules](../rules/README.md).
5. Check the result on the dashboard: `/local/taskflow/index.php` — see [Dashboard](../dashboard/README.md).

---

## Table of contents

1. [Core vocabulary](#1-core-vocabulary)
2. [How the pieces fit together](#2-how-the-pieces-fit-together)
3. [First admin workflow (click-by-click)](#3-first-admin-workflow-click-by-click)
4. [Where everything lives (pages and URLs)](#4-where-everything-lives-pages-and-urls)
5. [Who sees what](#5-who-sees-what)
6. [Related](#6-related)

---

## 1. Core vocabulary

| Term | Meaning | Details |
|------|---------|---------|
| **Unit** (organisational unit) | A group of users a rule can be attached to. Depending on the setting **Organisational unit** a unit is either a Taskflow-own unit record or a Moodle **cohort**. Units can have parent units (hierarchy). | [Units and users](../units_and_users/README.md) |
| **Rule** | The definition of an obligation: *who* (a unit, a target group or one specific user), optional *filters*, the *targets* to complete, the *due date* logic, the attached *messages* and the allowed *requests*. Stored as JSON, edited in a multi-step form. Only active (enabled) rules are applied. | [Rules](../rules/README.md) |
| **Filter** | A condition on a Moodle user field or a custom profile field (e.g. `contractstart` older than 90 days). A user only gets an assignment while they match all filters of the rule. | [Filters](../rules/02-filters.md) |
| **Target** | What the user must complete: a **booking option**, a **Moodle course** or a **competency**. Taskflow enrols/books the user and detects completion per target type. | [Targets](../rules/03-targets.md) |
| **Assignment** | One rule applied to one user: the record that carries targets, status, active flag, assigned date, due date, counters and history. Shown in all dashboards and tables. | [Assignments](../assignments/README.md) |
| **Status** | The state of an assignment, e.g. *Assigned*, *Enrolled*, *Prolonged*, *Overdue*, *Completed*, *Paused*, *Droppedout*, *Not relevant*, *Planned*. Each status also decides whether the assignment counts as *active*. | [Status lifecycle](../assignments/01-status-lifecycle.md) |
| **Message** | A reusable template (subject, body, sending settings) that a rule attaches. Sent as e-mail (with optional CC) and Moodle notification at the configured time or event. | [Messages](../messages/README.md) |
| **Request** | A self-service ticket an employee raises about one assignment: *Not relevant for me*, *Request Prolongation* or *Evidence* upload. Routed to the supervisor (and deputy) or to HR, who confirm or decline it. | [Requests](../requests/README.md) |
| **Supervisor / Deputy** | The supervisor of a user is the Moodle user whose id is stored in the user's supervisor profile field; supervisors automatically get the configured **Supervisor role**. A deputy is listed in the supervisor's deputy profile field and sees the same team. | [Units and users](../units_and_users/README.md) |
| **HR users** | Moodle user ids listed in the setting **HR userids**; they receive requests that a rule routes to HR. | [Settings](../settings/README.md), [Requests](../requests/README.md) |
| **Adapter** | A `taskflowadapter_*` subplugin that understands one external HR data format and customer-specific behaviour (import, field mapping, dashboards, forms, strings). Exactly one adapter is active (**External api with user data**). Shipped: *Standard API*, *KSW API*, *Ines API* (TU Wien). | [Adapters](../adapters/README.md) |
| **Internal communication** | The chat between assignee and supervisor on an assignment, with read tracking and a daily digest. | [Internal communication](../messages/03-internal-communication.md) |
| **History** | The append-only audit trail of an assignment (status changes, mails sent, course completions, requests …). | [History](../assignments/04-history.md) |

---

## 2. How the pieces fit together

```
 HR system / JSON upload / cohorts
            │  (adapter import, manual upload, cohort membership)
            ▼
   Users + profile fields + Units (or cohorts) + supervisor role
            │
            │  Rule saved / member added / user updated / daily re-check of date filters
            ▼
   Rule  ──►  Filters  ──►  one ASSIGNMENT per matching user
  (who,        (still         status: Planned → Assigned
   due date,    matches?)     │
   targets,                   ├─► enrol / book the user into the TARGETS
   messages,                  ├─► schedule MESSAGES (before/after start or due date, on status change)
   requests)                  └─► schedule the due-date check
                                       │
        completion events              │  due date reached
   (course completed, booking          ▼
    completed, competency rated,   Enrolled / At least one target completed / Completed
    evidence approved)             or Prolonged / Overdue (counters increase)
                                       │
   employee raises REQUEST ──► supervisor / HR confirms or declines ──► status Not relevant,
                                                                        due date extended,
                                                                        competency granted
   membership lost / filter fails ──► Droppedout       long leave / contract end ──► Paused
   cyclic rule: after the validity period the assignment is reopened (Assigned again)
```

Everything after "rule saved" happens through Moodle events and **adhoc tasks**, so changes become visible after cron has run (see [Scheduled tasks](../scheduled_tasks/README.md)).

---

## 3. First admin workflow (click-by-click)

1. **Install** `local_taskflow` together with `local_multistepform`, `local_wunderbyte_table` and `mod_booking`; finish under *Site administration → Notifications*. The upgrade creates the locked profile fields `unit_info`, `tissid_info`, `organisational_unit_info`, `end_info` and a role with shortname `supervisor`.
2. **Enable the shortcode filter** (*Site administration → Plugins → Filters → Manage filters*) if you want to place Taskflow tables on pages — see [Shortcodes](../shortcodes/README.md).
3. **Open the settings page** `/admin/settings.php?section=local_taskflow_settings` and set at least:
   - **External api with user data** — the adapter (default *Standard API*).
   - **Organisational unit** — *Units* or *Cohorts*.
   - **Supervisor role** — the role automatically given to supervisors.
   - **HR userids** — comma-separated Moodle user ids.
   - In the adapter section (e.g. **Standard API Settings**): for every custom profile field the JSON key and the *function* (supervisor, deputy, organisational unit, contract end, long leave, external id …).
   Full reference: [Settings](../settings/README.md), [Adapters](../adapters/README.md).
4. **Bring users into units.** Either upload a JSON file (`/local/taskflow/view.php` → **Upload users**), trigger the adapter import (**Trigger DWH import**), or — with *Cohorts* and **Cohort enrollment** enabled — add users to cohorts manually. See [Units and users](../units_and_users/README.md).
5. **Create message templates** at `/local/taskflow/message_form/editmessage.php` (subject, body with placeholders such as `<firstname>` or `<due_date>`, sending settings). See [Message templates](../messages/01-message-templates.md).
6. **Create a rule** at `/local/taskflow/editrule.php?id=0`:
   1. Step **Rule**: name, description, **Rule type** (*Rule for unit* / *Rule for target group* / *Rule for specific user*), the unit or user, **Due date type** (*Duration* or *Fixed date*), optionally **Delay of activation**, **Extension period**, **Does rule need cyclic validation?**, and **Enable rule**.
   2. Step **Filter**: optional conditions on user fields.
   3. Step **Targets**: add one or more targets (**Booking option**, **Moodle course**, **Competency**); tick *Complete before enrol to next* for sequential targets.
   4. Step **Messages**: pick templates.
   5. Step **Requests**: allow *Not relevant*, *Prolongation*, *Evidence* and choose whether they go to the **Supervisor** or **HR**.
   Save. See [Rules](../rules/README.md).
7. **Let cron run** (or run the adhoc tasks manually). Then open `/local/taskflow/index.php` — the admin tab lists the new assignments. See [Dashboard](../dashboard/README.md).
8. **Set up roles**: give HR the manager-like Taskflow capabilities, supervisors `local/taskflow:issupervisor` (the automatic supervisor role already has it), and keep `local/taskflow:createrequests` for everyone who may raise requests. See [Capabilities](../capabilities/README.md).
9. **Place shortcodes** for employees and supervisors, e.g. `[myassignments]`, `[supervisorassignments]`, `[requests]`, `[assignmentsavailability hrefmy="/my/" hrefsv="/my/"]`. See [Shortcodes](../shortcodes/README.md).

---

## 4. Where everything lives (pages and URLs)

| Page | URL | Who | What |
|------|-----|-----|------|
| Dashboard | `/local/taskflow/index.php` | any logged-in user; tabs depend on capabilities | Tabbed dashboard: **Admin- Dashboard**, **Supervisor**, one closable tab per selected user (user search). [Dashboard](../dashboard/README.md) |
| Overview / import page | `/local/taskflow/view.php` | logged-in users; buttons for admins | Buttons **Trigger DWH import** and **Upload users**, plus *My Assignments* and *Assignments for the supervisor*. |
| Assignment detail | `/local/taskflow/assignment.php?id=<assignmentid>` | assignee, their supervisor, or `local/taskflow:viewassignment` | Targets with completion, due date, supervisor contact, request buttons, evidence, possible courses, internal chat. [Assignment detail page](../assignments/02-assignment-detail-page.md) |
| Edit assignment | `/local/taskflow/editassignment.php?id=<assignmentid>` | supervisor of the assignee or `local/taskflow:viewassignment`; admin variant with `local/taskflow:editassignment` | Assignment data, status change form, comment, chat, history. [Edit assignment](../assignments/03-edit-assignment.md) |
| Rule editor | `/local/taskflow/editrule.php?id=<ruleid>` (`id=0` = new) | `local/taskflow:createrules` | Multi-step rule form. [Rules](../rules/README.md) |
| Message templates | `/local/taskflow/message_form/editmessage.php` | `local/taskflow:editmessages` | Manage message templates. [Messages](../messages/README.md) |
| My certificates | `/local/taskflow/mycertificates.php?userid=<id>` | the user, or `tool/certificate:viewallcertificates` | Certificates issued via `tool_certificate`. [Competencies and certificates](../competencies_and_certificates/README.md) |
| Settings | `/admin/settings.php?section=local_taskflow_settings` | site administrators | All plugin and adapter settings. [Settings](../settings/README.md) |
| Documentation | `/local/taskflow/documentation.php?file=<path>` | `local/taskflow:viewdocumentation` | These pages inside Moodle. |
| Scheduled tasks | `/admin/tool/task/scheduledtasks.php` | site administrators | Taskflow's scheduled tasks. [Scheduled tasks](../scheduled_tasks/README.md) |

The dashboards can also be embedded anywhere with [shortcodes](../shortcodes/README.md); with `block_multiblock` the assignment pages return to the right tab.

---

## 5. Who sees what

| Role | Typical access |
|------|----------------|
| **Employee (assignee)** | Own assignments (`[myassignments]`, assignment detail page), own requests, chat with the supervisor, evidence upload, certificates. Needs `local/taskflow:createrequests` (default for the *user* archetype). |
| **Supervisor** | Assignments and requests of the direct team (and of teams where they are deputy), supervisor dashboard tab, supervisor edit form. Needs `local/taskflow:issupervisor` — granted automatically through the **Supervisor role**. |
| **HR / administrator** | Admin dashboard tab, all assignments, all requests (`all=1`), rules, message templates, edit forms, downloads. Manager archetype has all Taskflow capabilities by default. |

Details: [Capabilities](../capabilities/README.md).

---

## 6. Related

- [Dashboard](../dashboard/README.md)
- [Rules](../rules/README.md)
- [Assignments](../assignments/README.md)
- [Adapters](../adapters/README.md)
- [Settings](../settings/README.md)

---

**For AI / explain-docs routing:** this chapter answers *"what is Taskflow / what is a rule, assignment, target, request, adapter / where do I click first / which page is which URL"*. For the meaning of a specific status go to [Status lifecycle](../assignments/01-status-lifecycle.md); for a specific setting to [Settings](../settings/README.md); for a specific rule-form field to [Rules](../rules/README.md); for e-mails to [Messages](../messages/README.md); for "not relevant / extension / evidence" to [Requests](../requests/README.md); for HR imports to [Adapters](../adapters/README.md).
