<?php
/**
 * Chain Test Execution Order
 *
 * 定义链路测试的执行顺序，按依赖关系从低到高排列。
 *
 * 层级说明:
 * - L1: 基础设施层（无依赖）
 * - L2: 核心扫描层（依赖 L1）
 * - L3: 配置验证层（相对独立）
 * - L4: 站点管理层（依赖 L2, L3）
 * - L5: 同步执行层（依赖 L4）
 * - L6: 翻译模块层（依赖 L2）
 * - L7: 扩展功能层（依赖 L5, L6）
 * - L8: UI/API 层（综合测试）
 *
 * @package WPTSALL
 * @since 0.9.0
 */

return array(
	// ========================================
	// L1: 基础设施层 - 无外部依赖
	// ========================================
	array(
		'level'       => 'L1',
		'name'        => '基础设施层',
		'description' => '数据库、字段发现、分类器等基础功能',
		'chains'      => array(
			'test-chain-14-field-discovery.php',    // 字段发现服务
			'test-chain-21-plugin-discovery.php',   // 插件动态发现
			'test-chain-12-data-classification.php', // 数据分类与访问验证
		),
	),

	// ========================================
	// L2: 核心扫描层 - 依赖 L1
	// ========================================
	array(
		'level'       => 'L2',
		'name'        => '核心扫描层',
		'description' => '模型扫描、字段分类',
		'chains'      => array(
			'test-chain-1-model-scanning.php', // 模型扫描链路
		),
	),

	// ========================================
	// L3: 配置验证层 - 相对独立
	// ========================================
	array(
		'level'       => 'L3',
		'name'        => '配置验证层',
		'description' => '验证器、任务参数配置',
		'chains'      => array(
			'test-chain-16-validation.php',      // 验证器链路
			'test-chain-15-task-parameters.php', // 任务参数与缓存配置
		),
	),

	// ========================================
	// L4: 站点管理层 - 依赖 L2, L3
	// ========================================
	array(
		'level'       => 'L4',
		'name'        => '站点管理层',
		'description' => '站点关系、虚拟站点管理',
		'chains'      => array(
			'test-chain-2-site-relation.php',     // 站点关系配置
			'test-chain-4-virtual-routing.php',   // 虚拟站点路由
			'test-chain-18-virtual-frontend.php', // 虚拟站点前端路由
		),
	),

	// ========================================
	// L5: 同步执行层 - 依赖 L4
	// ========================================
	array(
		'level'       => 'L5',
		'name'        => '同步执行层',
		'description' => '钩子生成、ID 映射、内容同步（chain-9 已迁入 legacy/）',
		'chains'      => array(
			'test-chain-7-hook-generation.php', // 钩子自动生成（含副作用断言）
			'test-chain-8-id-mapping.php',      // ID 映射
			'test-chain-3-content-sync.php',    // 内容同步执行（monitor/start 路径）
		),
	),

	// ========================================
	// L6: 翻译模块层 - 依赖 L2
	// ========================================
	array(
		'level'       => 'L6',
		'name'        => '翻译模块层',
		'description' => '语言包扫描、Gettext 翻译',
		'chains'      => array(
			'test-chain-6-language-pack.php', // 语言包扫描
			'test-chain-5-gettext.php',       // Gettext 翻译
		),
	),

	// ========================================
	// L7: 扩展功能层 - 依赖 L5, L6
	// chain-11（Cron）、chain-19（Admin UI）、chain-20（旧 REST）已迁入 legacy/
	// ========================================
	array(
		'level'       => 'L7',
		'name'        => '扩展功能层',
		'description' => '自定义模型、导入导出',
		'chains'      => array(
			'test-chain-13-custom-model.php', // 自定义模型服务
			'test-chain-17-import-export.php', // 导入导出（H2 validation 回传）
		),
	),
);
