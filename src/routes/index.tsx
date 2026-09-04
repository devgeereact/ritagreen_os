import { lazy, Suspense, type ReactElement } from "react"
import { createBrowserRouter } from "react-router-dom"
import { Loader2 } from "lucide-react"

import PublicLayout from "@/layouts/PublicLayout"
import RequireAuth from "@/auth/RequireAuth"
import ErrorPage from "@/pages/ErrorPage"

// The home page stays eager: it is the first paint for most visitors, and
// making it wait on a second round trip would cost exactly the metric this
// split exists to improve.
import Home from "@/pages/public/Home"

// Every other public page is a navigation, not a first paint.
//
// All seventeen used to be imported eagerly, so the entry chunk carried the
// whole corporate site — 697 kB raw, 206 kB gzipped — and a visitor landing on
// the home page downloaded and parsed the legal pages, the certificate
// verifier, the article template and five forms before anything rendered. That
// is LCP and INP spent on pages most visitors never open. Each is now its own
// chunk, fetched when it is actually routed to.
const About = lazy(() => import("@/pages/public/About"))
const Services = lazy(() => import("@/pages/public/Services"))
const ServiceDetail = lazy(() => import("@/pages/public/ServiceDetail"))
const Sectors = lazy(() => import("@/pages/public/Sectors"))
const Careers = lazy(() => import("@/pages/public/Careers"))
const Accreditations = lazy(() => import("@/pages/public/Accreditations"))
const Resources = lazy(() => import("@/pages/public/Resources"))
const Article = lazy(() => import("@/pages/public/Article"))
const Contact = lazy(() => import("@/pages/public/Contact"))
const Quote = lazy(() => import("@/pages/public/Quote"))
const Verify = lazy(() => import("@/pages/public/Verify"))
const Legal = lazy(() => import("@/pages/public/Legal"))
const NotFoundPublic = lazy(() => import("@/pages/public/NotFoundPublic"))

const Offer = lazy(() => import("@/pages/public/Offer"))
const RequestCover = lazy(() => import("@/pages/public/RequestCover"))
const Apply = lazy(() => import("@/pages/public/Apply"))

// Auth
const Login = lazy(() => import("@/pages/auth/Login"))
const ForgotPassword = lazy(() => import("@/pages/auth/ForgotPassword"))
const ResetPassword = lazy(() => import("@/pages/auth/ResetPassword"))
const GoogleCallback = lazy(() => import("@/pages/auth/GoogleCallback"))
const Register = lazy(() => import("@/pages/auth/Register"))

// CMS — lazy-loaded so public visitors never download the internal app bundle.
//
// AppLayout is part of that and was NOT: it was imported eagerly, so the shell
// it pulls in -- the sidebar, the ⌘K command palette (cmdk), the AI assistant,
// the notification dropdown and the 2FA enrolment screen with its QR encoder --
// all landed in the entry chunk. Somebody reading the marketing home page was
// downloading the internal CMS chrome to render it.
const AppLayout = lazy(() => import("@/layouts/AppLayout"))
const Dashboard = lazy(() => import("@/pages/app/Dashboard"))
const Clients = lazy(() => import("@/pages/app/Clients"))
const ClientDetail = lazy(() => import("@/pages/app/ClientDetail"))
const Enquiries = lazy(() => import("@/pages/app/Enquiries"))
const Mailbox = lazy(() => import("@/pages/app/Mailbox"))
const Comms = lazy(() => import("@/pages/app/Comms"))
const Blog = lazy(() => import("@/pages/app/Blog"))
const TestimonialsAdmin = lazy(() => import("@/pages/app/Testimonials"))
const ContentStudio = lazy(() => import("@/pages/app/ContentStudio"))
const Workers = lazy(() => import("@/pages/app/Workers"))
const Compliance = lazy(() => import("@/pages/app/Compliance"))
const Scheduling = lazy(() => import("@/pages/app/Scheduling"))
const Jobs = lazy(() => import("@/pages/app/Jobs"))
const Quotes = lazy(() => import("@/pages/app/Quotes"))
const Contracts = lazy(() => import("@/pages/app/Contracts"))
const Timetable = lazy(() => import("@/pages/app/Timetable"))
const Timesheets = lazy(() => import("@/pages/app/Timesheets"))
const Payroll = lazy(() => import("@/pages/app/Payroll"))
const Bookings = lazy(() => import("@/pages/app/Bookings"))
const Incidents = lazy(() => import("@/pages/app/Incidents"))
const FieldOps = lazy(() => import("@/pages/app/FieldOps"))
const ATS = lazy(() => import("@/pages/app/ATS"))
const Reports = lazy(() => import("@/pages/app/Reports"))
const ClientPortal = lazy(() => import("@/pages/portal/ClientPortal"))
const WorkerApp = lazy(() => import("@/pages/worker/WorkerApp"))
const Invoicing = lazy(() => import("@/pages/app/Invoicing"))
const Academy = lazy(() => import("@/pages/app/Academy"))
const AcademyDetail = lazy(() => import("@/pages/app/AcademyDetail"))
const Analytics = lazy(() => import("@/pages/app/Analytics"))
const Insights = lazy(() => import("@/pages/app/Insights"))
const Files = lazy(() => import("@/pages/app/Files"))
const Settings = lazy(() => import("@/pages/app/Settings"))
const Profile = lazy(() => import("@/pages/app/Profile"))
const Help = lazy(() => import("@/pages/app/Help"))
const Notifications = lazy(() => import("@/pages/app/Notifications"))
const SmokeTest = lazy(() => import("@/pages/app/SmokeTest"))

// Suspense fallback while a lazily-loaded page chunk arrives.
function PageLoader() {
  return (
    <div className="flex h-full min-h-[60vh] w-full items-center justify-center">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
    </div>
  )
}

// Wrap a lazily-loaded page in a Suspense boundary. Named `cms` when only the
// internal app was lazy; the public pages are lazy now too.
const page = (el: ReactElement): ReactElement => (
  <Suspense fallback={<PageLoader />}>{el}</Suspense>
)

export const router = createBrowserRouter([
  // 🌐 PUBLIC CORPORATE SITE
  {
    element: <PublicLayout />,
    errorElement: <ErrorPage />,
    children: [
      { index: true, element: <Home /> },
      { path: "about", element: page(<About />) },
      { path: "services", element: page(<Services />) },
      { path: "services/:slug", element: page(<ServiceDetail />) },
      { path: "sectors", element: page(<Sectors />) },
      { path: "careers", element: page(<Careers />) },
      { path: "accreditations", element: page(<Accreditations />) },
      { path: "resources", element: page(<Resources />) },
      { path: "resources/:slug", element: page(<Article />) },
      { path: "contact", element: page(<Contact />) },
      { path: "quote", element: page(<Quote />) },
      { path: "request", element: page(<RequestCover />) },
      { path: "apply", element: page(<Apply />) },
      { path: "verify", element: page(<Verify />) },
      { path: "verify/:id", element: page(<Verify />) },
      { path: "privacy", element: page(<Legal kind="privacy" />) },
      { path: "terms", element: page(<Legal kind="terms" />) },
      { path: "cookies", element: page(<Legal kind="cookies" />) },
      { path: "*", element: page(<NotFoundPublic />) },
    ],
  },

  // 🔐 AUTH
  { path: "/login", element: page(<Login />), errorElement: <ErrorPage /> },
  { path: "/forgot", element: page(<ForgotPassword />), errorElement: <ErrorPage /> },
  { path: "/reset/:token", element: page(<ResetPassword />), errorElement: <ErrorPage /> },
  { path: "/register", element: page(<Register />), errorElement: <ErrorPage /> },
  { path: "/auth/google/callback", element: page(<GoogleCallback />), errorElement: <ErrorPage /> },

  // 📲 PUBLIC SHIFT OFFER (worker accepts/declines from an SMS link, no login)
  { path: "/offer/:token", element: page(<Offer />), errorElement: <ErrorPage /> },

  // 🏢 CLIENT PORTAL (client-role accounts)
  {
    path: "/portal",
    element: <RequireAuth />,
    errorElement: <ErrorPage />,
    children: [{ index: true, element: page(<ClientPortal />) }],
  },

  // 📱 WORKER MOBILE APP (worker-linked accounts)
  {
    path: "/m",
    element: <RequireAuth />,
    errorElement: <ErrorPage />,
    children: [{ index: true, element: page(<WorkerApp />) }],
  },

  // 📊 INTERNAL CMS (behind login)
  {
    path: "/app",
    element: <RequireAuth />,
    errorElement: <ErrorPage />,
    children: [
      {
        element: page(<AppLayout />),
        children: [
          { index: true, element: page(<Dashboard />) },
          { path: "clients", element: page(<Clients />) },
          { path: "clients/:id", element: page(<ClientDetail />) },
          { path: "enquiries", element: page(<Enquiries />) },
          { path: "bookings", element: page(<Bookings />) },
          { path: "incidents", element: page(<Incidents />) },
          { path: "field", element: page(<FieldOps />) },
          { path: "ats", element: page(<ATS />) },
          { path: "reports", element: page(<Reports />) },
          { path: "inbox", element: page(<Mailbox />) },
          { path: "workers", element: page(<Workers />) },
          { path: "scheduling", element: page(<Scheduling />) },
          { path: "timetable", element: page(<Timetable />) },
          { path: "timesheets", element: page(<Timesheets />) },
          { path: "payroll", element: page(<Payroll />) },
          { path: "jobs", element: page(<Jobs />) },
          { path: "compliance", element: page(<Compliance />) },
          { path: "quotes", element: page(<Quotes />) },
          { path: "contracts", element: page(<Contracts />) },
          { path: "invoicing", element: page(<Invoicing />) },
          { path: "academy", element: page(<Academy />) },
          { path: "academy/:id", element: page(<AcademyDetail />) },
          { path: "analytics", element: page(<Analytics />) },
          { path: "insights", element: page(<Insights />) },
          { path: "files", element: page(<Files />) },
          { path: "comms", element: page(<Comms />) },
          { path: "blog", element: page(<Blog />) },
          { path: "testimonials", element: page(<TestimonialsAdmin />) },
          { path: "content-studio", element: page(<ContentStudio />) },
          { path: "profile", element: page(<Profile />) },
          { path: "notifications", element: page(<Notifications />) },
          { path: "help", element: page(<Help />) },
          { path: "settings", element: page(<Settings />) },
          // Dev-only API smoke test — never registered in production builds.
          ...(import.meta.env.DEV ? [{ path: "smoke-test", element: page(<SmokeTest />) }] : []),
        ],
      },
    ],
  },
])
