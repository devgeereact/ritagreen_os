import { useState } from "react"
import { CircleCheck, CircleMinus } from "lucide-react"
import { toast } from "sonner"
import { Card } from "@/components/ui/card"
import { Switch } from "@/components/ui/switch"
import { useFeatures } from "@/hooks/use-features"
import { ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import type { FeatureName } from "@/lib/types"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

// What each switch does and what the 2026 plan recommends (file 02, "Keep,
// switch off, retire"). The server enforces every switch except finance, which
// only hides the pages: see migration 036.
const COPY: Record<FeatureName, { label: string; does: string; advice: string }> = {
  finance: {
    label: "Finance",
    does: "Invoicing, quotes, client contracts and payroll in the menu. Hiding them keeps every record.",
    advice: "Off for launch in the plan. Turn on when the office wants these screens.",
  },
  online_payments: {
    label: "Online payments",
    does: "Card pay links through Stripe and Direct Debit through GoCardless. Payments already started still complete.",
    advice: "Recommended off until confirmed in use.",
  },
  sms_whatsapp: {
    label: "SMS and WhatsApp",
    does: "Text and WhatsApp messages to workers, such as shift offers. Email and in-app notices carry on. Skipped messages are logged.",
    advice: "Recommended off until confirmed in use.",
  },
  inbox: {
    label: "Shared inbox",
    does: "The company mailbox inside the office app. Staff can use their own mail program instead.",
    advice: "Recommended off until confirmed in use.",
  },
  ai_assistant: {
    label: "AI assistant",
    does: "The assistant in the header, AI drafting buttons and the weather panel on Today.",
    advice: "Being retired. Leave off.",
  },
}

export function FeaturesCard({ canEdit }: { canEdit: boolean }) {
  const { data, loading, error, reload, setFeature } = useFeatures()
  const [saving, setSaving] = useState<FeatureName | null>(null)

  async function toggle(name: FeatureName, enabled: boolean) {
    setSaving(name)
    try {
      await setFeature(name, enabled)
      toast.success(`${COPY[name].label} switched ${enabled ? "on" : "off"}.`)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "The switch could not be changed.")
    } finally {
      setSaving(null)
    }
  }

  return (
    <Card className="max-w-3xl">
      <div className="border-b p-6">
        <h2 className="text-lg font-semibold">Features</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Turn optional areas on or off for everyone. Switching off hides the area and the server refuses it; no
          records are deleted.
          {!canEdit && " Only an owner can change these."}
        </p>
      </div>

      {loading && !data && <div className="p-6"><LoadingBlock rows={5} /></div>}
      {error && !data && <div className="p-6"><ErrorBlock message={error} onRetry={reload} /></div>}

      {data && (
        <ul className="divide-y">
          {data.switches.map((s) => {
            const copy = COPY[s.name]
            if (!copy) return null
            const id = `feature-${s.name}`
            return (
              <li key={s.name} className="flex flex-col gap-3 p-6 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <label htmlFor={id} className="font-semibold">{copy.label}</label>
                    <StateChip on={s.enabled} />
                  </div>
                  <p className="text-sm">{copy.does}</p>
                  <p className="text-sm text-muted-foreground">{copy.advice}</p>
                  {s.updated_at && (
                    <p className="text-xs text-muted-foreground">
                      Last changed {shortDate(s.updated_at)}{s.updated_by ? ` by ${s.updated_by}` : ""}
                    </p>
                  )}
                </div>
                <Switch
                  id={id}
                  checked={s.enabled}
                  disabled={!canEdit || saving !== null}
                  onCheckedChange={(v) => toggle(s.name, v)}
                  className="shrink-0 sm:mt-1"
                />
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

// Status with a symbol and a word, never colour alone (docs/DESIGN_SYSTEM.md).
function StateChip({ on }: { on: boolean }) {
  return on ? (
    <span className="inline-flex items-center gap-1 rounded-md bg-success-soft px-2 py-0.5 text-xs font-semibold text-success">
      <CircleCheck className="size-3.5" aria-hidden="true" /> On
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 rounded-md bg-awaiting-soft px-2 py-0.5 text-xs font-semibold text-awaiting">
      <CircleMinus className="size-3.5" aria-hidden="true" /> Off
    </span>
  )
}
