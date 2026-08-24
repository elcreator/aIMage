<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Utiliser AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Décrivez un lot de travail sur les images ; il s\'exécute en arrière-plan.',
    'denied' => 'Vous n\'avez pas la permission d\'utiliser AIMage.',

    'new_job' => 'Nouveau lot',
    'jobs' => 'Lots',
    'no_jobs' => 'Rien pour l\'instant. Décrivez ce que vous voulez faire.',
    'instruction_placeholder' => 'p. ex. agrandir toutes les images de products/, ou générer 10 images d\'un lac de montagne à l\'aube',
    'send' => 'Envoyer',
    'record' => 'Dicter',
    'recording' => 'Enregistrement — cliquez pour arrêter',
    'transcribing' => 'Transcription…',
    'speak_answer' => 'Lire les réponses à voix haute',

    // Models
    'text_model' => 'Modèle de planification',
    'image_model' => 'Modèle d\'image',
    'voice_model' => 'Modèle de dictée',
    'output_folder' => 'Dossier des résultats',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'par image',
    'est_cost' => 'Coût estimé',
    'est_time' => 'Attente estimée',
    'eta_range' => '~:p50 s en général, jusqu\'à :p90 s',
    'price_exact' => 'Prix fixe',
    'price_approx' => 'Approximatif',
    'price_unknown' => 'Aucun prix publié',
    'basis_tariff' => 'un tarif fixe par image',
    'basis_tariff_max' => 'le palier de résolution le plus cher, donc une borne supérieure',
    'basis_observed' => 'la médiane d\'exécutions passées comparables',
    'basis_rates' => 'tarifs par jetons — le montant final dépend de la longueur',
    'basis_estimated' => 'une hypothèse ; aucun historique de facturation ne l\'appuie',
    'basis_unpriced' => 'actuellement sans tarif',
    'latency_measured' => 'mesuré sur :n exécutions réelles',
    'latency_coarse' => 'agrégé sur les variantes de ce modèle',
    'latency_seeded' => 'une estimation, pas une mesure',
    'latency_none' => 'inconnu',
    'catalog_stale' => 'Liste de modèles en cache — la passerelle est injoignable.',

    // Job states
    'status_planning' => 'Planification',
    'status_awaiting_input' => 'Attend votre réponse',
    'status_awaiting_approval' => 'Attend votre approbation',
    'status_running' => 'En cours',
    'status_succeeded' => 'Terminé',
    'status_failed' => 'Échec',
    'status_cancelled' => 'Annulé',

    'approve' => 'Approuver et lancer',
    'cancel_job' => 'Annuler',
    'plan_summary' => ':steps étape(s), environ :images image(s)',
    'progress' => ':done sur :total terminées',
    'failed_count' => ':n en échec',
    'reply_placeholder' => 'Réponse…',

    // Steps
    'step_generate' => 'Générer',
    'step_edit' => 'Modifier',
    'step_variate' => 'Variation',
    'step_upscale' => 'Agrandir',
    'step_describe' => 'Décrire',
    'before' => 'Avant',
    'after' => 'Après',

    // Keys
    'key_needed_title' => 'Une clé API est nécessaire',
    'key_needed_body' => 'AIMage fonctionne via la passerelle ai.artur.work. Saisissez votre propre clé, ou demandez '
        . 'à un administrateur d\'en définir une pour tout le site.',
    'key_your_own' => 'Votre clé',
    'key_site' => 'Clé du site',
    'key_using_own' => 'Votre propre clé est utilisée.',
    'key_using_site' => 'La clé du site est utilisée.',
    'key_placeholder' => 'Collez votre clé',
    'key_save' => 'Enregistrer la clé',
    'key_clear' => 'Supprimer',
    'key_saved' => 'Clé enregistrée et vérifiée.',
    'key_cleared' => 'Clé supprimée.',

    // Batching health
    'batching_unavailable' => 'Le traitement en arrière-plan est indisponible : le travail ne démarrera pas tout seul.',
    'batching_not_registered' => 'Le type de tâche AIMage n\'est pas enregistré dans cette installation d\'Evolution CMS.',
    'batching_scheduler_down' => 'Le planificateur ne tourne pas. Lancez-le avec « php core/artisan schedule:work », ou '
        . 'faites appeler « schedule:run » par cron chaque minute.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Vous n\'avez pas la permission de faire cela.',
    'error_no_key' => 'Aucune clé API n\'est configurée pour vous ni pour ce site.',
    'error_empty_instruction' => 'Dites ce que vous voulez faire.',
    'error_unknown_model' => 'La passerelle ne propose pas de modèle :kind nommé « :model ».',
    'error_folder_denied' => 'Vous ne pouvez pas écrire dans « :folder ».',
    'error_job_not_found' => 'Ce lot n\'existe pas, ou ne vous appartient pas.',
    'error_job_finished' => 'Ce lot est déjà terminé.',
    'error_not_awaiting_approval' => 'Ce lot n\'attend pas d\'approbation.',
    'error_key_from_config' => 'La clé du site est définie dans la configuration et ne peut pas être modifiée ici.',
    'error_key_rejected' => 'La passerelle a rejeté cette clé.',
    'error_no_audio' => 'Aucun audio n\'a été reçu.',
    'error_audio_too_large' => 'Cet enregistrement est trop volumineux.',
    'error_audio_unsupported' => 'Ce format audio n\'est pas pris en charge.',
    'error_empty_transcript' => 'Rien n\'a pu être transcrit à partir de cet enregistrement.',
    'error_empty_text' => 'Il n\'y a rien à lire à voix haute.',
    'error_speech_disabled' => 'La lecture des réponses à voix haute n\'est pas configurée.',
];
