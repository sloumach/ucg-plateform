import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'
import { loadEnv } from 'vite'
import { validateBuildEnvironment } from './buildEnvironment.ts'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  validateBuildEnvironment(loadEnv(mode, process.cwd(), 'VITE_'))

  return {
    plugins: [react(), tailwindcss()],
    test: {
      environment: 'jsdom',
      include: ['src/**/*.test.{ts,tsx}'],
      setupFiles: ['./src/test/setup.ts'],
    },
  }
})
