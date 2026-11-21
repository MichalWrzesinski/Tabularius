<script setup lang="ts">
import { proto } from '../mock/api'
</script>

<template>
  <div>
    <div class="pagehead"><div><h1>Ustawienia</h1><p>Informacje o środowisku. Ustawienia zmienia operator w konfiguracji.</p></div></div>
    <div class="card params" style="max-width: 640px">
      <h3>Środowisko</h3>
      <div class="param"><small>TRYB</small><p>{{ proto.mode === 'demo' ? 'Demonstracyjny — dane syntetyczne' : 'Organizacja' }}</p></div>
      <div class="param"><small>PROFIL INTEGRACJI</small><p>demo-autoparts v1</p></div>
      <div class="param"><small>STREFA CZASOWA RAPORTÓW</small><p>Europe/Warsaw</p></div>
      <div class="param"><small>WAŻNOŚĆ PLIKÓW</small><p>7 dni</p></div>
    </div>
  </div>
</template>
