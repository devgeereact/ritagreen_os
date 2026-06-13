import { lazy, Suspense, type ReactElement } from "react"
import { createBrowserRouter } from "react-router-dom"
import { Loader2 } from "lucide-react"

import PublicLayout from "@/layouts/PublicLayout"
import AppLayout from "@/layouts/AppLayout"
import RequireAuth from "@/auth/RequireAuth"
import ErrorPage from "@/pages/ErrorPage"

// Public corporate site — eager: this is the first paint for most visitors.
import Home from "@/pages/public/Home"
import About from "@/pages/public/About"
import Services from "@/pages/public/Services"
import ServiceDetail from "@/pages/public/ServiceDetail"
import Sectors from "@/pages/public/Sectors"
import Careers from "@/pages/public/Careers"
import Accreditations from "@/pages/public/Accreditations"
import Resources from "@/pages/public/Resources"
import Article from "@/pages/public/Article"
import Contact from "@/pages/public/Contact"
import Quote from "@/pages/public/Quote"
import Verify from "@/pages/public/Verify"
import Legal from "@/pages/public/Legal"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

// Auth
import Login from "@/pages/auth/Login"

// CMS — lazy-loaded so public visitors never download the internal app bundle.
const Dashboard = lazy(() => import("@/pages/app/Dashboard"))
const Clients = lazy(() => import("@/pages/app/Clients"))
const ClientDetail = lazy(() => import("@/pages/app/ClientDetail"))
const Enquiries = lazy(() => import("@/pages/app/Enquiries"))
const Mailbox = lazy(() => import("@/pages/app/Mailbox"))
const Comms = lazy(() => import("@/pages/app/Comms"))
const Workers = lazy(() => import("@/pages/app/Workers"))
const Compliance = lazy(() => import("@/pages/app/Compliance"))
const Scheduling = lazy(() => import("@/pages/app/Scheduling"))
const Jobs = lazy(() => import("@/pages/app/Jobs"))
const Quotes = lazy(() => import("@/pages/app/Quotes"))
const Contracts = lazy(() => import("@/pages/app/Contracts"))
const Timetable = lazy(() => import("@/pages/app/Timetable"))
const Timesheets = lazy(() => import("@/pages/app/Timesheets"))
const Invoicing = lazy(() => import("@/pages/app/Invoicing"))
const Academy = lazy(() => import("@/pages/app/Academy"))
const AcademyDetail = lazy(() => import("@/pages/app/AcademyDetail"))
const Analytics = lazy(() => import("@/pages/app/Analytics"))
const Insights = lazy(() => import("@/pages/app/Insights"))
const Files = lazy(() => import("@/pages/app/Files"))
const Settings = lazy(() => import("@/pages/app/Settings"))
const SmokeTest = lazy(() => import("@/pages/app/SmokeTest"))

// Suspense fallback while a lazy CMS chunk loads.
function PageLoader() {
  return (
    <div className="flex h-full min-h-[60vh] w-full items-center justify-center">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
    </div>
  )
}

// Wrap a lazy CMS element in a Suspense boundary.
const cms = (el: ReactElement): ReactElement => (
  <Suspense fallback={<PageLoader />}>{el}</Suspense>
)

export const router = createBrowserRouter([
  // 🌐 PUBLIC CORPORATE SITE
  {
    element: <PublicLayout />,
    errorElement: <ErrorPage />,
    children: [
      { index: true, element: <Home /> },
      { path: "about", element: <About /> },
      { path: "services", element: <Services /> },
      { path: "services/:slug", element: <ServiceDetail /> },
      { path: "sectors", element: <Sectors /> },
      { path: "careers", element: <Careers /> },
      { path: "accreditations", element: <Accreditations /> },
      { path: "resources", element: <Resources /> },
      { path: "resources/:slug", element: <Article /> },
      { path: "contact", element: <Contact /> },
      { path: "quote", element: <Quote /> },
      { path: "verify", element: <Verify /> },
      { path: "verify/:id", element: <Verify /> },
      { path: "privacy", element: <Legal kind="privacy" /> },
      { path: "terms", element: <Legal kind="terms" /> },
      { path: "cookies", element: <Legal kind="cookies" /> },
      { path: "*", element: <NotFoundPublic /> },
    ],
  },

  // 🔐 AUTH
  { path: "/login", element: <Login />, errorElement: <ErrorPage /> },

  // 📊 INTERNAL CMS (behind login)
  {
    path: "/app",
    element: <RequireAuth />,
    errorElement: <ErrorPage />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: cms(<Dashboard />) },
          { path: "clients", element: cms(<Clients />) },
          { path: "clients/:id", element: cms(<ClientDetail />) },
          { path: "enquiries", element: cms(<Enquiries />) },
          { path: "inbox", element: cms(<Mailbox />) },
          { path: "workers", element: cms(<Workers />) },
          { path: "scheduling", element: cms(<Scheduling />) },
          { path: "timetable", element: cms(<Timetable />) },
          { path: "timesheets", element: cms(<Timesheets />) },
          { path: "jobs", element: cms(<Jobs />) },
          { path: "compliance", element: cms(<Compliance />) },
          { path: "quotes", element: cms(<Quotes />) },
          { path: "contracts", element: cms(<Contracts />) },
          { path: "invoicing", element: cms(<Invoicing />) },
          { path: "academy", element: cms(<Academy />) },
          { path: "academy/:id", element: cms(<AcademyDetail />) },
          { path: "analytics", element: cms(<Analytics />) },
          { path: "insights", element: cms(<Insights />) },
          { path: "files", element: cms(<Files />) },
          { path: "comms", element: cms(<Comms />) },
          { path: "settings", element: cms(<Settings />) },
          // Dev-only API smoke test — never registered in production builds.
          ...(import.meta.env.DEV ? [{ path: "smoke-test", element: cms(<SmokeTest />) }] : []),
        ],
      },
    ],
  },
])
