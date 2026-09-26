import { QueryClient } from '@tanstack/vue-query'
import { shouldRetryRequest } from '../api/errors'

export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: Infinity,
        refetchOnWindowFocus: false,
        refetchOnReconnect: false,
        retry: shouldRetryRequest,
      },
    },
  })
}
