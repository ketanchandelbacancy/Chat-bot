import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    // Forward /api calls to the Laravel backend (php artisan serve).
    proxy: {
      '/api': 'http://localhost:8000',
    },
  },
})
