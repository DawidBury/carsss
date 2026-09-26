<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { useVariant } from './queries'
import { buildShareUrl, buildSpecGroups, buildSpecSheetHeader } from './rows'
import SpecRow from './SpecRow.vue'

const props = defineProps<{
  variantId: string
}>()

const variantIdRef = computed(() => props.variantId)
const variantQuery = useVariant(variantIdRef)

const isGone = computed(
  () =>
    variantQuery.isError.value &&
    variantQuery.error.value?.kind === 'not-found',
)

const header = computed(() => {
  const variant = variantQuery.data.value
  return variant === undefined ? null : buildSpecSheetHeader(variant)
})

const groups = computed(() => {
  const variant = variantQuery.data.value
  return variant === undefined ? [] : buildSpecGroups(variant)
})

const shareUrl = computed(() => buildShareUrl(props.variantId))

const SKELETON_WIDTHS = ['62%', '88%', '45%', '74%', '56%']

const COPY_FEEDBACK_MS = 1800
const justCopied = ref(false)
let copyTimeoutId: ReturnType<typeof setTimeout> | undefined

async function handleCopy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(shareUrl.value)
  } catch {
    return
  }
  justCopied.value = true
  if (copyTimeoutId !== undefined) clearTimeout(copyTimeoutId)
  copyTimeoutId = setTimeout(() => {
    justCopied.value = false
  }, COPY_FEEDBACK_MS)
}

const copyLabel = computed(() => (justCopied.value ? 'Skopiowano' : 'Kopiuj link'))

onUnmounted(() => {
  if (copyTimeoutId !== undefined) clearTimeout(copyTimeoutId)
})
</script>

<template>
  <div
    v-if="variantQuery.isPending.value"
    class="spec-sheet-loading"
  >
    <div class="spec-sheet-loading-status">
      <span class="spec-sheet-loading-dot" />
      pobieranie danych z API
    </div>
    <div class="spec-sheet-loading-lines">
      <div
        v-for="(width, index) in SKELETON_WIDTHS"
        :key="index"
        class="spec-sheet-loading-line"
        :style="{ width }"
      />
    </div>
  </div>

  <div
    v-else-if="isGone"
    class="spec-sheet-gone"
  >
    <div class="spec-sheet-gone-title">
      Ten samochód już nie istnieje
    </div>
    <p class="spec-sheet-gone-message">
      Wariant, do którego prowadzi ten link, nie jest już dostępny w bazie.
    </p>
  </div>

  <div
    v-else-if="header !== null"
    class="spec-sheet-result"
  >
    <div class="spec-sheet-header">
      <div class="spec-sheet-heading">
        <div class="spec-sheet-kicker">
          {{ header.kicker }}
        </div>
        <div class="spec-sheet-title">
          {{ header.title }}
        </div>
        <div
          v-if="header.meta !== ''"
          class="spec-sheet-meta"
        >
          {{ header.meta }}
        </div>
      </div>
      <button
        type="button"
        class="btn btn-secondary"
        @click="handleCopy"
      >
        {{ copyLabel }}
      </button>
    </div>

    <div
      v-for="group in groups"
      :key="group.label"
      class="spec-sheet-group"
    >
      <div class="spec-sheet-group-label">
        {{ group.label }}
      </div>
      <div class="spec-sheet-group-rows">
        <SpecRow
          v-for="row in group.rows"
          :key="row.label"
          :label="row.label"
          :value="row.value"
          :is-unknown="row.isUnknown"
        />
      </div>
    </div>

    <div class="spec-sheet-share">
      <div class="spec-sheet-share-label">
        Link GET
      </div>
      <code class="spec-sheet-share-url">{{ shareUrl }}</code>
    </div>
  </div>
</template>

<style scoped>
.spec-sheet-loading {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-6);
}

.spec-sheet-loading-status {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  font-size: 13px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--color-text) 55%, transparent);
}

.spec-sheet-loading-dot {
  width: 10px;
  height: 10px;
  background: var(--color-accent);
  animation: spec-sheet-pulse 1s ease-in-out infinite;
}

@keyframes spec-sheet-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.25;
  }
}

.spec-sheet-loading-lines {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-top: var(--space-4);
}

.spec-sheet-loading-line {
  height: 13px;
  background: color-mix(in srgb, var(--color-text) 10%, transparent);
}

.spec-sheet-gone {
  display: flex;
  flex-direction: column;
  padding: var(--space-6);
  gap: var(--space-3);
}

.spec-sheet-gone-title {
  font-family: var(--font-heading);
  font-weight: 600;
  font-size: 20px;
}

.spec-sheet-gone-message {
  margin: 0;
  font-size: 14px;
  color: color-mix(in srgb, var(--color-text) 65%, transparent);
}

.spec-sheet-result {
  display: flex;
  flex-direction: column;
  padding: 0;
  gap: 0;
}

.spec-sheet-header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-4);
  padding: var(--space-6);
  border-bottom: 1px solid var(--color-divider);
}

.spec-sheet-heading {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.spec-sheet-kicker {
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--color-accent-700);
}

.spec-sheet-title {
  font-family: var(--font-heading);
  font-weight: 600;
  font-size: 34px;
  line-height: 1.05;
  letter-spacing: -0.02em;
}

.spec-sheet-meta {
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  color: color-mix(in srgb, var(--color-text) 55%, transparent);
}

.spec-sheet-group {
  padding: var(--space-6);
  border-bottom: 1px solid var(--color-divider);
  display: grid;
  grid-template-columns: 170px minmax(0, 1fr);
  gap: var(--space-6);
}

.spec-sheet-group-label {
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--color-accent-700);
  padding-top: 4px;
}

.spec-sheet-group-rows {
  display: flex;
  flex-direction: column;
}

.spec-sheet-share {
  padding: var(--space-4) var(--space-6);
  display: grid;
  grid-template-columns: 170px minmax(0, 1fr);
  gap: var(--space-6);
  align-items: baseline;
}

.spec-sheet-share-label {
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.spec-sheet-share-url {
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  word-break: break-all;
  color: color-mix(in srgb, var(--color-text) 70%, transparent);
}

@media (max-width: 640px) {
  .spec-sheet-loading,
  .spec-sheet-gone {
    padding: var(--space-4);
  }

  .spec-sheet-header {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-3);
    padding: var(--space-4);
  }

  .spec-sheet-header .btn {
    align-self: flex-start;
  }

  .spec-sheet-title {
    font-size: 26px;
  }

  .spec-sheet-group {
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-3);
    padding: var(--space-4);
  }

  .spec-sheet-group-label {
    padding-top: 0;
  }

  .spec-sheet-share {
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-2);
    padding: var(--space-4);
  }
}
</style>
