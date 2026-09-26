<script setup lang="ts">
const EXAMPLE_QUERIES = [
  'audi a4 tdi',
  'bmw',
  'alfa romeo 156',
  'audi tt quattro',
] as const

const emit = defineEmits<{
  pick: [query: string]
}>()
</script>

<template>
  <section class="empty-state">
    <div class="empty-state-kicker">
      Przykładowe zapytania
    </div>
    <div class="empty-state-examples">
      <button
        v-for="example in EXAMPLE_QUERIES"
        :key="example"
        type="button"
        class="btn btn-secondary"
        @click="emit('pick', example)"
      >
        {{ example }}
      </button>
    </div>
    <p class="empty-state-hint">
      Wybierz przykład albo zacznij pisać markę, model lub wersję — podpowiedzi
      pojawią się pod polem wyszukiwania.
    </p>
  </section>
</template>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.empty-state-kicker {
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.empty-state-examples {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.empty-state-hint {
  font-size: 13px;
  color: color-mix(in srgb, var(--color-text) 55%, transparent);
  max-width: 60ch;
  text-wrap: pretty;
  margin: 0;
}
</style>
