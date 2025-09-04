#!/bin/bash

# Cap PHP Server - 发布版本清理脚本
# 根据项目清理规范，为发布准备清理目录

echo "🚀 准备发布 Cap PHP Server..."
echo

# 1. 清理测试文件（可选）
echo "📋 检查测试文件..."
if [ -d "tests" ]; then
    echo "   发现测试目录 tests/"
    read -p "   是否保留测试文件？ (y/N): " keep_tests
    if [[ ! $keep_tests =~ ^[Yy]$ ]]; then
        echo "   删除测试目录..."
        rm -rf tests/
        echo "   删除 phpunit.xml..."
        rm -f phpunit.xml
        echo "   ✅ 测试文件已清理"
    else
        echo "   ✅ 保留测试文件"
    fi
fi

# 2. 清理临时文件
echo
echo "🧹 清理临时文件..."

# 删除调试日志
if [ -f "debug_capjs_detailed.log" ]; then
    rm -f debug_capjs_detailed.log
    echo "   ✅ 删除调试日志"
fi

# 删除临时测试文件
find . -name "cap_test_*.json" -delete 2>/dev/null
find . -name "*.tmp" -delete 2>/dev/null

# 删除IDE临时文件
if [ -d ".qoder" ]; then
    rm -rf .qoder/
    echo "   ✅ 删除IDE临时文件"
fi

# 3. 清理vendor目录（开发依赖）
echo
echo "📦 检查依赖..."
if [ -d "vendor" ]; then
    read -p "   重新安装生产依赖（删除开发依赖）？ (y/N): " reinstall_deps
    if [[ $reinstall_deps =~ ^[Yy]$ ]]; then
        echo "   删除vendor目录..."
        rm -rf vendor/
        echo "   重新安装生产依赖..."
        composer install --no-dev --optimize-autoloader
        echo "   ✅ 生产依赖已优化"
    fi
fi

# 4. 验证核心文件
echo
echo "🔍 验证核心文件..."

core_files=(
    "src/"
    "example/"
    "composer.json"
    "README.md"
    "LICENSE"
    "DEPLOY_NGINX.md"
    "OPTIMIZATION_SUMMARY.md"
    "TESTING_REPORT.md"
)

missing_files=()
for file in "${core_files[@]}"; do
    if [ ! -e "$file" ]; then
        missing_files+=("$file")
    else
        echo "   ✅ $file"
    fi
done

if [ ${#missing_files[@]} -ne 0 ]; then
    echo
    echo "⚠️  缺失核心文件:"
    for file in "${missing_files[@]}"; do
        echo "   ❌ $file"
    done
fi

# 5. 验证demo文件
echo
echo "🎯 验证demo文件..."
demo_files=(
    "example/index.html"
    "example/index.php"
)

for file in "${demo_files[@]}"; do
    if [ -e "$file" ]; then
        echo "   ✅ $file"
    else
        echo "   ❌ $file (缺失)"
    fi
done

# 6. 最终检查
echo
echo "📊 目录结构概览:"
echo "   核心目录: src/"
echo "   示例目录: example/"
echo "   配置文件: composer.json"
echo "   文档文件: *.md"

# 7. 生成发布信息
echo
echo "📝 生成发布信息..."
cat > RELEASE_INFO.txt << EOF
Cap PHP Server - 发布信息
========================

发布时间: $(date '+%Y-%m-%d %H:%M:%S')
版本: $(grep '"version"' composer.json 2>/dev/null | sed 's/.*: *"\([^"]*\)".*/\1/' || echo "未指定")

包含文件:
- src/               # 核心源代码
- example/           # 示例和演示
- composer.json      # Composer配置
- README.md          # 项目文档
- LICENSE            # 开源许可证
- DEPLOY_NGINX.md    # 部署指南
- OPTIMIZATION_SUMMARY.md  # 优化总结
- TESTING_REPORT.md  # 测试报告

特性:
✅ SHA-256工作量证明机制
✅ 多存储支持 (Redis/File/Memory)
✅ 频率限制保护
✅ cap.js完全兼容
✅ 企业级安全性
✅ 完整测试覆盖

安装:
composer require sparkinzy/cap_php_server

EOF

echo "   ✅ 发布信息已保存到 RELEASE_INFO.txt"

echo
echo "🎉 发布准备完成！"
echo
echo "📋 下一步:"
echo "   1. 检查 RELEASE_INFO.txt"
echo "   2. 更新版本号 (composer.json)"
echo "   3. 创建 Git 标签"
echo "   4. 发布到 Packagist"
echo