import { useState } from "react"
import { useNavigate } from "react-router-dom"
import { CheckCheck, Bell } from "lucide-react"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { useApi } from "@/hooks/use-api"
import { api } from "@/lib/api"
import { cn } from "@/lib/utils"
import { shortDate } from "@/lib/format"
import type { AppNotification } from "@/lib/types"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Feed = { notifications: AppNotification[]; unread: number }

export default function Notifications() {
  const { data, loading, error, reload } = useApi<Feed>("notifications.php")
  const navigate = useNavigate()
  const [items, setItems] = useState<AppNotification[] | null>(null)

  // Local copy so read-state updates feel instant; seeded from the fetch.
  const list = items ?? data?.notifications ?? []
  const unread = list.filter((n) => !n.read).length

  async function markAllRead() {
    setItems(list.map((n) => ({ ...n, read: true })))
    api("notifications.php?action=read_all", { method: "POST", body: {} }).catch(() => reload())
  }

  function open(n: AppNotification) {
    if (!n.read && !n.broadcast) {
      setItems(list.map((x) => (x.id === n.id ? { ...x, read: true } : x)))
      api("notifications.php?action=read", { method: "POST", body: { id: n.id } }).catch(() => {})
    }
    if (n.url) navigate(n.url)
  }

  return (
    <div className="space-y-6">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Notifications</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {unread > 0 ? `${unread} unread` : "You're all caught up."}
          </p>
        </div>
        {unread > 0 && (
          <Button variant="outline" size="sm" className="gap-2" onClick={markAllRead}>
            <CheckCheck className="size-4" /> Mark all read
          </Button>
        )}
      </div>

      {loading && !data ? (
        <LoadingBlock rows={5} />
      ) : error ? (
        <ErrorBlock message="Couldn't load notifications." onRetry={reload} />
      ) : list.length === 0 ? (
        <EmptyBlock title="Nothing here yet" hint="Alerts about shifts, compliance and enquiries will show up here." />
      ) : (
        <Card className="divide-y divide-border p-0">
          {list.map((n) => (
            <button
              key={n.id}
              onClick={() => open(n)}
              className={cn(
                "flex w-full items-start gap-3 px-5 py-4 text-left transition-colors hover:bg-muted/50",
                !n.read && "bg-muted/40",
              )}
            >
              <span
                className={cn(
                  "mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg",
                  n.read ? "bg-muted text-muted-foreground" : "bg-primary/10 text-primary",
                )}
              >
                <Bell className="size-4" />
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <p className="truncate text-sm font-medium">{n.title}</p>
                  {!n.read && <span className="size-2 shrink-0 rounded-full bg-primary" />}
                </div>
                {n.body && <p className="mt-0.5 text-sm text-muted-foreground">{n.body}</p>}
                <p className="mt-1 text-xs text-muted-foreground">{shortDate(n.created_at)}</p>
              </div>
            </button>
          ))}
        </Card>
      )}
    </div>
  )
}
