import { computed } from 'vue'
import type { Ref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import type { UseQueryReturnType } from '@tanstack/vue-query'
import { getBrands, getModels } from '../api/client'
import type { ApiError } from '../api/errors'
import type { Brand, Model } from '../api/types'
import { fetchModelVariants } from './paging'
import type { ModelVariants } from './paging'

export function useBrands(
  enabled: Readonly<Ref<boolean>>,
): UseQueryReturnType<Brand[], ApiError> {
  return useQuery({
    queryKey: ['brands'] as const,
    queryFn: async ({ signal }) => {
      const response = await getBrands({ signal })
      return response.data
    },
    enabled,
    staleTime: Infinity,
  })
}

export function useModels(
  brandId: Readonly<Ref<string | null>>,
): UseQueryReturnType<Model[], ApiError> {
  return useQuery({
    queryKey: computed(() => ['models', brandId.value] as const),
    queryFn: async ({ signal }) => {
      const currentBrandId = brandId.value
      if (currentBrandId === null) {
        throw new Error('useModels: query ran without a brandId')
      }
      const response = await getModels(currentBrandId, { signal })
      return response.data
    },
    enabled: computed(() => brandId.value !== null),
    staleTime: Infinity,
  })
}

export function useModelVariants(
  modelId: Readonly<Ref<string | null>>,
): UseQueryReturnType<ModelVariants, ApiError> {
  return useQuery({
    queryKey: computed(() => ['model-variants', modelId.value] as const),
    queryFn: async ({ signal }) => {
      const currentModelId = modelId.value
      if (currentModelId === null) {
        throw new Error('useModelVariants: query ran without a modelId')
      }
      return fetchModelVariants(currentModelId, { signal })
    },
    enabled: computed(() => modelId.value !== null),
    staleTime: Infinity,
  })
}
