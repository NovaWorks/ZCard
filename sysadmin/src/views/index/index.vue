<!-- 布局容器:按菜单类型(menuType)渲染不同菜单布局 -->
<template>
  <div class="app-layout" :class="`layout-${menuType}`">
    <!-- 混合布局(TOP_LEFT):顶栏一级菜单 + 侧边栏子菜单 -->
    <template v-if="menuType === MenuTypeEnum.TOP_LEFT">
      <div class="app-mixed-header">
        <ArtHeaderBar />
        <ArtMixedMenu :list="menuList" />
      </div>
      <div class="app-body">
        <aside id="app-sidebar">
          <ArtSidebarMenu />
        </aside>
        <main id="app-main">
          <div id="app-content">
            <ArtPageContent />
          </div>
        </main>
      </div>
    </template>

    <!-- 顶栏布局(TOP):顶栏一级菜单,无侧边栏 -->
    <template v-else-if="menuType === MenuTypeEnum.TOP">
      <div class="app-mixed-header">
        <ArtHeaderBar />
        <ArtHorizontalMenu :list="menuList" />
      </div>
      <div class="app-body">
        <main id="app-main" class="no-sidebar">
          <div id="app-content">
            <ArtPageContent />
          </div>
        </main>
      </div>
    </template>

    <!-- 侧边栏布局(LEFT / 双列 DUAL_MENU) -->
    <template v-else>
      <aside id="app-sidebar">
        <ArtSidebarMenu />
      </aside>
      <main id="app-main">
        <div id="app-header">
          <ArtHeaderBar />
        </div>
        <div id="app-content">
          <ArtPageContent />
        </div>
      </main>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { storeToRefs } from 'pinia'
  import { MenuTypeEnum } from '@/enums/appEnum'
  import { useSettingStore } from '@/store/modules/setting'
  import { useMenuStore } from '@/store/modules/menu'

  defineOptions({ name: 'AppLayout' })

  const { menuType } = storeToRefs(useSettingStore())
  const menuStore = useMenuStore()
  const menuList = computed(() => menuStore.menuList)
</script>

<style lang="scss" scoped>
  @use './style';
</style>
