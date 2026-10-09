# Delivery, approval and documentation consolidation

## Decisions for owner review

Suggested defaults let the plan progress without inventing business facts. Each item can be approved, amended or deferred independently.

| ID | Decision | Recommended position |
|---|---|---|
| D01 | Business and naming | One RitaGreen umbrella; Healthcare first; Cleaning, Security, Training visible; confirm legal/trading names |
| D02 | Direct care boundary | Include care enquiries and workforce coordination; identify the approved care-record system before delivery changes |
| D03 | First release scope | F01–F22 and F30 essentials; add P2 functions only after core journeys pass |
| D04 | Design | #33512d with warm neutrals, stable office navigation and phone-first staff portal |
| D05 | Recruitment | Role pages with one active intake route per role; Google links supported, embedding optional after real-form testing |
| D06 | Content | Small structured CMS for shared facts, forms, social URLs and existing news, no page builder |
| D07 | Social presence | One company profile per platform, recover existing accounts, use draft copy and real imagery |
| D08 | News | Keep the existing feature, publish only with a named owner and useful content |
| D09 | Public facts | Confirm domain, service areas, hours, contacts, offers and all regulated/accreditation claims |
| D10 | Staff operations | Confirm employee/worker arrangements, actual roles, check requirements, rates, leave and timesheet approval owners |
| D11 | Notifications | Portal/in-app source of truth; use existing approved email only after delivery verification; SMS/WhatsApp automation later |
| D12 | Documentation | Consolidate approved decisions into existing canonical files before implementation; keep plans marked proposed until transferred |

Most useful owner answers: what direct care is currently delivered and where its records live; correct corporate identity and domain; which existing Google Forms/accounts are owned; who approves compliance, rota changes and public content. No credentials are needed in chat or planning files.

## Phased route

### Phase 0: approve and establish the baseline

Confirm D01–D12 and record amendments. Map each recommended feature to existing source and observed behaviour. Use fictional local records, not production workforce data. Verify local setup before running any database-dependent journey. Assess critical auth, file-access and data-integrity concerns before cosmetic work. No production migration is implied by this approval.

Deliverable: agreed scope, page inventory, role map, content fact sheet and recorded baseline. Gate: critical blockers have owners, and direct-care boundaries are explicit.

### Phase 1: approve representative designs

Prepare homepage, office rota/staff profile and mobile next-shift mock-ups. Review real content length, empty/error states, mobile use and contrast. Agree navigation and a small reusable component/token set.

Gate: owner approves representative screens and copy direction. Do not redesign every screen before this decision.

### Phase 2: staff records and recruitment

Verify identity linking, emergency contacts, onboarding, protected documents, role-based compliance and recruitment intake. Add missing essentials and simplify existing screens. Confirm Google Form ownership and daily intake responsibility before activating any external route.

Gate: a fictional candidate reaches a correctly linked worker record; an unsuitable worker remains blocked; unauthorised users cannot view documents, exports or other people's data.

### Phase 3: rota and staff portal

Complete publishing, offers, availability, leave requests, site instructions, changes, timesheets and escalation. Apply the shared approach to cleaning and security. Bring direct-care coordination in only within the agreed care-system boundary.

Gate: office-to-worker end-to-end journey passes, including conflicting/overnight shifts, cancellations, failed alerts, time corrections and missed-visit handling where applicable.

### Phase 4: website and shared content

Refresh pages using approved facts and real imagery. Add role/form directory, structured company/social settings and controlled news publication. Review old content and URLs. Test all enquiry routes before directing visitors to them.

Gate: service paths work on phone and desktop; CMS changes appear as agreed; drafts/private data remain private; redirect and indexing plan is reviewed. No unsupported claims remain in approved content.

### Phase 5: business hand-offs and social rollout

Check client/site/contract journeys, approved hours and finance exports. Add small P2 functions only where staff need them. Prepare final profile assets, confirm ownership and approval, then publish through separately authorised account actions.

Gate: named staff can complete their daily tasks, enquiries have owners and external changes have explicit approval.

### Phase 6: release and adoption

Run applicable checks, pilot with a small authorised group and fictional or appropriately controlled records, train office staff and collect problems. Production release requires an exact deployment plan, verified backup/restore, migration compatibility, rollback and post-change verification. Frontend and manually deployed PHP must be compatible. Pushing main deploys the frontend, so it remains a production action.

No fixed schedule is promised until baseline gaps, owner availability and capacity are known. Build one cohesive slice at a time and review it before moving on.

## Verification plan for implementation

Run the project's type check, applicable lint assessment, frontend tests, PHP tests, PHP syntax checks, build and documentation consistency check. Preserve and report the known lint baseline rather than calling it a pass. Use integration/manual evidence for journeys that unit tests cannot prove.

| Journey | Required evidence |
|---|---|
| Recruitment and identity | Native/Google intake reconciliation, duplicate review, one person with linked roles |
| Compliance | Missing/rejected/expired evidence, per-service eligibility, assignment blocked on server |
| Access | Worker-own-record isolation, client-own-organisation isolation, protected uploads and exports |
| Rota | Publish, accept/decline, double booking, overnight/DST, change notice and cancellation |
| Hours | Actual time, breaks, approval, correction history and export reconciliation |
| Direct care | Defined system boundary, restricted information, accountable missed-visit and concern escalation |
| Content/forms | Preview, publish, unpublish, bad URL, inactive role, public/private separation and stale-data handling |
| Design | Keyboard, zoom, contrast, touch use, reduced motion, loading, error and empty states |
| Recovery | Failed notification visibility, account offboarding, restore test and release rollback |

Record PASS, FAIL, PARTIAL, NOT TESTED, BLOCKED or NOT APPLICABLE against actual evidence. A successful build does not establish that these workflows work.

## After approval: one source of truth

Do not rewrite every Markdown file indiscriminately. Inventory each document, rule, hook and memory, record its responsibility and change only affected material. Project facts belong here; global GEE OS methods remain external references. Changes outside this workspace need their own access/approval path.

| Target | Approved content to transfer |
|---|---|
| docs/PRD.md | Purpose, audiences, services, boundaries and non-goals |
| docs/BLUEPRINT.md | Agreed feature requirements and working journeys |
| docs/ROADMAP.md | Approved phase order, dependencies and acceptance gates |
| docs/DESIGN_SYSTEM.md | Agreed colour, typography, components and layout standards |
| docs/ARCHITECTURE.md | Actual architecture and confirmed integration boundaries, no planned feature described as built |
| docs/SCHEMA.md, docs/API.md | Only implemented data and endpoint changes, alongside implementation |
| docs/UK_GOVERNANCE.md | Verified role requirements, data ownership and approved governance processes |
| docs/RULES.md, AGENTS.md | Durable project constraints, concise and without duplicated business detail |
| .agent/PROJECT.yml, .agent/CURRENT-TASK.md | Current phase, scope, authority and evidence obligations |
| CLAUDE.md, README.md | Accurate entry points and concise project knowledge |
| docs/STATUS.json, TRACEABILITY.md, CHANGELOG.md | Actual completion/evidence and links to requirements |
| docs/PROGRESS.md | Regenerate from STATUS.json, do not hand-edit |
| Applicable hooks/agent memories | Remove contradictions and point to canonical rules; preserve platform-specific enforcement differences |

Keep a durable approved decision record under the project's existing documentation structure before removing this temporary folder. It should state approved decisions, date, owner, exclusions and where each requirement moved, rather than copy the whole pack. Preserve proposal versus implementation status.

Run link and consistency checks after transfer, inspect instruction precedence, and ensure requirement → data/API → UI → tests → docs → release evidence agrees. Do not add hooks just to enforce aesthetic preferences. Hooks cannot substitute for server-side access checks.

Only then review the exact directory docs/Plans 2026 for removal, confirm all useful content and links have been transferred, obtain the required deletion approval and remove that directory alone. Do not delete plans immediately on design approval, and do not leave broken links to them. Keep an archive/decision record through the normal version history or approved records process.

## Current deliverable status

PASS: planning pack created and its local links checked. PARTIAL: public research, because Google profile and supported-living detail could not be read. NOT TESTED: application runtime, production readiness and external accounts. No implementation, account mutation or deployment occurred. Next action is owner review of D01–D12, followed by documentation consolidation and a separately authorised implementation phase.
