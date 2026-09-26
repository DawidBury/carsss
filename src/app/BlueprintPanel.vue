<script setup lang="ts"></script>

<template>
  <section class="card blueprint">
    <i class="corner tl" />
    <i class="corner tr" />
    <i class="corner bl" />
    <i class="corner br" />
    <slot />
  </section>
</template>

<style scoped>
.card.blueprint {
  background: transparent;
}
</style>
