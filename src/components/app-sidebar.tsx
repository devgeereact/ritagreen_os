"use client"

import { useNavigate } from "react-router-dom"
import {
  Gauge,
  Handshake,
  Inbox,
  FolderOpen,
  Users,
  CalendarRange,
  ShieldCheck,
  ClipboardList,
  GraduationCap,
  ReceiptText,
  CreditCard,
  BarChart3,
  Settings2,
  Activity,
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

// RitaGreenOS module navigation.
const navMain = [
  { title: "Command Centre", url: "/app", icon: Gauge },
  { title: "Clients", url: "/app/clients", icon: Handshake },
  { title: "Enquiries", url: "/app/enquiries", icon: Inbox },
  { title: "Workers", url: "/app/workers", icon: Users },
  { title: "Scheduling", url: "/app/scheduling", icon: CalendarRange },
  { title: "Jobs", url: "/app/jobs", icon: ClipboardList },
  { title: "Compliance", url: "/app/compliance", icon: ShieldCheck },
  { title: "Academy", url: "/app/academy", icon: GraduationCap },
  { title: "Quoting", url: "/app/quotes", icon: ReceiptText },
  { title: "Invoicing", url: "/app/invoicing", icon: CreditCard },
  { title: "Analytics", url: "/app/analytics", icon: BarChart3 },
  { title: "Files", url: "/app/files", icon: FolderOpen },
  { title: "Settings", url: "/app/settings", icon: Settings2 },
  { title: "Smoke test", url: "/app/smoke-test", icon: Activity },
]

type AppSidebarProps = React.ComponentProps<typeof Sidebar> & {
  onHoverChange?: (value: boolean) => void
}

export function AppSidebar({ onHoverChange, ...props }: AppSidebarProps) {
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
          <NavMain items={navMain} />
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
