import { defineComponent, ref } from 'vue'
import type { Ref } from 'vue'
import { mount } from '@vue/test-utils'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SearchResponse } from '../api/types'
import { MAX_SEARCH_LIMIT, searchVariants } from '../api/client'
import { useSearchSuggestions } from './queries'

vi.mock('../api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/client')>()
  return { ...actual, searchVariants: vi.fn() }
})

const searchVariantsMock = vi.mocked(searchVariants)

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function mountSearchSuggestions(query: Ref<string>) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  const host = defineComponent({
    setup() {
      useSearchSuggestions(query)
      return () => null
    },
  })
  return mount(host, {
    global: { plugins: [[VueQueryPlugin, { queryClient }]] },
  })
}

beforeEach(() => {
  searchVariantsMock.mockReset()
  searchVariantsMock.mockResolvedValue({ data: [] } satisfies SearchResponse)
})

describe('useSearchSuggestions', () => {
  it('requests the maximum search limit, not the backend default', async () => {
    const query = ref('audi a4')
    const wrapper = mountSearchSuggestions(query)

    await wait(400)

    expect(searchVariantsMock).toHaveBeenCalledWith(
      { q: 'audi a4', limit: MAX_SEARCH_LIMIT },
      expect.objectContaining({ signal: expect.anything() }),
    )
    wrapper.unmount()
  })
})
