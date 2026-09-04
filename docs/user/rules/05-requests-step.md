[Back to chapter overview](README.md)

# Step "Requests" — self-service requests per rule

A **request** lets the assignee ask for an exception directly from the assignment page: that the assignment is *not relevant* for them, that the *due date is extended*, or that a *certificate/evidence* is accepted instead of completing a target. This step decides, **per rule and per request type**, whether the request is allowed and **who decides** it.

## Prerequisite: enable request types globally

Only request types that are switched on in Site administration → Plugins → Local plugins → Wunderbyte Taskflow → *Taskflow Request Settings* appear in this step:

| Setting (UI label) | Key | Request type shown in the step |
|--------------------|-----|--------------------------------|
| **Allow user to request assignment not-relevant status** | `allowselfnotrelevant` | *Assignment not-relevant status* |
| **Allow user to request extension of assignment duedate** | `allowselfextension` | *Assignment duedate extension* |
| **Allow upload evidence of competencies by users** | `allowuploadevidence` | *Upload evidence of competencies* |

If none is enabled the step is empty. See [Settings](../settings/README.md).

## Fields

For every enabled request type the step shows its title followed by one select:

| Field (UI label) | Options | Default | Meaning |
|------------------|---------|---------|---------|
| **Requests go to:** | **Not allowed**, **Supervisor**, **HR** | Not allowed | *Not allowed* — the button for this request is not offered on assignments of this rule. *Supervisor* — the request is sent to the assignee's supervisor (plus their deputies when the setting *Mails to supervisor are also forwarded to deputy* is on) and appears in the supervisor's requests dashboard. *HR* — the request is sent to the users listed in the setting **HR userids** (`hrusers`). |

The receiver is stored per request type in the rule (`0` = Supervisor, `1` = HR, `not_allowed`). Existing assignments read the setting from the rule at the time the request is raised, so changing the receiver takes effect immediately for all assignments of the rule.

## What each request type does

| Request type | Raised by the assignee via | Receiver decides | Effect when **confirmed** | Effect when **declined** |
|--------------|----------------------------|------------------|---------------------------|--------------------------|
| **Assignment not-relevant status** | Button on the assignment page, form with a comment; the notice tells the user who will review ("Your Supervisor/HR will review your request…") | Supervisor / HR | Assignment status → **Not relevant** (inactive; dates cleared). | Assignment unchanged. |
| **Assignment duedate extension** | Button *Request Prolongation*, comment | Supervisor / HR | Recorded as confirmed; the actual new due date is set by the reviewer when editing the assignment (status *Prolonged*, extension period). See [../assignments/05-due-dates-prolongation-overdue.md](../assignments/05-due-dates-prolongation-overdue.md). | Assignment unchanged. |
| **Upload evidence of competencies** | Upload form (title, description, URL, files, *Valid until date*) on an assignment with a **competency** target | Reviewer sets the evidence status | Evidence **Approved** → the competency counts as achieved for the target (until *Valid until date*), assignment can complete. | **Rejected** → competency removed again; *Under review* keeps it pending. |

An assignee can have only one open request of a type per assignment (a second attempt is refused as duplicate). Raising a request requires the capability `local/taskflow:createrequests`; deciding requires `local/taskflow:treatrequests`.

Notifications: when a request is created, request-type templates with *Request opened* that are attached to the rule ([04-messages-step.md](04-messages-step.md)) are sent to the receiver; when it is decided, *Request closed* templates go to the assignee. Both outcomes are written to the assignment history.

The full end-to-end flow, the requests dashboard, the `requests` shortcode and the columns/actions for reviewers are described in [../requests/README.md](../requests/README.md).

## Quick recipes

- **Let supervisors waive irrelevant trainings**: enable *Allow user to request assignment not-relevant status*; in the rule set *Assignment not-relevant status* → **Supervisor**.
- **Central HR handles all extensions**: enable *Allow user to request extension of assignment duedate*; set *Assignment duedate extension* → **HR**; enter the HR user ids in **HR userids**.
- **Accept external certificates for a competency**: rule with a *Competency* target; enable *Allow upload evidence of competencies by users*; set *Upload evidence of competencies* → **Supervisor** or **HR**.

## Related

- [../requests/README.md](../requests/README.md) — reviewing requests
- [../competencies_and_certificates/README.md](../competencies_and_certificates/README.md) — evidence review and certificates
- [../units_and_users/README.md](../units_and_users/README.md) — how supervisors, deputies and HR users are defined
- [../capabilities/README.md](../capabilities/README.md)
