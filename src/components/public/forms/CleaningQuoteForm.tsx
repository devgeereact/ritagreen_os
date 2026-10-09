import { api } from "@/lib/api"
import { CLEANING_FREQUENCIES, CLEANING_SITE_TYPES } from "@/components/public/forms/options"
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

// Cleaning quote: posts to quotes.php?action=request, which files a Cleaning
// request in the office Bookings queue for somebody to price. It used to post to
// contact.php as free text. Fields from the 2026 plan (file 05): organisation,
// contact, site type, size, frequency, start date.

type Values = {
  organisation: string
  name: string
  email: string
  phone: string
  siteType: string
  area: string
  size: string
  frequency: string
  startDate: string
  notes: string
  consent: boolean
  marketing: boolean
}

export function CleaningQuoteForm({ idPrefix = "cq" }: { idPrefix?: string }) {
  const id = (k: string) => `${idPrefix}-${k}`
  const form = useEnquiryForm<Values>({
    initial: {
      organisation: "",
      name: "",
      email: "",
      phone: "",
      siteType: "",
      area: "",
      size: "",
      frequency: "",
      startDate: "",
      notes: "",
      consent: false,
      marketing: false,
    },
    validate: (v) => {
      const e: FieldErrors<Values> = {}
      if (!v.name.trim()) e.name = "Tell us your name."
      if (!v.email.trim()) e.email = "Add an email so we can send the quote."
      else if (!EMAIL_PATTERN.test(v.email.trim())) e.email = "That email does not look right. Check it and try again."
      if (!v.siteType) e.siteType = "Choose the type of site."
      if (!v.frequency) e.frequency = "Choose how often you need cleaning."
      if (!v.consent) e.consent = "Tick the box so we can contact you about this quote."
      return e
    },
    send: (v) =>
      api("quotes.php?action=request", {
        method: "POST",
        auth: false,
        body: {
          organisation: v.organisation.trim(),
          contact_name: v.name.trim(),
          email: v.email.trim(),
          phone: v.phone.trim(),
          site_type: v.siteType,
          area: v.area.trim(),
          size: v.size.trim(),
          frequency: v.frequency,
          start_date: v.startDate,
          notes: v.notes.trim(),
          consent: v.consent,
          marketing_consent: v.marketing,
        },
      }),
  })
  const { values: v, set, errors } = form

  if (form.status === "sent") {
    return (
      <FormSuccess title="Thank you, we have your quote request" onReset={form.reset} resetLabel="Ask for another quote">
        <p>
          The office will look at the details and come back to you to talk through the site before we put a price in
          writing.
        </p>
      </FormSuccess>
    )
  }

  return (
    <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Cleaning quote">
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField id={id("organisation")} label="Organisation" optional value={v.organisation} onChange={(x) => set("organisation", x)} autoComplete="organization" maxLength={160} />
        <TextField id={id("name")} label="Your name" value={v.name} onChange={(x) => set("name", x)} error={errors.name} autoComplete="name" maxLength={160} />
        <TextField id={id("email")} label="Email" type="email" value={v.email} onChange={(x) => set("email", x)} error={errors.email} autoComplete="email" inputMode="email" maxLength={190} />
        <TextField id={id("phone")} label="Phone" optional type="tel" value={v.phone} onChange={(x) => set("phone", x)} autoComplete="tel" inputMode="tel" maxLength={40} />
        <SelectField id={id("site-type")} label="Type of site" value={v.siteType} onChange={(x) => set("siteType", x)} options={CLEANING_SITE_TYPES} error={errors.siteType} />
        <TextField id={id("area")} label="Area or postcode" optional value={v.area} onChange={(x) => set("area", x)} maxLength={120} />
        <TextField id={id("size")} label="Size" optional value={v.size} onChange={(x) => set("size", x)} maxLength={200} hint="For example: 3 floors, 12 flats, or 400 square metres." />
        <SelectField id={id("frequency")} label="How often" value={v.frequency} onChange={(x) => set("frequency", x)} options={CLEANING_FREQUENCIES} error={errors.frequency} />
        <TextField id={id("start")} label="Preferred start date" optional type="date" value={v.startDate} onChange={(x) => set("startDate", x)} />
      </div>
      <TextAreaField id={id("notes")} label="Anything else" optional value={v.notes} onChange={(x) => set("notes", x)} maxLength={3000} hint="Areas to include, access, keys, anything you are unhappy with today." />
      <CheckField id={id("consent")} checked={v.consent} onChange={(x) => set("consent", x)} error={errors.consent} required>
        RitaGreen may contact me about this quote and keep these details to prepare it.
      </CheckField>
      <CheckField id={id("marketing")} checked={v.marketing} onChange={(x) => set("marketing", x)}>
        Optional: email me now and then about RitaGreen services. I can unsubscribe at any time.
      </CheckField>
      <FormFailure message={form.failure} />
      <SubmitButton sending={form.status === "sending"}>Ask for a quote</SubmitButton>
      <PrivacyNote>We use these details to prepare your quote.</PrivacyNote>
    </form>
  )
}
