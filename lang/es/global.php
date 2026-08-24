<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Usar AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Describe un lote de trabajo con imágenes; se ejecuta en segundo plano.',
    'denied' => 'No tienes permiso para usar AIMage.',

    'new_job' => 'Nuevo lote',
    'jobs' => 'Lotes',
    'no_jobs' => 'Nada todavía. Describe lo que quieres hacer.',
    'instruction_placeholder' => 'p. ej. ampliar todas las imágenes de products/, o generar 10 imágenes de un lago de montaña al amanecer',
    'send' => 'Enviar',
    'record' => 'Dictar',
    'recording' => 'Grabando — haz clic para detener',
    'transcribing' => 'Transcribiendo…',
    'speak_answer' => 'Leer las respuestas en voz alta',

    // Models
    'text_model' => 'Modelo de planificación',
    'image_model' => 'Modelo de imagen',
    'voice_model' => 'Modelo de dictado',
    'output_folder' => 'Carpeta de resultados',
    'model_provider' => 'vía :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'por imagen',
    'est_cost' => 'Coste estimado',
    'est_time' => 'Espera estimada',
    'eta_range' => '~:p50 s habitual, hasta :p90 s',
    'price_exact' => 'Precio fijo',
    'price_approx' => 'Aproximado',
    'price_unknown' => 'Sin precio publicado',
    'basis_tariff' => 'una tarifa fija por imagen',
    'basis_tariff_max' => 'el nivel de resolución más caro, es decir, un límite superior',
    'basis_observed' => 'la mediana de ejecuciones anteriores comparables',
    'basis_rates' => 'tarifas por tokens: el importe final depende de la longitud',
    'basis_estimated' => 'una suposición; no hay historial de facturación para ella',
    'basis_unpriced' => 'actualmente sin precio',
    'latency_measured' => 'medido a partir de :n ejecuciones reales',
    'latency_coarse' => 'agrupado entre las variantes de este modelo',
    'latency_seeded' => 'una estimación, no una medición',
    'latency_none' => 'desconocido',
    'catalog_stale' => 'Mostrando una lista de modelos en caché: no se pudo contactar con la pasarela.',

    // Job states
    'status_planning' => 'Planificando',
    'status_awaiting_input' => 'Necesita tu respuesta',
    'status_awaiting_approval' => 'Necesita tu aprobación',
    'status_running' => 'En ejecución',
    'status_succeeded' => 'Hecho',
    'status_failed' => 'Fallido',
    'status_cancelled' => 'Cancelado',

    'approve' => 'Aprobar y ejecutar',
    'cancel_job' => 'Cancelar',
    'plan_summary' => ':steps paso(s), unas :images imagen(es)',
    'progress' => ':done de :total completados',
    'failed_count' => ':n fallidos',
    'reply_placeholder' => 'Respuesta…',

    // Steps
    'step_generate' => 'Generar',
    'step_edit' => 'Editar',
    'step_variate' => 'Variación',
    'step_upscale' => 'Ampliar',
    'step_describe' => 'Describir',
    'before' => 'Antes',
    'after' => 'Después',

    // Keys
    'key_needed_title' => 'Se necesita una clave API',
    'key_needed_body' => 'AIMage funciona a través de la pasarela ai.artur.work. Introduce tu propia clave o pide a '
        . 'un administrador que configure una para todo el sitio.',
    'key_your_own' => 'Tu clave',
    'key_site' => 'Clave del sitio',
    'key_using_own' => 'Se está usando tu propia clave.',
    'key_using_site' => 'Se está usando la clave del sitio.',
    'key_placeholder' => 'Pega tu clave',
    'key_save' => 'Guardar clave',
    'key_clear' => 'Eliminar',
    'key_saved' => 'Clave guardada y verificada.',
    'key_cleared' => 'Clave eliminada.',

    // Batching health
    'batching_unavailable' => 'El procesamiento en segundo plano no está disponible, así que el trabajo no se ejecutará solo.',
    'batching_not_registered' => 'El tipo de tarea de AIMage no está registrado en esta instalación de Evolution CMS.',
    'batching_scheduler_down' => 'El planificador no se está ejecutando. Inícialo con «php core/artisan schedule:work», o '
        . 'haz que cron llame a «schedule:run» cada minuto.',

    // Errors returned by the endpoints
    'error_forbidden' => 'No tienes permiso para hacer eso.',
    'error_no_key' => 'No hay ninguna clave API configurada para ti ni para este sitio.',
    'error_empty_instruction' => 'Di qué quieres que se haga.',
    'error_unknown_model' => 'La pasarela no ofrece ningún modelo de :kind llamado «:model».',
    'error_folder_denied' => 'No puedes escribir en «:folder».',
    'error_job_not_found' => 'Ese lote no existe, o no es tuyo.',
    'error_job_finished' => 'Ese lote ya ha terminado.',
    'error_not_awaiting_approval' => 'Ese lote no está esperando aprobación.',
    'error_key_from_config' => 'La clave del sitio está definida en la configuración y no puede cambiarse aquí.',
    'error_key_rejected' => 'La pasarela rechazó esa clave.',
    'error_no_audio' => 'No se recibió audio.',
    'error_audio_too_large' => 'Esa grabación es demasiado grande.',
    'error_audio_unsupported' => 'Ese formato de audio no es compatible.',
    'error_empty_transcript' => 'No se pudo transcribir nada de esa grabación.',
    'error_empty_text' => 'No hay nada que leer en voz alta.',
    'error_speech_disabled' => 'La lectura de respuestas en voz alta no está configurada.',
];
