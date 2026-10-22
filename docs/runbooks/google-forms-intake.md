# Runbook: connecting a Google Form to Recruitment

**Who:** the owner, or a named company administrator with edit access to the form.
**Time:** about 20 minutes per form, the first time.
**Result:** every response to the form arrives in Recruitment as an applicant at "Applied",
linked to one person record, within a minute of being sent.

The website shows the form on a role page (`/careers/:slug`); this runbook is what makes a
response reach the office app. The two are independent: a form can be on the website and not
yet connected, and the office then sees nothing until it is.

No real secret belongs in this document, in a commit, in an email or in a chat message. The
secret is created in the office app, shown once, and pasted straight into the script's
properties.

---

## 1. Before you start

- The form belongs to the **company** Google account (two-step verification on), not a
  personal one.
- The form asks only what file 06 of the 2026 plan lists: name, phone, email, postcode, role,
  right to work (yes or no, no documents), experience, availability, how they heard about us,
  privacy notice acknowledgement, optional marketing consent. **No file upload questions**:
  they force respondents to sign in to Google.
- Question titles match the intake mapping. The starting mapping (migration 011) expects these
  titles exactly, ignoring case and a trailing `*`:

  | Question title | Becomes |
  |---|---|
  | Full name | name (required) |
  | Email address | email (required) |
  | Phone number | phone |
  | Which service are you applying for? | service |
  | Position applied for | role |
  | Postcode | postcode |
  | Do you have the right to work in the UK? | right to work |
  | SIA licence number, Previous employer, Relevant training, References | kept in notes |
  | Anything else you would like us to know? | notes |

  Any other question is not lost: its answer is added to the applicant's notes as
  `[unmapped] question: answer`. To map a new question properly, ask a developer to add a row
  to `integration_field_maps`; there is no screen for it yet.

## 2. Create the secret in the office app

1. Sign in as an owner (super admin or admin) and open **Settings, Form intake**.
2. Under **Intake keys**, find the key for this form's service, for example
   `careers_care` ("Careers form, Care"). One key per service; several forms may share one.
3. Press **Set secret** (or **New secret** if one exists; that replaces it, and any form
   already using the old one stops being accepted until it is given the new one).
4. Copy the secret from the dialog. It is shown **once** and stored only as a hash. Note the
   key name shown with it.
5. In **Website, Forms**, add the form (or edit it) and set **Sends responses through** to
   the same key. That is what lets Settings show this form's intake health.

## 3. Install the script on the form

1. Open the form in Google Forms (editing view). Menu **⋮, Apps Script**. This creates a script
   bound to the form; that matters, because the trigger below needs the form.
2. Replace everything in `Code.gs` with the script in section 6. Save.
3. **Project Settings (gear), Script Properties, Add script property**, three times:

   | Property | Value |
   |---|---|
   | `RG_ENDPOINT` | `https://ritagreenservices.com/forms-intake.php` |
   | `RG_KEY` | the key from step 2, for example `careers_care` |
   | `RG_SECRET` | the secret you copied |

   Script Properties are visible only to editors of the script. Do not put the secret in the
   code.
4. **Triggers (clock icon), Add Trigger:** function `onFormSubmit`, deployment `Head`, event
   source `From form`, event type `On form submit`, failure notifications `Notify me
   immediately`. Save and accept the permissions Google asks for (the form, and connecting to
   an external service).
5. Optionally add a second trigger: function `retryFailed`, `Time-driven`, `Hour timer`,
   `Every hour`. It re-sends anything that failed three times.

## 4. Test with a sandbox form first

Do this before connecting a live recruitment form, ideally against a local or staging copy
of the office app rather than production.

1. Make a copy of the form (**⋮, Make a copy**) named "TEST, do not share". The copy gets its
   own script; repeat section 3 on it.
2. For a local test, `RG_ENDPOINT` must be an address Google can reach. Google cannot reach
   `localhost`, so a local test needs a temporary tunnel; otherwise test against production
   with an obviously fake applicant (name "Test Applicant", an `example.com` email) and archive
   it afterwards.
3. In the script editor, choose `testConnection` and **Run**. The execution log should show
   `HTTP 201` (or `200` with `duplicate` on a second run). `401` means the secret does not
   match; `404` means the key is wrong; `409` means the key is paused; `503` means no secret
   has been set.
4. Submit the copy of the form once, as an applicant would.
5. In the office app: **Recruitment** shows the applicant at Applied, source Google Form;
   **Settings, Form intake** shows the submission as `mapped` and the key as Working.
6. Submit the same response again is not possible from the form, but `testConnection` sends
   the same `response_id` each time; a second run returns `duplicate: true` and creates
   nothing. That is the redelivery protection.
7. Delete the test applicant's record by archiving it, and delete the test form.

## 5. What the office sees when it goes wrong

| Symptom in Settings, Form intake | Meaning | Fix |
|---|---|---|
| Key shows **No secret set** | Every submission is refused (503) | Section 2 |
| Key shows **Nothing received** | No successful submission within the expected gap (a week) | Check the form is still live; open the script's **Executions** list for errors |
| "Bad or missing shared secret" under the key | A script is sending the wrong secret (401) | Re-copy the secret, or set a new one and update the script |
| A submission marked **failed** with "Required answers were empty" or "No name could be mapped" | It arrived but the questions do not match the mapping | Fix the question title or the mapping, then press **Retry** on that submission. The answers were kept, so nothing is lost |
| Website, Forms shows **Review due** | Nobody has checked the form for 90 days | Open it, check the questions and the privacy link, press **Reviewed** |

On the Google side, every attempt is in the script's **Executions** list. A response that
failed three times is kept in Script Properties under `RG_FAILED_IDS` and is re-sent by
`retryFailed`. Re-sending is always safe: the office app recognises the `response_id` and
never creates a second applicant.

A submission never makes anybody ready to work. It creates an applicant at Applied, and the
checks, onboarding and approval follow in the office app as for any other applicant.

## 6. The script

Copy everything in the block into `Code.gs`.

```javascript
/**
 * RitaGreen OS: send each Google Form response to Recruitment.
 *
 * Posts {response_id, answers} to forms-intake.php with the integration key in
 * the query string and the shared secret in the X-RitaGreen-Secret header.
 * Configuration lives in Script Properties: RG_ENDPOINT, RG_KEY, RG_SECRET.
 * Never paste the secret into this file.
 */

var MAX_ATTEMPTS = 3;

/** Installable trigger: From form, On form submit. */
function onFormSubmit(e) {
  var response = e && e.response ? e.response : null;
  if (!response) {
    console.error('onFormSubmit ran without a form response. Is the trigger "From form"?');
    return;
  }
  var ok = sendResponse_(response);
  if (!ok) rememberFailure_(response.getId());
}

/** Build the payload forms-intake.php expects: {response_id, answers}. */
function buildPayload_(response) {
  var answers = {};
  response.getItemResponses().forEach(function (item) {
    var title = item.getItem().getTitle();
    var value = item.getResponse();
    // Checkbox answers arrive as arrays; grids as arrays of arrays. Keep arrays
    // flat and as strings, which is what the mapping reads.
    if (Array.isArray(value)) {
      value = value.map(function (v) { return Array.isArray(v) ? v.join(' ') : String(v); });
    } else if (value !== null && value !== undefined) {
      value = String(value);
    }
    answers[title] = value;
  });
  // The respondent email, when the form collects it automatically.
  var email = response.getRespondentEmail();
  if (email && !answers['Email address']) answers['Email address'] = email;
  return { response_id: response.getId(), answers: answers };
}

/** Send one response, retrying transient failures. Returns true when the office app accepted it. */
function sendResponse_(response) {
  var props = PropertiesService.getScriptProperties();
  var endpoint = props.getProperty('RG_ENDPOINT');
  var key = props.getProperty('RG_KEY');
  var secret = props.getProperty('RG_SECRET');
  if (!endpoint || !key || !secret) {
    console.error('Script Properties RG_ENDPOINT, RG_KEY and RG_SECRET must all be set.');
    return false;
  }

  var payload = buildPayload_(response);
  var url = endpoint + '?key=' + encodeURIComponent(key);
  var options = {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    headers: { 'X-RitaGreen-Secret': secret },
    muteHttpExceptions: true,
    followRedirects: false
  };

  for (var attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    var code = 0;
    var body = '';
    try {
      var res = UrlFetchApp.fetch(url, options);
      code = res.getResponseCode();
      body = res.getContentText().slice(0, 500);
    } catch (err) {
      body = String(err);
    }

    // Never log the secret or the answers: the log is visible to every editor.
    console.log('Response ' + payload.response_id + ': attempt ' + attempt + ', HTTP ' + code);

    if (code === 200 || code === 201) return true;
    // 422: it arrived and was kept, but could not be mapped. The office retries
    // it from Settings after fixing the mapping; sending again would not help.
    if (code === 422) {
      console.warn('Stored but not mapped: ' + body);
      return true;
    }
    // Wrong key, wrong secret, paused or not configured: retrying will not fix it.
    if (code === 400 || code === 401 || code === 404 || code === 409 || code === 503) {
      console.error('Refused (HTTP ' + code + '): ' + body);
      return false;
    }
    // 429, 5xx or no connection: wait and try again.
    if (attempt < MAX_ATTEMPTS) Utilities.sleep(2000 * attempt * attempt);
  }
  console.error('Gave up on response ' + payload.response_id + ' after ' + MAX_ATTEMPTS + ' attempts.');
  return false;
}

/** Keep the id of a response that could not be sent, for retryFailed. */
function rememberFailure_(responseId) {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var props = PropertiesService.getScriptProperties();
    var ids = JSON.parse(props.getProperty('RG_FAILED_IDS') || '[]');
    if (ids.indexOf(responseId) === -1) ids.push(responseId);
    props.setProperty('RG_FAILED_IDS', JSON.stringify(ids.slice(-200)));
  } finally {
    lock.releaseLock();
  }
}

/** Time-driven trigger: re-send responses that failed. Safe to run any time. */
function retryFailed() {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var props = PropertiesService.getScriptProperties();
    var ids = JSON.parse(props.getProperty('RG_FAILED_IDS') || '[]');
    if (ids.length === 0) return;
    var form = FormApp.getActiveForm();
    var still = [];
    ids.forEach(function (id) {
      var response;
      try { response = form.getResponse(id); } catch (err) { response = null; }
      if (!response) return; // deleted from the form: nothing to send
      if (!sendResponse_(response)) still.push(id);
    });
    props.setProperty('RG_FAILED_IDS', JSON.stringify(still));
    console.log('retryFailed: ' + (ids.length - still.length) + ' sent, ' + still.length + ' still failing.');
  } finally {
    lock.releaseLock();
  }
}

/** Run by hand from the editor: sends the most recent response, or says there is none. */
function testConnection() {
  var responses = FormApp.getActiveForm().getResponses();
  if (responses.length === 0) {
    console.log('Submit the form once first, then run this again.');
    return;
  }
  var ok = sendResponse_(responses[responses.length - 1]);
  console.log(ok ? 'Accepted by the office app.' : 'Not accepted. See the lines above.');
}
```

## 7. Changing or retiring a form

- **A new form for the same role:** add it in Website, Forms (or change the address on the
  existing entry; the role page address does not change and the old address is kept in the
  form's history), then repeat section 3 on the new form.
- **Rotating the secret:** Settings, Form intake, **New secret**, then update `RG_SECRET` on
  every form using that key. Submissions between the two steps are refused, recorded as
  failures on the key and kept by the script for `retryFailed`.
- **Retiring a form:** switch it off in Website, Forms (roles using it then say applications
  are closed), delete the triggers, and stop accepting responses in Google Forms.

## Not tested

This script has not been run inside Google Apps Script from the build environment, which has
no Google account. What is tested is the receiving side: the payload shape, the key and
secret, the duplicate protection and the failure recording
(`server/tests/db/cases/080_forms_intake.php`, `170_careers_cms.php`). Run section 4 before
relying on it.

## 8. Import from a Google Sheet

For responses that are already in the form's Sheet (from before the script was installed, or
from a day the script was failing), and for updating existing staff contact details in bulk.
Office roles only (owner, admin, manager).

1. In Google Sheets, open the responses Sheet and choose **File, Download, Comma-separated
   values (.csv)**. XLSX files are not read. Excel users: **Save as, CSV UTF-8**.
2. In the office app open **Recruitment**, then **Import from spreadsheet**, and choose the file.
   Up to 1,000 rows and 2 MB; the first row must be the column headings.
3. **Columns:** check where each column goes. The first time, tick "Save these columns for next
   time", name it and pick the form it belongs to; the next download of the same Sheet maps
   itself. Choose "Applications" for form responses or "Staff contact details only" for a staff
   list, and a service for rows that do not say.
4. **Preview:** every row says what it will do. New applicant and Update rows are ticked. A
   row the form feed already delivered, or one imported before, reads **Already received**.
   **Needs a decision** means the email, phone or name points at somebody on record who may not
   be the same person: pick them, or "Someone new". Nothing is merged without that choice.
5. **Apply.** Each row is applied on its own. New applicants arrive in Recruitment at Applied
   with "spreadsheet import" as the source and the file and row in their notes. For staff, only
   phone, email, address, postcode, the first emergency contact and availability change; checks,
   pay and status never do. Every change is in the audit log with the file name and row.
6. **Report:** created, updated, skipped and failed. **Download failed rows** gives the failed
   rows back as CSV with the reason in the first columns: fix them in the Sheet and import that
   file. Rows already applied are recognised and skipped, so importing the whole Sheet again is
   safe.
7. **Close the import** when you are done. The uploaded file is never kept; closing clears the
   rows it was holding.

Not tested: a real Google Sheets download. The import is tested with a synthetic sample
(`server/tests/fixtures/sheet-import-sample.csv`) shaped like a Google Forms responses sheet.
