# UK governance framework

**Status:** framework, not legal advice.

---

## How to read this document

This document describes **how the system is built to support** the company's UK obligations.
It does not state what those obligations are, and nothing in it is legal advice.

Three rules govern everything below.

**1. The system supports compliance. It does not create it.**
Using this software does not make RitaGreen Services compliant with anything. It gives the
company a place to record evidence, a schedule for reviewing it, and a gate that prevents a
worker being deployed without it. Interface copy, documents and any marketing claim must
reflect that. See `docs/RULES.md` rule 22.

**2. Policy values are configuration, owned by the business.**
Retention periods, recheck intervals, which credentials are mandatory for which service, alert
thresholds — none of these are hard-coded. They are settings, and the company's management and
legal advisers own their values. Guidance changes; software that hard-codes guidance goes stale
silently. See `docs/RULES.md` rule 23.

**3. Verify against the source before relying on any specific requirement.**
Guidance in every area below is revised periodically. This document deliberately does **not**
state version numbers, effective dates or specific durations, because a stale figure written
here would be trusted. Check the primary source, then set the value in Settings.

## Primary sources

| Area | Source |
|---|---|
| Right to work checks | Home Office — *Right to work checks: an employer's guide*, gov.uk |
| SIA licensing | *Check security staff have a licence to work*, gov.uk · Security Industry Authority |
| DBS checks and the Update Service | Disclosure and Barring Service, gov.uk |
| Employment particulars | Acas — *What must be written in an employment contract* |
| Holiday records | Acas — *Holiday entitlement: keeping records* |
| Data protection in employment | ICO — *Employment information*, including recruitment and selection, keeping employment records, and monitoring workers |
| Care sector standards | Care Quality Commission · Skills for Care |

---

## Right to work

**Current state: a boolean.** `workers.right_to_work TINYINT(1)`. No method, date, verifier,
evidence or expiry. This is the weakest point in the system and Milestone 3 exists to fix it.

**Target.** Right to work becomes a check record, not a flag:

| Field | Purpose |
|---|---|
| Check type and method | How the check was performed |
| Check date | When |
| Reference | Where the process produces one |
| Evidence | A document in the vault, with its own metadata |
| Outcome | The result of the check |
| Verifier | Which named user performed it |
| Follow-up required | Whether a further check is due |
| Recheck due date | When, driving the alert engine |
| Audit trail | Every change to any of the above |

The check **types, methods and recheck intervals are configurable**, because the permitted
methods change. The system stores what was done and when; it does not encode a rule about what
counts as valid.

Right to work is a mandatory requirement in every service's rule set, and its absence or
expiry blocks deployment. That is a hard default, though the requirement catalogue is
configurable like every other.

## SIA licensing — Security

Security work requires the correct licence for the role. The system therefore treats SIA
verification as a **workflow with evidence**, not a field on the worker record.

Captured: licence number, licence type, status, issue and expiry dates, the role it covers,
verification date, the named verifying officer, and the evidence.

Already built: `workers.sia_number` and `sia_expiry`; `server/scheduling.php` already refuses
to match or assign a Security worker whose SIA licence has lapsed; `server/expiry-alerts.php`
already tracks SIA expiry through the 90/60/30/expired tiers; `server/reports.php?action=sia_acs`
produces an evidence pack.

Missing: the verification workflow itself. A licence number typed into a field is not evidence
that anyone checked the register. Milestone 3 adds the check record — who verified, against
what, on what date, with what outcome.

## DBS — Care

Captured today: `dbs_status`, `dbs_expiry`, `dbs_update_service`, `recheck_due` on the worker
record. `server/scheduling.php` blocks Care assignments on a lapsed DBS.

The Update Service flag matters because it changes what rechecking involves. Where a worker is
registered, the recheck cadence is a **configurable interval**, not a hard-coded one.

Milestone 3 moves DBS into the same generalised verification lifecycle as right to work and SIA.

## Employment particulars

Acas sets out particulars that a written statement should cover — parties, start date, role,
place of work, pay, working arrangements, holiday, sickness, leave, notice, probation and
required training among them.

**Design rule: do not store these only inside a PDF.** A signed PDF is the record, but the
particulars must also exist as structured fields, or the system cannot report on them, cannot
alert on them, and cannot answer a question about them without a human opening a document.

Milestone 6 adds the structured fields alongside the employment contract lifecycle.

## Holiday and absence records

Holiday taken, holiday carried over, holiday pay and payments in lieu need to be recorded and
retained. The system currently has nowhere to put any of it.

Milestone 6 adds a leave and absence module. **The retention period is a setting**, defaulted
by the business against current guidance, not fixed in code.

## Data protection

The ICO's employment guidance covers recruitment, verification, employment records, health
information and monitoring. The system's obligations under it are structural, and each maps to
a specific mechanism.

| Principle | Mechanism |
|---|---|
| Lawfulness and transparency | `consent_log` records consent with source, IP and timestamp. Privacy notices on every public form |
| Purpose limitation | Data captured for recruitment is not silently reused. Candidate and worker records are distinct roles on one person |
| Data minimisation | Only fields with a stated operational purpose. Nationality and similar are captured only where legitimately required for a check |
| Accuracy | One person record removes the drift between duplicate copies. A worker-initiated correction request workflow |
| Storage limitation | The retention engine (Milestone 9) — every record type has a policy, a period, a review date, then archive and a deletion workflow. **Nothing is kept forever by default** |
| Integrity and confidentiality | Encryption in transit, hashed session tokens, hashed passwords at bcrypt cost 12, documents outside the reachable path and streamed behind an auth check, TOTP 2FA, per-session revoke, rate limiting |
| Accountability | The audit log, and access logging on sensitive record views |

### Special-category information

Health information, disciplinary matters and payroll are **not** ordinary worker profile
fields. Milestone 5 introduces sensitive zones, each with its own permission rule:

```
GENERAL       staff with access to the person
EMPLOYMENT    HR
COMPLIANCE    compliance staff
HEALTH        restricted
DISCIPLINARY  restricted
PAYROLL       restricted
LEGAL         restricted
```

A worker profile is not one undifferentiated record visible to everyone who can open it.

### Subject rights

`server/gdpr.php` already provides export and erasure by email address, restricted to
`super_admin` and `admin`. Milestone 9 extends this to work from the person record, to route
through the deletion-approval workflow rather than erasing immediately, and to log every
export with the requesting user and the purpose.

## Retention

The retention engine is deliberately **not** shipped with legal periods baked in.

```
Record type
   ↓
Retention policy   (configured in Settings)
   ↓
Retention period
   ↓
Review date        → surfaced as a task, not silently actioned
   ↓
Archive
   ↓
Deletion workflow  → requested, approved, logged
```

Different record types have different requirements, and those requirements differ by sector
and change over time. The company sets the values against its own legal advice.

## Sector standards

Already supported through `server/reports.php`:

| Pack | Action |
|---|---|
| CQC evidence | `?action=cqc_pack` |
| SIA Approved Contractor Scheme | `?action=sia_acs` |
| COSHH | `?action=coshh` |
| Skills for Care | `?action=skills_for_care` |

Per-worker evidence bundle: `server/compliance.php?action=pack&worker_id=N`.

## Review

This document, and the configured policy values it points at, should be reviewed on a schedule
set by the business and whenever guidance in any area above is revised. The review belongs to
management, not to the development process.
