import { useCallback, useEffect, useState } from "react"
import { Bell } from "lucide-react"
import { useNavigate } from "react-router-dom"

import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from "@/components/ui/dropdown-menu"
import { api } from "@/lib/api"
import { cn } from "@/lib/utils"
import { shortDate } from "@/lib/format"
import type { AppNotification } from "@/lib/types"

export function NotificationDropdown() {
  const navigate = useNavigate()
  const [items, setItems] = useState<AppNotification[]>([])
  const [unread, setUnread] = useState(0)

  const load = useCallback(() => {
    api<{ notifications: AppNotification[]; unread: number }>("notifications.php")
      .then((r) => {
        setItems(r.notifications)
        setUnread(r.unread)
      })
      .catch(() => {})
  }, [])

  // Poll just the count cheaply; refresh the list when the menu opens.
  useEffect(() => {
    let alive = true
    const tick = () =>
      api<{ unread: number }>("notifications.php?action=unread")
        .then((r) => alive && setUnread(r.unread))
        .catch(() => {})
    tick()
    const id = setInterval(tick, 60_000)
    return () => {
      alive = false
      clearInterval(id)
    }
  }, [])

  async function markAllRead() {
    try {
      await api("notifications.php?action=read_all", { method: "POST", body: {} })
      setUnread(0)
      setItems((prev) => prev.map((n) => ({ ...n, read: true })))
    } catch {
      /* non-fatal */
    }
  }

  async function openItem(n: AppNotification) {
    if (!n.read && !n.broadcast) {
      api("notifications.php?action=read", { method: "POST", body: { id: n.id } }).catch(() => {})
      setUnread((u) => Math.max(0, u - 1))
      setItems((prev) => prev.map((x) => (x.id === n.id ? { ...x, read: true } : x)))
    }
    if (n.url) navigate(n.url)
  }

  return (
    <DropdownMenu onOpenChange={(o) => o && load()}>
      <DropdownMenuTrigger asChild>
        <div className="relative">
          <Button variant="ghost" size="icon" className="rounded-full [&_svg]:size-5 h-10 w-10 p-0">
            <Bell />
          </Button>
          {unread > 0 && (
            <span className="absolute -top-1 -right-1 h-4 min-w-4 rounded-full bg-destructive px-1 text-[10px] font-medium text-destructive-foreground flex items-center justify-center">
              {unread}
            </span>
          )}
        </div>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-80 p-0 rounded-lg border shadow-elevate">
        <DropdownMenuLabel className="flex items-center justify-between px-4 py-3">
          <span>Notifications</span>
          {unread > 0 && (
            <button onClick={markAllRead} className="text-xs text-muted-foreground hover:text-foreground">
              Mark all read
            </button>
          )}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />

        <div className="max-h-80 overflow-y-auto">
          {items.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">You're all caught up.</p>
          ) : (
            <div className="flex flex-col">
              {items.map((n) => (
                <button
                  key={n.id}
                  onClick={() => openItem(n)}
                  className={cn(
                    "flex flex-col gap-0.5 px-4 py-3 text-left transition-colors",
                    !n.read ? "bg-muted/50 hover:bg-muted" : "hover:bg-muted/50",
                  )}
                >
                  <p className="text-sm font-medium leading-none">{n.title}</p>
                  {n.body && <p className="text-xs text-muted-foreground">{n.body}</p>}
                  <span className="text-xs text-muted-foreground">{shortDate(n.created_at)}</span>
                </button>
              ))}
            </div>
          )}
        </div>

        <DropdownMenuSeparator />
        <button
          onClick={() => navigate("/app/notifications")}
          className="w-full px-4 py-2.5 text-center text-xs font-medium text-primary transition-colors hover:bg-muted/50"
        >
          View all notifications
        </button>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
