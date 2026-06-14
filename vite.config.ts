import { defineConfig } from "vite"
import react from "@vitejs/plugin-react"
import path from "path"

export default defineConfig({
  base: "/",
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  server: {
    // Dev-only: forward PHP API calls to the live server so the browser sees
    // them as same-origin. Sidesteps the missing Access-Control-Allow-Origin
    // header on the server's CORS config. Prod serves PHP from the same domain.
    proxy: {
      "^/.*\\.php": {
        target: "https://ritagreenservices.com",
        changeOrigin: true,
        secure: true,
      },
    },
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
          editor: ["@tiptap/react", "@tiptap/starter-kit"],
          pdf: ["jspdf", "jspdf-autotable"],
        },
      },
    },
  },
})
