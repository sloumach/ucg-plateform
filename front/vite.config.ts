import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'
import { loadEnv } from 'vite'
import { validateBuildEnvironment } from './buildEnvironment.ts'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const environmentDirectory = process.env.UCG_BROWSER_ROOT || process.cwd()
  validateBuildEnvironment(loadEnv(mode, environmentDirectory, 'VITE_'))

  return {
    envDir: environmentDirectory,
    plugins: [react(), tailwindcss()],
    server: { watch: { ignored: ['**/reports/**'] } },
    test: {
      environment: 'jsdom',
      include: ['src/**/*.test.{ts,tsx}'],
      setupFiles: ['./src/test/setup.ts'],
    },
  }
})
