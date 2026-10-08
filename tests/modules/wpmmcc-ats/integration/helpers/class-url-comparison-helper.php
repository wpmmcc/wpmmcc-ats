<?php
/**
 * URL Comparison Helper
 *
 * 提供 URL 内容比较的通用工具类
 *
 * @package WPTSALL
 * @since 0.3.0
 */

class WPTSALL_URL_Comparison_Helper {

	/**
	 * 使用 curl 获取 URL 内容
	 *
	 * @param string $url URL 地址
	 * @param array  $options 可选参数
	 * @return array 包含 success, http_code, error, content 的数组
	 */
	public static function fetch_url( $url, $options = array() ) {
		$defaults = array(
			'timeout'         => 30,
			'follow_redirect' => true,
			'user_agent'      => 'WPTSALL-Test-Bot/1.0',
			'verify_ssl'      => false,
			'headers'         => array(),
		);

		$options = wp_parse_args( $options, $defaults );

		$ch = curl_init();

		$curl_options = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => $options['follow_redirect'],
			CURLOPT_TIMEOUT        => $options['timeout'],
			CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'],
			CURLOPT_USERAGENT      => $options['user_agent'],
			CURLOPT_HEADER         => true,
		);

		if ( ! empty( $options['headers'] ) ) {
			$curl_options[ CURLOPT_HTTPHEADER ] = $options['headers'];
		}

		curl_setopt_array( $ch, $curl_options );

		$response      = curl_exec( $ch );
		$http_code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size   = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$error         = curl_error( $ch );
		$error_code    = curl_errno( $ch );
		$redirect_url  = curl_getinfo( $ch, CURLINFO_REDIRECT_URL );
		$total_time    = curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
		$content_type  = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );

		curl_close( $ch );

		if ( $error ) {
			return array(
				'success'      => false,
				'http_code'    => $http_code,
				'error'        => $error,
				'error_code'   => $error_code,
				'content'      => '',
				'headers'      => '',
				'total_time'   => $total_time,
				'content_type' => $content_type,
			);
		}

		$headers = substr( $response, 0, $header_size );
		$body    = substr( $response, $header_size );

		return array(
			'success'      => $http_code === 200,
			'http_code'    => $http_code,
			'error'        => '',
			'error_code'   => 0,
			'content'      => $body,
			'headers'      => $headers,
			'total_time'   => $total_time,
			'content_type' => $content_type,
			'redirect_url' => $redirect_url,
		);
	}

	/**
	 * 从 HTML 中提取标题
	 *
	 * @param string $html HTML 内容
	 * @return string 标题
	 */
	public static function extract_title( $html ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $matches ) ) {
			return trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * 从 HTML 中提取 meta description
	 *
	 * @param string $html HTML 内容
	 * @return string Meta description
	 */
	public static function extract_meta_description( $html ) {
		if ( preg_match( '/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/is', $html, $matches ) ) {
			return trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * 从 HTML 中提取 Open Graph 标题
	 *
	 * @param string $html HTML 内容
	 * @return string OG 标题
	 */
	public static function extract_og_title( $html ) {
		if ( preg_match( '/<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']/is', $html, $matches ) ) {
			return trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * 从 HTML 中提取主要内容
	 *
	 * @param string $html HTML 内容
	 * @return string 主要内容
	 */
	public static function extract_main_content( $html ) {
		// 尝试提取 article 标签内容
		if ( preg_match( '/<article[^>]*>(.*?)<\/article>/is', $html, $matches ) ) {
			return self::strip_html_tags( $matches[1] );
		}

		// 尝试提取 main 标签内容
		if ( preg_match( '/<main[^>]*>(.*?)<\/main>/is', $html, $matches ) ) {
			return self::strip_html_tags( $matches[1] );
		}

		// 尝试提取 .entry-content 类
		if ( preg_match( '/<div[^>]*class=["\'][^"\']*entry-content[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
			return self::strip_html_tags( $matches[1] );
		}

		// 尝试提取 .post-content 类
		if ( preg_match( '/<div[^>]*class=["\'][^"\']*post-content[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
			return self::strip_html_tags( $matches[1] );
		}

		return '';
	}

	/**
	 * 清理 HTML 标签
	 *
	 * @param string $html HTML 内容
	 * @return string 纯文本内容
	 */
	public static function strip_html_tags( $html ) {
		// 移除 script 和 style 标签
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

		// 移除 HTML 标签
		$text = wp_strip_all_tags( $html );

		// 规范化空白字符
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	/**
	 * 比较两个 HTML 内容
	 *
	 * @param string $source_html 源 HTML
	 * @param string $target_html 目标 HTML
	 * @param array  $options 比较选项
	 * @return array 比较结果
	 */
	public static function compare_html( $source_html, $target_html, $options = array() ) {
		$defaults = array(
			'compare_title'            => true,
			'compare_meta_description' => true,
			'compare_og_title'         => true,
			'compare_content'          => true,
			'strict_match'             => false,
			'similarity_threshold'     => 0.8,
		);

		$options = wp_parse_args( $options, $defaults );

		$comparisons = array();
		$all_match   = true;

		// 比较标题
		if ( $options['compare_title'] ) {
			$source_title = self::extract_title( $source_html );
			$target_title = self::extract_title( $target_html );

			$title_match = $options['strict_match']
				? $source_title === $target_title
				: self::text_similarity( $source_title, $target_title ) >= $options['similarity_threshold'];

			$comparisons['title'] = array(
				'match'      => $title_match,
				'source'     => $source_title,
				'target'     => $target_title,
				'similarity' => self::text_similarity( $source_title, $target_title ),
			);

			if ( ! $title_match ) {
				$all_match = false;
			}
		}

		// 比较 meta description
		if ( $options['compare_meta_description'] ) {
			$source_meta = self::extract_meta_description( $source_html );
			$target_meta = self::extract_meta_description( $target_html );

			if ( $source_meta || $target_meta ) {
				$meta_match = $options['strict_match']
					? $source_meta === $target_meta
					: self::text_similarity( $source_meta, $target_meta ) >= $options['similarity_threshold'];

				$comparisons['meta_description'] = array(
					'match'      => $meta_match,
					'source'     => $source_meta,
					'target'     => $target_meta,
					'similarity' => self::text_similarity( $source_meta, $target_meta ),
				);

				if ( ! $meta_match ) {
					$all_match = false;
				}
			}
		}

		// 比较 OG 标题
		if ( $options['compare_og_title'] ) {
			$source_og = self::extract_og_title( $source_html );
			$target_og = self::extract_og_title( $target_html );

			if ( $source_og || $target_og ) {
				$og_match = $options['strict_match']
					? $source_og === $target_og
					: self::text_similarity( $source_og, $target_og ) >= $options['similarity_threshold'];

				$comparisons['og_title'] = array(
					'match'      => $og_match,
					'source'     => $source_og,
					'target'     => $target_og,
					'similarity' => self::text_similarity( $source_og, $target_og ),
				);

				if ( ! $og_match ) {
					$all_match = false;
				}
			}
		}

		// 比较主要内容
		if ( $options['compare_content'] ) {
			$source_content = self::extract_main_content( $source_html );
			$target_content = self::extract_main_content( $target_html );

			$content_match = $options['strict_match']
				? $source_content === $target_content
				: self::text_similarity( $source_content, $target_content ) >= $options['similarity_threshold'];

			$comparisons['content'] = array(
				'match'        => $content_match,
				'source_chars' => mb_strlen( $source_content ),
				'target_chars' => mb_strlen( $target_content ),
				'similarity'   => self::text_similarity( $source_content, $target_content ),
			);

			if ( ! $content_match ) {
				$all_match = false;
			}
		}

		return array(
			'all_match'    => $all_match,
			'comparisons'  => $comparisons,
		);
	}

	/**
	 * 计算两个文本的相似度
	 *
	 * @param string $text1 文本1
	 * @param string $text2 文本2
	 * @return float 相似度 (0-1)
	 */
	public static function text_similarity( $text1, $text2 ) {
		if ( empty( $text1 ) && empty( $text2 ) ) {
			return 1.0;
		}

		if ( empty( $text1 ) || empty( $text2 ) ) {
			return 0.0;
		}

		// 使用 Levenshtein 距离算法
		$max_len = max( mb_strlen( $text1 ), mb_strlen( $text2 ) );

		// 如果字符串太长，使用相似文本百分比
		if ( $max_len > 255 ) {
			similar_text( $text1, $text2, $percent );
			return $percent / 100;
		}

		$distance = levenshtein( $text1, $text2 );

		return 1 - ( $distance / $max_len );
	}

	/**
	 * 检查 URL 是否可访问
	 *
	 * @param string $url URL 地址
	 * @param int    $timeout 超时时间（秒）
	 * @return array 包含 accessible 和 http_code 的数组
	 */
	public static function is_url_accessible( $url, $timeout = 10 ) {
		$ch = curl_init();

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_NOBODY         => true,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_SSL_VERIFYPEER => false,
			)
		);

		curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error     = curl_error( $ch );

		curl_close( $ch );

		return array(
			'accessible' => $http_code === 200,
			'http_code'  => $http_code,
			'error'      => $error,
		);
	}

	/**
	 * 批量检查 URL
	 *
	 * @param array $urls URL 数组
	 * @return array 检查结果
	 */
	public static function batch_check_urls( $urls ) {
		$results = array();

		foreach ( $urls as $key => $url ) {
			$results[ $key ] = self::is_url_accessible( $url );

			// 避免过快请求
			usleep( 100000 ); // 0.1 秒
		}

		return $results;
	}

	/**
	 * 生成 URL 比较报告
	 *
	 * @param array $comparisons 比较结果数组
	 * @return string Markdown 格式的报告
	 */
	public static function generate_comparison_report( $comparisons ) {
		$report = "# URL 比较测试报告\n\n";
		$report .= sprintf( "生成时间: %s\n\n", current_time( 'Y-m-d H:i:s' ) );

		$total   = count( $comparisons );
		$passed  = 0;
		$failed  = 0;
		$warning = 0;

		foreach ( $comparisons as $comparison ) {
			switch ( $comparison['status'] ) {
				case 'success':
				case 'pass':
					++$passed;
					break;
				case 'warning':
					++$warning;
					break;
				case 'error':
				case 'fail':
					++$failed;
					break;
			}
		}

		$report .= "## 总体统计\n\n";
		$report .= sprintf( "- 总测试数: %d\n", $total );
		$report .= sprintf( "- 通过: %d (%.1f%%)\n", $passed, $total > 0 ? ( $passed / $total * 100 ) : 0 );
		$report .= sprintf( "- 警告: %d (%.1f%%)\n", $warning, $total > 0 ? ( $warning / $total * 100 ) : 0 );
		$report .= sprintf( "- 失败: %d (%.1f%%)\n\n", $failed, $total > 0 ? ( $failed / $total * 100 ) : 0 );

		$report .= "## 详细结果\n\n";
		$report .= "| # | URL | 状态 | 消息 |\n";
		$report .= "|---|-----|------|------|\n";

		foreach ( $comparisons as $index => $comparison ) {
			$status_icon = '❌';
			if ( in_array( $comparison['status'], array( 'success', 'pass' ), true ) ) {
				$status_icon = '✅';
			} elseif ( $comparison['status'] === 'warning' ) {
				$status_icon = '⚠️';
			}

			$report .= sprintf(
				"| %d | %s | %s %s | %s |\n",
				$index + 1,
				$comparison['url'] ?? 'N/A',
				$status_icon,
				$comparison['status'],
				$comparison['message'] ?? ''
			);
		}

		return $report;
	}
}
