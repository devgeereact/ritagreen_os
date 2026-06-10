"use client"

import { useNavigate } from "react-router-dom"
import {
  Gauge,
  Handshake,
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

// Pass-1 navigation. Built modules are linked; the rest of the 16-module
// platform lands in later passes.
const navMain = [
  { title: "Command Centre", url: "/app", icon: Gauge },
  { title: "Clients", url: "/app/clients", icon: Handshake },
  { title: "Workers", url: "/app/workers", icon: Users },
  { title: "Smoke test", url: "/app/smoke-test", icon: Activity },
]

// Modules scoped for later passes — shown disabled so the shape is visible.
const navSoon = [
  { title: "Scheduling", icon: CalendarRange },
  { title: "Compliance", icon: ShieldCheck },
  { title: "Jobs", icon: ClipboardList },
  { title: "Academy", icon: GraduationCap },
  { title: "Quoting", icon: ReceiptText },
  { title: "Invoicing", icon: CreditCard },
  { title: "Analytics", icon: BarChart3 },
  { title: "Settings", icon: Settings2 },
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
          <SidebarMenu className="px-2">
            <p className="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground group-data-[collapsible=icon]:hidden">
              Coming soon
            </p>
            {navSoon.map((m) => (
              <SidebarMenuItem key={m.title}>
                <SidebarMenuButton tooltip={`${m.title} (coming soon)`} disabled className="opacity-50">
                  <m.icon className="h-4 w-4" />
                  <span>{m.title}</span>
                </SidebarMenuButton>
              </SidebarMenuItem>
            ))}
          </SidebarMenu>
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
