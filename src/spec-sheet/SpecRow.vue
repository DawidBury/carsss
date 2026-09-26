<script setup lang="ts">
defineProps<{
  label: string
  value: string
  isUnknown: boolean
}>()
</script>

<template>
  <div class="spec-row">
    <span class="spec-row-label">{{ label }}</span>
    <span
      class="spec-row-value"
      :class="{ 'spec-row-value--unknown': isUnknown }"
    >
      <span :aria-hidden="isUnknown || undefined">{{ value }}</span>
      <span
        v-if="isUnknown"
        class="visually-hidden"
      >brak danych</span>
    </span>
  </div>
</template>

<style scoped>
.spec-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--space-4);
  align-items: baseline;
  padding: 7px 0;
  border-bottom: 1px solid color-mix(in srgb, var(--color-text) 8%, transparent);
}

.spec-row-label {
  font-size: 14px;
  color: color-mix(in srgb, var(--color-text) 62%, transparent);
}

.spec-row-value {
  font-variant-numeric: tabular-nums;
  font-size: 15px;
  font-weight: 500;
  text-align: right;
}

.spec-row-value--unknown {
  color: color-mix(in srgb, var(--color-text) 45%, transparent);
  font-weight: 400;
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
