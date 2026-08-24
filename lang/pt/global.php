<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Utilizar o AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Descreva um lote de trabalho com imagens; é executado em segundo plano.',
    'denied' => 'Não tem permissão para utilizar o AIMage.',

    'new_job' => 'Novo lote',
    'jobs' => 'Lotes',
    'no_jobs' => 'Ainda nada. Descreva o que quer que seja feito.',
    'instruction_placeholder' => 'ex.: ampliar todas as imagens em products/, ou gerar 10 imagens de um lago de montanha ao amanhecer',
    'send' => 'Enviar',
    'record' => 'Ditar',
    'recording' => 'A gravar — clique para parar',
    'transcribing' => 'A transcrever…',
    'speak_answer' => 'Ler as respostas em voz alta',

    // Models
    'text_model' => 'Modelo de planeamento',
    'image_model' => 'Modelo de imagem',
    'voice_model' => 'Modelo de ditado',
    'output_folder' => 'Pasta de resultados',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'por imagem',
    'est_cost' => 'Custo estimado',
    'est_time' => 'Espera estimada',
    'eta_range' => '~:p50 s habitual, até :p90 s',
    'price_exact' => 'Preço fixo',
    'price_approx' => 'Aproximado',
    'price_unknown' => 'Sem preço publicado',
    'basis_tariff' => 'uma tarifa fixa por imagem',
    'basis_tariff_max' => 'o escalão de resolução mais caro, ou seja, um limite superior',
    'basis_observed' => 'a mediana de execuções anteriores comparáveis',
    'basis_rates' => 'tarifas por tokens — o valor final depende do comprimento',
    'basis_estimated' => 'um pressuposto; não existe histórico de facturação para ele',
    'basis_unpriced' => 'de momento sem preço',
    'latency_measured' => 'medido a partir de :n execuções reais',
    'latency_coarse' => 'agregado pelas variantes deste modelo',
    'latency_seeded' => 'uma estimativa, não uma medição',
    'latency_none' => 'desconhecido',
    'catalog_stale' => 'A mostrar uma lista de modelos em cache — não foi possível contactar o gateway.',

    // Job states
    'status_planning' => 'A planear',
    'status_awaiting_input' => 'Precisa da sua resposta',
    'status_awaiting_approval' => 'Precisa da sua aprovação',
    'status_running' => 'Em execução',
    'status_succeeded' => 'Concluído',
    'status_failed' => 'Falhou',
    'status_cancelled' => 'Cancelado',

    'approve' => 'Aprovar e executar',
    'cancel_job' => 'Cancelar',
    'plan_summary' => ':steps passo(s), cerca de :images imagem(ns)',
    'progress' => ':done de :total concluídos',
    'failed_count' => ':n falharam',
    'reply_placeholder' => 'Resposta…',

    // Steps
    'step_generate' => 'Gerar',
    'step_edit' => 'Editar',
    'step_variate' => 'Variação',
    'step_upscale' => 'Ampliar',
    'step_describe' => 'Descrever',
    'before' => 'Antes',
    'after' => 'Depois',

    // Keys
    'key_needed_title' => 'É necessária uma chave de API',
    'key_needed_body' => 'O AIMage funciona através do gateway ai.artur.work. Introduza a sua própria chave, ou peça '
        . 'a um administrador que defina uma para todo o site.',
    'key_your_own' => 'A sua chave',
    'key_site' => 'Chave do site',
    'key_using_own' => 'A usar a sua própria chave.',
    'key_using_site' => 'A usar a chave do site.',
    'key_placeholder' => 'Cole a sua chave',
    'key_save' => 'Guardar chave',
    'key_clear' => 'Remover',
    'key_saved' => 'Chave guardada e verificada.',
    'key_cleared' => 'Chave removida.',

    // Batching health
    'batching_unavailable' => 'O processamento em segundo plano está indisponível, por isso o trabalho não arrancará sozinho.',
    'batching_not_registered' => 'O tipo de tarefa do AIMage não está registado nesta instalação do Evolution CMS.',
    'batching_scheduler_down' => 'O agendador não está a correr. Inicie-o com «php core/artisan schedule:work», ou '
        . 'faça o cron chamar «schedule:run» a cada minuto.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Não tem permissão para fazer isso.',
    'error_no_key' => 'Não está configurada nenhuma chave de API para si nem para este site.',
    'error_empty_instruction' => 'Diga o que quer que seja feito.',
    'error_unknown_model' => 'O gateway não oferece nenhum modelo :kind chamado «:model».',
    'error_folder_denied' => 'Não pode escrever em «:folder».',
    'error_job_not_found' => 'Esse lote não existe, ou não é seu.',
    'error_job_finished' => 'Esse lote já terminou.',
    'error_not_awaiting_approval' => 'Esse lote não está à espera de aprovação.',
    'error_key_from_config' => 'A chave do site está definida na configuração e não pode ser alterada aqui.',
    'error_key_rejected' => 'O gateway rejeitou essa chave.',
    'error_no_audio' => 'Não foi recebido áudio.',
    'error_audio_too_large' => 'Essa gravação é demasiado grande.',
    'error_audio_unsupported' => 'Esse formato de áudio não é suportado.',
    'error_empty_transcript' => 'Não foi possível transcrever nada dessa gravação.',
    'error_empty_text' => 'Não há nada para ler em voz alta.',
    'error_speech_disabled' => 'A leitura das respostas em voz alta não está configurada.',
];
