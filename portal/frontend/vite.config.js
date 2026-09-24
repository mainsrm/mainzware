import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      // Local dev only: forward API calls to the PHP backend served by Apache.
      '/api': 'http://localhost:8080',
      // Keep Live Worship as a separate Vite app while making the portal tile
      // work at the same path used by the production static build.
      '/live-worship': 'http://localhost:5174',
    },
  },
  build: {
    outDir: 'dist',
  },
});
