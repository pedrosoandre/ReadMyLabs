<?php
// ReadMyLabs — logout. Aceita GET (link) e POST (formulário). Sem CSRF estrito
// porque logout não tem efeito destrutivo cruzado (no máximo, atrapalha).
require_once __DIR__ . '/auth/lib/sessao.php';
encerrarSessao();
header('Location: /');
exit;
