"use client"

import { useNavigate } from "react-router-dom"
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuLabel,
} from "@/components/ui/dropdown-menu"
import { Button } from "@/components/ui/button"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { User, Settings, LayoutDashboard, LifeBuoy, LogOut } from "lucide-react"
import { useAuth } from "@/providers/auth-provider"
import { canAccess } from "@/lib/access"

// Canonical account menu (top-right). Real auth data, real navigation, working
// logout. Identity also shows in the sidebar footer; both stay consistent.
export function UserDropdown() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const initials = user?.name
    ? user.name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase()
    : "RG"
  const role = user?.role?.replace(/_/g, " ") ?? ""

  const go = (to: string) => () => navigate(to)

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" className="ml-2 h-10 w-10 overflow-hidden rounded-full p-0" aria-label="Account menu">
          <Avatar className="h-10 w-10 rounded-full">
            <AvatarFallback className="bg-primary text-xs font-semibold text-primary-foreground">{initials}</AvatarFallback>
          </Avatar>
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-60 rounded-lg p-2 shadow-elevate">
        <DropdownMenuLabel className="mb-1 rounded-md border border-border bg-muted/50 p-3 font-normal">
          <p className="truncate text-sm font-medium">{user?.name ?? "Member"}</p>
          <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
          {role && <p className="mt-0.5 text-xs capitalize text-primary">{role}</p>}
        </DropdownMenuLabel>

        <DropdownMenuItem className="h-9 gap-2" onClick={go("/app/profile")}>
          <User className="size-4" /> Profile
        </DropdownMenuItem>
        <DropdownMenuItem className="h-9 gap-2" onClick={go("/app")}>
          <LayoutDashboard className="size-4" /> Command Centre
        </DropdownMenuItem>
        {canAccess(user, "/app/settings") && (
          <DropdownMenuItem className="h-9 gap-2" onClick={go("/app/settings")}>
            <Settings className="size-4" /> Settings
          </DropdownMenuItem>
        )}
        <DropdownMenuItem className="h-9 gap-2" onClick={go("/app/help")}>
          <LifeBuoy className="size-4" /> Help &amp; support
        </DropdownMenuItem>

        <DropdownMenuSeparator className="my-2" />

        <DropdownMenuItem
          className="h-9 gap-2 text-destructive focus:text-destructive"
          onClick={() => {
            logout()
            navigate("/login", { replace: true })
          }}
        >
          <LogOut className="size-4" /> Log out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
