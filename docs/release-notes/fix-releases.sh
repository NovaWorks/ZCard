#!/bin/bash
#
# 重新整理 GitHub Releases(需用有写权限的 GitHub 账号登录 gh)
# 用法: bash docs/release-notes/fix-releases.sh
#
# 如果 gh 登录的不是有写权限的账号,先:
#   gh auth login   # 切换到 Rust / 仓库 owner 账号
#
set -e
cd "$(git rev-parse --show-toplevel)"

echo "=== 检查 gh 登录 ==="
gh auth status || { echo "请先 gh auth login"; exit 1; }

echo ""
echo "=== 修复 v1.0.0(首个正式版,去掉 prerelease 标记) ==="
gh release edit v1.0.0 \
  --title "v1.0.0 首个正式版" \
  --notes-file docs/release-notes/v1.0.0.md \
  --repo NovaWorks/ZCard
# v1.0.0 改为正式版(非 prerelease)
gh release edit v1.0.0 --prerelease=false --repo NovaWorks/ZCard || true

echo ""
echo "=== 修复 v1.0.1(安装向导 + 在线更新) ==="
gh release edit v1.0.1 \
  --title "v1.0.1 安装向导 + 在线更新优化" \
  --notes-file docs/release-notes/v1.0.1.md \
  --repo NovaWorks/ZCard

echo ""
echo "=== 修复 v1.1.0(货源对接) ==="
gh release edit v1.1.0 \
  --title "v1.1.0 货源对接(双向供货/拿货)" \
  --notes-file docs/release-notes/v1.1.0.md \
  --repo NovaWorks/ZCard

echo ""
echo "=== 设置 Latest 指向 v1.1.0 ==="
# GitHub 自动把最新非 prerelease 设为 latest,这里确认一下
gh release list --repo NovaWorks/ZCard

echo ""
echo "✅ 全部 release 已整理完成"
