<?php

declare(strict_types=1);

return [
    'app.title' => 'Compartilhe seus top artistas semanalmente!',
    'app.description' => 'Publique automaticamente seus top artistas do Last.fm da última semana no dia escolhido usando sua conta Bluesky ou Mastodon',
    'app.language' => 'Idioma',

    'footer.made_with_love' => 'Feito com ❤️ por',
    'footer.total_users' => '{0} %d usuários|{1} %d usuário|[2,*] %d usuários',

    'login.button' => 'Entrar',
    'login.bluesky.app_password' => 'Você precisará gerar uma %s para fazer login, isso é para sua própria segurança.',
    'login.bluesky.app_password_link' => 'Senha de App',
    'placeholder_username' => 'Identificador (nome de usuário, e-mail ou DID)',
    'placeholder_password' => 'Senha',
    'placeholder_instance_mastodon' => 'URL da instância (ex.: https://mastodon.social)',
    'placeholder_instance_atproto' => 'URL da instância (ex.: https://bsky.social)',

    'settings.logout' => 'Sair',
    'settings.lastfm_username' => 'Usuário do Last.fm',
    'settings.day_of_week' => 'Dia da semana',
    'settings.hour' => 'Horário',
    'settings.save' => 'Salvar',
    'settings.edit' => 'Editar',
    'settings.remove_account' => 'Remover conta',
    'settings.confirm_delete' => 'Tem certeza que deseja excluir sua conta?',
    'settings.saved' => 'Configurações salvas.',
    'settings.last_update' => 'Última atualização',
    'settings.with_montage' => 'com',
    'settings.montage' => 'colagem',

    'day.sunday' => 'Domingo',
    'day.monday' => 'Segunda-feira',
    'day.tuesday' => 'Terça-feira',
    'day.wednesday' => 'Quarta-feira',
    'day.thursday' => 'Quinta-feira',
    'day.friday' => 'Sexta-feira',
    'day.saturday' => 'Sábado',

    'status.active' => 'Sua conta está ativa. Preencha os dados do Last.fm para agendar publicações semanais.',
    'status.schedule' => 'Sua conta está configurada para publicações semanais.',
    'status.queued' => 'Seu post está na fila para processamento. Ele será enviado em breve.',
    'status.sending' => 'Seu post está sendo enviado.',
    'status.error' => 'Ocorreu um erro. O sistema tentará novamente na próxima rotina.',

    'post.top_artists' => 'Top 5 artistas da Last.week',
    'post.scrobbles' => '%d Scrobbles com Lastfm',
    'post.via' => 'via',
    'post.alt_text' => 'Colagem de fotos das bandas %s',

    'auth.logged_out' => 'Sessão encerrada.',

    'error.missing_fields' => 'Preencha todos os campos obrigatórios.',
    'error.auth_failed' => 'Falha na autenticação.',
    'error.lastfm_user_not_found' => 'Usuário do Last.fm não encontrado.',
    'error.invalid_timezone' => 'Timezone inválida.',
    'error.invalid_time' => 'Formato de horário inválido.',
    'error.generic' => 'Algo deu errado.',

    'admin.config.saved' => 'Configurações salvas com sucesso.',
];
