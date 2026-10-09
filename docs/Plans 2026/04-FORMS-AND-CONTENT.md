# Recruitment forms and shared content

## Recommended recruitment approach

Keep an application page on the RitaGreen website with a role selector or role cards. Each role has its own stable RitaGreen URL. The CMS controls whether its destination is the existing native short application, an external Google Form link or an embedded Google Form. Use Google Forms as a practical interim intake route where needed, not a second permanent staff database.

Default recommendation: role page with brief requirements and an external Continue application button. Offer embedding for simple forms after testing the actual form. Always provide Open form directly as a fallback and state when the person is going to Google.

[Google's sharing guidance](https://support.google.com/docs/answer/2839588/send-your-form-to-people?hl=en-GB) supports embedding forms on a website. [Google's file-upload guidance](https://support.google.com/docs/answer/15473134?hl=en) says upload responses require a Google sign-in. Test each real form's responder permissions, embedding behaviour and phone usability before choosing embedded mode.

| Option | Benefit | Trade-off |
|---|---|---|
| External Google Form | Quick, reliable hand-off and simple CMS configuration | Leaves the site and does not itself create an ATS record |
| Embedded Google Form | Applicant stays within the site's surrounding page | Google styling, sign-in restrictions and cross-origin limitations remain |
| Existing native intake | Current source already sends a short application to ATS | Extra recruitment questions and protected document collection need a defined extension |

Do not make candidates submit the same initial details through both native and Google forms. Each role has one active intake path and a named office owner.

## Forms directory in the CMS

Minimum fields: title, purpose, service, role, internal stable slug, public summary, destination mode, Google responder URL or native destination, active state, owner and last reviewed date. An approved administrator edits links; the website reads only active public entries.

Possible entries: healthcare assistant, support worker, registered nurse if recruited, cleaner, security officer, trainer if recruited, general interest and training enrolment. Recruitment and course enrolment remain separate purposes even when the same person is involved.

Accept only approved HTTPS Google Forms responder addresses. Resolve approved short forms links through a controlled validation process rather than accepting arbitrary redirects. Build an iframe from a validated form identifier or approved destination, never arbitrary pasted HTML. No script snippets, public Drive folder links or unrestricted embed URLs.

Changing a destination retains the RitaGreen role page URL. Inactive roles show applications closed and other opportunities. Broken destinations show a helpful contact route, not an empty frame. Preview before publication, record who changed it and retain the previous value for recovery.

## Response ownership and intake

Embedding does not synchronise responses and the website cannot reliably inspect a cross-origin form's submission. Do not show a native 'application received' message or record a successful application solely because a person clicked or opened the form.

First release process:

1. Responses stay in the company-controlled Google account with restricted access.
2. A named recruiter checks the intake each working day.
3. Recruiter creates or links the candidate in the ATS, with source, role and received date.
4. Duplicate candidates are reviewed before linking to the person record.
5. The CMS becomes the operational record; the form response follows the approved retention process.

If volume justifies it, add reviewed CSV import next, with field mapping, preview, duplicate handling and error reporting. Automated Google integration is later scope with separate credentials, failure handling and ownership. Never claim a connected form is fully integrated merely because it appears inside the website.

Collect name, contact, broad experience, role and availability first. Request identity documents, detailed checks and emergency contact through an appropriate protected onboarding process when needed. Google Forms must not be used casually as a public collection point for bank, health or DBS evidence.

## One owner for shared information

| Information | Proposed authoritative home | Consumers |
|---|---|---|
| Legal identity, address and approved contact details | Restricted company settings | Website footer/contact, approved document templates |
| Service descriptions and coverage | Structured service content | Service pages and enquiry choices |
| Public job/role details | Recruitment publishing record | Careers and role pages |
| Form destinations | Forms directory | Recruitment and training pages |
| Social profile URLs | Company social settings | Website/footer/contact |
| News/articles | Existing blog records | Published website news and article pages |
| Course dates and public course summaries | Training records with explicit public fields | Training page |
| Testimonials | Reviewed, permissioned testimonial records | Selected public pages |
| Staff, vetting and care records | Protected operational records | Authorised app/portal only |

Current company.ts and divisions.ts hold important public data in source. Plan a small structured CMS extension after checking existing content/settings endpoints. Do not add a generic page builder or duplicate public and private copies of the same authoritative data.

## Publication and privacy boundaries

Draft → preview → review → publish → archive. A simple authorised publisher can cover both review and publication in a small team, with history. Current blog source exposes draft/published states, so do not assume richer approval or scheduled publishing already exists.

Public APIs return an explicit public field list. Never serialise full staff/client records to the website. Preview must require authorisation. Test rich-text sanitisation, link/image validation and unpublishing. Cache updates should appear within an agreed interval, proposed maximum five minutes, with a visible refresh path if stale.

Social URLs can update from CMS. Social posts themselves remain manually published on their platforms initially. Article sharing does not require an automatic cross-platform scheduler.

Recruitment data handling needs a documented purpose, access, retention and rights process. See [ICO employment information guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/employment/), noting that parts of its recruitment material are draft guidance. Privacy acknowledgement and optional marketing permission must be separate; do not assume a single consent checkbox settles every processing purpose.

## Acceptance examples

An editor changes a form URL in preview, publishes it and sees the new destination on the same role page. An inactive form disappears from active options. A malformed URL is rejected. A private draft article never appears in public search/list/detail paths. A worker or public visitor cannot change any destination. A recruiter can reconcile a submitted response to one candidate with recorded source and no automatic worker approval.
