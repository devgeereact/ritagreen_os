import { Link } from "react-router-dom"
import { ArrowRight, CalendarX2, CheckCircle2, ClipboardCheck, Clock, Plus, UserCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import type { ActionCentre, ActionItem, RotaShift, TodayTile } from "@/lib/types"
import { todayIso } from "@/lib/rota"
import { serviceLabel } from "@/lib/services"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"
import { ServiceDot, StateChip } from "@/components/rota/bits"
import { cn } from "@/lib/utils"

/**
 * Today: what needs doing now (plan file 04, "Office app layout").
 *
 * Built on server/action-centre.php, which decides what this account may see:
 * a tile the account may not see comes back null and is left out, so a zero
 * always means "nothing to do", never "not yours to know". No charts, no money.
 */
export default function Today() {
  const { hasRole } = useAuth()
  // The rota is office-only on the server (scheduling.php).
  const isOffice = hasRole("super_admin", "admin", "manager")
  const centre = useApi<ActionCentre>("action-centre.php")
  const today = todayIso()
  const shifts = useApi<{ shifts: RotaShift[] }>(isOffice ? `scheduling.php?from=${today}&to=${today}` : null, [today])

  const heading = new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Today, {heading}</h1>
          <p className="text-sm text-muted-foreground">
            What needs doing now{centre.data?.scope ? `, for ${serviceLabel(centre.data.scope)}` : ""}.
          </p>
        </div>
        {isOffice && (
          <Button asChild>
            <Link to="/app/scheduling"><Plus className="mr-1 size-4" aria-hidden /> Add shift</Link>
          </Button>
        )}
      </div>

      {centre.loading && !centre.data && <LoadingBlock rows={3} />}
      {centre.error && <ErrorBlock message={centre.error} onRetry={centre.reload} />}

      {centre.data && <Tiles tiles={centre.data.tiles} />}

      {isOffice && (
        <section aria-labelledby="today-shifts" className="space-y-2">
          <div className="flex items-center justify-between">
            <h2 id="today-shifts" className="text-base font-semibold">Today's shifts</h2>
            <Link to="/app/scheduling" className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
              Open rota <ArrowRight className="size-3.5" aria-hidden />
            </Link>
          </div>
          {shifts.loading && !shifts.data && <LoadingBlock rows={2} />}
          {shifts.error && <ErrorBlock message={shifts.error} onRetry={shifts.reload} />}
          {shifts.data && <ShiftList shifts={shifts.data.shifts.filter((s) => s.state !== "cancelled")} />}
        </section>
      )}

      {centre.data && (
        <section aria-labelledby="needs-action" className="space-y-2">
          <h2 id="needs-action" className="text-base font-semibold">Needs action</h2>
          {centre.data.items.length === 0 ? (
            <Card>
              <CardContent className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                <CheckCircle2 className="size-4 text-success" aria-hidden />
                Nothing waiting on you. Checks, applicants, timesheets and requests are all up to date.
              </CardContent>
            </Card>
          ) : (
            <Card>
              <ul className="divide-y divide-border">
                {centre.data.items.map((it, i) => <ActionRow key={`${it.kind}-${i}`} item={it} />)}
              </ul>
            </Card>
          )}
          <p className="text-xs text-muted-foreground">Checked at {centre.data.generated_at.slice(11)} UTC.</p>
        </section>
      )}
    </div>
  )
}

const TILE_DEFS = [
  { key: "unfilled_shifts", label: "Unfilled shifts", sub: "next 7 days", icon: CalendarX2, none: "Every shift this week has somebody on it." },
  { key: "checks_to_review", label: "Checks to review", sub: "evidence received", icon: ClipboardCheck, none: "No evidence waiting for a reviewer." },
  { key: "expiring_30", label: "Evidence expiring", sub: "within 30 days", icon: Clock, none: "Nothing lapses in the next 30 days." },
  { key: "approvals_waiting", label: "Approvals waiting", sub: "ready to approve", icon: UserCheck, none: "Nobody is waiting for approval." },
] as const

function Tiles({ tiles }: { tiles: ActionCentre["tiles"] }) {
  const visible = TILE_DEFS.filter((t) => tiles[t.key] !== null)
  if (visible.length === 0) return null
  return (
    <div className={cn("grid gap-3", visible.length >= 4 ? "grid-cols-2 lg:grid-cols-4" : "grid-cols-2 lg:grid-cols-3")}>
      {visible.map((t) => {
        const tile = tiles[t.key] as NonNullable<TodayTile>
        const Icon = t.icon
        return (
          <Link
            key={t.key}
            to={tile.link}
            className="group rounded-lg border border-border bg-card p-4 transition-colors duration-150 hover:border-primary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <div className="flex items-start justify-between gap-2">
              <span className="text-sm font-medium text-muted-foreground">{t.label}</span>
              <Icon className={cn("size-4", tile.count > 0 ? "text-warning" : "text-muted-foreground")} aria-hidden />
            </div>
            <p className="mt-1 text-2xl font-bold tabular-nums">{tile.count}</p>
            <p className="text-xs text-muted-foreground">{tile.count === 0 ? t.none : t.sub}</p>
          </Link>
        )
      })}
    </div>
  )
}

function ShiftList({ shifts }: { shifts: RotaShift[] }) {
  if (shifts.length === 0) {
    return (
      <Card>
        <CardContent className="py-6 text-sm text-muted-foreground">No shifts today.</CardContent>
      </Card>
    )
  }
  return (
    <Card>
      {/* A table on desktop, stacked rows on a phone (plan file 04). */}
      <table className="hidden w-full text-sm md:table">
        <thead>
          <tr className="border-b border-border text-left text-xs text-muted-foreground">
            <th className="px-4 py-2 font-medium">Time</th>
            <th className="px-4 py-2 font-medium">Site</th>
            <th className="px-4 py-2 font-medium">Worker</th>
            <th className="px-4 py-2 font-medium">Service</th>
            <th className="px-4 py-2 font-medium">Status</th>
          </tr>
        </thead>
        <tbody>
          {shifts.map((s) => (
            <tr key={s.id} className="border-b border-border last:border-0">
              <td className="px-4 py-2 tabular-nums">{s.starts_at.slice(11, 16)} to {s.ends_at.slice(11, 16)}</td>
              <td className="px-4 py-2">
                <Link to="/app/scheduling" className="hover:underline">{s.site_name ?? s.title}</Link>
              </td>
              <td className="px-4 py-2">{s.worker ?? <span className="text-muted-foreground">Nobody yet</span>}</td>
              <td className="px-4 py-2"><ServiceDot service={s.division} /></td>
              <td className="px-4 py-2"><StateChip state={s.state} />{s.conflicts.length > 0 && <span className="ml-1 text-xs text-destructive">{s.conflicts.length} problem{s.conflicts.length === 1 ? "" : "s"}</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <ul className="divide-y divide-border md:hidden">
        {shifts.map((s) => (
          <li key={s.id}>
            <Link to="/app/scheduling" className="flex items-start justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <p className="text-sm font-medium tabular-nums">{s.starts_at.slice(11, 16)} to {s.ends_at.slice(11, 16)}</p>
                <p className="truncate text-sm">{s.site_name ?? s.title}</p>
                <p className="truncate text-xs text-muted-foreground">{s.worker ?? "Nobody yet"}</p>
              </div>
              <div className="flex shrink-0 flex-col items-end gap-1">
                <StateChip state={s.state} />
                <ServiceDot service={s.division} />
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  )
}

const URGENCY_LABEL: Record<ActionItem["urgency"], string> = { urgent: "Urgent", today: "Today", soon: "Soon" }
const URGENCY_TONE: Record<ActionItem["urgency"], string> = {
  urgent: "border-destructive/40 bg-destructive/10 text-foreground",
  today: "border-warning/40 bg-warning-soft text-foreground",
  soon: "border-border bg-muted text-muted-foreground",
}

function ActionRow({ item }: { item: ActionItem }) {
  return (
    <li className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className={cn("rounded-full border px-1.5 py-0.5 text-[11px] font-medium leading-none", URGENCY_TONE[item.urgency])}>
            {URGENCY_LABEL[item.urgency]}
          </span>
          <p className="font-medium">{item.title}</p>
        </div>
        <p className="text-sm text-muted-foreground">{item.detail}</p>
        {item.people.length > 0 && (
          <p className="text-sm">
            {item.people.map((p, i) => (
              <span key={p.id}>
                {i > 0 && ", "}
                <Link to={`/app/people/${p.id}`} className="text-primary hover:underline">{p.name}</Link>
              </span>
            ))}
            {item.count > item.people.length && item.kind !== "verification" && <span className="text-muted-foreground"> and more</span>}
          </p>
        )}
      </div>
      {item.link && (
        <Button asChild size="sm" variant="outline" className="shrink-0 self-start">
          <Link to={item.link}>{item.action} <ArrowRight className="ml-1 size-3.5" aria-hidden /></Link>
        </Button>
      )}
    </li>
  )
}
