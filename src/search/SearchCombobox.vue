<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import type { SearchHit } from '../api/types'
import { formatVariantDiscriminator } from '../format/labels'
import { describeResultCount, useComboboxNavigation } from './navigation'
import { MIN_QUERY_LENGTH, useSearchSuggestions } from './queries'

const query = defineModel<string>('query', { required: true })

const emit = defineEmits<{
  select: [hit: SearchHit]
  clear: []
}>()

const suggestionsQuery = useSearchSuggestions(query)
const hits = computed<readonly SearchHit[]>(
  () => suggestionsQuery.data.value ?? [],
)

function handleSelect(hit: SearchHit): void {
  emit('select', hit)
}

function handleClear(): void {
  query.value = ''
  emit('clear')
}

const navigation = useComboboxNavigation({
  hits,
  onSelect: handleSelect,
  onClear: handleClear,
})

watch(
  () => suggestionsQuery.isError.value,
  (isError) => {
    if (isError) navigation.open()
  },
)

const isQueryLongEnough = computed(
  () => query.value.trim().length >= MIN_QUERY_LENGTH,
)

const isPopupVisible = computed(
  () => navigation.isOpen.value && isQueryLongEnough.value,
)

const statusMessage = computed(() =>
  isQueryLongEnough.value
    ? describeResultCount(hits.value.length, suggestionsQuery.isError.value)
    : '',
)

function suggestionTitle(hit: SearchHit): string {
  const discriminator = formatVariantDiscriminator(hit.gearbox, hit.drive)
  return [`${hit.brand} ${hit.model}`, hit.version, discriminator]
    .filter((part) => part !== '')
    .join(' · ')
}

function suggestionSubtitle(hit: SearchHit): string | null {
  if (hit.generation !== null && hit.bodyStyle !== null) {
    return `${hit.generation} ${hit.bodyStyle}`
  }
  return hit.unresolvedSegment
}

function handleOptionClick(hit: SearchHit): void {
  handleSelect(hit)
  navigation.close()
}

function handleClearClick(): void {
  handleClear()
}

const rootRef = ref<HTMLElement | null>(null)

function handleOutsideInteraction(event: MouseEvent): void {
  if (rootRef.value === null) return
  if (!rootRef.value.contains(event.target as Node)) {
    navigation.close()
  }
}

onMounted(() => {
  document.addEventListener('mousedown', handleOutsideInteraction)
})

onUnmounted(() => {
  document.removeEventListener('mousedown', handleOutsideInteraction)
})
</script>

<template>
  <div
    ref="rootRef"
    class="search-combobox"
  >
    <div class="search-combobox-row">
      <div class="field search-combobox-field">
        <label for="search-combobox-input">Marka / model / kod nadwozia</label>
        <input
          id="search-combobox-input"
          v-model="query"
          class="input search-combobox-input"
          type="text"
          role="combobox"
          :aria-expanded="isPopupVisible"
          :aria-controls="navigation.listboxId"
          aria-autocomplete="list"
          :aria-activedescendant="navigation.activeOptionId.value ?? undefined"
          autocomplete="off"
          spellcheck="false"
          placeholder="np. Audi A4 TDI, BMW, alfa romeo 156"
          @keydown="navigation.handleKeydown"
        >
      </div>
      <button
        type="button"
        class="btn btn-secondary search-combobox-clear"
        @click="handleClearClick"
      >
        Wyczyść
      </button>
    </div>

    <div
      role="status"
      aria-live="polite"
      aria-atomic="true"
      class="visually-hidden"
    >
      {{ statusMessage }}
    </div>

    <div
      v-if="isPopupVisible"
      class="search-combobox-popup"
    >
      <p
        v-if="suggestionsQuery.isError.value"
        class="search-combobox-popup-message"
      >
        Nie udało się pobrać podpowiedzi.
        <button
          type="button"
          class="btn btn-ghost"
          @click="suggestionsQuery.refetch()"
        >
          Spróbuj ponownie
        </button>
      </p>
      <p
        v-else-if="hits.length === 0"
        class="search-combobox-popup-message"
      >
        Brak podpowiedzi.
      </p>
      <ul
        v-else
        :id="navigation.listboxId"
        role="listbox"
        aria-label="Podpowiedzi wyszukiwania"
        class="search-combobox-list"
      >
        <li
          v-for="(hit, index) in hits"
          :id="navigation.optionId(index)"
          :key="hit.id"
          role="option"
          :aria-selected="index === navigation.activeIndex.value"
          class="search-combobox-option"
          :class="{ 'search-combobox-option--active': index === navigation.activeIndex.value }"
          @mousedown.prevent="handleOptionClick(hit)"
        >
          <span class="search-combobox-option-title">{{ suggestionTitle(hit) }}</span>
          <span
            v-if="suggestionSubtitle(hit) !== null"
            class="search-combobox-option-subtitle"
          >
            {{ suggestionSubtitle(hit) }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.search-combobox {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.search-combobox-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--space-3);
  align-items: end;
}

.search-combobox-field {
  min-width: 0;
}

.search-combobox-input {
  min-height: 46px;
  font-size: 16px;
}

.search-combobox-clear {
  min-height: 46px;
}

.search-combobox-popup {
  border-top: 1px solid var(--color-divider);
  padding-top: var(--space-4);
}

.search-combobox-popup-message {
  margin: 0;
  font-size: 14px;
  color: color-mix(in srgb, var(--color-text) 60%, transparent);
}

.search-combobox-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  max-height: 360px;
  overflow-y: auto;
}

.search-combobox-option {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  text-align: left;
  cursor: pointer;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-md);
  padding: var(--space-3);
}

.search-combobox-option--active {
  border-color: var(--color-accent);
  background: color-mix(in srgb, var(--color-accent) 8%, transparent);
}

.search-combobox-option-title {
  font-family: var(--font-heading);
  font-weight: 600;
  font-size: 17px;
}

.search-combobox-option-subtitle {
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  color: color-mix(in srgb, var(--color-text) 55%, transparent);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
