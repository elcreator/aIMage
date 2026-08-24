<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'استفاده از AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'یک دستهٔ کار روی تصویرها را شرح دهید؛ در پس‌زمینه اجرا می‌شود.',
    'denied' => 'شما اجازهٔ استفاده از AIMage را ندارید.',

    'new_job' => 'دستهٔ جدید',
    'jobs' => 'دسته‌ها',
    'no_jobs' => 'هنوز چیزی نیست. شرح دهید چه کاری باید انجام شود.',
    'instruction_placeholder' => 'مثلاً: همهٔ تصویرهای products/ را بزرگ کن، یا ۱۰ تصویر از دریاچه‌ای کوهستانی در سپیده‌دم بساز',
    'send' => 'ارسال',
    'record' => 'گفتار',
    'recording' => 'در حال ضبط — برای توقف کلیک کنید',
    'transcribing' => 'در حال پیاده‌سازی متن…',
    'speak_answer' => 'خواندن پاسخ‌ها با صدای بلند',

    // Models
    'text_model' => 'مدل برنامه‌ریزی',
    'image_model' => 'مدل تصویر',
    'voice_model' => 'مدل گفتارنویسی',
    'output_folder' => 'پوشهٔ نتایج',
    'model_provider' => 'از طریق :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'برای هر تصویر',
    'est_cost' => 'هزینهٔ تخمینی',
    'est_time' => 'انتظار تخمینی',
    'eta_range' => 'معمولاً حدود :p50 ثانیه، تا :p90 ثانیه',
    'price_exact' => 'قیمت ثابت',
    'price_approx' => 'تقریبی',
    'price_unknown' => 'قیمتی منتشر نشده است',
    'basis_tariff' => 'تعرفهٔ ثابت برای هر تصویر',
    'basis_tariff_max' => 'گران‌ترین ردهٔ وضوح، یعنی یک کران بالا',
    'basis_observed' => 'میانهٔ اجراهای مشابه گذشته',
    'basis_rates' => 'نرخ توکنی — مبلغ نهایی به طول بستگی دارد',
    'basis_estimated' => 'یک فرض؛ سابقهٔ صورت‌حسابی برای آن وجود ندارد',
    'basis_unpriced' => 'در حال حاضر قیمت‌گذاری نشده',
    'latency_measured' => 'اندازه‌گیری‌شده بر پایهٔ :n اجرای واقعی',
    'latency_coarse' => 'تجمیع‌شده در میان گونه‌های این مدل',
    'latency_seeded' => 'یک تخمین، نه یک اندازه‌گیری',
    'latency_none' => 'نامعلوم',
    'catalog_stale' => 'فهرست مدل‌ها از حافظهٔ نهان نمایش داده می‌شود — دسترسی به دروازه ممکن نشد.',

    // Job states
    'status_planning' => 'در حال برنامه‌ریزی',
    'status_awaiting_input' => 'منتظر پاسخ شماست',
    'status_awaiting_approval' => 'منتظر تأیید شماست',
    'status_running' => 'در حال اجرا',
    'status_succeeded' => 'انجام شد',
    'status_failed' => 'ناموفق',
    'status_cancelled' => 'لغو شد',

    'approve' => 'تأیید و اجرا',
    'cancel_job' => 'لغو',
    'plan_summary' => ':steps گام، حدود :images تصویر',
    'progress' => ':done از :total انجام شد',
    'failed_count' => ':n ناموفق',
    'reply_placeholder' => 'پاسخ…',

    // Steps
    'step_generate' => 'ساخت',
    'step_edit' => 'ویرایش',
    'step_variate' => 'گونهٔ دیگر',
    'step_upscale' => 'بزرگ‌نمایی',
    'step_describe' => 'توصیف',
    'before' => 'پیش',
    'after' => 'پس',

    // Keys
    'key_needed_title' => 'به یک کلید API نیاز است',
    'key_needed_body' => 'AIMage از طریق دروازهٔ ai.artur.work کار می‌کند. کلید خودتان را وارد کنید، یا از یک '
        . 'مدیر بخواهید کلیدی برای کل سایت تنظیم کند.',
    'key_your_own' => 'کلید شما',
    'key_site' => 'کلید سراسری سایت',
    'key_using_own' => 'از کلید خودتان استفاده می‌شود.',
    'key_using_site' => 'از کلید سراسری سایت استفاده می‌شود.',
    'key_placeholder' => 'کلید خود را بچسبانید',
    'key_save' => 'ذخیرهٔ کلید',
    'key_clear' => 'حذف',
    'key_saved' => 'کلید ذخیره و تأیید شد.',
    'key_cleared' => 'کلید حذف شد.',

    // Batching health
    'batching_unavailable' => 'اجرای پس‌زمینه در دسترس نیست، بنابراین کار به‌خودی‌خود آغاز نخواهد شد.',
    'batching_not_registered' => 'نوع وظیفهٔ AIMage در این نصب Evolution CMS ثبت نشده است.',
    'batching_scheduler_down' => 'زمان‌بند در حال اجرا نیست. آن را با «php core/artisan schedule:work» اجرا کنید، یا '
        . 'cron را طوری تنظیم کنید که هر دقیقه «schedule:run» را فراخوانی کند.',

    // Errors returned by the endpoints
    'error_forbidden' => 'اجازهٔ انجام این کار را ندارید.',
    'error_no_key' => 'نه برای شما و نه برای این سایت کلید API تنظیم نشده است.',
    'error_empty_instruction' => 'بنویسید چه کاری باید انجام شود.',
    'error_unknown_model' => 'دروازه هیچ مدل :kind با نام «:model» ارائه نمی‌دهد.',
    'error_folder_denied' => 'اجازهٔ نوشتن در «:folder» را ندارید.',
    'error_job_not_found' => 'چنین دسته‌ای وجود ندارد، یا متعلق به شما نیست.',
    'error_job_finished' => 'این دسته پیش‌تر به پایان رسیده است.',
    'error_not_awaiting_approval' => 'این دسته منتظر تأیید نیست.',
    'error_key_from_config' => 'کلید سایت در پیکربندی تعیین شده و از اینجا قابل تغییر نیست.',
    'error_key_rejected' => 'دروازه این کلید را نپذیرفت.',
    'error_no_audio' => 'هیچ صدایی دریافت نشد.',
    'error_audio_too_large' => 'این ضبط بیش از حد بزرگ است.',
    'error_audio_unsupported' => 'این قالب صوتی پشتیبانی نمی‌شود.',
    'error_empty_transcript' => 'از این ضبط چیزی قابل پیاده‌سازی نبود.',
    'error_empty_text' => 'چیزی برای خواندن با صدای بلند وجود ندارد.',
    'error_speech_disabled' => 'خواندن پاسخ‌ها با صدای بلند پیکربندی نشده است.',
];
