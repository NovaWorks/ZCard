<?php

return [

    'column_manager' => [

        'heading' => '列',

        'actions' => [

            'apply' => [
                'label' => '应用列',
            ],

            'reorder' => [
                'label' => '排序列',
            ],

            'reset' => [
                'label' => '重置',
            ],

        ],

    ],

    'columns' => [

        'actions' => [
            'label' => '操作|操作',
        ],

        'icon' => [

            'boolean' => [
                'true' => '是',
                'false' => '否',
            ],

        ],

        'select' => [

            'loading_message' => '加载中...',

            'no_options_message' => '没有可选项。',

            'no_search_results_message' => '无匹配选项。',

            'placeholder' => '选择一项',

            'searching_message' => '搜索中...',

            'search_prompt' => '输入以搜索...',

        ],

        'text' => [

            'actions' => [
                'collapse_list' => '收起 :count 项',
                'expand_list' => '展开更多 :count 项',
            ],

            'more_list_items' => '还有 :count 项',

        ],

    ],

    'fields' => [

        'bulk_select_page' => [
            'label' => '全选/取消全选本页用于批量操作。',
        ],

        'bulk_select_record' => [
            'label' => '选择/取消选择第 :key 项用于批量操作。',
        ],

        'bulk_select_group' => [
            'label' => '选择/取消选择分组 :title 用于批量操作。',
        ],

        'search' => [
            'label' => '搜索',
            'placeholder' => '搜索',
            'indicator' => '搜索',
        ],

    ],

    'summary' => [

        'heading' => '汇总',

        'subheadings' => [
            'all' => '全部 :label',
            'group' => ':group 汇总',
            'page' => '本页',
        ],

        'summarizers' => [

            'average' => [
                'label' => '平均',
            ],

            'count' => [
                'label' => '计数',
            ],

            'sum' => [
                'label' => '求和',
            ],

        ],

    ],

    'actions' => [

        'disable_reordering' => [
            'label' => '完成排序',
        ],

        'enable_reordering' => [
            'label' => '拖拽排序',
        ],

        'reorder_record' => [
            'label' => '排序第 :key 项',
        ],

        'filter' => [
            'label' => '筛选',
        ],

        'group' => [
            'label' => '分组',
        ],

        'open_bulk_actions' => [
            'label' => '批量操作',
        ],

        'column_manager' => [
            'label' => '列管理',
        ],

        'toggle_record_content' => [
            'label' => '展开/收起第 :key 项',
        ],

    ],

    'empty' => [

        'heading' => '暂无:model',

        'description' => '创建一个:model 以开始。',

    ],

    'filters' => [

        'actions' => [

            'apply' => [
                'label' => '应用筛选',
            ],

            'remove' => [
                'label' => '移除筛选',
            ],

            'remove_all' => [
                'label' => '移除全部筛选',
                'tooltip' => '移除全部筛选',
            ],

            'reset' => [
                'label' => '重置',
            ],

        ],

        'heading' => '筛选',

        'indicator' => '已启用筛选',

        'multi_select' => [
            'placeholder' => '全部',
        ],

        'select' => [

            'placeholder' => '全部',

            'relationship' => [
                'empty_option_label' => '无',
            ],

        ],

        'trashed' => [

            'label' => '已删除记录',

            'only_trashed' => '仅已删除',
            'with_trashed' => '含已删除',
            'without_trashed' => '不含已删除',

        ],

    ],

    'grouping' => [

        'fields' => [

            'group' => [
                'label' => '分组依据',
            ],

            'direction' => [

                'label' => '分组方向',

                'options' => [
                    'asc' => '升序',
                    'desc' => '降序',
                ],

            ],

        ],

    ],

    'loading' => '加载中...',

    'reorder_indicator' => '拖拽记录以排序。',

    'result_count' => '{0} 无结果|{1} :count 条结果|[2,*] :count 条结果',

    'selection_indicator' => [

        'selected_count' => '已选 1 条|已选 :count 条',

        'actions' => [

            'select_all' => [
                'label' => '全选 :count',
            ],

            'deselect_all' => [
                'label' => '取消全选',
            ],

        ],

    ],

    'sorting' => [

        'fields' => [

            'column' => [
                'label' => '排序依据',
            ],

            'direction' => [

                'label' => '排序方向',

                'options' => [
                    'asc' => '升序',
                    'desc' => '降序',
                ],

            ],

        ],

    ],

    'default_model_label' => '记录',

];
