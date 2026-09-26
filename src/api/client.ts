import type {
  Brand,
  Collection,
  Model,
  ProblemDetails,
  SearchResponse,
  Variant,
} from './types'
import { ApiError, toApiError, type ApiErrorKind } from './errors'

export type RequestOptions = {
  signal?: AbortSignal
}

export type VariantsPageQuery = {
  modelId: string
  limit: number
  cursor?: string
}

export type SearchQuery = {
  q: string
  limit?: number
}

export const API_BASE_PATH = '/api/v1'

export const MAX_PAGE_LIMIT = 200

export const MAX_SEARCH_LIMIT = 20

type QueryParamValue = string | number | undefined

function buildUrl(
  path: string,
  params: Record<string, QueryParamValue>,
): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined) continue
    search.set(key, String(value))
  }
  const query = search.toString()
  return `${API_BASE_PATH}${path}${query ? `?${query}` : ''}`
}

function errorKindForStatus(status: number): ApiErrorKind {
  if (status === 400) return 'invalid-request'
  if (status === 401) return 'unauthorized'
  if (status === 404) return 'not-found'
  if (status >= 500) return 'server'
  return 'unexpected'
}

async function parseProblem(response: Response): Promise<ProblemDetails | null> {
  try {
    return (await response.json()) as ProblemDetails
  } catch {
    return null
  }
}

async function request<T>(
  path: string,
  params: Record<string, QueryParamValue>,
  options: RequestOptions | undefined,
): Promise<T> {
  const url = buildUrl(path, params)

  let response: Response
  try {
    response = await fetch(url, { signal: options?.signal })
  } catch (error) {
    throw toApiError(error)
  }

  if (!response.ok) {
    const problem = await parseProblem(response)
    if (problem === null) {
      throw new ApiError('unexpected', response.status, null)
    }
    const kind = errorKindForStatus(response.status)
    if (kind === 'invalid-request') {
      console.error('Invalid request parameter', problem.detail, problem.errors)
    }
    throw new ApiError(kind, response.status, problem)
  }

  try {
    return (await response.json()) as T
  } catch {
    throw new ApiError('unexpected', response.status, null)
  }
}

export async function getBrands(
  options?: RequestOptions,
): Promise<Collection<Brand>> {
  return request<Collection<Brand>>(
    '/brands',
    { limit: MAX_PAGE_LIMIT },
    options,
  )
}

export async function getModels(
  brandId: string,
  options?: RequestOptions,
): Promise<Collection<Model>> {
  return request<Collection<Model>>(
    '/models',
    { brandId, limit: MAX_PAGE_LIMIT },
    options,
  )
}

export async function getVariantsPage(
  query: VariantsPageQuery,
  options?: RequestOptions,
): Promise<Collection<Variant>> {
  return request<Collection<Variant>>(
    '/variants',
    { modelId: query.modelId, limit: query.limit, cursor: query.cursor },
    options,
  )
}

export async function getVariant(
  variantId: string,
  options?: RequestOptions,
): Promise<Variant> {
  return request<Variant>(
    `/variants/${encodeURIComponent(variantId)}`,
    {},
    options,
  )
}

export async function searchVariants(
  query: SearchQuery,
  options?: RequestOptions,
): Promise<SearchResponse> {
  return request<SearchResponse>(
    '/search',
    { q: query.q, limit: query.limit },
    options,
  )
}
