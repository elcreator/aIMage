<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Використання AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Опишіть пачку роботи із зображеннями; вона виконується у фоновому режимі.',
    'denied' => 'У вас немає дозволу користуватися AIMage.',

    'new_job' => 'Нова пачка',
    'jobs' => 'Пачки',
    'no_jobs' => 'Поки що порожньо. Опишіть, що потрібно зробити.',
    'instruction_placeholder' => 'напр.: збільшити всі зображення в products/ або згенерувати 10 зображень гірського озера на світанку',
    'send' => 'Надіслати',
    'record' => 'Продиктувати',
    'recording' => 'Триває запис — натисніть, щоб зупинити',
    'transcribing' => 'Розпізнавання…',
    'speak_answer' => 'Озвучувати відповіді',

    // Models
    'text_model' => 'Модель планування',
    'image_model' => 'Модель зображень',
    'voice_model' => 'Модель розпізнавання мовлення',
    'output_folder' => 'Тека результатів',
    'model_provider' => 'через :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'за зображення',
    'est_cost' => 'Орієнтовна вартість',
    'est_time' => 'Орієнтовне очікування',
    'eta_range' => '~:p50 с зазвичай, до :p90 с',
    'price_exact' => 'Фіксована ціна',
    'price_approx' => 'Приблизно',
    'price_unknown' => 'Ціну не опубліковано',
    'basis_tariff' => 'фіксований тариф за зображення',
    'basis_tariff_max' => 'найдорожчий рівень роздільності, тобто верхня межа',
    'basis_observed' => 'медіана порівнянних минулих запусків',
    'basis_rates' => 'потокенні тарифи — підсумкова сума залежить від довжини',
    'basis_estimated' => 'припущення; історії списань для нього немає',
    'basis_unpriced' => 'наразі без ціни',
    'latency_measured' => 'виміряно за :n справжніми запусками',
    'latency_coarse' => 'усереднено за варіантами цієї моделі',
    'latency_seeded' => 'оцінка, а не вимірювання',
    'latency_none' => 'невідомо',
    'catalog_stale' => 'Показано кешований перелік моделей — шлюз недоступний.',

    // Job states
    'status_planning' => 'Планування',
    'status_awaiting_input' => 'Потрібна ваша відповідь',
    'status_awaiting_approval' => 'Потрібне ваше підтвердження',
    'status_running' => 'Виконується',
    'status_succeeded' => 'Готово',
    'status_failed' => 'Помилка',
    'status_cancelled' => 'Скасовано',

    'approve' => 'Підтвердити та запустити',
    'cancel_job' => 'Скасувати',
    'plan_summary' => 'кроків: :steps, зображень приблизно :images',
    'progress' => 'готово :done з :total',
    'failed_count' => 'з помилкою: :n',
    'reply_placeholder' => 'Відповідь…',

    // Steps
    'step_generate' => 'Генерація',
    'step_edit' => 'Редагування',
    'step_variate' => 'Варіація',
    'step_upscale' => 'Збільшення',
    'step_describe' => 'Опис',
    'before' => 'Було',
    'after' => 'Стало',

    // Keys
    'key_needed_title' => 'Потрібен ключ API',
    'key_needed_body' => 'AIMage працює через шлюз ai.artur.work. Введіть власний ключ або попросіть адміністратора '
        . 'задати спільний ключ для всього сайту.',
    'key_your_own' => 'Ваш ключ',
    'key_site' => 'Спільний ключ сайту',
    'key_using_own' => 'Використовується ваш власний ключ.',
    'key_using_site' => 'Використовується спільний ключ сайту.',
    'key_placeholder' => 'Вставте ключ',
    'key_save' => 'Зберегти ключ',
    'key_clear' => 'Видалити',
    'key_saved' => 'Ключ збережено та перевірено.',
    'key_cleared' => 'Ключ видалено.',

    // Batching health
    'batching_unavailable' => 'Фонове виконання недоступне, тож робота сама не запуститься.',
    'batching_not_registered' => 'Тип завдань AIMage не зареєстровано в цій інсталяції Evolution CMS.',
    'batching_scheduler_down' => 'Планувальник не працює. Запустіть його командою «php core/artisan schedule:work» або '
        . 'налаштуйте cron на виклик «schedule:run» щохвилини.',

    // Errors returned by the endpoints
    'error_forbidden' => 'У вас немає дозволу на цю дію.',
    'error_no_key' => 'Ключ API не налаштовано ні для вас, ні для цього сайту.',
    'error_empty_instruction' => 'Напишіть, що потрібно зробити.',
    'error_unknown_model' => 'Шлюз не пропонує моделі :kind з назвою «:model».',
    'error_folder_denied' => 'Вам недоступний запис у «:folder».',
    'error_job_not_found' => 'Такої пачки немає або вона не ваша.',
    'error_job_finished' => 'Цю пачку вже завершено.',
    'error_not_awaiting_approval' => 'Ця пачка не очікує на підтвердження.',
    'error_key_from_config' => 'Ключ сайту задано в конфігурації, і його не можна змінити тут.',
    'error_key_rejected' => 'Шлюз відхилив цей ключ.',
    'error_no_audio' => 'Аудіо не отримано.',
    'error_audio_too_large' => 'Цей запис завеликий.',
    'error_audio_unsupported' => 'Цей формат аудіо не підтримується.',
    'error_empty_transcript' => 'З цього запису не вдалося нічого розпізнати.',
    'error_empty_text' => 'Немає чого озвучувати.',
    'error_speech_disabled' => 'Озвучування відповідей не налаштоване.',
];
