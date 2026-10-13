<?php
/**
 * Log Splitter - WordPress 日志分离器
 *
 * 拦截 PHP 错误，按来源分流到不同日志文件：
 * - WordPress 核心 → debug.log
 * - 各插件 → logs/plugins/{plugin-slug}.log
 * - 主题 → logs/themes/{theme-slug}.log
 * - Deprecated 警告 → logs/deprecated.log（可选单独分离）
 *
 * @package LogSplitter
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 日志分离器类
 */
class WP_Log_Splitter {

    /**
     * 日志基础目录
     */
    private $log_dir;

    /**
     * 核心日志文件路径
     */
    private $core_log;

    /**
     * 是否将 Deprecated 警告单独分离
     */
    private $separate_deprecated = true;

    /**
     * 日志级别映射
     */
    private $error_types = array(
        E_ERROR             => 'Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
        // E_STRICT (2048) 在 PHP 8.4 中已废弃，使用数字值
        2048                => 'Strict',
    );

    /**
     * 构造函数
     */
    public function __construct() {
        $this->log_dir  = WP_CONTENT_DIR . '/logs';
        $this->core_log = WP_CONTENT_DIR . '/debug.log';

        // 确保目录存在
        $this->ensure_directories();

        // 注册错误处理器
        set_error_handler( array( $this, 'handle_error' ) );

        // 注册异常处理器
        set_exception_handler( array( $this, 'handle_exception' ) );

        // 注册 shutdown 处理器（捕获致命错误）
        register_shutdown_function( array( $this, 'handle_shutdown' ) );
    }

    /**
     * 确保日志目录存在
     */
    private function ensure_directories() {
        $dirs = array(
            $this->log_dir,
            $this->log_dir . '/plugins',
            $this->log_dir . '/themes',
        );

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
        }
    }

    /**
     * 错误处理器
     *
     * @param int    $errno   错误级别
     * @param string $errstr  错误消息
     * @param string $errfile 错误文件
     * @param int    $errline 错误行号
     * @return bool
     */
    public function handle_error( $errno, $errstr, $errfile, $errline ) {
        // 检查是否应该报告此错误
        if ( ! ( error_reporting() & $errno ) ) {
            return false;
        }

        // 格式化日志消息
        $type    = isset( $this->error_types[ $errno ] ) ? $this->error_types[ $errno ] : 'Unknown';
        $message = $this->format_message( $type, $errstr, $errfile, $errline );

        // 确定日志文件
        $log_file = $this->get_log_file( $errfile, $errno, $errstr );

        // 写入日志
        $this->write_log( $log_file, $message );

        // 返回 true 阻止 PHP 默认错误处理
        // 对于致命错误，返回 false 让 PHP 继续处理
        if ( in_array( $errno, array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
            return false;
        }

        return true;
    }

    /**
     * 异常处理器
     *
     * @param Throwable $exception 异常对象
     */
    public function handle_exception( $exception ) {
        $message = $this->format_message(
            'Exception',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        $log_file = $this->get_log_file( $exception->getFile(), E_ERROR, $exception->getMessage() );
        $this->write_log( $log_file, $message );
    }

    /**
     * Shutdown 处理器（捕获致命错误）
     */
    public function handle_shutdown() {
        $error = error_get_last();

        if ( $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
            $type    = isset( $this->error_types[ $error['type'] ] ) ? $this->error_types[ $error['type'] ] : 'Fatal Error';
            $message = $this->format_message( $type, $error['message'], $error['file'], $error['line'] );

            $log_file = $this->get_log_file( $error['file'], $error['type'], $error['message'] );
            $this->write_log( $log_file, $message );
        }
    }

    /**
     * 确定日志文件路径
     *
     * @param string $file    错误文件路径
     * @param int    $errno   错误级别
     * @param string $errstr  错误消息
     * @return string
     */
    private function get_log_file( $file, $errno, $errstr ) {
        // Deprecated 警告单独分离
        if ( $this->separate_deprecated && in_array( $errno, array( E_DEPRECATED, E_USER_DEPRECATED ), true ) ) {
            return $this->log_dir . '/deprecated.log';
        }

        // 检查是否来自插件
        if ( strpos( $file, '/plugins/' ) !== false ) {
            if ( preg_match( '#/plugins/([^/]+)/#', $file, $matches ) ) {
                $plugin_slug = sanitize_file_name( $matches[1] );
                return $this->log_dir . '/plugins/' . $plugin_slug . '.log';
            }
        }

        // 检查是否来自主题
        if ( strpos( $file, '/themes/' ) !== false ) {
            if ( preg_match( '#/themes/([^/]+)/#', $file, $matches ) ) {
                $theme_slug = sanitize_file_name( $matches[1] );
                return $this->log_dir . '/themes/' . $theme_slug . '.log';
            }
        }

        // 检查错误消息中是否包含插件信息（有些错误的 file 是核心但实际来自插件）
        if ( preg_match( '/plugins\/([^\/]+)\//', $errstr, $matches ) ) {
            $plugin_slug = sanitize_file_name( $matches[1] );
            return $this->log_dir . '/plugins/' . $plugin_slug . '.log';
        }

        // 默认写入核心日志
        return $this->core_log;
    }

    /**
     * 格式化日志消息
     *
     * @param string $type    错误类型
     * @param string $message 错误消息
     * @param string $file    错误文件
     * @param int    $line    错误行号
     * @param string $trace   堆栈跟踪（可选）
     * @return string
     */
    private function format_message( $type, $message, $file, $line, $trace = '' ) {
        $timestamp = gmdate( 'd-M-Y H:i:s' ) . ' UTC';
        $formatted = "[{$timestamp}] PHP {$type}: {$message} in {$file} on line {$line}";

        if ( ! empty( $trace ) ) {
            $formatted .= "\nStack trace:\n{$trace}";
        }

        return $formatted;
    }

    /**
     * 写入日志
     *
     * @param string $file    日志文件路径
     * @param string $message 日志消息
     */
    private function write_log( $file, $message ) {
        // 确保目录存在
        $dir = dirname( $file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // 追加写入
        error_log( $message . "\n", 3, $file );
    }
}

// 仅在启用 WP_DEBUG_LOG 时激活
if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
    new WP_Log_Splitter();
}
