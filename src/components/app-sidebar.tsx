"use client"

import { useEffect, useState } from "react"
import { useNavigate } from "react-router-dom"
import {
  Gauge,
  Handshake,
  Inbox,
  Mail,
  FolderOpen,
  FileSignature,
  Megaphone,
  Users,
  CalendarRange,
  Clock,
  Clock4,
  Banknote,
  CalendarPlus,
  ShieldCheck,
  ClipboardList,
  GraduationCap,
  ReceiptText,
  CreditCard,
  BarChart3,
  TrendingUp,
  Settings2,
  LogOut,
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
import { api } from "@/lib/api"

// Poll the mailbox for unread count to badge the Inbox nav item. Silent on
// failure (e.g. IMAP not configured) so the sidebar never breaks.
function useInboxUnread(): number {
  const [count, setCount] = useState(0)
  useEffect(() => {
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
  }, [])
  return count
}

// RitaGreenOS module navigation, grouped so the important things are easy to
// find rather than a flat 20-item wall.
const NAV_GROUPS: { label?: string; items: { title: string; url: string; icon: typeof Gauge }[] }[] = [
  {
    items: [{ title: "Command Centre", url: "/app", icon: Gauge }],
  },
  {
    label: "Operations",
    items: [
      { title: "Scheduling", url: "/app/scheduling", icon: CalendarRange },
      { title: "Timetable", url: "/app/timetable", icon: Clock },
      { title: "Jobs", url: "/app/jobs", icon: ClipboardList },
      { title: "Timesheets", url: "/app/timesheets", icon: Clock4 },
      { title: "Payroll", url: "/app/payroll", icon: Banknote },
    ],
  },
  {
    label: "People",
    items: [
      { title: "Workers", url: "/app/workers", icon: Users },
      { title: "Compliance", url: "/app/compliance", icon: ShieldCheck },
      { title: "Academy", url: "/app/academy", icon: GraduationCap },
    ],
  },
  {
    label: "Clients & sales",
    items: [
      { title: "Clients", url: "/app/clients", icon: Handshake },
      { title: "Enquiries", url: "/app/enquiries", icon: Inbox },
      { title: "Bookings", url: "/app/bookings", icon: CalendarPlus },
      { title: "Quoting", url: "/app/quotes", icon: ReceiptText },
      { title: "Contracts", url: "/app/contracts", icon: FileSignature },
      { title: "Invoicing", url: "/app/invoicing", icon: CreditCard },
    ],
  },
  {
    label: "Insight",
    items: [
      { title: "Analytics", url: "/app/analytics", icon: BarChart3 },
      { title: "Insights", url: "/app/insights", icon: TrendingUp },
    ],
  },
  {
    label: "Comms & files",
    items: [
      { title: "Inbox", url: "/app/inbox", icon: Mail },
      { title: "Comms", url: "/app/comms", icon: Megaphone },
      { title: "Files", url: "/app/files", icon: FolderOpen },
    ],
  },
  {
    label: "Admin",
    items: [{ title: "Settings", url: "/app/settings", icon: Settings2 }],
  },
]

type AppSidebarProps = React.ComponentProps<typeof Sidebar> & {
  onHoverChange?: (value: boolean) => void
}

export function AppSidebar({ onHoverChange, ...props }: AppSidebarProps) {
  const unread = useInboxUnread()
  const groups = NAV_GROUPS.map((g) => ({
    ...g,
    items: g.items.map((i) =>
      i.url === "/app/inbox" ? { ...i, badge: unread || undefined } : i,
    ),
  }))
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
          {groups.map((g, i) => (
            <NavMain key={g.label ?? `g${i}`} label={g.label} items={g.items} />
          ))}
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
          <DropdownMenuContent className="w-56 rounded-xl" side="right" align="end" sideOffset={4}>
            <DropdownMenuLabel className="font-normal">
              <p className="text-sm font-medium">{user?.name}</p>
              <p className="text-xs text-muted-foreground">{user?.email}</p>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="gap-2"
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
