<?php

$translations = [
    'language' => ['label' => '选择语言', 'chinese' => '中文', 'english' => 'EN'],
    'nav' => ['reservations' => '预约', 'workspace' => '工作区', 'images' => '图片', 'videos' => '视频', 'profile' => '个人资料', 'admin' => '管理后台', 'sign_out' => '退出登录'],
    'common' => ['confirm' => '确认', 'cancel' => '取消', 'actions' => '操作', 'status' => '状态', 'purpose' => '用途', 'optional' => '选填', 'disabled' => '已禁用', 'refresh_status' => '刷新状态', 'next' => '下一项', 'project' => '项目', 'projects' => '项目', 'window' => '时段', 'state' => '状态', 'broker' => 'Broker'],
    'auth' => [
        'sign_in' => '登录', 'company_sign_in' => '公司账号登录', 'no_registration' => '账号由管理员创建，已关闭公开注册。', 'email' => '电子邮箱', 'password' => '密码', 'remember_me' => '记住我', 'forgot_password' => '忘记密码？', 'confirm_password' => '确认密码', 'confirm_your_password' => '请确认你的密码', 'reset_password' => '重置密码', 'reset_link_notice' => '如果账号存在，系统会发送密码重置链接。', 'send_reset_link' => '发送重置链接', 'choose_new_password' => '设置新密码', 'new_password' => '新密码', 'authenticator_code' => '身份验证器验证码', 'verify' => '验证', 'recovery_code' => '恢复代码', 'use_recovery_code' => '使用恢复代码',
    ],
    'dashboard' => ['title' => '控制台', 'reservations_help' => '预约并管理你的独占制作时段。', 'workspace_help' => '在有效预约期间打开隔离的 Codex 终端。', 'security' => '安全设置', 'security_help' => '管理密码和 TOTP 双重验证。'],
    'profile' => ['title' => '个人安全设置', 'two_factor' => '双重身份验证', 'totp_required' => '进入门户前必须启用 TOTP。', 'enable_totp' => '启用 TOTP', 'confirm_totp' => '确认 TOTP', 'totp_enabled' => 'TOTP 已启用并确认。', 'show_recovery_codes' => '显示恢复代码', 'disable_totp' => '停用 TOTP', 'change_password' => '修改密码', 'current_password' => '当前密码', 'update_password' => '更新密码'],
    'reservations' => [
        'title' => '我的预约', 'new' => '新建预约', 'exclusive_time' => '独占制作时段', 'intro' => '先选择 AI 服务器，再选择这台服务器当前可用的开始和结束时间。所有时间均按 :timezone 显示。', 'view_mine' => '查看我的预约', 'step_1' => '第 1 步', 'step_2' => '第 2 步', 'select_window' => '选择预约时段', 'date' => '日期', 'start_time' => '开始时间', 'end_time' => '结束时间', 'loading' => '加载中…', 'select_start' => '请先选择开始时间', 'loading_availability' => '正在加载当前可用时段…', 'purpose_placeholder' => '你准备在这个时段完成什么工作？', 'timing_help' => '服务器空闲时，第一个选项可以从当前分钟开始；之后的开始时间按 15 分钟递进，结束时间为整点。最长可预约 8 个实际小时，后一位可以从前一位预约结束的同一时刻开始。', 'reserve' => '预约此时段', 'live_availability' => '实时可用状态', 'available_windows' => '可用时段', 'availability_help' => '第一个空闲选项可以从当前分钟开始，后续时段按 15 分钟递进，并显示下一次预约或维护窗口前最晚可结束的时间。', 'availability_warning' => '页面打开期间可用状态可能变化；提交预约时 PostgreSQL 会再次检查资源时段。', 'javascript_required' => '加载实时预约状态需要启用 JavaScript。', 'no_reservations' => '还没有预约。', 'book_time' => '预约时间', 'reservation' => '预约', 'today' => '今天 · ', 'tomorrow' => '明天 · ', 'now' => '现在 · ', 'duration_hours' => ':count 小时', 'duration_minutes' => ':count 分钟', 'extend' => '延长预约', 'extend_help' => '预约总时长最长 8 小时；可选结束时间会自动停在下一位预约或维护窗口开始之前。', 'extend_to' => '新的结束时间', 'extend_option' => ':time · 增加 :added · 总计 :total', 'extend_confirm' => '确认延长', 'extend_unavailable' => '当前无法继续延长：预约已达到最长时长，或下一位预约/维护窗口紧接当前结束时间。',
    ],
    'compute_nodes' => [
        'singular' => 'AI服务器', 'plural' => 'AI服务器', 'select_server' => '选择 AI 服务器',
        'select_help' => '这里只显示管理员已加入的服务器。选完服务器后，再选择这台服务器的预约时间。',
        'select_first' => '请先选择一台可用的服务器。', 'all_servers' => '所有服务器',
        'ip_help' => '仅允许受信任网段内的私有 IPv4 地址；预约页面绝不会暴露 IP。',
        'state_help' => '新加入或修改 IP 的服务器会保持维护状态；健康检查通过后，再由管理员设为在线。',
        'availability' => ['idle' => '空闲', 'busy' => '占用中', 'abnormal' => '异常'],
        'scheduling' => ['online' => '在线', 'draining' => '停止接单', 'maintenance' => '维护中', 'offline' => '离线'],
        'capabilities' => ['local_ai' => '本地 AI', 'local_image' => '本地图像'],
        'fields' => ['display_name' => '显示名称', 'host_ip' => '主机私有 IP', 'visible' => '显示在预约页面', 'scheduling_state' => '调度状态', 'availability' => '可用状态', 'last_heartbeat' => '最后心跳', 'last_error' => '最后异常'],
        'errors' => ['unavailable' => '所选 AI 服务器当前不可用，请选择其他服务器。', 'invalid_ip' => '请输入允许网段内的私有 IPv4 服务器地址。', 'ip_has_reservations' => '这台服务器还有运行中或未来预约；完成或取消这些预约后才能修改 IP。'],
    ],
    'workspace' => [
        'choose_project_title' => '选择项目', 'entry' => '工作区入口', 'choose_project_folder' => '选择项目文件夹', 'secure_workspace' => '安全 AI 工作区', 'private_root_help' => '在终端中，/workspace 已经对应你的私有根目录 :root。终端路径不会重复显示邮箱层级，也不会挂载命名卷父目录或其他用户的根目录。', 'open_video_library' => '打开视频库', 'active_at' => '工作区正在 /workspace/:directory 中运行', 'active_lock_help' => '仍可修改项目显示名称。目录重命名和项目删除会在 :time 后解锁，避免正在运行的终端丢失工作目录。', 'not_enabled' => '工作区尚未启用', 'runtime_disabled' => '配置中已禁用 Workspace 运行服务。', 'first_visit' => '首次使用', 'create_first' => '请先创建第一个项目', 'project_fields_help' => '项目名称用于界面显示；目录名称是终端实际打开的文件夹。', 'project_name' => '项目名称', 'project_name_help' => '显示名称支持中文和英文。', 'directory_name' => '目录名称', 'directory_rules' => '1–64 个字符：小写 a-z、数字 0-9，以及内部的 -、_ 或 .；开头和结尾必须是字母或数字。例如 qi-yue-liu-huo。', 'directory_rules_short' => '仅限小写 a-z、数字 0-9 和内部的 -、_、.；开头和结尾必须是字母或数字。', 'create_enter' => '创建并进入', 'your_projects' => '你的项目', 'project_selection_help' => '选择项目后终端会在该目录中打开。切换项目只会安全刷新你的 Workspace 容器，并保留 CODEX_HOME。', 'enter' => '进入', 'edit_project' => '编辑名称或目录', 'rename_locked' => 'Workspace 运行期间目录重命名已锁定；你仍可以修改上方的显示名称。', 'save_project' => '保存项目设置', 'delete_project' => '删除项目', 'delete_project_help' => '从门户移除此项目，并仅把 /workspace/:directory 移入你的私有恢复区。不会删除其他项目、CODEX_HOME 或 /outputs。', 'deletion_locked' => 'Workspace 运行期间项目删除已锁定。', 'type_delete' => '输入 delete 以确认', 'create_another' => '创建另一个项目', 'codex_account' => 'Codex 账号', 'choose_codex_account' => '请选择本次使用的 Codex 账号', 'codex_account_help' => '每次进入 Workspace 都可以重新选择。个人凭证按用户隔离；公司 Codex 一次只允许一位用户使用。', 'company_account' => '使用公司 Codex 账号', 'company_account_help' => '使用服务器上已登录的公司账号，进入后自动启动 Codex。', 'company_unavailable' => '公司账号当前不可用', 'company_managed' => '登录状态由服务器管理', 'company_occupied' => '公司 Codex 正被占用', 'company_single_user' => '一次只能有一位用户使用公司 Codex。', 'company_owned_by_me' => '公司 Codex 当前由你使用', 'personal_account' => '使用自己的 Codex 账号', 'personal_account_help' => '使用你自己的独立登录；已登录会直接进入 Codex，首次使用会自动启动设备码登录。', 'personal_isolated' => '个人凭证独立保存', 'account_loading' => '正在准备你的 Workspace', 'account_loading_help' => '请稍候，正在应用所选 Codex 账号并准备安全工作区；就绪后页面会自动进入。', 'back_projects' => '返回项目列表', 'isolated_terminal' => '隔离的 Codex 终端', 'video_library' => '视频库', 'style_library' => '风格库', 'style_library_title' => 'H3 视频风格画廊', 'style_library_intro' => '先查看每种风格的示范视频，再把对应的 $skill-name 复制到 Codex。画廊固定为 3 列，首屏显示 9 个，向下滚动查看其余风格。', 'close_style_library' => '关闭风格库', 'copy_skill_name' => '复制 Skill 名称', 'style_library_close_hint' => '此窗口只能点击右上角 × 关闭；点击视频外区域不会关闭或中断视频。', 'style_library_pages' => '风格画廊分页', 'style_page_previous' => '上一页', 'style_page_next' => '下一页', 'video_unsupported' => '当前浏览器无法播放此示范视频。', 'switch_project' => '切换项目', 'reselect_account' => '重新选择 Codex 账号', 'company_badge' => '公司 Codex 账号', 'personal_badge' => '个人 Codex 账号', 'isolation_enabled' => '已启用邮箱根目录隔离', 'no_active_window' => '当前没有有效预约时段', 'no_active_help' => '项目已经选定。无需预约也可进入 Codex 查看和继续工作；本地 AI 只在预约时段开放。', 'stopped' => '你的 Workspace 已停止', 'reservation_ready' => '预约已就绪', 'restart_help' => '你的预约在 :time 前仍然有效。重新启动会打开同一个私有项目、CODEX_HOME 和输出目录。', 'start_help' => '启动时只会创建固定的强化容器模板和你自己的私有卷。', 'restart' => '重新启动工作区', 'start' => '启动工作区', 'codex_sessions' => 'Codex 会话', 'continue_session' => '继续之前的工作', 'enter_workspace' => '进入工作区', 'session_history_help' => '选择这个项目保存过的会话继续工作，或新开一个空白 Codex 会话。项目文件和历史记录都保留在你的私有卷中。', 'collapse_sessions' => '收起会话', 'expand_sessions' => '展开会话', 'new_blank_session' => '新开空白 Session', 'loading_sessions' => '正在加载历史会话…', 'session_switch_confirm' => '当前终端进程会关闭并重新打开，项目文件会保留。是否继续？', 'cli_loading' => '正在加载 Codex CLI', 'cli_loading_resume' => '正在恢复所选会话；Codex 真正就绪后会自动进入。', 'cli_loading_new' => '正在启动空白会话；Codex 真正就绪后会自动进入。', 'cli_loading_slow' => 'Codex 仍在准备中；就绪后会自动打开，无需反复按回车。', 'session_javascript_required' => '加载和选择历史会话需要启用 JavaScript。', 'upload_image' => '上传图像到 Codex', 'upload_help' => '支持最大 20 MB 的 JPEG、PNG、WebP 或 GIF；图像只会存入当前项目的 uploads/。', 'choose_image' => '选择图像或拖放到这里', 'upload_target' => '将上传到 /workspace/:directory', 'upload_add' => '上传并添加到 CLI', 'selected_preview' => '所选图像预览', 'copy_command' => '复制命令', 'copy_terminal_text' => '打开可复制文本', 'copy_terminal_screen' => '复制当前屏幕', 'copy_terminal_help' => '这里是浏览器当前可读取的全部终端文字快照。可像普通文本一样选择任意部分，或复制全部。', 'copy_all_terminal_text' => '复制全部文字', 'company_runtime_help' => '服务器已管理该账号的登录状态，终端会直接启动 Codex。退出 Codex 后可以在同一终端重新启动。', 'personal_runtime_help' => '终端会先检查你的独立登录状态：已登录会直接进入 Codex；首次使用会自动启动设备码登录并在完成后进入 Codex。', 'being_prepared' => '正在准备 Workspace', 'prepared_help' => '请稍后刷新。Codex 工作区可在预约外使用；只有本地 AI 会等待预约开始。', 'stop' => '停止工作区', 'stop_help' => '这会停止你的当前 Workspace；若本地 AI 预约正在使用，会先安全撤权。预约和其他用户的工作区不会被替换。', 'stop_help_runtime' => '这只会停止你的 Workspace。项目文件、个人 CODEX_HOME 和输出都会保留，不影响其他用户。', 'confirm_stop' => '确认停止工作区', 'abandon' => '放弃预约', 'abandon_help' => '立即释放本次本地 AI 预约，但保留普通 Workspace、项目文件、CODEX_HOME 和 /outputs。', 'confirm_abandon' => '确认放弃预约',
        'enter_without_reservation' => '不预约直接进入工作区',
        'style_demo_unavailable' => '示范视频暂未提供。',
        'local_ai_countdown' => '本地 AI 还有 :time 开始',
        'local_ai_starting' => '正在启用本地 AI…',
        'local_ai_ready' => '本地 AI 已可用',
        'local_ai_start_failed' => '本地 AI 启动失败',
        'terminal_intro' => '项目 :project 将在 /workspace/:directory 打开。在 Codex 中使用 /model 可在 OpenAI 与本地 Qwen 间切换；H3 视频和 Z-Image-Turbo 图像任务仍通过受限 Broker 运行。',
    ],
    'style_catalog' => [
        'h3_editorial_fashion_motion' => [
            'title' => '高定杂志动态',
            'description' => '锁定人物与高定服装，以克制镜头、动态杂志版式和节拍同步转场构成时尚短片。',
        ],
        'h3_surreal_miniature_absurdism' => [
            'title' => '超现实微缩荒诞',
            'description' => '以写实微距、极端尺度差、怪诞生物和明确材质物理构成视觉喜剧。',
        ],
        'h3_chibi_live_action_sticker' => [
            'title' => '真人 × 2D 贴纸',
            'description' => '仅有一个扁平 2D 吉祥物，在完全写实的真人环境中产生真实物理互动。',
        ],
        'h3_creature_motion_replacement' => [
            'title' => '夜景生物替换',
            'description' => '让电影级生物继承精确走位、道具接触、镜头节奏与氛围光线。',
        ],
        'h3_multiverse_portal_ensemble' => [
            'title' => '多元传送门群像',
            'description' => '原创角色依次穿过同一发光传送门，最终落成清晰稳定的英雄群像。',
        ],
        'h3_deadpan_mockumentary_interview' => [
            'title' => '冷面伪纪录片访谈',
            'description' => '以克制纪录片镜头、精准对白、尴尬停顿和微表情反应制造干冷笑点。',
        ],
        'h3_soft_body_physics_comedy' => [
            'title' => '软体物理喜剧',
            'description' => '通过压缩、摩擦、回弹和近似真实的材质行为，完成无伤害的触感笑料。',
        ],
        'h3_retro_pixel_sprite_loop' => [
            'title' => '16-bit 精灵循环',
            'description' => '保持像素轮廓与限色稳定，以阶梯式动作设计可返回首帧的角色循环。',
        ],
        'h3_japanese_craft_commercial' => [
            'title' => '日式工艺广告',
            'description' => '用克制微距、精准手作、细腻拟音和干净产品落版呈现工艺质感。',
        ],
        'h3_micro_fpv_impossible_one_take' => [
            'title' => '微型 FPV 极限一镜到底',
            'description' => '微型镜头贴着材质表面连续穿越细小障碍，最终以尺度反转完成揭示。',
        ],
        'h3_occlusion_orbit_ensemble' => [
            'title' => '遮挡衔接环绕群像',
            'description' => '三名角色依次完成脚到头的贴身环绕，并用自然前景遮挡无剪切交接。',
        ],
        'h3_character_intro_motion_card' => [
            'title' => '角色登场动态卡',
            'description' => '以完整角色概念板锁定身份，在 13 个动作与编辑排版镜头中完成登场。',
        ],
        'h3_ancient_title_sequence' => [
            'title' => '古装剧质感片头',
            'description' => '以墨、绢、铜、山水和象征性匹配转场构成原创时代剧开场。',
        ],
        'h3_interactive_creature_encyclopedia' => [
            'title' => '交互式生物图鉴',
            'description' => '在稳定收藏界面中扫描并展示多种原创生物，保持各自解剖结构独立。',
        ],
        'h3_anime_character_showcase_pv' => [
            'title' => '动漫角色展示 PV',
            'description' => '角色居中完成身份稳定的 360° 转身，漫画世界逐步装配并无缝回到首帧。',
        ],
        'h3_material_carving_asmr' => [
            'title' => '材质雕刻 ASMR',
            'description' => '以安全连续雕刻、稳定双手和精准材质拟音完成原创雕塑揭示。',
        ],
        'h3_pop_art_split_screen_motion' => [
            'title' => '波普分屏动效',
            'description' => '固定主产品穿梭于节拍同步的绘画分屏、硬边分隔和最终合屏。',
        ],
        'h3_dark_sci_fi_motion_poster' => [
            'title' => '暗黑科幻动态海报',
            'description' => '分层重建原海报，并锁定人物侧脸、机械头盔、飞船、传送门、排版与整体构图。',
        ],
        'h3_asymmetric_speed_duo_choreography' => [
            'title' => '3:1 异速双人编舞',
            'description' => '用可计数的 3:1 动作规则锁定快慢差，让成年跟随者始终延迟模仿且不会追平。',
        ],
        'h3_layered_windsurfing_fashion_mv' => [
            'title' => '分层风帆时尚 MV',
            'description' => '把人物、装备和摄影参考分层授权，构成随原创音乐推进的水上时尚运动弧线。',
        ],
        'h3_water_obstacle_variety_show' => [
            'title' => '水上闯关综艺',
            'description' => '锁定无伤害喜剧节拍，并让模型自由补足安全走位、观众反应与电视转播镜头。',
        ],
        'h3_two_part_character_reveal' => [
            'title' => '双段角色电影揭示',
            'description' => '用镜像衔接契约连接两段密集角色揭示，并明确禁止第二段重复第一段镜头。',
        ],
        'h3_first_person_finger_controlled_dance' => [
            'title' => '第一人称手指控舞',
            'description' => '前景手指给出明确方向指令，成年舞者以零延迟的全身位移即时响应。',
        ],
    ],
    'images' => [
        'persistent_media' => '持久化项目媒体',
        'title' => '图片库',
        'intro' => '图片按账号和项目隔离。你可以在新标签页中打开原图、下载、直接重命名，或移入私有恢复区。',
        'open_recovery' => '进入私有恢复区',
        'back_projects' => '返回项目列表',
        'select_all' => '选择全部图片',
        'selected_count' => '已选择 :count 张图片',
        'bulk_trash_confirm' => '确定把所选图片移入私有恢复区吗？',
        'bulk_trash' => '把所选图片移入恢复区',
        'count' => ':count 张图片',
        'none_project' => '这个项目中还没有生成的图片。',
        'create_first' => '请先创建项目',
        'saved_under_project' => '新生成的图片都会保存在 Workspace 当前选择的项目下。',
        'create_project' => '创建项目',
        'legacy_title' => '未分配的历史图片',
        'legacy_help' => '这些文件来自旧版用户输出卷，当时没有记录所属项目。系统会原样保留，不会猜测归属。',
        'select_media' => '选择 :name',
        'open_new_tab' => '在新标签页打开',
        'download' => '下载',
        'rename_delete' => '重命名或删除',
        'new_filename' => '新图片文件名',
        'rename' => '重命名',
        'trash_help' => '把此图片移入私有恢复区。',
        'delete' => '删除',
        'trash_request_failed' => '无法把所选图片移入恢复区，请刷新页面后重试。',
    ],
    'videos' => [
        'persistent_media' => '持久化项目媒体', 'title' => '视频库', 'intro' => '视频按账号和项目隔离。你可以在新标签页中播放、下载原文件、直接重命名，或移入私有恢复区。', 'back_projects' => '返回项目列表', 'count' => ':count 个视频', 'none_project' => '这个项目中还没有生成的视频。', 'create_first' => '请先创建项目', 'saved_under_project' => '新生成的视频都会保存在 Workspace 当前选择的项目下。', 'create_project' => '创建项目', 'legacy_title' => '未分配的历史视频', 'legacy_help' => '这些文件来自旧版用户输出卷，当时没有记录所属项目。系统会原样保留，不会猜测归属。', 'open_new_tab' => '在新标签页打开', 'download' => '下载', 'rename_delete' => '重命名或删除', 'new_filename' => '新视频文件名', 'rename' => '重命名', 'trash_help' => '把此视频移入私有恢复区。', 'delete' => '删除', 'open_recovery' => '进入私有恢复区', 'select_all' => '全选视频', 'selected_count' => '已选择 :count 个视频', 'select_media' => '选择 :name', 'bulk_trash' => '批量移入私有恢复区', 'bulk_trash_confirm' => '要把所选视频移入你的私有恢复区吗？之后仍可恢复。',
    ],
    'recovery' => [
        'private_media' => '仅限本人访问的媒体恢复',
        'title' => '私有恢复区',
        'intro' => '这里只显示当前账号移入恢复区的媒体。你可以批量恢复，也可以从存储中永久删除；永久删除后无法找回。',
        'back_images' => '返回图片库',
        'back_videos' => '返回视频库',
        'images' => '图片',
        'videos' => '视频',
        'total_size' => '总大小',
        'empty_title' => '恢复区为空',
        'empty_help' => '从图片库或视频库删除的媒体会出现在这里。',
        'select_all' => '全选媒体',
        'selected_count' => '已选择 :count 项',
        'legacy_scope' => '未分配的历史媒体',
        'removed_project' => '已移除或不可用的项目',
        'image' => '图片',
        'video' => '视频',
        'removed_at' => '移入时间',
        'preview' => '预览',
        'restore_title' => '恢复所选媒体',
        'restore_help' => '把文件恢复到原项目或历史媒体范围的根目录；绝不会覆盖同名文件。',
        'restore_selected' => '批量恢复所选项',
        'purge_title' => '永久删除所选媒体',
        'purge_help' => '这会从存储中真实移除所选文件，之后无法恢复。',
        'type_purge_confirmation' => '输入 delete 确认永久删除',
        'purge_selected' => '批量永久删除所选项',
    ],
    'recovery_errors' => [
        'restore_collision' => '无法恢复 :name，因为其项目中已存在同名文件。',
    ],
    'image_library_errors' => [
        'name_rules' => '请使用以 .gif、.jpeg、.jpg、.png 或 .webp 结尾的图片文件名，且不能包含斜杠或控制字符。',
        'name_duplicate' => '已存在同名图片。',
    ],
    'statuses' => ['confirmed' => '已确认', 'provisioning' => '准备中', 'active' => '运行中', 'ending' => '结束中', 'completed' => '已完成', 'cancelled' => '已取消', 'failed' => '失败'],
    'messages' => ['reservation_created' => '预约已创建。', 'reservation_cancelled' => '预约已取消。', 'reservation_extended' => '预约已延长。', 'video_renamed' => '视频已重命名。', 'legacy_video_renamed' => '历史视频已重命名。', 'video_trashed' => '视频已移入私有恢复区。', 'legacy_video_trashed' => '历史视频已移入私有恢复区。', 'videos_trashed' => '已把 :count 个视频移入私有恢复区。', 'image_renamed' => '图片已重命名。', 'legacy_image_renamed' => '历史图片已重命名。', 'image_trashed' => '图片已移入私有恢复区。', 'legacy_image_trashed' => '历史图片已移入私有恢复区。', 'images_trashed' => '已把 :count 张图片移入私有恢复区。', 'media_restored' => '已恢复 :count 个媒体文件。', 'media_purged' => '已永久删除 :count 个媒体文件。', 'project_created' => '项目已创建，Workspace 已在所选目录中打开。', 'project_updated' => '项目设置已更新。', 'project_removed' => '项目已移除，其目录已移入你的私有恢复区。', 'project_opened' => 'Workspace 已在所选项目目录中打开。', 'workspace_started' => 'Workspace 已启动。在 Codex 中使用 /model 可在 OpenAI 与本地 Qwen 间切换；H3 和 Z-Image 任务仍受 Broker 限制。', 'workspace_stopped_restartable' => 'Workspace 已停止。预约仍保留到计划结束时间，在此之前可以重新启动。', 'workspace_stopped_ended' => 'Workspace 已停止，但预约时段在清理期间结束，无法重新启动。', 'reservation_abandoned' => '当前预约已放弃；项目文件、CODEX_HOME 和输出均已保留。'],
    'errors' => [
        'company_account_occupied' => '公司 Codex 正被占用，一次只能有一位用户使用；你仍可选择个人 Codex。',
        'workspace_capacity_full' => 'Workspace 容量已满；系统没有中断正在使用或已预约的用户，请稍后重试。',
        'local_ai_runtime_change_blocked' => '本地 AI 授权与当前 Workspace 不一致，暂不能切换账号、项目或 Session；当前 Workspace 保持不变。',
        'session_switch_job_active' => '本地 AI 图像或视频任务仍在运行；任务完成后再切换 Codex 会话。',
        'valid_reservation_date' => '请选择有效的预约日期。', 'outside_booking_horizon' => '该日期超出可预约范围。', 'choose_project_enter' => '进入工作区前，请先选择或创建项目。', 'choose_project_account' => '选择 Codex 账号前，请先选择或创建项目。', 'company_account_unavailable' => '公司 Codex 账号当前不可用。', 'account_apply_failed' => '无法应用所选 Codex 账号；现有 Workspace 的安全限制没有被放宽。', 'valid_image' => '请选择有效的 JPEG、PNG、WebP 或 GIF 图像。', 'image_type' => '请选择 JPEG、PNG、WebP 或 GIF 图像。', 'image_too_large' => '图像不能大于 20 MB。', 'unsupported_image' => '不支持的图像类型。', 'image_unreadable' => '上传的图像为空或无法读取。', 'image_workspace_failed' => '无法把图像添加到当前 Workspace；Codex 中没有附加任何内容。', 'project_name_rules' => '项目名称必须为 1–80 个字符，且不能包含斜杠或控制字符。', 'directory_name_rules' => '目录名称必须为 1–64 个字符，只能使用小写 a-z、数字 0-9 和内部的连字符、下划线或句点，且开头和结尾必须是字母或数字。', 'directory_duplicate' => '你的其他项目已经使用此目录名称。', 'unsafe_workspace_root' => '此账号邮箱无法映射为安全的工作区根目录。', 'video_name_rules' => '请使用以 .mp4、.webm、.mov 或 .m4v 结尾的视频文件名，且不能包含斜杠或控制字符。', 'video_name_duplicate' => '已存在同名视频。', 'choose_project_start' => '启动工作区前，请先选择或创建项目。', 'no_reservation_start' => '当前没有可启动的预约。', 'choose_account_start' => '启动 Workspace 前，请选择要使用的 Codex 账号。', 'workspace_start_failed' => 'Workspace 启动失败；安全边界没有被放宽，请由管理员检查运行证据。', 'confirm_stop' => '继续前请确认停止 Workspace。', 'no_active_stop' => '你当前没有可停止的 Workspace。', 'workspace_stop_failed' => 'Workspace 停止失败并保持关闭；预约未被显示为可重启，请由管理员检查运行证据。', 'confirm_abandon' => '请确认要放弃当前预约。', 'reservation_abandon_failed' => '放弃预约失败并保持关闭；预约没有被释放，请由管理员检查运行证据。', 'project_selection_failed' => '无法把项目选择应用到强化 Workspace；现有运行实例的安全限制没有被放宽。', 'directory_active' => 'Workspace 运行期间不能重命名目录，未做任何更改。', 'directory_rename_failed' => '目录重命名失败并保持关闭；未做任何更改，请由管理员检查运行证据。', 'confirm_project_delete' => '请输入完整的 delete 以确认删除项目。', 'project_delete_active' => 'Workspace 正在运行，项目及其文件均未删除。', 'project_delete_failed' => '项目删除失败并保持关闭；未删除任何内容，请由管理员检查运行证据。', 'cancel_confirmed_only' => '只有已确认的预约可以取消。', 'cancellation_deadline' => '已超过取消预约的截止时间。', 'extend_active_only' => '只有运行中的预约可以延长。', 'end_must_be_later' => '新的结束时间必须晚于当前结束时间。', 'start_outside_horizon' => '开始时间超出可预约范围。', 'future_limit' => '已达到未来预约数量上限。', 'overlaps_maintenance' => '所选资源锁定时段与维护窗口重叠。', 'window_just_reserved' => '该资源时段刚被其他人预约，请选择其他时间。', 'workspace_not_enabled' => 'Workspace 尚未启用。', 'reservation_cannot_start' => '此预约无法启动 Workspace。', 'outside_activation_window' => '当前不在该预约的可启动时段内。', 'resource_admin_locked' => '资源正由管理员锁定清理。', 'stop_before_directory' => '请先停止运行中的 Workspace，再修改目录名称。', 'stop_before_project_delete' => '请先停止运行中的 Workspace，再删除项目。', 'not_current_reservation' => '这已不是当前预约。', 'valid_codex_account' => '请选择有效的 Codex 账号。', 'valid_session_action' => '请选择有效的会话操作。', 'session_history_failed' => '无法加载历史会话，当前终端没有改变。', 'session_not_found' => '该历史会话已不在当前项目中。', 'session_switch_failed' => '无法切换 Codex 会话，项目文件已保留。', 'confirm_session_delete' => '请确认永久删除此会话。', 'session_delete_active' => '当前 Codex 会话不能删除，请先切换到其他会话。', 'session_delete_failed' => '无法从 Codex 永久删除该会话。', 'totp_before_portal' => '使用门户前，请先启用并确认 TOTP。',
    ],
    'admin' => ['portal_navigation' => '门户导航', 'portal_home' => '门户首页', 'portal' => '门户', 'user' => '用户', 'users' => '用户', 'reservation' => '预约', 'reservations' => '预约', 'maintenance_window' => '维护窗口', 'maintenance_windows' => '维护窗口', 'audit_event' => '审计事件', 'audit_events' => '审计事件', 'email_address' => '电子邮箱', 'password_create_help' => '系统会通过邮件发送一次性密码设置链接。', 'password_edit_help' => '留空将保留当前密码。', 'delete' => '删除', 'cannot_delete_self' => '不能删除自己的账号。', 'delete_user_confirm' => '确定删除 :name？这会永久删除用户账号及其工作区数据库记录；工作区文件会保留。有预约历史的用户不能删除。', 'user_deleted' => '用户已删除', 'user_cannot_delete' => '无法删除用户', 'force_cancel' => '强制取消', 'force_cancel_confirm' => '确定强制取消 :user 的预约吗？如果本地 AI 正在使用，会先撤销 Broker 权限，再释放预约时段；Workspace 项目文件、CODEX_HOME 和输出都会保留。', 'force_cancelled' => '预约已强制取消', 'force_cancel_failed' => '无法安全地强制取消预约', 'force_cancel_unavailable' => '该预约已不再占用可预约时段。', 'fields' => ['id' => 'ID', 'name' => '姓名', 'email' => '电子邮箱', 'password' => '密码', 'role' => '角色', 'timezone' => '时区', 'created_at' => '创建时间', 'updated_at' => '更新时间', 'two_factor_confirmed_at' => 'TOTP 确认时间', 'user_id' => '用户', 'starts_at' => '开始时间', 'ends_at' => '结束时间', 'lock_starts_at' => '资源锁定开始', 'lock_ends_at' => '资源锁定结束', 'status' => '状态', 'activated_at' => '激活时间', 'first_connected_at' => '首次连接时间', 'cancelled_at' => '取消时间', 'end_reason' => '结束原因', 'created_by' => '创建人', 'automatic' => '自动创建', 'reason' => '原因', 'actor_id' => '操作人', 'action' => '操作', 'target_type' => '目标类型', 'target_id' => '目标 ID', 'ip_address' => 'IP 地址', 'request_id' => '请求 ID']],
    'roles' => ['user' => '普通用户', 'operator' => '运维人员', 'admin' => '管理员'],
    'formats' => ['date_time' => 'Y年n月j日 H:i T', 'time' => 'H:i T', 'time_short' => 'H:i', 'image_date' => 'Y年n月j日 H:i', 'video_date' => 'Y年n月j日 H:i', 'date_short' => 'n月j日 D', 'date_long' => 'n月j日 l', 'date_time_cross_day' => 'n月j日 D · H:i T'],
    'javascript' => [
        'select_server_first' => '请先选择一台可用的 AI 服务器。', 'selected_server_unavailable' => '所选 AI 服务器已不可用，请选择其他服务器。', 'choose_start' => '请选择可用的开始时间。', 'choose_end' => '现在请选择结束时间。', 'choose_end_option' => '选择结束时间', 'select_start' => '请先选择开始时间', 'no_windows' => '没有可用时段', 'choose_another_date' => '请选择其他日期。', 'end_by' => '最晚于 :time 结束', 'loading' => '加载中…', 'loading_availability' => '正在加载当前可用时段…', 'choose_start_option' => '选择开始时间', 'no_start_times' => '没有可用的开始时间', 'available_start_times' => '共有 :count 个可用开始时间，请选择一个。', 'no_bookable_times' => '该日期已没有可预约时间。', 'availability_unavailable' => '暂时无法获取可用状态', 'unable_load_times' => '无法加载时间', 'load_failed' => '无法加载当前可用状态，请刷新页面后重试。', 'choose_both' => '预约前请同时选择可用的开始和结束时间。', 'choose_image' => '选择图像或拖放到这里', 'invalid_image' => '请选择 JPEG、PNG、WebP 或 GIF 图像。', 'image_too_large' => '图像不能大于 20 MB。', 'ready_upload' => '可以上传并添加到 Codex 输入区', 'copied' => '已复制', 'copy_command' => '复制命令', 'clipboard_blocked' => '浏览器阻止了剪贴板访问，请手动选择并复制命令。', 'choose_before_upload' => '上传前请先选择图像。', 'uploading' => '上传中…', 'uploaded_inserted' => '上传成功并已插入 Codex。请在终端中按回车，然后选择对应文件作为附件。', 'uploaded_copy' => '上传成功，但终端尚未就绪；请把下面的命令复制到 Codex。', 'upload_failed' => '图像上传失败，Codex 中没有添加任何内容。', 'upload_add' => '上传并添加到 CLI', 'terminal_not_ready' => '终端文字尚未就绪。', 'terminal_copy_failed' => '浏览器阻止了复制。请打开可复制文本后手动复制。', 'terminal_copied' => '已复制 :count 个字符', 'terminal_text_ready' => '已有 :count 个字符可供选择', 'resuming_session' => '正在重新打开所选会话…', 'opening_blank_session' => '正在新开空白会话…', 'session_switch_failed' => '无法切换 Codex 会话，当前项目文件没有改变。', 'session_personal_only' => '历史会话仅在个人 Codex 账号中可用；你仍可在这里新开空白会话。', 'session_count' => '当前项目共有 :count 个历史会话', 'no_saved_sessions' => '当前项目还没有历史会话。', 'session_updated' => '更新于 :time · :id', 'current_session' => '当前会话', 'continue_this_session' => '继续此会话', 'collapse_sessions' => '收起会话', 'expand_sessions' => '展开会话', 'delete_session' => '删除', 'confirm_delete_session' => '确认删除', 'cancel_delete_session' => '取消', 'session_delete_warning' => '将永久删除此 Codex 会话记录及其派生会话。', 'deleting_session' => '正在从 Codex 永久删除会话…', 'session_deleted' => 'Codex 会话已永久删除。', 'session_delete_failed' => '无法从 Codex 永久删除该会话。',
        'local_ai_countdown' => '本地 AI 还有 :time 开始',
        'local_ai_countdown_days' => ':days天 :time',
        'local_ai_starting' => '正在启用本地 AI…',
        'local_ai_ready' => '本地 AI 已可用',
        'local_ai_start_failed' => '本地 AI 启动失败',
        'local_ai_unavailable' => '本地 AI 不可用',
        'company_codex_occupied' => '公司 Codex 正被占用',
        'company_codex_owned_by_me' => '公司 Codex 当前由你使用',
        'company_codex_available' => '登录状态由服务器管理',
        'company_codex_unavailable' => '公司 Codex 暂不可用',
    ],
];

$translations['workspace']['upload_media'] = '上传图片或视频到 Codex';
$translations['workspace']['upload_help'] = '支持最大 20 MB 的 JPEG、PNG、WebP、GIF 图片，或最大 32 MB 的 MP4、WebM、MOV、M4V 视频；文件会保存到当前项目及对应媒体库。';
$translations['workspace']['choose_media'] = '选择图片或视频，或拖放到这里';
$translations['workspace']['selected_preview'] = '所选媒体预览';
$translations['workspace']['terminal_intro'] = '项目 :project 将在 /workspace/:directory 打开。在 Codex 中使用 /model 可在 OpenAI、Qwen 与 DeepSeek 间切换；H3 视频和本地图像任务仍通过受限 Broker 运行。';

$translations['errors']['media_type'] = '请选择 JPEG、PNG、WebP、GIF、MP4、WebM、MOV 或 M4V 文件。';
$translations['errors']['media_too_large'] = '视频不能大于 32 MB。';
$translations['errors']['unsupported_media'] = '不支持的图片或视频类型。';
$translations['errors']['media_unreadable'] = '上传的文件为空或无法读取。';
$translations['errors']['media_workspace_failed'] = '无法把文件添加到当前 Workspace 或项目媒体库；Codex 中没有附加任何内容。';

$translations['javascript']['choose_media'] = '选择图片或视频，或拖放到这里';
$translations['javascript']['invalid_media'] = '请选择 JPEG、PNG、WebP、GIF、MP4、WebM、MOV 或 M4V 文件。';
$translations['javascript']['video_too_large'] = '视频不能大于 32 MB。';
$translations['javascript']['choose_media_before_upload'] = '上传前请先选择图片或视频。';

$translations['media_upload'] = [
    'errors' => [
        'type' => '请选择 JPEG、PNG、WebP、GIF、MP4、WebM、MOV 或 M4V 文件。',
        'too_large' => '视频不能大于 32 MB。',
        'unsupported' => '不支持的图片或视频类型。',
        'unreadable' => '上传的文件为空或无法读取。',
        'workspace_failed' => '无法把文件添加到当前 Workspace 或项目媒体库；Codex 中没有附加任何内容。',
    ],
    'javascript' => [],
];

unset(
    $translations['errors']['image_type'],
    $translations['errors']['image_unreadable'],
    $translations['errors']['image_workspace_failed'],
    $translations['errors']['unsupported_image'],
    $translations['errors']['valid_image'],
    $translations['javascript']['choose_before_upload'],
    $translations['javascript']['choose_image'],
    $translations['javascript']['invalid_image'],
    $translations['workspace']['upload_image'],
    $translations['workspace']['choose_image'],
);

return $translations;
