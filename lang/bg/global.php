<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Използване на AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Опишете партида работа с изображения; тя се изпълнява във фонов режим.',
    'denied' => 'Нямате права да използвате AIMage.',

    'new_job' => 'Нова партида',
    'jobs' => 'Партиди',
    'no_jobs' => 'Още няма нищо. Опишете какво искате да се направи.',
    'instruction_placeholder' => 'напр.: увеличи всички изображения в products/ или генерирай 10 изображения на планинско езеро на зазоряване',
    'send' => 'Изпрати',
    'record' => 'Диктувай',
    'recording' => 'Записва се — щракнете, за да спрете',
    'transcribing' => 'Разпознаване…',
    'speak_answer' => 'Изчитай отговорите на глас',

    // Models
    'text_model' => 'Модел за планиране',
    'image_model' => 'Модел за изображения',
    'voice_model' => 'Модел за диктовка',
    'output_folder' => 'Папка с резултати',
    'model_provider' => 'през :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'на изображение',
    'est_cost' => 'Прогнозна цена',
    'est_time' => 'Прогнозно изчакване',
    'eta_range' => '~:p50 с обичайно, до :p90 с',
    'price_exact' => 'Фиксирана цена',
    'price_approx' => 'Приблизително',
    'price_unknown' => 'Няма публикувана цена',
    'basis_tariff' => 'фиксирана тарифа на изображение',
    'basis_tariff_max' => 'най-скъпото ниво на разделителна способност, тоест горна граница',
    'basis_observed' => 'медианата на сравними минали изпълнения',
    'basis_rates' => 'тарифи по токени — крайната сума зависи от дължината',
    'basis_estimated' => 'предположение; за него няма история на таксуване',
    'basis_unpriced' => 'в момента без цена',
    'latency_measured' => 'измерено по :n реални изпълнения',
    'latency_coarse' => 'обобщено по вариантите на този модел',
    'latency_seeded' => 'оценка, а не измерване',
    'latency_none' => 'неизвестно',
    'catalog_stale' => 'Показва се кеширан списък с модели — шлюзът е недостъпен.',

    // Job states
    'status_planning' => 'Планиране',
    'status_awaiting_input' => 'Чака вашия отговор',
    'status_awaiting_approval' => 'Чака вашето одобрение',
    'status_running' => 'Изпълнява се',
    'status_succeeded' => 'Готово',
    'status_failed' => 'Неуспех',
    'status_cancelled' => 'Отменено',

    'approve' => 'Одобри и стартирай',
    'cancel_job' => 'Отмени',
    'plan_summary' => 'стъпки: :steps, изображения приблизително :images',
    'progress' => 'готови :done от :total',
    'failed_count' => 'с грешка: :n',
    'reply_placeholder' => 'Отговор…',

    // Steps
    'step_generate' => 'Генериране',
    'step_edit' => 'Редакция',
    'step_variate' => 'Вариация',
    'step_upscale' => 'Увеличаване',
    'step_describe' => 'Описание',
    'before' => 'Преди',
    'after' => 'След',

    // Keys
    'key_needed_title' => 'Необходим е API ключ',
    'key_needed_body' => 'AIMage работи през шлюза ai.artur.work. Въведете свой ключ или помолете администратор '
        . 'да зададе общ ключ за целия сайт.',
    'key_your_own' => 'Вашият ключ',
    'key_site' => 'Общ ключ за сайта',
    'key_using_own' => 'Използва се вашият собствен ключ.',
    'key_using_site' => 'Използва се общият ключ за сайта.',
    'key_placeholder' => 'Поставете ключа',
    'key_save' => 'Запази ключа',
    'key_clear' => 'Премахни',
    'key_saved' => 'Ключът е запазен и проверен.',
    'key_cleared' => 'Ключът е премахнат.',

    // Batching health
    'batching_unavailable' => 'Фоновото изпълнение е недостъпно, така че работата няма да тръгне сама.',
    'batching_not_registered' => 'Типът задачи на AIMage не е регистриран в тази инсталация на Evolution CMS.',
    'batching_scheduler_down' => 'Планировчикът не работи. Стартирайте го с „php core/artisan schedule:work“ или '
        . 'настройте cron да извиква „schedule:run“ всяка минута.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Нямате права за това действие.',
    'error_no_key' => 'Не е настроен API ключ нито за вас, нито за този сайт.',
    'error_empty_instruction' => 'Напишете какво искате да се направи.',
    'error_unknown_model' => 'Шлюзът не предлага модел :kind с име „:model“.',
    'error_folder_denied' => 'Нямате право да записвате в „:folder“.',
    'error_job_not_found' => 'Такава партида не съществува или не е ваша.',
    'error_job_finished' => 'Тази партида вече е приключила.',
    'error_not_awaiting_approval' => 'Тази партида не чака одобрение.',
    'error_key_from_config' => 'Ключът на сайта е зададен в конфигурацията и не може да се променя оттук.',
    'error_key_rejected' => 'Шлюзът отхвърли този ключ.',
    'error_no_audio' => 'Не е получено аудио.',
    'error_audio_too_large' => 'Този запис е твърде голям.',
    'error_audio_unsupported' => 'Този аудиоформат не се поддържа.',
    'error_empty_transcript' => 'От този запис не можа да се разпознае нищо.',
    'error_empty_text' => 'Няма какво да се изчете на глас.',
    'error_speech_disabled' => 'Изчитането на отговорите на глас не е настроено.',
];
