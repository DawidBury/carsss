import { computed, onUnmounted, ref, watch } from 'vue'
import type { Ref } from 'vue'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import type { UseQueryReturnType } from '@tanstack/vue-query'
import { MAX_SEARCH_LIMIT, searchVariants } from '../api/client'
import type { ApiError } from '../api/errors'
import type { SearchHit } from '../api/types'

export const SEARCH_DEBOUNCE_MS = 250

export const MIN_QUERY_LENGTH = 2

const SEARCH_STALE_TIME_MS = 5 * 60 * 1000

function useDebouncedValue(source: Readonly<Ref<string>>): Readonly<Ref<string>> {
  const debounced = ref(source.value)
  let timeoutId: ReturnType<typeof setTimeout> | undefined

  watch(source, (value) => {
    if (timeoutId !== undefined) clearTimeout(timeoutId)
    timeoutId = setTimeout(() => {
      debounced.value = value
    }, SEARCH_DEBOUNCE_MS)
  })

  onUnmounted(() => {
    if (timeoutId !== undefined) clearTimeout(timeoutId)
  })

  return debounced
}

export function useSearchSuggestions(
  query: Readonly<Ref<string>>,
): UseQueryReturnType<SearchHit[], ApiError> {
  const debouncedQuery = useDebouncedValue(query)
  const trimmedQuery = computed(() => debouncedQuery.value.trim())
  const isQueryLongEnough = computed(
    () => trimmedQuery.value.length >= MIN_QUERY_LENGTH,
  )

  return useQuery({
    queryKey: computed(() => ['search', trimmedQuery.value] as const),
    queryFn: async ({ signal }) => {
      const response = await searchVariants(
        { q: trimmedQuery.value, limit: MAX_SEARCH_LIMIT },
        { signal },
      )
      return response.data
    },
    enabled: isQueryLongEnough,
    staleTime: SEARCH_STALE_TIME_MS,
    placeholderData: keepPreviousData,
  })
}
