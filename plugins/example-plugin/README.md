# 示例插件（example-plugin）

> ⚠ **Phase 0 占位**：本插件当前不会被 ZCard 主程序加载。
> 插件系统的 Hook 总线、安装/启停生命周期在 **Phase 2** 实现（见 spec §3.2）。

本目录用于演示未来插件的标准结构：

```
plugins/example-plugin/
├── plugin.json          # 清单：名称/版本/hooks/权限/配置
├── src/ServiceProvider.php  # 插件入口
└── README.md
```

Phase 2 文档完善后，第三方可照此结构编写并在线安装启停插件。
