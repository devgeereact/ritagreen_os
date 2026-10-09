# System and features

## Working principles

People should not have to understand the database to do their job. Use plain labels, short forms, useful defaults and a clear next action. Retain the existing architecture and reusable components. First verify existing features, then repair or simplify them before adding alternatives.

P1 means essential for the first coherent operational release. P2 means a small addition after core journeys work. Later means preserve any useful existing function but defer new development. These priorities do not imply that features are currently missing.

## Recommended feature inventory

| ID | Priority | Feature | Minimum useful scope |
|---|---|---|---|
| F01 | P1 | Staff record | One identity, contact details, address, roles, services, employment status and start date |
| F02 | P1 | Emergency contacts | Name, relationship, telephone and optional second contact, restricted to authorised office staff and the individual |
| F03 | P1 | Recruitment pipeline | New, reviewing, interview, checks, ready to onboard, hired, declined, withdrawn; owner, next action and date |
| F04 | P1 | Onboarding checklist | References, right to work, role checks, agreement, policy acknowledgement and induction, linked to evidence |
| F05 | P1 | Documents | Protected uploads, document type, reviewed status, verifier, date, renewal where applicable and replacement history |
| F06 | P1 | Compliance overview | Missing, awaiting review, valid, due soon, expired or not applicable; derived per role/service |
| F07 | P1 | Training records | Course, provider, completion, assessment evidence, certificate, renewal rule and due list |
| F08 | P1 | Rota | Day/week view, site, role, worker, start/end, break, draft/published state and status |
| F09 | P1 | Availability and absence | Worker availability and leave requests; office approval; no automatic employment entitlement calculation without agreed rules |
| F10 | P1 | Assignment checks | Required evidence, overlapping shifts, availability and configurable working-time warnings, checked again on the server |
| F11 | P1 | Shift changes | Offer, accept/decline, cancellation reason, reassignment and notification history |
| F12 | P1 | Staff portal | Next shift, rota, site instructions, own documents, training due, availability and contact office |
| F13 | P1 | Timesheets | Actual start/end, breaks, worker submission, office approval, corrections and export |
| F14 | P1 | Clients and sites | Organisation, contacts, service, location, access instructions, requirements and linked assignments |
| F15 | P1 | Enquiries and follow-up | Separate care, staffing, cleaning, security, training and recruitment queues; owner and next action |
| F16 | P1 | Quotes and contracts | Service scope, agreed rates, dates, relevant documents and renewal reminders |
| F17 | P1 | Cleaning delivery | Recurring jobs, assigned team, checklist, supervisor review and optional safe completion photos |
| F18 | P1 | Direct-care coordination | Restricted operational client reference, assigned team, visit times and missed-visit escalation; agreed care-system boundary |
| F19 | P1 | Incidents and complaints | Category, urgency, restricted details, owner, actions and closure record; clear escalation outside the app |
| F20 | P1 | Alerts and tasks | Shift changes, expiring evidence, overdue checks and unanswered enquiries; avoid repeated noise |
| F21 | P1 | Access and audit | Role-scoped server checks, own-record access, protected files, change history and account offboarding |
| F22 | P1 | Website content | Published news, role form destinations, social URLs, approved contact details and reviewed service copy |
| F23 | P2 | Training administration | Session date, trainer, venue, capacity, attendees, attendance, outcome and certificate issue |
| F24 | P2 | Client self-service | Own cover requests and approved timesheets; add only if clients will use it |
| F25 | P2 | Finance hand-off | Approved hours, agreed rates, invoice drafts and payroll/accounting export; reconcile corrections |
| F26 | P2 | Basic reports | Unfilled shifts, ready-to-work staff, evidence due, recruitment funnel, approved hours and enquiry follow-up |
| F27 | P2 | Policies and acknowledgements | Versioned handbook/site policy, acknowledgement date and reminders |
| F28 | P2 | Equipment register | Uniform/key issue and return only where operationally useful |
| F29 | P2 | Feedback | Short client feedback and staff concern form routed to an owner |
| F30 | P1 | Recovery and support | Clear errors, failed-notification queue, backups with restore evidence, help contact and basic administrator runbook |

No LMS, video course player, automated candidate ranking, bespoke payroll engine, native mobile app, full social publishing suite, complex workflow builder, route optimisation or continuous location tracking. Existing AI, payment and communications features should be assessed for real use rather than expanded by default.

## Main journeys

### Recruit and onboard

Role page → approved form → reviewed candidate intake → interview → evidence requests → verification → link worker role to existing person → invite to staff portal. A submitted application never makes someone ready to work. Preserve source and application dates. Potential duplicate matches go to review; shared names or email addresses must not trigger a blind merge.

### Keep staff ready

Staff member sees a due item → uploads replacement → office reviews → system recalculates role eligibility → relevant reminders close. An uploaded certificate stays awaiting review until verified. A rejected document states what needs correcting.

Record DBS check level, workforce, issue/check dates and review requirements. Do not treat every DBS certificate as having a universal legal expiry date or request the same check for every job. Eligibility depends on the role: [DBS employer guidance](https://www.gov.uk/guidance/dbs-check-requests-guidance-for-employers). Record right-to-work evidence and follow-up using the applicable [Home Office checking process](https://www.gov.uk/check-job-applicant-right-to-work). For licensable security roles record and verify the correct [SIA licence](https://www.gov.uk/check-a-private-security-licence). Professional registration, such as NMC, is required in the checklist where the actual role calls for it; verify the relevant current provider guidance during implementation.

### Schedule and work

Office creates a shift/job/visit → selects a site and role → system checks suitability → publishes or offers assignment → worker confirms → worker receives location and instructions → records actual hours → timesheet reviewed. Preserve overnight shifts, Europe/London daylight-saving behaviour, breaks and amendments. Changes after publication tell affected people what changed.

One scheduling engine supports healthcare shifts, cleaning jobs and security assignments with different labels and required fields. Recurring direct-care visits must also account for visit duration, realistic travel gaps and an accountable missed-visit escalation owner. Start with manual sequencing and travel allowances, not a routing algorithm.

### Respond to a concern

Worker or client reports concern → designated person receives it → records triage and actions → follows the company's safeguarding or emergency process → closes with evidence. Do not present an unmonitored in-app panic button as an emergency service. Verify the existing alert path before retaining emergency language.

## Access model

| Role | Recommended access |
|---|---|
| Owner/admin | Configuration, authorised operational oversight and access management |
| Recruitment/compliance | Candidates, relevant staff records and evidence; sensitive fields limited to need |
| Scheduler/operations | Ready-to-work status, availability, assignments and site instructions; no routine bank, DBS detail or health-record access |
| Finance | Approved hours, pay/billing data and finance documents; no routine clinical or vetting files |
| Training coordinator | Course sessions, attendance and relevant training records |
| Content editor | Public content, form destinations and social links; no workforce data |
| Worker | Own assignments, own records, own requests and appropriate site instructions |
| Client, if enabled | Own organisation's requests and approved records only |

Map these responsibilities to the current role system before creating new roles. Verify access on list, detail, export and file endpoints. Restrict direct-care client information more tightly than ordinary site data. No patient details in public content, social posts or open group messages.

## First useful dashboard

Show today's rota, unfilled assignments, documents needing review, evidence due, new applicants and enquiries needing action. Every figure opens a filtered list. Avoid decorative charts and totals that do not lead to a task.

Success is demonstrated by a reviewed candidate becoming a worker without duplicate identity, a blocked unsuitable assignment, a published shift visible to only the right worker, a corrected timesheet with history and a public content change appearing without exposing a draft.
