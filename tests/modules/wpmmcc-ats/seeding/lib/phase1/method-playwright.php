<?php
/**
 * Phase 1 Method: Playwright Automation
 *
 * 使用 Playwright 自动化后台操作导入数据（如 LearnPress）
 *
 * @package WPTSALL\DevTools\Seeding\Phase1
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 执行 playwright 方式的数据导入
 *
 * @param array  $source    数据源配置
 * @param string $base_path 基础路径
 * @return bool 是否成功
 */
function seed_phase1_method_playwright( $source, $base_path ) {
    $script = $base_path . '/' . ( $source['script'] ?? '' );

    if ( ! file_exists( $script ) ) {
        seed_log( "Playwright 脚本不存在: $script", 'error' );
        return false;
    }

    seed_log( "Playwright 脚本: $script" );

    // 检查 npx 是否可用
    $npx_check = shell_exec( 'which npx 2>/dev/null' );
    if ( empty( $npx_check ) ) {
        seed_log( 'npx 命令不可用，请安装 Node.js', 'error' );
        return false;
    }

    // 检查 playwright 是否安装
    $script_dir = dirname( $script );
    $package_json = $script_dir . '/package.json';

    if ( ! file_exists( $package_json ) ) {
        seed_log( "提示: Playwright 脚本目录需要 package.json", 'warning' );
        seed_log( "  cd $script_dir && npm init -y && npm install @playwright/test", 'warning' );
    } else {
        $playwright_module = $script_dir . '/node_modules/@playwright/test';
        $package_lock      = $script_dir . '/package-lock.json';

        if ( ! is_dir( $playwright_module ) ) {
            $install_command = file_exists( $package_lock ) ? 'npm ci' : 'npm install';
            seed_log( "Playwright 依赖缺失，执行: cd $script_dir && $install_command", 'warning' );

            $install_output = array();
            $install_code   = 0;
            $original_dir   = getcwd();
            chdir( $script_dir );
            exec( $install_command . ' 2>&1', $install_output, $install_code );
            chdir( $original_dir );

            if ( $install_code !== 0 ) {
                seed_log( "Playwright 依赖安装失败 (exit code: $install_code)", 'error' );
                foreach ( array_slice( $install_output, -20 ) as $line ) {
                    seed_log( "  $line", 'error' );
                }
                return false;
            }
        }
    }

    // 构建命令
    $command_template = $source['command'] ?? 'npx playwright test {script}';
    $script_arg = basename( $script );
    $command = str_replace( '{script}', escapeshellarg( $script_arg ), $command_template );
    $env_prefix = seed_phase1_playwright_env_prefix();
    if ( $env_prefix === '' ) {
        return false;
    }

    seed_log( "执行: $command" );
    seed_log( "注意: Playwright 执行可能需要较长时间，请耐心等待...", 'warning' );

    // 执行命令（在脚本目录下）
    $original_dir = getcwd();
    chdir( $script_dir );

    $output = array();
    $return_code = 0;
    exec( $env_prefix . $command . ' 2>&1', $output, $return_code );

    chdir( $original_dir );

    // 输出执行结果
    foreach ( $output as $line ) {
        if ( preg_match( '/(passed|failed|error|success)/i', $line ) ) {
            seed_log( "  $line" );
        }
    }

    if ( $return_code === 0 ) {
        seed_log( "Playwright 执行成功" );
        return true;
    } else {
        seed_log( "Playwright 执行失败 (exit code: $return_code)", 'error' );
        foreach ( array_slice( $output, -20 ) as $line ) {
            seed_log( "  $line", 'error' );
        }
        return false;
    }
}

/**
 * Build Playwright environment variables for WordPress admin automation.
 *
 * If explicit WP_USER/WP_PASS are provided, keep them. Otherwise create or
 * rotate a dedicated ephemeral test administrator and pass its credentials only
 * to the child Playwright process.
 *
 * @return string Shell-safe env prefix.
 */
function seed_phase1_playwright_env_prefix() {
    $user = getenv( 'WP_USER' );
    $pass = getenv( 'WP_PASS' );

    if ( empty( $user ) || empty( $pass ) ) {
        $user = getenv( 'WPTSALL_SEED_WP_USER' );
        $pass = getenv( 'WPTSALL_SEED_WP_PASS' );
    }

    if ( empty( $user ) || empty( $pass ) ) {
        $user = 'wptsall_seed_admin';
        $pass = wp_generate_password( 24, false, false );
        $email = 'wptsall_seed_admin@wptsall.test';
        $wp_user = get_user_by( 'login', $user );

        if ( $wp_user ) {
            wp_set_password( $pass, (int) $wp_user->ID );
            wp_update_user(
                array(
                    'ID'        => (int) $wp_user->ID,
                    'user_email' => $email,
                )
            );
            $user_id = (int) $wp_user->ID;
        } else {
            $user_id = wp_insert_user(
                array(
                    'user_login' => $user,
                    'user_pass'  => $pass,
                    'user_email' => $email,
                    'role'       => 'administrator',
                )
            );

            if ( is_wp_error( $user_id ) ) {
                seed_log( '无法创建 Playwright 测试管理员: ' . $user_id->get_error_message(), 'error' );
                return '';
            }
        }

        $user_obj = new WP_User( (int) $user_id );
        $user_obj->set_role( 'administrator' );

        if ( is_multisite() ) {
            add_user_to_blog( get_current_blog_id(), (int) $user_id, 'administrator' );
        }

        seed_log( "Playwright 使用临时测试管理员: $user" );
    }

    $base_url = getenv( 'WPTSALL_SEED_WP_BASE_URL' );
    if ( empty( $base_url ) ) {
        $base_url = home_url( '/' );
    }

    $env = array(
        'WP_USER'                   => $user,
        'WP_PASS'                   => $pass,
        'WPTSALL_SEED_WP_BASE_URL'  => $base_url,
    );

    $parts = array();
    foreach ( $env as $key => $value ) {
        $parts[] = $key . '=' . escapeshellarg( (string) $value );
    }

    return implode( ' ', $parts ) . ' ';
}

/**
 * 检查 Playwright 环境
 *
 * @param string $script_dir 脚本目录
 * @return array 检查结果
 */
function seed_phase1_check_playwright_env( $script_dir ) {
    $result = array(
        'node'       => false,
        'npm'        => false,
        'npx'        => false,
        'playwright' => false,
        'browsers'   => false,
    );

    // 检查 Node.js
    $node_version = shell_exec( 'node --version 2>/dev/null' );
    $result['node'] = ! empty( $node_version );

    // 检查 npm
    $npm_version = shell_exec( 'npm --version 2>/dev/null' );
    $result['npm'] = ! empty( $npm_version );

    // 检查 npx
    $npx_path = shell_exec( 'which npx 2>/dev/null' );
    $result['npx'] = ! empty( $npx_path );

    // 检查 playwright 是否安装
    $package_lock = $script_dir . '/package-lock.json';
    if ( file_exists( $package_lock ) ) {
        $lock_content = file_get_contents( $package_lock );
        $result['playwright'] = strpos( $lock_content, '@playwright/test' ) !== false;
    }

    // 检查浏览器是否安装（简化检查）
    $result['browsers'] = is_dir( getenv( 'HOME' ) . '/.cache/ms-playwright' );

    return $result;
}

/**
 * 生成 Playwright 环境安装指南
 *
 * @param string $script_dir 脚本目录
 * @return string 安装指南
 */
function seed_phase1_playwright_setup_guide( $script_dir ) {
    return <<<GUIDE
Playwright 环境安装指南：

1. 进入脚本目录：
   cd $script_dir

2. 初始化 npm 项目（如果没有 package.json）：
   npm init -y

3. 安装 Playwright：
   npm install @playwright/test

4. 安装浏览器：
   npx playwright install

5. 运行测试：
   npx playwright test
GUIDE;
}
