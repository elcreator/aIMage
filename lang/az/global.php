<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'AIMage-dən istifadə',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Şəkil işinin bir dəstəsini təsvir edin; iş arxa fonda icra olunur.',
    'denied' => 'AIMage-dən istifadə etmək icazəniz yoxdur.',

    'new_job' => 'Yeni dəstə',
    'jobs' => 'Dəstələr',
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

    'approve' => 'Təsdiqlə və işə sal',
    'cancel_job' => 'Ləğv et',
    'plan_summary' => 'addım: :steps, təxminən :images şəkil',
    'progress' => ':total-dan :done hazırdır',
    'failed_count' => 'alınmayan: :n',
    'reply_placeholder' => 'Cavab…',

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
    'batching_unavailable' => 'Arxa fonda icra əlçatan deyil, ona görə iş öz-özünə başlamayacaq.',
    'batching_not_registered' => 'AIMage tapşırıq növü bu Evolution CMS quraşdırmasında qeydiyyatdan keçməyib.',
    'batching_scheduler_down' => 'Planlayıcı işləmir. Onu «php core/artisan schedule:work» əmri ilə başladın və ya '
        . 'cron-u hər dəqiqə «schedule:run» çağıracaq şəkildə tənzimləyin.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Bunu etmək icazəniz yoxdur.',
    'error_no_key' => 'Nə sizin üçün, nə də bu sayt üçün API açarı təyin edilməyib.',
    'error_empty_instruction' => 'Nə edilməsini istədiyinizi yazın.',
    'error_unknown_model' => 'Şlüz «:model» adlı :kind modeli təklif etmir.',
    'error_folder_denied' => '«:folder» qovluğuna yazmaq icazəniz yoxdur.',
    'error_job_not_found' => 'Belə bir dəstə yoxdur və ya sizə aid deyil.',
    'error_job_finished' => 'Bu dəstə artıq tamamlanıb.',
    'error_not_awaiting_approval' => 'Bu dəstə təsdiq gözləmir.',
    'error_key_from_config' => 'Saytın açarı konfiqurasiyada təyin edilib və buradan dəyişdirilə bilməz.',
    'error_key_rejected' => 'Şlüz bu açarı rədd etdi.',
    'error_no_audio' => 'Səs alınmadı.',
    'error_audio_too_large' => 'Bu yazı həddindən artıq böyükdür.',
    'error_audio_unsupported' => 'Bu səs formatı dəstəklənmir.',
    'error_empty_transcript' => 'Bu yazıdan heç nə mətnə çevrilə bilmədi.',
    'error_empty_text' => 'Ucadan oxunacaq heç nə yoxdur.',
    'error_speech_disabled' => 'Cavabların ucadan oxunması tənzimlənməyib.',
];
