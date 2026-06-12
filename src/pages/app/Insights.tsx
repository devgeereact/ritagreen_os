import { useMemo } from "react"
import { AlertTriangle, ShieldAlert, UserX, TrendingDown, Activity } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { useApi } from "@/hooks/use-api"
import type { Worker, ComplianceItem, Client, Job, Invoice } from "@/lib/types"
import { DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

// Operational risk signals, computed client-side from the live CRM. These are
// heuristic early-warnings — "who is about to fall out of compliance" and
// "which clients look like they're going quiet" — not statistical forecasts.
// They get sharper as more history accumulates.

type Tone = "red" | "amber" | "green"
type Scored<T> = { item: T; score: number; tone: Tone; reasons: string[] }

const DAY = 86_400_000
function daysBetween(iso: string | null, now: number): number | null {
  if (!iso) return null
  const t = Date.parse(iso.replace(" ", "T"))
  return Number.isNaN(t) ? null : Math.round((t - now) / DAY)
}
function toneFor(score: number): Tone {
  return score >= 60 ? "red" : score >= 25 ? "amber" : "green"
}

export default function Insights() {
  const workers = useApi<{ workers: Worker[] }>("workers.php")
  const compliance = useApi<{ items: ComplianceItem[] }>("compliance.php")
  const clients = useApi<{ clients: Client[] }>("clients.php")
  const jobs = useApi<{ jobs: Job[] }>("jobs.php")
  const invoices = useApi<{ invoices: Invoice[] }>("invoices.php")

  const loading = workers.loading || compliance.loading || clients.loading || jobs.loading || invoices.loading
  const error = workers.error || compliance.error || clients.error || jobs.error || invoices.error
  const reload = () => {
    workers.reload(); compliance.reload(); clients.reload(); jobs.reload(); invoices.reload()
  }

  const now = Date.now()

  // ---- Workforce deployability / no-show risk ----
  const workerRisk = useMemo<Scored<Worker>[]>(() => {
    const items = compliance.data?.items ?? []
    const byWorker = new Map<number, ComplianceItem[]>()
    for (const c of items) {
      const arr = byWorker.get(c.worker_id) ?? []
      arr.push(c); byWorker.set(c.worker_id, arr)
    }
    return (workers.data?.workers ?? [])
      .map((w): Scored<Worker> => {
        let score = 0
        const reasons: string[] = []
        if (!w.right_to_work) { score += 80; reasons.push("No right-to-work on file") }
        const dbsDays = daysBetween(w.dbs_expiry, now)
        if (w.dbs_status === "expired" || (dbsDays !== null && dbsDays < 0)) {
          score += 70; reasons.push("DBS expired")
        } else if (dbsDays !== null && dbsDays <= 30) {
          score += 35; reasons.push(`DBS expires in ${dbsDays}d`)
        }
        if (w.division === "security") {
          const siaDays = daysBetween(w.sia_expiry, now)
          if (!w.sia_expiry || (siaDays !== null && siaDays < 0)) {
            score += 70; reasons.push("SIA licence missing/expired — cannot be scheduled")
          } else if (siaDays !== null && siaDays <= 30) {
            score += 40; reasons.push(`SIA expires in ${siaDays}d`)
          }
        }
        // certifications table signals
        const certs = byWorker.get(w.id) ?? []
        const expired = certs.filter((c) => c.status === "expired")
        const expiring = certs.filter((c) => c.status === "expiring")
        for (const c of expired) { score += 30; reasons.push(`${c.type} expired`) }
        for (const c of expiring) {
          score += 18
          reasons.push(`${c.type} expires${c.days != null ? ` in ${c.days}d` : " soon"}`)
        }
        if (w.status === "inactive") { score += 15; reasons.push("Marked inactive") }
        return { item: w, score: Math.min(score, 100), tone: toneFor(score), reasons }
      })
      .filter((r) => r.reasons.length > 0)
      .sort((a, b) => b.score - a.score)
  }, [workers.data, compliance.data, now])

  // ---- Client churn risk ----
  const clientRisk = useMemo<Scored<Client>[]>(() => {
    const jobsByClient = new Map<number, Job[]>()
    for (const j of jobs.data?.jobs ?? []) {
      if (j.client_id == null) continue
      const a = jobsByClient.get(j.client_id) ?? []; a.push(j); jobsByClient.set(j.client_id, a)
    }
    const invByClient = new Map<number, Invoice[]>()
    for (const i of invoices.data?.invoices ?? []) {
      if (i.client_id == null) continue
      const a = invByClient.get(i.client_id) ?? []; a.push(i); invByClient.set(i.client_id, a)
    }
    return (clients.data?.clients ?? [])
      .map((c): Scored<Client> => {
        let score = 0
        const reasons: string[] = []
        if (c.status === "paused") { score += 55; reasons.push("Account paused") }
        if (c.status === "prospect") { score += 20; reasons.push("Still a prospect — not yet converted") }

        const cJobs = jobsByClient.get(c.id) ?? []
        const cInv = invByClient.get(c.id) ?? []
        const lastActivity = [...cJobs.map((j) => j.scheduled_for || j.created_at), ...cInv.map((i) => i.created_at)]
          .map((d) => daysBetween(d, now))
          .filter((n): n is number => n !== null)
          .map((n) => -n) // age in days
        const ageDays = lastActivity.length ? Math.min(...lastActivity) : null

        if (c.status === "active" && cJobs.length === 0 && cInv.length === 0) {
          score += 45; reasons.push("Active client with no jobs or invoices")
        } else if (ageDays !== null && ageDays > 60) {
          score += 40; reasons.push(`No activity in ${ageDays}d`)
        } else if (ageDays !== null && ageDays > 30) {
          score += 20; reasons.push(`Quiet for ${ageDays}d`)
        }

        const overdue = cInv.filter((i) => i.status === "overdue")
        if (overdue.length) { score += 25; reasons.push(`${overdue.length} overdue invoice${overdue.length > 1 ? "s" : ""}`) }

        return { item: c, score: Math.min(score, 100), tone: toneFor(score), reasons }
      })
      .filter((r) => r.reasons.length > 0)
      .sort((a, b) => b.score - a.score)
  }, [clients.data, jobs.data, invoices.data, now])

  const workersRed = workerRisk.filter((r) => r.tone === "red").length
  const clientsAtRisk = clientRisk.filter((r) => r.tone !== "green").length

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Insights</h1>
        <p className="text-sm text-muted-foreground">
          Early warnings from your live data — who needs attention before it becomes a problem.
        </p>
      </div>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {!loading && !error && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Kpi icon={ShieldAlert} label="Workers — urgent" value={workersRed} tone={workersRed ? "red" : "green"} />
            <Kpi icon={AlertTriangle} label="Workers flagged" value={workerRisk.length} tone={workerRisk.length ? "amber" : "green"} />
            <Kpi icon={TrendingDown} label="Clients at risk" value={clientsAtRisk} tone={clientsAtRisk ? "amber" : "green"} />
            <Kpi icon={Activity} label="Accounts reviewed" value={clientRisk.length} tone="primary" />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <UserX className="size-4 text-primary" /> Deployability & cover risk
                </CardTitle>
              </CardHeader>
              <CardContent>
                {workerRisk.length === 0 ? (
                  <Empty text="Every worker is clear to be scheduled. Nothing expiring soon." />
                ) : (
                  <ul className="space-y-3">
                    {workerRisk.map((r) => (
                      <RiskRow
                        key={r.item.id}
                        tone={r.tone}
                        title={r.item.name}
                        sub={`${r.item.role} · ${DIVISION_LABELS[r.item.division] ?? r.item.division}`}
                        reasons={r.reasons}
                      />
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <TrendingDown className="size-4 text-primary" /> Client churn risk
                </CardTitle>
              </CardHeader>
              <CardContent>
                {clientRisk.length === 0 ? (
                  <Empty text="No churn signals. Every client looks engaged." />
                ) : (
                  <ul className="space-y-3">
                    {clientRisk.map((r) => (
                      <RiskRow
                        key={r.item.id}
                        tone={r.tone}
                        title={r.item.name}
                        sub={r.item.type.replace("_", " ")}
                        reasons={r.reasons}
                      />
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          <p className="text-xs text-muted-foreground">
            Heuristic signals from current records — they sharpen as more job and payment history builds up.
          </p>
        </>
      )}
    </div>
  )
}

const TONE_DOT: Record<Tone, string> = {
  red: "bg-destructive",
  amber: "bg-amber-500",
  green: "bg-primary",
}

function RiskRow({ tone, title, sub, reasons }: { tone: Tone; title: string; sub: string; reasons: string[] }) {
  return (
    <li className="flex gap-3 rounded-xl border border-border bg-card/50 p-3">
      <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${TONE_DOT[tone]}`} />
      <div className="min-w-0">
        <div className="flex items-baseline gap-2">
          <span className="font-medium">{title}</span>
          <span className="truncate text-xs capitalize text-muted-foreground">{sub}</span>
        </div>
        <p className="mt-0.5 text-sm text-muted-foreground">{reasons.join(" · ")}</p>
      </div>
    </li>
  )
}

function Empty({ text }: { text: string }) {
  return <p className="py-10 text-center text-sm text-muted-foreground">{text}</p>
}

function Kpi({
  icon: Icon,
  label,
  value,
  tone = "primary",
}: {
  icon: React.ComponentType<{ className?: string }>
  label: string
  value: string | number
  tone?: "primary" | "red" | "amber" | "green"
}) {
  const color =
    tone === "red" ? "text-destructive" : tone === "amber" ? "text-amber-500" : "text-primary"
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-muted-foreground">{label}</span>
          <Icon className={`size-4 ${color}`} />
        </div>
        <p className="mt-2 text-2xl font-bold">{value}</p>
      </CardContent>
    </Card>
  )
}
