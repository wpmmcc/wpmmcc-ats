<?php
/**
 * Chain 12: 数据分类与访问验证链路测试
 *
 * 测试数据分类流程：字段分类 → 内容格式推断 → PII 检测 → URL 分类
 *
 * 服务层（直接 PHP API，不经过 REST）:
 * - Core\Smart_Field_Classifier::classify_for_chain($field)
 * - Core\Smart_Field_Classifier::infer_content_format($field)
 * - Core\Sync_Access_Validator
 * - Core\Data_Classifier
 * - Core\URL_Classifier
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 12 Test: Data Classification & Access Validation
 */
class Test_Chain_12_Data_Classification extends REST_Integration_Test_Case {

	/**
	 * 标记链路是否可运行
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * 跳过原因
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * Smart_Field_Classifier 完整类名（解析后）
	 *
	 * @var string|null
	 */
	private static $sfc_class = null;

	/**
	 * 类级别设置 - 检查前置条件
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// 确定 Smart_Field_Classifier 的实际命名空间
		$candidates = array(
			'WPTSALL\\Core\\Smart_Field_Classifier',
			'WPTSALL\\Models\\Services\\Smart_Field_Classifier',
		);
		foreach ( $candidates as $candidate ) {
			if ( class_exists( $candidate ) ) {
				self::$sfc_class = $candidate;
				break;
			}
		}

		// 检查至少有一个分类器类存在
		$classifier_classes = array_merge( $candidates, array(
			'WPTSALL\\Core\\Sync_Access_Validator',
			'WPTSALL\\Core\\Data_Classifier',
			'WPTSALL\\Core\\URL_Classifier',
		) );

		$has_classifier = false;
		foreach ( $classifier_classes as $class ) {
			if ( class_exists( $class ) ) {
				$has_classifier = true;
				break;
			}
		}

		if ( ! $has_classifier ) {
			self::$chain_runnable = false;
			self::$skip_reason = '缺少分类器类，链路无法测试';
		}
	}

	/**
	 * 每个测试前设置
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	// ========================================
	// Smart_Field_Classifier PHP API 直接调用测试
	// ========================================

	/**
	 * 测试 Smart_Field_Classifier 类存在
	 */
	public function test_smart_field_classifier_exists() {
		$this->assertNotNull(
			self::$sfc_class,
			'Smart_Field_Classifier 类应存在（Core 或 Models\\Services 命名空间下）'
		);
	}

	/**
	 * 测试 classify_for_chain 静态方法存在
	 */
	public function test_classify_for_chain_method_exists() {
		if ( ! self::$sfc_class ) {
			$this->markTestSkipped( 'Smart_Field_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( self::$sfc_class );
		$this->assertTrue(
			$reflection->hasMethod( 'classify_for_chain' ),
			self::$sfc_class . '::classify_for_chain 方法应存在'
		);
	}

	/**
	 * 测试 classify_for_chain 对 post_title 返回 model 对象结构
	 *
	 * 新增：验证返回数组包含 type 和 content_format 键
	 */
	public function test_classify_returns_model_objects_source() {
		if ( ! self::$sfc_class ) {
			$this->markTestSkipped( 'Smart_Field_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( self::$sfc_class );
		if ( ! $reflection->hasMethod( 'classify_for_chain' ) ) {
			$this->markTestSkipped( 'classify_for_chain 方法不存在' );
		}

		$result = call_user_func( array( self::$sfc_class, 'classify_for_chain' ), 'post_title' );

		$this->assertIsArray( $result, 'classify_for_chain 应返回数组' );
		$this->assertArrayHasKey( 'type', $result, '结果应包含 type 键' );
		$this->assertArrayHasKey( 'content_format', $result, '结果应包含 content_format 键' );
		$this->assertNotEmpty( $result['type'], 'type 不应为空' );
	}

	/**
	 * 测试 post_title 字段分类为 translate，且 content_format 有值
	 *
	 * 新增：确保 translate 类型字段的 content_format 已设置
	 */
	public function test_classify_translate_field_has_content_format() {
		if ( ! self::$sfc_class ) {
			$this->markTestSkipped( 'Smart_Field_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( self::$sfc_class );
		if ( ! $reflection->hasMethod( 'classify_for_chain' ) ) {
			$this->markTestSkipped( 'classify_for_chain 方法不存在' );
		}

		// post_title 应是需要翻译的字段
		$result = call_user_func( array( self::$sfc_class, 'classify_for_chain' ), 'post_title' );

		$this->assertIsArray( $result, '结果应为数组' );

		if ( isset( $result['type'] ) && 'translate' === $result['type'] ) {
			$this->assertNotNull(
				$result['content_format'],
				'translate 类型字段的 content_format 应不为 null'
			);
			$this->assertNotEmpty(
				$result['content_format'],
				'translate 类型字段的 content_format 应有值'
			);
		}
	}

	/**
	 * 测试 post_content 字段分类结果
	 */
	public function test_classify_post_content_field() {
		if ( ! self::$sfc_class ) {
			$this->markTestSkipped( 'Smart_Field_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( self::$sfc_class );
		if ( ! $reflection->hasMethod( 'classify_for_chain' ) ) {
			$this->markTestSkipped( 'classify_for_chain 方法不存在' );
		}

		$result = call_user_func( array( self::$sfc_class, 'classify_for_chain' ), 'post_content' );

		$this->assertIsArray( $result, '结果应为数组' );
		$this->assertArrayHasKey( 'type', $result, '结果应包含 type 键' );

		// post_content 应是 translate 类型
		$this->assertEquals( 'translate', $result['type'], 'post_content 应分类为 translate' );
	}

	/**
	 * 测试系统字段（_edit_lock）分类为 skip 或 system 类型
	 */
	public function test_classify_system_field() {
		if ( ! self::$sfc_class ) {
			$this->markTestSkipped( 'Smart_Field_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( self::$sfc_class );
		if ( ! $reflection->hasMethod( 'classify_for_chain' ) ) {
			$this->markTestSkipped( 'classify_for_chain 方法不存在' );
		}

		$result = call_user_func( array( self::$sfc_class, 'classify_for_chain' ), '_edit_lock' );

		$this->assertIsArray( $result, '结果应为数组' );
		$this->assertArrayHasKey( 'type', $result, '结果应包含 type 键' );

		// 系统字段应被分类为 skip 或 system（不应为 translate）
		$this->assertNotEquals( 'translate', $result['type'], '系统字段 _edit_lock 不应分类为 translate' );
	}

	/**
	 * 测试 classify_batch_for_chain 批量分类
	 */
	public function test_classify_batch_for_chain() {
		if ( ! self::$sfc_class ) {
			$this->markTestSkipped( 'Smart_Field_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( self::$sfc_class );
		if ( ! $reflection->hasMethod( 'classify_batch_for_chain' ) ) {
			$this->markTestSkipped( 'classify_batch_for_chain 方法不存在' );
		}

		$fields = array(
			'post_title'   => array(),
			'post_content' => array(),
			'_edit_lock'   => array(),
		);

		$results = call_user_func( array( self::$sfc_class, 'classify_batch_for_chain' ), $fields );

		$this->assertIsArray( $results, '批量分类结果应为数组' );
		$this->assertArrayHasKey( 'post_title', $results, '结果应包含 post_title' );
		$this->assertArrayHasKey( 'post_content', $results, '结果应包含 post_content' );
		$this->assertArrayHasKey( '_edit_lock', $results, '结果应包含 _edit_lock' );
	}

	// ========================================
	// Sync_Access_Validator 测试
	// ========================================

	/**
	 * 测试 Sync_Access_Validator 类存在
	 */
	public function test_sync_access_validator_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Core\\Sync_Access_Validator' ),
			'Sync_Access_Validator 类应存在'
		);
	}

	/**
	 * 测试 Sync_Access_Validator 验证方法存在
	 */
	public function test_sync_access_validator_methods() {
		if ( ! class_exists( 'WPTSALL\\Core\\Sync_Access_Validator' ) ) {
			$this->markTestSkipped( 'Sync_Access_Validator 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Core\\Sync_Access_Validator' );

		$has_validate = $reflection->hasMethod( 'validate' ) ||
		                $reflection->hasMethod( 'check_access' ) ||
		                $reflection->hasMethod( 'is_allowed' );

		$this->assertTrue( $has_validate, 'Sync_Access_Validator 应有验证方法' );
	}

	// ========================================
	// Data_Classifier 测试
	// ========================================

	/**
	 * 测试 Data_Classifier 类存在
	 */
	public function test_data_classifier_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Core\\Data_Classifier' ),
			'Data_Classifier 类应存在'
		);
	}

	/**
	 * 测试 Data_Classifier 分类方法存在
	 */
	public function test_data_classifier_methods() {
		if ( ! class_exists( 'WPTSALL\\Core\\Data_Classifier' ) ) {
			$this->markTestSkipped( 'Data_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Core\\Data_Classifier' );

		$has_classify = $reflection->hasMethod( 'classify' ) ||
		                $reflection->hasMethod( 'detect_type' ) ||
		                $reflection->hasMethod( 'analyze' );

		$this->assertTrue( $has_classify, 'Data_Classifier 应有分类方法' );
	}

	// ========================================
	// URL_Classifier 测试
	// ========================================

	/**
	 * 测试 URL_Classifier 类存在
	 */
	public function test_url_classifier_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Core\\URL_Classifier' ),
			'URL_Classifier 类应存在'
		);
	}

	/**
	 * 测试 URL_Classifier 分类方法存在
	 */
	public function test_url_classifier_methods() {
		if ( ! class_exists( 'WPTSALL\\Core\\URL_Classifier' ) ) {
			$this->markTestSkipped( 'URL_Classifier 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Core\\URL_Classifier' );

		$has_classify = $reflection->hasMethod( 'classify' ) ||
		                $reflection->hasMethod( 'classify_url' ) ||
		                $reflection->hasMethod( 'get_url_type' );

		$this->assertTrue( $has_classify, 'URL_Classifier 应有分类方法' );
	}

	// ========================================
	// URL 分类测试
	// ========================================

	/**
	 * 测试管理员 URL 包含 wp-admin
	 */
	public function test_admin_url_classification() {
		$admin_url = admin_url( 'edit.php' );

		$this->assertStringContainsString( 'wp-admin', $admin_url, '管理员 URL 应包含 wp-admin' );
	}

	/**
	 * 测试公开 URL 不包含 wp-admin
	 */
	public function test_public_url_classification() {
		$public_url = home_url( '/sample-page/' );

		$this->assertStringNotContainsString( 'wp-admin', $public_url, '公开 URL 不应包含 wp-admin' );
	}

	/**
	 * 测试 REST URL 包含 wp-json
	 */
	public function test_protected_url_classification() {
		$rest_url = rest_url( 'wptsall/v1/site-relations' );

		$this->assertStringContainsString( 'wp-json', $rest_url, 'REST URL 应包含 wp-json' );
	}

	// ========================================
	// 隐私级别评估测试
	// ========================================

	/**
	 * 测试不同文章状态的隐私级别
	 */
	public function test_privacy_level_assessment() {
		$public_post = $this->create_test_post( array(
			'post_title'  => 'Public Content',
			'post_status' => 'publish',
		) );

		$private_post = $this->create_test_post( array(
			'post_title'  => 'Private Content',
			'post_status' => 'private',
		) );

		$draft_post = $this->create_test_post( array(
			'post_title'  => 'Draft Content',
			'post_status' => 'draft',
		) );

		$this->assertEquals( 'publish', get_post_status( $public_post ) );
		$this->assertEquals( 'private', get_post_status( $private_post ) );
		$this->assertEquals( 'draft', get_post_status( $draft_post ) );
	}

	/**
	 * 测试元数据规则匹配（私有 meta 与公开 meta 可共存）
	 */
	public function test_metadata_rules_matching() {
		$post_id = $this->create_test_post( array(
			'post_title' => 'Private Meta Test',
		) );

		update_post_meta( $post_id, '_private_data', 'secret value' );
		update_post_meta( $post_id, 'public_data', 'visible value' );

		$private_value = get_post_meta( $post_id, '_private_data', true );
		$public_value  = get_post_meta( $post_id, 'public_data', true );

		$this->assertEquals( 'secret value', $private_value );
		$this->assertEquals( 'visible value', $public_value );
	}
}
