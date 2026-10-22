import { AlertTriangle, CheckCircle2, CircleHelp, MinusCircle, XCircle } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { useApi } from "@/hooks/use-api"
import type { SystemHealth } from "@/lib/types"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"
import { cn } from "@/lib/utils"

type Tone = "ok" | "warn" | "fail" | "off" | "unknown" | "late"

const TONE: Record<Tone, { icon: typeof CheckCircle2; label: string; className: string }> = {
  ok: { icon: CheckCircle2, label: "Working", className: "text-success" },
  warn: { icon: AlertTriangle, label: "Check", className: "text-warning" },
  late: { icon: AlertTriangle, label: "Late", className: "text-destructive" },
  fail: { icon: XCircle, label: "Failing", className: "text-destructive" },
  off: { icon: MinusCircle, label: "Switched off", className: "text-muted-foreground" },
  unknown: { icon: CircleHelp, label: "Unknown", className: "text-warning" },
}

/** A symbol and a word, never colour alone (docs/DESIGN_SYSTEM.md). */
function StateLabel({ state }: { state: Tone }) {
  const t = TONE[state]
  const Icon = t.icon
  return (
    <span className={cn("inline-flex items-center gap-1 text-sm font-medium", t.className)}>
      <Icon className="size-4" aria-hidden /> {t.label}
    </span>
  )
}

const when = (utc: string | null): string => (utc ? `${utc.slice(0, 16).replace("T", " ")} UTC` : "Never")

/**
 * System health in Settings (section S5). Reads server/system-health.php:
 * the checks, when each scheduled job last ran, the email queue and form
 * intake. A job that has never reported is shown as unknown, not as fine.
 */
export function SystemHealthTab() {
  const { data, loading, error, reload } = useApi<SystemHealth>("system-health.php")
  if (loading && !data) return <LoadingBlock rows={4} />
  if (error) return <ErrorBlock message={error} onRetry={reload} />
  if (!data) return null

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">Checked {data.generated_at} UTC. Every line is a live check, not a stored status.</p>
        <Button size="sm" variant="outline" onClick={reload}>Check again</Button>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-base">Scheduled jobs</CardTitle></CardHeader>
        <CardContent className="px-0">
          <ul className="divide-y divide-border">
            {data.jobs.map((j) => (
              <li key={j.key} className="flex flex-col gap-1 px-6 py-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                  <p className="font-medium">{j.label}</p>
                  <p className="text-sm text-muted-foreground">
                    {j.last_success_at ? `Last ran ${when(j.last_success_at)}` : "No successful run recorded"}{j.last_detail ? `: ${j.last_detail}` : ""}. Expected every {j.every_hours === 1 ? "hour" : j.every_hours === 24 ? "day" : j.every_hours === 168 ? "week" : `${j.every_hours} hours`}.
                  </p>
                  {j.note && <p className="text-sm text-muted-foreground">{j.note}</p>}
                </div>
                <StateLabel state={j.state} />
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle className="text-base">Email queue</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            {!data.mail_queue.enabled && <p className="text-sm text-muted-foreground">Outgoing mail is switched off in the server configuration. Nothing is sent.</p>}
            <dl className="grid grid-cols-3 gap-2 text-sm">
              {(["queued", "sent", "failed"] as const).map((k) => (
                <div key={k}>
                  <dt className="text-xs capitalize text-muted-foreground">{k}</dt>
                  <dd className={cn("text-lg font-semibold tabular-nums", k === "failed" && data.mail_queue.counts.failed > 0 && "text-destructive")}>{data.mail_queue.counts[k]}</dd>
                </div>
              ))}
            </dl>
            {data.mail_queue.recent_failures.length === 0 ? (
              <p className="text-sm text-muted-foreground">No failed emails.</p>
            ) : (
              <ul className="space-y-2">
                {data.mail_queue.recent_failures.map((f) => (
                  <li key={f.id} className="rounded-md border border-border p-2 text-sm">
                    <p className="font-medium">{f.subject}</p>
                    <p className="text-xs text-muted-foreground">{f.created_at.slice(0, 16)} · {f.attempts} attempts · {f.last_error ?? "No error recorded"}</p>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle className="text-base">Form intake</CardTitle></CardHeader>
          <CardContent className="px-0">
            {data.forms.length === 0 ? (
              <p className="px-6 text-sm text-muted-foreground">No forms are connected.</p>
            ) : (
              <ul className="divide-y divide-border">
                {data.forms.map((f) => (
                  <li key={f.id} className="flex items-start justify-between gap-3 px-6 py-3">
                    <div className="min-w-0">
                      <p className="font-medium">{f.name}</p>
                      <p className="text-sm text-muted-foreground">
                        {!f.live ? "Not connected yet." : `Last received ${when(f.last_success_at)}.`}
                        {f.failed > 0 && ` ${f.failed} could not be read.`}
                        {f.quiet && " Nothing received in the expected time."}
                      </p>
                    </div>
                    <StateLabel state={f.state} />
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-base">Other checks</CardTitle></CardHeader>
        <CardContent className="px-0">
          <ul className="divide-y divide-border">
            {data.checks.filter((c) => c.key !== "cron").map((c) => (
              <li key={c.key} className="flex flex-col gap-1 px-6 py-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                  <p className="font-medium">{c.label}</p>
                  <p className="text-sm text-muted-foreground">{c.detail}</p>
                </div>
                <StateLabel state={c.state} />
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  )
}
