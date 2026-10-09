import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { useApi } from "@/hooks/use-api"
import { usePostAction } from "@/hooks/use-post-action"
import { serviceName } from "@/lib/people"
import type { OnboardingTemplateItem, ServiceId } from "@/lib/types"
import { Section } from "@/components/people/shared"

/**
 * The checklist each service's new workers start with.
 *
 * Starting onboarding copies the active items into the new worker's checklist.
 * Changing a list here changes what the next person is asked for, not anybody
 * already part way through. Items are retired rather than deleted.
 */
export function OnboardingTemplates() {
  const { data, loading, error, reload } = useApi<{ services: Partial<Record<ServiceId, OnboardingTemplateItem[]>> }>("onboarding-templates.php")
  if (loading && !data) return <LoadingBlock rows={4} />
  if (error) return <ErrorBlock message={error} onRetry={reload} />
  if (!data) return null

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        Each new worker's onboarding checklist starts from the list for their service. Tasks for one person only,
        such as a check a particular site needs, are added on that person's profile.
      </p>
      <div className="grid gap-4 lg:grid-cols-2">
        {(Object.entries(data.services) as [ServiceId, OnboardingTemplateItem[]][]).map(([service, items]) => (
          <ServiceList key={service} service={service} items={items} onChanged={reload} />
        ))}
      </div>
    </div>
  )
}

function ServiceList({ service, items, onChanged }: { service: ServiceId; items: OnboardingTemplateItem[]; onChanged: () => void }) {
  const { run, busy } = usePostAction(onChanged)
  const [label, setLabel] = useState("")
  const active = items.filter((i) => i.active)
  const retired = items.filter((i) => !i.active)

  return (
    <Section title={serviceName(service)} description={`${active.length} ${active.length === 1 ? "task" : "tasks"} for a new worker.`}>
      <ol className="space-y-1.5">
        {active.map((item, idx) => (
          <li key={item.id} className="flex items-center justify-between gap-2 text-sm">
            <span><span className="tabular-nums text-muted-foreground">{idx + 1}.</span> {item.label}</span>
            <Button size="sm" variant="ghost" disabled={busy}
              onClick={() => run(`onboarding-templates.php?action=update&id=${item.id}`, { active: false }, "Task retired.")}>
              Retire
            </Button>
          </li>
        ))}
      </ol>
      <form
        className="flex flex-wrap gap-2"
        onSubmit={async (e) => {
          e.preventDefault()
          if (await run("onboarding-templates.php", { service, label }, "Task added.")) setLabel("")
        }}
      >
        <Input aria-label={`Add a task to the ${serviceName(service)} list`} placeholder="Add a task" maxLength={160}
          value={label} onChange={(e) => setLabel(e.target.value)} className="min-w-[10rem] flex-1" />
        <Button type="submit" size="sm" variant="outline" disabled={busy || label.trim() === ""}>Add</Button>
      </form>
      {retired.length > 0 && (
        <details className="text-sm">
          <summary className="cursor-pointer text-muted-foreground">Retired ({retired.length})</summary>
          <ul className="mt-2 space-y-1.5">
            {retired.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground">{item.label}</span>
                <Button size="sm" variant="ghost" disabled={busy}
                  onClick={() => run(`onboarding-templates.php?action=update&id=${item.id}`, { active: true }, "Task restored.")}>
                  Restore
                </Button>
              </li>
            ))}
          </ul>
        </details>
      )}
    </Section>
  )
}
