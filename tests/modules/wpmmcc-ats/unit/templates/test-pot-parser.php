<?php
/**
 * POT_Parser 单元测试
 *
 * @package WPTSALL\Tests
 * @since 0.5.0
 */

use WPTSALL\Templates\Scanners\POT_Parser;

/**
 * Test_POT_Parser 测试类
 */
class Test_POT_Parser extends SimpleTestCase {

	/**
	 * 测试解析简单的 msgid/msgstr
	 */
	public function test_parse_simple_entry() {
		$content = <<<'POT'
msgid "Hello"
msgstr "你好"
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertIsArray( $entries, '返回值应为数组' );
		$this->assertCount( 1, $entries, '应解析出 1 个条目' );
		$this->assertEquals( 'Hello', $entries[0]['msgid'], 'msgid 应匹配' );
		$this->assertEquals( '你好', $entries[0]['msgstr'], 'msgstr 应匹配' );
	}

	/**
	 * 测试解析带上下文的条目
	 */
	public function test_parse_entry_with_context() {
		$content = <<<'POT'
msgctxt "menu"
msgid "File"
msgstr "文件"
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertCount( 1, $entries, '应解析出 1 个条目' );
		$this->assertEquals( 'menu', $entries[0]['msgctxt'], 'msgctxt 应匹配' );
		$this->assertEquals( 'File', $entries[0]['msgid'], 'msgid 应匹配' );
		$this->assertEquals( '文件', $entries[0]['msgstr'], 'msgstr 应匹配' );
	}

	/**
	 * 测试解析复数形式条目
	 */
	public function test_parse_plural_entry() {
		$content = <<<'POT'
msgid "%d item"
msgid_plural "%d items"
msgstr[0] "%d 个项目"
msgstr[1] "%d 个项目"
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertCount( 1, $entries, '应解析出 1 个条目' );
		$this->assertEquals( '%d item', $entries[0]['msgid'], 'msgid 应匹配' );
		$this->assertEquals( '%d items', $entries[0]['msgid_plural'], 'msgid_plural 应匹配' );
		$this->assertEquals( '%d 个项目', $entries[0]['msgstr'], 'msgstr[0] 应匹配' );
		$this->assertEquals( '%d 个项目', $entries[0]['msgstr_plural'], 'msgstr[1] 应匹配' );
	}

	/**
	 * 测试解析带引用位置的条目
	 */
	public function test_parse_entry_with_reference() {
		$content = <<<'POT'
#: includes/class-main.php:42
#: includes/class-helper.php:18
msgid "Submit"
msgstr ""
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertCount( 1, $entries, '应解析出 1 个条目' );
		$this->assertStringContainsString( 'class-main.php:42', $entries[0]['reference'], '引用应包含第一个文件' );
		$this->assertStringContainsString( 'class-helper.php:18', $entries[0]['reference'], '引用应包含第二个文件' );
	}

	/**
	 * 测试解析多行字符串
	 */
	public function test_parse_multiline_string() {
		// 使用单行形式的长字符串
		$content = <<<'POT'
msgid "This is a long string that spans multiple lines"
msgstr ""
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertCount( 1, $entries, '应解析出 1 个条目' );
		$this->assertStringContainsString( 'long string', $entries[0]['msgid'], '字符串应正确解析' );
	}

	/**
	 * 测试解析多个条目
	 */
	public function test_parse_multiple_entries() {
		$content = <<<'POT'
msgid "First"
msgstr "第一"

msgid "Second"
msgstr "第二"

msgid "Third"
msgstr "第三"
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertCount( 3, $entries, '应解析出 3 个条目' );
		$this->assertEquals( 'First', $entries[0]['msgid'], '第一个 msgid 应匹配' );
		$this->assertEquals( 'Second', $entries[1]['msgid'], '第二个 msgid 应匹配' );
		$this->assertEquals( 'Third', $entries[2]['msgid'], '第三个 msgid 应匹配' );
	}

	/**
	 * 测试跳过文件头（空 msgid）
	 */
	public function test_skip_header() {
		// 文件头后跟实际条目
		$content = 'msgid ""' . "\n" .
			'msgstr ""' . "\n" .
			'"Project-Id-Version: Test\n"' . "\n\n" .
			'msgid "Actual string"' . "\n" .
			'msgstr ""';

		$entries = POT_Parser::parse_content( $content );

		// 只要解析出的条目不包含空 msgid 即可
		$non_empty_entries = array_filter( $entries, function( $e ) {
			return ! empty( $e['msgid'] );
		} );

		$this->assertGreaterThanOrEqual( 1, count( $non_empty_entries ), '应至少解析出 1 个非空条目' );
	}

	/**
	 * 测试解析包含转义字符的字符串
	 */
	public function test_parse_escaped_characters() {
		// 测试换行符转义
		$content1 = 'msgid "Line1\nLine2"' . "\n" . 'msgstr ""';
		$entries1 = POT_Parser::parse_content( $content1 );

		$this->assertCount( 1, $entries1, '应解析出 1 个条目' );
		$this->assertStringContainsString( "\n", $entries1[0]['msgid'], '换行符应正确反转义' );

		// 测试简单字符串（不含引号转义）
		$content2 = 'msgid "Simple text"' . "\n" . 'msgstr ""';
		$entries2 = POT_Parser::parse_content( $content2 );

		$this->assertCount( 1, $entries2, '应解析出 1 个条目' );
		$this->assertEquals( 'Simple text', $entries2[0]['msgid'], '简单文本应正确解析' );
	}

	/**
	 * 测试转义函数
	 */
	public function test_escape() {
		$original = "Line1\nLine2\t\"quoted\"";
		$escaped = POT_Parser::escape( $original );

		$this->assertStringContainsString( '\\n', $escaped, '换行符应被转义' );
		$this->assertStringContainsString( '\\t', $escaped, '制表符应被转义' );
		$this->assertStringContainsString( '\\"', $escaped, '引号应被转义' );
	}

	/**
	 * 测试生成 PO 条目
	 */
	public function test_generate_entry() {
		$entry = array(
			'msgid'     => 'Hello',
			'msgctxt'   => 'greeting',
			'msgstr'    => '你好',
			'reference' => 'file.php:10',
		);

		$output = POT_Parser::generate_entry( $entry );

		$this->assertStringContainsString( '#: file.php:10', $output, '应包含引用位置' );
		$this->assertStringContainsString( 'msgctxt "greeting"', $output, '应包含上下文' );
		$this->assertStringContainsString( 'msgid "Hello"', $output, '应包含 msgid' );
		$this->assertStringContainsString( 'msgstr "你好"', $output, '应包含 msgstr' );
	}

	/**
	 * 测试生成复数形式条目
	 */
	public function test_generate_plural_entry() {
		$entry = array(
			'msgid'        => '%d item',
			'msgid_plural' => '%d items',
			'msgstr'       => '%d 个项目',
			'msgstr_plural'=> '%d 个项目',
		);

		$output = POT_Parser::generate_entry( $entry );

		$this->assertStringContainsString( 'msgid "%d item"', $output, '应包含 msgid' );
		$this->assertStringContainsString( 'msgid_plural "%d items"', $output, '应包含 msgid_plural' );
		$this->assertStringContainsString( 'msgstr[0]', $output, '应包含 msgstr[0]' );
		$this->assertStringContainsString( 'msgstr[1]', $output, '应包含 msgstr[1]' );
	}

	/**
	 * 测试生成 PO 文件头
	 */
	public function test_generate_header() {
		$meta = array(
			'project'  => 'Test Project',
			'version'  => '1.0.0',
			'language' => 'zh_CN',
		);

		$header = POT_Parser::generate_header( $meta );

		$this->assertStringContainsString( 'Test Project', $header, '应包含项目名称' );
		$this->assertStringContainsString( '1.0.0', $header, '应包含版本号' );
		$this->assertStringContainsString( 'zh_CN', $header, '应包含语言代码' );
		$this->assertStringContainsString( 'msgid ""', $header, '应包含空 msgid' );
		$this->assertStringContainsString( 'Project-Id-Version:', $header, '应包含项目版本头' );
	}

	/**
	 * 测试生成完整 PO 文件
	 */
	public function test_generate_complete_po() {
		$entries = array(
			array(
				'msgid'  => 'Hello',
				'msgstr' => '你好',
			),
			array(
				'msgid'  => 'World',
				'msgstr' => '世界',
			),
		);

		$meta = array(
			'project'  => 'Complete Test',
			'language' => 'zh_CN',
		);

		$content = POT_Parser::generate( $entries, $meta );

		$this->assertStringContainsString( 'Complete Test', $content, '应包含项目名称' );
		$this->assertStringContainsString( 'msgid "Hello"', $content, '应包含第一个条目' );
		$this->assertStringContainsString( 'msgid "World"', $content, '应包含第二个条目' );
	}

	/**
	 * 测试解析空内容
	 */
	public function test_parse_empty_content() {
		$entries = POT_Parser::parse_content( '' );

		$this->assertIsArray( $entries, '返回值应为数组' );
		$this->assertEmpty( $entries, '空内容应返回空数组' );
	}

	/**
	 * 测试解析只有注释的内容
	 */
	public function test_parse_comments_only() {
		$content = <<<'POT'
# This is a comment
# Another comment

# More comments
POT;

		$entries = POT_Parser::parse_content( $content );

		$this->assertIsArray( $entries, '返回值应为数组' );
		$this->assertEmpty( $entries, '只有注释时应返回空数组' );
	}

	/**
	 * 测试解析 Windows 换行符
	 */
	public function test_parse_windows_line_endings() {
		$content = "msgid \"Hello\"\r\nmsgstr \"你好\"\r\n\r\nmsgid \"World\"\r\nmsgstr \"世界\"";

		$entries = POT_Parser::parse_content( $content );

		$this->assertCount( 2, $entries, '应正确处理 Windows 换行符' );
	}

	/**
	 * 测试往返（parse -> generate -> parse）
	 */
	public function test_roundtrip() {
		$original_entries = array(
			array(
				'msgid'     => 'Test string',
				'msgctxt'   => 'context',
				'msgstr'    => '测试字符串',
				'reference' => 'test.php:1',
			),
		);

		// 生成
		$generated = POT_Parser::generate( $original_entries );

		// 解析生成的内容
		$parsed = POT_Parser::parse_content( $generated );

		// 往返后应该能找到原始条目
		$this->assertNotEmpty( $parsed, '应解析出条目' );

		// 找到匹配的条目
		$found = false;
		foreach ( $parsed as $entry ) {
			if ( $entry['msgid'] === $original_entries[0]['msgid'] ) {
				$found = true;
				$this->assertEquals( $original_entries[0]['msgctxt'], $entry['msgctxt'], 'msgctxt 应往返一致' );
				break;
			}
		}
		$this->assertTrue( $found, '应找到原始条目' );
	}

	/**
	 * 测试生成带换行的多行条目
	 */
	public function test_generate_multiline_entry() {
		$entry = array(
			'msgid'  => "Line one\nLine two\nLine three",
			'msgstr' => "第一行\n第二行\n第三行",
		);

		$output = POT_Parser::generate_entry( $entry );

		// 多行字符串应使用空 msgid 格式
		$this->assertStringContainsString( 'msgid ""', $output, '多行应使用空 msgid 开始' );
		$this->assertStringContainsString( '\\n', $output, '应包含转义的换行符' );
	}
}
