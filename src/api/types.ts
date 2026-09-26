export type Gearbox = 'manual' | 'automatic'

export type Drive = 'front' | 'rear' | 'all'

export type FuelType = 'petrol' | 'diesel' | 'hybrid' | 'electric'

export type Brand = {
  id: string
  name: string
}

export type Model = {
  id: string
  name: string
  brand: Brand
}

export type Version = {
  id: string
  name: string
  fuelType: FuelType
  generation: string | null
  bodyStyle: string | null
  unresolvedSegment: string | null
  powerHp: number | null
  engineDisplacementCcm: number | null
  bootCapacityL: number | null
  model: Model
}

export type Variant = {
  id: string
  acceleration0To100S: number
  fuelConsumptionL100Km: number | null
  fuelTankL: number | null
  gearbox: Gearbox | null
  drive: Drive | null
  version: Version
}

export type SearchHit = {
  id: string
  brand: string
  model: string
  version: string
  fuelType: FuelType
  generation: string | null
  bodyStyle: string | null
  unresolvedSegment: string | null
  gearbox: Gearbox | null
  drive: Drive | null
}

export type Page = {
  limit: number
  next: string | null
}

export type Collection<T> = {
  data: T[]
  page: Page
}

export type SearchResponse = {
  data: SearchHit[]
}

export type ProblemParameterError = {
  parameter: string
  reason: string
}

export type ProblemDetails = {
  type: string
  title: string
  status: number
  detail?: string
  instance?: string
  errors?: ProblemParameterError[]
  traceId?: string
}
