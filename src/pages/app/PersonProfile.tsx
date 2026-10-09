import { useCallback } from "react"
import { Link, useParams, useSearchParams } from "react-router-dom"
import { ArrowLeft, ArrowRight, CheckCircle2 } from "lucide-react"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { ErrorBlock, LoadingBlock } from "@/components/app/state-block"
import { useApi } from "@/hooks/use-api"
import { initials } from "@/lib/people"
import type { NextAction, PersonProfile as Profile } from "@/lib/types"
import { ServiceTag } from "@/components/people/shared"
import { OverviewTab } from "@/components/people/overview-tab"
import { ChecksTab } from "@/components/people/checks-tab"
import { TrainingTab } from "@/components/people/training-tab"
import { ShiftsTab } from "@/components/people/shifts-tab"
import { EmploymentTab } from "@/components/people/employment-tab"
import { EmergencyTab } from "@/components/people/emergency-tab"
import { ActivityTab } from "@/components/people/activity-tab"

// The tabs, exactly as docs/Plans 2026/02 "The person profile" lists them.
const TABS: { value: NextAction["tab"]; label: string }[] = [
  { value: "overview", label: "Overview" },
  { value: "checks", label: "Checks and documents" },
  { value: "training", label: "Training" },
  { value: "shifts", label: "Shifts" },
  { value: "employment", label: "Employment" },
  { value: "emergency", label: "Emergency contacts" },
  { value: "activity", label: "Activity" },
]

/**
 * One page per person.
 *
 * Read in one call from server/people.php, which applies the role, the
 * person's service scope and a zone per section. Every action on the page goes
 * to the endpoint that owns it, and the page re-reads afterwards, because the
 * statuses shown are calculated by the server and never by this screen.
 */
export default function PersonProfile() {
  const { personId } = useParams()
  const id = Number(personId)
  const [params, setParams] = useSearchParams()
  const tab = (TABS.some((t) => t.value === params.get("tab")) ? params.get("tab") : "overview") as NextAction["tab"]
  const { data, loading, error, reload } = useApi<Profile>(Number.isFinite(id) && id > 0 ? `people.php?id=${id}` : null, [id])

  const setTab = useCallback(
    (value: string) => setParams((p) => { p.set("tab", value); return p }, { replace: true }),
    [setParams],
  )

  if (!Number.isFinite(id) || id <= 0) return <ErrorBlock message="That is not a person." />

  return (
    <div className="space-y-5">
      <Button asChild variant="ghost" size="sm" className="-ml-2">
        <Link to="/app/workers"><ArrowLeft className="mr-1 size-4" aria-hidden /> People</Link>
      </Button>

      {loading && !data && <LoadingBlock rows={6} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && (
        <>
          <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 items-start gap-4">
              {/* No photo is held for anybody yet, so the avatar shows initials. */}
              <Avatar className="size-14 shrink-0">
                <AvatarFallback className="text-base font-semibold">{initials(data.person.full_name)}</AvatarFallback>
              </Avatar>
              <div className="min-w-0">
                <h1 className="truncate text-2xl font-bold tracking-tight">{data.person.full_name}</h1>
                <p className="text-sm text-muted-foreground">
                  {data.payroll_no ? <>Payroll number <span className="tabular-nums">{data.payroll_no}</span></> : "No payroll number yet"}
                </p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {data.services.map((s) => <ServiceTag key={s.service} service={s.service} status={s.status} />)}
                </div>
              </div>
            </div>
            <NextActionPanel action={data.next_action} canManage={data.can.manage} onGo={setTab} />
          </header>

          <Tabs value={tab} onValueChange={setTab}>
            <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
              <TabsList className="w-max justify-start">
                {TABS.map((t) => <TabsTrigger key={t.value} value={t.value}>{t.label}</TabsTrigger>)}
              </TabsList>
            </div>
            <TabsContent value="overview" className="mt-4"><OverviewTab profile={data} onChanged={reload} /></TabsContent>
            <TabsContent value="checks" className="mt-4"><ChecksTab profile={data} onChanged={reload} onOpenEmployment={() => setTab("employment")} /></TabsContent>
            <TabsContent value="training" className="mt-4"><TrainingTab profile={data} onChanged={reload} /></TabsContent>
            <TabsContent value="shifts" className="mt-4"><ShiftsTab profile={data} /></TabsContent>
            <TabsContent value="employment" className="mt-4"><EmploymentTab profile={data} onChanged={reload} /></TabsContent>
            <TabsContent value="emergency" className="mt-4"><EmergencyTab profile={data} onChanged={reload} /></TabsContent>
            <TabsContent value="activity" className="mt-4"><ActivityTab profile={data} /></TabsContent>
          </Tabs>
        </>
      )}
    </div>
  )
}

/** The single most important thing to do next, worked out by the server. */
function NextActionPanel({ action, canManage, onGo }: { action: NextAction | null; canManage: boolean; onGo: (tab: string) => void }) {
  if (!action) {
    return (
      <p className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
        <CheckCircle2 className="size-4 text-success" aria-hidden /> Nothing outstanding
      </p>
    )
  }
  return (
    <div className="rounded-lg border border-border bg-card p-3 sm:max-w-xs">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Next action</p>
      <p className="mt-0.5 font-semibold">{action.label}</p>
      <Button size="sm" className="mt-2 w-full" onClick={() => onGo(action.tab)}>
        {canManage ? "Go to it" : "View"} <ArrowRight className="ml-1 size-4" aria-hidden />
      </Button>
    </div>
  )
}
