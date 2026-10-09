import { api } from "@/lib/api"
import {
  CALLBACK_TIMES,
  CARE_FOR,
  CARE_NEED,
  COURSES,
  GENERAL_TOPICS,
} from "@/components/public/forms/options"
import { EMAIL_PATTERN, useEnquiryForm, type FieldErrors } from "@/components/public/forms/use-enquiry-form"
import {
  CheckField,
  FormFailure,
  FormSuccess,
  PrivacyNote,
  SelectField,
  SubmitButton,
  TextAreaField,
  TextField,
} from "@/components/public/forms/fields"

// The three forms that post to contact.php, each with its own `type`: care,
// training and general. contact.php files a care enquiry under Healthcare and a
// training enquiry under Training, and opens the stored message with the type,
// so the office Enquiries screen shows what each one is. The form-specific
// answers are composed into the message as labelled lines.

type Contact = { name: string; email: string; phone: string }

function checkContact(v: Contact): FieldErrors<Contact> {
  const e: FieldErrors<Contact> = {}
  if (!v.name.trim()) e.name = "Tell us your name."
  if (!v.email.trim()) e.email = "Add an email so we can reply."
  else if (!EMAIL_PATTERN.test(v.email.trim())) e.email = "That email does not look right. Check it and try again."
  return e
}

function ContactFields({
  id,
  v,
  set,
  errors,
}: {
  id: (k: string) => string
  v: Contact
  set: (k: keyof Contact, x: string) => void
  errors: FieldErrors<Contact>
}) {
  return (
    <div className="grid gap-5 sm:grid-cols-2">
      <TextField id={id("name")} label="Your name" value={v.name} onChange={(x) => set("name", x)} error={errors.name} autoComplete="name" maxLength={160} />
      <TextField id={id("phone")} label="Phone" optional type="tel" value={v.phone} onChange={(x) => set("phone", x)} autoComplete="tel" inputMode="tel" maxLength={40} />
      <TextField id={id("email")} label="Email" type="email" value={v.email} onChange={(x) => set("email", x)} error={errors.email} autoComplete="email" inputMode="email" maxLength={190} className="sm:col-span-2" />
    </div>
  )
}

function post(type: "care" | "training" | "general", v: Contact, message: string, extra: Record<string, unknown> = {}) {
  return api("contact.php", {
    method: "POST",
    auth: false,
    body: { name: v.name.trim(), email: v.email.trim(), phone: v.phone.trim(), message, type, source: type === "general" ? "contact" : type, ...extra },
  })
}

// ---------------------------------------------------------------------------
// Care enquiry. A conversation, not a sales form: who it is for, the broad kind
// of support, and when to call. It asks for no medical detail, and says so.

type CareValues = Contact & { forWhom: string; need: string; callback: string; area: string; notes: string }

export function CareEnquiryForm({ idPrefix = "care" }: { idPrefix?: string }) {
  const id = (k: string) => `${idPrefix}-${k}`
  const form = useEnquiryForm<CareValues>({
    initial: { name: "", email: "", phone: "", forWhom: "", need: "", callback: "", area: "", notes: "" },
    validate: (v) => {
      const e: FieldErrors<CareValues> = checkContact(v)
      if (!v.forWhom) e.forWhom = "Tell us who the support is for."
      if (!v.need) e.need = "Choose the closest option, or not sure yet."
      if (!v.callback) e.callback = "Tell us when is best to call you."
      return e
    },
    send: (v) =>
      post(
        "care",
        v,
        [
          `Support is for: ${v.forWhom}`,
          `Kind of support: ${v.need}`,
          `Best time to call: ${v.callback}`,
          `Area: ${v.area.trim() || "not given"}`,
          v.notes.trim() ? `\n${v.notes.trim()}` : "",
        ]
          .filter(Boolean)
          .join("\n"),
      ),
  })
  const { values: v, set, errors } = form

  if (form.status === "sent") {
    return (
      <FormSuccess title="Thank you, we will call you" onReset={form.reset} resetLabel="Send another enquiry">
        <p>Someone from the office will ring at the time you chose to talk through what would help. There is no obligation.</p>
      </FormSuccess>
    )
  }

  return (
    <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Care enquiry">
      <ContactFields id={id} v={v} set={(k, x) => set(k, x)} errors={errors} />
      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField id={id("for")} label="Who is the support for?" value={v.forWhom} onChange={(x) => set("forWhom", x)} options={CARE_FOR} error={errors.forWhom} />
        <SelectField id={id("need")} label="What kind of support?" value={v.need} onChange={(x) => set("need", x)} options={CARE_NEED} error={errors.need} />
        <SelectField id={id("callback")} label="Best time to call" value={v.callback} onChange={(x) => set("callback", x)} options={CALLBACK_TIMES} error={errors.callback} />
        <TextField id={id("area")} label="Area or postcode" optional value={v.area} onChange={(x) => set("area", x)} maxLength={60} />
      </div>
      <TextAreaField
        id={id("notes")}
        label="Anything you would like us to know first"
        optional
        value={v.notes}
        onChange={(x) => set("notes", x)}
        maxLength={1500}
        hint="Please do not include medical details here. We will talk those through with you on the phone."
      />
      <FormFailure message={form.failure} />
      <SubmitButton sending={form.status === "sending"}>Ask us to call</SubmitButton>
      <PrivacyNote>We use these details to call you back about this enquiry, nothing else.</PrivacyNote>
    </form>
  )
}

// ---------------------------------------------------------------------------
// Training enquiry: course, group size, preferred dates, contact.

type TrainingValues = Contact & { organisation: string; course: string; groupSize: string; dates: string; notes: string }

export function TrainingEnquiryForm({ idPrefix = "tr" }: { idPrefix?: string }) {
  const id = (k: string) => `${idPrefix}-${k}`
  const form = useEnquiryForm<TrainingValues>({
    initial: { name: "", email: "", phone: "", organisation: "", course: "", groupSize: "", dates: "", notes: "" },
    validate: (v) => {
      const e: FieldErrors<TrainingValues> = checkContact(v)
      if (!v.course) e.course = "Choose the training you are asking about, or not sure yet."
      const n = Number(v.groupSize)
      if (v.groupSize && (!Number.isInteger(n) || n < 1 || n > 500)) e.groupSize = "Enter the number of people as a whole number."
      return e
    },
    send: (v) =>
      post(
        "training",
        v,
        [
          `Course: ${v.course}`,
          `Organisation: ${v.organisation.trim() || "not given"}`,
          `Group size: ${v.groupSize || "not given"}`,
          `Preferred dates: ${v.dates.trim() || "not given"}`,
          v.notes.trim() ? `\n${v.notes.trim()}` : "",
        ]
          .filter(Boolean)
          .join("\n"),
      ),
  })
  const { values: v, set, errors } = form

  if (form.status === "sent") {
    return (
      <FormSuccess title="Thank you, we have your training enquiry" onReset={form.reset} resetLabel="Send another enquiry">
        <p>The office will come back to you with dates and what the course covers.</p>
      </FormSuccess>
    )
  }

  return (
    <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Training enquiry">
      <ContactFields id={id} v={v} set={(k, x) => set(k, x)} errors={errors} />
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField id={id("organisation")} label="Organisation" optional value={v.organisation} onChange={(x) => set("organisation", x)} autoComplete="organization" maxLength={160} />
        <SelectField id={id("course")} label="Training" value={v.course} onChange={(x) => set("course", x)} options={COURSES} error={errors.course} />
        <TextField id={id("group")} label="Number of people" optional type="number" inputMode="numeric" min="1" max="500" value={v.groupSize} onChange={(x) => set("groupSize", x)} error={errors.groupSize} />
        <TextField id={id("dates")} label="Preferred dates" optional value={v.dates} onChange={(x) => set("dates", x)} maxLength={200} hint="For example: weekdays in January." />
      </div>
      <TextAreaField id={id("notes")} label="Anything else" optional value={v.notes} onChange={(x) => set("notes", x)} maxLength={2000} />
      <FormFailure message={form.failure} />
      <SubmitButton sending={form.status === "sending"}>Send enquiry</SubmitButton>
      <PrivacyNote />
    </form>
  )
}

// ---------------------------------------------------------------------------
// General contact: name, contact, message.

type GeneralValues = Contact & { topic: string; message: string; marketing: boolean }

export function GeneralEnquiryForm({ idPrefix = "gen" }: { idPrefix?: string }) {
  const id = (k: string) => `${idPrefix}-${k}`
  const form = useEnquiryForm<GeneralValues>({
    initial: { name: "", email: "", phone: "", topic: "", message: "", marketing: false },
    validate: (v) => {
      const e: FieldErrors<GeneralValues> = checkContact(v)
      if (!v.message.trim()) e.message = "Tell us briefly what your message is about."
      return e
    },
    send: (v) => post("general", v, v.message.trim(), { division: v.topic || "other", marketing_consent: v.marketing }),
  })
  const { values: v, set, errors } = form

  if (form.status === "sent") {
    return (
      <FormSuccess title="Thank you, your message is with us" onReset={form.reset} resetLabel="Send another message">
        <p>Someone from the office will reply by email or phone.</p>
      </FormSuccess>
    )
  }

  return (
    <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Send a message">
      <ContactFields id={id} v={v} set={(k, x) => set(k, x)} errors={errors} />
      <SelectField id={id("topic")} label="What is it about?" optional placeholder="Choose a service" value={v.topic} onChange={(x) => set("topic", x)} options={GENERAL_TOPICS} />
      <TextAreaField id={id("message")} label="Message" rows={5} value={v.message} onChange={(x) => set("message", x)} error={errors.message} maxLength={5000} />
      <CheckField id={id("marketing")} checked={v.marketing} onChange={(x) => set("marketing", x)}>
        Optional: email me now and then about RitaGreen services. I can unsubscribe at any time.
      </CheckField>
      <FormFailure message={form.failure} />
      <SubmitButton sending={form.status === "sending"}>Send message</SubmitButton>
      <PrivacyNote>We use these details to answer your message. That needs no tick above.</PrivacyNote>
    </form>
  )
}
