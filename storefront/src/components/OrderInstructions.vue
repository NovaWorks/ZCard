<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DOMPurify from 'dompurify'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps<{ html?: string | null }>()
const { t } = useI18n()

const sanitizedHtml = computed(() => DOMPurify.sanitize(props.html || '', {
  USE_PROFILES: { html: true },
  ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto|tel):|[^a-z]|[a-z+.-]+(?:[^a-z+.-:]|$))/i,
}))
</script>

<template>
  <section v-if="sanitizedHtml" class="order-instructions rounded-field border border-border bg-surface-subtle p-3 text-left">
    <div class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-ink">
      <AppIcon name="ri:book-open-line" class="h-4 w-4 text-primary" />
      <span>{{ t('order.instructionsTitle') }}</span>
    </div>
    <div class="instructions-content text-xs leading-relaxed text-ink-soft" v-html="sanitizedHtml"></div>
  </section>
</template>

<style scoped>
.instructions-content :deep(p) { margin: 0 0 .6rem; }
.instructions-content :deep(p:last-child) { margin-bottom: 0; }
.instructions-content :deep(h1),
.instructions-content :deep(h2),
.instructions-content :deep(h3),
.instructions-content :deep(h4) { margin: .75rem 0 .4rem; color: var(--color-ink); font-weight: 700; line-height: 1.4; }
.instructions-content :deep(ul),
.instructions-content :deep(ol) { margin: .5rem 0; padding-left: 1.25rem; }
.instructions-content :deep(ul) { list-style: disc; }
.instructions-content :deep(ol) { list-style: decimal; }
.instructions-content :deep(a) { color: var(--color-primary); text-decoration: underline; overflow-wrap: anywhere; }
.instructions-content :deep(img) { max-width: 100%; height: auto; border-radius: .5rem; }
.instructions-content :deep(pre),
.instructions-content :deep(table) { max-width: 100%; overflow-x: auto; }
.instructions-content :deep(pre) { margin: .5rem 0; border-radius: .5rem; padding: .75rem; background: white; }
.instructions-content :deep(code) { overflow-wrap: anywhere; }
.instructions-content :deep(blockquote) { margin: .5rem 0; border-left: 3px solid var(--color-primary); padding-left: .75rem; }
</style>
