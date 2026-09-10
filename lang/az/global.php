<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'AIMage-dən istifadə',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Lazım olan şəkil işini təsvir edin; o, fonda icra olunur.',
    'denied' => 'AIMage-dən istifadə etmək icazəniz yoxdur.',

    'new_job' => 'Yeni tapşırıq',
    'jobs' => 'Tapşırıqlar',
    'no_jobs' => 'Hələ heç nə yoxdur. Nə edilməsini istədiyinizi təsvir edin.',
    'instruction_placeholder' => 'məs.: products/ qovluğundakı bütün şəkilləri böyüt, ya da dan yeri söküləndə dağ gölünün 10 şəklini yarat',
    'send' => 'Göndər',
    'record' => 'Diktə et',
    'recording' => 'Yazılır — dayandırmaq üçün klikləyin',
    'transcribing' => 'Mətnə çevrilir…',
    'speak_answer' => 'Cavabları ucadan oxu',

    // Models
    'text_model' => 'Planlaşdırma modeli',
    'image_model' => 'Şəkil modeli',
    'voice_model' => 'Diktə modeli',
    'output_folder' => 'Nəticələr qovluğu',
    // Model-specific controls. The keys are the gateway's field names;
    // these are only what a person reads above the picker.
    'control_size' => 'Ölçü',
    'control_quality' => 'Keyfiyyət',
    'control_background' => 'Fon',
    'control_aspect_ratio' => 'En-boy nisbəti',
    'model_provider' => ':provider vasitəsilə',

    // The two numbers the picker exists to show.
    'per_image' => 'hər şəkil üçün',
    'est_cost' => 'Təxmini xərc',
    'est_time' => 'Təxmini gözləmə',
    'eta_range' => 'adətən ~:p50 san, :p90 san-ə qədər',
    'price_exact' => 'Sabit qiymət',
    'price_approx' => 'Təxmini',
    'price_unknown' => 'Dərc olunmuş qiymət yoxdur',
    'basis_tariff' => 'hər şəkil üçün sabit tarif',
    'basis_tariff_max' => 'ən yüksək qiymətli ayırdetmə səviyyəsi, yəni yuxarı hədd',
    'basis_observed' => 'oxşar keçmiş icraların medianı',
    'basis_rates' => 'token tarifləri — yekun məbləğ uzunluqdan asılıdır',
    'basis_estimated' => 'ehtimal; bunun üçün hesablama tarixçəsi yoxdur',
    'basis_unpriced' => 'hazırda qiymətləndirilməyib',
    'latency_measured' => ':n real icra əsasında ölçülüb',
    'latency_coarse' => 'bu modelin variantları üzrə birləşdirilib',
    'latency_seeded' => 'ölçmə deyil, təxmin',
    'latency_none' => 'naməlum',
    'catalog_stale' => 'Keşlənmiş model siyahısı göstərilir — şlüzə qoşulmaq mümkün olmadı.',

    // Job states
    'status_planning' => 'Planlaşdırma',
    'status_awaiting_input' => 'Cavabınızı gözləyir',
    'status_awaiting_approval' => 'Təsdiqinizi gözləyir',
    'status_running' => 'İcra olunur',
    'status_succeeded' => 'Hazırdır',
    'status_failed' => 'Alınmadı',
    'status_cancelled' => 'Ləğv edildi',
    // Step states. `running`, `succeeded` and `failed` are shared with the job above.
    'status_queued' => 'Növbədə',
    'status_polling' => 'Gözləyir',
    'status_skipped' => 'Ötürülüb',

    'approve' => 'Təsdiqlə və işə sal',
    'cancel_job' => 'Ləğv et',
    'plan_summary' => 'addım: :steps, təxminən :images şəkil',
    'progress' => ':total-dan :done hazırdır',
    'failed_count' => 'alınmayan: :n',
    'reply_placeholder' => 'Cavab…',
    // Who said it, above each turn in the thread.
    'turn_user' => 'Siz',
    'turn_assistant' => 'Köməkçi',

    // Steps
    'step_generate' => 'Yaratma',
    'step_edit' => 'Redaktə',
    'step_variate' => 'Variasiya',
    'step_upscale' => 'Böyütmə',
    'step_describe' => 'Təsvir',
    'before' => 'Əvvəl',
    'after' => 'Sonra',

    // Keys
    'key_needed_title' => 'API açarı lazımdır',
    'key_needed_body' => 'AIMage ai.artur.work şlüzü vasitəsilə işləyir. Öz açarınızı daxil edin və ya '
        . 'administratordan bütün sayt üçün ümumi açar təyin etməsini xahiş edin.',
    'key_your_own' => 'Sizin açarınız',
    'key_site' => 'Saytın ümumi açarı',
    'key_using_own' => 'Sizin öz açarınız istifadə olunur.',
    'key_using_site' => 'Saytın ümumi açarı istifadə olunur.',
    'key_placeholder' => 'Açarınızı yapışdırın',
    'key_save' => 'Açarı yadda saxla',
    'key_clear' => 'Sil',
    'key_saved' => 'Açar yadda saxlanıldı və yoxlanıldı.',
    'key_cleared' => 'Açar silindi.',

    // Batching health
    'batching_unavailable' => 'Fon emalı əlçatmazdır, buna görə iş öz-özünə işə düşməyəcək.',
    'batching_not_registered' => 'AIMage tapşırıq növü bu Evolution CMS quraşdırmasında qeydiyyatdan keçməyib.',
    'batching_scheduler_down' => 'Planlayıcı işləmir. Onu «php core/artisan schedule:work» əmri ilə başladın və ya '
        . 'cron-u hər dəqiqə «schedule:run» çağıracaq şəkildə tənzimləyin.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Bunu etmək icazəniz yoxdur.',
    'error_no_key' => 'Nə sizin üçün, nə də bu sayt üçün API açarı təyin edilməyib.',
    'error_empty_instruction' => 'Nə edilməsini istədiyinizi yazın.',
    'error_unknown_model' => 'Şlüz «:model» adlı :kind modeli təklif etmir.',
    'error_model_cannot_continue' => '":model" burada artıq növbəyə qoyulmuş ":step" işini yerinə yetirə bilmir.',
    'error_folder_denied' => '«:folder» qovluğuna yazmaq icazəniz yoxdur.',
    'error_job_not_found' => 'Belə tapşırıq yoxdur və ya sizin deyil.',
    'error_job_finished' => 'Bu tapşırıq artıq tamamlanıb.',
    'error_not_awaiting_approval' => 'Bu tapşırıq təsdiq gözləmir.',
    'error_key_from_config' => 'Saytın açarı konfiqurasiyada təyin edilib və buradan dəyişdirilə bilməz.',
    'error_key_rejected' => 'Şlüz bu açarı rədd etdi.',
    'error_no_audio' => 'Səs alınmadı.',
    'error_audio_too_large' => 'Bu yazı həddindən artıq böyükdür.',
    'error_audio_unsupported' => 'Bu səs formatı dəstəklənmir.',
    'error_empty_transcript' => 'Bu yazıdan heç nə mətnə çevrilə bilmədi.',
    'error_empty_text' => 'Ucadan oxunacaq heç nə yoxdur.',
    'error_speech_disabled' => 'Cavabların ucadan oxunması tənzimlənməyib.',
    'error_voice_disabled' => 'Səslə giriş və ucadan oxuma bu sayt üçün söndürülüb.',

    // The file browser.
    'files_browse' => 'Gözdən keçir…',
    'files_title' => 'Fayllar',
    'files_up' => 'Yuxarı',
    'files_use_folder' => 'Nəticələri buraya qoy',
    'files_here' => 'Nəticələr buraya gedir',
    'files_empty' => 'Bu qovluqda heç nə yoxdur.',
    'files_resolution' => 'Ölçü',
    'files_bytes' => 'Həcm',
    'files_modified' => 'Dəyişdirilib',
    'files_url' => 'URL',
    'files_copy' => 'Kopyala',
    'files_copied' => 'Kopyalandı',
    'files_unknown' => 'Naməlum',
    'files_not_writable' => 'Nəticələri buraya yazmaq olmaz.',
    'files_close' => 'Bağla',
    'files_locate' => 'Bu faylın harada olduğunu göstər',
    'error_file_not_found' => 'Bu şəkil mövcud deyil və ya siz onu görə bilməzsiniz.',
];
