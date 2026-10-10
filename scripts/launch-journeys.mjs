// S10 launch journeys (docs/TESTING.md, "2026 launch journeys").
//
// Headless Chromium against the local build and the disposable test database,
// synthetic data only. Each step records PASS/FAIL, how it was driven (UI or
// API), and a screenshot named s10-*.png in the output folder.
//
//   node scripts/launch-journeys.mjs <out dir> [J01,J02,...]
//
// Needs: playwright-core (npm i --no-save playwright-core), a Chromium at
// RG_CHROME, the built app served on RG_BASE with *.php proxied to an API that
// uses server/tests/db/config.php, and docker container rg-mariadb holding
// RG_TEST_DB_NAME. Never point any of it at production.
import { chromium } from "playwright-core"
import fs from "node:fs"

const BASE = process.env.RG_BASE ?? "http://127.0.0.1:5160"
const OUT = process.argv[2]
const ONLY = process.argv[3] ?? "all"
const STATE = OUT + "/s10-journey-state.json"
const CHROME = process.env.RG_CHROME ?? process.env.HOME + "/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome"
const DB_NAME = process.env.RG_TEST_DB_NAME ?? "ritagreen_os_test_s10"
fs.mkdirSync(OUT, { recursive: true })

const state = fs.existsSync(STATE) ? JSON.parse(fs.readFileSync(STATE, "utf8")) : {}
const save = () => fs.writeFileSync(STATE, JSON.stringify(state, null, 2))
const results = []
const tokens = {}

async function token(email) {
  if (tokens[email]) return tokens[email]
  const r = await fetch(`${BASE}/auth.php?action=login`, {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "LocalDev123!" }),
  })
  const j = await r.json()
  if (!j.token) throw new Error("login refused for " + email + ": " + JSON.stringify(j))
  return (tokens[email] = j.token)
}
async function api(email, path, body, method) {
  const t = email ? await token(email) : null
  const r = await fetch(`${BASE}/${path}`, {
    method: method ?? (body === undefined ? "GET" : "POST"),
    headers: { "Content-Type": "application/json", ...(t ? { Authorization: `Bearer ${t}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  let json = null
  try { json = await r.json() } catch { /* not json */ }
  return { status: r.status, json }
}

const browser = await chromium.launch({ executablePath: CHROME })
async function pageAs(email, width = 1280) {
  const ctx = await browser.newContext({ viewport: { width, height: width > 600 ? 900 : 812 }, serviceWorkers: "block" })
  if (email) {
    const t = await token(email)
    await ctx.addInitScript(([tk]) => {
      localStorage.setItem("rgos_token", tk)
      localStorage.setItem("rgos_token_issued", String(Date.now()))
      localStorage.setItem("rg_storage_notice", "seen")
    }, [t])
  }
  const page = await ctx.newPage()
  page.errors = []
  page.on("pageerror", (e) => page.errors.push(e.message))
  return page
}
async function shot(page, name) {
  const file = `${OUT}/s10-${name}.png`
  await page.screenshot({ path: file, fullPage: true })
  return `s10-${name}.png`
}
async function step(id, title, how, fn) {
  if (ONLY !== "all" && !ONLY.split(",").includes(id)) return
  const started = Date.now()
  try {
    const detail = await fn()
    results.push({ id, title, how, result: "PASS", detail: detail ?? "" })
  } catch (e) {
    results.push({ id, title, how, result: "FAIL", detail: String(e.message ?? e).slice(0, 400) })
  }
  console.log(`${results.at(-1).result}  ${id} ${title} (${Date.now() - started} ms) ${results.at(-1).detail}`)
  save()
}
const expect = (cond, msg) => { if (!cond) throw new Error(msg) }
const toastText = async (page) => (await page.locator("[data-sonner-toast]").allInnerTexts()).join(" | ")

const stamp = state.stamp ?? (state.stamp = Date.now().toString().slice(-6))
const NAME = `Journey Tester ${stamp}`
const EMAIL = `journey.${stamp}@example.test`

// ---------------------------------------------------------------- J1 apply
await step("J01", "Public apply through the native form on /careers", "UI", async () => {
  const page = await pageAs(null, 375)
  await page.goto(`${BASE}/careers`, { waitUntil: "networkidle" })
  await page.locator("#ap-name").fill(NAME)
  await page.locator("#ap-phone").fill("07700 900456")
  await page.locator("#ap-email").fill(EMAIL)
  await page.locator("#ap-role").selectOption("Care assistant or support worker")
  await page.locator("#ap-about").fill("Two years as a care assistant in a residential home. Can travel across Leeds. Days and nights.")
  await page.locator("#ap-consent").check()
  await page.getByRole("button", { name: "Send my details" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "01-apply-sent-375")
  const r = await api("admin@local.invalid", "ats.php")
  const a = (r.json?.applicants ?? []).find((x) => x.email === EMAIL)
  expect(a, "applicant not in ATS")
  state.applicantId = a.id
  return `applicant #${a.id}, stage ${a.stage}; ${f}`
})

// ---------------------------------------------------------------- J2 review
await step("J02", "Recruiter opens the candidate and moves them to screened", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/ats`, { waitUntil: "networkidle" })
  await page.getByRole("button", { name: NAME }).click()
  await page.getByRole("dialog").getByText("Move to another stage").waitFor()
  await page.getByRole("dialog").getByRole("combobox").filter({ hasText: "Choose a stage" }).click()
  await page.getByRole("option", { name: /screened/i }).click()
  await page.getByRole("dialog").getByRole("button", { name: "Move", exact: true }).click()
  await page.waitForTimeout(1200)
  const f = await shot(page, "02-ats-screened")
  const r = await api("admin@local.invalid", `ats.php?id=${state.applicantId}`)
  expect(r.json?.applicant?.stage === "screened", "stage is " + r.json?.applicant?.stage)
  return f
})

await step("J03", "Start onboarding: worker created with the care template checklist", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/ats`, { waitUntil: "networkidle" })
  await page.getByRole("button", { name: NAME }).click()
  await page.getByRole("button", { name: /Start onboarding/ }).click()
  await page.waitForTimeout(1500)
  const r = await api("admin@local.invalid", `ats.php?id=${state.applicantId}`)
  const ap = r.json?.applicant
  expect(ap?.worker_id, "no worker created: " + (await toastText(page)))
  state.workerId = ap.worker_id
  state.personId = ap.person_id
  const tasks = r.json?.tasks ?? []
  expect(tasks.some((t) => /Enhanced DBS/.test(t.title ?? t.label ?? "")), "care template not applied: " + JSON.stringify(tasks.map((t) => t.title ?? t.label)))
  // Tick the checklist in the dialog.
  const boxes = page.getByRole("dialog").getByRole("checkbox")
  const n = await boxes.count()
  for (let i = 0; i < n; i++) {
    const b = boxes.nth(i)
    if ((await b.getAttribute("data-state")) !== "checked") { await b.click(); await page.waitForTimeout(700) }
  }
  await page.waitForTimeout(800)
  const f = await shot(page, "03-onboarding-checklist")
  const after = await api("admin@local.invalid", `ats.php?id=${state.applicantId}`)
  const open = (after.json?.tasks ?? []).filter((t) => !t.done)
  expect(open.length === 0, `${open.length} checklist items still open`)
  return `worker #${state.workerId}, person #${state.personId}, ${n} checklist items ticked; ${f}`
})

// ---------------------------------------------------------------- J4-J7 evidence, contract, approval
// A tiny synthetic PDF, so no real document is ever uploaded.
const PDF = OUT + "/s10-synthetic-evidence.pdf"
fs.writeFileSync(PDF, "%PDF-1.4\n% synthetic evidence for the S10 launch journeys\n%%EOF\n")
async function profile(email = "admin@local.invalid", tab = "Overview", width = 1280) {
  const page = await pageAs(email, width)
  await page.goto(`${BASE}/app/people/${state.personId}`, { waitUntil: "networkidle" })
  await page.getByRole("tab", { name: tab }).click()
  await page.waitForTimeout(600)
  return page
}
async function upload(page, buttonName) {
  const [chooser] = await Promise.all([page.waitForEvent("filechooser"), page.getByRole("button", { name: buttonName }).first().click()])
  await chooser.setFiles(PDF)
  await page.waitForTimeout(1500)
}
async function item(key) {
  const r = await api("admin@local.invalid", `people.php?id=${state.personId}`)
  for (const a of r.json?.checks?.assessments ?? []) for (const i of a.items) if (i.key === key) return i
  return null
}

await step("J04", "Right to work recorded and signed off on the profile", "UI", async () => {
  const page = await profile("admin@local.invalid", "Checks and documents")
  await page.getByRole("button", { name: "Record check" }).first().click()
  const dlg = page.getByRole("dialog")
  await dlg.locator("#rtw-ref").fill("W4K-7Q2-9ZP")
  await dlg.getByRole("button", { name: "Record check" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "04-rtw-recorded")
  const i = await item("right_to_work")
  expect(i?.state === "met", "right_to_work is " + JSON.stringify(i?.state) + " " + (await toastText(page)))
  return `right_to_work ${i.state}, verifier ${i.verified_by}; ${f}`
})

await step("J05", "DBS certificate uploaded and verified with an expiry", "UI", async () => {
  const page = await profile("admin@local.invalid", "Checks and documents")
  const row = page.locator("li").filter({ hasText: /DBS/ }).first()
  const [chooser] = await Promise.all([page.waitForEvent("filechooser"), row.getByRole("button", { name: "Upload evidence" }).click()])
  await chooser.setFiles(PDF)
  await page.waitForTimeout(2000)
  await page.locator("li").filter({ hasText: /DBS/ }).first().getByRole("button", { name: "Verify" }).click()
  const dlg = page.getByRole("dialog")
  await dlg.locator("#verify-method").fill("Checked the certificate against the DBS update service")
  await dlg.locator("#verify-expiry").fill("2029-10-01")
  await dlg.getByRole("button", { name: "Verify" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "05-dbs-verified")
  const i = await item("dbs")
  expect(i?.state === "met", "dbs is " + JSON.stringify(i) + " " + (await toastText(page)))
  return `dbs ${i.state}, verifier ${i.verified_by}, origin ${i.evidence_origin}; ${f}`
})

await step("J06", "Contract drafted, sent, signed copy attached and reviewed", "UI", async () => {
  const page = await profile("admin@local.invalid", "Employment")
  await page.getByRole("button", { name: "Draft contract" }).first().click()
  let dlg = page.getByRole("dialog")
  await dlg.locator("#c-title").fill("Care assistant")
  await dlg.locator("#c-start").fill("2026-10-12")
  await dlg.getByRole("button", { name: "Draft contract" }).click()
  await page.waitForTimeout(1500)
  await page.getByRole("button", { name: "Mark sent" }).click()
  await page.waitForTimeout(1200)
  await page.getByRole("button", { name: "Attach signed copy" }).click()
  dlg = page.getByRole("dialog")
  const [chooser] = await Promise.all([page.waitForEvent("filechooser"), dlg.getByRole("button", { name: "Upload signed copy" }).click()])
  await chooser.setFiles(PDF)
  await page.waitForTimeout(1500)
  await dlg.getByRole("button", { name: "Record signature" }).click()
  await page.waitForTimeout(1500)
  await page.getByRole("button", { name: "Review contract" }).click()
  await page.getByRole("dialog").getByRole("button", { name: "Mark reviewed" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "06-contract-reviewed")
  const r = await api("admin@local.invalid", `people.php?id=${state.personId}`)
  const contracts = JSON.stringify(r.json?.employment ?? r.json)
  expect(/"reviewed_at":"20/.test(contracts), "contract not reviewed: " + (await toastText(page)))
  return f
})

await step("J07", "Approved to work from the profile overview", "UI", async () => {
  const page = await profile("admin@local.invalid", "Overview")
  await page.getByRole("button", { name: "Approve to work" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "07-approved")
  const text = await page.locator("body").innerText()
  expect(/Approved for work on/.test(text), "not approved: " + (await toastText(page)))
  return `${text.match(/Approved for work on[^.]*/)?.[0]}; ${f}`
})

// ---------------------------------------------------------------- J8-J15 rota, portal, clock, timesheet
const WORKER_EMAIL = `journey.login.${stamp}@local.invalid`
const SHIFT_TITLE = `Journey day carer ${stamp}`
const london = (offsetMin) => {
  const d = new Date(Date.now() + offsetMin * 60000)
  const p = Object.fromEntries(new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/London", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hour12: false })
    .formatToParts(d).map((x) => [x.type, x.value]))
  return `${p.year}-${p.month}-${p.day}T${p.hour === "24" ? "00" : p.hour}:${p.minute}`
}
const sql = async (statement) => {
  const { execSync } = await import("node:child_process")
  return execSync("docker exec -i rg-mariadb mariadb -N -urg_test -prg_test_local_only " + DB_NAME, { input: statement }).toString().trim()
}

await step("J08", "Owner creates the worker's portal login in Settings, Users", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/settings?tab=users`, { waitUntil: "networkidle" })
  await page.getByRole("button", { name: "Add user" }).click()
  const dlg = page.getByRole("dialog")
  await dlg.locator("#name").fill(NAME)
  await dlg.locator("#email").fill(WORKER_EMAIL)
  await dlg.locator("#role").click()
  await page.getByRole("option", { name: "employee" }).click()
  await page.waitForTimeout(500)
  await dlg.getByRole("combobox").last().click()
  await page.getByRole("option", { name: NAME }).click()
  await dlg.locator("#password").fill("LocalDev123!")
  await shot(page, "08a-new-user-dialog")
  await dlg.locator("button[type=submit]").click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "08b-worker-login-created")
  await token(WORKER_EMAIL)
  return `${WORKER_EMAIL} signs in; ${f}`
})

await step("J08c", "Office sets the pay rate and the days the worker is available, including weekends", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/workers`, { waitUntil: "networkidle" })
  await page.waitForTimeout(800)
  const row = page.locator("tr").filter({ hasText: NAME }).first()
  await row.getByRole("button", { name: /Set rate|\/hr/ }).click()
  const dlg = page.getByRole("dialog")
  await dlg.locator("#pay-rate").fill("13.50")
  const weekends = dlg.locator("label").filter({ hasText: "Weekends" }).getByRole("checkbox")
  if ((await weekends.getAttribute("data-state")) !== "checked") await weekends.click()
  const nights = dlg.locator("label").filter({ hasText: "Nights" }).getByRole("checkbox")
  if ((await nights.getAttribute("data-state")) !== "checked") await nights.click()
  await dlg.getByRole("button", { name: "Save worker" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "08c-worker-availability")
  const row2 = await sql(`SELECT pay_rate_pence, avail_days, avail_nights, avail_weekends FROM workers WHERE id = ${state.workerId};`)
  expect(row2 === "1350\t1\t1\t1", "worker row " + row2 + " " + (await toastText(page)))
  return `rate 1350p, days/nights/weekends on; ${f}`
})

await step("J09", "Office adds a draft shift at Oakleaf starting in five minutes", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/scheduling`, { waitUntil: "networkidle" })
  await page.getByRole("button", { name: "Add shift" }).click()
  const dlg = page.getByRole("dialog")
  await dlg.locator("#add-title").fill(SHIFT_TITLE)
  await dlg.locator("#add-site").click()
  await page.getByRole("option", { name: /Oakleaf — Main House/ }).click()
  await dlg.locator("#add-start").fill(london(5))
  await dlg.locator("#add-end").fill(london(5 + 8 * 60))
  await dlg.getByRole("button", { name: /Add draft/ }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "09-shift-draft")
  const id = Number(await sql(`SELECT id FROM shifts WHERE title = '${SHIFT_TITLE}' ORDER BY id DESC LIMIT 1;`))
  expect(id > 0, "shift not created: " + (await toastText(page)))
  state.shiftId = id
  expect((await sql(`SELECT is_draft FROM shifts WHERE id = ${id};`)) === "1", "not a draft")
  const w = await api(WORKER_EMAIL, "me.php")
  expect(!JSON.stringify(w.json).includes(SHIFT_TITLE), "the worker can see the draft")
  return `shift #${id}, draft, not in the worker's portal; ${f}`
})

await step("J10", "Office publishes the rota", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/scheduling`, { waitUntil: "networkidle" })
  await page.getByRole("button", { name: /Publish/ }).first().click()
  await page.getByRole("dialog").getByRole("button", { name: "Publish" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "10-published")
  expect((await sql(`SELECT is_draft FROM shifts WHERE id = ${state.shiftId};`)) === "0", "still a draft: " + (await toastText(page)))
  return f
})

await step("J11", "Office offers the open shift to the new worker", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/scheduling`, { waitUntil: "networkidle" })
  await page.getByText(SHIFT_TITLE).first().click()
  await page.waitForTimeout(1500)
  const label = page.locator("label").filter({ hasText: NAME }).first()
  await label.waitFor({ timeout: 10000 })
  await label.getByRole("checkbox").click()
  await page.getByRole("button", { name: /Offer to selected/ }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "11-offered")
  const n = Number(await sql(`SELECT COUNT(*) FROM shift_offers WHERE shift_id = ${state.shiftId} AND worker_id = ${state.workerId};`))
  expect(n === 1, "no offer: " + (await toastText(page)))
  return f
})

await step("J12", "Worker signs in on a phone and accepts the offer in the portal", "UI", async () => {
  const page = await pageAs(null, 375)
  await page.goto(`${BASE}/login`, { waitUntil: "networkidle" })
  await page.getByLabel(/Email/i).first().fill(WORKER_EMAIL)
  await page.getByLabel(/Password/i).first().fill("LocalDev123!")
  await page.getByRole("button", { name: /Sign in|Log in/i }).first().click()
  await page.waitForTimeout(2500)
  await page.goto(`${BASE}/m/rota`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  await shot(page, "12a-portal-offer-375")
  await page.getByRole("button", { name: "Accept" }).first().click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "12b-portal-accepted-375")
  const w = await sql(`SELECT worker_id FROM shifts WHERE id = ${state.shiftId};`)
  expect(Number(w) === state.workerId, "not assigned: " + (await toastText(page)))
  return `assigned to worker #${state.workerId}; ${f}`
})

await step("J13", "Worker clocks in and, eight hours later, clocks out", "UI; the eight hours are simulated by moving the clock-in back in the database", async () => {
  const page = await pageAs(WORKER_EMAIL, 375)
  await page.goto(`${BASE}/m`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  await page.getByRole("button", { name: "Clock in" }).click()
  await page.waitForTimeout(1500)
  await shot(page, "13a-clocked-in-375")
  await sql(`UPDATE shift_events SET created_at = DATE_SUB(created_at, INTERVAL 8 HOUR) WHERE shift_id = ${state.shiftId} AND kind = 'clock_in';`)
  await page.reload({ waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  await page.getByRole("button", { name: "Clock out" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "13b-clocked-out-375")
  const row = await sql(`SELECT id, minutes, status FROM timesheets WHERE shift_id = ${state.shiftId};`)
  expect(row, "no timesheet from the clock: " + (await toastText(page)))
  const [id, minutes, status] = row.split("\t")
  state.timesheetId = Number(id)
  return `timesheet #${id}, ${minutes} minutes, ${status}; ${f}`
})

await step("J14", "Worker submits the timesheet with a 30-minute break", "UI", async () => {
  const page = await pageAs(WORKER_EMAIL, 375)
  await page.goto(`${BASE}/m/records`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  const brk = page.getByLabel(/Break/i).first()
  if (await brk.count()) await brk.fill("30")
  await page.getByRole("button", { name: "Submit" }).first().click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "14-timesheet-submitted-375")
  const row = await sql(`SELECT status, break_minutes FROM timesheets WHERE id = ${state.timesheetId};`)
  expect(row.startsWith("pending"), "status " + row + " " + (await toastText(page)))
  return `${row.replace("\t", ", break ")}; ${f}`
})

await step("J15", "Office approves the timesheet", "UI", async () => {
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/timesheets`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  const row = page.locator("tr").filter({ hasText: NAME }).first()
  await row.getByRole("button", { name: "Approve" }).click()
  await page.waitForTimeout(1500)
  const f = await shot(page, "15-timesheet-approved")
  const st = await sql(`SELECT status FROM timesheets WHERE id = ${state.timesheetId};`)
  expect(st === "approved", "status " + st + " " + (await toastText(page)))
  return f
})

// ---------------------------------------------------------------- negatives
const dayAhead = (n) => london(n * 24 * 60).slice(0, 10)

await step("N1", "An unapproved worker cannot be booked", "API refusal, UI shows why", async () => {
  const kofi = Number(await sql("SELECT id FROM workers WHERE name = 'Kofi Baptiste';"))
  const kofiPerson = Number(await sql(`SELECT person_id FROM workers WHERE id = ${kofi};`))
  const d = dayAhead(40)
  const r = await api("admin@local.invalid", "scheduling.php", { title: "Should be refused", division: "care", worker_id: kofi, starts_at: `${d} 08:00`, ends_at: `${d} 16:00` })
  expect(r.status === 409, `expected 409, got ${r.status} ${JSON.stringify(r.json).slice(0, 200)}`)
  const codes = (r.json?.check?.blocking ?? []).map((b) => b.code)
  expect(codes.includes("employment"), "blocking codes " + codes.join(","))
  const page = await pageAs("admin@local.invalid")
  await page.goto(`${BASE}/app/people/${kofiPerson}`, { waitUntil: "networkidle" })
  await page.waitForTimeout(800)
  const f = await shot(page, "N1-unapproved-profile")
  expect(/Not yet approved for work/.test(await page.locator("body").innerText()), "profile does not say not approved")
  return `409, blocking ${codes.join(", ")}: ${r.json?.error}; ${f}`
})

await step("N2", "A worker on approved leave cannot be booked", "UI: worker requests, office approves; booking refusal checked through the API", async () => {
  const d = dayAhead(45)
  const me = await pageAs(WORKER_EMAIL, 375)
  await me.goto(`${BASE}/m/me`, { waitUntil: "networkidle" })
  await me.waitForTimeout(800)
  await me.locator("#lv-from").fill(d)
  await me.locator("#lv-to").fill(d)
  await me.locator("#lv-hours").fill("8")
  await me.locator("#lv-reason").fill("Family wedding")
  await me.getByRole("button", { name: "Request leave" }).click()
  await me.waitForTimeout(1500)
  await shot(me, "N2a-leave-requested-375")
  expect((await sql(`SELECT status FROM leave_records WHERE person_id = ${state.personId} AND starts_on = '${d}';`)) === "requested", "leave not requested: " + (await toastText(me)))
  const page = await profile("admin@local.invalid", "Employment")
  await page.getByRole("button", { name: "Approve", exact: true }).first().click()
  await page.waitForTimeout(1500)
  await shot(page, "N2b-leave-approved")
  const st = await sql(`SELECT status FROM leave_records WHERE person_id = ${state.personId} AND starts_on = '${d}';`)
  expect(st === "approved", "leave status " + st)
  const r = await api("admin@local.invalid", "scheduling.php", { title: "On leave", division: "care", worker_id: state.workerId, starts_at: `${d} 08:00`, ends_at: `${d} 16:00` })
  expect(r.status === 409 && (r.json?.check?.blocking ?? []).some((b) => b.code === "leave"), `expected a leave refusal, got ${r.status} ${JSON.stringify(r.json).slice(0, 200)}`)
  return `409: ${r.json?.error}; s10-N2a-leave-requested-375.png, s10-N2b-leave-approved.png`
})

await step("N3", "A manager scoped to security is refused a care worker", "UI and API", async () => {
  const r = await api("scoped@local.invalid", `people.php?id=${state.personId}`)
  expect(r.status === 403, `profile read ${r.status}`)
  const t = await api("scoped@local.invalid", `timesheets.php?action=approve&id=${state.timesheetId}`, {})
  expect(t.status === 403, `timesheet action ${t.status}`)
  const s = await api("scoped@local.invalid", `scheduling.php?id=${state.shiftId}`)
  expect(s.status === 403, `care shift read ${s.status}`)
  const page = await pageAs("scoped@local.invalid")
  await page.goto(`${BASE}/app/people/${state.personId}`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  const f = await shot(page, "N3-scoped-manager-refused")
  return `profile 403, timesheet 403, shift 403; ${f}`
})

await step("N4", "A worker cannot read another worker's data", "API and UI", async () => {
  const amaraPerson = Number(await sql("SELECT person_id FROM workers WHERE name = 'Amara Chen';"))
  const p = await api(WORKER_EMAIL, `people.php?id=${amaraPerson}`)
  expect(p.status === 401 || p.status === 403, `profile ${p.status}`)
  const amaraFile = Number(await sql("SELECT f.id FROM files f JOIN workers w ON w.id = f.worker_id WHERE w.name = 'Amara Chen' AND f.deleted_at IS NULL LIMIT 1;"))
  const fl = await api(WORKER_EMAIL, `files.php?action=download&id=${amaraFile}`)
  expect(fl.status === 401 || fl.status === 403, `file ${fl.status}`)
  const ts = await api(WORKER_EMAIL, "me.php?action=timesheets")
  const foreign = (ts.json?.timesheets ?? []).filter((x) => x.worker_id !== undefined && x.worker_id !== state.workerId)
  expect(foreign.length === 0, "foreign timesheets visible")
  const amaraTs = Number(await sql(`SELECT id FROM timesheets WHERE worker_id = (SELECT id FROM workers WHERE name = 'Amara Chen') LIMIT 1;`))
  const sub = await api(WORKER_EMAIL, `me.php?action=submit_timesheet&id=${amaraTs}`, { break_minutes: 0 })
  expect(sub.status === 404 || sub.status === 403, `submitting Amara's timesheet ${sub.status}`)
  const page = await pageAs(WORKER_EMAIL, 375)
  await page.goto(`${BASE}/app/people/${amaraPerson}`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  const f = await shot(page, "N4-worker-refused-375")
  return `profile ${p.status}, file ${fl.status}, Amara's timesheet ${sub.status}; landed on ${new URL(page.url()).pathname}; ${f}`
})

await step("N5", "Draft shifts are invisible to workers", "API and UI", async () => {
  const d = dayAhead(50)
  const r = await api("admin@local.invalid", "scheduling.php", { title: `Draft only ${stamp}`, division: "care", site_id: Number(await sql("SELECT id FROM sites WHERE name = 'Oakleaf — Main House';")), starts_at: `${d} 08:00`, ends_at: `${d} 16:00` })
  expect(r.status === 201, `create ${r.status}`)
  const id = r.json.id
  const me = await api(WORKER_EMAIL, "me.php")
  expect(!JSON.stringify(me.json).includes(`Draft only ${stamp}`), "draft visible in me.php")
  const claim = await api(WORKER_EMAIL, `me.php?action=accept&shift_id=${id}`, {})
  expect(claim.status === 409 || claim.status === 404, `claim ${claim.status}`)
  const page = await pageAs(WORKER_EMAIL, 375)
  await page.goto(`${BASE}/m/rota`, { waitUntil: "networkidle" })
  await page.waitForTimeout(1000)
  expect(!(await page.locator("body").innerText()).includes(`Draft only ${stamp}`), "draft shown in the portal")
  const f = await shot(page, "N5-draft-invisible-375")
  return `draft #${id} not listed, claim ${claim.status}; ${f}`
})

save()
const prev =fs.existsSync(OUT + "/s10-journey-results.json") ? JSON.parse(fs.readFileSync(OUT + "/s10-journey-results.json", "utf8")) : []
const merged = [...prev.filter((p) => !results.some((r) => r.id === p.id)), ...results].sort((a, b) => a.id.localeCompare(b.id))
fs.writeFileSync(OUT + "/s10-journey-results.json", JSON.stringify(merged, null, 2))
await browser.close()
