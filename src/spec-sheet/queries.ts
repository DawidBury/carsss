import { computed } from 'vue'
import type { Ref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import type { QueryClient, UseQueryReturnType } from '@tanstack/vue-query'
import { getVariant } from '../api/client'
import type { ApiError } from '../api/errors'
import type { Variant } from '../api/types'

export function useVariant(
  variantId: Readonly<Ref<string | null>>,
): UseQueryReturnType<Variant, ApiError> {
  return useQuery({
    queryKey: computed(() => ['variant', variantId.value] as const),
    queryFn: async ({ signal }) => {
      const currentId = variantId.value
      if (currentId === null) {
        throw new Error('useVariant: query ran without a variantId')
      }
      return getVariant(currentId, { signal })
    },
    enabled: computed(() => variantId.value !== null),
    staleTime: Infinity,
  })
}

export function seedVariant(queryClient: QueryClient, variant: Variant): void {
  queryClient.setQueryData(['variant', variant.id], variant)
}
