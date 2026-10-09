import { useState } from "react"
import { ChevronRight } from "lucide-react"
import { Link, useLocation } from "react-router-dom"

import {
  SidebarGroup,
  SidebarMenu,
  SidebarMenuAction,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
} from "@/components/ui/sidebar"
import { cn } from "@/lib/utils"
import type { NavDestination } from "@/lib/nav"

function isUnder(pathname: string, url: string) {
  return pathname === url || pathname.startsWith(`${url}/`)
}

// The office app's destinations. The destination itself is always a link, so
// one click takes you somewhere; its secondary pages sit underneath in a short
// list that opens when you are in that area and can be toggled with the chevron.
//
// Collapsed to the icon rail, the sub-lists and chevrons are hidden by the
// sidebar primitives and each icon still links to its destination, with a
// tooltip naming it.
export function NavMain({ items }: { items: NavDestination[] }) {
  const { pathname } = useLocation()

  return (
    <SidebarGroup>
      <SidebarMenu>
        {items.map((item) => (
          <Destination key={item.title} item={item} pathname={pathname} />
        ))}
      </SidebarMenu>
    </SidebarGroup>
  )
}

function Destination({ item, pathname }: { item: NavDestination; pathname: string }) {
  const children = item.items ?? []
  const childActive = children.some((c) => isUnder(pathname, c.url))
  // "/app" is a prefix of everything, so Today is active only on itself.
  const selfActive = item.url === "/app" ? pathname === "/app" : isUnder(pathname, item.url)
  const active = selfActive || childActive
  // Opens on arrival in the area; the chevron overrides it either way.
  const [toggled, setToggled] = useState<boolean | null>(null)
  const open = children.length > 0 && (toggled ?? active)
  const badge = item.badge ?? children.reduce((n, c) => n + (c.badge ?? 0), 0)

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild tooltip={item.title} isActive={selfActive && !childActive}>
        <Link to={item.url} className={cn(active && "font-semibold text-sidebar-accent-foreground")}>
          <item.icon className="h-4 w-4" />
          <span>{item.title}</span>
        </Link>
      </SidebarMenuButton>

      {children.length > 0 ? (
        <SidebarMenuAction
          onClick={() => setToggled(!open)}
          aria-label={open ? `Hide ${item.title} pages` : `Show ${item.title} pages`}
          aria-expanded={open}
        >
          <ChevronRight className={cn("transition-transform duration-150", open && "rotate-90")} />
        </SidebarMenuAction>
      ) : badge ? (
        <SidebarMenuBadge className="bg-primary text-primary-foreground">{badge > 99 ? "99+" : badge}</SidebarMenuBadge>
      ) : null}

      {open && (
        <SidebarMenuSub>
          {children.map((child) => (
            <SidebarMenuSubItem key={child.url}>
              <SidebarMenuSubButton asChild isActive={isUnder(pathname, child.url)}>
                <Link to={child.url}>
                  <span>{child.title}</span>
                  {child.badge ? (
                    <span className="ml-auto rounded-md bg-primary px-1.5 text-xs font-semibold tabular-nums text-primary-foreground">
                      {child.badge > 99 ? "99+" : child.badge}
                    </span>
                  ) : null}
                </Link>
              </SidebarMenuSubButton>
            </SidebarMenuSubItem>
          ))}
        </SidebarMenuSub>
      )}
    </SidebarMenuItem>
  )
}
