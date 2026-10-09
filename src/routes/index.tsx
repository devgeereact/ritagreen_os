import { lazy, Suspense, type ReactElement } from "react"
import { createBrowserRouter, Navigate } from "react-router-dom"

import PublicLayout from "@/layouts/PublicLayout"
import BareLayout from "@/layouts/BareLayout"
import RequireAuth from "@/auth/RequireAuth"
import ErrorPage from "@/pages/ErrorPage"
import { PageLoader } from "@/routes/page-loader"
import {
  LegacyArticleRedirect,
  LegacyQuoteRedirect,
  LegacyRedirect,
  LegacyServiceRedirect,
  LegacyServicesRedirect,
} from "@/routes/legacy-redirects"

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
//
// The 2026 website (section S6): six service pages share one lazy chunk
// (ServicePage, told which page by a prop); /request, /news and the rest are
// one chunk each. The previous site's addresses redirect, through the small
// eager components in legacy-redirects.tsx and real 301s in public/.htaccess.
const ServicePage = lazy(() => import("@/pages/public/ServicePage"))
const About = lazy(() => import("@/pages/public/About"))
const Careers = lazy(() => import("@/pages/public/Careers"))
const News = lazy(() => import("@/pages/public/News"))
const Article = lazy(() => import("@/pages/public/Article"))
const Contact = lazy(() => import("@/pages/public/Contact"))
const Request = lazy(() => import("@/pages/public/Request"))
const Verify = lazy(() => import("@/pages/public/Verify"))
const Legal = lazy(() => import("@/pages/public/Legal"))
const DataRequest = lazy(() => import("@/pages/public/DataRequest"))
const NotFoundPublic = lazy(() => import("@/pages/public/NotFoundPublic"))

const Offer = lazy(() => import("@/pages/public/Offer"))

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
const Requests = lazy(() => import("@/pages/app/Requests"))
const Mailbox = lazy(() => import("@/pages/app/Mailbox"))
const Comms = lazy(() => import("@/pages/app/Comms"))
const Blog = lazy(() => import("@/pages/app/Blog"))
const TestimonialsAdmin = lazy(() => import("@/pages/app/Testimonials"))
const ContentStudio = lazy(() => import("@/pages/app/ContentStudio"))
const Workers = lazy(() => import("@/pages/app/Workers"))
const PersonProfile = lazy(() => import("@/pages/app/PersonProfile"))
const Compliance = lazy(() => import("@/pages/app/Compliance"))
const Scheduling = lazy(() => import("@/pages/app/Scheduling"))
const Jobs = lazy(() => import("@/pages/app/Jobs"))
const Quotes = lazy(() => import("@/pages/app/Quotes"))
const Contracts = lazy(() => import("@/pages/app/Contracts"))
const Timesheets = lazy(() => import("@/pages/app/Timesheets"))
const Payroll = lazy(() => import("@/pages/app/Payroll"))
const Bookings = lazy(() => import("@/pages/app/Bookings"))
const Incidents = lazy(() => import("@/pages/app/Incidents"))
const FieldOps = lazy(() => import("@/pages/app/FieldOps"))
const ATS = lazy(() => import("@/pages/app/ATS"))
const Reports = lazy(() => import("@/pages/app/Reports"))
const ClientPortal = lazy(() => import("@/pages/portal/ClientPortal"))
const WorkerApp = lazy(() => import("@/pages/worker/WorkerApp"))
const PortalHome = lazy(() => import("@/pages/worker/PortalHome"))
const PortalRota = lazy(() => import("@/pages/worker/PortalRota"))
const PortalRecords = lazy(() => import("@/pages/worker/PortalRecords"))
const PortalMe = lazy(() => import("@/pages/worker/PortalMe"))
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
      { path: "healthcare", element: page(<ServicePage key="healthcare" pageKey="healthcare" />) },
      { path: "healthcare/staffing", element: page(<ServicePage key="staffing" pageKey="healthcare/staffing" />) },
      {
        path: "healthcare/supported-living",
        element: page(<ServicePage key="supported-living" pageKey="healthcare/supported-living" />),
      },
      { path: "cleaning", element: page(<ServicePage key="cleaning" pageKey="cleaning" />) },
      { path: "security", element: page(<ServicePage key="security" pageKey="security" />) },
      { path: "training", element: page(<ServicePage key="training" pageKey="training" />) },
      { path: "about", element: page(<About />) },
      { path: "careers", element: page(<Careers />) },
      { path: "news", element: page(<News />) },
      { path: "news/:slug", element: page(<Article />) },
      { path: "contact", element: page(<Contact />) },
      { path: "request", element: page(<Request />) },
      // The previous site's addresses (S6). Old links and bookmarks still land.
      { path: "services", element: <LegacyServicesRedirect /> },
      { path: "services/:slug", element: <LegacyServiceRedirect /> },
      { path: "sectors", element: <LegacyRedirect from="/sectors" /> },
      { path: "accreditations", element: <LegacyRedirect from="/accreditations" /> },
      { path: "resources", element: <LegacyRedirect from="/resources" /> },
      { path: "resources/:slug", element: <LegacyArticleRedirect /> },
      { path: "apply", element: <LegacyRedirect from="/apply" /> },
      { path: "quote", element: <LegacyQuoteRedirect /> },
      { path: "verify", element: page(<Verify />) },
      { path: "verify/:id", element: page(<Verify />) },
      { path: "privacy", element: page(<Legal kind="privacy" />) },
      { path: "terms", element: page(<Legal kind="terms" />) },
      { path: "cookies", element: page(<Legal kind="cookies" />) },
      { path: "data-request", element: page(<DataRequest />) },
      { path: "*", element: page(<NotFoundPublic />) },
    ],
  },

  // 🔐 AUTH — under BareLayout, which carries the legal links and the storage
  // notice these pages previously had no way of showing.
  {
    element: <BareLayout />,
    errorElement: <ErrorPage />,
    children: [
      { path: "/login", element: page(<Login />) },
      { path: "/forgot", element: page(<ForgotPassword />) },
      { path: "/reset/:token", element: page(<ResetPassword />) },
      { path: "/register", element: page(<Register />) },
      { path: "/auth/google/callback", element: page(<GoogleCallback />) },

      // 📲 PUBLIC SHIFT OFFER (worker accepts/declines from an SMS link, no login)
      { path: "/offer/:token", element: page(<Offer />) },
    ],
  },

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
    children: [
      {
        element: page(<WorkerApp />),
        children: [
          { index: true, element: page(<PortalHome />) },
          { path: "rota", element: page(<PortalRota />) },
          { path: "records", element: page(<PortalRecords />) },
          { path: "me", element: page(<PortalMe />) },
        ],
      },
    ],
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
          { path: "requests", element: page(<Requests />) },
          { path: "enquiries", element: page(<Enquiries />) },
          { path: "bookings", element: page(<Bookings />) },
          { path: "incidents", element: page(<Incidents />) },
          { path: "field", element: page(<FieldOps />) },
          { path: "ats", element: page(<ATS />) },
          { path: "reports", element: page(<Reports />) },
          { path: "inbox", element: page(<Mailbox />) },
          { path: "workers", element: page(<Workers />) },
          // One page per person, keyed by people.id. /app/people itself is the
          // staff list, which still lives at /app/workers until the People area
          // is rebuilt around it.
          { path: "people", element: <Navigate to="/app/workers" replace /> },
          { path: "people/:personId", element: page(<PersonProfile />) },
          { path: "scheduling", element: page(<Scheduling />) },
          // /app/timetable was a second, worse copy of Timesheets: the same
          // endpoint, the same totals, the same table and the same dialog, minus
          // the CSV export and minus the role check on the "log time" button.
          // It was named for a calendar it never was -- the week view it implied
          // is Scheduling's timeGridWeek. Kept as a redirect so existing links
          // and bookmarks still land on the page that does the work.
          { path: "timetable", element: <Navigate to="/app/timesheets" replace /> },
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
