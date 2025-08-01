<template>
  <div
    ref="scroller"
    v-koel-overflow-fade
    class="virtual-scroller will-change-transform overflow-scroll"
    @scroll.passive="onScroll"
  >
    <div :style="{ height: `${totalHeight}px` }" class="will-change-transform overflow-hidden">
      <div :style="{ transform: `translateY(${offsetY}px)` }" class="will-change-transform items-wrapper">
        <slot v-for="item in renderedItems" :item="item" />
      </div>
    </div>
  </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, toRefs } from 'vue'

const props = defineProps<{ items: any[], itemHeight: number }>()
const emit = defineEmits<{
  (e: 'scrolled-to-end'): void
  (e: 'scroll', event: Event): void
}>()

const { items, itemHeight } = toRefs(props)

const scroller = ref<HTMLElement>()
const scrollerHeight = ref(0)
const renderAhead = 5
const scrollTop = ref(0)

const totalHeight = computed(() => items.value.length * itemHeight.value)
const startPosition = computed(() => Math.max(0, Math.floor(scrollTop.value / itemHeight.value) - renderAhead))
const offsetY = computed(() => startPosition.value * itemHeight.value)

const renderedItems = computed(() => {
  let count = Math.ceil(scrollerHeight.value / itemHeight.value) + 2 * renderAhead
  count = Math.min(items.value.length - startPosition.value, count)
  return items.value.slice(startPosition.value, startPosition.value + count)
})

const onScroll = (e: Event) => requestAnimationFrame(() => {
  scrollTop.value = (e.target as HTMLElement).scrollTop

  if (!scroller.value) {
    return
  }

  emit('scroll', e)

  if (scroller.value.scrollTop + scroller.value.clientHeight + itemHeight.value >= scroller.value.scrollHeight) {
    emit('scrolled-to-end')
  }
})

const observer = new ResizeObserver(entries => entries.forEach(el => scrollerHeight.value = el.contentRect.height))

onMounted(() => {
  observer.observe(scroller.value!)
  scrollerHeight.value = scroller.value!.offsetHeight
})

onBeforeUnmount(() => observer.unobserve(scroller.value!))
</script>

<style lang="postcss" scoped>
.virtual-scroller {
  @supports (scrollbar-gutter: stable) {
    overflow: auto;
    scrollbar-gutter: stable;

    @media (hover: none) {
      scrollbar-gutter: auto;
    }
  }
}
</style>
