import { api } from "@/lib/api"
import type { ServiceId } from "@/lib/services"
import { STAFF_SERVICES, labelFor } from "@/components/public/forms/options"
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

// Request staff: posts to bookings.php, which files a new request in the office
// Bookings queue. The fields are the ones the 2026 plan lists (file 05):
// organisation, contact, service, role and number, site area, dates and times,
// notes. bookings.php stores the site and the free text in site_name and detail,
// so the extra fields are composed into those, one labelled line each.

type Values = {
  organisation: string
  name: string
  email: string
  phone: string
  service: string
  roles: string
  area: string
  start: string
  end: string
  pattern: string
  notes: string
  consent: boolean
}

export function RequestStaffForm({ service, idPrefix = "rs" }: { service?: Extract<ServiceId, "care" | "cleaning" | "security">; idPrefix?: string }) {
  const id = (k: string) => `${idPrefix}-${k}`
  const form = useEnquiryForm<Values>({
    initial: {
      organisation: "",
      name: "",
      email: "",
      phone: "",
      service: service ?? "",
      roles: "",
      area: "",
      start: "",
      end: "",
      pattern: "",
      notes: "",
      consent: false,
    },
    validate: (v) => {
      const e: FieldErrors<Values> = {}
      if (!v.organisation.trim()) e.organisation = "Tell us the organisation or site you are booking for."
      if (!v.name.trim()) e.name = "Tell us your name."
      if (!v.email.trim()) e.email = "Add an email so we can confirm the booking."
      else if (!EMAIL_PATTERN.test(v.email.trim())) e.email = "That email does not look right. Check it and try again."
      if (!v.service) e.service = "Choose the service you need."
      if (!v.roles.trim()) e.roles = "Tell us the role and how many people you need."
      if (v.start && v.end && v.end <= v.start) e.end = "The finish must be after the start."
      if (!v.consent) e.consent = "Tick the box so we can contact you about this request."
      return e
    },
    send: (v) => {
      const org = v.organisation.trim()
      const area = v.area.trim()
      const detail = [
        "Request staff",
        `Organisation: ${org}`,
        `Role and number: ${v.roles.trim()}`,
        `Site area: ${area || "not given"}`,
        `Dates and times: ${v.pattern.trim() || "see start and finish"}`,
        v.notes.trim() ? `\nNotes:\n${v.notes.trim()}` : "",
      ]
        .filter(Boolean)
        .join("\n")
      return api("bookings.php", {
        method: "POST",
        auth: false,
        body: {
          contact_name: v.name.trim(),
          email: v.email.trim(),
          phone: v.phone.trim(),
          division: v.service,
          site_name: area ? `${org}, ${area}` : org,
          detail,
          preferred_start: v.start,
          preferred_end: v.end,
          consent: v.consent,
        },
      })
    },
  })
  const { values: v, set, errors } = form

  if (form.status === "sent") {
    return (
      <FormSuccess title="Thank you, we have your request" onReset={form.reset} resetLabel="Request more staff">
        <p>
          Your request for {labelFor(STAFF_SERVICES, v.service).split(":")[0]} staff is with the office. Someone will
          call or email you to confirm who is coming and when. If the shift is within the next few hours, please call
          us as well.
        </p>
      </FormSuccess>
    )
  }

  return (
    <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Request staff">
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField id={id("organisation")} label="Organisation or site" value={v.organisation} onChange={(x) => set("organisation", x)} error={errors.organisation} autoComplete="organization" maxLength={110} />
        <TextField id={id("area")} label="Area or postcode" optional value={v.area} onChange={(x) => set("area", x)} maxLength={40} hint="For example SE13 or Lewisham." />
        <TextField id={id("name")} label="Your name" value={v.name} onChange={(x) => set("name", x)} error={errors.name} autoComplete="name" maxLength={160} />
        <TextField id={id("phone")} label="Phone" optional type="tel" value={v.phone} onChange={(x) => set("phone", x)} autoComplete="tel" inputMode="tel" maxLength={40} hint="The quickest way for us to confirm." />
        <TextField id={id("email")} label="Email" type="email" value={v.email} onChange={(x) => set("email", x)} error={errors.email} autoComplete="email" inputMode="email" maxLength={190} className="sm:col-span-2" />
      </div>
      {!service && (
        <SelectField id={id("service")} label="Service" value={v.service} onChange={(x) => set("service", x)} options={STAFF_SERVICES} error={errors.service} />
      )}
      <TextField id={id("roles")} label="Role and number of people" value={v.roles} onChange={(x) => set("roles", x)} error={errors.roles} maxLength={200} hint="For example: 2 care assistants, 1 senior carer." />
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField id={id("start")} label="First shift starts" optional type="datetime-local" value={v.start} onChange={(x) => set("start", x)} />
        <TextField id={id("end")} label="First shift finishes" optional type="datetime-local" value={v.end} onChange={(x) => set("end", x)} error={errors.end} />
      </div>
      <TextField id={id("pattern")} label="Dates and times" optional value={v.pattern} onChange={(x) => set("pattern", x)} maxLength={300} hint="For a pattern, for example: every Saturday in November, 08:00 to 20:00." />
      <TextAreaField id={id("notes")} label="Anything else we should know" optional value={v.notes} onChange={(x) => set("notes", x)} maxLength={3000} hint="Skills or experience the shift needs, parking, who to ask for on arrival." />
      <CheckField id={id("consent")} checked={v.consent} onChange={(x) => set("consent", x)} error={errors.consent} required>
        RitaGreen may contact me about this request and keep these details to arrange it.
      </CheckField>
      <FormFailure message={form.failure} />
      <SubmitButton sending={form.status === "sending"}>Send request</SubmitButton>
      <PrivacyNote>We use these details to arrange the staff you have asked for.</PrivacyNote>
    </form>
  )
}
