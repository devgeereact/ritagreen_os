# Product requirements — RitaGreen Services Management System

**Status:** baseline, written against the codebase as it stood on 2026-08-31.
**Owner:** Gideon Akinlotan.

---

## 1. What this product is

RitaGreen Services is one company operating four divisions — Care, Academy, Cleaning and
Security. This product is the single operational system behind all four: one company
identity, one worker database, one recruitment pipeline, one compliance engine, one document
repository, one communications centre, one audit trail.

It is a **workforce compliance and operations platform**, not four management systems and not
a website CMS that grew.

The Academy is a **record and enrolment module inside the platform**, not a learning
management system. The platform records who was trained, in what, by whom, when, and when it
expires. Course delivery stays where it is.

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
former worker are *roles that person holds*, not separate records. Today the system can hold
the same human three times over (see `docs/BLUEPRINT.md` §"The identity defect").

**Compliance is calculated, never remembered.** The system derives whether a person may work,
per service, from the evidence on file and the rules configured for that service. No staff
member ticks a box marked "compliant".

**Ineligibility is enforced, not reported.** A person who does not meet the requirements for a
service cannot be assigned to work in it. The system blocks the action and states the reason.

## 4. Users

| User | Sees | Key need |
|---|---|---|
| **Owner** | Everything | "What needs my attention today?" — not a list of records |
| **Staff** | Scoped by role and service | Get through the day's verification and recruitment work without opening fifteen pages |
| **Worker** | Their own record only | Know their own status, upload what is asked, request leave |
| **Client** | Their own account only | Raise a cover request, see who is assigned, see invoices |

The three top-level account types are Owner, Staff and Worker, with Client as a fourth,
externally-facing type. Beneath Staff sit granular roles and per-service scoping — "Staff"
must never mean "full access".

## 5. Principles

1. **Person-centred.** The person is the spine. Everything else hangs off them.
2. **Service-aware.** Care, Academy, Cleaning and Security have genuinely different
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

- Full payroll processing (pay *records* yes; running payroll, no)
- Accounting
- A learning management system — no video hosting, no course authoring, no quizzes, no
  classrooms
- A full CRM
- Full rota management and complex time tracking beyond what already exists
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
