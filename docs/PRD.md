# Product requirements — RitaGreen Services Management System

**Status:** baseline of 2026-08-31, revised on 10 October 2026 for the owner's 2026 decisions
([`docs/DECISIONS-2026.md`](DECISIONS-2026.md)).
**Owner:** Gideon Akinlotan.

---

## 1. What this product is

RitaGreen Services Ltd trades as one brand, **RitaGreen Services**, with four services shown
publicly as **Healthcare, Cleaning, Security and Training** (decision D01). Inside the system
the services keep their stored identifiers `care`, `cleaning`, `security` and `academy`; the
public labels "Care" and "Academy" are retired. This product is the single operational system
behind all four: one company identity, one worker database, one recruitment pipeline, one
compliance engine, one document repository, one communications centre, one audit trail, plus
the public website that feeds it.

| Service (internal id) | Who buys | What RitaGreen supplies | Roles |
|---|---|---|---|
| **Healthcare** (`care`), the lead service | Care homes, nursing homes, NHS and private hospitals, day centres, supported living providers; families arranging care | Staffing, planned and short-notice cover. Second: supported living and home care, offered through a page and an enquiry route (D02) | Care assistant, support worker, senior carer, registered nurse |
| **Cleaning** (`cleaning`) | Landlords and letting agents, schools, construction sites, offices and commercial premises, care settings | Contract cleaning: regular, communal, void and end of tenancy, builders' cleans, infection-control cleaning | Cleaner, team leader, supervisor |
| **Security** (`security`) | Companies, sites, events, residential blocks | Licensed officers: static guarding, patrols, door supervision, concierge | Security officer, door supervisor, supervisor |
| **Training** (`academy`) | Care providers, RitaGreen's own staff, individuals | Instructor-led courses, in person or through an external provider | Trainer |

Healthcare leads navigation, the homepage and the social profiles. The checks each service
needs, and what the system enforces, are in [`docs/UK_GOVERNANCE.md`](UK_GOVERNANCE.md), "Role
checks per service". Company facts the owner has still to confirm (registered address, CQC
position, office hours and others) are listed in [`docs/ROADMAP.md`](ROADMAP.md).

It is a **workforce compliance and operations platform**, not four management systems and not
a website CMS that grew.

Training is a **record and enrolment module inside the platform**, not a learning management
system. The platform records bookings, attendance, who was trained, in what, by whom, when, and
when it expires. Course delivery stays in the room or with an external provider.

## 2. The problem

The company's problem is not that it has four websites. It is **fragmentation**. Worker
information lives across websites, Google Forms, Gmail, spreadsheets, Google Drive, paper,
certificates, WhatsApp, and individual staff members' memories.

The cost of that fragmentation is that nobody can answer these questions quickly or with
evidence:

- Has this person actually been verified, and by whom?
- When does their right to work expire?
- Is their mandatory training still valid?
- Does this security officer still hold an active SIA licence?
- Has their contract been signed?
- Where is their certificate?
- Which services are they approved to work in?

Each of those is an operational question with a legal edge. Answering them from memory or
from a spreadsheet is the risk this product exists to remove.

## 3. Outcome

Three things must become true.

**One permanent record per human.** A person exists once. Candidate, worker, learner and
former worker are *roles that person holds*, not separate records. The system used to hold the
same human up to three times over; that is fixed (see `docs/BLUEPRINT.md`, "The identity defect").

**Compliance is calculated, never remembered.** The system derives whether a person may work,
per service, from the evidence on file and the rules configured for that service. No staff
member ticks a box marked "compliant".

**Ineligibility is enforced, not reported.** A person who does not meet the requirements for a
service cannot be assigned to work in it. The system blocks the action and states the reason.

## 4. Users

| User | Uses | Sees | Key need |
|---|---|---|---|
| **Owner** | Office app at `/app`, Today and Reports | Everything | See blockers, approvals and expiring checks at a glance, not a list of records |
| **Office staff** | Office app at `/app` | Scoped by role and service, sensitive zones only where granted | One list of what needs doing today; one record per person |
| **Worker** | Staff portal at `/m` | Their own record only | Know where to be, keep documents in date, ask for leave |
| **Client organisation** | Website and enquiry forms; client portal at `/portal`, restricted until its future is decided | Their own account only | Get reliable staff quickly and know they are checked |
| **Family arranging care** | Website, care enquiry | Nothing in the system | A human conversation, not a sales form |
| **Job seeker** | Website careers pages and the linked Google Forms | Nothing in the system | Find a role and apply in minutes on a phone |

One person can be an applicant, a worker in more than one service and a training attendee at
once. They exist once, and approval for one service never approves them for another.

The three top-level account types are Owner, Staff and Worker, with Client as a fourth,
externally-facing type. Beneath Staff sit granular roles and per-service scoping — "Staff"
must never mean "full access".

## 5. Principles

1. **Person-centred.** The person is the spine. Everything else hangs off them.
2. **Service-aware.** Healthcare, Cleaning, Security and Training have genuinely different
   requirements. One person may be eligible for some and not others, simultaneously.
3. **Compliance-driven.** Status is derived from evidence. Rules are configured by the
   business, not hard-coded by a developer.
4. **Evidence over assertion.** "Verified" without a verifier, a date and a document is not
   verification.
5. **The server is the source of truth.** The browser never holds authoritative compliance
   state, online or offline.
6. **No silent loss.** Employment records are archived, never hard-deleted by ordinary staff.

## 6. Non-goals

Explicitly out of scope. These are how this product turns into an unfinishable one.

- Full payroll processing (pay *records* and the pay-run export yes; running payroll, no)
- Accounting
- A learning management system: no lessons, quizzes, video hosting, course authoring,
  classrooms or learner progress
- Care plans and medication records. The rota schedules healthcare shifts and records clock-in; it does
  not hold clinical or care-planning information (D02)
- A full CRM
- Route optimisation and live location tracking
- Native app-store apps (the staff portal is an installable web app)
- Automatic social posting, and a page builder for the website
- Multi-company or multi-tenant features
- AI recruitment scoring or automated hiring decisions
- Biometric monitoring or employee surveillance

Some of these may become separate products later. None of them are this one.

## 7. Compliance positioning

The system is designed to **support** compliance with UK requirements and the company's own
policies. It does not, and must not be described as, making the company compliant.

Requirements change. Home Office right-to-work guidance, DBS guidance, SIA requirements, Acas
guidance and ICO expectations all move. Therefore **compliance rules are configuration, not
code**, and the company's management and legal advisers own the policy values. See
`docs/UK_GOVERNANCE.md`.

## 8. What success looks like

- A named worker's full compliance position can be produced, with evidence, in under a minute.
- No assignment can be made to an ineligible worker.
- No credential expires without someone having been told at 90, 60 and 30 days.
- A person recruited through any intake route exists exactly once in the database.
- An auditor's request is answered by exporting a pack, not by assembling one.
