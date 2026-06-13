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
