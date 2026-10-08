<?php
/**
 * E2E v2 共享 PHP 工具函数
 *
 * 所有 PHP 脚本 require 此文件获取通用 helper。
 * 必须在 wp eval-file 上下文中使用（ABSPATH 已定义）。
 *
 * @package WPTSALL\E2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

/**
 * 翻译标记正则模式。
 * 匹配 【en_US】...【/en_US】 等格式。
 */
define( 'E2E_MARKER_PATTERN', '/\xe3\x80\x90[a-z]{2}_[A-Z]{2}\xe3\x80\x91.*?\xe3\x80\x90\/[a-z]{2}_[A-Z]{2}\xe3\x80\x91/s' );

/**
 * 当前 E2E scope。
 */
function e2e_scope(): string {
	$scope = getenv( 'E2E_SCOPE' );
	$scope = is_string( $scope ) ? trim( $scope ) : '';

	return '' !== $scope ? $scope : 'full';
}

/**
 * 当前 E2E project。
 */
function e2e_project(): string {
	$project = getenv( 'E2E_PROJECT' );
	$project = is_string( $project ) ? trim( $project ) : '';

	return '' !== $project ? $project : 'core-content';
}

/**
 * Matrix parallel lane (distinct E2E_SLOT client + namespaced virtual relation).
 */
function e2e_is_matrix_parallel_lane(): bool {
	$par = getenv( 'E2E_MATRIX_PARALLEL' );
	if ( ! in_array( $par, array( '1', 'true', 'yes' ), true ) ) {
		return false;
	}
	$slot = getenv( 'E2E_SLOT' );
	return is_string( $slot ) && '' !== $slot && 'shared' !== $slot;
}

/**
 * Stable namespace for per-lane virtual relation / logs (slot + project).
 */
function e2e_lane_namespace(): string {
	$slot    = getenv( 'E2E_SLOT' );
	$slot    = is_string( $slot ) ? $slot : 'shared';
	$project = e2e_project();
	$short   = preg_replace( '/-content$/', '', $project );
	$raw     = strtolower( $slot . '_' . $short );
	$raw     = preg_replace( '/[^a-z0-9_]+/', '_', $raw );

	return substr( $raw, 0, 48 );
}

/**
 * Dedicated virtual-site target id for this matrix lane (avoids relation contention).
 */
function e2e_lane_virtual_target_id(): string {
	return 'v_e2e_' . e2e_lane_namespace();
}

/**
 * Plugin slugs this lane should scan/bind in matrix-parallel mode.
 *
 * Merge model_binding_plugins into the lane scan, not just model_plugins:
 * meta-only projects (e.g. ACF) bind their model plugins through
 * model_binding_plugins (wordpress-blog + woocommerce), and those models'
 * rules must exist for Stage 4 verification (e.g. product rules carrying
 * the seeded ACF "brand" meta).
 *
 * @return string[]
 */
function e2e_lane_model_plugin_slugs(): array {
	$slugs = array_merge( e2e_model_plugin_slugs(), e2e_model_binding_plugin_slugs() );
	if ( ! in_array( 'wordpress-blog', $slugs, true ) ) {
		$slugs[] = 'wordpress-blog';
	}

	return array_values( array_unique( array_filter( $slugs ) ) );
}

/**
 * 加载独立插件 project canonical spec。
 *
 * @return array<string,array<string,mixed>>
 */
function e2e_plugin_project_specs(): array {
	static $specs = null;

	if ( null !== $specs ) {
		return $specs;
	}

	$file = dirname( __DIR__ ) . '/project-specs.json';
	if ( ! file_exists( $file ) ) {
		$specs = array();
		return $specs;
	}

	$decoded = json_decode( (string) file_get_contents( $file ), true );
	$projects = is_array( $decoded['plugin_projects'] ?? null ) ? $decoded['plugin_projects'] : array();
	$specs    = array();

	foreach ( $projects as $project_name => $project_spec ) {
		if ( ! is_string( $project_name ) || ! is_array( $project_spec ) ) {
			continue;
		}
		$specs[ $project_name ] = $project_spec;
	}

	return $specs;
}

/**
 * 获取当前或指定 plugin project spec。
 *
 * @param string|null $project Project name.
 * @return array<string,mixed>
 */
function e2e_plugin_project_spec( ?string $project = null ): array {
	$project = is_string( $project ) && '' !== $project ? $project : e2e_project();
	$specs   = e2e_plugin_project_specs();

	return is_array( $specs[ $project ] ?? null ) ? $specs[ $project ] : array();
}

/**
 * 读取 plugin project scalar field。
 *
 * @param string      $field   Field name.
 * @param string|null $project Project name.
 * @return string
 */
function e2e_plugin_project_scalar_field( string $field, ?string $project = null ): string {
	$spec = e2e_plugin_project_spec( $project );
	$value = $spec[ $field ] ?? '';
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * 读取 plugin project list field。
 *
 * @param string      $field   Field name.
 * @param string|null $project Project name.
 * @return string[]
 */
function e2e_plugin_project_list_field( string $field, ?string $project = null ): array {
	$spec = e2e_plugin_project_spec( $project );
	$raw  = $spec[ $field ] ?? array();

	if ( ! is_array( $raw ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map(
				static function ( $value ): string {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				$raw
			),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		)
	);
}

/**
 * 读取 plugin project 的 seed expectation。
 *
 * @param string|null $project Project name.
 * @return array<string,mixed>
 */
function e2e_plugin_project_seed_expectation( ?string $project = null ): array {
	$spec = e2e_plugin_project_spec( $project );
	$raw  = $spec['seed_expectation'] ?? array();

	return is_array( $raw ) ? $raw : array();
}

/**
 * 读取 plugin project 的 generic verifier spec。
 *
 * @param string|null $project Project name.
 * @return array<string,mixed>
 */
function e2e_plugin_project_verifier_spec( ?string $project = null ): array {
	$spec = e2e_plugin_project_spec( $project );
	$raw  = $spec['verifier'] ?? array();

	return is_array( $raw ) ? $raw : array();
}

/**
 * 读取 plugin project 的 seed verifier contract。
 *
 * @param string|null $project Project name.
 * @return array<string,mixed>
 */
function e2e_plugin_project_seed_checks( ?string $project = null ): array {
	$spec = e2e_plugin_project_spec( $project );
	$raw  = $spec['seed_checks'] ?? array();

	return is_array( $raw ) ? $raw : array();
}

/**
 * 当前 project 是否为独立插件 project。
 */
function e2e_is_plugin_specific_project(): bool {
	return ! empty( e2e_plugin_project_spec() );
}

/**
 * 当前独立插件 project 对应的 plugin slug；非独立项目则返回空串。
 */
function e2e_project_plugin_slug(): string {
	return e2e_plugin_project_scalar_field( 'plugin_slug' );
}

/**
 * 是否为 core-content 项目。
 */
function e2e_is_core_content_project(): bool {
	return in_array( e2e_project(), array( 'core-content', 'wptsall-content' ), true );
}

/**
 * 是否为 learning-content 项目。
 */
function e2e_is_learning_content_project(): bool {
	return in_array( e2e_project(), array( 'learning-content', 'tutor-content', 'learnpress-content' ), true );
}

/**
 * 是否为 commerce-content 项目。
 */
function e2e_is_commerce_content_project(): bool {
	return in_array( e2e_project(), array( 'commerce-content', 'woocommerce-content', 'easy-digital-downloads-content' ), true );
}

/**
 * 是否为 media-builder-content 项目。
 */
function e2e_is_media_builder_content_project(): bool {
	return in_array( e2e_project(), array( 'media-builder-content', 'envira-gallery-lite-content', 'elementor-content' ), true );
}

/**
 * 是否为 community-content 项目。
 */
function e2e_is_community_content_project(): bool {
	return in_array( e2e_project(), array( 'community-content', 'bbpress-content', 'site-reviews-content' ), true );
}

/**
 * 是否为 listings-events-content 项目。
 */
function e2e_is_listings_events_content_project(): bool {
	return in_array( e2e_project(), array( 'listings-events-content', 'the-events-calendar-content', 'wp-job-manager-content' ), true );
}

/**
 * 是否为 content-meta-content 项目。
 */
function e2e_is_content_meta_content_project(): bool {
	return in_array(
		e2e_project(),
		array(
			'content-meta-content',
			'seriously-simple-podcasting-content',
			'wp-recipe-maker-content',
			'wordpress-seo-content',
			'advanced-custom-fields-content',
		),
		true
	);
}

/**
 * 是否为 core-only 模式。
 */
function e2e_is_core_only(): bool {
	return 'core-only' === e2e_scope();
}

/**
 * Whether current project exactly matches the given project name.
 *
 * @param string $project Project name.
 * @return bool
 */
function e2e_is_exact_project( string $project ): bool {
	return e2e_project() === $project;
}

/**
 * WPTSALL 表前缀。
 */
function e2e_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'wptsall_' . $name;
}

/**
 * 检查表是否存在。
 */
function e2e_table_exists( string $table_name ): bool {
	global $wpdb;
	return (bool) $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" );
}

/**
 * 获取表行数。
 */
function e2e_table_count( string $table_name ): int {
	global $wpdb;
	if ( ! e2e_table_exists( $table_name ) ) {
		return 0;
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
}

/**
 * 清空表（TRUNCATE）。
 */
function e2e_truncate( string $table_name ): int {
	global $wpdb;
	if ( ! e2e_table_exists( $table_name ) ) {
		return 0;
	}
	$count = e2e_table_count( $table_name );
	$wpdb->query( "TRUNCATE TABLE $table_name" );
	return $count;
}

/**
 * 打印检查结果并记录。
 */
function e2e_check( string $name, bool $pass, string $detail, array &$checks, int &$passed, int &$failed ): void {
	$checks[] = array(
		'name'   => $name,
		'pass'   => $pass,
		'detail' => $detail,
	);
	if ( $pass ) {
		++$passed;
	} else {
		++$failed;
	}
}

/**
 * 打印检查结果表。
 */
function e2e_print_results( array $checks, int $passed, int $failed, int $skipped = 0 ): void {
	echo "\n" . str_repeat( '-', 70 ) . "\n";
	foreach ( $checks as $c ) {
		$icon = $c['pass'] ? 'PASS' : 'FAIL';
		echo sprintf( "[%s] %-48s %s\n", $icon, $c['name'], $c['detail'] );
	}
	echo str_repeat( '-', 70 ) . "\n";
	echo sprintf(
		"\nResult: %d passed, %d failed, %d skipped out of %d checks\n",
		$passed, $failed, $skipped, count( $checks )
	);
}

/**
 * 当前 E2E slot。
 */
function e2e_slot(): string {
	$slot = getenv( 'E2E_SLOT' );
	return is_string( $slot ) && '' !== $slot ? $slot : 'shared';
}

/**
 * Whether the current run is using a dedicated slot instead of shared mode.
 */
function e2e_is_slot_mode(): bool {
	return 'shared' !== e2e_slot();
}

/**
 * E2E runtime 根目录。
 */
function e2e_runtime_root_dir(): string {
	return dirname( __DIR__ ) . '/runtime';
}

/**
 * E2E reports 根目录。
 */
function e2e_reports_root_dir(): string {
	return dirname( __DIR__ ) . '/reports';
}

/**
 * 当前 slot 的 runtime 目录。
 */
function e2e_runtime_dir(): string {
	$root = e2e_runtime_root_dir();
	$slot = e2e_slot();
	return 'shared' === $slot ? $root : $root . '/' . $slot;
}

/**
 * 当前 slot 的 reports 目录。
 */
function e2e_reports_dir(): string {
	$root = e2e_reports_root_dir();
	$slot = e2e_slot();
	return 'shared' === $slot ? $root : $root . '/' . $slot;
}

/**
 * 当前 slot 的 runtime 文件路径。
 */
function e2e_runtime_file( string $filename ): string {
	return e2e_runtime_dir() . '/' . ltrim( $filename, '/' );
}

/**
 * 当前 slot 的 reports 文件路径。
 */
function e2e_reports_file( string $filename ): string {
	return e2e_reports_dir() . '/' . ltrim( $filename, '/' );
}

/**
 * 加载 runtime/relation-ids.json。
 */
function e2e_load_relation_ids(): array {
	$file = e2e_runtime_file( 'relation-ids.json' );
	if ( ! file_exists( $file ) ) {
		return array();
	}
	$data = json_decode( file_get_contents( $file ), true );
	return is_array( $data ) ? $data : array();
}

/**
 * 保存 runtime/relation-ids.json。
 */
function e2e_save_relation_ids( array $data ): void {
	$dir = e2e_runtime_dir();
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( e2e_runtime_file( 'relation-ids.json' ), json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
}

/**
 * 清理指定 blog 的翻译内容（ID > threshold）。
 * 包括删除 postmeta、term_relationships 和 posts。
 *
 * @return int 删除的 post 数量。
 */
function e2e_clean_blog( int $blog_id, int $threshold = 30 ): int {
	global $wpdb;
	$prefix      = $wpdb->get_blog_prefix( $blog_id );
	$posts_table = $prefix . 'posts';

	if ( ! e2e_table_exists( $posts_table ) ) {
		return 0;
	}

	$before = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM $posts_table WHERE ID > $threshold"
	);

	if ( $before === 0 ) {
		return 0;
	}

	$meta_table = $prefix . 'postmeta';
	$tr_table   = $prefix . 'term_relationships';

	$wpdb->query(
		"DELETE pm FROM $meta_table pm INNER JOIN $posts_table p ON pm.post_id = p.ID WHERE p.ID > $threshold"
	);
	$wpdb->query(
		"DELETE tr FROM $tr_table tr INNER JOIN $posts_table p ON tr.object_id = p.ID WHERE p.ID > $threshold"
	);
	$deleted = (int) $wpdb->query(
		"DELETE FROM $posts_table WHERE ID > $threshold"
	);

	return $deleted;
}

/**
 * 批量删除 posts（按 ID 列表，含 meta 和 term_relationships）。
 *
 * @param int[] $post_ids Post IDs to delete.
 * @return int 删除的 post 数量。
 */
function e2e_delete_posts_by_ids( array $post_ids ): int {
	global $wpdb;

	if ( empty( $post_ids ) ) {
		return 0;
	}

	$total = count( $post_ids );
	foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
		$id_list = implode( ',', array_map( 'intval', $chunk ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_list)" );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($id_list)" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($id_list)" );
	}

	return $total;
}

/**
 * 15 个测试插件 slug 列表。
 */
function e2e_plugin_slugs(): array {
	$project_plugin = e2e_project_plugin_slug();
	if ( '' !== $project_plugin ) {
		return array( $project_plugin );
	}

	if ( e2e_is_learning_content_project() ) {
		return array( 'tutor', 'learnpress' );
	}

	if ( e2e_is_commerce_content_project() ) {
		return array( 'woocommerce', 'easy-digital-downloads' );
	}

	if ( e2e_is_media_builder_content_project() ) {
		return array( 'envira-gallery-lite', 'elementor' );
	}

	if ( e2e_is_community_content_project() ) {
		return array( 'bbpress', 'site-reviews' );
	}

	if ( e2e_is_listings_events_content_project() ) {
		return array( 'the-events-calendar', 'wp-job-manager' );
	}

	if ( e2e_is_content_meta_content_project() ) {
		return array( 'seriously-simple-podcasting', 'wp-recipe-maker', 'wordpress-seo', 'advanced-custom-fields', 'wptsall' );
	}

	if ( e2e_is_core_only() ) {
		return array( 'wordpress-blog' );
	}

	return array(
		'woocommerce',
		'easy-digital-downloads',
		'bbpress',
		'tutor',
		'learnpress',
		'the-events-calendar',
		'wp-job-manager',
		'envira-gallery-lite',
		'seriously-simple-podcasting',
		'wp-recipe-maker',
		'elementor',
		'wordpress-seo',
		'advanced-custom-fields',
		'site-reviews',
		'wptsall',
	);
}

/**
 * 需要生成独立 model 的插件（具备可扫描内容对象）。
 *
 * 说明：
 * - `wordpress-seo` / `advanced-custom-fields` 属于“字段增强型”插件，通常不会单独注册
 *   可翻译内容对象（post_type/taxonomy），其字段会归入已有内容模型规则里验证。
 */
function e2e_model_plugin_slugs(): array {
	if ( e2e_is_plugin_specific_project() ) {
		return e2e_plugin_project_list_field( 'model_plugins' );
	}

	if ( e2e_is_learning_content_project() ) {
		return array( 'tutor', 'learnpress' );
	}

	if ( e2e_is_commerce_content_project() ) {
		return array( 'woocommerce', 'easy-digital-downloads' );
	}

	if ( e2e_is_media_builder_content_project() ) {
		return array( 'envira-gallery-lite', 'elementor' );
	}

	if ( e2e_is_community_content_project() ) {
		return array( 'bbpress', 'site-reviews' );
	}

	if ( e2e_is_listings_events_content_project() ) {
		return array( 'the-events-calendar', 'wp-job-manager' );
	}

	if ( e2e_is_content_meta_content_project() ) {
		return array( 'seriously-simple-podcasting', 'wp-recipe-maker', 'wptsall' );
	}

	if ( e2e_is_core_only() ) {
		return array( 'wordpress-blog' );
	}

	return array(
		'woocommerce',
		'easy-digital-downloads',
		'bbpress',
		'tutor',
		'learnpress',
		'the-events-calendar',
		'wp-job-manager',
		'envira-gallery-lite',
		'seriously-simple-podcasting',
		'wp-recipe-maker',
		'elementor',
		'site-reviews',
		'wptsall',
	);
}

/**
 * 字段增强型插件（不强制要求独立 model）。
 */
function e2e_meta_only_plugin_slugs(): array {
	if ( e2e_is_plugin_specific_project() ) {
		return e2e_plugin_project_list_field( 'meta_only_plugins' );
	}

	if ( e2e_is_learning_content_project() ) {
		return array();
	}

	if ( e2e_is_commerce_content_project() ) {
		return array();
	}

	if ( e2e_is_media_builder_content_project() ) {
		return array();
	}

	if ( e2e_is_community_content_project() ) {
		return array();
	}

	if ( e2e_is_listings_events_content_project() ) {
		return array();
	}

	if ( e2e_is_content_meta_content_project() ) {
		return array( 'wordpress-seo', 'advanced-custom-fields' );
	}

	if ( e2e_is_core_only() ) {
		return array();
	}

	return array(
		'wordpress-seo',
		'advanced-custom-fields',
	);
}

/**
 * 目标类型列表。
 */
function e2e_target_types(): array {
	if ( e2e_is_core_only() ) {
		return array( 'virtual', 'wp' );
	}

	return array( 'virtual', 'wp', 'self' );
}

/**
 * core-only 下需要覆盖的 WP 核心对象。
 */
function e2e_core_content_types(): array {
	$types = array( 'post', 'page', 'attachment' );

	if ( ! function_exists( 'sanitize_key' ) ) {
		return $types;
	}

	global $wpdb;
	$rules_table  = e2e_table( 'translation_rules' );
	$models_table = e2e_table( 'models' );

	if ( ! e2e_table_exists( $rules_table ) || ! e2e_table_exists( $models_table ) ) {
		return $types;
	}

	$taxonomy_rules = $wpdb->get_col(
		"SELECT DISTINCT r.object_name
		 FROM {$rules_table} r
		 INNER JOIN {$models_table} m ON r.model_id = m.id
		 WHERE r.is_active = 1
		   AND r.data_type = 'term'
		   AND m.plugin_slug = 'wordpress-blog'"
	);

	foreach ( (array) $taxonomy_rules as $object_name ) {
		$key = sanitize_key( (string) $object_name );
		if ( '' !== $key ) {
			$types[] = $key;
		}
	}

	$types = array_values( array_unique( $types ) );
	sort( $types );

	return $types;
}

/**
 * Plugin slugs that should be relation-bound for the active project.
 *
 * Empty array means "bind all active models".
 *
 * @return string[]
 */
function e2e_model_binding_plugin_slugs(): array {
	if ( e2e_is_plugin_specific_project() ) {
		return e2e_plugin_project_list_field( 'model_binding_plugins' );
	}

	if ( e2e_is_learning_content_project() ) {
		return array( 'wordpress-blog', 'tutor', 'learnpress' );
	}

	if ( e2e_is_commerce_content_project() ) {
		return array( 'wordpress-blog', 'woocommerce', 'easy-digital-downloads' );
	}

	if ( e2e_is_media_builder_content_project() ) {
		return array( 'wordpress-blog', 'envira-gallery-lite', 'elementor' );
	}

	if ( e2e_is_community_content_project() ) {
		return array( 'wordpress-blog', 'bbpress', 'site-reviews' );
	}

	if ( e2e_is_listings_events_content_project() ) {
		return array( 'wordpress-blog', 'the-events-calendar', 'wp-job-manager' );
	}

	if ( e2e_is_content_meta_content_project() ) {
		return array( 'wordpress-blog', 'seriously-simple-podcasting', 'wp-recipe-maker' );
	}

	if ( e2e_is_core_only() ) {
		return array( 'wordpress-blog' );
	}

	return array();
}

/**
 * Collect active rule object names for a given plugin scope.
 *
 * @param string[] $plugin_slugs Plugin slugs.
 * @return string[]
 */
function e2e_collect_rule_object_names( array $plugin_slugs ): array {
	if ( empty( $plugin_slugs ) || ! function_exists( 'sanitize_key' ) ) {
		return array();
	}

	global $wpdb;
	$rules_table  = e2e_table( 'translation_rules' );
	$models_table = e2e_table( 'models' );

	if ( ! e2e_table_exists( $rules_table ) || ! e2e_table_exists( $models_table ) ) {
		return array();
	}

	$plugin_slugs = array_values(
		array_filter(
			array_map( 'sanitize_key', $plugin_slugs ),
			static function ( $value ) {
				return '' !== $value;
			}
		)
	);

	if ( empty( $plugin_slugs ) ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $plugin_slugs ), '%s' ) );
	$rows         = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT r.object_name
			 FROM {$rules_table} r
			 INNER JOIN {$models_table} m ON r.model_id = m.id
			 WHERE r.is_active = 1
			   AND r.object_name != ''
			   AND m.plugin_slug IN ({$placeholders})",
			$plugin_slugs
		)
	);

	$objects = array();
	foreach ( (array) $rows as $object_name ) {
		$key = sanitize_key( (string) $object_name );
		if ( '' !== $key ) {
			$objects[] = $key;
		}
	}

	$objects = array_values( array_unique( $objects ) );
	sort( $objects );

	return $objects;
}

/**
 * Project-required content objects (post_type / taxonomy names).
 *
 * @return string[]
 */
function e2e_project_content_types(): array {
	if ( e2e_is_plugin_specific_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment' ),
			e2e_plugin_project_list_field( 'content_types' ),
			e2e_collect_rule_object_names( e2e_model_binding_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	if ( e2e_is_learning_content_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment', 'courses', 'lp_course' ),
			e2e_collect_rule_object_names( e2e_model_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	if ( e2e_is_commerce_content_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment', 'product', 'download' ),
			e2e_collect_rule_object_names( e2e_model_binding_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	if ( e2e_is_media_builder_content_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment', 'envira' ),
			e2e_collect_rule_object_names( e2e_model_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	if ( e2e_is_community_content_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment', 'topic', 'site-review' ),
			e2e_collect_rule_object_names( e2e_model_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	if ( e2e_is_listings_events_content_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment', 'tribe_events', 'job_listing' ),
			e2e_collect_rule_object_names( e2e_model_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	if ( e2e_is_content_meta_content_project() ) {
		$types = array_merge(
			array( 'post', 'page', 'attachment', 'podcast', 'wprm_recipe' ),
			e2e_collect_rule_object_names( e2e_model_binding_plugin_slugs() )
		);
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		sort( $types );
		return $types;
	}

	return e2e_core_content_types();
}

/**
 * 当前 scope 的 post_type / taxonomy 覆盖集合。
 */
function e2e_expected_content_types(): array {
	if ( e2e_is_plugin_specific_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_learning_content_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_commerce_content_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_media_builder_content_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_community_content_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_listings_events_content_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_content_meta_content_project() ) {
		return e2e_project_content_types();
	}

	if ( e2e_is_core_only() ) {
		return e2e_core_content_types();
	}

	return array( 'post', 'page' );
}

/**
 * 当前 scope 的最低 rule format 要求。
 */
function e2e_required_rule_formats(): array {
	if ( e2e_is_plugin_specific_project() ) {
		$formats = e2e_plugin_project_list_field( 'required_rule_formats' );
		return ! empty( $formats ) ? $formats : array( 'plain_text', 'rich_html' );
	}

	if ( e2e_is_learning_content_project() ) {
		return array( 'plain_text', 'rich_html' );
	}

	if ( e2e_is_commerce_content_project() ) {
		return array( 'plain_text', 'rich_html', 'serialized_php' );
	}

	if ( e2e_is_media_builder_content_project() ) {
		return array( 'plain_text', 'rich_html', 'serialized_php', 'json_structured' );
	}

	if ( e2e_is_community_content_project() ) {
		return array( 'plain_text', 'rich_html' );
	}

	if ( e2e_is_listings_events_content_project() ) {
		return array( 'plain_text', 'rich_html' );
	}

	if ( e2e_is_content_meta_content_project() ) {
		return array( 'plain_text', 'rich_html', 'serialized_php', 'json_structured' );
	}

	if ( e2e_is_core_only() ) {
		return array( 'plain_text', 'rich_html' );
	}

	return array( 'plain_text', 'rich_html', 'serialized_php', 'json_structured' );
}

/**
 * 当前 project 是否要求启用 meta-only coverage gate。
 */
function e2e_requires_meta_only_coverage(): bool {
	return ! empty( e2e_meta_only_plugin_slugs() );
}

/**
 * Return canonical relation ID map for target types only.
 *
 * This prevents auxiliary runtime keys (for example `wp_blog_id`) from being
 * mistakenly treated as relation IDs by callers that iterate relation maps.
 *
 * @param array|null $relation_ids Optional relation ids payload.
 * @return array<string,int>
 */
function e2e_relation_ids_only( ?array $relation_ids = null ): array {
	if ( null === $relation_ids ) {
		$relation_ids = e2e_load_relation_ids();
	}

	$normalized = array();
	foreach ( e2e_target_types() as $type ) {
		$normalized[ $type ] = (int) ( $relation_ids[ $type ] ?? 0 );
	}

	return $normalized;
}

/**
 * Return active relation IDs keyed by target type.
 *
 * @param array|null $relation_ids Optional relation ids payload.
 * @return array<string,int>
 */
function e2e_active_relation_ids( ?array $relation_ids = null ): array {
	$normalized = e2e_relation_ids_only( $relation_ids );
	$active     = array();

	foreach ( $normalized as $type => $relation_id ) {
		if ( $relation_id > 0 ) {
			$active[ $type ] = $relation_id;
		}
	}

	return $active;
}

/**
 * Resolve WP multisite target blog ID used by E2E relation.
 *
 * Priority:
 * 1) runtime/relation-ids.json -> wp relation id -> site_relations.target_site_id
 * 2) relation-ids.json direct wp_blog_id (if present)
 * 3) fallback to blog 2, then first available subsite
 *
 * @param array|null $relation_ids Optional relation ids payload.
 * @return int Blog ID, 0 if unavailable.
 */
function e2e_get_wp_target_blog_id( ?array $relation_ids = null ): int {
	if ( ! is_multisite() ) {
		return 0;
	}

	if ( null === $relation_ids ) {
		$relation_ids = e2e_load_relation_ids();
	}

	$wp_relation_id = (int) ( $relation_ids['wp'] ?? 0 );
	if ( $wp_relation_id > 0 ) {
		global $wpdb;
		$rel_table = e2e_table( 'site_relations' );
		if ( e2e_table_exists( $rel_table ) ) {
			$target_site_id = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT target_site_id FROM {$rel_table} WHERE id = %d AND target_site_type = %s LIMIT 1",
					$wp_relation_id,
					'wp'
				)
			);
			if ( ctype_digit( $target_site_id ) ) {
				$blog_id = (int) $target_site_id;
				if ( $blog_id > 0 && get_blog_details( $blog_id ) ) {
					return $blog_id;
				}
			}
		}
	}

	$direct_blog = (int) ( $relation_ids['wp_blog_id'] ?? 0 );
	if ( $direct_blog > 0 && get_blog_details( $direct_blog ) ) {
		return $direct_blog;
	}

	// Stable fallback for local test env.
	if ( get_blog_details( 2 ) ) {
		return 2;
	}

	$sites = get_sites(
		array(
			'number'  => 20,
			'orderby' => 'id',
			'order'   => 'ASC',
		)
	);
	foreach ( $sites as $site ) {
		$blog_id = (int) ( $site->blog_id ?? 0 );
		if ( $blog_id > 1 ) {
			return $blog_id;
		}
	}

	return 0;
}
