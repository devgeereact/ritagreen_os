import { PageHeader, Section } from "@/components/public/section"

type LegalKind = "privacy" | "terms" | "cookies"

const CONTENT: Record<LegalKind, { title: string; intro: string; sections: { h: string; p: string }[] }> = {
  privacy: {
    title: "Privacy policy",
    intro: "How RitaGreen Healthcare Services Ltd collects, uses and protects personal data.",
    sections: [
      { h: "Who we are", p: "RitaGreen Healthcare Services Ltd (Company No. 12528439) is the data controller for the personal data described in this policy." },
      { h: "What we collect", p: "We collect the details you give us through enquiry forms, the data needed to deliver our services, and the records required to employ and check our staff." },
      { h: "How we use it", p: "We use personal data to respond to enquiries, deliver care, training, cleaning and security, meet our legal and regulatory duties, and run our business." },
      { h: "How long we keep it", p: "We keep personal data only as long as we need it for the purpose it was collected, or as the law requires, then delete it." },
      { h: "Your rights", p: "Under UK GDPR you can ask for a copy of your data, ask us to correct or delete it, or object to certain uses. Contact us to make a request." },
    ],
  },
  terms: {
    title: "Terms of use",
    intro: "The terms that apply when you use this website.",
    sections: [
      { h: "Using this site", p: "This website is provided for information about RitaGreen's services. You agree to use it lawfully and not to misuse it." },
      { h: "Our content", p: "The content on this site belongs to RitaGreen unless stated otherwise. You may not copy or reuse it without permission." },
      { h: "Service terms", p: "Engaging any RitaGreen service is governed by a separate written agreement. These website terms do not form that agreement." },
      { h: "Liability", p: "We take care to keep this site accurate, but we do not guarantee it is error-free and accept no liability for reliance on it." },
    ],
  },
  cookies: {
    title: "Cookie policy",
    intro: "How this website uses cookies and similar technologies.",
    sections: [
      { h: "What cookies are", p: "Cookies are small files stored on your device that help a website work and remember your preferences." },
      { h: "How we use them", p: "We use only the cookies needed to run the site and, where you agree, to understand how it is used so we can improve it." },
      { h: "Managing cookies", p: "You can control or delete cookies through your browser settings. Blocking some may affect how the site works." },
    ],
  },
}

export default function Legal({ kind }: { kind: LegalKind }) {
  const c = CONTENT[kind]
  return (
    <>
      <PageHeader eyebrow="Legal" title={c.title} intro={c.intro} />
      <Section className="max-w-3xl">
        <p className="text-sm text-muted-foreground">
          Last updated {new Date().toLocaleDateString("en-GB", { month: "long", year: "numeric" })}.
          This is placeholder wording and should be reviewed by a solicitor before launch.
        </p>
        <div className="mt-8 space-y-8">
          {c.sections.map((s) => (
            <div key={s.h}>
              <h2 className="text-lg font-semibold">{s.h}</h2>
              <p className="mt-2 text-muted-foreground">{s.p}</p>
            </div>
          ))}
        </div>
      </Section>
    </>
  )
}
