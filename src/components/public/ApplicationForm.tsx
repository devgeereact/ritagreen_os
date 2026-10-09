import { api } from "@/lib/api"
import type { ServiceId } from "@/lib/services"
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
import type { Option } from "@/components/public/forms/options"

// The short native application form. It posts to ats.php, the applicant
// tracker, which files the applicant under a service and resolves them to the
// one person record, so somebody who applies twice lands on the record they
// already have.
//
// Two uses (section S7):
//   - /careers, "Register your interest": the visitor picks the kind of work.
//   - a role page whose vacancy has no linked Google Form: the role is fixed.
// A role with a linked form never shows this as well: one intake path per role
// (2026 plan, file 06).

// `division` is the ATS column an application is filed under, so the choice
// decides which recruiter sees it. "New to care" is the training route (they
// train first and are placed after), which is why it is filed under Training.
const ROLES: (Option & { division: ServiceId })[] = [
  { value: "Care assistant or support worker", label: "Care assistant or support worker", division: "care" },
  { value: "Senior carer", label: "Senior carer", division: "care" },
  { value: "Registered nurse (RGN or RMN)", label: "Registered nurse (RGN or RMN)", division: "care" },
  { value: "New to care, training first", label: "New to care, I would like to train first", division: "academy" },
  { value: "Cleaner", label: "Cleaner or cleaning supervisor", division: "cleaning" },
  { value: "Security officer", label: "Security officer or door supervisor", division: "security" },
  { value: "Something else", label: "Something else", division: "care" },
]

type Values = { name: string; email: string; phone: string; role: string; about: string; consent: boolean }

export function ApplicationForm({
  fixedRole,
  submitLabel = "Send application",
}: {
  /** A role page: the role and its service are known, so there is no role picker. */
  fixedRole?: { title: string; service: ServiceId }
  submitLabel?: string
}) {
  const form = useEnquiryForm<Values>({
    initial: { name: "", email: "", phone: "", role: fixedRole?.title ?? "", about: "", consent: false },
    validate: (v) => {
      const e: FieldErrors<Values> = {}
      if (!v.name.trim()) e.name = "Tell us your name."
      if (!v.email.trim()) e.email = "Add an email so we can reply."
      else if (!EMAIL_PATTERN.test(v.email.trim())) e.email = "That email does not look right. Check it and try again."
      if (!v.role) e.role = "Choose the work you are interested in."
      if (!v.about.trim()) e.about = "Tell us a little about yourself."
      if (!v.consent) e.consent = "Tick the box so we can contact you about your application."
      return e
    },
    send: (v) => {
      const division = fixedRole?.service ?? ROLES.find((r) => r.value === v.role)?.division ?? "care"
      return api("ats.php", {
        method: "POST",
        auth: false,
        body: {
          name: v.name.trim(),
          email: v.email.trim(),
          phone: v.phone.trim(),
          division,
          role_applied: fixedRole?.title ?? v.role,
          notes: v.about.trim(),
          consent: v.consent,
        },
      })
    },
  })
  const { values: v, set, errors } = form

  if (form.status === "sent") {
    return (
      <FormSuccess title="Thank you, we have your details" onReset={form.reset} resetLabel="Send another">
        <p>Someone from the office will be in touch about the work and what happens next.</p>
      </FormSuccess>
    )
  }

  return (
    <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Job application">
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField id="ap-name" label="Full name" value={v.name} onChange={(x) => set("name", x)} error={errors.name} autoComplete="name" maxLength={160} />
        <TextField id="ap-phone" label="Phone" optional type="tel" value={v.phone} onChange={(x) => set("phone", x)} autoComplete="tel" inputMode="tel" maxLength={40} />
        <TextField id="ap-email" label="Email" type="email" value={v.email} onChange={(x) => set("email", x)} error={errors.email} autoComplete="email" inputMode="email" maxLength={190} className="sm:col-span-2" />
      </div>
      {!fixedRole && (
        <SelectField id="ap-role" label="The work you are interested in" value={v.role} onChange={(x) => set("role", x)} options={ROLES} error={errors.role} />
      )}
      <TextAreaField
        id="ap-about"
        label="A little about you"
        rows={5}
        value={v.about}
        onChange={(x) => set("about", x)}
        error={errors.about}
        maxLength={3000}
        hint="Your experience, qualifications or licences, where you can travel to and when you can work."
      />
      <CheckField id="ap-consent" checked={v.consent} onChange={(x) => set("consent", x)} error={errors.consent} required>
        RitaGreen may keep these details and contact me about my application.
      </CheckField>
      <FormFailure message={form.failure} />
      <SubmitButton sending={form.status === "sending"}>{submitLabel}</SubmitButton>
      <PrivacyNote>We use these details to consider your application.</PrivacyNote>
    </form>
  )
}
