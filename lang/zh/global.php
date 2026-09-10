<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => '使用 AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => '描述您需要的图像处理；它将在后台运行。',
    'denied' => '您没有使用 AIMage 的权限。',

    'new_job' => '新建任务',
    'jobs' => '任务',
    'no_jobs' => '还没有内容。请描述您想完成的工作。',
    'instruction_placeholder' => '例如：放大 products/ 中的所有图片，或者生成 10 张黎明时分高山湖泊的图片',
    'send' => '发送',
    'record' => '口述',
    'recording' => '正在录音 — 点击停止',
    'transcribing' => '正在转写…',
    'speak_answer' => '朗读回复',

    // Models
    'text_model' => '规划模型',
    'image_model' => '图像模型',
    'voice_model' => '语音识别模型',
    'output_folder' => '结果文件夹',
    // Model-specific controls. The keys are the gateway's field names;
    // these are only what a person reads above the picker.
    'control_size' => '尺寸',
    'control_quality' => '质量',
    'control_background' => '背景',
    'control_aspect_ratio' => '宽高比',
    'model_provider' => '通过 :provider',

    // The two numbers the picker exists to show.
    'per_image' => '每张图片',
    'est_cost' => '预计费用',
    'est_time' => '预计等待',
    'eta_range' => '通常约 :p50 秒，最多 :p90 秒',
    'price_exact' => '固定价格',
    'price_approx' => '大致',
    'price_unknown' => '未公布价格',
    'basis_tariff' => '每张图片的固定资费',
    'basis_tariff_max' => '价格最高的分辨率档位，即价格上限',
    'basis_observed' => '同类历史运行的中位数',
    'basis_rates' => '按 token 计费 — 最终金额取决于长度',
    'basis_estimated' => '一种假设；没有对应的计费记录',
    'basis_unpriced' => '目前未定价',
    'latency_measured' => '基于 :n 次真实运行测得',
    'latency_coarse' => '按该模型的各变体汇总',
    'latency_seeded' => '这是估算值，而非实测值',
    'latency_none' => '未知',
    'catalog_stale' => '显示的是缓存的模型列表 — 无法连接到网关。',

    // Job states
    'status_planning' => '规划中',
    'status_awaiting_input' => '等待您的回答',
    'status_awaiting_approval' => '等待您的批准',
    'status_running' => '运行中',
    'status_succeeded' => '已完成',
    'status_failed' => '失败',
    'status_cancelled' => '已取消',
    // Step states. `running`, `succeeded` and `failed` are shared with the job above.
    'status_queued' => '排队中',
    'status_polling' => '等待中',
    'status_skipped' => '已跳过',

    'approve' => '批准并运行',
    'cancel_job' => '取消',
    'plan_summary' => '共 :steps 个步骤，约 :images 张图片',
    'progress' => '已完成 :done / :total',
    'failed_count' => '失败 :n 个',
    'reply_placeholder' => '回答…',
    // Who said it, above each turn in the thread.
    'turn_user' => '您',
    'turn_assistant' => '助手',

    // Steps
    'step_generate' => '生成',
    'step_edit' => '编辑',
    'step_variate' => '变体',
    'step_upscale' => '放大',
    'step_describe' => '描述',
    'before' => '之前',
    'after' => '之后',

    // Keys
    'key_needed_title' => '需要 API 密钥',
    'key_needed_body' => 'AIMage 通过 ai.artur.work 网关工作。请输入您自己的密钥，或请管理员设置一个'
        . '适用于整个站点的密钥。',
    'key_your_own' => '您的密钥',
    'key_site' => '站点密钥',
    'key_using_own' => '正在使用您自己的密钥。',
    'key_using_site' => '正在使用站点密钥。',
    'key_placeholder' => '粘贴您的密钥',
    'key_save' => '保存密钥',
    'key_clear' => '移除',
    'key_saved' => '密钥已保存并通过验证。',
    'key_cleared' => '密钥已移除。',

    // Batching health
    'batching_unavailable' => '后台处理不可用，因此任务不会自行运行。',
    'batching_not_registered' => '此 Evolution CMS 安装未注册 AIMage 任务类型。',
    'batching_scheduler_down' => '调度器未运行。请使用“php core/artisan schedule:work”启动它，或者'
        . '让 cron 每分钟调用一次“schedule:run”。',

    // Errors returned by the endpoints
    'error_forbidden' => '您没有执行该操作的权限。',
    'error_no_key' => '您和本站点都未配置 API 密钥。',
    'error_empty_instruction' => '请说明您想完成什么。',
    'error_unknown_model' => '网关未提供名为“:model”的 :kind 模型。',
    'error_model_cannot_continue' => '“:model”无法执行此处已排队的“:step”工作。',
    'error_folder_denied' => '您无权写入“:folder”。',
    'error_job_not_found' => '该任务不存在，或不属于您。',
    'error_job_finished' => '该任务已经结束。',
    'error_not_awaiting_approval' => '该任务未在等待批准。',
    'error_key_from_config' => '站点密钥在配置文件中设置，无法在此处更改。',
    'error_key_rejected' => '网关拒绝了该密钥。',
    'error_no_audio' => '未收到音频。',
    'error_audio_too_large' => '该录音过大。',
    'error_audio_unsupported' => '不支持该音频格式。',
    'error_empty_transcript' => '无法从该录音中转写出任何内容。',
    'error_empty_text' => '没有可朗读的内容。',
    'error_speech_disabled' => '尚未配置朗读回复功能。',
    'error_voice_disabled' => '本站已关闭语音输入和朗读功能。',

    // The file browser.
    'files_browse' => '浏览…',
    'files_title' => '文件',
    'files_up' => '上一级',
    'files_use_folder' => '结果放在这里',
    'files_here' => '结果保存到这里',
    'files_empty' => '此文件夹为空。',
    'files_resolution' => '分辨率',
    'files_bytes' => '大小',
    'files_modified' => '修改时间',
    'files_url' => '网址',
    'files_copy' => '复制',
    'files_copied' => '已复制',
    'files_unknown' => '未知',
    'files_not_writable' => '无法在此写入结果。',
    'files_close' => '关闭',
    'files_locate' => '显示该文件的位置',
    'error_file_not_found' => '该图片不存在，或您无权查看。',
];
