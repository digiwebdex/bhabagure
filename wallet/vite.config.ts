import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// The wallet calls its API on its own origin (/api/v1/wallet): nginx serves both on wallet.bhabaghure.com.bd. In
// development the dev server forwards those calls to the local API, keeping the browser's Host, so the API's
// own-page check sees one origin as in production.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5175,
    strictPort: true,
    proxy: {
      '/api/v1/wallet': { target: process.env.WALLET_API_URL ?? 'http://localhost:8000', changeOrigin: false },
    },
  },
})
