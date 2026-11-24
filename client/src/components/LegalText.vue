<script setup lang="ts">
// One paragraph or list item of a legal document with its placeholders highlighted, rendered without template
// whitespace between the parts.
import { h } from 'vue'
import { placeholderParts } from '../lib/legal'

const props = defineProps<{ text: string }>()
const Parts = () => placeholderParts(props.text).map((part) => (part.placeholder ? h('mark', part.text) : part.text))
</script>

<template><Parts /></template>
