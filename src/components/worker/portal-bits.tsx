import { type ReactNode } from "react"
import { CheckCircle2, CircleSlash, Clock, WifiOff, XCircle } from "lucide-react"
import { cn } from "@/lib/utils"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"
import { useOnline } from "@/components/worker/portal-utils"

/**
 * Shown instead of personal data while the phone is offline.
 *
 * The portal deliberately keeps no copy of a worker's records on the phone
 * (docs/PRIVACY.md, "What the staff portal keeps on the phone"), so with no
 * signal there is nothing to show, and saying so is better than a blank page or
 * a stale answer about whether somebody may work.
 */
export function OfflineBlock() {
  return (
    <div role="status" className="flex flex-col items-center rounded-lg border border-dashed border-border bg-card px-6 py-12 text-center">
      <span className="grid size-12 place-items-center rounded-lg bg-muted text-muted-foreground">
        <WifiOff className="size-6" aria-hidden />
      </span>
      <p className="mt-4 font-semibold">You are offline</p>
      <p className="mt-1.5 max-w-xs text-sm text-muted-foreground">
        Your shifts and records are not stored on this phone. Connect to the internet to see them.
      </p>
    </div>
  )
}

/** Loading, error and offline in one place, then the content. */
export function PortalState({ loading, error, onRetry, hasData, rows = 3, children }: {
  loading: boolean
  error: string | null
  onRetry: () => void
  hasData: boolean
  rows?: number
  children: ReactNode
}) {
  const online = useOnline()
  if (!online) return <OfflineBlock />
  if (loading && !hasData) return <LoadingBlock rows={rows} />
  if (error) return <ErrorBlock message={error} onRetry={onRetry} />
  if (!hasData) return null
  return <>{children}</>
}

/** The title row of a tab. */
export function PortalHeader({ title, sub, action }: { title: string; sub?: string; action?: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-[22px] font-semibold leading-7">{title}</h1>
        {sub && <p className="mt-0.5 text-sm text-muted-foreground">{sub}</p>}
      </div>
      {action}
    </div>
  )
}

export function PortalSection({ title, children, id }: { title: string; children: ReactNode; id?: string }) {
  return (
    <section aria-labelledby={id} className="space-y-3">
      <h2 id={id} className="text-base font-semibold">{title}</h2>
      {children}
    </section>
  )
}

export function PortalCard({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn("rounded-lg border border-border bg-card p-4", className)}>{children}</div>
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="rounded-lg border border-dashed border-border bg-card px-4 py-6 text-center text-sm text-muted-foreground">{children}</p>
}

const TONE = {
  ok: { cls: "border-success/40 bg-success/10", icon: CheckCircle2, iconCls: "text-success" },
  wait: { cls: "border-warning/40 bg-warning/10", icon: Clock, iconCls: "text-warning" },
  bad: { cls: "border-destructive/40 bg-destructive/10", icon: XCircle, iconCls: "text-destructive" },
  quiet: { cls: "border-border bg-muted", icon: CircleSlash, iconCls: "text-muted-foreground" },
} as const

/** A small status: a symbol and a word, never colour alone. */
export function Chip({ tone, children }: { tone: keyof typeof TONE; children: ReactNode }) {
  const t = TONE[tone]
  const Icon = t.icon
  return (
    <span className={cn("inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium text-foreground", t.cls)}>
      <Icon className={cn("size-3.5", t.iconCls)} aria-hidden />
      {children}
    </span>
  )
}
