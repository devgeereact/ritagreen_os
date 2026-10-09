import { CheckList, PageHeader, Section, SectionTitle, Steps } from "@/components/public/section"
import { Faqs } from "@/components/public/Faqs"
import { siteButton } from "@/components/public/site-button"
import { api } from "@/lib/api"
import { useCompanyDetails } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"
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

// /careers: work with RitaGreen. Restyled in S6 with new copy; the application
// still goes to ats.php, the applicant tracker, exactly as before. Vacancies
// managed in the office app, role pages and embedded application forms are
// section S7, which replaces the general form below with real roles.

// `division` is the ATS column an application is filed under, so the choice
// decides which recruiter sees it. "New to care" is the training route (they
// train first and are placed after), which is why it is filed under Training.
const ROLES: (Option & { division: "care" | "academy" | "cleaning" | "security" })[] = [
  { value: "Care assistant or support worker", label: "Care assistant or support worker", division: "care" },
  { value: "Senior carer", label: "Senior carer", division: "care" },
  { value: "Registered nurse (RGN or RMN)", label: "Registered nurse (RGN or RMN)", division: "care" },
  { value: "New to care, training first", label: "New to care, I would like to train first", division: "academy" },
  { value: "Cleaner", label: "Cleaner or cleaning supervisor", division: "cleaning" },
  { value: "Security officer", label: "Security officer or door supervisor", division: "security" },
  { value: "Something else", label: "Something else", division: "care" },
]

const STEPS = [
  { title: "Apply", body: "Tell us the work you are looking for. It takes a few minutes on a phone." },
  { title: "We talk", body: "Someone from the office calls you about the role and what happens next." },
  { title: "Checks, then shifts", body: "We complete your checks with you. Once they are done and you are approved, you can be booked." },
]

const WE_CHECK = [
  "Your right to work in the UK",
  "An enhanced DBS, where the role needs one",
  "References from previous employers",
  "Your qualifications: the Care Certificate, an NMC registration, an SIA licence or COSHH training, depending on the role",
]

const FAQS = [
  {
    q: "Do I need experience?",
    a: "For most healthcare, cleaning and security roles, yes, or the right qualification. If you are new to care, choose the training option and we will talk you through how to start.",
  },
  {
    q: "What happens after I apply?",
    a: "Someone from the office will contact you about the role. If it is a match, we arrange your checks together. You can be booked once they are complete and you have been approved.",
  },
  {
    q: "What documents will I need?",
    a: "Proof of your right to work, your qualifications and licences for the role, and details of people who can give a reference. We will tell you exactly what we need when we call.",
  },
  {
    q: "Is there a fee to apply or to work with you?",
    a: "No. We never charge you to apply.",
  },
]

type Values = { name: string; email: string; phone: string; role: string; about: string; consent: boolean }

export default function Careers() {
  const company = useCompanyDetails()
  useSeo({
    title: "Careers: healthcare, cleaning and security jobs in London",
    description:
      "Work with RitaGreen Services. Care assistant, support worker, nurse, cleaning and security roles across London. Apply in a few minutes.",
    path: "/careers",
  })

  const form = useEnquiryForm<Values>({
    initial: { name: "", email: "", phone: "", role: "", about: "", consent: false },
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
    // The applicant tracker, not the enquiry inbox: ats.php files the applicant
    // under the chosen service and resolves them to the one person record, so
    // somebody who applies twice lands on the record they already have.
    send: (v) => {
      const role = ROLES.find((r) => r.value === v.role)
      return api("ats.php", {
        method: "POST",
        auth: false,
        body: {
          name: v.name.trim(),
          email: v.email.trim(),
          phone: v.phone.trim(),
          division: role?.division ?? "care",
          role_applied: v.role,
          notes: v.about.trim(),
          consent: v.consent,
        },
      })
    },
  })
  const { values: v, set, errors } = form

  return (
    <>
      <PageHeader
        eyebrow="Careers"
        title="Join a team that looks after its people"
        intro={
          <p>
            We are looking for care assistants, support workers, nurses, cleaners and security officers across London.
            Tell us what you do and the office will be in touch.
          </p>
        }
      >
        <a href="#apply" className={siteButton()}>
          Apply now
        </a>
      </PageHeader>

      <Section tone="surface" labelledBy="how-title">
        <SectionTitle id="how-title" title="How it works" />
        <Steps items={STEPS} />
      </Section>

      <Section labelledBy="check-title">
        <div className="grid gap-10 md:grid-cols-2">
          <div>
            <SectionTitle
              id="check-title"
              title="What we check"
              intro="The same checks for everyone, so the people you work beside have been checked too:"
            />
            <CheckList items={WE_CHECK} className="mt-6" />
          </div>
          <div>
            <h2 id="faq-title" className="text-[1.625rem] leading-tight md:text-[2.125rem]">
              Questions
            </h2>
            <Faqs items={FAQS} className="mt-6" />
          </div>
        </div>
      </Section>

      <Section tone="surface" id="apply" labelledBy="apply-title">
        <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
          <SectionTitle
            id="apply-title"
            title="Apply"
            intro={`Questions first? Call the office on ${company.phone.display}, ${company.officeHours.toLowerCase()}.`}
          />
          <div className="rounded-lg border border-border bg-background p-5 sm:p-8">
            {form.status === "sent" ? (
              <FormSuccess title="Thank you, we have your application" onReset={form.reset} resetLabel="Send another application">
                <p>Someone from the office will be in touch about the role and what happens next.</p>
              </FormSuccess>
            ) : (
              <form onSubmit={form.onSubmit} noValidate className="grid gap-5" aria-label="Job application">
                <div className="grid gap-5 sm:grid-cols-2">
                  <TextField id="ap-name" label="Full name" value={v.name} onChange={(x) => set("name", x)} error={errors.name} autoComplete="name" maxLength={160} />
                  <TextField id="ap-phone" label="Phone" optional type="tel" value={v.phone} onChange={(x) => set("phone", x)} autoComplete="tel" inputMode="tel" maxLength={40} />
                  <TextField id="ap-email" label="Email" type="email" value={v.email} onChange={(x) => set("email", x)} error={errors.email} autoComplete="email" inputMode="email" maxLength={190} className="sm:col-span-2" />
                </div>
                <SelectField id="ap-role" label="The work you are interested in" value={v.role} onChange={(x) => set("role", x)} options={ROLES} error={errors.role} />
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
                <SubmitButton sending={form.status === "sending"}>Send application</SubmitButton>
                <PrivacyNote>We use these details to consider your application.</PrivacyNote>
              </form>
            )}
          </div>
        </div>
      </Section>
    </>
  )
}
