<script setup lang="ts">
/**
 * 统一图标组件(storefront)
 *
 * 基于 unplugin-icons + Remix Icon(ri: 前缀,与 sysadmin 的 @iconify/vue 命名一致)。
 * 图标在编译期内联为 SVG,产物零运行时请求、可 currentColor 染色。
 * 用到的图标在此集中注册,新图标只需 import 并加入 map。
 *
 * 用法:<AppIcon name="ri:shopping-cart-2-line" class="w-5 h-5" />
 * 外部数据(如分类图标)以 "ri:" 开头时也会走 Iconify 渲染(见 CategoryNav)。
 */
import { computed } from 'vue'

import RIRocketLine from '~icons/ri/rocket-line'
import RIShieldCheckLine from '~icons/ri/shield-check-line'
import RITimeLine from '~icons/ri/time-line'
import RICustomerService2Line from '~icons/ri/customer-service-2-line'
import RINotification3Line from '~icons/ri/notification-3-line'
import RIShoppingCart2Line from '~icons/ri/shopping-cart-2-line'
import RIUser3Line from '~icons/ri/user-3-line'
import RIFolder2Line from '~icons/ri/folder-2-line'
import RIFolderOpenLine from '~icons/ri/folder-open-line'
import RIFileTextLine from '~icons/ri/file-text-line'
import RIHome4Line from '~icons/ri/home-4-line'
import RIMenuLine from '~icons/ri/menu-line'
import RIToolsLine from '~icons/ri/tools-line'
import RIArchiveLine from '~icons/ri/archive-line'
import RIMoneyCnyCircleLine from '~icons/ri/money-cny-circle-line'
import RICloseLine from '~icons/ri/close-line'
import RICloseCircleLine from '~icons/ri/close-circle-line'
import RICheckboxCircleLine from '~icons/ri/checkbox-circle-line'
import RICheckLine from '~icons/ri/check-line'
import RIEmotionSadLine from '~icons/ri/emotion-sad-line'
import RISearchLine from '~icons/ri/search-line'
import RIAlertLine from '~icons/ri/alert-line'
import RILightbulbLine from '~icons/ri/lightbulb-line'
import RIClipboardLine from '~icons/ri/clipboard-line'
import RIPlugLine from '~icons/ri/plug-line'
import RIEmotionHappyLine from '~icons/ri/emotion-happy-line'
import RIStarFill from '~icons/ri/star-fill'
import RILink from '~icons/ri/link'
import RIBookOpenLine from '~icons/ri/book-open-line'
import RIGift2Line from '~icons/ri/gift-2-line'
import RIStore3Line from '~icons/ri/store-3-line'
import RIBankCardLine from '~icons/ri/bank-card-line'
import RIBankCard2Line from '~icons/ri/bank-card-2-line'
import RIBankLine from '~icons/ri/bank-line'
import RICoinsLine from '~icons/ri/coins-line'
import RIMegaphoneLine from '~icons/ri/megaphone-line'
import RIShoppingBagLine from '~icons/ri/shopping-bag-line'
import RILayoutGridLine from '~icons/ri/layout-grid-line'
import RILayout2Line from '~icons/ri/layout-2-line'
import RIAlipayLine from '~icons/ri/alipay-line'
import RIWechatLine from '~icons/ri/wechat-line'
import RIQqLine from '~icons/ri/qq-line'
import RIPaypalLine from '~icons/ri/paypal-line'
import RILockLine from '~icons/ri/lock-line'

/** 名称 → 图标组件映射(与 Iconify 命名一致,如 "ri:bank-card-line") */
const MAP: Record<string, any> = {
  'ri:rocket-line': RIRocketLine,
  'ri:shield-check-line': RIShieldCheckLine,
  'ri:time-line': RITimeLine,
  'ri:customer-service-2-line': RICustomerService2Line,
  'ri:notification-3-line': RINotification3Line,
  'ri:shopping-cart-2-line': RIShoppingCart2Line,
  'ri:user-3-line': RIUser3Line,
  'ri:folder-2-line': RIFolder2Line,
  'ri:folder-open-line': RIFolderOpenLine,
  'ri:file-text-line': RIFileTextLine,
  'ri:home-4-line': RIHome4Line,
  'ri:menu-line': RIMenuLine,
  'ri:tools-line': RIToolsLine,
  'ri:archive-line': RIArchiveLine,
  'ri:money-cny-circle-line': RIMoneyCnyCircleLine,
  'ri:close-line': RICloseLine,
  'ri:close-circle-line': RICloseCircleLine,
  'ri:checkbox-circle-line': RICheckboxCircleLine,
  'ri:check-line': RICheckLine,
  'ri:emotion-sad-line': RIEmotionSadLine,
  'ri:search-line': RISearchLine,
  'ri:alert-line': RIAlertLine,
  'ri:lightbulb-line': RILightbulbLine,
  'ri:clipboard-line': RIClipboardLine,
  'ri:plug-line': RIPlugLine,
  'ri:emotion-happy-line': RIEmotionHappyLine,
  'ri:star-fill': RIStarFill,
  'ri:link': RILink,
  'ri:book-open-line': RIBookOpenLine,
  'ri:gift-2-line': RIGift2Line,
  'ri:store-3-line': RIStore3Line,
  'ri:bank-card-line': RIBankCardLine,
  'ri:bank-card-2-line': RIBankCard2Line,
  'ri:bank-line': RIBankLine,
  'ri:coins-line': RICoinsLine,
  'ri:megaphone-line': RIMegaphoneLine,
  'ri:shopping-bag-line': RIShoppingBagLine,
  'ri:layout-grid-line': RILayoutGridLine,
  'ri:layout-2-line': RILayout2Line,
  'ri:alipay-line': RIAlipayLine,
  'ri:wechat-line': RIWechatLine,
  'ri:qq-line': RIQqLine,
  'ri:paypal-line': RIPaypalLine,
  'ri:lock-line': RILockLine,
}

const props = defineProps<{ name?: string }>()
const Comp = computed(() => (props.name && MAP[props.name]) || RIFileTextLine)
</script>

<template>
  <component :is="Comp" />
</template>
