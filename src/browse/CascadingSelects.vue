<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Variant } from '../api/types'
import { groupVariantsByVersion } from './grouping'
import { useBrands, useModelVariants, useModels } from './queries'

const brandId = defineModel<string | null>('brandId', { required: true })
const modelId = defineModel<string | null>('modelId', { required: true })

const emit = defineEmits<{
  select: [variant: Variant]
}>()

const brandsOpened = ref(false)

function handleBrandFocus(): void {
  brandsOpened.value = true
}

const brandsQuery = useBrands(brandsOpened)
const modelsQuery = useModels(brandId)
const modelVariantsQuery = useModelVariants(modelId)

const brands = computed(() => brandsQuery.data.value ?? [])
const models = computed(() => modelsQuery.data.value ?? [])
const versionGroups = computed(() =>
  groupVariantsByVersion(modelVariantsQuery.data.value?.variants ?? []),
)

const brandsFailed = computed(() => brandsQuery.isError.value)
const modelsFailed = computed(() => modelsQuery.isError.value)
const modelVariantsFailed = computed(() => modelVariantsQuery.isError.value)
const isTruncated = computed(
  () => modelVariantsQuery.data.value?.truncated === true,
)

const isModelSelectDisabled = computed(
  () => brandId.value === null || modelsFailed.value,
)
const isVariantSelectDisabled = computed(
  () => modelId.value === null || isTruncated.value || modelVariantsFailed.value,
)

const brandPlaceholder = computed(() =>
  brandsFailed.value ? '— nie udało się pobrać marek —' : '— wybierz markę —',
)

const modelPlaceholder = computed(() => {
  if (modelsFailed.value) return '— nie udało się pobrać modeli —'
  return brandId.value === null ? '— najpierw marka —' : '— wybierz model —'
})

const variantPlaceholder = computed(() => {
  if (modelVariantsFailed.value) return '— nie udało się pobrać wersji —'
  if (modelId.value === null) return '— najpierw model —'
  if (isTruncated.value) return '— za dużo wersji, użyj wyszukiwarki —'
  return '— wybierz wersję —'
})

const selectedVariantOptionValue = ref('')

watch(modelId, () => {
  selectedVariantOptionValue.value = ''
})

function handleBrandChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  brandId.value = value === '' ? null : value
  modelId.value = null
}

function handleModelChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  modelId.value = value === '' ? null : value
}

function handleVariantChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  if (value === '') return
  const variant = modelVariantsQuery.data.value?.variants.find(
    (candidate) => candidate.id === value,
  )
  if (variant === undefined) return
  selectedVariantOptionValue.value = value
  emit('select', variant)
}
</script>

<template>
  <div class="cascading-selects">
    <div class="field">
      <label for="cascading-select-brand">Marka</label>
      <select
        id="cascading-select-brand"
        class="input"
        :value="brandId ?? ''"
        :disabled="brandsFailed"
        @focusin="handleBrandFocus"
        @change="handleBrandChange"
      >
        <option value="">
          {{ brandPlaceholder }}
        </option>
        <option
          v-for="brand in brands"
          :key="brand.id"
          :value="brand.id"
        >
          {{ brand.name }}
        </option>
      </select>
    </div>

    <div class="field">
      <label for="cascading-select-model">Model / generacja</label>
      <select
        id="cascading-select-model"
        class="input"
        :value="modelId ?? ''"
        :disabled="isModelSelectDisabled"
        @change="handleModelChange"
      >
        <option value="">
          {{ modelPlaceholder }}
        </option>
        <option
          v-for="model in models"
          :key="model.id"
          :value="model.id"
        >
          {{ model.name }}
        </option>
      </select>
    </div>

    <div class="field">
      <label for="cascading-select-variant">Wersja</label>
      <select
        id="cascading-select-variant"
        class="input"
        :value="selectedVariantOptionValue"
        :disabled="isVariantSelectDisabled"
        @change="handleVariantChange"
      >
        <option value="">
          {{ variantPlaceholder }}
        </option>
        <optgroup
          v-for="group in versionGroups"
          :key="group.versionId"
          :label="group.versionName"
        >
          <option
            v-for="option in group.options"
            :key="option.variantId"
            :value="option.variantId"
          >
            {{ option.label }}
          </option>
        </optgroup>
      </select>
    </div>
  </div>
</template>

<style scoped>
.cascading-selects {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--space-3);
}

@media (max-width: 900px) {
  .cascading-selects {
    grid-template-columns: minmax(0, 1fr);
  }

  .cascading-selects select {
    font-size: 16px;
  }
}

.cascading-selects select:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@supports (appearance: base-select) {
  .cascading-selects select {
    appearance: base-select;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    text-align: left;
    cursor: pointer;
  }

  .cascading-selects select:disabled {
    cursor: not-allowed;
  }

  .cascading-selects select:open {
    border-color: var(--color-accent);
  }

  .cascading-selects select::picker-icon {
    content: '';
    flex: none;
    width: 7px;
    height: 7px;
    margin-inline-end: 2px;
    border-right: 1.5px solid currentColor;
    border-bottom: 1.5px solid currentColor;
    color: color-mix(in srgb, var(--color-text) 50%, transparent);
    rotate: 45deg;
    translate: 0 -2px;
    transition:
      rotate 120ms ease,
      translate 120ms ease,
      color 120ms ease;
  }

  .cascading-selects select:open::picker-icon {
    color: var(--color-accent);
    rotate: 225deg;
    translate: 0 2px;
  }

  .cascading-selects select::picker(select) {
    appearance: base-select;
    min-width: anchor-size(width);
    max-height: 320px;
    margin-block-start: 4px;
    padding: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: color-mix(in srgb, var(--color-text) 28%, transparent) transparent;
    background: var(--color-bg);
    border: 1px solid color-mix(in srgb, var(--color-text) 45%, transparent);
    border-radius: 0;
    box-shadow: var(--shadow-md);
    opacity: 0;
    translate: 0 -4px;
    transition:
      opacity 120ms ease,
      translate 120ms ease,
      display 120ms allow-discrete,
      overlay 120ms allow-discrete;
  }

  .cascading-selects select:open::picker(select) {
    opacity: 1;
    translate: 0 0;
  }

  @starting-style {
    .cascading-selects select:open::picker(select) {
      opacity: 0;
      translate: 0 -4px;
    }
  }

  .cascading-selects optgroup {
    margin: 0;
    padding: var(--space-3) var(--space-3) var(--space-1);
    border-top: 1px solid var(--color-divider);
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
    font-size: 11px;
    font-style: normal;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--color-accent-700);
  }

  .cascading-selects option {
    margin: 0;
    padding: var(--space-2) var(--space-3);
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 400;
    letter-spacing: normal;
    text-transform: none;
    color: var(--color-text);
    cursor: pointer;
  }

  .cascading-selects optgroup option {
    margin-inline: calc(-1 * var(--space-3));
    padding-inline-start: var(--space-6);
  }

  .cascading-selects option::checkmark {
    display: none;
  }

  .cascading-selects option[value=''] {
    color: color-mix(in srgb, var(--color-text) 55%, transparent);
  }

  .cascading-selects option:hover,
  .cascading-selects option:focus {
    background: color-mix(in srgb, var(--color-text) 7%, transparent);
    outline: none;
  }

  .cascading-selects option:checked {
    background: color-mix(in srgb, var(--color-accent) 12%, transparent);
    box-shadow: inset 2px 0 0 var(--color-accent);
    color: var(--color-accent-800);
  }

  .cascading-selects option:checked:hover,
  .cascading-selects option:checked:focus {
    background: color-mix(in srgb, var(--color-accent) 18%, transparent);
  }

  @media (prefers-reduced-motion: reduce) {
    .cascading-selects select::picker-icon,
    .cascading-selects select::picker(select) {
      transition: none;
    }
  }
}
</style>
