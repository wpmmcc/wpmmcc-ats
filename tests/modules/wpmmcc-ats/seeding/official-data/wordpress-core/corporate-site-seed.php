<?php
/**
 * WordPress Core - 企业官网数据填充
 *
 * 创建完整的企业官网示例数据，覆盖 WordPress 核心字段：
 * - 页面 (page)
 * - 文章 (post)
 * - 分类 (category)
 * - 标签 (post_tag)
 * - 评论 (comment)
 * - 导航菜单 (nav_menu)
 * - 特色图片 (_thumbnail_id)
 * - 摘要 (post_excerpt)
 *
 * 执行: wp eval-file corporate-site-seed.php
 *
 * @package WPTSALL\DevTools\Seeding
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WordPress 企业官网数据填充（完整版）                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

$created = array(
    'pages'      => 0,
    'posts'      => 0,
    'categories' => 0,
    'tags'       => 0,
    'comments'   => 0,
    'menus'      => 0,
);

// =============================================================
// 1. 创建分类
// =============================================================
echo "Step 1: 创建分类...\n";

$categories = array(
    array(
        'name'        => '公司新闻',
        'slug'        => 'company-news',
        'description' => '公司最新动态、公告和活动',
    ),
    array(
        'name'        => '行业动态',
        'slug'        => 'industry-news',
        'description' => '行业趋势、市场分析和技术前沿',
    ),
    array(
        'name'        => '产品更新',
        'slug'        => 'product-updates',
        'description' => '产品发布、功能更新和版本说明',
    ),
    array(
        'name'        => '技术分享',
        'slug'        => 'tech-sharing',
        'description' => '技术文章、最佳实践和开发经验',
    ),
);

$category_ids = array();
foreach ( $categories as $cat ) {
    $existing = get_term_by( 'slug', $cat['slug'], 'category' );
    if ( $existing ) {
        $category_ids[ $cat['slug'] ] = $existing->term_id;
        echo "  - 分类已存在: {$cat['name']}\n";
    } else {
        $term = wp_insert_term( $cat['name'], 'category', array(
            'slug'        => $cat['slug'],
            'description' => $cat['description'],
        ) );
        if ( ! is_wp_error( $term ) ) {
            $category_ids[ $cat['slug'] ] = $term['term_id'];
            $created['categories']++;
            echo "  ✓ 创建分类: {$cat['name']}\n";
        }
    }
}

// =============================================================
// 2. 创建标签
// =============================================================
echo "\nStep 2: 创建标签...\n";

$tags = array(
    array( 'name' => '数字化转型', 'slug' => 'digital-transformation' ),
    array( 'name' => '人工智能', 'slug' => 'ai' ),
    array( 'name' => '云计算', 'slug' => 'cloud-computing' ),
    array( 'name' => '企业服务', 'slug' => 'enterprise-service' ),
    array( 'name' => '技术创新', 'slug' => 'tech-innovation' ),
    array( 'name' => '产品发布', 'slug' => 'product-launch' ),
    array( 'name' => '获奖荣誉', 'slug' => 'awards' ),
    array( 'name' => '合作伙伴', 'slug' => 'partnership' ),
    array( 'name' => '开发者', 'slug' => 'developer' ),
    array( 'name' => '最佳实践', 'slug' => 'best-practices' ),
);

$tag_ids = array();
foreach ( $tags as $tag ) {
    $existing = get_term_by( 'slug', $tag['slug'], 'post_tag' );
    if ( $existing ) {
        $tag_ids[ $tag['slug'] ] = $existing->term_id;
        echo "  - 标签已存在: {$tag['name']}\n";
    } else {
        $term = wp_insert_term( $tag['name'], 'post_tag', array( 'slug' => $tag['slug'] ) );
        if ( ! is_wp_error( $term ) ) {
            $tag_ids[ $tag['slug'] ] = $term['term_id'];
            $created['tags']++;
            echo "  ✓ 创建标签: {$tag['name']}\n";
        }
    }
}

// =============================================================
// 3. 创建页面
// =============================================================
echo "\nStep 3: 创建页面...\n";

$pages = array(
    array(
        'title'   => '首页',
        'slug'    => 'home',
        'excerpt' => '欢迎访问我们的企业官网，了解我们的产品和服务。',
        'content' => '<!-- wp:cover {"dimRatio":50,"minHeight":400,"align":"full"} -->
<div class="wp-block-cover alignfull" style="min-height:400px">
<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>
<div class="wp-block-cover__inner-container">
<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">欢迎来到我们的公司</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">我们致力于为客户提供优质的产品和服务，推动企业数字化转型</p>
<!-- /wp:paragraph -->
</div>
</div>
<!-- /wp:cover -->

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">我们的优势</h2>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">专业团队</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>拥有多年行业经验的专业团队，为您提供高质量的服务。</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">优质服务</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>7x24小时客户支持，随时响应您的需求。</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">创新技术</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>持续创新，采用最新技术为您的业务赋能。</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->',
    ),
    array(
        'title'   => '服务项目',
        'slug'    => 'services',
        'excerpt' => '我们提供网站开发、移动应用、系统集成和技术咨询等全方位服务。',
        'content' => '<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">我们的服务</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>我们提供全方位的解决方案，满足您的业务需求。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">网站开发</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>从企业官网到电商平台，我们提供定制化的网站开发服务。响应式设计，兼容各种设备。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">移动应用</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>iOS 和 Android 原生应用开发，以及跨平台解决方案。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">系统集成</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>帮助企业整合现有系统，提高运营效率，降低管理成本。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">技术咨询</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>提供专业的技术咨询服务，帮助您做出正确的技术决策。</p>
<!-- /wp:paragraph -->',
    ),
    array(
        'title'   => '关于我们',
        'slug'    => 'about',
        'excerpt' => '了解我们的公司历史、使命愿景和核心价值观。',
        'content' => '<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">关于我们</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>我们是一家专注于数字化转型的科技公司，成立于2015年。多年来，我们帮助众多企业实现了业务的数字化升级。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">我们的使命</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>用技术创新推动企业发展，让每一家企业都能享受数字化带来的便利。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">我们的愿景</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>成为企业数字化转型的首选合作伙伴。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">核心价值观</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list">
<li>客户至上：客户的成功就是我们的成功</li>
<li>持续创新：拥抱变化，不断进步</li>
<li>团队协作：众人拾柴火焰高</li>
<li>诚信正直：言行一致，值得信赖</li>
</ul>
<!-- /wp:list -->',
    ),
    array(
        'title'   => '联系我们',
        'slug'    => 'contact',
        'excerpt' => '联系我们获取更多信息或合作咨询。',
        'content' => '<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">联系我们</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>如有任何问题或合作意向，欢迎随时与我们联系。</p>
<!-- /wp:paragraph -->

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">办公地址</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>北京市朝阳区建国路88号<br>SOHO现代城A座2001室</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">联系方式</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>电话：010-12345678<br>邮箱：contact@example.com</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">工作时间</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>周一至周五：9:00 - 18:00<br>周末：休息</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->',
    ),
    array(
        'title'   => '新闻中心',
        'slug'    => 'news',
        'excerpt' => '了解公司最新动态、行业资讯和产品更新。',
        'content' => '<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">新闻中心</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>了解我们的最新动态、行业资讯和产品更新。</p>
<!-- /wp:paragraph -->

<!-- wp:latest-posts {"postsToShow":10,"displayPostContent":true,"displayPostDate":true,"displayFeaturedImage":true} /-->',
    ),
);

$page_ids = array();
foreach ( $pages as $page ) {
    $existing = get_page_by_path( $page['slug'] );
    if ( $existing ) {
        $page_ids[ $page['slug'] ] = $existing->ID;
        echo "  - 页面已存在: {$page['title']}\n";
    } else {
        $page_id = wp_insert_post( array(
            'post_title'   => $page['title'],
            'post_name'    => $page['slug'],
            'post_excerpt' => $page['excerpt'],
            'post_content' => $page['content'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            $page_ids[ $page['slug'] ] = $page_id;
            $created['pages']++;
            echo "  ✓ 创建页面: {$page['title']} (ID: $page_id)\n";
        }
    }
}

// 设置首页为静态首页
if ( isset( $page_ids['home'] ) ) {
    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', $page_ids['home'] );
    echo "  ✓ 设置首页为静态页面\n";
}

// 设置新闻页为文章页
if ( isset( $page_ids['news'] ) ) {
    update_option( 'page_for_posts', $page_ids['news'] );
    echo "  ✓ 设置新闻中心为文章页\n";
}

// =============================================================
// 4. 创建文章
// =============================================================
echo "\nStep 4: 创建文章...\n";

$posts = array(
    // 公司新闻
    array(
        'title'    => '公司荣获2025年度创新企业奖',
        'slug'     => 'innovation-award-2025',
        'excerpt'  => '在近日举办的科技创新大会上，我公司凭借卓越的技术创新能力和优秀的产品，荣获"2025年度创新企业"荣誉称号。',
        'content'  => '<!-- wp:paragraph -->
<p>在近日举办的科技创新大会上，我公司凭借卓越的技术创新能力和优秀的产品，荣获"2025年度创新企业"荣誉称号。</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>本次评选由行业协会主办，经过严格的评审流程，从全国数百家参评企业中脱颖而出。这一荣誉是对我们团队过去一年努力的肯定，也是对我们未来发展的激励。</p>
<!-- /wp:paragraph -->

<!-- wp:quote -->
<blockquote class="wp-block-quote">
<p>感谢评委会的认可，更要感谢团队每一位成员的辛勤付出。我们将继续秉持创新精神，为客户创造更大价值。</p>
<cite>—— 公司CEO</cite>
</blockquote>
<!-- /wp:quote -->

<!-- wp:paragraph -->
<p>未来，我们将继续加大研发投入，推动技术创新，为客户提供更优质的产品和服务。</p>
<!-- /wp:paragraph -->',
        'category' => 'company-news',
        'tags'     => array( 'awards', 'tech-innovation' ),
    ),
    array(
        'title'    => '公司与行业领先企业达成战略合作',
        'slug'     => 'strategic-partnership-announcement',
        'excerpt'  => '我公司与多家行业领先企业签署战略合作协议，将在技术研发、市场拓展等领域展开深度合作。',
        'content'  => '<!-- wp:paragraph -->
<p>近日，我公司与多家行业领先企业签署战略合作协议，将在技术研发、市场拓展、资源共享等领域展开深度合作。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">合作内容</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li>联合技术研发，共同攻克行业难题</li>
<li>资源共享，实现优势互补</li>
<li>市场协同，拓展业务版图</li>
<li>人才交流，提升团队能力</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>此次战略合作的达成，标志着我公司在行业生态建设方面迈出了重要一步。</p>
<!-- /wp:paragraph -->',
        'category' => 'company-news',
        'tags'     => array( 'partnership', 'enterprise-service' ),
    ),
    array(
        'title'    => '公司成功举办年度技术峰会',
        'slug'     => 'annual-tech-summit-2025',
        'excerpt'  => '2025年度技术峰会圆满落幕，来自全国各地的500多位技术专家和企业代表参与了本次盛会。',
        'content'  => '<!-- wp:paragraph -->
<p>2025年度技术峰会于上周在北京国际会议中心圆满落幕。来自全国各地的500多位技术专家、企业代表和行业伙伴参与了本次盛会。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">峰会亮点</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>本次峰会以"智能未来，共创价值"为主题，涵盖了人工智能、云计算、大数据等多个热门话题。</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<li>主题演讲：行业大咖分享前沿洞察</li>
<li>圆桌论坛：深度探讨技术趋势</li>
<li>产品展示：最新解决方案亮相</li>
<li>交流晚宴：建立行业连接</li>
</ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>感谢所有参与者的支持，期待明年再会！</p>
<!-- /wp:paragraph -->',
        'category' => 'company-news',
        'tags'     => array( 'tech-innovation', 'ai', 'cloud-computing' ),
    ),

    // 产品更新
    array(
        'title'    => '新版产品 3.0 正式发布',
        'slug'     => 'product-3-0-release',
        'excerpt'  => '经过数月的精心打磨，我们的新版产品 3.0 今日正式发布，带来全新的用户界面和更强大的功能。',
        'content'  => '<!-- wp:paragraph -->
<p>经过数月的精心打磨，我们的新版产品 3.0 今日正式发布。新版本带来了全新的用户界面和更强大的功能。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">主要更新</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>全新界面</strong>：更加简洁直观的用户体验</li>
<li><strong>性能优化</strong>：响应速度提升 50%</li>
<li><strong>AI 助手</strong>：智能推荐和自动化功能</li>
<li><strong>安全增强</strong>：多层防护机制</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">升级说明</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>老用户可以直接在后台升级，新用户欢迎注册体验。如有任何问题，请联系我们的客服团队。</p>
<!-- /wp:paragraph -->',
        'category' => 'product-updates',
        'tags'     => array( 'product-launch', 'ai' ),
    ),
    array(
        'title'    => '移动端 App 2.5 版本更新',
        'slug'     => 'mobile-app-2-5-update',
        'excerpt'  => '移动端 App 迎来 2.5 版本更新，新增离线模式、深色主题等多项实用功能。',
        'content'  => '<!-- wp:paragraph -->
<p>我们的移动端 App 迎来 2.5 版本更新，为用户带来更便捷的移动办公体验。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">新增功能</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li>离线模式：无网络也能正常使用</li>
<li>深色主题：保护眼睛，节省电量</li>
<li>手势操作：滑动即可完成常用操作</li>
<li>小组件：桌面快捷入口</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>立即前往 App Store 或 Google Play 更新体验！</p>
<!-- /wp:paragraph -->',
        'category' => 'product-updates',
        'tags'     => array( 'product-launch' ),
    ),

    // 行业动态
    array(
        'title'    => '数字化转型：企业发展的必由之路',
        'slug'     => 'digital-transformation-trend',
        'excerpt'  => '随着技术的不断进步，数字化转型已成为企业发展的必然趋势。本文将探讨企业如何有效推进数字化转型。',
        'content'  => '<!-- wp:paragraph -->
<p>随着技术的不断进步，数字化转型已成为企业发展的必然趋势。无论是传统制造业还是服务业，都在积极拥抱数字化变革。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">数字化转型的关键要素</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>成功的数字化转型需要从以下几个方面入手：</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<li><strong>战略规划</strong>：明确转型目标和路径</li>
<li><strong>技术选型</strong>：选择适合企业的技术方案</li>
<li><strong>人才培养</strong>：提升团队的数字化能力</li>
<li><strong>文化变革</strong>：建立创新和开放的企业文化</li>
</ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>数字化转型不是一蹴而就的，需要企业持续投入和不断优化。</p>
<!-- /wp:paragraph -->',
        'category' => 'industry-news',
        'tags'     => array( 'digital-transformation', 'enterprise-service' ),
    ),
    array(
        'title'    => '2025年人工智能发展趋势展望',
        'slug'     => 'ai-trends-2025',
        'excerpt'  => '人工智能技术持续演进，2025年将迎来哪些新的发展趋势？本文为您深度解读。',
        'content'  => '<!-- wp:paragraph -->
<p>人工智能技术持续快速发展，正在深刻改变各行各业。2025年，AI 领域将迎来更多突破和创新。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">主要趋势</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>大模型普及</strong>：更多企业将部署专属大模型</li>
<li><strong>多模态融合</strong>：文字、图像、语音的统一理解</li>
<li><strong>边缘AI</strong>：智能终端本地处理能力增强</li>
<li><strong>AI Agent</strong>：自主决策和任务执行能力提升</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>企业应当积极关注这些趋势，提前布局，抢占先机。</p>
<!-- /wp:paragraph -->',
        'category' => 'industry-news',
        'tags'     => array( 'ai', 'tech-innovation', 'digital-transformation' ),
    ),
    array(
        'title'    => '云计算市场格局与发展机遇',
        'slug'     => 'cloud-computing-opportunities',
        'excerpt'  => '云计算市场持续增长，企业如何把握云时代的发展机遇？本文分析当前市场格局和未来趋势。',
        'content'  => '<!-- wp:paragraph -->
<p>云计算已成为企业数字化基础设施的核心组成部分，市场规模持续扩大。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">市场现状</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>据最新报告显示，全球云计算市场规模已超过5000亿美元，年增长率保持在20%以上。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">发展机遇</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li>混合云架构成为主流选择</li>
<li>云原生技术加速普及</li>
<li>行业云解决方案需求增长</li>
<li>数据安全和合规要求提升</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>选择合适的云服务商和架构方案，是企业上云成功的关键。</p>
<!-- /wp:paragraph -->',
        'category' => 'industry-news',
        'tags'     => array( 'cloud-computing', 'enterprise-service' ),
    ),

    // 技术分享
    array(
        'title'    => 'API 设计最佳实践指南',
        'slug'     => 'api-design-best-practices',
        'excerpt'  => '良好的 API 设计是系统可维护性和扩展性的基础。本文总结了 RESTful API 设计的最佳实践。',
        'content'  => '<!-- wp:paragraph -->
<p>良好的 API 设计是系统可维护性和扩展性的基础。本文总结了 RESTful API 设计的最佳实践。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">设计原则</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<li><strong>资源导向</strong>：使用名词而非动词</li>
<li><strong>版本控制</strong>：在 URL 中包含版本号</li>
<li><strong>状态码规范</strong>：正确使用 HTTP 状态码</li>
<li><strong>分页设计</strong>：大数据集必须分页</li>
<li><strong>错误处理</strong>：统一的错误响应格式</li>
</ol>
<!-- /wp:list -->

<!-- wp:code -->
<pre class="wp-block-code"><code>// 示例：获取用户列表
GET /api/v2/users?page=1&amp;limit=20

// 响应格式
{
  "data": [...],
  "meta": {
    "total": 100,
    "page": 1,
    "limit": 20
  }
}</code></pre>
<!-- /wp:code -->

<!-- wp:paragraph -->
<p>遵循这些原则，可以设计出易用、一致且可维护的 API。</p>
<!-- /wp:paragraph -->',
        'category' => 'tech-sharing',
        'tags'     => array( 'developer', 'best-practices' ),
    ),
    array(
        'title'    => '微服务架构实践经验分享',
        'slug'     => 'microservices-architecture-experience',
        'excerpt'  => '微服务架构在大型系统中越来越普及，但实施过程中也面临诸多挑战。本文分享我们的实践经验。',
        'content'  => '<!-- wp:paragraph -->
<p>微服务架构在大型系统中越来越普及，但实施过程中也面临诸多挑战。本文分享我们在微服务转型中的实践经验。</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">核心挑战</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li>服务拆分粒度的把控</li>
<li>分布式事务的处理</li>
<li>服务间通信的复杂性</li>
<li>监控和故障排查</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">解决方案</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>我们采用了以下策略来应对这些挑战：</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<li>基于领域驱动设计（DDD）进行服务拆分</li>
<li>采用 Saga 模式处理分布式事务</li>
<li>使用服务网格（Service Mesh）管理服务通信</li>
<li>建立完善的可观测性体系</li>
</ol>
<!-- /wp:list -->',
        'category' => 'tech-sharing',
        'tags'     => array( 'developer', 'best-practices', 'cloud-computing' ),
    ),
);

$post_ids = array();
foreach ( $posts as $post ) {
    $existing = get_page_by_path( $post['slug'], OBJECT, 'post' );
    if ( $existing ) {
        $post_ids[ $post['slug'] ] = $existing->ID;
        echo "  - 文章已存在: {$post['title']}\n";
        continue;
    }

    $cat_id = isset( $category_ids[ $post['category'] ] ) ? array( $category_ids[ $post['category'] ] ) : array();

    $post_id = wp_insert_post( array(
        'post_title'    => $post['title'],
        'post_name'     => $post['slug'],
        'post_excerpt'  => $post['excerpt'],
        'post_content'  => $post['content'],
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_author'   => 1,
        'post_category' => $cat_id,
    ) );

    if ( $post_id && ! is_wp_error( $post_id ) ) {
        $post_ids[ $post['slug'] ] = $post_id;
        $created['posts']++;

        // 添加标签
        if ( ! empty( $post['tags'] ) ) {
            $tag_ids_for_post = array();
            foreach ( $post['tags'] as $tag_slug ) {
                if ( isset( $tag_ids[ $tag_slug ] ) ) {
                    $tag_ids_for_post[] = $tag_ids[ $tag_slug ];
                }
            }
            if ( ! empty( $tag_ids_for_post ) ) {
                wp_set_post_terms( $post_id, $tag_ids_for_post, 'post_tag' );
            }
        }

        echo "  ✓ 创建文章: {$post['title']} (ID: $post_id)\n";
    }
}

// =============================================================
// 5. 创建评论
// =============================================================
echo "\nStep 5: 创建评论...\n";

$comments = array(
    array(
        'post_slug'    => 'innovation-award-2025',
        'author'       => '张先生',
        'email'        => 'zhang@example.com',
        'content'      => '恭喜获奖！一直关注贵公司的发展，期待更多创新产品。',
    ),
    array(
        'post_slug'    => 'innovation-award-2025',
        'author'       => '李女士',
        'email'        => 'li@example.com',
        'content'      => '实至名归！贵公司的产品确实帮助我们提升了很多效率。',
    ),
    array(
        'post_slug'    => 'product-3-0-release',
        'author'       => '王工',
        'email'        => 'wang@example.com',
        'content'      => '新版本的 AI 助手功能太棒了，大大提高了工作效率！',
    ),
    array(
        'post_slug'    => 'product-3-0-release',
        'author'       => '陈经理',
        'email'        => 'chen@example.com',
        'content'      => '界面更清爽了，操作也更流畅，好评！',
    ),
    array(
        'post_slug'    => 'api-design-best-practices',
        'author'       => '开发者小刘',
        'email'        => 'liu@example.com',
        'content'      => '文章写得很实用，正好在做 API 设计，收藏了！',
    ),
    array(
        'post_slug'    => 'digital-transformation-trend',
        'author'       => '赵总',
        'email'        => 'zhao@example.com',
        'content'      => '数字化转型确实是大趋势，我们公司也在积极推进中。',
    ),
);

foreach ( $comments as $comment ) {
    if ( ! isset( $post_ids[ $comment['post_slug'] ] ) ) {
        continue;
    }

    $post_id = $post_ids[ $comment['post_slug'] ];

    // 检查是否已有相同评论
    $existing = get_comments( array(
        'post_id'      => $post_id,
        'author_email' => $comment['email'],
        'number'       => 1,
    ) );

    if ( ! empty( $existing ) ) {
        echo "  - 评论已存在: {$comment['author']} on {$comment['post_slug']}\n";
        continue;
    }

    $comment_id = wp_insert_comment( array(
        'comment_post_ID'      => $post_id,
        'comment_author'       => $comment['author'],
        'comment_author_email' => $comment['email'],
        'comment_content'      => $comment['content'],
        'comment_approved'     => 1,
    ) );

    if ( $comment_id ) {
        $created['comments']++;
        echo "  ✓ 创建评论: {$comment['author']} (ID: $comment_id)\n";
    }
}

// =============================================================
// 6. 创建导航菜单
// =============================================================
echo "\nStep 6: 创建导航菜单...\n";

$menu_name = '主导航';
$existing_menu = wp_get_nav_menu_object( $menu_name );

if ( $existing_menu ) {
    $menu_id = $existing_menu->term_id;
    echo "  - 菜单已存在: $menu_name\n";
} else {
    $menu_id = wp_create_nav_menu( $menu_name );
    $created['menus']++;
    echo "  ✓ 创建菜单: $menu_name (ID: $menu_id)\n";
}

if ( ! is_wp_error( $menu_id ) ) {
    // 清空现有菜单项
    $existing_items = wp_get_nav_menu_items( $menu_id );
    foreach ( $existing_items as $item ) {
        wp_delete_post( $item->ID, true );
    }

    // 添加菜单项
    $menu_items = array(
        array( 'title' => '首页', 'page' => 'home', 'order' => 1 ),
        array( 'title' => '服务', 'page' => 'services', 'order' => 2 ),
        array( 'title' => '新闻', 'page' => 'news', 'order' => 3 ),
        array( 'title' => '关于', 'page' => 'about', 'order' => 4 ),
        array( 'title' => '联系', 'page' => 'contact', 'order' => 5 ),
    );

    foreach ( $menu_items as $item ) {
        if ( isset( $item['page'] ) && isset( $page_ids[ $item['page'] ] ) ) {
            wp_update_nav_menu_item( $menu_id, 0, array(
                'menu-item-title'     => $item['title'],
                'menu-item-object-id' => $page_ids[ $item['page'] ],
                'menu-item-object'    => 'page',
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
                'menu-item-position'  => $item['order'],
            ) );
        }
    }

    // 设置菜单位置
    $locations = get_theme_mod( 'nav_menu_locations' );
    if ( ! is_array( $locations ) ) {
        $locations = array();
    }
    $locations['primary'] = $menu_id;
    set_theme_mod( 'nav_menu_locations', $locations );

    echo "  ✓ 添加了 " . count( $menu_items ) . " 个菜单项\n";
}

// =============================================================
// 汇总
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  企业官网数据填充完成                                         ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf( "║  页面:     %2d 个                                             ║\n", $created['pages'] );
printf( "║  文章:     %2d 篇                                             ║\n", $created['posts'] );
printf( "║  分类:     %2d 个                                             ║\n", $created['categories'] );
printf( "║  标签:     %2d 个                                             ║\n", $created['tags'] );
printf( "║  评论:     %2d 条                                             ║\n", $created['comments'] );
printf( "║  菜单:     %2d 个                                             ║\n", $created['menus'] );
echo "╚══════════════════════════════════════════════════════════════╝\n";

echo "\n访问首页查看效果: " . home_url() . "\n";

return array(
    'success' => true,
    'created' => $created,
);
