# 06 Forms and CMS links

## Recruitment: the decided route (D03)

```text
Careers page  ->  Role page /careers/:slug  ->  Embedded Google Form
                                                   |
                         Google Sheet (company account) <-+-> Apps Script on submit
                                                               |
                                    server/forms-intake.php (key + shared secret)
                                                               |
                               Recruitment board: new applicant, linked to one person
```

1. **Careers page** lists active vacancies from the CMS: title, service tag, location, hours, pay `[confirm per role]`.
2. **Role page** shows what the job involves, requirements, what the checks will be, then the embedded Google Form. Above the form: "Prefer a full screen? Open the form in a new tab." Below it: a phone number for anyone who cannot use the form.
3. **Google Form** asks only what is needed to start: name, phone, email, postcode, role, right to work (yes or no, no documents), experience, availability, how they heard about us, privacy notice acknowledgement, separate optional marketing consent.
4. **No file uploads in Google Forms.** Google requires respondents to sign in to a Google account to upload files, which stops many applicants. Identity documents, DBS and certificates are collected later through the staff portal's Records tab, after the applicant is invited.
5. **Auto-feed.** An Apps Script on each form's submit trigger posts the response to `forms-intake.php` with the integration key and the `X-RitaGreen-Secret` header. The endpoint already stores the raw payload before mapping, is safe against redelivery (`response_id`), and keeps failed submissions for retry (launch plan Phase 2). The secret lives only in `server/config.php` and the script's properties, never in the repository.
6. **Recruitment board** shows the applicant at "Applied" with the source form and role. A submission never makes anyone ready to work.

### Apps Script, for the owner to install per form

Delivered in S7 as `docs/runbooks/google-forms-intake.md` with copy-paste script, where to put the key and secret, and how to test with a sandbox form before going live. The office Settings page shows each form's last submission and any failures (`forms-intake.php?action=health`).

### Embedding rules

The CMS stores only an approved Google Forms address (`https://docs.google.com/forms/d/e/<id>/viewform`). The website builds the iframe from the form id; pasted HTML or scripts are never accepted. If the form cannot load, the page shows the "open in a new tab" link and the phone number, never an empty box. The website does not claim "application received", because it cannot see inside Google's form; Google shows its own confirmation.

## Spreadsheet import (owner request)

For responses already sitting in Google Sheets, and for updating existing staff details in bulk.

| Step | What happens |
|---|---|
| 1 Upload | Office user (Recruitment or Owner) downloads the Sheet as CSV or XLSX and uploads it in Recruitment, "Import from spreadsheet" |
| 2 Map | Match columns to fields once per form; the mapping is saved and reused next time |
| 3 Preview | Every row shown with what will happen: new applicant, update to an existing person, duplicate already received, or problem |
| 4 Match | Existing people are matched on email, then phone, then name with date of birth. Uncertain matches are flagged for a person to decide; nothing merges silently |
| 5 Approve | The user ticks the rows to apply. Updates show old and new values side by side |
| 6 Apply | Identity is written through `people` using `find_or_create_person()`; the worker record is linked, never given its own copy. Every change is audited with the file name and row |
| 7 Report | Summary: created, updated, skipped, failed, with a downloadable list of failures |

Fields the import may update for existing staff: phone, email, address, emergency contacts, availability. It never changes compliance status, verification results, pay or employment status; those have their own workflows.

## Forms directory in the CMS (Website area)

| Field | Purpose |
|---|---|
| Title | "Support worker application" |
| Purpose | Recruitment, training booking, or other |
| Service and role | Links the form to a vacancy |
| Google form address | Validated as an approved Google Forms address |
| Display | Embedded or link only |
| Active | Inactive forms disappear from the site; the role page says applications are closed and suggests other roles |
| Owner | The office person who checks intake daily |
| Last reviewed | Date, shown in Settings when older than 90 days |

Changing a form address keeps the same role page URL. The previous value is kept in history.

## What the CMS publishes to the website

One owner for each fact. The website reads public endpoints that return an explicit list of public fields, never whole records.

| Information | Edited in | Shown on |
|---|---|---|
| Company name, address, phone, email, hours, company number | Website, Company details | Header, footer, contact page, structured data |
| Social profile links and WhatsApp contact link | Website, Company details | Footer, contact page, `/links` |
| Vacancies | Website, Vacancies | Careers, role pages, homepage careers band, job structured data |
| Form links | Website, Forms | Role pages, training page |
| News posts | Website, Posts (existing `blog.php`) | `/news`, homepage latest news |
| Testimonials, with permission record | Website, Testimonials (existing) | Homepage, service pages |
| Training course dates | Website, Courses (public fields only) | Training page |
| Service descriptions | Code in S6 (`src/lib/divisions.ts`), moved to CMS later only if the owner edits them often | Service pages |

Drafts are never public. Published changes appear within five minutes. Staff, applicant and client records are never exposed to the website.

## Privacy

A privacy notice link on every form. Marketing consent separate and optional. Retention for unsuccessful applicants follows `docs/RETENTION.md` `[confirm period with owner]`. The Google account that owns the forms and Sheets belongs to the company, uses two-step verification, and Sheet access is limited to named recruiters.
