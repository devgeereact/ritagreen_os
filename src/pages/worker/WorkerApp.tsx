import { useState } from "react"
import { useNavigate } from "react-router-dom"
import { useDocumentTitle } from "@/hooks/use-document-title"
import { LogOut, MapPin, LogIn, LogOut as ClockOut, TriangleAlert, ShieldCheck, ShieldX, Plus } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { DIVISION_LABELS, gbp, monthBounds } from "@/lib/format"
import type { PayslipLine } from "@/lib/types"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"
import { LocationNotice } from "@/components/worker/LocationNotice"

type MeData = {
  worker: { id: number; name: string; role: string; division: string; status: string; right_to_work: boolean }
  compliance: { type: string; expiry: string | null; status: string; days: number | null }[]
  shifts: { id: number; title: string; division: string; site_name: string | null; client: string | null; starts_at: string; ends_at: string; clocked_in: number; clocked_out: number }[]
  open_shifts: { id: number; title: string; site_name: string | null; client: string | null; starts_at: string; ends_at: string }[]
}

function getPos(): Promise<{ lat: number | null; lng: number | null }> {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve({ lat: null, lng: null })
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => resolve({ lat: null, lng: null }),
      { timeout: 5000 },
    )
  })
}

function when(dt: string) {
  const d = new Date(dt.replace(" ", "T"))
  return d.toLocaleString("en-GB", { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })
}

export default function WorkerApp() {
  useDocumentTitle("My shifts — RitaGreen Services")
  const { logout } = useAuth()
  const navigate = useNavigate()
  const { data, loading, error, reload } = useApi<MeData>("me.php")
  const [busy, setBusy] = useState<string | null>(null)

  const CLOCK_LABEL = {
    clock_in: "Clocked in.",
    clock_out: "Clocked out.",
    check_in: "Check call recorded.",
  } as const

  async function clock(shiftId: number, kind: keyof typeof CLOCK_LABEL) {
    setBusy(`${kind}-${shiftId}`)
    try {
      const pos = await getPos()
      await api("field.php", { method: "POST", body: { shift_id: shiftId, kind, lat: pos.lat, lng: pos.lng } })
      toast.success(CLOCK_LABEL[kind])
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not record.")
    } finally { setBusy(null) }
  }

  async function accept(shiftId: number) {
    setBusy(`accept-${shiftId}`)
    try {
      await api(`me.php?action=accept&shift_id=${shiftId}`, { method: "POST", body: {} })
      toast.success("Shift accepted.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not accept.")
    } finally { setBusy(null) }
  }

  async function panic(shiftId: number) {
    if (!window.confirm("Send a panic alert to the office now?")) return
    setBusy(`panic-${shiftId}`)
    try {
      const pos = await getPos()
      await api("field.php", { method: "POST", body: { shift_id: shiftId, kind: "panic", lat: pos.lat, lng: pos.lng } })
      toast.success("Alert sent. The office has been notified.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not send alert.")
    } finally { setBusy(null) }
  }

  const expired = data?.compliance.filter((c) => c.status === "expired") ?? []

  return (
    <div className="mx-auto min-h-svh max-w-md bg-background pb-12">
      <header className="sticky top-0 z-10 flex h-14 items-center justify-between border-b bg-background px-4">
        <span className="font-bold"><span className="text-foreground">Rita</span><span className="text-primary">Green</span></span>
        <Button variant="ghost" size="sm" onClick={() => { logout(); navigate("/login", { replace: true }) }}>
          <LogOut className="size-4" />
        </Button>
      </header>

      <main className="space-y-5 p-4">
        {/* Above the shifts, so it is read before the first clock-in rather
            than found afterwards. */}
        <LocationNotice />
        {loading && <LoadingBlock rows={4} />}
        {error && <ErrorBlock message={error} onRetry={reload} />}

        {data && !loading && (
          <>
            <div>
              <h1 className="text-xl font-bold">Hi, {data.worker.name.split(" ")[0]}</h1>
              <p className="text-sm capitalize text-muted-foreground">{data.worker.role} · {DIVISION_LABELS[data.worker.division] ?? data.worker.division}</p>
            </div>

            {/* Compliance banner */}
            <Card className={expired.length ? "border-destructive" : ""}>
              <CardContent className="pt-5">
                <div className="flex items-center gap-2 text-sm font-medium">
                  {expired.length ? <ShieldX className="size-5 text-destructive" /> : <ShieldCheck className="size-5 text-primary" />}
                  {expired.length ? `${expired.length} expired — you can't be scheduled` : "You're clear to work"}
                </div>
                <ul className="mt-2 space-y-1 text-xs text-muted-foreground">
                  {data.compliance.map((c, i) => (
                    <li key={i} className="flex justify-between">
                      <span>{c.type}</span>
                      <span className={c.status === "expired" ? "text-destructive" : c.status === "expiring" ? "text-warning" : ""}>
                        {c.expiry ? c.expiry : "—"}{c.status === "expiring" && c.days !== null ? ` (${c.days}d)` : ""}
                      </span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>

            {/* My shifts */}
            <section>
              <h2 className="mb-2 text-sm font-semibold">My shifts</h2>
              {data.shifts.length === 0 ? (
                <p className="text-sm text-muted-foreground">No upcoming shifts.</p>
              ) : (
                <div className="space-y-3">
                  {data.shifts.map((s) => (
                    <Card key={s.id}>
                      <CardContent className="space-y-3 pt-5">
                        <div>
                          <p className="font-medium">{s.title}</p>
                          <p className="text-xs text-muted-foreground">{when(s.starts_at)} · {s.client || s.site_name || ""}</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                          {!s.clocked_in && (
                            <Button size="sm" onClick={() => clock(s.id, "clock_in")} disabled={busy === `clock_in-${s.id}`}>
                              <LogIn className="mr-1 size-4" /> Clock in
                            </Button>
                          )}
                          {s.clocked_in === 1 && s.clocked_out === 0 && (
                            <>
                              <Button size="sm" variant="outline" onClick={() => clock(s.id, "clock_out")} disabled={busy === `clock_out-${s.id}`}>
                                <ClockOut className="mr-1 size-4" /> Clock out
                              </Button>
                              {/* A check call is a lone worker saying they are
                                  still fine mid-shift. field.php has accepted
                                  check_in all along and the ⌘K palette advertises
                                  "check calls", but nothing anywhere could send
                                  one. Only offered while on site, which is the
                                  only time it means anything. */}
                              <Button size="sm" variant="outline" onClick={() => clock(s.id, "check_in")} disabled={busy === `check_in-${s.id}`}>
                                <MapPin className="mr-1 size-4" /> Check in
                              </Button>
                              <Badge variant="default" className="gap-1"><MapPin className="size-3" /> On site</Badge>
                            </>
                          )}
                          {s.clocked_out === 1 && <Badge variant="secondary">Completed</Badge>}
                          <Button size="sm" variant="ghost" className="text-destructive" onClick={() => panic(s.id)} disabled={busy === `panic-${s.id}`}>
                            <TriangleAlert className="mr-1 size-4" /> Panic
                          </Button>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}
            </section>

            {/* Open shifts */}
            {data.open_shifts.length > 0 && (
              <section>
                <h2 className="mb-2 text-sm font-semibold">Open shifts you can pick up</h2>
                <div className="space-y-3">
                  {data.open_shifts.map((s) => (
                    <Card key={s.id}>
                      <CardContent className="flex items-center justify-between gap-2 pt-5">
                        <div className="min-w-0">
                          <p className="truncate font-medium">{s.title}</p>
                          <p className="text-xs text-muted-foreground">{when(s.starts_at)} · {s.client || s.site_name || ""}</p>
                        </div>
                        <Button size="sm" onClick={() => accept(s.id)} disabled={busy === `accept-${s.id}`}>
                          <Plus className="mr-1 size-4" /> Accept
                        </Button>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              </section>
            )}

            <MyHours />
          </>
        )}
      </main>
    </div>
  )
}

/**
 * The worker's own hours and pay for the current month.
 *
 * me.php?action=payslip has existed and returned this all along, scoped to the
 * caller's own worker_id, and nothing anywhere called it — the same dead surface
 * check_in was. Somebody being paid for these hours is the one person who should
 * be able to see them without asking the office.
 *
 * Gross only, and it says so: there is no PAYE, National Insurance or pension
 * anywhere in the system, so presenting this as take-home would be wrong.
 */
function MyHours() {
  const { from, to } = monthBounds()
  const { data, loading, error } = useApi<{ lines: PayslipLine[]; totals: { hours: number; gross_pence: number } }>(
    `me.php?action=payslip&from=${from}&to=${to}`,
    [from, to],
  )

  return (
    <section>
      <h2 className="mb-2 text-sm font-semibold">My hours this month</h2>
      {loading && <LoadingBlock rows={2} />}
      {error && <p className="text-sm text-muted-foreground">Your hours could not be loaded.</p>}
      {data && (
        <Card>
          <CardContent className="pt-5">
            {data.lines.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No approved hours yet this month. Hours appear here once the office has approved them.
              </p>
            ) : (
              <>
                <ul className="space-y-1.5 text-sm">
                  {data.lines.map((l, i) => (
                    <li key={i} className="flex items-baseline justify-between gap-3">
                      <span className="text-muted-foreground">
                        {l.work_date} · {l.start_time}–{l.end_time}
                        {l.break_minutes > 0 ? ` (less ${l.break_minutes}m)` : ""}
                      </span>
                      <span className="whitespace-nowrap font-medium">{l.hours.toFixed(2)}h</span>
                    </li>
                  ))}
                </ul>
                <div className="mt-3 flex items-baseline justify-between border-t pt-3 text-sm">
                  <span className="font-medium">{data.totals.hours.toFixed(2)} hours</span>
                  <span className="font-bold">{gbp(data.totals.gross_pence)}</span>
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                  Approved hours only, before tax and any other deductions. Ask the office if
                  something looks wrong.
                </p>
              </>
            )}
          </CardContent>
        </Card>
      )}
    </section>
  )
}
