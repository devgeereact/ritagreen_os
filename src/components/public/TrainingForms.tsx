import { Section, SectionTitle } from "@/components/public/section"
import { GoogleFormEmbed } from "@/components/public/GoogleFormEmbed"
import { useApi } from "@/hooks/use-api"
import type { CompanyDetails } from "@/lib/company"

type PublicTrainingForm = {
  title: string
  service: string | null
  form: { id: string; display: "embedded" | "link" }
}

/**
 * Training forms on /training (S10): every active form in the forms directory
 * whose purpose is "training", shown the way a role page shows its application
 * form. Nothing loads from Google until the visitor presses the button, the
 * "open in a new tab" link and the phone number are always there, and the id
 * comes from server/website-forms.php?action=public, which sends only valid ids.
 *
 * No forms, or the read failing, shows nothing: the enquiry form further down
 * the page is still the way to ask about training.
 */
export function TrainingForms({ company }: { company: CompanyDetails }) {
  const { data } = useApi<{ forms: PublicTrainingForm[] }>("website-forms.php?action=public&purpose=training")
  const forms = data?.forms ?? []
  if (forms.length === 0) return null

  return (
    <Section id="training-forms" labelledBy="training-forms-title">
      <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
        <SectionTitle
          id="training-forms-title"
          title="Training forms"
          intro="Book or register through a short Google Form. Google shows its own confirmation when you send it."
        />
        <div className="grid gap-10">
          {forms.map((f) => (
            <div key={f.form.id} className="grid gap-3">
              <h3 className="text-xl font-semibold">{f.title}</h3>
              <GoogleFormEmbed formId={f.form.id} display={f.form.display} roleTitle={f.title} company={company} kind="training" />
            </div>
          ))}
        </div>
      </div>
    </Section>
  )
}
