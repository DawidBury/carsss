import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const apiKey = env.CARS_API_KEY

  if (!apiKey) {
    throw new Error(
      'CARS_API_KEY is not set. Copy .env.example to .env.local and fill it in.',
    )
  }

  return {
    plugins: [vue()],
    server: {
      proxy: {
        '/api': {
          target: 'http://localhost:8080',
          changeOrigin: true,
          rewrite: (path: string) => path.replace(/^\/api/, ''),
          configure: (proxy) => {
            proxy.on('proxyReq', (proxyReq) => {
              proxyReq.setHeader('X-API-Key', apiKey)
            })
          },
        },
      },
    },
    test: {
      environment: 'jsdom',
      include: ['src/**/*.test.ts'],
    },
  }
})
