import { useState } from "react"
import { ExternalLink } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import type { CompanyRecord } from "@/lib/company"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

// Website area: company details and social links (section S7, file 06). One
// record, read by the website's header, footer, contact page, /links and
// structured data. Owner only, and every change is audited. The website shows a
// change within five minutes; if this record ever cannot be read, it shows the
// facts bundled in src/lib/company.ts instead.

type Field = { key: keyof CompanyRecord; label: string; hint?: string; placeholder?: string; type?: string }

const DETAILS: Field[] = [
  { key: "trading_name", label: "Trading name" },
  { key: "legal_name", label: "Legal name", hint: "As on Companies House." },
  { key: "company_no", label: "Company number" },
  { key: "phone", label: "Office phone", type: "tel" },
  { key: "urgent_phone", label: "Urgent cover line", type: "tel", hint: "Shown as the 24hr line for urgent cover." },
  { key: "email", label: "Email", type: "email" },
  { key: "office_hours", label: "Office hours", placeholder: "Monday to Friday, 9am to 5pm" },
  { key: "area", label: "Area covered" },
  { key: "registered_address", label: "Registered office address", hint: "Leave empty until confirmed. Empty fields are not shown." },
]

const SOCIAL: Field[] = [
  { key: "instagram_url", label: "Instagram", placeholder: "https://instagram.com/ritagreenservices" },
  { key: "facebook_url", label: "Facebook", placeholder: "https://facebook.com/ritagreenservices" },
  { key: "linkedin_url", label: "LinkedIn", placeholder: "https://linkedin.com/company/..." },
  { key: "tiktok_url", label: "TikTok", placeholder: "https://www.tiktok.com/@ritagreenservices" },
  { key: "whatsapp_url", label: "WhatsApp enquiries", placeholder: "https://wa.me/44...", hint: "A public chat link for enquiries. Never the staff community invite." },
]

type Loaded = { company: CompanyRecord; updated_at: string | null; updated_by_name: string | null }

export default function CompanyDetails() {
  const { data, loading, error, reload } = useApi<Loaded>("company.php?action=edit")
  if (loading) return <LoadingBlock rows={6} />
  if (error) return <ErrorBlock message={error} onRetry={reload} />
  if (!data) return null
  // Keyed by the last save, so the form starts from what is stored after each one.
  return <Editor key={data.updated_at ?? "never"} loaded={data} onSaved={reload} />
}

function Editor({ loaded, onSaved }: { loaded: Loaded; onSaved: () => void }) {
  const [values, setValues] = useState<CompanyRecord>(loaded.company)
  const [saving, setSaving] = useState(false)
  const changed = (Object.keys(values) as (keyof CompanyRecord)[]).some((k) => values[k] !== loaded.company[k])

  async function save() {
    setSaving(true)
    try {
      await api("company.php?action=update", { method: "POST", body: values })
      toast.success("Saved. The website shows the change within five minutes.")
      onSaved()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save the company details.")
    } finally {
      setSaving(false)
    }
  }

  const field = (f: Field) => (
    <div key={f.key} className="grid gap-1.5">
      <Label htmlFor={`c-${f.key}`}>{f.label}</Label>
      <Input
        id={`c-${f.key}`}
        type={f.type ?? (f.key.endsWith("_url") ? "url" : "text")}
        value={values[f.key]}
        placeholder={f.placeholder}
        onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
      />
      {f.hint && <p className="text-xs text-muted-foreground">{f.hint}</p>}
    </div>
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Company details</h1>
          <p className="text-sm text-muted-foreground">
            Shown in the website header, footer, contact page and the /links page.
            {loaded.updated_at && ` Last changed ${shortDate(loaded.updated_at)}${loaded.updated_by_name ? ` by ${loaded.updated_by_name}` : ""}.`}
          </p>
        </div>
        <Button asChild variant="outline">
          <a href="/links" target="_blank" rel="noopener noreferrer">
            <ExternalLink className="mr-1 size-4" /> View /links
          </a>
        </Button>
      </div>

      <Card className="max-w-3xl p-6">
        <h2 className="text-sm font-semibold">Contact details</h2>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">{DETAILS.map(field)}</div>
      </Card>

      <Card className="max-w-3xl p-6">
        <h2 className="text-sm font-semibold">Social links</h2>
        <p className="mt-1 text-xs text-muted-foreground">
          Leave a network empty to hide it. Only https addresses on that network are accepted. X is not listed.
        </p>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">{SOCIAL.map(field)}</div>
      </Card>

      <div className="flex max-w-3xl justify-end gap-2">
        <Button variant="outline" disabled={!changed || saving} onClick={() => setValues(loaded.company)}>
          Undo changes
        </Button>
        <Button disabled={!changed || saving} onClick={save}>
          {saving ? "Saving..." : "Save and publish"}
        </Button>
      </div>
    </div>
  )
}
