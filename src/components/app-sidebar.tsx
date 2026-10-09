import { useEffect, useState } from "react"
import { useNavigate } from "react-router-dom"
import {
  Settings2,
  LogOut,
  LifeBuoy,
  User,
  ChevronsUpDown,
} from "lucide-react"

import { NavMain } from "@/components/nav-main"
import { Logo } from "@/components/brand/Logo"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
} from "@/components/ui/sidebar"
import { useAuth } from "@/providers/auth-provider"
import { canAccess } from "@/lib/access"
import { api } from "@/lib/api"
import { useFeatures } from "@/hooks/use-features"
import { visibleDestinations } from "@/lib/nav"

// Poll the mailbox for unread count to badge the Inbox nav item. Silent on
// failure (e.g. IMAP not configured) so the sidebar never breaks.
//
// Only for accounts that may open the Inbox, and only while the owner has the
// inbox switched on. inbox.php is office-only and refuses when switched off, so
// polling otherwise would put a 403 in the console every 60 seconds for a badge
// nobody sees.
function useInboxUnread(mayOpenInbox: boolean): number {
  const [count, setCount] = useState(0)
  useEffect(() => {
    if (!mayOpenInbox) return
    let alive = true
    const load = () =>
      api<{ unread: number }>("inbox.php?action=unread")
        .then((r) => alive && setCount(r.unread))
        .catch(() => {})
    load()
    const id = setInterval(load, 60_000)
    return () => {
      alive = false
      clearInterval(id)
    }
  }, [mayOpenInbox])
  return count
}

type AppSidebarProps = React.ComponentProps<typeof Sidebar> & {
  onHoverChange?: (value: boolean) => void
}

export function AppSidebar({ onHoverChange, ...props }: AppSidebarProps) {
  const { user } = useAuth()
  const { isOn } = useFeatures()
  const unread = useInboxUnread(canAccess(user, "/app/inbox") && isOn("inbox"))
  const destinations = visibleDestinations(
    (url) => canAccess(user, url),
    isOn,
    { "/app/inbox": unread },
  )
  return (
    <div
      onMouseEnter={() => onHoverChange?.(true)}
      onMouseLeave={() => onHoverChange?.(false)}
    >
      <Sidebar collapsible="icon" {...props}>
        <SidebarHeader className="h-16 justify-center border-b">
          <div className="px-2 group-data-[collapsible=icon]:hidden">
            <Logo />
          </div>
        </SidebarHeader>

        <SidebarContent>
          <NavMain items={destinations} />
        </SidebarContent>

        <SidebarFooter className="h-16 justify-center border-t">
          <SidebarUser />
        </SidebarFooter>

        <SidebarRail />
      </Sidebar>
    </div>
  )
}

function SidebarUser() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const initials = user?.name
    ? user.name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase()
    : "RG"

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton size="lg" className="bg-muted px-2 data-[state=open]:bg-sidebar-accent">
              <Avatar className="h-8 w-8 rounded-lg">
                <AvatarFallback className="rounded-lg bg-primary text-primary-foreground text-xs">
                  {initials}
                </AvatarFallback>
              </Avatar>
              <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user?.name ?? "Member"}</span>
                <span className="truncate text-xs capitalize text-muted-foreground">
                  {user?.role?.replace("_", " ") ?? ""}
                </span>
              </div>
              <ChevronsUpDown className="ml-auto size-4" />
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent className="w-56 rounded-lg shadow-elevate" side="right" align="end" sideOffset={4}>
            <DropdownMenuLabel className="font-normal">
              <p className="text-sm font-medium">{user?.name}</p>
              <p className="text-xs text-muted-foreground">{user?.email}</p>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem className="gap-2" onClick={() => navigate("/app/profile")}>
              <User className="size-4" /> Profile
            </DropdownMenuItem>
            <DropdownMenuItem className="gap-2" onClick={() => navigate("/app/settings")}>
              <Settings2 className="size-4" /> Settings
            </DropdownMenuItem>
            <DropdownMenuItem className="gap-2" onClick={() => navigate("/app/help")}>
              <LifeBuoy className="size-4" /> Help &amp; support
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="gap-2 text-destructive focus:text-destructive"
              onClick={() => {
                logout()
                navigate("/login", { replace: true })
              }}
            >
              <LogOut className="size-4" /> Log out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
