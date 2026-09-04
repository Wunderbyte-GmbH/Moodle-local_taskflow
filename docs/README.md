# local_taskflow — Documentation

Welcome to the documentation for the **Wunderbyte Taskflow** Moodle plugin (`local_taskflow`) by [Wunderbyte GmbH](https://www.wunderbyte.at).

Taskflow turns organisational data (units, supervisors, contract dates) and rules into **assignments**: obligations for a user to complete one or more targets (a booking option, a Moodle course, a competency) by a due date. It tracks the status of every assignment, sends messages, lets employees raise requests (not relevant / extension / evidence) and gives supervisors, HR and administrators dashboards to follow up.

This `docs/` directory is the central reference for administrators, HR staff, supervisors and employees who work with Taskflow. Inside Moodle the same pages are available at `/local/taskflow/documentation.php` (capability `local/taskflow:viewdocumentation`).

---

## Quick-start guide

| I want to… | Go to… |
|------------|--------|
| Understand what Taskflow is and how the pieces fit together | [Getting started](user/getting_started/README.md) |
| Find my way around the dashboards (admin, supervisor, per-user tabs) | [Dashboard](user/dashboard/README.md) |
| Understand what an assignment is and what its status means | [Assignments](user/assignments/README.md) · [Status lifecycle](user/assignments/01-status-lifecycle.md) |
| See or edit one assignment, change its status, read its history | [Assignment detail page](user/assignments/02-assignment-detail-page.md) · [Edit assignment](user/assignments/03-edit-assignment.md) · [History](user/assignments/04-history.md) |
| Understand due dates, overdue, prolongation, pausing | [Due dates, prolongation, overdue](user/assignments/05-due-dates-prolongation-overdue.md) |
| Make a training recur every year (cyclic assignments) | [Cyclic assignments](user/assignments/06-cyclic-assignments.md) |
| Create a rule that assigns a training to a unit or user | [Rules](user/rules/README.md) · [Rule step](user/rules/01-rule-step.md) |
| Restrict a rule to certain users (profile fields, dates) | [Filters](user/rules/02-filters.md) |
| Define what has to be completed (booking option, course, competency) | [Targets](user/rules/03-targets.md) |
| Attach reminders and notifications to a rule | [Messages step](user/rules/04-messages-step.md) · [Messages](user/messages/README.md) |
| Write or edit a message template, use placeholders | [Message templates](user/messages/01-message-templates.md) · [Placeholders](user/messages/02-placeholders.md) |
| Let assignee and supervisor chat about an assignment | [Internal communication](user/messages/03-internal-communication.md) |
| Let employees ask for "not relevant", an extension or upload evidence | [Requests](user/requests/README.md) · [Requests step](user/rules/05-requests-step.md) |
| Understand units, cohorts, supervisors, deputies, HR users | [Units and users](user/units_and_users/README.md) |
| Work with competencies, evidence uploads and certificates | [Competencies and certificates](user/competencies_and_certificates/README.md) |
| Connect Taskflow to an HR data source (adapter) | [Adapters](user/adapters/README.md) · [Standard](user/adapters/standard.md) · [KSW](user/adapters/ksw.md) · [TU Wien INES](user/adapters/tuines.md) |
| Look up an admin setting | [Settings](user/settings/README.md) |
| Set up roles and permissions | [Capabilities](user/capabilities/README.md) |
| Understand the background tasks and caches | [Scheduled tasks](user/scheduled_tasks/README.md) |
| Embed a dashboard or an assignment list on a Moodle page | [Shortcodes](user/shortcodes/README.md) |

Important distinction for AI / explain tasks:

- Questions about **who gets an assignment, when it is due and what must be completed** belong to [Rules](user/rules/README.md) (rule step, filters, targets).
- Questions about **the state of an existing assignment** (assigned, overdue, prolonged, paused, completed, dropped out) belong to [Assignments](user/assignments/README.md), especially the [status lifecycle](user/assignments/01-status-lifecycle.md).
- Questions about **e-mails, reminders and notifications** belong to [Messages](user/messages/README.md). Attaching a template to a rule is described in the [messages step](user/rules/04-messages-step.md).
- Questions about **"not relevant for me", due-date extension or evidence upload by the employee** belong to [Requests](user/requests/README.md). Requests are not messages, although confirming or declining one can trigger a message.
- Questions about the **chat between assignee and supervisor** (and its daily digest) belong to [Internal communication](user/messages/03-internal-communication.md), not to message templates.
- Questions about **importing people, org units, supervisors, contract end or long leave from an HR system** belong to [Adapters](user/adapters/README.md) and the page of the adapter in use.
- Questions about **units, cohorts, supervisor/deputy fields and HR users** belong to [Units and users](user/units_and_users/README.md).

## First admin workflow (click-by-click)

1. Install the plugin and its dependencies (`local_multistepform`, `local_wunderbyte_table`, `mod_booking`), then finish the upgrade under *Site administration → Notifications*.
2. Open the settings page: *Site administration → Plugins → Local plugins → Wunderbyte Taskflow* (`/admin/settings.php?section=local_taskflow_settings`). Choose the adapter (**External api with user data**), the **Organisational unit** model (Units or Cohorts), the **Supervisor role** and the **HR userids**. See [Settings](user/settings/README.md).
3. Map your user profile fields to Taskflow functions (supervisor, deputy, organisational unit, contract end, long leave, external id) in the adapter section of the same page. See [Adapters](user/adapters/README.md).
4. Get people into units: import them through the adapter (`/local/taskflow/view.php` → **Trigger DWH import** / **Upload users**) or add them to cohorts manually. See [Units and users](user/units_and_users/README.md).
5. Create message templates: `/local/taskflow/message_form/editmessage.php`. See [Messages](user/messages/README.md).
6. Create your first rule: `/local/taskflow/editrule.php?id=0` — step **Rule** (who, due date), **Filter**, **Targets**, **Messages**, **Requests**. See [Rules](user/rules/README.md).
7. Open the dashboard `/local/taskflow/index.php` and check the created assignments; run the adhoc tasks (cron) if nothing appears yet. See [Dashboard](user/dashboard/README.md) and [Scheduled tasks](user/scheduled_tasks/README.md).
8. Give supervisors and employees access: assign the capabilities described in [Capabilities](user/capabilities/README.md) and place the [shortcodes](user/shortcodes/README.md) `[myassignments]`, `[supervisorassignments]` and `[requests]` on your pages.

---

## Documentation sections

### User guides

| Directory | Description |
|-----------|-------------|
| [`getting_started/`](user/getting_started/README.md) | What Taskflow is, core vocabulary, how the pieces fit, first workflow, where every page lives |
| [`dashboard/`](user/dashboard/README.md) | The tabbed dashboard on `index.php`: admin tab, supervisor tab, per-user tabs, assignments table, rules and requests dashboards |
| [`assignments/`](user/assignments/README.md) | Assignments: status lifecycle, detail page, edit page, history, due dates and prolongation, cyclic assignments |
| [`rules/`](user/rules/README.md) | Rules: the rule step, filters, targets, messages step, requests step |
| [`messages/`](user/messages/README.md) | Message templates, sending, placeholders, internal communication (chat) |
| [`requests/`](user/requests/README.md) | Self-service requests: not relevant, extension, evidence; who receives them and how they are treated |
| [`units_and_users/`](user/units_and_users/README.md) | Organisational units vs cohorts, hierarchy, membership, supervisor and deputy, HR users, long leave and contract end |
| [`competencies_and_certificates/`](user/competencies_and_certificates/README.md) | Competency targets, evidence upload and review, my certificates |

### Administrator guides

| Directory | Description |
|-----------|-------------|
| [`adapters/`](user/adapters/README.md) | What an adapter is, the field-mapping model, and the pages for [standard](user/adapters/standard.md), [ksw](user/adapters/ksw.md) and [tuines](user/adapters/tuines.md) |
| [`settings/`](user/settings/README.md) | Every admin setting with key, label, default and effect |
| [`capabilities/`](user/capabilities/README.md) | All 14 capabilities, default archetypes and a recommended role setup |
| [`scheduled_tasks/`](user/scheduled_tasks/README.md) | Scheduled tasks, adhoc tasks, caches and when to purge them |
| [`shortcodes/`](user/shortcodes/README.md) | The 6 shortcodes with all arguments, password protection, required capabilities, block_multiblock usage |

### Developer guides

| File | Description |
|------|-------------|
| [`developer-guides/ARCHITECTURE_OVERVIEW.md`](developer-guides/ARCHITECTURE_OVERVIEW.md) | How events, observers, adhoc tasks and the assignment pipeline fit together |
| [`developer-guides/ADAPTER_API.md`](developer-guides/ADAPTER_API.md) | Writing a `taskflowadapter_*` subplugin |
| [`developer-guides/RULE_JSON_FORMAT.md`](developer-guides/RULE_JSON_FORMAT.md) | The stored rule JSON |

---

## Contributing to documentation

- All documentation is written in Markdown (English).
- Each feature has its own subdirectory under `docs/user/`; sub-pages are numbered (`01-…`, `02-…`).
- Every link inside `docs/user` is relative and stays inside `docs/user`, so the pages work both on GitHub and in the in-Moodle viewer (`/local/taskflow/documentation.php?file=user/…`).
- Use the real UI wording from the language strings; put the language key next to it only where the text is ambiguous.
- No screenshots.

---

## Related resources

- [Wunderbyte website](https://www.wunderbyte.at)
- [GitHub repository](https://github.com/Wunderbyte-GmbH/moodle-local_taskflow)
- Related plugins: [mod_booking](https://github.com/Wunderbyte-GmbH/moodle-mod_booking), [local_wunderbyte_table](https://github.com/Wunderbyte-GmbH/moodle-local_wunderbyte_table), [local_multistepform](https://github.com/Wunderbyte-GmbH/moodle-local_multistepform)
