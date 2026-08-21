{{-- Moldura comum dos e-mails do participante.
     Sem imagem, sem rastreador e sem dado pessoal alem do primeiro nome: a
     mensagem precisa abrir bem em qualquer programa de e-mail, inclusive nos
     que bloqueiam imagem por padrao. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo')</title>
</head>
<body style="margin:0;padding:24px;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;line-height:1.6;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 16px;font-size:20px;">@yield('titulo')</h1>

        @yield('conteudo')

        <p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e3e3e3;color:#555555;font-size:13px;">
            Esta mensagem foi enviada porque existe uma inscrição sua neste evento.
            Se precisar de ajuda, responda a este e-mail ou procure a organização.
        </p>
    </div>
</body>
</html>
