import { defineConfig } from "vite"
import react from "@vitejs/plugin-react"
import { VitePWA } from "vite-plugin-pwa"
import path from "path"

// Where the dev server sends *.php. Local by default — see the proxy comment below.
const API_TARGET = process.env.RG_API_TARGET ?? "http://127.0.0.1:8122"
const USING_PRODUCTION = /ritagreenservices\.com/.test(API_TARGET)

if (USING_PRODUCTION) {
  console.warn(
    "\n  WARNING: the dev server is proxying to PRODUCTION (" + API_TARGET + ")." +
      "\n  Reads and writes will hit live workforce data. Unset RG_API_TARGET to use the local API.\n",
  )
}

export default defineConfig({
  base: "/",
  plugins: [
    react(),
    // Replaces the hand-written public/sw.js, which precached four fixed paths
    // and had no idea about hashed asset names. Assets were only cached
    // opportunistically after a first fetch, so offline broke after every
    // deploy -- the one moment it most needed to work.
    VitePWA({
      registerType: "prompt", // never swap the app out under someone mid-task
      includeAssets: ["favicon.svg", "apple-touch-icon.png", "og.png"],
      manifest: {
        name: "RitaGreen Worker",
        short_name: "RitaGreen",
        description: "Shifts, clock-in, compliance and messages for RitaGreen workers.",
        start_url: "/m",
        scope: "/",
        display: "standalone",
        background_color: "#ffffff",
        theme_color: "#0C447C",
        icons: [
          { src: "/icon-192.png", sizes: "192x192", type: "image/png" },
          { src: "/icon-512.png", sizes: "512x512", type: "image/png" },
          // Maskable is a separate entry on purpose: Android crops it to the
          // launcher's shape, so it is drawn inset and bleeds to the edges.
          { src: "/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
        ],
      },
      workbox: {
        // The build hash goes in the precache manifest, so a deploy invalidates
        // exactly what changed and offline keeps working.
        globPatterns: ["**/*.{js,css,html,svg,png,woff2}"],
        // The installable app is the WORKER app, starting at /m. Precaching the
        // office bundle would make a field worker download charts, a calendar
        // and a PDF engine they will never open -- about 1.3 MB of the total.
        // These stay available online and are simply not part of the install.
        globIgnores: [
          "**/charts-*.js",
          "**/calendar-*.js",
          "**/pdf-*.js",
          "**/html2canvas*.js",
          "**/index.es-*.js",
          "**/purify.es-*.js",
          "**/og.png",
        ],
        maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
        navigateFallback: "/index.html",
        // The PHP API is never cached. Compliance data going stale in a cache
        // would mean showing somebody an eligibility answer that is no longer
        // true, which is worse than showing them nothing.
        navigateFallbackDenylist: [/\.php/],
        runtimeCaching: [
          {
            // Read-only worker data, network-first: fresh when online, and the
            // last known answer when not. Writes are never served from here.
            urlPattern: /\/(me|notifications)\.php/,
            handler: "NetworkFirst",
            method: "GET",
            options: {
              cacheName: "worker-reads",
              networkTimeoutSeconds: 5,
              expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 12 },
            },
          },
          {
            urlPattern: /^https:\/\/fonts\.(googleapis|gstatic)\.com\//,
            handler: "CacheFirst",
            options: {
              cacheName: "google-fonts",
              expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 365 },
            },
          },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  // Project block 12 in the workspace port scheme (see ~/.claude/CLAUDE.md, "Dev-server ports").
  // strictPort so a clash fails loudly instead of drifting to a random port.
  // Previously unpinned, so this fell back to Vite's shared 5173.
  server: {
    port: 5122,
    strictPort: true,
    // Forward PHP API calls so the browser sees them as same-origin.
    //
    // The target defaults to the LOCAL PHP server (npm run dev:api). It used to
    // default to https://ritagreenservices.com, which meant every developer read
    // and wrote the live workforce database — including while testing a
    // migration. Pointing at production is now a deliberate act:
    //
    //     RG_API_TARGET=https://ritagreenservices.com npm run dev
    //
    // See docs/RULES.md rule 13.
    proxy: {
      "^/.*\\.php": {
        target: API_TARGET,
        changeOrigin: true,
        secure: true,
        configure: (proxy) => {
          proxy.on("error", (err) => {
            if (!USING_PRODUCTION) {
              console.error(
                `\n  API request failed against ${API_TARGET}: ${err.message}` +
                  "\n  Is the local PHP server running?  npm run dev:api" +
                  "\n  Setup: docs/ARCHITECTURE.md, section 10.\n",
              )
            }
          })
        },
      },
    },
  },
  preview: {
    port: 5122,
    strictPort: true,
  },
  build: {
    rollupOptions: {
      output: {
        // Split heavy, rarely-changing vendor libs out of the main bundle so
        // the public site doesn't pay for CMS-only charts/calendar/editor code.
        manualChunks: {
          react: ["react", "react-dom", "react-router-dom"],
          charts: ["apexcharts", "react-apexcharts"],
          calendar: [
            "@fullcalendar/daygrid",
            "@fullcalendar/timegrid",
            "@fullcalendar/interaction",
            "@fullcalendar/react",
          ],
          pdf: ["jspdf", "jspdf-autotable"],
        },
      },
    },
  },
})
