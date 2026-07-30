import type { RouteRecordRaw } from 'vue-router'

const layout = () => import('@/views/index/index.vue')

export default {
  path: '/category',
  name: 'category',
  component: '/index/index',
  redirect: '/category/list',
  meta: { title: '分类管理', icon: 'ri:price-tag-3-line' },
  children: [
    {
      path: 'list',
      name: 'CategoryList',
      component: '/category/list',
      meta: { title: '分类列表' }
    }
  ]
} as RouteRecordRaw
