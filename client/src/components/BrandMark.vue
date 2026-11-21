<script setup lang="ts">
import logo from '../assets/logo.svg'

defineProps<{ size?: number }>()
</script>

<template>
  <span class="brand">
    <img :src="logo" alt="" :width="size ?? 45" :height="size ?? 45" />
    <span>Tabularius</span>
  </span>
</template>

<style scoped>
.brand {
  display: inline-flex;
  align-items: center;
  gap: 13px;
  font-size: 25px;
  font-weight: 700;
  letter-spacing: -0.8px;
}
.brand img {
  filter: drop-shadow(0 2px 3px #12082212);
}
</style>
