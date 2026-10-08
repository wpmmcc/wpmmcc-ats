<?php
/**
 * REST Integration Test Case Base Class
 *
 * 提供 REST API 驱动的集成测试基类
 * 支持 WordPress REST API 测试、资源跟踪和自动清理
 *
 * @package WPTSALL
 * @since 0.9.0
 */

/**
 * REST Integration Test Case
 *
 * 继承自 Integration_Test_Case，添加 REST API 测试支持
 */
class REST_Integration_Test_Case extends Integration_Test_Case {

	/**
	 * REST 服务器实例
	 *
	 * @var WP_REST_Server
	 */
	protected static $server;

	/**
	 * 管理员用户 ID
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * 跟踪的资源（用于自动清理）
	 *
	 * @var array
	 */
	protected $tracked_resources = array(
		'posts'           => array(),
		'virtual_sites'   => array(),
		'site_relations'  => array(),
		'tasks'           => array(),
		'templates'       => array(),
		'translation_rules' => array(),
	);

	/**
	 * 缓存的路由列表
	 *
	 * @var array|null
	 */
	protected static $cached_routes = null;

	/**
	 * 缓存的命名空间列表
	 *
	 * @var array|null
	 */
	protected static $cached_namespaces = null;

	/**
	 * REST 服务器是否正常初始化
	 *
	 * @var bool
	 */
	protected static $rest_server_ready = false;

	/**
	 * 是否已经完成全局初始化
	 *
	 * @var bool
	 */
	private static $global_init_done = false;

	/**
	 * 类级别初始化（所有测试前执行一次）
	 */
	public static function setUpBeforeClass(): void {
		global $wp_rest_server;

		// 防止多次初始化 REST 服务器
		if ( self::$global_init_done ) {
			return;
		}
		self::$global_init_done = true;

		// 初始化 REST 服务器
		self::$server = $wp_rest_server = new \WP_REST_Server();

		// 只注册 WPTSALL 的 REST 路由，避免其他插件导致挂起
		self::register_wptsall_routes_only();

		// 标记为就绪并缓存路由信息
		self::$rest_server_ready = true;
		self::$cached_routes = array_keys( self::$server->get_routes() );
		self::$cached_namespaces = self::$server->get_namespaces();
	}

	/**
	 * 只注册 WPTSALL 的 REST 路由
	 * 避免调用完整的 rest_api_init 导致其他插件挂起
	 */
	private static function register_wptsall_routes_only(): void {
		// 注册 WordPress 核心路由（必要的基础设施）
		create_initial_rest_routes();

		// 注册 WPTSALL 模块路由
		if ( class_exists( 'WPTSALL\Models\Module' ) ) {
			\WPTSALL\Models\Module::register_rest_routes();
		}
		if ( class_exists( 'WPTSALL\Sites\Module' ) ) {
			\WPTSALL\Sites\Module::register_rest_routes();
		}
		if ( class_exists( 'WPTSALL\Tasks\Module' ) ) {
			\WPTSALL\Tasks\Module::register_rest_routes();
		}
		if ( class_exists( 'WPTSALL\Templates\Module' ) ) {
			\WPTSALL\Templates\Module::register_rest_routes();
		}

		// 注册其他 WPTSALL 路由函数
		if ( function_exists( 'wptsall_register_translation_rule_rest_routes' ) ) {
			\WPTSALL\Models\API\wptsall_register_translation_rule_rest_routes();
		}
		if ( function_exists( 'wptsall_register_rest_routes' ) ) {
			wptsall_register_rest_routes();
		}
		if ( function_exists( 'wptsall_register_plugin_mapping_routes' ) ) {
			wptsall_register_plugin_mapping_routes();
		}
		if ( function_exists( 'wptsall_register_language_task_route' ) ) {
			wptsall_register_language_task_route();
		}
	}

	/**
	 * 每个测试前执行
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建管理员用户
		$this->admin_user_id = $this->create_admin_user();
		wp_set_current_user( $this->admin_user_id );

		// 重置资源跟踪
		$this->tracked_resources = array(
			'posts'           => array(),
			'virtual_sites'   => array(),
			'site_relations'  => array(),
			'tasks'           => array(),
			'templates'       => array(),
			'translation_rules' => array(),
		);
	}

	/**
	 * 每个测试后执行
	 */
	public function tearDown(): void {
		$this->cleanup_resources();
		parent::tearDown();
	}

	// ========================================
	// REST 请求方法
	// ========================================

	/**
	 * 发送 REST 请求
	 *
	 * @param string $method    HTTP 方法 (GET, POST, PUT, DELETE)
	 * @param string $route     路由路径 (不含命名空间前缀)
	 * @param array  $params    请求参数
	 * @param string $namespace 命名空间 (默认 wptsall/v2)
	 * @return WP_REST_Response
	 */
	protected function rest_request( $method, $route, $params = array(), $namespace = 'wptsall/v2' ) {
		$full_route = '/' . $namespace . '/' . ltrim( $route, '/' );

		$request = new \WP_REST_Request( $method, $full_route );

		// 根据方法设置参数
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_body_params( $params );
			$request->set_header( 'Content-Type', 'application/json' );
		} else {
			$request->set_query_params( $params );
		}

		return self::$server->dispatch( $request );
	}

	/**
	 * 发送 GET 请求
	 *
	 * @param string $route     路由路径
	 * @param array  $params    查询参数
	 * @param string $namespace 命名空间
	 * @return WP_REST_Response
	 */
	protected function rest_get( $route, $params = array(), $namespace = 'wptsall/v2' ) {
		return $this->rest_request( 'GET', $route, $params, $namespace );
	}

	/**
	 * 发送 POST 请求
	 *
	 * @param string $route     路由路径
	 * @param array  $params    请求体参数
	 * @param string $namespace 命名空间
	 * @return WP_REST_Response
	 */
	protected function rest_post( $route, $params = array(), $namespace = 'wptsall/v2' ) {
		return $this->rest_request( 'POST', $route, $params, $namespace );
	}

	/**
	 * 发送 PUT 请求
	 *
	 * @param string $route     路由路径
	 * @param array  $params    请求体参数
	 * @param string $namespace 命名空间
	 * @return WP_REST_Response
	 */
	protected function rest_put( $route, $params = array(), $namespace = 'wptsall/v2' ) {
		return $this->rest_request( 'PUT', $route, $params, $namespace );
	}

	/**
	 * 发送 DELETE 请求
	 *
	 * @param string $route     路由路径
	 * @param array  $params    查询参数
	 * @param string $namespace 命名空间
	 * @return WP_REST_Response
	 */
	protected function rest_delete( $route, $params = array(), $namespace = 'wptsall/v2' ) {
		return $this->rest_request( 'DELETE', $route, $params, $namespace );
	}

	/**
	 * 检查 REST 路由是否存在
	 *
	 * @param string $route     路由路径（支持通配符，如 'discovery/*'）
	 * @param string $namespace 命名空间
	 * @return bool
	 */
	protected function route_exists( $route, $namespace = 'wptsall/v2' ) {
		// 使用缓存的路由列表（避免重复调用 get_routes）
		if ( null === self::$cached_routes ) {
			return false;
		}

		$routes = self::$cached_routes;
		$full_route = '/' . $namespace . '/' . ltrim( $route, '/' );

		// 直接匹配 - $routes 是数字索引数组，使用 in_array
		if ( in_array( $full_route, $routes, true ) ) {
			return true;
		}

		// 通配符匹配（如 discovery/* 匹配 discovery/fields）
		$pattern = str_replace( array( '*', '/' ), array( '.*', '\\/' ), $full_route );
		foreach ( $routes as $registered_route ) {
			if ( preg_match( '/^' . $pattern . '$/', $registered_route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 检查命名空间是否存在
	 *
	 * @param string $namespace 命名空间
	 * @return bool
	 */
	protected function namespace_exists( $namespace ) {
		// 使用缓存的命名空间列表（避免重复调用 get_namespaces）
		if ( null === self::$cached_namespaces ) {
			return false;
		}

		return in_array( $namespace, self::$cached_namespaces, true );
	}

	/**
	 * 跳过测试如果路由不存在
	 *
	 * @param string $route     路由路径
	 * @param string $namespace 命名空间
	 * @param string $message   跳过消息
	 */
	protected function skip_if_route_missing( $route, $namespace = 'wptsall/v2', $message = '' ) {
		if ( ! $this->route_exists( $route, $namespace ) ) {
			$msg = $message ?: "REST 路由不存在: /{$namespace}/{$route}";
			$this->markTestSkipped( $msg );
		}
	}

	/**
	 * 跳过测试如果命名空间不存在
	 *
	 * @param string $namespace 命名空间
	 * @param string $message   跳过消息
	 */
	protected function skip_if_namespace_missing( $namespace, $message = '' ) {
		if ( ! $this->namespace_exists( $namespace ) ) {
			$msg = $message ?: "REST 命名空间不存在: {$namespace}";
			$this->markTestSkipped( $msg );
		}
	}

	// ========================================
	// REST 断言方法
	// ========================================

	/**
	 * 断言 REST 响应成功
	 *
	 * @param WP_REST_Response $response        响应对象
	 * @param int              $expected_status 期望的 HTTP 状态码
	 * @param string           $message         失败消息
	 */
	protected function assertRestSuccess( $response, $expected_status = 200, $message = '' ) {
		$actual_status = $response->get_status();
		$data = $response->get_data();

		$debug_message = $message ?: sprintf(
			'REST 请求应成功 (期望状态 %d，实际 %d)。响应数据: %s',
			$expected_status,
			$actual_status,
			wp_json_encode( $data, JSON_UNESCAPED_UNICODE )
		);

		$this->assertEquals( $expected_status, $actual_status, $debug_message );
	}

	/**
	 * 断言 REST 响应错误
	 *
	 * @param WP_REST_Response $response        响应对象
	 * @param string           $error_code      期望的错误代码
	 * @param int              $expected_status 期望的 HTTP 状态码
	 * @param string           $message         失败消息
	 */
	protected function assertRestError( $response, $error_code = '', $expected_status = 400, $message = '' ) {
		$actual_status = $response->get_status();
		$data = $response->get_data();

		$debug_message = $message ?: sprintf(
			'REST 请求应返回错误 (期望状态 %d，实际 %d)',
			$expected_status,
			$actual_status
		);

		$this->assertEquals( $expected_status, $actual_status, $debug_message );

		// Accept both WP_Error `code` and product envelope `error`.
		if ( $error_code ) {
			$actual_code = '';
			if ( is_array( $data ) ) {
				if ( isset( $data['code'] ) ) {
					$actual_code = (string) $data['code'];
				} elseif ( isset( $data['error'] ) ) {
					$actual_code = (string) $data['error'];
				}
			}
			$aliases = array( $error_code );
			// Historical tests used `not_found`; Controllers now prefer
			// WordPress REST convention `rest_object_not_found`.
			if ( 'not_found' === $error_code ) {
				$aliases[] = 'rest_object_not_found';
			} elseif ( 'rest_object_not_found' === $error_code ) {
				$aliases[] = 'not_found';
			}
			$this->assertTrue(
				in_array( $actual_code, $aliases, true ),
				sprintf(
					'错误代码不匹配 (expected one of [%s], got %s)',
					implode( ', ', $aliases ),
					$actual_code !== '' ? $actual_code : wp_json_encode( $data )
				)
			);
		}
	}

	/**
	 * 断言响应数据包含指定键
	 *
	 * @param WP_REST_Response $response 响应对象
	 * @param string           $key      键名
	 * @param string           $message  失败消息
	 */
	protected function assertRestDataHasKey( $response, $key, $message = '' ) {
		$data = $response->get_data();
		$this->assertArrayHasKey( $key, $data, $message ?: "响应应包含键 '{$key}'" );
	}

	/**
	 * 断言响应数据字段值
	 *
	 * @param WP_REST_Response $response 响应对象
	 * @param string           $key      键名
	 * @param mixed            $expected 期望值
	 * @param string           $message  失败消息
	 */
	protected function assertRestDataEquals( $response, $key, $expected, $message = '' ) {
		$data = $response->get_data();
		$this->assertArrayHasKey( $key, $data, "响应应包含键 '{$key}'" );
		$this->assertEquals( $expected, $data[ $key ], $message ?: "响应字段 '{$key}' 值不匹配" );
	}

	/**
	 * 断言字符串包含子串
	 *
	 * @param string $needle   要查找的子串
	 * @param string $haystack 被搜索的字符串
	 * @param string $message  失败消息
	 */
	public function assertStringContainsString( $needle, $haystack, $message = '' ) {
		$message = $message ?: "字符串应包含 '{$needle}'";
		$this->assertTrue( strpos( $haystack, $needle ) !== false, $message );
	}

	/**
	 * 断言字符串不包含子串
	 *
	 * @param string $needle   要查找的子串
	 * @param string $haystack 被搜索的字符串
	 * @param string $message  失败消息
	 */
	public function assertStringNotContainsString( $needle, $haystack, $message = '' ) {
		$message = $message ?: "字符串不应包含 '{$needle}'";
		$this->assertTrue( strpos( $haystack, $needle ) === false, $message );
	}

	/**
	 * 断言值小于或等于
	 *
	 * @param mixed  $expected 期望的最大值
	 * @param mixed  $actual   实际值
	 * @param string $message  失败消息
	 */
	public function assertLessThanOrEqual( $expected, $actual, $message = '' ) {
		$message = $message ?: "值 {$actual} 应小于或等于 {$expected}";
		$this->assertTrue( $actual <= $expected, $message );
	}

	// ========================================
	// 测试数据创建方法（自动跟踪清理）
	// ========================================

	/**
	 * 创建或获取管理员用户
	 *
	 * @return int 用户 ID
	 */
	protected function create_admin_user() {
		// 首先尝试获取现有的测试管理员
		$existing_user = get_user_by( 'email', 'wptsall_test_admin@example.com' );
		if ( $existing_user ) {
			return $existing_user->ID;
		}

		// 如果不存在，创建新用户
		$unique_id = substr( md5( uniqid( '', true ) ), 0, 8 );
		$user_id = wp_insert_user( array(
			'user_login' => 'wptsall_test_admin',
			'user_pass'  => wp_generate_password(),
			'user_email' => 'wptsall_test_admin@example.com',
			'role'       => 'administrator',
		) );

		// 如果用户名已存在，尝试带唯一 ID 的用户名
		if ( is_wp_error( $user_id ) ) {
			$user_id = wp_insert_user( array(
				'user_login' => 'test_admin_' . $unique_id,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'test_admin_' . $unique_id . '@example.com',
				'role'       => 'administrator',
			) );
		}

		if ( is_wp_error( $user_id ) ) {
			throw new \Exception( '创建管理员用户失败: ' . $user_id->get_error_message() );
		}

		return $user_id;
	}

	/**
	 * 创建测试文章
	 *
	 * @param array $args 文章参数
	 * @return int 文章 ID
	 */
	protected function create_test_post( $args = array() ) {
		$fire_after_hooks = true;
		if ( array_key_exists( 'fire_after_hooks', $args ) ) {
			$fire_after_hooks = (bool) $args['fire_after_hooks'];
			unset( $args['fire_after_hooks'] );
		}

		$defaults = array(
			'post_title'   => 'Test Post ' . wp_rand( 1000, 9999 ),
			'post_content' => 'Test content for integration testing.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $this->admin_user_id,
		);

		$post_args = wp_parse_args( $args, $defaults );
		$post_id = wp_insert_post( $post_args, false, $fire_after_hooks );

		if ( is_wp_error( $post_id ) ) {
			throw new \Exception( '创建测试文章失败: ' . $post_id->get_error_message() );
		}

		$this->track_resource( 'posts', $post_id );

		return $post_id;
	}

	/**
	 * 创建测试虚拟站点
	 *
	 * @param array $args 虚拟站点参数
	 * @return int|false 虚拟站点 ID 或 false
	 */
	protected function create_test_virtual_site( $args = array() ) {
		$unique_suffix = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
		$defaults = array(
			'name'        => 'Test Virtual Site ' . wp_rand( 1000, 9999 ),
			'path_prefix' => 'test-vs-' . $unique_suffix,
			'lang'        => 'zh_CN',
		);

		$explicit_path_prefix = ! empty( $args['path_prefix'] ) && is_string( $args['path_prefix'] );
		$max_attempts         = $explicit_path_prefix ? 1 : 5;
		$last_error           = array();

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$site_args = wp_parse_args( $args, $defaults );
			if ( ! $explicit_path_prefix ) {
				$retry_suffix              = substr( md5( uniqid( (string) wp_rand(), true ) . '|' . $attempt ), 0, 16 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
				$site_args['path_prefix']  = 'test-vs-' . $retry_suffix;
			}

			// 使用 REST API 创建
			$response = $this->rest_post( 'virtual-sites', $site_args );
			if ( $response->get_status() === 200 ) {
				$data    = $response->get_data();
				$site_id = $data['site_id'] ?? false;

				if ( $site_id ) {
					$this->track_resource( 'virtual_sites', $site_id );
				}

				return $site_id;
			}

			$last_error = (array) $response->get_data();
			$errors     = (array) ( $last_error['data']['errors'] ?? array() );
			$conflicted = in_array( 'URL 路径前缀已存在', $errors, true );
			if ( ! $conflicted ) {
				break;
			}
		}

		throw new \Exception( '创建虚拟站点失败: ' . wp_json_encode( $last_error, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * 创建测试站点关系
	 *
	 * @param int    $virtual_site_id 虚拟站点 ID
	 * @param string $template        模板标识
	 * @param array  $extra_args      额外参数
	 * @return array|false 关系数据或 false
	 */
	protected function create_test_relation( $virtual_site_id, $template = 'wordpress-blog', $extra_args = array() ) {
		$source_lang = get_option( 'WPLANG', 'en_US' ) ?: 'en_US';

		// 获取虚拟站点信息以获取语言
		$vs_response = $this->rest_get( 'virtual-sites/' . $virtual_site_id );
		$vs_data = $vs_response->get_data();
		$target_lang = $vs_data['lang'] ?? 'zh_CN';

		$args = array_merge( array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $source_lang,
			'template'       => $template,
			'target_sites'   => array(
				array(
					'id'   => $virtual_site_id,
					'type' => 'virtual',
					'lang' => $target_lang,
				),
			),
		), $extra_args );

		$response = $this->rest_post( 'site-relations', $args );

		if ( $response->get_status() !== 200 ) {
			$data = $response->get_data();
			throw new \Exception( '创建站点关系失败: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
		}

		$data = $response->get_data();
		$relation_ids = $data['relation_ids'] ?? array();

		foreach ( $relation_ids as $id ) {
			$this->track_resource( 'site_relations', $id );
		}

		return $data;
	}

	/**
	 * 创建测试任务
	 *
	 * @param int $relation_id 关系 ID
	 * @return array|false 任务数据或 false
	 */
	protected function create_test_monitoring_task( $relation_id ) {
		$response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $relation_id,
		) );

		if ( $response->get_status() !== 200 ) {
			return false;
		}

		$data = $response->get_data();
		$task_id = $data['task_id'] ?? false;

		if ( $task_id ) {
			$this->track_resource( 'tasks', $task_id );
		}

		return $data;
	}

	// ========================================
	// 资源跟踪与清理
	// ========================================

	/**
	 * 跟踪资源以便清理
	 *
	 * @param string $type 资源类型
	 * @param int    $id   资源 ID
	 */
	protected function track_resource( $type, $id ) {
		if ( ! isset( $this->tracked_resources[ $type ] ) ) {
			$this->tracked_resources[ $type ] = array();
		}

		$this->tracked_resources[ $type ][] = $id;
	}

	/**
	 * 清理所有跟踪的资源
	 */
	protected function cleanup_resources() {
		// 按依赖顺序删除：先删除有依赖关系的资源

		// 1. 删除任务
		foreach ( $this->tracked_resources['tasks'] as $id ) {
			$this->rest_delete( 'tasks/' . $id );
		}

		// 2. 删除站点关系
		foreach ( $this->tracked_resources['site_relations'] as $id ) {
			$this->rest_delete( 'site-relations/' . $id );
		}

		// 3. 删除虚拟站点
		foreach ( $this->tracked_resources['virtual_sites'] as $id ) {
			$this->rest_delete( 'virtual-sites/' . $id );
		}

		// 4. 删除文章
		foreach ( $this->tracked_resources['posts'] as $id ) {
			wp_delete_post( $id, true );
		}

		// 5. 删除模板
		foreach ( $this->tracked_resources['templates'] as $id ) {
			if ( class_exists( 'WPTSALL\Templates\Services\Template_Service' ) ) {
				\WPTSALL\Templates\Services\Template_Service::delete( $id );
			}
		}

		// 6. 删除翻译规则
		foreach ( $this->tracked_resources['translation_rules'] as $id ) {
			if ( class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
				\WPTSALL\Models\Services\Translation_Rule_Service::delete_rule( $id );
			}
		}

		// 重置跟踪
		$this->tracked_resources = array(
			'posts'           => array(),
			'virtual_sites'   => array(),
			'site_relations'  => array(),
			'tasks'           => array(),
			'templates'       => array(),
			'translation_rules' => array(),
		);
	}

	// ========================================
	// 辅助方法
	// ========================================

	/**
	 * 获取当前站点语言
	 *
	 * @return string 语言代码
	 */
	protected function get_source_lang() {
		$lang = get_option( 'WPLANG', 'en_US' );
		return $lang ?: 'en_US';
	}

	/**
	 * 等待异步操作完成（模拟）
	 *
	 * @param int $microseconds 等待时间（微秒）
	 */
	protected function wait_for_async( $microseconds = 100000 ) {
		usleep( $microseconds );
	}

	/**
	 * 获取响应数据
	 *
	 * @param WP_REST_Response $response 响应对象
	 * @return array 响应数据
	 */
	protected function get_response_data( $response ) {
		return $response->get_data();
	}

	/**
	 * 调试输出响应
	 *
	 * @param WP_REST_Response $response 响应对象
	 * @param string           $label    标签
	 */
	protected function debug_response( $response, $label = 'Response' ) {
		$data = $response->get_data();
		error_log( sprintf(
			"[%s] Status: %d, Data: %s",
			$label,
			$response->get_status(),
			wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
		) );
	}

	// ========================================
	// 依赖检查方法
	// ========================================

	/**
	 * 检查类是否存在
	 *
	 * @param string $class_name 完整类名
	 * @param string $message    跳过消息
	 * @return bool
	 */
	protected function require_class( $class_name, $message = '' ) {
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $message ?: "{$class_name} 类不存在" );
			return false;
		}
		return true;
	}

	/**
	 * 检查函数是否存在
	 *
	 * @param string $function_name 函数名
	 * @param string $message       跳过消息
	 * @return bool
	 */
	protected function require_function( $function_name, $message = '' ) {
		if ( ! function_exists( $function_name ) ) {
			$this->markTestSkipped( $message ?: "{$function_name} 函数不存在" );
			return false;
		}
		return true;
	}

	/**
	 * 检查多个类是否存在（任一存在即可）
	 *
	 * @param array  $class_names 类名数组
	 * @param string $message     跳过消息
	 * @return string|false 返回存在的类名或 false
	 */
	protected function require_any_class( $class_names, $message = '' ) {
		foreach ( $class_names as $class_name ) {
			if ( class_exists( $class_name ) ) {
				return $class_name;
			}
		}
		$this->markTestSkipped( $message ?: '所需类均不存在: ' . implode( ', ', $class_names ) );
		return false;
	}

	/**
	 * 检查多个函数是否存在（任一存在即可）
	 *
	 * @param array  $function_names 函数名数组
	 * @param string $message        跳过消息
	 * @return string|false 返回存在的函数名或 false
	 */
	protected function require_any_function( $function_names, $message = '' ) {
		foreach ( $function_names as $function_name ) {
			if ( function_exists( $function_name ) ) {
				return $function_name;
			}
		}
		$this->markTestSkipped( $message ?: '所需函数均不存在: ' . implode( ', ', $function_names ) );
		return false;
	}

	/**
	 * 检查 REST 端点是否可用
	 *
	 * @param string $endpoint   端点路径
	 * @param string $namespace  命名空间
	 * @param string $message    跳过消息
	 * @return bool
	 */
	protected function require_rest_endpoint( $endpoint, $namespace = 'wptsall/v2', $message = '' ) {
		$response = $this->rest_get( $endpoint, array(), $namespace );
		$status = $response->get_status();

		// 404 表示端点不存在
		if ( 404 === $status ) {
			$this->markTestSkipped( $message ?: "REST 端点 {$namespace}/{$endpoint} 不存在" );
			return false;
		}
		return true;
	}

	/**
	 * 检查数据库表是否存在
	 *
	 * @param string $table_name 表名（不含前缀）
	 * @param string $message    跳过消息
	 * @return bool
	 */
	protected function require_table( $table_name, $message = '' ) {
		global $wpdb;

		if ( ! function_exists( 'wptsall_table' ) ) {
			$this->markTestSkipped( 'wptsall_table 函数不存在' );
			return false;
		}

		$table = wptsall_table( $table_name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $table !== $table_exists ) {
			$this->markTestSkipped( $message ?: "{$table_name} 表不存在" );
			return false;
		}
		return true;
	}

	/**
	 * 检查类方法是否存在
	 *
	 * @param string $class_name  类名
	 * @param string $method_name 方法名
	 * @param string $message     跳过消息
	 * @return bool
	 */
	protected function require_method( $class_name, $method_name, $message = '' ) {
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $message ?: "{$class_name} 类不存在" );
			return false;
		}

		$reflection = new \ReflectionClass( $class_name );
		if ( ! $reflection->hasMethod( $method_name ) ) {
			$this->markTestSkipped( $message ?: "{$class_name}::{$method_name} 方法不存在" );
			return false;
		}
		return true;
	}
}
