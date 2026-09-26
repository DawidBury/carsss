<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useQueryClient } from '@tanstack/vue-query'
import type { SearchHit, Variant } from '../api/types'
import type { ApiError } from '../api/errors'
import CascadingSelects from '../browse/CascadingSelects.vue'
import SearchCombobox from '../search/SearchCombobox.vue'
import SpecSheet from '../spec-sheet/SpecSheet.vue'
import { seedVariant, useVariant } from '../spec-sheet/queries'
import BlueprintPanel from './BlueprintPanel.vue'
import EmptyState from './EmptyState.vue'
import ErrorPanel from './ErrorPanel.vue'
import { useVariantIdInUrl } from './url'

const queryClient = useQueryClient()

const searchQuery = ref('')
const brandId = ref<string | null>(null)
const modelId = ref<string | null>(null)

const { variantIdFromUrl: selectedVariantId, pushVariantId } = useVariantIdInUrl()

const variantQuery = useVariant(selectedVariantId)

const genericVariantError = computed<ApiError | null>(() => {
  const error = variantQuery.error.value
  if (error === null || error.kind === 'not-found') return null
  return error
})

const isEmpty = computed(
  () => searchQuery.value.trim() === '' && selectedVariantId.value === null,
)

watch(brandId, () => {
  if (selectedVariantId.value !== null) pushVariantId(null)
})

watch(modelId, () => {
  if (selectedVariantId.value !== null) pushVariantId(null)
})

function handleSearchHitSelected(hit: SearchHit): void {
  pushVariantId(hit.id)
}

function handleVariantPicked(variant: Variant): void {
  seedVariant(queryClient, variant)
  pushVariantId(variant.id)
}

function handleSearchCleared(): void {
  pushVariantId(null)
  brandId.value = null
  modelId.value = null
}

function handleExamplePicked(example: string): void {
  searchQuery.value = example
}

function handleRetryVariant(): void {
  void variantQuery.refetch()
}
</script>

<template>
  <div class="search-screen">
    <div class="search-screen-inner">
      <header class="search-screen-header">
        <h1 class="search-screen-title">
          ilejedzie<span class="search-screen-title-accent">.pl</span>
        </h1>
        <div class="search-screen-subtitle">
          baza parametrów technicznych · wyszukiwanie po marce, modelu i wersji
        </div>
      </header>

      <BlueprintPanel class="search-screen-panel">
        <SearchCombobox
          v-model:query="searchQuery"
          @select="handleSearchHitSelected"
          @clear="handleSearchCleared"
        />
        <CascadingSelects
          v-model:brand-id="brandId"
          v-model:model-id="modelId"
          @select="handleVariantPicked"
        />
      </BlueprintPanel>

      <ErrorPanel
        v-if="genericVariantError !== null"
        :error="genericVariantError"
        @retry="handleRetryVariant"
      />
      <BlueprintPanel
        v-else-if="selectedVariantId !== null"
        class="spec-sheet-panel"
      >
        <SpecSheet :variant-id="selectedVariantId" />
      </BlueprintPanel>
      <EmptyState
        v-else-if="isEmpty"
        @pick="handleExamplePicked"
      />
    </div>
  </div>
</template>

<style scoped>
.search-screen {
  min-height: 100dvh;
  padding: var(--space-8) var(--space-6) 96px;
  font-family: var(--font-body);
}

.search-screen-inner {
  max-width: 860px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.search-screen-header {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.search-screen-title {
  margin: 0;
  font-size: 44px;
  letter-spacing: -0.02em;
}

.search-screen-title-accent {
  color: var(--color-accent-700);
}

.search-screen-subtitle {
  font-size: 11px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--color-text) 55%, transparent);
}

.search-screen-panel {
  padding: var(--space-6);
  gap: var(--space-6);
}

.spec-sheet-panel {
  padding: 0;
  gap: 0;
}

@media (max-width: 640px) {
  .search-screen {
    padding: var(--space-6) var(--space-4) var(--space-8);
  }

  .search-screen-panel {
    padding: var(--space-4);
    gap: var(--space-4);
  }
}
</style>
