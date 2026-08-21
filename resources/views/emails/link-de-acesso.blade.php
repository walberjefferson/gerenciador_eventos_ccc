{{-- O corpo do e-mail de acesso. Texto simples, sem imagem e sem dado
     pessoal: nome do evento, situacao da inscricao e o link. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seu link de acesso à inscrição</title>
</head>
<body style="margin:0;padding:24px;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;line-height:1.6;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Aqui está o seu acesso</h1>

        <p style="margin:0 0 16px;">
            Você pediu o link para acompanhar a sua inscrição. É só tocar no link abaixo.
        </p>

        @foreach ($inscricoes as $inscricao)
            <div style="margin:0 0 16px;padding:16px;border:1px solid #e3e3e3;border-radius:8px;">
                <p style="margin:0 0 4px;font-weight:bold;">{{ $inscricao['evento'] }}</p>
                <p style="margin:0 0 12px;color:#555555;">Situação: {{ $inscricao['situacao'] }}</p>
                <p style="margin:0;">
                    <a href="{{ $inscricao['link'] }}" style="color:#0b6bb3;">Acompanhar minha inscrição</a>
                </p>
            </div>
        @endforeach

        <p style="margin:0 0 16px;">
            O link vale por {{ $validadeEmDias }} dias. Depois desse prazo ele para de funcionar — se precisar, é só pedir outro na
            página de acesso.
        </p>

        <p style="margin:0;color:#555555;">
            Se não foi você quem pediu, pode ignorar esta mensagem. Nada muda na sua inscrição.
        </p>
    </div>
</body>
</html>
