<template>
  <SidebarItem
    :class="{ current, droppable }"
    :href="href"
    class="playlist select-none"
    draggable="true"
    @contextmenu="onContextMenu"
    @dragleave="onDragLeave"
    @dragover="onDragOver"
    @dragstart="onDragStart"
    @drop="onDrop"
  >
    <template #icon>
      <Icon v-if="isRecentlyPlayedList(list)" :icon="faClockRotateLeft" class="text-k-success" fixed-width />
      <Icon v-else-if="isFavoriteList(list)" :icon="faHeart" class="text-k-love" fixed-width />
      <Icon v-else-if="list.is_smart" :icon="faWandMagicSparkles" fixed-width />
      <Icon v-else-if="list.is_collaborative" :icon="faUsers" fixed-width />
      <ListMusicIcon v-else :size="16" />
    </template>
    {{ list.name }}
  </SidebarItem>
</template>

<script lang="ts" setup>
import { faClockRotateLeft, faHeart, faUsers, faWandMagicSparkles } from '@fortawesome/free-solid-svg-icons'
import { ListMusicIcon } from 'lucide-vue-next'
import { computed, ref, toRefs } from 'vue'
import { eventBus } from '@/utils/eventBus'
import { useRouter } from '@/composables/useRouter'
import { favoriteStore } from '@/stores/favoriteStore'
import { useDraggable, useDroppable } from '@/composables/useDragAndDrop'
import { usePlaylistManagement } from '@/composables/usePlaylistManagement'

import SidebarItem from '@/components/layout/main-wrapper/sidebar/SidebarItem.vue'

const props = defineProps<{ list: PlaylistLike }>()
const { onRouteChanged, url } = useRouter()
const { startDragging } = useDraggable('playlist')
const { acceptsDrop, resolveDroppedItems } = useDroppable(['playables', 'album', 'artist', 'browser-media'])

const droppable = ref(false)

const { addToPlaylist } = usePlaylistManagement()

const { list } = toRefs(props)

const isPlaylist = (list: PlaylistLike): list is Playlist => 'id' in list
const isFavoriteList = (list: PlaylistLike): list is FavoriteList => list.name === 'Favorites'
const isRecentlyPlayedList = (list: PlaylistLike): list is RecentlyPlayedList => list.name === 'Recently Played'

const current = ref(false)

const href = computed(() => {
  if (isPlaylist(list.value)) {
    return url('playlists.show', { id: list.value.id })
  }

  if (isFavoriteList(list.value)) {
    return url('favorites')
  }

  if (isRecentlyPlayedList(list.value)) {
    return url('recently-played')
  }

  throw new Error('Invalid playlist-like type.')
})

const contentEditable = computed(() => {
  if (isRecentlyPlayedList(list.value)) {
    return false
  }
  if (isFavoriteList(list.value)) {
    return true
  }

  return !list.value.is_smart
})

const onContextMenu = (event: MouseEvent) => {
  if (isPlaylist(list.value)) {
    event.preventDefault()
    eventBus.emit('PLAYLIST_CONTEXT_MENU_REQUESTED', event, list.value)
  }
}

const onDragStart = (event: DragEvent) => isPlaylist(list.value) && startDragging(event, list.value)

const onDragOver = (event: DragEvent) => {
  if (!contentEditable.value) {
    return false
  }
  if (!acceptsDrop(event)) {
    return false
  }

  event.preventDefault()
  droppable.value = true

  return false
}

const onDragLeave = () => (droppable.value = false)

const onDrop = async (event: DragEvent) => {
  droppable.value = false

  if (!contentEditable.value) {
    return false
  }
  if (!acceptsDrop(event)) {
    return false
  }

  const playables = await resolveDroppedItems(event)

  if (!playables?.length) {
    return false
  }

  if (isFavoriteList(list.value)) {
    await favoriteStore.like(playables)
  } else if (isPlaylist(list.value)) {
    await addToPlaylist(list.value, playables)
  }

  return false
}

onRouteChanged(route => {
  switch (route.screen) {
    case 'Favorites':
      current.value = isFavoriteList(list.value)
      break

    case 'RecentlyPlayed':
      current.value = isRecentlyPlayedList(list.value)
      break

    case 'Playlist':
      current.value = (list.value as Playlist).id === route.params!.id
      break

    default:
      current.value = false
      break
  }
})
</script>

<style lang="postcss" scoped>
.droppable {
  @apply ring-1 ring-offset-0 ring-k-accent rounded-md cursor-copy;
}
</style>
