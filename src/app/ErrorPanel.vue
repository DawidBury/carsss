<script setup lang="ts">
import { computed } from 'vue'
import type { ApiError, ApiErrorKind } from '../api/errors'
import BlueprintPanel from './BlueprintPanel.vue'

const props = defineProps<{
  error: ApiError
}>()

const emit = defineEmits<{
  retry: []
}>()

const messagesByKind: Record<ApiErrorKind, string> = {
  network: 'Nie udało się połączyć z serwerem. Sprawdź połączenie internetowe.',
  'invalid-request': 'Żądanie do serwera było nieprawidłowe.',
  unauthorized: 'Serwer odrzucił żądanie — brak autoryzacji.',
  'not-found': 'Nie znaleziono zasobu.',
  server: 'Wystąpił błąd serwera.',
  unexpected: 'Wystąpił nieoczekiwany błąd.',
}

const message = computed(() => messagesByKind[props.error.kind])
const showRetry = computed(() => props.error.kind !== 'unauthorized')
</script>

<template>
  <BlueprintPanel class="error-panel">
    <div class="error-panel-title">
      Błąd połączenia z API
    </div>
    <div class="error-panel-message">
      {{ message }}
    </div>
    <div
      v-if="error.traceId !== null"
      class="error-panel-trace"
    >
      Identyfikator zgłoszenia: {{ error.traceId }}
    </div>
    <div v-if="showRetry">
      <button
        type="button"
        class="btn btn-primary"
        @click="emit('retry')"
      >
        Spróbuj ponownie
      </button>
    </div>
  </BlueprintPanel>
</template>

<style scoped>
.error-panel {
  padding: var(--space-6);
  gap: var(--space-3);
  border-color: var(--color-accent-700);
}

.error-panel-title {
  font-family: var(--font-heading);
  font-weight: 600;
  font-size: 20px;
}

.error-panel-message {
  font-size: 14px;
  color: color-mix(in srgb, var(--color-text) 65%, transparent);
}

.error-panel-trace {
  font-family: var(--font-heading);
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  color: color-mix(in srgb, var(--color-text) 55%, transparent);
}

@media (max-width: 640px) {
  .error-panel {
    padding: var(--space-4);
  }
}
</style>
