import { afterEach, describe, expect, it, vi } from 'vitest'
import brandsFixture from './fixtures/brands.json'
import modelsFixture from './fixtures/models.json'
import searchResultsFixture from './fixtures/search-results.json'
import searchEmptyFixture from './fixtures/search-empty.json'
import variantFixture from './fixtures/variant.json'
import variantsPage1Fixture from './fixtures/variants-page-1.json'
import variantsPage2Fixture from './fixtures/variants-page-2.json'
import error401Fixture from './fixtures/error-401-unauthorized.json'
import error404Fixture from './fixtures/error-404-variant-not-found.json'
import error400UnknownParamFixture from './fixtures/error-400-unknown-parameter.json'
import error400BadUuidFixture from './fixtures/error-400-invalid-uuid.json'
import {
  API_BASE_PATH,
  MAX_PAGE_LIMIT,
  MAX_SEARCH_LIMIT,
  getBrands,
  getModels,
  getVariant,
  getVariantsPage,
  searchVariants,
} from './client'
import { isApiError } from './errors'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

function stubFetch(response: Response): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue(response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('MAX_PAGE_LIMIT and MAX_SEARCH_LIMIT', () => {
  it('match the API ceilings', () => {
    expect(MAX_PAGE_LIMIT).toBe(200)
    expect(MAX_SEARCH_LIMIT).toBe(20)
  })
})

describe('getBrands', () => {
  it('requests the base path with the maximum page limit', async () => {
    const fetchMock = stubFetch(jsonResponse(brandsFixture))

    const result = await getBrands()

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_PATH}/brands?limit=200`,
      { signal: undefined },
    )
    expect(result.data).toHaveLength(brandsFixture.data.length)
    expect(result.data[0]).toEqual(brandsFixture.data[0])
  })

  it('forwards the abort signal', async () => {
    const fetchMock = stubFetch(jsonResponse(brandsFixture))
    const controller = new AbortController()

    await getBrands({ signal: controller.signal })

    expect(fetchMock).toHaveBeenCalledWith(expect.any(String), {
      signal: controller.signal,
    })
  })
})

describe('getModels', () => {
  it('sends brandId and the maximum page limit', async () => {
    const fetchMock = stubFetch(jsonResponse(modelsFixture))

    await getModels('27e8418b-9ee1-422d-8774-39040f92b933')

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_PATH}/models?brandId=27e8418b-9ee1-422d-8774-39040f92b933&limit=200`,
      { signal: undefined },
    )
  })
})

describe('getVariantsPage', () => {
  it('omits cursor when it is not given', async () => {
    const fetchMock = stubFetch(jsonResponse(variantsPage1Fixture))

    await getVariantsPage({ modelId: 'model-1', limit: 200 })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_PATH}/variants?modelId=model-1&limit=200`,
      { signal: undefined },
    )
  })

  it('re-sends an opaque cursor exactly as received', async () => {
    const fetchMock = stubFetch(jsonResponse(variantsPage2Fixture))
    const cursor = variantsPage1Fixture.page.next

    const result = await getVariantsPage({ modelId: 'model-1', limit: 200, cursor })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_PATH}/variants?modelId=model-1&limit=200&cursor=${encodeURIComponent(cursor)}`,
      { signal: undefined },
    )
    expect(result.page.next).toBeNull()
  })

  it('parses a full page of nested variant data', async () => {
    stubFetch(jsonResponse(variantsPage1Fixture))

    const result = await getVariantsPage({ modelId: 'model-1', limit: 200 })

    expect(result.data[0]?.version.model.brand.name).toBe(
      variantsPage1Fixture.data[0]?.version.model.brand.name,
    )
    expect(result.page.next).toBe(variantsPage1Fixture.page.next)
  })
})

describe('getVariant', () => {
  it('requests the variant by id with no query string', async () => {
    const fetchMock = stubFetch(jsonResponse(variantFixture))

    const result = await getVariant(variantFixture.id)

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_PATH}/variants/${variantFixture.id}`,
      { signal: undefined },
    )
    expect(result).toEqual(variantFixture)
  })

  it('maps a 404 to kind not-found and preserves the problem detail', async () => {
    stubFetch(jsonResponse(error404Fixture, 404))

    await expect(getVariant('00000000-0000-0000-0000-000000000000')).rejects.toSatisfy(
      (error: unknown) => {
        if (!isApiError(error)) return false
        return (
          error.kind === 'not-found' &&
          error.status === 404 &&
          error.problem?.detail === error404Fixture.detail
        )
      },
    )
  })

  it('maps a 400 to kind invalid-request', async () => {
    stubFetch(jsonResponse(error400BadUuidFixture, 400))

    await expect(getVariant('not-a-uuid')).rejects.toSatisfy((error: unknown) => {
      if (!isApiError(error)) return false
      return error.kind === 'invalid-request' && error.status === 400
    })
  })

  it('logs the detail and errors of a 400 to the console for a developer to act on', async () => {
    const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    stubFetch(jsonResponse(error400BadUuidFixture, 400))

    await expect(getVariant('not-a-uuid')).rejects.toThrow()

    expect(consoleErrorSpy).toHaveBeenCalledWith(
      expect.any(String),
      error400BadUuidFixture.detail,
      error400BadUuidFixture.errors,
    )
    consoleErrorSpy.mockRestore()
  })

  it('does not log a 404 to the console', async () => {
    const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    stubFetch(jsonResponse(error404Fixture, 404))

    await expect(getVariant('00000000-0000-0000-0000-000000000000')).rejects.toThrow()

    expect(consoleErrorSpy).not.toHaveBeenCalled()
    consoleErrorSpy.mockRestore()
  })

  it('maps a 401 to kind unauthorized', async () => {
    stubFetch(jsonResponse(error401Fixture, 401))

    await expect(getVariant('any-id')).rejects.toSatisfy((error: unknown) => {
      if (!isApiError(error)) return false
      return error.kind === 'unauthorized' && error.status === 401
    })
  })

  it('maps a 5xx to kind server', async () => {
    stubFetch(
      jsonResponse(
        { type: 'x', title: 'Internal server error', status: 500, traceId: 't-1' },
        500,
      ),
    )

    await expect(getVariant('any-id')).rejects.toSatisfy((error: unknown) => {
      if (!isApiError(error)) return false
      return error.kind === 'server' && error.traceId === 't-1'
    })
  })

  it('maps a network failure to kind network', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(getVariant('any-id')).rejects.toSatisfy((error: unknown) => {
      if (!isApiError(error)) return false
      return error.kind === 'network' && error.status === null
    })
  })

  it('maps a non-JSON error body to kind unexpected', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('not json', { status: 500 })),
    )

    await expect(getVariant('any-id')).rejects.toSatisfy((error: unknown) => {
      if (!isApiError(error)) return false
      return error.kind === 'unexpected' && error.status === 500
    })
  })

  it('maps a non-JSON success body to kind unexpected', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('not json', { status: 200 })),
    )

    await expect(getVariant('any-id')).rejects.toSatisfy((error: unknown) => {
      if (!isApiError(error)) return false
      return error.kind === 'unexpected' && error.status === 200
    })
  })
})

describe('searchVariants', () => {
  it('omits limit when it is not given', async () => {
    const fetchMock = stubFetch(jsonResponse(searchResultsFixture))

    await searchVariants({ q: 'audi a4 tdi' })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_PATH}/search?q=audi+a4+tdi`,
      { signal: undefined },
    )
  })

  it('parses real hits including gearbox and drive', async () => {
    stubFetch(jsonResponse(searchResultsFixture))

    const result = await searchVariants({ q: 'audi a4 tdi', limit: MAX_SEARCH_LIMIT })

    expect(result.data.length).toBeGreaterThan(0)
    expect(result.data[0]).toHaveProperty('gearbox')
    expect(result.data[0]).toHaveProperty('drive')
  })

  it('parses an empty result set for a query the catalogue does not cover', async () => {
    stubFetch(jsonResponse(searchEmptyFixture))

    const result = await searchVariants({ q: 'golf' })

    expect(result.data).toEqual([])
  })

  it('maps a 400 from an unexpected parameter to kind invalid-request', async () => {
    stubFetch(jsonResponse(error400UnknownParamFixture, 400))

    await expect(searchVariants({ q: 'audi' })).rejects.toSatisfy(
      (error: unknown) => {
        if (!isApiError(error)) return false
        return (
          error.kind === 'invalid-request' &&
          error.problem?.errors?.[0]?.parameter === 'bogus'
        )
      },
    )
  })
})
